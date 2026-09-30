<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ProductionService
{
    public function __construct(private PDO $db) {}

    public function milk(): array
    {
        return $this->db->query('SELECT id, production_date, milking_session, animal_id, quantity_litres, notes FROM milk_production ORDER BY production_date DESC, milking_session DESC LIMIT 200')->fetchAll();
    }

    public function eggs(): array
    {
        return $this->db->query('SELECT id, production_date, eggs_collected, eggs_broken, notes FROM egg_production ORDER BY production_date DESC LIMIT 200')->fetchAll();
    }

    public function recordMilk(array $input, int $userId): array
    {
        $date = $this->date($input['productionDate'] ?? '');
        $session = (string) ($input['session'] ?? '');
        $quantity = filter_var($input['quantityLitres'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!in_array($session, ['morning', 'evening'], true) || $quantity === false || $quantity < 0 || $date > date('Y-m-d')) {
            throw new \InvalidArgumentException('Enter a valid past or current production date, session and quantity.');
        }
        $statement = $this->db->prepare('INSERT INTO milk_production (production_date, milking_session, quantity_litres, notes, recorded_by) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$date, $session, $quantity, trim((string) ($input['notes'] ?? '')) ?: null, $userId]);
        return ['id' => (int) $this->db->lastInsertId(), 'date' => $date];
    }

    public function recordEggs(array $input, int $userId): array
    {
        $date = $this->date($input['productionDate'] ?? '');
        $collected = filter_var($input['eggsCollected'] ?? null, FILTER_VALIDATE_INT);
        $broken = filter_var($input['eggsBroken'] ?? 0, FILTER_VALIDATE_INT);
        if ($collected === false || $collected < 0 || $broken === false || $broken < 0 || $broken > $collected || $date > date('Y-m-d')) {
            throw new \InvalidArgumentException('Enter valid egg counts and a past or current date.');
        }
        $statement = $this->db->prepare('INSERT INTO egg_production (production_date, eggs_collected, eggs_broken, notes, recorded_by) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$date, $collected, $broken, trim((string) ($input['notes'] ?? '')) ?: null, $userId]);
        return ['id' => (int) $this->db->lastInsertId(), 'date' => $date];
    }

    private function date(mixed $value): string
    {
        $date = date_create((string) $value);
        if (!$date) throw new \InvalidArgumentException('Production date must be valid.');
        return $date->format('Y-m-d');
    }
}
