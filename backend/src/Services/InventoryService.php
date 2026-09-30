<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class InventoryService
{
    public function __construct(private PDO $db) {}

    public function list(): array
    {
        return $this->db->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level, unit_cost, is_active FROM inventory_items ORDER BY name')->fetchAll();
    }

    public function create(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $category = (string) ($input['category'] ?? '');
        $unit = trim((string) ($input['unit'] ?? ''));
        $quantity = (float) ($input['quantityOnHand'] ?? 0);
        $reorder = (float) ($input['reorderLevel'] ?? 0);
        if ($name === '' || $unit === '' || !in_array($category, ['feed', 'medicine', 'equipment', 'supplies', 'other'], true) || $quantity < 0 || $reorder < 0) {
            throw new \InvalidArgumentException('Enter a name, category, unit and non-negative quantities.');
        }
        $statement = $this->db->prepare('INSERT INTO inventory_items (name, category, unit, quantity_on_hand, reorder_level, unit_cost) VALUES (?, ?, ?, ?, ?, ?)');
        $unitCost = $input['unitCost'] ?? null;
        $statement->execute([$name, $category, $unit, $quantity, $reorder, $unitCost === '' ? null : $unitCost]);
        $id = (int) $this->db->lastInsertId();
        $result = $this->db->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $result->execute([$id]);
        return $result->fetch();
    }

    public function transact(int $id, array $input, int $userId): array
    {
        $type = (string) ($input['transactionType'] ?? '');
        $quantity = (float) ($input['quantityChange'] ?? 0);
        if (!in_array($type, ['in', 'out', 'adjustment'], true) || $quantity == 0 || ($type === 'out' && $quantity > 0)) {
            throw new \InvalidArgumentException('Choose a transaction type and a valid signed quantity.');
        }
        $this->db->beginTransaction();
        $lock = $this->db->prepare('SELECT quantity_on_hand FROM inventory_items WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $current = $lock->fetchColumn();
        if ($current === false || (float) $current + $quantity < 0) {
            $this->db->rollBack();
            throw new \InvalidArgumentException('Inventory cannot become negative.');
        }
        $this->db->prepare('UPDATE inventory_items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?')->execute([$quantity, $id]);
        $this->db->prepare('INSERT INTO inventory_transactions (item_id, transaction_type, quantity_change, reason, transaction_date, recorded_by) VALUES (?, ?, ?, ?, NOW(), ?)')->execute([$id, $type, $quantity, trim((string) ($input['reason'] ?? '')) ?: null, $userId]);
        $this->db->commit();
        $result = $this->db->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $result->execute([$id]);
        return $result->fetch();
    }
}
