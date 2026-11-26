<?php
/**
 * File purpose: implements EventService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use DateTimeImmutable;
use PDO;

final class EventService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}

    /**
 * Performs the public list operation for this service.
 * @return array Result of this operation.
 */
    public function publicList(): array
    {
        return $this->db->query(
            "SELECT id, title, description, event_date, start_time, end_time, location, status
             FROM farm_events
             WHERE status IN ('published', 'cancelled') AND event_date >= CURRENT_DATE()
             ORDER BY event_date, start_time"
        )->fetchAll();
    }

    /**
 * Performs the admin list operation for this service.
 * @return array Result of this operation.
 */
    public function adminList(): array
    {
        return $this->db->query(
            'SELECT id, title, description, event_date, start_time, end_time, location, status
             FROM farm_events
             ORDER BY CASE WHEN event_date >= CURRENT_DATE() THEN 0 ELSE 1 END, event_date, start_time
             LIMIT 300'
        )->fetchAll();
    }

    /**
 * Validates input and creates a new record.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function create(array $input, int $userId): array
    {
        $event = $this->validate($input);
        $statement = $this->db->prepare(
            'INSERT INTO farm_events (title, description, event_date, start_time, end_time, location, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $event['title'], $event['description'], $event['eventDate'], $event['startTime'],
            $event['endTime'], $event['location'], $event['status'], $userId,
        ]);
        return $this->find((int) $this->db->lastInsertId());
    }

    /**
 * Validates input and updates the matching record.
 * @param int $id Input used by this operation.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
    public function update(int $id, array $input): array
    {
        $event = $this->validate($input);
        $statement = $this->db->prepare(
            'UPDATE farm_events SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?, status = ? WHERE id = ?'
        );
        $statement->execute([
            $event['title'], $event['description'], $event['eventDate'], $event['startTime'],
            $event['endTime'], $event['location'], $event['status'], $id,
        ]);
        return $this->find($id);
    }

    /**
 * Performs the set status operation for this service.
 * @param int $id Input used by this operation.
 * @param string $status Input used by this operation.
 * @return array Result of this operation.
 */
    public function setStatus(int $id, string $status): array
    {
        if (!in_array($status, ['draft', 'published', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Choose draft, published or cancelled.');
        }
        $statement = $this->db->prepare('UPDATE farm_events SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
        return $this->find($id);
    }

    /**
 * Performs the validate operation for this service.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
    private function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $eventDate = trim((string) ($input['eventDate'] ?? ''));
        $startTime = trim((string) ($input['startTime'] ?? ''));
        $endTime = trim((string) ($input['endTime'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if ($title === '' || strlen($title) > 600 || $description === '' || strlen($description) > 8000 || $location === '' || strlen($location) > 800) {
            throw new \InvalidArgumentException('Enter an event title, description and location within the allowed lengths.');
        }
        if (!$date || $date->format('Y-m-d') !== $eventDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Enter a valid event date.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime) || $startTime >= $endTime || $endTime > '16:00') {
            throw new \InvalidArgumentException('Events must have a valid same-day schedule and finish by 4:00 p.m.');
        }
        if (!in_array($status, ['draft', 'published', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Choose draft, published or cancelled.');
        }

        return compact('title', 'description', 'eventDate', 'startTime', 'endTime', 'location', 'status');
    }

    /**
 * Loads one record by its identifier.
 * @param int $id Input used by this operation.
 * @return array Result of this operation.
 */
    private function find(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id, title, description, event_date, start_time, end_time, location, status FROM farm_events WHERE id = ?'
        );
        $statement->execute([$id]);
        $event = $statement->fetch();
        if (!$event) {
            throw new \InvalidArgumentException('Event not found.');
        }
        $event['id'] = (int) $event['id'];
        return $event;
    }
}