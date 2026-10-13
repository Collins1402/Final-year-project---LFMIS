<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\ReportService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReportServiceTest extends TestCase
{
    public function testReturnsTwelveMonthlyProductionAndFinancePeriods(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE sales (sold_at TEXT, total_amount REAL)');
        $db->exec('CREATE TABLE expenses (expense_date TEXT, amount REAL)');
        $db->exec('CREATE TABLE farm_produce (production_date TEXT, produce_name TEXT, unit TEXT, quantity_produced REAL)');
        $db->exec('CREATE TABLE health_records (id INTEGER, animal_id INTEGER, record_type TEXT, record_date TEXT, next_due_date TEXT)');
        $db->exec('CREATE TABLE animals (id INTEGER, status TEXT)');
        $today = date('Y-m-d');
        $db->prepare('INSERT INTO sales VALUES (?, ?)')->execute([$today . ' 10:00:00', 150]);
        $db->prepare('INSERT INTO expenses VALUES (?, ?)')->execute([$today, 50]);
        $db->prepare('INSERT INTO farm_produce VALUES (?, ?, ?, ?)')->execute([$today, 'Milk', 'litres', 22]);

        $summary = (new ReportService($db))->summary();
        $currentMonth = $summary['months'][11];

        $this->assertCount(12, $summary['months']);
        $this->assertSame(date('Y-m'), $currentMonth['month']);
        $this->assertSame(150.0, $currentMonth['sales']);
        $this->assertSame(50.0, $currentMonth['expenses']);
        $this->assertSame(100.0, $currentMonth['net']);
        $this->assertSame(22.0, $currentMonth['production']['Milk (litres)']);
    }
}