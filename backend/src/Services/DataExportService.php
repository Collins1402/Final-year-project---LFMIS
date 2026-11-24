<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class DataExportService
{
    public function __construct(private PDO $db, private PermissionService $permissions) {}

    public function export(int $userId, string $role): array
    {
        if ($role === 'admin') return $this->administratorData($userId);
        if ($role === 'veterinarian') return ['animal_health_records' => $this->query(
            'SELECT a.tag_number, a.species, a.name AS animal_name, h.record_type, h.description, h.medication,
                    h.dosage, h.cost, h.record_date, h.next_due_date
             FROM health_records h JOIN animals a ON a.id = h.animal_id
             ORDER BY h.record_date DESC, h.id DESC'
        )];
        if ($role === 'customer') return $this->customerData($userId);
        if ($role === 'buyer') {
            $data = $this->customerData($userId);
            $data['animal_health_records'] = $this->ownedAnimalHealthRecords($userId);
            return $data;
        }
        if ($role === 'worker') return $this->workerData($userId);
        throw new \DomainException('Data export is not available for this account role.');
    }

    private function administratorData(int $userId): array
    {
        return [
            'account' => $this->account($userId),
            'user_accounts' => $this->query("SELECT u.id, u.full_name, u.username, u.email, r.name AS role, u.phone, u.is_active, u.email_verified_at, u.last_login_at, u.created_at FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id"),
            'animals' => $this->query('SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, notes, created_at FROM animals ORDER BY tag_number'),
            'animal_health_records' => $this->query('SELECT h.id, a.tag_number, a.species, h.record_type, h.description, h.medication, h.dosage, h.cost, h.record_date, h.next_due_date FROM health_records h JOIN animals a ON a.id = h.animal_id ORDER BY h.record_date DESC, h.id DESC'),
            'farm_activities' => $this->query('SELECT id, activity_type, description, animal_id, assigned_to, activity_at, status, completed_at, created_at FROM farm_activities ORDER BY activity_at DESC'),
            'production_records' => $this->query('SELECT id, produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, created_at FROM farm_produce ORDER BY production_date DESC, id DESC'),
            'inventory' => $this->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level, unit_cost, is_active FROM inventory_items ORDER BY name'),
            'inventory_transactions' => $this->query('SELECT id, item_id, transaction_type, quantity_change, unit_cost, reason, transaction_date FROM inventory_transactions ORDER BY transaction_date DESC'),
            'product_listings' => $this->query('SELECT id, name, product_type, production_kind, species, unit, available_quantity, unit_price, is_active, created_at FROM products ORDER BY name'),
            'orders' => $this->query('SELECT o.id, c.name AS customer, c.phone AS customer_phone, o.status, o.total_amount, o.notes, o.ordered_at, o.accepted_at, o.fulfilled_at FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.ordered_at DESC'),
            'order_items' => $this->query('SELECT oi.id, oi.order_id, p.name AS listing, oi.item_name, oi.quantity, oi.unit, oi.unit_price FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id ORDER BY oi.order_id, oi.id'),
            'payments' => $this->query('SELECT id, order_id, customer_id, amount, method, status, transaction_reference, result_desc, paid_at, created_at FROM payments ORDER BY created_at DESC'),
            'sales' => $this->query('SELECT id, order_id, customer_id, description, quantity, unit, unit_price, total_amount, sold_at FROM sales ORDER BY sold_at DESC'),
            'farm_expenses' => $this->query('SELECT id, category, description, amount, expense_date, created_at FROM expenses ORDER BY expense_date DESC'),
            'feedback' => $this->query('SELECT id, user_id, category, message, status, resolved_by, resolved_at, created_at FROM feedback ORDER BY created_at DESC'),
            'farm_events' => $this->query('SELECT id, title, description, event_date, start_time, end_time, location, status, created_at FROM farm_events ORDER BY event_date DESC'),
            'audit_log' => $this->query('SELECT id, user_id, action, entity_type, entity_id, details, ip_address, created_at FROM audit_logs ORDER BY created_at DESC'),
        ];
    }

    private function workerData(int $userId): array
    {
        $data = ['account' => $this->account($userId)];
        if ($this->permissions->allows('worker', 'animals.view')) $data['animals'] = $this->query('SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, created_at FROM animals ORDER BY tag_number');
        if ($this->permissions->allows('worker', 'health.view')) $data['animal_health_records'] = $this->query('SELECT h.id, a.tag_number, a.species, h.record_type, h.description, h.medication, h.dosage, h.cost, h.record_date, h.next_due_date FROM health_records h JOIN animals a ON a.id = h.animal_id ORDER BY h.record_date DESC, h.id DESC');
        if ($this->permissions->allows('worker', 'activities.view')) $data['farm_activities'] = $this->query('SELECT id, activity_type, description, animal_id, assigned_to, activity_at, status, completed_at, created_at FROM farm_activities ORDER BY activity_at DESC');
        if ($this->permissions->allows('worker', 'production.view')) $data['production_records'] = $this->query('SELECT id, produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, created_at FROM farm_produce ORDER BY production_date DESC, id DESC');
        if ($this->permissions->allows('worker', 'inventory.view')) {
            $data['inventory'] = $this->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level, unit_cost, is_active FROM inventory_items ORDER BY name');
            $data['inventory_transactions'] = $this->query('SELECT id, item_id, transaction_type, quantity_change, unit_cost, reason, transaction_date FROM inventory_transactions ORDER BY transaction_date DESC');
        }
        return $data;
    }

    private function customerData(int $userId): array
    {
        $customerId = $this->db->prepare('SELECT id FROM customers WHERE user_id = ?');
        $customerId->execute([$userId]);
        $id = $customerId->fetchColumn();
        $data = ['account' => $this->account($userId), 'purchases' => [], 'purchase_items' => [], 'payments' => []];
        if ($id === false) return $data;
        $data['purchases'] = $this->query('SELECT id, status, total_amount, ordered_at, accepted_at, fulfilled_at FROM orders WHERE customer_id = ? ORDER BY ordered_at DESC', [(int) $id]);
        $data['purchase_items'] = $this->query('SELECT oi.order_id, oi.item_name, oi.quantity, oi.unit, oi.unit_price FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.customer_id = ? ORDER BY oi.order_id, oi.id', [(int) $id]);
        $data['payments'] = $this->query('SELECT p.order_id, p.amount, p.method, p.status, p.transaction_reference, p.paid_at, p.created_at FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.customer_id = ? ORDER BY p.created_at DESC', [(int) $id]);
        return $data;
    }

    private function ownedAnimalHealthRecords(int $userId): array
    {
        return $this->query('SELECT a.tag_number, a.species, a.name AS animal_name, h.record_type, h.description, h.medication,
                                   h.dosage, h.cost, h.record_date, h.next_due_date
                            FROM health_records h JOIN animals a ON a.id = h.animal_id
                            JOIN order_animals oa ON oa.animal_id = a.id
                            JOIN orders o ON o.id = oa.order_id
                            JOIN customers c ON c.id = o.customer_id
                            WHERE c.user_id = ?
                            ORDER BY h.record_date DESC, h.id DESC', [$userId]);
    }

    private function account(int $userId): array
    {
        return $this->query('SELECT u.full_name, u.username, u.email, u.phone, r.name AS role, u.created_at FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$userId]);
    }

    private function query(string $sql, array $parameters = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
