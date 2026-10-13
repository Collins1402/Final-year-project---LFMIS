<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ProductionService
{
    public function __construct(private PDO $db) {}

    public function records(): array
    {
        return $this->db->query('SELECT id, produce_name, production_date, production_session, quantity_produced, quantity_sold, quantity_damaged, unit, notes FROM farm_produce ORDER BY production_date DESC, id DESC LIMIT 300')->fetchAll();
    }

    public function summary(): array
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $monthStart = date('Y-m-01');
        $rangeStart = min($weekStart, $monthStart);
        $statement = $this->db->prepare(
            'SELECT production_date, produce_name, unit, SUM(quantity_produced) AS produced,
                    SUM(quantity_sold) AS sold, SUM(quantity_damaged) AS damaged
             FROM farm_produce WHERE production_date BETWEEN ? AND ?
             GROUP BY production_date, produce_name, unit ORDER BY production_date, produce_name'
        );
        $statement->execute([$rangeStart, $today]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $weekly = [];
        $monthly = [];
        $daily = [];
        foreach ($rows as $row) {
            $key = $row['produce_name'] . ' (' . $row['unit'] . ')';
            $values = ['produced' => (float) $row['produced'], 'sold' => (float) $row['sold'], 'damaged' => (float) $row['damaged']];
            if ($row['production_date'] >= $weekStart) {
                $weekly[$key] = $this->sumAmounts($weekly[$key] ?? ['produced' => 0, 'sold' => 0, 'damaged' => 0], $values);
                $daily[$row['production_date']][$key] = $values;
            }
            if ($row['production_date'] >= $monthStart) {
                $monthly[$key] = $this->sumAmounts($monthly[$key] ?? ['produced' => 0, 'sold' => 0, 'damaged' => 0], $values);
            }
        }
        return ['weekStart' => $weekStart, 'today' => $today, 'weeklyTotals' => $weekly, 'monthlyTotals' => $monthly, 'dailyTotals' => $daily];
    }

    public function record(array $input, int $userId): array
    {
        $name = trim((string) ($input['produceName'] ?? ''));
        $date = $this->date($input['productionDate'] ?? '');
        $session = trim((string) ($input['session'] ?? ''));
        $quantity = filter_var($input['quantityProduced'] ?? null, FILTER_VALIDATE_FLOAT);
        $sold = filter_var($input['quantitySold'] ?? 0, FILTER_VALIDATE_FLOAT);
        $damaged = filter_var($input['quantityDamaged'] ?? 0, FILTER_VALIDATE_FLOAT);
        $unit = trim((string) ($input['unit'] ?? ''));
        if (
            $name === '' || strlen($name) > 120 || $unit === '' || strlen($unit) > 30 ||
            !in_array($session, ['', 'morning', 'evening', 'other'], true) ||
            $quantity === false || $quantity < 0 || $sold === false || $sold < 0 ||
            $damaged === false || $damaged < 0 || $sold + $damaged > $quantity ||
            $date > date('Y-m-d')
        ) {
            throw new \InvalidArgumentException('Enter a valid produce name, date, quantity, sold amount, damaged amount and unit.');
        }
        $statement = $this->db->prepare('INSERT INTO farm_produce (produce_name, production_date, production_session, quantity_produced, quantity_sold, quantity_damaged, unit, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $date, $session !== '' ? $session : null, $quantity, $sold, $damaged, $unit, trim((string) ($input['notes'] ?? '')) ?: null, $userId]);
        $this->db->prepare('UPDATE products SET available_quantity = available_quantity + ? WHERE is_active = 1 AND production_kind = ?')->execute([$quantity - $sold - $damaged, strtolower($name)]);
        return ['id' => (int) $this->db->lastInsertId(), 'date' => $date];
    }

    private function date(mixed $value): string
    {
        $date = date_create((string) $value);
        if (!$date) throw new \InvalidArgumentException('Production date must be valid.');
        return $date->format('Y-m-d');
    }

    private function sumAmounts(array $left, array $right): array
    {
        return [
            'produced' => $left['produced'] + $right['produced'],
            'sold' => $left['sold'] + $right['sold'],
            'damaged' => $left['damaged'] + $right['damaged'],
        ];
    }
}
