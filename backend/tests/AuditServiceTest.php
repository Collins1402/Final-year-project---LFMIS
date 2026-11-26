<?php
/**
 * File purpose: proves the expected auditservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditServiceTest extends TestCase
{
    /**
 * Runs the test records and filters audit events behavior under test.
 * @return void Result of this operation.
 */
    public function testRecordsAndFiltersAuditEvents(): void
    {
        $db = $this->database();
        $service = new AuditService($db);
        $service->record(7, 'user_created', 'user', 12, ['role' => 'worker'], '203.0.113.8');
        $service->record(null, 'sign_in_failed', 'auth', null, ['reason' => 'invalid_credentials'], '203.0.113.9');

        $today = gmdate('Y-m-d');
        $records = $service->list(['userId' => '7', 'action' => 'user_', 'from' => $today, 'to' => $today]);

        $this->assertCount(1, $records);
        $this->assertSame('Farm Admin', $records[0]['user_name']);
        $this->assertSame('user_created', $records[0]['action']);
        $this->assertSame('{"role":"worker"}', $records[0]['details']);
        $this->assertSame('203.0.113.8', $records[0]['ip_address']);
    }

    /**
 * Runs the test rejects invalid audit filters behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsInvalidAuditFilters(): void
    {
        $service = new AuditService($this->database());

        $this->expectException(\InvalidArgumentException::class);
        $service->list(['userId' => '1 OR 1=1']);
    }

    /**
 * Runs the test record rejects invalid event names behavior under test.
 * @return void Result of this operation.
 */
    public function testRecordRejectsInvalidEventNames(): void
    {
        $service = new AuditService($this->database());

        $this->expectException(\InvalidArgumentException::class);
        $service->record(7, str_repeat('x', 41), 'user');
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)');
        $db->exec("INSERT INTO users VALUES (7, 'Farm Admin')");
        $db->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }
}
