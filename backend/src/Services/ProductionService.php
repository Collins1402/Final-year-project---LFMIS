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
        return ['id' => (int) $this->db->lastInsertId(), 'date' => $date];
    }

    private function date(mixed $value): string
    {
        $date = date_create((string) $value);
        if (!$date) throw new \InvalidArgumentException('Production date must be valid.');
        return $date->format('Y-m-d');
    }
}
