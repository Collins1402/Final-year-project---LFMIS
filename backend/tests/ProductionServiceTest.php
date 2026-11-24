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

    public function testRejectsDuplicateMilkAndReturnsExistingRecordForEditing(): void
    {
        $service = $this->service();
        $input = ['produceName' => 'Milk', 'productionDate' => date('Y-m-d'), 'session' => 'morning', 'animalId' => '1', 'quantityProduced' => '12', 'unit' => 'litres'];
        $service->record($input, 7);
        try {
            $service->record($input, 7);
            $this->fail('Expected duplicate production to be rejected.');
        } catch (\Lfmis\Services\DuplicateProductionException $error) {
            $this->assertStringContainsString('Edit the existing record', $error->getMessage());
            $this->assertSame(1, (int) $error->existingRecord['id']);
        }
        $updated = $service->update(1, array_merge($input, ['quantityProduced' => '13']), 7);
        $this->assertSame(1, $updated['id']);
        $this->assertEquals(13, $service->records()[0]['quantity_produced']);
    }

    public function testRejectsDuplicateEggsForSameDateAndSession(): void
    {
        $service = $this->service();
        $input = ['produceName' => 'Eggs', 'productionDate' => date('Y-m-d'), 'session' => '', 'quantityProduced' => '20', 'unit' => 'trays'];
        $service->record($input, 7);
        $this->expectException(\Lfmis\Services\DuplicateProductionException::class);
        $service->record($input, 7);
    }

    public function testWarnsAndDoesNotSaveImplausibleMilkAmount(): void
    {
        $db = $this->database();
        $service = new ProductionService($db);
        try {
            $service->record(['produceName' => 'Milk', 'productionDate' => date('Y-m-d'), 'session' => 'morning', 'quantityProduced' => '5000', 'unit' => 'litres'], 7);
            $this->fail('Expected an implausible amount warning.');
        } catch (\Lfmis\Services\ProductionPlausibilityWarning $warning) {
            $this->assertStringContainsString('not saved', $warning->getMessage());
        }
        $this->assertCount(0, $service->records());
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
        $db->exec('ALTER TABLE farm_produce ADD COLUMN animal_id INTEGER NULL');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, tag_number TEXT NOT NULL UNIQUE)');
        $db->exec("INSERT INTO animals (id, tag_number) VALUES (1, 'SIM-C001')");
        $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, production_kind TEXT, available_quantity REAL, is_active INTEGER)');
        return $db;
    }
}
