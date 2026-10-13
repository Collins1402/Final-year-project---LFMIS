<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\HealthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase
{
    public function testDueSoonIncludesOverdueOpenFollowUpsAndExcludesClosedOrInactiveOnes(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE animals (id INTEGER PRIMARY KEY, tag_number TEXT, name TEXT, status TEXT)");
        $db->exec("CREATE TABLE health_records (id INTEGER PRIMARY KEY, animal_id INTEGER, record_type TEXT, description TEXT, record_date TEXT, next_due_date TEXT)");
        $db->exec("INSERT INTO animals VALUES (1, 'C-1', 'Daisy', 'active'), (2, 'C-2', 'Bella', 'inactive')");

        $today = date('Y-m-d');
        $overdue = date('Y-m-d', strtotime('-1 day'));
        $oldRecordDate = date('Y-m-d', strtotime('-60 days'));
        $newRecordDate = date('Y-m-d', strtotime('-30 days'));
        $db->prepare('INSERT INTO health_records VALUES (?, ?, ?, ?, ?, ?)')->execute([1, 1, 'treatment', 'Old follow-up', $oldRecordDate, $oldRecordDate]);
        $db->prepare('INSERT INTO health_records VALUES (?, ?, ?, ?, ?, ?)')->execute([2, 1, 'treatment', 'Follow-up closed', $newRecordDate, null]);
        $db->prepare('INSERT INTO health_records VALUES (?, ?, ?, ?, ?, ?)')->execute([3, 1, 'vaccination', 'Overdue vaccine', $oldRecordDate, $overdue]);
        $db->prepare('INSERT INTO health_records VALUES (?, ?, ?, ?, ?, ?)')->execute([4, 2, 'vaccination', 'Inactive animal', $oldRecordDate, $today]);

        $records = (new HealthService($db))->dueSoon();

        $this->assertCount(1, $records);
        $this->assertSame(3, (int) $records[0]['id']);
        $this->assertSame('Overdue vaccine', $records[0]['description']);
    }
}