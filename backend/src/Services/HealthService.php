<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class HealthService
{
    public function __construct(private PDO $db)
    {
    }

    public function list(): array
    {
        return $this->db->query(
            'SELECT health_records.id, health_records.animal_id, animals.tag_number, animals.name AS animal_name,
                    health_records.record_type, health_records.description, health_records.medication,
                    health_records.dosage, health_records.cost, health_records.record_date, health_records.next_due_date
             FROM health_records JOIN animals ON animals.id = health_records.animal_id
             ORDER BY health_records.record_date DESC, health_records.id DESC'
        )->fetchAll();
    }

    public function dueSoon(int $days = 7): array
    {
        $statement = $this->db->prepare(
            'SELECT health_records.id, health_records.animal_id, animals.tag_number, animals.name AS animal_name,
                    health_records.record_type, health_records.description, health_records.next_due_date
             FROM health_records JOIN animals ON animals.id = health_records.animal_id
             WHERE health_records.next_due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
             ORDER BY health_records.next_due_date'
        );
        $statement->execute([$days]);
        return $statement->fetchAll();
    }

    public function create(array $input, int $userId): array
    {
        $animalId = filter_var($input['animalId'] ?? null, FILTER_VALIDATE_INT);
        $type = (string) ($input['recordType'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        $recordDate = $this->date((string) ($input['recordDate'] ?? ''));
        $dueDate = isset($input['nextDueDate']) && trim((string) $input['nextDueDate']) !== '' ? $this->date((string) $input['nextDueDate']) : null;
        if (!$animalId || !in_array($type, ['vaccination', 'treatment', 'deworming', 'checkup', 'illness', 'other'], true) || $description === '') {
            throw new \InvalidArgumentException('Enter an animal, record type and description.');
        }
        if ($dueDate !== null && $dueDate < $recordDate) {
            throw new \InvalidArgumentException('The next due date cannot be before the record date.');
        }
        $check = $this->db->prepare('SELECT COUNT(*) FROM animals WHERE id = ?');
        $check->execute([$animalId]);
        if ((int) $check->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Animal not found.');
        }
        $statement = $this->db->prepare(
            'INSERT INTO health_records (animal_id, record_type, description, medication, dosage, cost, record_date, next_due_date, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $animalId, $type, $description, $this->textOrNull($input['medication'] ?? null), $this->textOrNull($input['dosage'] ?? null),
            isset($input['cost']) && $input['cost'] !== '' ? (float) $input['cost'] : null, $recordDate, $dueDate, $userId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $result = $this->db->prepare('SELECT * FROM health_records WHERE id = ?');
        $result->execute([$id]);
        return $result->fetch();
    }

    private function date(string $value): string
    {
        $date = date_create($value);
        if (!$date) {
            throw new \InvalidArgumentException('Health record dates must be valid dates.');
        }
        return $date->format('Y-m-d');
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : substr($text, 0, 120);
    }
}
