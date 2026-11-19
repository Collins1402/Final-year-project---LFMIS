<?php
declare(strict_types=1);

namespace Lfmis\Jobs;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Lfmis\Core\Database;
use Lfmis\Core\Env;
use Lfmis\Services\FarmMetricsService;
use Lfmis\Services\SyntheticTrendsService;
use Lfmis\Services\TrendsService;
use PDO;
use Throwable;

final class RefreshTrends
{
    public const TIMEZONE = 'Africa/Nairobi';

    private TrendsService $trends;
    private SyntheticTrendsService $synthetic;
    private FarmMetricsService $farmMetrics;

    public function __construct(
        private PDO $db,
        ?TrendsService $trends = null,
        ?SyntheticTrendsService $synthetic = null,
        ?FarmMetricsService $farmMetrics = null
    ) {
        $this->trends = $trends ?? new TrendsService($this->db);
        $this->synthetic = $synthetic ?? new SyntheticTrendsService($this->db);
        $this->farmMetrics = $farmMetrics ?? new FarmMetricsService($this->db);
    }

    public static function reportingPeriodFor(?DateTimeInterface $time = null): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        if ($time instanceof DateTimeImmutable) {
            $dt = $time->setTimezone($tz);
        } elseif ($time !== null) {
            $dt = (new DateTimeImmutable('@' . $time->getTimestamp()))->setTimezone($tz);
        } else {
            $dt = new DateTimeImmutable('now', $tz);
        }

        $hour = (int) $dt->format('G');
        $periodStartHour = intdiv($hour, 6) * 6;

        $periodStart = $dt->setTime($periodStartHour, 0, 0);
        $periodEnd = $periodStart->modify('+6 hours');

        $startStr = $periodStart->format('H:i');
        $endStr = $periodEnd->format('H:i');
        if ($periodStartHour === 18) {
            $endStr = '00:00';
        }
        $windowCode = "{$startStr}–{$endStr}";

        return [
            'periodStart' => $periodStart->format('Y-m-d H:i:s'),
            'periodEnd' => $periodEnd->format('Y-m-d H:i:s'),
            'nextRefresh' => $periodEnd->format('Y-m-d H:i:s'),
            'windowCode' => $windowCode,
            'timezone' => self::TIMEZONE,
        ];
    }

    public function run(bool $force = false, ?int $seed = null): array
    {
        $period = self::reportingPeriodFor();
        $periodStart = $period['periodStart'];
        $periodEnd = $period['periodEnd'];
        $now = gmdate('Y-m-d H:i:s');

        // Check if already processed
        if (!$force && $this->isPeriodCompleted($periodStart)) {
            return [
                'status' => 'skipped',
                'message' => "Reporting period {$period['windowCode']} ({$periodStart} EAT) was already processed.",
                'period' => $period,
            ];
        }

        // Detect and log missed periods if server was offline
        $this->detectAndLogMissedPeriods($periodStart);

        // Record execution start
        $this->recordJobStart($periodStart, $periodEnd, $now);

        $liveStatus = 'success';
        $syntheticStatus = 'success';
        $farmStatus = 'success';
        $errors = [];

        // 1. Live trends
        $liveData = null;
        try {
            $liveData = $this->trends->current(true);
            $hasAnyArticles = !empty($liveData['kenya']['articles'])
                || !empty($liveData['east_africa']['articles'])
                || !empty($liveData['global']['articles']);
            $hasStale = !empty($liveData['kenya']['stale'])
                || !empty($liveData['east_africa']['stale'])
                || !empty($liveData['global']['stale']);

            if (!$hasAnyArticles) {
                $liveStatus = 'failed';
            } elseif ($hasStale) {
                $liveStatus = 'partial';
            }

            // Persist snapshots
            foreach (['kenya', 'east_africa', 'global'] as $scope) {
                $scopeArticles = $liveData[$scope]['articles'] ?? [];
                $scopeStatus = empty($scopeArticles) ? 'failed' : (!empty($liveData[$scope]['stale']) ? 'stale' : 'success');
                $this->persistSnapshotHistory('live', $scope, $periodStart, $periodEnd, $scopeArticles, $scopeStatus);
            }
        } catch (Throwable $e) {
            $liveStatus = 'failed';
            $errors[] = 'Live trends error: ' . $e->getMessage();
        }

        // 2. Synthetic trends
        $synthData = null;
        try {
            $synthData = $this->synthetic->generate($periodStart, $seed);
            foreach (['kenya', 'east_africa', 'global'] as $scope) {
                $scopeData = $synthData['scopes'][$scope] ?? [];
                $this->persistSnapshotHistory('simulated', $scope, $periodStart, $periodEnd, $scopeData, 'success');
            }
        } catch (Throwable $e) {
            $syntheticStatus = 'failed';
            $errors[] = 'Synthetic trends error: ' . $e->getMessage();
        }

        // 3. Actual Farm Performance
        $farmData = null;
        try {
            $farmData = $this->farmMetrics->summary();
            $this->persistSnapshotHistory('actual_farm', 'farm', $periodStart, $periodEnd, $farmData, 'success');
        } catch (Throwable $e) {
            $farmStatus = 'failed';
            $errors[] = 'Actual farm metrics error: ' . $e->getMessage();
        }

        // Finalize log
        $completedAt = gmdate('Y-m-d H:i:s');
        $errorMessage = !empty($errors) ? implode('; ', $errors) : null;
        $this->recordJobCompletion($periodStart, $completedAt, $liveStatus, $syntheticStatus, $farmStatus, $errorMessage);

        return [
            'status' => ($liveStatus === 'failed' && $syntheticStatus === 'failed') ? 'failed' : 'success',
            'period' => $period,
            'startedAt' => $now,
            'completedAt' => $completedAt,
            'liveStatus' => $liveStatus,
            'syntheticStatus' => $syntheticStatus,
            'actualFarmStatus' => $farmStatus,
            'errors' => $errors,
        ];
    }

    public function status(): array
    {
        $period = self::reportingPeriodFor();
        $lastLog = null;
        $missedCount = 0;

        try {
            $stmt = $this->db->query('SELECT * FROM trend_refresh_logs ORDER BY period_start DESC LIMIT 1');
            $lastLog = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $missedStmt = $this->db->query("SELECT COUNT(*) FROM trend_refresh_logs WHERE error_message LIKE '%Missed scheduled period%'");
            $missedCount = (int) $missedStmt->fetchColumn();
        } catch (Throwable) {}

        return [
            'currentPeriod' => $period['windowCode'],
            'periodStart' => $period['periodStart'],
            'periodEnd' => $period['periodEnd'],
            'nextScheduledRefresh' => $period['nextRefresh'],
            'timezone' => self::TIMEZONE,
            'lastLog' => $lastLog,
            'missedPeriodsCount' => $missedCount,
            'isPeriodProcessed' => $this->isPeriodCompleted($period['periodStart']),
        ];
    }

    private function isPeriodCompleted(string $periodStart): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT live_status, synthetic_status FROM trend_refresh_logs WHERE period_start = ? AND completed_at IS NOT NULL');
            $stmt->execute([$periodStart]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row['live_status'] !== 'failed' && $row['synthetic_status'] === 'success';
            }
        } catch (Throwable) {}
        return false;
    }

    private function detectAndLogMissedPeriods(string $currentPeriodStart): void
    {
        try {
            $stmt = $this->db->query('SELECT period_start FROM trend_refresh_logs ORDER BY period_start DESC LIMIT 1');
            $lastLoggedStart = $stmt->fetchColumn();
            if ($lastLoggedStart === false) {
                return; // First run, no previous missed period
            }

            $lastTime = strtotime((string) $lastLoggedStart);
            $currTime = strtotime($currentPeriodStart);
            $sixHours = 6 * 3600;

            $checkTime = $lastTime + $sixHours;
            while ($checkTime < $currTime) {
                $tz = new DateTimeZone(self::TIMEZONE);
                $missedDt = (new DateTimeImmutable("@{$checkTime}"))->setTimezone($tz);
                $p = self::reportingPeriodFor($missedDt);
                $mStart = $p['periodStart'];
                $mEnd = $p['periodEnd'];

                $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
                $insertSql = $isSqlite
                    ? 'INSERT OR IGNORE INTO trend_refresh_logs (period_start, period_end, started_at, completed_at, live_status, synthetic_status, actual_farm_status, error_message, attempt_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                    : 'INSERT IGNORE INTO trend_refresh_logs (period_start, period_end, started_at, completed_at, live_status, synthetic_status, actual_farm_status, error_message, attempt_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)';

                $ins = $this->db->prepare($insertSql);
                $ins->execute([
                    $mStart,
                    $mEnd,
                    $mStart,
                    $mEnd,
                    'failed',
                    'failed',
                    'failed',
                    "Missed scheduled period ({$p['windowCode']} EAT) while system was offline.",
                ]);

                $checkTime += $sixHours;
            }
        } catch (Throwable) {}
    }

    private function recordJobStart(string $periodStart, string $periodEnd, string $startedAt): void
    {
        try {
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($isSqlite) {
                $sql = 'INSERT INTO trend_refresh_logs (period_start, period_end, started_at, live_status, synthetic_status, actual_farm_status, attempt_count)
                        VALUES (?, ?, ?, "partial", "partial", "partial", 1)
                        ON CONFLICT(period_start) DO UPDATE SET started_at = excluded.started_at, attempt_count = attempt_count + 1';
            } else {
                $sql = 'INSERT INTO trend_refresh_logs (period_start, period_end, started_at, live_status, synthetic_status, actual_farm_status, attempt_count)
                        VALUES (?, ?, ?, "partial", "partial", "partial", 1)
                        ON DUPLICATE KEY UPDATE started_at = VALUES(started_at), attempt_count = attempt_count + 1';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$periodStart, $periodEnd, $startedAt]);
        } catch (Throwable) {}
    }

    private function recordJobCompletion(
        string $periodStart,
        string $completedAt,
        string $liveStatus,
        string $syntheticStatus,
        string $actualFarmStatus,
        ?string $errorMessage
    ): void {
        try {
            $stmt = $this->db->prepare(
                'UPDATE trend_refresh_logs
                 SET completed_at = ?, live_status = ?, synthetic_status = ?, actual_farm_status = ?, error_message = ?
                 WHERE period_start = ?'
            );
            $stmt->execute([$completedAt, $liveStatus, $syntheticStatus, $actualFarmStatus, $errorMessage, $periodStart]);
        } catch (Throwable) {}
    }

    private function persistSnapshotHistory(
        string $trendType,
        string $scope,
        string $periodStart,
        string $periodEnd,
        mixed $payloadData,
        string $status
    ): void {
        try {
            $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($isSqlite) {
                $sql = 'INSERT INTO trend_snapshot_history (trend_type, geographic_scope, period_start, period_end, payload, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT(trend_type, geographic_scope, period_start) DO UPDATE SET payload = excluded.payload, status = excluded.status';
            } else {
                $sql = 'INSERT INTO trend_snapshot_history (trend_type, geographic_scope, period_start, period_end, payload, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE payload = VALUES(payload), status = VALUES(status)';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$trendType, $scope, $periodStart, $periodEnd, $payload, $status]);
        } catch (Throwable) {}
    }
}

// Allow direct CLI execution: php backend/src/Jobs/RefreshTrends.php
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once dirname(__DIR__) . '/Core/Env.php';
    require_once dirname(__DIR__) . '/Core/Database.php';
    Env::load(dirname(__DIR__, 2) . '/.env.local');
    $db = Database::connection();
    $job = new RefreshTrends($db);
    $force = in_array('--force', $argv, true);
    $result = $job->run($force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
