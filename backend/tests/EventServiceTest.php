<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\EventService;
use PDO;
use PHPUnit\Framework\TestCase;

final class EventServiceTest extends TestCase
{
    public function testCreatesDaytimeEventThatEndsAtFour(): void
    {
        $service = $this->service();
        $event = $service->create($this->eventInput('16:00'), 7);

        $this->assertSame(1, $event['id']);
        $this->assertSame('draft', $event['status']);
        $this->assertSame('16:00', substr($event['end_time'], 0, 5));
    }

    public function testRejectsEventsEndingAfterFour(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create($this->eventInput('16:01'), 7);
    }

    public function testRejectsOvernightOrReversedTimes(): void
    {
        $input = $this->eventInput('10:00');
        $input['startTime'] = '15:30';

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create($input, 7);
    }

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

    private function service(): EventService
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE farm_events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL, event_date TEXT NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL, location TEXT NOT NULL, status TEXT NOT NULL, created_by INTEGER NULL)');
        return new EventService($db);
    }
}