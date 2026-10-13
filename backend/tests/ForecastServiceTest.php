<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\ForecastService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ForecastServiceTest extends TestCase
{
    public function testTrainingDataUsesLatestGapFreeRunAndExcludesToday(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 40, 30);
        $this->addProductionDays($db, 24, 1, true);
        $this->addProductionDays($db, 0, 0);

        $data = (new ForecastService($db))->trainingData();

        $this->assertCount(24, $data['observations']);
        $this->assertSame(date('Y-m-d', strtotime('-24 days')), $data['observations'][0]['date']);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $data['observations'][23]['date']);
        $this->assertTrue($data['includesSimulatedData']);
    }

    public function testReportRejectsHistoryShorterThanTwentyOneDays(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 19, 0);
        $requestCount = 0;
        $service = new ForecastService($db, static function () use (&$requestCount): array {
            $requestCount++;
            return [];
        });

        try {
            $service->report(1);
            $this->fail('Expected short production history to be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('At least 21 consecutive', $error->getMessage());
        }
        $this->assertSame(0, $requestCount);
    }

    public function testReportStoresAnIdenticalDailyBacktestOnlyOnce(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 21, 0);
        $service = new ForecastService($db, static function (string $url): array {
            if (str_ends_with($url, '/forecast')) {
                return [
                    'method' => 'Ridge regression, 7-day lags + weather',
                    'trainingDays' => 22,
                    'backtest' => ['days' => 8, 'maeLitres' => 6.4, 'rmseLitres' => 7.1, 'baselineMaeLitres' => 6.3],
                    'forecast' => [],
                    'evaluation' => [],
                ];
            }
            return ['checks' => []];
        });

        $service->report(4);
        $service->report(4);

        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM forecast_runs')->fetchColumn());
        $stored = $db->query('SELECT method, requested_by, uses_weather FROM forecast_runs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Ridge regression, 7-day lags + weather', $stored['method']);
        $this->assertSame(4, (int) $stored['requested_by']);
        $this->assertSame(1, (int) $stored['uses_weather']);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_produce (production_date TEXT, produce_name TEXT, quantity_produced REAL, unit TEXT, notes TEXT)');
        $db->exec('CREATE TABLE weather_observations (observation_date TEXT, rain_mm REAL, temp_c REAL)');
        $db->exec('CREATE TABLE farm_activities (status TEXT)');
        $db->exec('CREATE TABLE inventory_items (is_active INTEGER, quantity_on_hand REAL, reorder_level REAL)');
        $db->exec('CREATE TABLE animals (id INTEGER, status TEXT)');
        $db->exec('CREATE TABLE health_records (id INTEGER, animal_id INTEGER, record_type TEXT, record_date TEXT, next_due_date TEXT)');
        $db->exec('CREATE TABLE forecast_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, method TEXT, training_days INTEGER, backtest_days INTEGER, mae_litres REAL, rmse_litres REAL, baseline_mae_litres REAL, uses_weather INTEGER, notes TEXT, requested_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function addProductionDays(PDO $db, int $firstDaysAgo, int $lastDaysAgo, bool $simulated = false): void
    {
        $insert = $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?, ?)');
        for ($daysAgo = $firstDaysAgo; $daysAgo >= $lastDaysAgo; $daysAgo--) {
            $insert->execute([
                date('Y-m-d', strtotime('-' . $daysAgo . ' days')),
                'Milk',
                18 + $daysAgo / 10,
                'litres',
                $simulated ? 'SIMULATED DATA' : null,
            ]);
        }
    }
}