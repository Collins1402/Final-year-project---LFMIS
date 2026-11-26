<?php
/**
 * File purpose: proves the expected healthservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\HealthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase
{
    /**
 * Runs the test due soon includes overdue open follow ups and excludes closed or inactive ones behavior under test.
 * @return void Result of this operation.
 */
    public function testDueSoonIncludesOverdueOpenFollowUpsAndExcludesClosedOrInactiveOnes(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE animals (id INTEGER PRIMARY KEY, tag_number TEXT, species TEXT, name TEXT, status TEXT)");
        $db->exec("CREATE TABLE health_records (id INTEGER PRIMARY KEY, animal_id INTEGER, record_type TEXT, description TEXT, medication TEXT, dosage TEXT, cost REAL, record_date TEXT, next_due_date TEXT)");
        $db->exec("INSERT INTO animals VALUES (1, 'C-1', 'cattle', 'Daisy', 'active'), (2, 'C-2', 'goat', 'Bella', 'inactive')");

        $today = date('Y-m-d');
        $overdue = date('Y-m-d', strtotime('-1 day'));
        $oldRecordDate = date('Y-m-d', strtotime('-60 days'));
        $newRecordDate = date('Y-m-d', strtotime('-30 days'));
        $insert = $db->prepare('INSERT INTO health_records (id, animal_id, record_type, description, record_date, next_due_date) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 1, 'treatment', 'Old follow-up', $oldRecordDate, $oldRecordDate]);
        $insert->execute([2, 1, 'treatment', 'Follow-up closed', $newRecordDate, null]);
        $insert->execute([3, 1, 'vaccination', 'Overdue vaccine', $oldRecordDate, $overdue]);
        $insert->execute([4, 2, 'vaccination', 'Inactive animal', $oldRecordDate, $today]);

        $records = (new HealthService($db))->dueSoon();

        $this->assertCount(1, $records);
        $this->assertSame(3, (int) $records[0]['id']);
        $this->assertSame('Overdue vaccine', $records[0]['description']);
        $listedRecords = (new HealthService($db))->list();
        $this->assertSame('cattle', $listedRecords[2]['species']);
    }
}