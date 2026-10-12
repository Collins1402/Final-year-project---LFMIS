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

    private function service(): ProductionService
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_produce (id INTEGER PRIMARY KEY AUTOINCREMENT, produce_name TEXT NOT NULL, production_date TEXT NOT NULL, production_session TEXT NULL, quantity_produced REAL NOT NULL, quantity_sold REAL NOT NULL, quantity_damaged REAL NOT NULL, unit TEXT NOT NULL, notes TEXT NULL, recorded_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return new ProductionService($db);
    }
}