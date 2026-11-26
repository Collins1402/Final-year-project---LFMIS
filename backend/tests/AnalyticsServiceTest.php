<?php
/**
 * File purpose: proves the expected analyticsservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AnalyticsAccessException;
use Lfmis\Services\AnalyticsService;
use Lfmis\Services\AssistantService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnalyticsServiceTest extends TestCase
{
    private PDO $db;
    private AnalyticsService $analytics;

    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE farm_produce (id INTEGER PRIMARY KEY, produce_name TEXT, production_date TEXT, quantity_produced REAL, unit TEXT, notes TEXT)');
        $this->db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY, sold_at TEXT, total_amount REAL)');
        $this->db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, amount REAL)');
        $this->analytics = new AnalyticsService($this->db);
    }

    /**
 * Runs the test milk series calculates totals change and seven period average behavior under test.
 * @return void Result of this operation.
 */
    public function testMilkSeriesCalculatesTotalsChangeAndSevenPeriodAverage(): void
    {
        $today = new \DateTimeImmutable('today');
        $insert = $this->db->prepare('INSERT INTO farm_produce (produce_name, production_date, quantity_produced, unit, notes) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['milk', $today->modify('-1 day')->format('Y-m-d'), 20, 'litres', null]);
        $insert->execute(['milk', $today->modify('-3 days')->format('Y-m-d'), 10, 'litres', null]);
        $insert->execute(['milk', $today->modify('-8 days')->format('Y-m-d'), 10, 'litres', null]);
        $insert->execute(['milk', $today->format('Y-m-d'), 999, 'litres', 'SIMULATED DATA']);

        $chart = $this->analytics->series('milk', '7d', null, null, 'admin');

        self::assertCount(7, $chart['labels']);
        self::assertSame(30.0, $chart['stats']['total']);
        self::assertSame(200.0, $chart['stats']['changePct']);
        self::assertSame(round(30 / 7, 2), $chart['stats']['movingAvg7']);
        self::assertSame('up', $chart['stats']['trend']);
        self::assertNotSame(999.0, $chart['stats']['total']);
    }

    /**
 * Runs the test empty metric has honest no data result behavior under test.
 * @return void Result of this operation.
 */
    public function testEmptyMetricHasHonestNoDataResult(): void
    {
        $chart = $this->analytics->series('eggs', '30d');
        self::assertFalse($chart['hasData']);
        self::assertSame('no_data', $chart['stats']['trend']);
        self::assertNull($chart['stats']['total']);
        self::assertStringContainsString('No recorded data', $chart['message']);
    }

    /**
 * Runs the test unknown metric and period are rejected behavior under test.
 * @return void Result of this operation.
 */
    public function testUnknownMetricAndPeriodAreRejected(): void
    {
        try { $this->analytics->series('not_a_metric', '30d'); self::fail('Unknown metric accepted.'); }
        catch (\InvalidArgumentException $error) { self::assertStringContainsString('supported analytics metric', $error->getMessage()); }
        $this->expectException(\InvalidArgumentException::class);
        $this->analytics->series('milk', 'all-time');
    }

    /**
 * Runs the test role scopes block customer and worker finance charts behavior under test.
 * @return void Result of this operation.
 */
    public function testRoleScopesBlockCustomerAndWorkerFinanceCharts(): void
    {
        try { $this->analytics->series('milk', '30d', null, null, 'customer'); self::fail('Customer received farm analytics.'); }
        catch (AnalyticsAccessException) { self::assertTrue(true); }
        $this->expectException(AnalyticsAccessException::class);
        $this->analytics->series('sales', '30d', null, null, 'worker');
    }

    /**
 * Runs the test model supplied numbers cannot alter server computed chart data behavior under test.
 * @return void Result of this operation.
 */
    public function testModelSuppliedNumbersCannotAlterServerComputedChartData(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->db->prepare('INSERT INTO farm_produce (produce_name, production_date, quantity_produced, unit, notes) VALUES (?, ?, ?, ?, ?)')->execute(['milk', $today, 12, 'litres', null]);
        $caller = static fn(): array => ['metric' => 'milk', 'period' => '7d', 'chartType' => 'bar', 'datasets' => [['data' => [999999]]], 'total' => 999999, 'answer' => 'invented'];
        $result = (new AssistantService($this->db, $this->analytics, $caller))->answer('Show milk chart for the last 7 days', ['role' => 'admin']);
        self::assertSame(12.0, $result['charts'][0]['stats']['total']);
        self::assertNotSame([999999], $result['charts'][0]['datasets'][0]['data']);
        self::assertSame('bar', $result['charts'][0]['type']);
    }

    /**
 * Runs the test assistant does not return charts to customer or finance to worker behavior under test.
 * @return void Result of this operation.
 */
    public function testAssistantDoesNotReturnChartsToCustomerOrFinanceToWorker(): void
    {
        $assistant = new AssistantService($this->db, $this->analytics);
        $customer = $assistant->answer('Show milk chart', ['role' => 'customer']);
        self::assertSame([], $customer['charts']);
        self::assertStringContainsString('cannot access private farm operating records', $customer['answer']);
        $worker = $assistant->answer('Show sales chart', ['role' => 'worker']);
        self::assertSame([], $worker['charts']);
        self::assertStringContainsString('cannot access private farm operating records', $worker['answer']);
    }
}
