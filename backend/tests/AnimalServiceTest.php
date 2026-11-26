<?php
/**
 * File purpose: proves the expected animalservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AnimalService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnimalServiceTest extends TestCase
{
    /**
 * Runs the test duplicate tag returns clear conflict message behavior under test.
 * @return void Result of this operation.
 */
    public function testDuplicateTagReturnsClearConflictMessage(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_number TEXT NOT NULL UNIQUE, species TEXT NOT NULL, breed TEXT NULL, name TEXT NULL, sex TEXT NOT NULL, date_of_birth TEXT NULL, weight_kg REAL NULL, notes TEXT NULL, created_by INTEGER NULL, status TEXT NOT NULL DEFAULT "active", status_changed_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $service = new AnimalService($db);
        $animal = ['tagNumber' => 'SIM-C001', 'species' => 'cattle', 'sex' => 'female'];
        $service->create($animal, 1);
        try {
            $service->create($animal, 1);
            $this->fail('Expected duplicate animal tag to be rejected.');
        } catch (\DomainException $error) {
            $this->assertSame('Tag SIM-C001 is already registered.', $error->getMessage());
        }
    }
}
