<?php
/**
 * File purpose: proves the expected eventservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\EventService;
use PDO;
use PHPUnit\Framework\TestCase;

final class EventServiceTest extends TestCase
{
    /**
 * Runs the test creates daytime event that ends at four behavior under test.
 * @return void Result of this operation.
 */
    public function testCreatesDaytimeEventThatEndsAtFour(): void
    {
        $service = $this->service();
        $event = $service->create($this->eventInput('16:00'), 7);

        $this->assertSame(1, $event['id']);
        $this->assertSame('draft', $event['status']);
        $this->assertSame('16:00', substr($event['end_time'], 0, 5));
    }

    /**
 * Runs the test rejects events ending after four behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsEventsEndingAfterFour(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create($this->eventInput('16:01'), 7);
    }

    /**
 * Runs the test rejects overnight or reversed times behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsOvernightOrReversedTimes(): void
    {
        $input = $this->eventInput('10:00');
        $input['startTime'] = '15:30';

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create($input, 7);
    }

    /**
 * Handles the event input operation for the API router.
 * @param mixed $endTime Value supplied to this operation.
 * @return array Result of this operation.
 */
    private function eventInput(string $endTime): array
    {
        return [
            'title' => 'Farm tour',
            'description' => 'A daytime visit to the farm.',
            'eventDate' => '2026-11-01',
            'startTime' => '10:00',
            'endTime' => $endTime,
            'location' => 'Flouri Farm',
        ];
    }

    /**
 * Handles the service operation for the API router.
 * @return EventService Result of this operation.
 */
    private function service(): EventService
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL, event_date TEXT NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL, location TEXT NOT NULL, status TEXT NOT NULL, created_by INTEGER NULL)');
        return new EventService($db);
    }
}