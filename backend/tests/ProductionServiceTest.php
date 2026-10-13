<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\ProductionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProductionServiceTest extends TestCase
{
    public function testRecordsGenericProduceAndUnitsSold(): void
    {
        $service = $this->service();
        $date = date('Y-m-d');

        $result = $service->record([
            'produceName' => 'Eggs',
            'productionDate' => $date,
            'session' => '',
            'quantityProduced' => '120',
            'quantitySold' => '48',
            'quantityDamaged' => '2',
            'unit' => 'trays',
        ], 7);

        $this->assertSame(1, $result['id']);
        $record = $service->records()[0];
        $this->assertSame('Eggs', $record['produce_name']);
        $this->assertEquals(120, $record['quantity_produced']);
        $this->assertEquals(48, $record['quantity_sold']);
        $this->assertSame('trays', $record['unit']);
    }

    public function testRejectsSoldAndDamagedQuantitiesAboveProduction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->record([
            'produceName' => 'Milk',
            'productionDate' => date('Y-m-d'),
            'quantityProduced' => '10',
            'quantitySold' => '8',
            'quantityDamaged' => '3',
            'unit' => 'litres',
        ], 7);
    }

    public function testSummaryGroupsRecentDailyAndMonthToDateProduction(): void
    {
        $db = $this->database();
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $insert = $db->prepare('INSERT INTO farm_produce (produce_name, production_date, production_session, quantity_produced, quantity_sold, quantity_damaged, unit) VALUES (?, ?, ?, ?, 0, 0, ?)');
        $insert->execute(['Milk', $today, 'morning', 12, 'litres']);
        $insert->execute(['Milk', $yesterday, 'evening', 8, 'litres']);
        $insert->execute(['Eggs', $today, null, 5, 'trays']);

        $summary = (new ProductionService($db))->summary();

        $this->assertSame(20.0, $summary['weeklyTotals']['Milk (litres)']['produced']);
        $this->assertSame(12.0, $summary['dailyTotals'][$today]['Milk (litres)']['produced']);
        $this->assertSame(5.0, $summary['monthlyTotals']['Eggs (trays)']['produced']);
    }

    private function service(): ProductionService
    {
        return new ProductionService($this->database());
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_produce (id INTEGER PRIMARY KEY AUTOINCREMENT, produce_name TEXT NOT NULL, production_date TEXT NOT NULL, production_session TEXT NULL, quantity_produced REAL NOT NULL, quantity_sold REAL NOT NULL, quantity_damaged REAL NOT NULL, unit TEXT NOT NULL, notes TEXT NULL, recorded_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, production_kind TEXT, available_quantity REAL, is_active INTEGER)');
        return $db;
    }
}