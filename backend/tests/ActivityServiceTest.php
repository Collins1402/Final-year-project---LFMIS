<?php
/**
 * File purpose: proves the expected activityservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\ActivityService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ActivityServiceTest extends TestCase
{
    /**
 * Runs the test health concern creates issue and notifies admin and veterinarian behavior under test.
 * @return void Result of this operation.
 */
    public function testHealthConcernCreatesIssueAndNotifiesAdminAndVeterinarian(): void
    {
        $db = $this->database();

        $activity = (new ActivityService($db))->create($this->input([
            'healthConcern' => '1',
            'animalId' => '1',
            'symptoms' => 'Not eating and low energy',
        ]), 20);

        $this->assertSame(1, (int) $activity['health_record_id']);
        $record = $db->query('SELECT animal_id, record_type, description, recorded_by FROM health_records')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $record['animal_id']);
        $this->assertSame('issue', $record['record_type']);
        $this->assertSame('Not eating and low energy', $record['description']);
        $this->assertSame(20, (int) $record['recorded_by']);
        $this->assertSame([7, 9], array_map('intval', $db->query('SELECT user_id FROM notifications ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
 * Runs the test health concern requires animal and symptoms behavior under test.
 * @return void Result of this operation.
 */
    public function testHealthConcernRequiresAnimalAndSymptoms(): void
    {
        $db = $this->database();
        $input = $this->input(['healthConcern' => '1', 'animalId' => '', 'symptoms' => '']);

        try {
            (new ActivityService($db))->create($input, 20);
            $this->fail('A health concern without symptoms or an animal should be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('Choose an animal', $error->getMessage());
        }
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM farm_activities')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM health_records')->fetchColumn());
    }

    /**
 * Runs the test ordinary activity does not create health record or expose concern notifications behavior under test.
 * @return void Result of this operation.
 */
    public function testOrdinaryActivityDoesNotCreateHealthRecordOrExposeConcernNotifications(): void
    {
        $db = $this->database();

        (new ActivityService($db))->create($this->input(['animalId' => '1']), 20);

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM health_records')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    /**
 * Handles the input operation for the API router.
 * @param mixed $overrides Value supplied to this operation.
 * @return array Result of this operation.
 */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'activityType' => 'feeding',
            'description' => 'Morning feeding',
            'activityAt' => '2026-11-19T07:30',
            'animalId' => '',
            'healthConcern' => false,
            'symptoms' => '',
        ], $overrides);
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE farm_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, activity_type TEXT, description TEXT, animal_id INTEGER, assigned_to INTEGER, activity_at TEXT, status TEXT DEFAULT "planned", completed_at TEXT, created_by INTEGER)');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, tag_number TEXT, status TEXT)');
        $db->exec('CREATE TABLE health_records (id INTEGER PRIMARY KEY AUTOINCREMENT, animal_id INTEGER, record_type TEXT, description TEXT, record_date TEXT, recorded_by INTEGER)');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, is_active INTEGER)');
        $db->exec('CREATE TABLE notifications (user_id INTEGER, type TEXT, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, dedupe_key TEXT, UNIQUE(user_id, dedupe_key))');
        $db->exec("INSERT INTO animals VALUES (1, 'COW-01', 'active'), (2, 'COW-02', 'sold')");
        $db->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'worker'), (3, 'veterinarian')");
        $db->exec('INSERT INTO users VALUES (7, 1, 1), (8, 2, 1), (9, 3, 1)');
        return $db;
    }
}
