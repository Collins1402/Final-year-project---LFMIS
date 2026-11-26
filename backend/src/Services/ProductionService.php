<?php
/**
 * File purpose: implements ProductionService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ProductionService
{
    private const PLAUSIBILITY_LIMITS = ['milk_litres' => 40.0, 'egg_trays' => 150.0];

    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}

    /**
 * Performs the records operation for this service.
 * @return array Result of this operation.
 */
    public function records(): array
    {
        return $this->db->query('SELECT id, produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes FROM farm_produce ORDER BY production_date DESC, id DESC LIMIT 300')->fetchAll();
    }

    /**
 * Performs the summary operation for this service.
 * @return array Result of this operation.
 */
    public function summary(): array
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $monthStart = date('Y-m-01');
        $rangeStart = min($weekStart, $monthStart);
        $statement = $this->db->prepare('SELECT production_date, produce_name, unit, SUM(quantity_produced) AS produced, SUM(quantity_sold) AS sold, SUM(quantity_damaged) AS damaged FROM farm_produce WHERE production_date BETWEEN ? AND ? GROUP BY production_date, produce_name, unit ORDER BY production_date, produce_name');
        $statement->execute([$rangeStart, $today]);
        $weekly = $monthly = $daily = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['produce_name'] . ' (' . $row['unit'] . ')';
            $values = ['produced' => (float) $row['produced'], 'sold' => (float) $row['sold'], 'damaged' => (float) $row['damaged']];
            if ($row['production_date'] >= $weekStart) {
                $weekly[$key] = $this->sumAmounts($weekly[$key] ?? ['produced' => 0, 'sold' => 0, 'damaged' => 0], $values);
                $daily[$row['production_date']][$key] = $values;
            }
            if ($row['production_date'] >= $monthStart) $monthly[$key] = $this->sumAmounts($monthly[$key] ?? ['produced' => 0, 'sold' => 0, 'damaged' => 0], $values);
        }
        return ['weekStart' => $weekStart, 'today' => $today, 'weeklyTotals' => $weekly, 'monthlyTotals' => $monthly, 'dailyTotals' => $daily];
    }

    /**
 * Performs the record operation for this service.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function record(array $input, int $userId): array
    {
        return $this->save($input, $userId, null);
    }

    /**
 * Validates input and updates the matching record.
 * @param int $id Input used by this operation.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function update(int $id, array $input, int $userId): array
    {
        $find = $this->db->prepare('SELECT * FROM farm_produce WHERE id = ?');
        $find->execute([$id]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw new \RuntimeException('Production record not found.');
        return $this->save($input, $userId, $existing);
    }

    /**
 * Performs the save operation for this service.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @param ?array $editing Input used by this operation.
 * @return array Result of this operation.
 */
    private function save(array $input, int $userId, ?array $editing): array
    {
        $name = trim((string) ($input['produceName'] ?? $editing['produce_name'] ?? ''));
        $date = $this->date($input['productionDate'] ?? $editing['production_date'] ?? '');
        $session = trim((string) ($input['session'] ?? $editing['production_session'] ?? ''));
        $quantity = filter_var($input['quantityProduced'] ?? $editing['quantity_produced'] ?? null, FILTER_VALIDATE_FLOAT);
        $soldInput = $input['quantitySold'] ?? $editing['quantity_sold'] ?? 0;
        $damagedInput = $input['quantityDamaged'] ?? $editing['quantity_damaged'] ?? 0;
        $sold = $soldInput === '' || $soldInput === null ? 0.0 : filter_var($soldInput, FILTER_VALIDATE_FLOAT);
        $damaged = $damagedInput === '' || $damagedInput === null ? 0.0 : filter_var($damagedInput, FILTER_VALIDATE_FLOAT);
        $unit = trim((string) ($input['unit'] ?? $editing['unit'] ?? ''));
        $animalId = ($input['animalId'] ?? $editing['animal_id'] ?? null);
        $animalId = $animalId === null || $animalId === '' ? null : filter_var($animalId, FILTER_VALIDATE_INT);
        if ($animalId === false || $name === '' || strlen($name) > 120 || $unit === '' || strlen($unit) > 30 || !in_array($session, ['', 'morning', 'evening', 'other'], true) || $quantity === false || $quantity < 0 || $sold === false || $sold < 0 || $damaged === false || $damaged < 0 || $sold + $damaged > $quantity || $date > date('Y-m-d')) {
            throw new \InvalidArgumentException('Enter a valid produce name, date, quantity, sold amount, damaged amount and unit.');
        }
        $this->checkPlausibility($name, $unit, (float) $quantity);
        $normalizedName = strtolower($name);
        if ($normalizedName === 'eggs' && $animalId !== null) throw new \InvalidArgumentException('Egg records are recorded for the flock, not an individual animal.');
        if (in_array($normalizedName, ['milk', 'eggs'], true)) {
            $duplicate = $this->findDuplicate($normalizedName, $date, $session !== '' ? $session : null, $animalId, $editing['id'] ?? null);
            if ($duplicate) {
                $subject = $normalizedName === 'milk' ? 'milk record for this animal' : 'egg record for this flock';
                throw new DuplicateProductionException("A {$subject}, date and session already exists. Edit the existing record instead.", $duplicate);
            }
        }
        if ($animalId !== null) {
            $animal = $this->db->prepare('SELECT id FROM animals WHERE id = ?');
            $animal->execute([$animalId]);
            if (!$animal->fetchColumn()) throw new \InvalidArgumentException('Select an existing animal for this production record.');
        }
        $notes = trim((string) ($input['notes'] ?? $editing['notes'] ?? '')) ?: null;
        $net = (float) $quantity - (float) $sold - (float) $damaged;
        if ($editing) {
            $statement = $this->db->prepare('UPDATE farm_produce SET produce_name=?, production_date=?, production_session=?, animal_id=?, quantity_produced=?, quantity_sold=?, quantity_damaged=?, unit=?, notes=? WHERE id=?');
            $statement->execute([$name, $date, $session !== '' ? $session : null, $animalId, $quantity, $sold, $damaged, $unit, $notes, $editing['id']]);
            $oldNet = (float) $editing['quantity_produced'] - (float) $editing['quantity_sold'] - (float) $editing['quantity_damaged'];
            $oldKind = strtolower($editing['produce_name']);
            if ($oldKind === $normalizedName) {
                $this->adjustProductStock($oldKind, $net - $oldNet);
            } else {
                $this->adjustProductStock($oldKind, -$oldNet);
                $this->adjustProductStock($normalizedName, $net);
            }
            return ['id' => (int) $editing['id'], 'date' => $date];
        }
        $statement = $this->db->prepare('INSERT INTO farm_produce (produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $date, $session !== '' ? $session : null, $animalId, $quantity, $sold, $damaged, $unit, $notes, $userId]);
        $this->adjustProductStock(strtolower($name), $net);
        return ['id' => (int) $this->db->lastInsertId(), 'date' => $date];
    }

    /**
 * Performs the check plausibility operation for this service.
 * @param string $name Input used by this operation.
 * @param string $unit Input used by this operation.
 * @param float $quantity Input used by this operation.
 * @return void Result of this operation.
 */
    private function checkPlausibility(string $name, string $unit, float $quantity): void
    {
        if (strtolower($name) === 'milk' && strtolower($unit) === 'litres' && $quantity > self::PLAUSIBILITY_LIMITS['milk_litres']) {
            throw new ProductionPlausibilityWarning('More than 40 litres of milk in one session is unusual. Check the quantity; this record was not saved.');
        }
        if (strtolower($name) === 'eggs' && strtolower($unit) === 'trays' && $quantity > self::PLAUSIBILITY_LIMITS['egg_trays']) {
            throw new ProductionPlausibilityWarning('More than 150 egg trays in one flock entry is unusual. Check the quantity; this record was not saved.');
        }
    }

    /**
 * Performs the find duplicate operation for this service.
 * @param string $name Input used by this operation.
 * @param string $date Input used by this operation.
 * @param ?string $session Input used by this operation.
 * @param ?int $animalId Input used by this operation.
 * @param mixed $excludeId Input used by this operation.
 * @return ?array Result of this operation.
 */
    private function findDuplicate(string $name, string $date, ?string $session, ?int $animalId, mixed $excludeId): ?array
    {
        $sql = 'SELECT id, produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes FROM farm_produce WHERE LOWER(produce_name)=? AND production_date=? AND ((production_session IS NULL AND ? IS NULL) OR production_session=?) AND ((animal_id IS NULL AND ? IS NULL) OR animal_id=?)';
        $params = [$name, $date, $session, $session, $animalId, $animalId];
        if ($excludeId !== null) { $sql .= ' AND id<>?'; $params[] = $excludeId; }
        $sql .= ' ORDER BY id LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    /**
 * Performs the adjust product stock operation for this service.
 * @param string $kind Input used by this operation.
 * @param float $change Input used by this operation.
 * @return void Result of this operation.
 */
    private function adjustProductStock(string $kind, float $change): void
    {
        $this->db->prepare('UPDATE products SET available_quantity = available_quantity + ? WHERE is_active = 1 AND production_kind = ?')->execute([$change, $kind]);
    }

    /**
 * Performs the date operation for this service.
 * @param mixed $value Input used by this operation.
 * @return string Result of this operation.
 */
    private function date(mixed $value): string
    {
        $date = date_create((string) $value);
        if (!$date) throw new \InvalidArgumentException('Production date must be valid.');
        return $date->format('Y-m-d');
    }

    /**
 * Performs the sum amounts operation for this service.
 * @param array $left Input used by this operation.
 * @param array $right Input used by this operation.
 * @return array Result of this operation.
 */
    private function sumAmounts(array $left, array $right): array
    {
        return ['produced' => $left['produced'] + $right['produced'], 'sold' => $left['sold'] + $right['sold'], 'damaged' => $left['damaged'] + $right['damaged']];
    }
}
