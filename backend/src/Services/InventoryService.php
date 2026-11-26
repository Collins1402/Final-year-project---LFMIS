<?php
/**
 * File purpose: implements InventoryService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class InventoryService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}

    /**
 * Returns the matching records for the requested scope.
 * @return array Result of this operation.
 */
    public function list(): array
    {
        return $this->db->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level, unit_cost, is_active FROM inventory_items ORDER BY name')->fetchAll();
    }

    /**
 * Performs the transactions operation for this service.
 * @param ?int $itemId Input used by this operation.
 * @return array Result of this operation.
 */
    public function transactions(?int $itemId = null): array
    {
        $query = 'SELECT inventory_transactions.id, inventory_transactions.item_id, inventory_items.name AS item_name,
                         inventory_transactions.transaction_type, inventory_transactions.quantity_change,
                         inventory_transactions.reason, inventory_transactions.transaction_date
                  FROM inventory_transactions JOIN inventory_items ON inventory_items.id = inventory_transactions.item_id';
        if ($itemId !== null) {
            $query .= ' WHERE inventory_transactions.item_id = ?';
        }
        $query .= ' ORDER BY inventory_transactions.transaction_date DESC, inventory_transactions.id DESC LIMIT 300';
        $statement = $this->db->prepare($query);
        $statement->execute($itemId === null ? [] : [$itemId]);
        return $statement->fetchAll();
    }

    /**
 * Validates input and creates a new record.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
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

    /**
 * Performs the transact operation for this service.
 * @param int $id Input used by this operation.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function transact(int $id, array $input, int $userId): array
    {
        $type = (string) ($input['transactionType'] ?? '');
        $quantity = (float) ($input['quantityChange'] ?? 0);
        if (!in_array($type, ['in', 'out', 'adjustment'], true) || $quantity == 0 || ($type === 'in' && $quantity < 0) || ($type === 'out' && $quantity > 0)) {
            throw new \InvalidArgumentException('Choose a transaction type and a valid signed quantity.');
        }
        $this->db->beginTransaction();
        try {
            $lockSql = 'SELECT quantity_on_hand FROM inventory_items WHERE id = ?';
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $this->db->prepare($lockSql);
            $lock->execute([$id]);
            $current = $lock->fetchColumn();
            if ($current === false) {
                throw new \InvalidArgumentException('Inventory item not found.');
            }
            if ((float) $current + $quantity < 0) {
                throw new \InvalidArgumentException('Inventory cannot become negative.');
            }
            $this->db->prepare('UPDATE inventory_items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?')->execute([$quantity, $id]);
            $this->db->prepare('INSERT INTO inventory_transactions (item_id, transaction_type, quantity_change, reason, transaction_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $type, $quantity, trim((string) ($input['reason'] ?? '')) ?: null, date('Y-m-d H:i:s'), $userId]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $result = $this->db->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $result->execute([$id]);
        return $result->fetch();
    }
}
