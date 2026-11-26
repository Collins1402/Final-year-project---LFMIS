<?php
/**
 * File purpose: proves the expected refreshtrendsjob behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Lfmis\Jobs\RefreshTrends;
use Lfmis\Services\FarmMetricsService;
use Lfmis\Services\SyntheticTrendsService;
use Lfmis\Services\TrendsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class RefreshTrendsJobTest extends TestCase
{
    /**
 * Runs the test reporting period aligns with east africa time boundaries behavior under test.
 * @return void Result of this operation.
 */
    public function testReportingPeriodAlignsWithEastAfricaTimeBoundaries(): void
    {
        $tz = new DateTimeZone('Africa/Nairobi');

        // Test 04:15 EAT -> 00:00–06:00
        $t1 = new DateTimeImmutable('2026-11-19 04:15:00', $tz);
        $p1 = RefreshTrends::reportingPeriodFor($t1);
        $this->assertSame('00:00–06:00', $p1['windowCode']);
        $this->assertSame('2026-11-19 00:00:00', $p1['periodStart']);
        $this->assertSame('2026-11-19 06:00:00', $p1['periodEnd']);

        // Test 08:30 EAT -> 06:00–12:00
        $t2 = new DateTimeImmutable('2026-11-19 08:30:00', $tz);
        $p2 = RefreshTrends::reportingPeriodFor($t2);
        $this->assertSame('06:00–12:00', $p2['windowCode']);
        $this->assertSame('2026-11-19 06:00:00', $p2['periodStart']);
        $this->assertSame('2026-11-19 12:00:00', $p2['periodEnd']);

        // Test 14:45 EAT -> 12:00–18:00
        $t3 = new DateTimeImmutable('2026-11-19 14:45:00', $tz);
        $p3 = RefreshTrends::reportingPeriodFor($t3);
        $this->assertSame('12:00–18:00', $p3['windowCode']);
        $this->assertSame('2026-11-19 12:00:00', $p3['periodStart']);
        $this->assertSame('2026-11-19 18:00:00', $p3['periodEnd']);

        // Test 21:10 EAT -> 18:00–00:00
        $t4 = new DateTimeImmutable('2026-11-19 21:10:00', $tz);
        $p4 = RefreshTrends::reportingPeriodFor($t4);
        $this->assertSame('18:00–00:00', $p4['windowCode']);
        $this->assertSame('2026-11-19 18:00:00', $p4['periodStart']);
        $this->assertSame('2026-11-20 00:00:00', $p4['periodEnd']);
    }

    /**
 * Runs the test runs refresh and prevents duplicate processing behavior under test.
 * @return void Result of this operation.
 */
    public function testRunsRefreshAndPreventsDuplicateProcessing(): void
    {
        $db = $this->database();
        $http = static function (string $url): string {
            return json_encode(['articles' => [[
                'title' => 'East African milk and livestock bulletin',
                'url' => 'https://news.example.test/bulletin',
                'seendate' => '20261119T100000Z',
            ]]]);
        };
        $trends = new TrendsService($db, $http);
        $synth = new SyntheticTrendsService($db);
        $farm = new FarmMetricsService($db);

        $job = new RefreshTrends($db, $trends, $synth, $farm);

        $firstRun = $job->run();
        $this->assertSame('success', $firstRun['status']);
        $this->assertSame('success', $firstRun['liveStatus']);
        $this->assertSame('success', $firstRun['syntheticStatus']);

        // Second run without force should be skipped
        $secondRun = $job->run();
        $this->assertSame('skipped', $secondRun['status']);
        $this->assertStringContainsString('was already processed', $secondRun['message']);

        // With force, it executes again
        $forcedRun = $job->run(true);
        $this->assertSame('success', $forcedRun['status']);
    }

    /**
 * Runs the test detects and logs missed periods behavior under test.
 * @return void Result of this operation.
 */
    public function testDetectsAndLogsMissedPeriods(): void
    {
        $db = $this->database();
        // Insert an old log from yesterday
        $db->exec("INSERT INTO trend_refresh_logs (period_start, period_end, started_at, completed_at, live_status, synthetic_status, actual_farm_status)
                   VALUES ('2026-11-18 00:00:00', '2026-11-18 06:00:00', '2026-11-18 00:01:00', '2026-11-18 00:02:00', 'success', 'success', 'success')");

        $http = static function (string $url): string {
            return json_encode(['articles' => []]);
        };
        $job = new RefreshTrends($db, new TrendsService($db, $http), new SyntheticTrendsService($db), new FarmMetricsService($db));

        $job->run();

        $missedLogs = $db->query("SELECT COUNT(*) FROM trend_refresh_logs WHERE error_message LIKE '%Missed scheduled period%'")->fetchColumn();
        $this->assertGreaterThan(0, (int) $missedLogs);
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('
            CREATE TABLE trend_snapshots (snapshot_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at TEXT NOT NULL);
            CREATE TABLE trend_snapshot_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trend_type TEXT NOT NULL,
                geographic_scope TEXT NOT NULL,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uq_snap UNIQUE (trend_type, geographic_scope, period_start)
            );
            CREATE TABLE trend_refresh_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_start TEXT UNIQUE NOT NULL,
                period_end TEXT NOT NULL,
                started_at TEXT NOT NULL,
                completed_at TEXT,
                live_status TEXT NOT NULL,
                synthetic_status TEXT NOT NULL,
                actual_farm_status TEXT NOT NULL,
                error_message TEXT,
                attempt_count INTEGER DEFAULT 1
            );
            CREATE TABLE synthetic_trend_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_id INTEGER,
                period_start TEXT NOT NULL,
                metric_name TEXT NOT NULL,
                category TEXT NOT NULL,
                geographic_scope TEXT NOT NULL,
                metric_value REAL NOT NULL,
                previous_value REAL NOT NULL,
                change_percent REAL NOT NULL,
                unit TEXT NOT NULL,
                is_simulated INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');
        return $db;
    }
}
