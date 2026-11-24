<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AnimalService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnimalServiceTest extends TestCase
{
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
