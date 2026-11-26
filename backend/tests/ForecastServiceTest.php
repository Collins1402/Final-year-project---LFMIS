<?php
/**
 * File purpose: proves the expected forecastservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\ForecastService;
use Lfmis\Services\ForecastUnavailableException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ForecastServiceTest extends TestCase
{
    /**
 * Runs the test training data uses latest gap free run and excludes today behavior under test.
 * @return void Result of this operation.
 */
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
        $this->assertEquals(20.4, $data['observations'][0]['litres']);
        $this->assertTrue($data['includesSimulatedData']);
    }

    /**
 * Runs the test training data flags test data demo records as simulated behavior under test.
 * @return void Result of this operation.
 */
    public function testTrainingDataFlagsTestDataDemoRecordsAsSimulated(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 22, 1);
        $db->exec("UPDATE farm_produce SET notes = 'TEST DATA - demo record'");

        $data = (new ForecastService($db))->trainingData();

        $this->assertCount(22, $data['observations']);
        $this->assertTrue($data['includesSimulatedData']);
    }

    /**
 * Runs the test report rejects history shorter than twenty one days behavior under test.
 * @return void Result of this operation.
 */
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

    /**
 * Runs the test report surfaces unavailable ml service for the api to handle behavior under test.
 * @return void Result of this operation.
 */
    public function testReportSurfacesUnavailableMlServiceForTheApiToHandle(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 22, 1);
        $service = new ForecastService($db, static function (): array {
            throw new ForecastUnavailableException('Forecast service is unavailable.');
        });

        $this->expectException(ForecastUnavailableException::class);
        $service->report(1);
    }

    /**
 * Runs the test report stores an identical daily backtest only once behavior under test.
 * @return void Result of this operation.
 */
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

    /**
 * Runs the test scenario projects milk and eggs from additional animals behavior under test.
 * @return void Result of this operation.
 */
    public function testScenarioProjectsMilkAndEggsFromAdditionalAnimals(): void
    {
        $db = $this->database();
        $this->addProductionDays($db, 22, 1);
        $insertEggs = $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?, ?)');
        for ($daysAgo = 22; $daysAgo >= 1; $daysAgo--) {
            $insertEggs->execute([date('Y-m-d', strtotime('-' . $daysAgo . ' days')), 'Eggs', 4, 'trays', null]);
        }
        $db->exec("INSERT INTO animals VALUES (1, 'active', 'cattle'), (2, 'active', 'cattle'), (3, 'active', 'chicken'), (4, 'active', 'chicken'), (5, 'active', 'chicken'), (6, 'active', 'chicken'), (7, 'active', 'chicken')");
        $db->exec("INSERT INTO inventory_items (id, name, unit, category, is_active) VALUES (1, 'Dairy feed', 'kg', 'feed', 1)");
        $db->prepare('INSERT INTO inventory_transactions VALUES (1, ?, ?, ?)')->execute(['out', -70, date('Y-m-d', strtotime('-10 days'))]);
        $service = new ForecastService($db, static function (string $url, array $payload): array {
            $value = array_sum(array_column($payload['observations'], 'litres')) / count($payload['observations']);
            return ['forecast' => array_fill(0, 30, ['litres' => $value])];
        });

        $result = $service->scenario(2, 5);

        $this->assertSame(30, $result['horizonDays']);
        $this->assertSame('ready', $result['scenarios'][0]['status']);
        $this->assertSame(2, $result['scenarios'][0]['additionalAnimals']);
        $this->assertEquals($result['scenarios'][0]['baselineTotal'] * 2, $result['scenarios'][0]['estimatedTotal']);
        $this->assertSame('ready', $result['scenarios'][1]['status']);
        $this->assertEquals($result['scenarios'][1]['baselineTotal'] * 2, $result['scenarios'][1]['estimatedTotal']);
        $this->assertSame(70.0, $result['feedUse'][0]['recordedUse30Days']);
        $this->assertSame(140.0, $result['feedUse'][0]['estimatedUseWithAddedAnimals']);
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_produce (production_date TEXT, produce_name TEXT, quantity_produced REAL, unit TEXT, notes TEXT)');
        $db->exec('CREATE TABLE weather_observations (observation_date TEXT, rain_mm REAL, temp_c REAL)');
        $db->exec('CREATE TABLE farm_activities (status TEXT)');
        $db->exec('CREATE TABLE inventory_items (id INTEGER, name TEXT, unit TEXT, category TEXT, is_active INTEGER, quantity_on_hand REAL, reorder_level REAL)');
        $db->exec('CREATE TABLE animals (id INTEGER, status TEXT, species TEXT)');
        $db->exec('CREATE TABLE inventory_transactions (item_id INTEGER, transaction_type TEXT, quantity_change REAL, transaction_date TEXT)');
        $db->exec('CREATE TABLE health_records (id INTEGER, animal_id INTEGER, record_type TEXT, record_date TEXT, next_due_date TEXT)');
        $db->exec('CREATE TABLE forecast_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, method TEXT, training_days INTEGER, backtest_days INTEGER, mae_litres REAL, rmse_litres REAL, baseline_mae_litres REAL, uses_weather INTEGER, notes TEXT, requested_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    /**
 * Handles the add production days operation for the API router.
 * @param mixed $db Value supplied to this operation.
 * @param mixed $firstDaysAgo Value supplied to this operation.
 * @param mixed $lastDaysAgo Value supplied to this operation.
 * @param mixed $simulated Value supplied to this operation.
 * @return void Result of this operation.
 */
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