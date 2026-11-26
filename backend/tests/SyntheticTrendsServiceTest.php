<?php
/**
 * File purpose: proves the expected synthetictrendsservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\SyntheticTrendsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SyntheticTrendsServiceTest extends TestCase
{
    /**
 * Runs the test generates synthetic indicators for three scopes behavior under test.
 * @return void Result of this operation.
 */
    public function testGeneratesSyntheticIndicatorsForThreeScopes(): void
    {
        $db = $this->database();
        $service = new SyntheticTrendsService($db);

        $result = $service->generate('2026-11-19 12:00:00', 42);

        $this->assertSame(SyntheticTrendsService::DISCLAIMER, $result['disclaimer']);
        $this->assertArrayHasKey('kenya', $result['scopes']);
        $this->assertArrayHasKey('east_africa', $result['scopes']);
        $this->assertArrayHasKey('global', $result['scopes']);

        $kenya = $result['scopes']['kenya']['categories'];
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_DAIRY, $kenya);
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_POULTRY, $kenya);
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_LIVESTOCK, $kenya);
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_ANIMAL_FEED, $kenya);
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_FARM_ECONOMY, $kenya);
        $this->assertArrayHasKey(SyntheticTrendsService::CATEGORY_PRODUCTION, $kenya);

        // Verify indicative milk price
        $milkPrice = $kenya[SyntheticTrendsService::CATEGORY_DAIRY]['indicative_milk_price_kes'];
        $this->assertSame('KES/L', $milkPrice['unit']);
        $this->assertGreaterThan(40.0, $milkPrice['metricValue']);
        $this->assertLessThan(85.0, $milkPrice['metricValue']);
        $this->assertTrue($milkPrice['isSimulated']);
        $this->assertSame(SyntheticTrendsService::DISCLAIMER, $milkPrice['disclaimer']);
    }

    /**
 * Runs the test reproducible generation with seed behavior under test.
 * @return void Result of this operation.
 */
    public function testReproducibleGenerationWithSeed(): void
    {
        $db1 = $this->database();
        $service1 = new SyntheticTrendsService($db1);
        $run1 = $service1->generate('2026-11-19 12:00:00', 12345);

        $db2 = $this->database();
        $service2 = new SyntheticTrendsService($db2);
        $run2 = $service2->generate('2026-11-19 12:00:00', 12345);

        $val1 = $run1['scopes']['kenya']['categories']['dairy']['milk_demand_index']['metricValue'];
        $val2 = $run2['scopes']['kenya']['categories']['dairy']['milk_demand_index']['metricValue'];

        $this->assertSame($val1, $val2);
    }

    /**
 * Runs the test active simulated events are labelled and affect indicators behavior under test.
 * @return void Result of this operation.
 */
    public function testActiveSimulatedEventsAreLabelledAndAffectIndicators(): void
    {
        $db = $this->database();
        $service = new SyntheticTrendsService($db);

        $events = $service->activeEvents('2026-11-19 12:00:00', 10);
        $this->assertNotEmpty($events);

        foreach ($events as $ev) {
            $this->assertSame(SyntheticTrendsService::EVENT_DISCLAIMER, $ev['sourceLabel']);
            $this->assertNotEmpty($ev['name']);
            $this->assertNotEmpty($ev['affectedIndicators']);
        }
    }

    /**
 * Runs the test percentage change calculation behavior under test.
 * @return void Result of this operation.
 */
    public function testPercentageChangeCalculation(): void
    {
        $db = $this->database();
        $service = new SyntheticTrendsService($db);

        // First run
        $service->generate('2026-11-19 06:00:00', 100);

        // Second run with a shifted seed
        $run2 = $service->generate('2026-11-19 12:00:00', 200);

        $metric = $run2['scopes']['kenya']['categories']['dairy']['dairy_feed_cost_index'];
        $expectedChange = round((($metric['metricValue'] - $metric['previousValue']) / $metric['previousValue']) * 100.0, 2);

        $this->assertSame($expectedChange, $metric['changePercent']);
    }

    /**
 * Runs the test history metrics returns time series behavior under test.
 * @return void Result of this operation.
 */
    public function testHistoryMetricsReturnsTimeSeries(): void
    {
        $db = $this->database();
        $service = new SyntheticTrendsService($db);

        $service->generate('2026-11-19 00:00:00', 1);
        $service->generate('2026-11-19 06:00:00', 2);
        $service->generate('2026-11-19 12:00:00', 3);

        $history = $service->historyMetrics('kenya', 'dairy', 'milk_demand_index');
        $this->assertCount(1, $history);
        $this->assertSame('milk_demand_index', $history[0]['metricName']);
        $this->assertCount(3, $history[0]['points']);
        $this->assertSame(SyntheticTrendsService::DISCLAIMER, $history[0]['disclaimer']);
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE synthetic_trend_metrics (
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
        )');
        return $db;
    }
}
