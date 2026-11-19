<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\DeliveryException;
use Lfmis\Services\ReminderService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReminderServiceTest extends TestCase
{
    public function testSelectsDueRecordsAndSendsEachReminderOnce(): void
    {
        $db = $this->database();
        $this->addHealthRecord($db, 1, '2026-03-10');
        $this->addHealthRecord($db, 2, '2026-03-11');
        $this->addHealthRecord($db, 3, '2026-03-12');
        $sent = [];
        $service = new ReminderService($db, static function (string $to, string $subject, string $body) use (&$sent): bool {
            $sent[] = [$to, $subject, $body];
            return true;
        });

        $first = $service->sendDue('2026-03-11');
        $second = $service->sendDue('2026-03-11');

        $this->assertSame(['selected' => 2, 'sent' => 2, 'failed' => 0], $first);
        $this->assertSame(['selected' => 0, 'sent' => 0, 'failed' => 0], $second);
        $this->assertCount(2, $sent);
        $this->assertSame(1, (int) $db->query('SELECT reminder_attempts FROM health_records WHERE id = 1')->fetchColumn());
        $this->assertNotNull($db->query('SELECT reminded_at FROM health_records WHERE id = 1')->fetchColumn());
        $this->assertSame(4, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testFailedEmailIsRetriedAndAttemptCountIncreases(): void
    {
        $db = $this->database();
        $this->addHealthRecord($db, 1, '2026-03-11');
        $sendCount = 0;
        $service = new ReminderService($db, static function () use (&$sendCount): bool {
            $sendCount++;
            if ($sendCount === 1) {
                throw new DeliveryException('SMTP unavailable.');
            }
            return true;
        });

        $first = $service->sendDue('2026-03-11');
        $second = $service->sendDue('2026-03-11');

        $this->assertSame(['selected' => 1, 'sent' => 0, 'failed' => 1], $first);
        $this->assertSame(['selected' => 1, 'sent' => 1, 'failed' => 0], $second);
        $this->assertSame(2, (int) $db->query('SELECT reminder_attempts FROM health_records WHERE id = 1')->fetchColumn());
        $this->assertNotNull($db->query('SELECT reminded_at FROM health_records WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE health_records (id INTEGER PRIMARY KEY, animal_id INTEGER, record_type TEXT, description TEXT, next_due_date TEXT, reminded_at TEXT, reminder_attempts INTEGER NOT NULL DEFAULT 0, reminder_error TEXT)');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, tag_number TEXT)');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, email TEXT, is_active INTEGER)');
        $db->exec('CREATE TABLE notifications (user_id INTEGER, type TEXT, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, dedupe_key TEXT, UNIQUE(user_id, dedupe_key))');
        $db->exec("INSERT INTO animals VALUES (1, 'COW-01')");
        $db->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'worker')");
        $db->exec("INSERT INTO users VALUES (7, 1, 'admin@example.test', 1), (8, 2, 'worker@example.test', 1)");
        return $db;
    }

    private function addHealthRecord(PDO $db, int $id, string $dueDate): void
    {
        $db->prepare("INSERT INTO health_records (id, animal_id, record_type, description, next_due_date) VALUES (?, 1, 'vaccination', 'Routine vaccine', ?)")->execute([$id, $dueDate]);
    }
}
