<?php
/**
 * File purpose: proves the expected overviewservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\OverviewService;
use PDO;
use PHPUnit\Framework\TestCase;

final class OverviewServiceTest extends TestCase
{
    /**
 * Runs the test summary aggregates farm and business indicators behavior under test.
 * @return void Result of this operation.
 */
    public function testSummaryAggregatesFarmAndBusinessIndicators(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_produce (produce_name TEXT, production_date TEXT, production_session TEXT, quantity_produced REAL, unit TEXT)');
        $db->exec('CREATE TABLE animals (id INTEGER, species TEXT, status TEXT)');
        $db->exec('CREATE TABLE health_records (id INTEGER, animal_id INTEGER, record_type TEXT, record_date TEXT, next_due_date TEXT)');
        $db->exec('CREATE TABLE farm_activities (status TEXT)');
        $db->exec('CREATE TABLE inventory_items (is_active INTEGER, quantity_on_hand REAL, reorder_level REAL)');
        $db->exec('CREATE TABLE orders (status TEXT)');
        $db->exec('CREATE TABLE sales (total_amount REAL, sold_at TEXT)');
        $db->exec('CREATE TABLE expenses (amount REAL, expense_date TEXT)');
        $today = date('Y-m-d');
        $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?, ?)')->execute(['Milk', $today, 'morning', 12.5, 'litres']);
        $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?, ?)')->execute(['Milk', $today, 'evening', 8, 'litres']);
        $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?, ?)')->execute(['Eggs', $today, null, 4, 'trays']);
        $db->exec("INSERT INTO animals VALUES (1, 'cattle', 'active'), (2, 'goat', 'active'), (3, 'goat', 'sold')");
        $db->exec("INSERT INTO farm_activities VALUES ('planned'), ('completed')");
        $db->exec('INSERT INTO inventory_items VALUES (1, 2, 5), (1, 10, 5)');
        $db->exec("INSERT INTO orders VALUES ('pending'), ('quoted'), ('fulfilled')");
        $db->prepare('INSERT INTO sales VALUES (?, ?)')->execute([100, $today . ' 10:00:00']);
        $db->prepare('INSERT INTO expenses VALUES (?, ?)')->execute([25, $today]);

        $result = (new OverviewService($db))->summary();

        $this->assertSame(20.5, $result['milkToday']['morning'] + $result['milkToday']['evening']);
        $this->assertSame(4.0, $result['eggsToday']);
        $this->assertSame(2, $result['activeAnimals']);
        $this->assertSame(1, $result['openTasks']);
        $this->assertSame(1, $result['lowStockCount']);
        $this->assertSame(2, $result['pendingOrders']);
        $this->assertSame(100.0, $result['salesThisMonth']);
        $this->assertSame(25.0, $result['expensesThisMonth']);
    }
}