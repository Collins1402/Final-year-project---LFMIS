<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\FarmMetricsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class FarmMetricsServiceTest extends TestCase
{
    public function testReturnsEmptyStateWithoutFabricationWhenNoRecordsExist(): void
    {
        $db = new PDO('sqlite::memory:');
        $service = new FarmMetricsService($db);

        $result = $service->summary();

        $this->assertSame(FarmMetricsService::DISCLAIMER, $result['disclaimer']);
        $this->assertFalse($result['hasData']);
        $this->assertSame(0.0, $result['metrics']['milk']['todayTotal']);
        $this->assertSame(0, $result['metrics']['livestock']['activeCount']);
        $this->assertSame(0.0, $result['metrics']['finance']['salesThisMonth']);
    }

    public function testCalculatesAccurateFarmMetricsFromGenuineRecords(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE farm_produce (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                produce_name TEXT,
                production_date TEXT,
                production_session TEXT,
                quantity_produced REAL
            );
            CREATE TABLE animals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                species TEXT,
                status TEXT
            );
            CREATE TABLE inventory_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                category TEXT,
                unit TEXT,
                quantity_on_hand REAL,
                reorder_level REAL,
                is_active INTEGER
            );
            CREATE TABLE sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                quantity REAL,
                unit_price REAL,
                sold_at TEXT
            );
            CREATE TABLE expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount REAL,
                expense_date TEXT
            );
        ");

        $today = date('Y-m-d');
        // Produce
        $db->exec("INSERT INTO farm_produce (produce_name, production_date, production_session, quantity_produced) VALUES ('Milk', '{$today}', 'morning', 45.5)");
        $db->exec("INSERT INTO farm_produce (produce_name, production_date, production_session, quantity_produced) VALUES ('Milk', '{$today}', 'evening', 32.0)");
        $db->exec("INSERT INTO farm_produce (produce_name, production_date, production_session, quantity_produced) VALUES ('Eggs', '{$today}', 'morning', 12.0)");

        // Animals
        $db->exec("INSERT INTO animals (species, status) VALUES ('cattle', 'active'), ('cattle', 'active'), ('goat', 'active')");

        // Inventory
        $db->exec("INSERT INTO inventory_items (name, category, unit, quantity_on_hand, reorder_level, is_active) VALUES ('Dairy Meal', 'feed', 'kg', 5, 20, 1)");

        // Finance
        $db->exec("INSERT INTO sales (quantity, unit_price, sold_at) VALUES (50, 60, '{$today} 10:00:00')");
        $db->exec("INSERT INTO expenses (amount, expense_date) VALUES (1500, '{$today}')");

        $service = new FarmMetricsService($db);
        $result = $service->summary();

        $this->assertTrue($result['hasData']);
        $this->assertSame(77.5, $result['metrics']['milk']['todayTotal']);
        $this->assertSame(45.5, $result['metrics']['milk']['morning']);
        $this->assertSame(32.0, $result['metrics']['milk']['evening']);
        $this->assertSame(12.0, $result['metrics']['eggs']['todayTotal']);
        $this->assertSame(3, $result['metrics']['livestock']['activeCount']);
        $this->assertSame(2, $result['metrics']['livestock']['bySpecies']['cattle']);
        $this->assertSame(1, $result['metrics']['inventory']['shortagesCount']);
        $this->assertSame(3000.0, $result['metrics']['finance']['salesThisMonth']);
        $this->assertSame(1500.0, $result['metrics']['finance']['expensesThisMonth']);
        $this->assertSame(1500.0, $result['metrics']['finance']['netMarginThisMonth']);
    }
}
