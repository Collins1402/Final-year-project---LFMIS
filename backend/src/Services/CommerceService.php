<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class CommerceService
{
    public function __construct(private PDO $db) {}

    public function products(): array
    {
        return $this->db->query('SELECT * FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public function createProduct(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = (string) ($input['productType'] ?? '');
        $unit = trim((string) ($input['unit'] ?? ''));
        $price = (float) ($input['unitPrice'] ?? 0);
        if ($name === '' || $unit === '' || !in_array($type, ['livestock', 'produce', 'other'], true) || $price < 0) throw new \InvalidArgumentException('Enter a valid product name, type, unit and price.');
        $statement = $this->db->prepare('INSERT INTO products (name, product_type, unit, available_quantity, unit_price, species) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $type, $unit, max(0, (float) ($input['availableQuantity'] ?? 0)), $price, $input['species'] ?? null]);
        $id = (int) $this->db->lastInsertId();
        $result = $this->db->prepare('SELECT * FROM products WHERE id = ?'); $result->execute([$id]); return $result->fetch();
    }

    public function orders(): array
    {
        return $this->db->query('SELECT orders.id, orders.status, orders.total_amount, orders.ordered_at, customers.name AS customer, customers.phone FROM orders JOIN customers ON customers.id = orders.customer_id ORDER BY orders.ordered_at DESC LIMIT 300')->fetchAll();
    }

    public function createOrder(array $input, int $userId): array
    {
        $name = trim((string) ($input['customerName'] ?? ''));
        $phone = trim((string) ($input['customerPhone'] ?? '')) ?: null;
        $item = trim((string) ($input['itemName'] ?? ''));
        $quantity = (float) ($input['quantity'] ?? 0);
        $unit = trim((string) ($input['unit'] ?? ''));
        $unitPrice = (float) ($input['unitPrice'] ?? 0);
        if ($name === '' || $item === '' || $quantity <= 0 || $unit === '' || $unitPrice < 0) throw new \InvalidArgumentException('Enter customer and order details.');
        $this->db->beginTransaction();
        $customer = $this->db->prepare('SELECT id FROM customers WHERE email = ? OR (phone IS NOT NULL AND phone = ?) LIMIT 1');
        $customer->execute([$input['email'] ?? null, $phone]);
        $customerId = (int) $customer->fetchColumn();
        if (!$customerId) { $create = $this->db->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)'); $create->execute([$name, $phone, $input['email'] ?? null]); $customerId = (int) $this->db->lastInsertId(); }
        $order = $this->db->prepare('INSERT INTO orders (customer_id, status, total_amount, handled_by) VALUES (?, \'quoted\', ?, ?)');
        $order->execute([$customerId, $quantity * $unitPrice, $userId]);
        $orderId = (int) $this->db->lastInsertId();
        $itemStatement = $this->db->prepare('INSERT INTO order_items (order_id, item_name, quantity, unit, unit_price) VALUES (?, ?, ?, ?, ?)');
        $itemStatement->execute([$orderId, $item, $quantity, $unit, $unitPrice]);
        $this->db->commit();
        return ['id' => $orderId, 'customer' => $name, 'totalAmount' => $quantity * $unitPrice, 'status' => 'quoted'];
    }

    public function updateOrder(int $id, array $input, int $userId): array
    {
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['pending', 'quoted', 'accepted', 'fulfilled', 'cancelled'], true)) throw new \InvalidArgumentException('Unsupported order status.');
        $statement = $this->db->prepare('UPDATE orders SET status = ?, total_amount = COALESCE(?, total_amount), handled_by = ? WHERE id = ?');
        $statement->execute([$status, isset($input['totalAmount']) ? (float) $input['totalAmount'] : null, $userId, $id]);
        if ($statement->rowCount() === 0) throw new \RuntimeException('Order not found.');
        return ['id' => $id, 'status' => $status];
    }

    public function sales(): array { return $this->db->query('SELECT * FROM sales ORDER BY sold_at DESC LIMIT 300')->fetchAll(); }
    public function createSale(array $input, int $userId): array
    {
        $description = trim((string) ($input['description'] ?? '')); $quantity = (float) ($input['quantity'] ?? 0); $unit = trim((string) ($input['unit'] ?? '')); $price = (float) ($input['unitPrice'] ?? 0); $date = date_create((string) ($input['soldAt'] ?? ''));
        if ($description === '' || $quantity <= 0 || $unit === '' || $price < 0 || !$date) throw new \InvalidArgumentException('Enter valid sale details.');
        $statement = $this->db->prepare('INSERT INTO sales (description, quantity, unit, unit_price, sold_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?)'); $statement->execute([$description, $quantity, $unit, $price, $date->format('Y-m-d H:i:s'), $userId]); return ['id' => (int) $this->db->lastInsertId()];
    }

    public function expenses(): array { return $this->db->query('SELECT * FROM expenses ORDER BY expense_date DESC LIMIT 300')->fetchAll(); }
    public function createExpense(array $input, int $userId): array
    {
        $category = (string) ($input['category'] ?? ''); $description = trim((string) ($input['description'] ?? '')); $amount = (float) ($input['amount'] ?? 0); $date = date_create((string) ($input['expenseDate'] ?? ''));
        if (!in_array($category, ['feed', 'veterinary', 'labour', 'equipment', 'utilities', 'transport', 'maintenance', 'other'], true) || $description === '' || $amount <= 0 || !$date) throw new \InvalidArgumentException('Enter valid expense details.');
        $statement = $this->db->prepare('INSERT INTO expenses (category, description, amount, expense_date, recorded_by) VALUES (?, ?, ?, ?, ?)'); $statement->execute([$category, $description, $amount, $date->format('Y-m-d'), $userId]); return ['id' => (int) $this->db->lastInsertId()];
    }

    public function payments(): array { return $this->db->query('SELECT payments.*, customers.name AS customer FROM payments LEFT JOIN customers ON customers.id = payments.customer_id ORDER BY payments.created_at DESC LIMIT 300')->fetchAll(); }
    public function createPayment(array $input, int $userId): array
    {
        $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT); $method = (string) ($input['method'] ?? ''); $reference = trim((string) ($input['transactionReference'] ?? '')) ?: null;
        if (!$orderId || !in_array($method, ['cash', 'mpesa', 'bank', 'other'], true)) throw new \InvalidArgumentException('Choose an order and payment method.');
        $order = $this->db->prepare('SELECT customer_id, total_amount FROM orders WHERE id = ?'); $order->execute([$orderId]); $row = $order->fetch(); if (!$row || (float) $row['total_amount'] <= 0) throw new \InvalidArgumentException('The order must have a positive total.');
        $status = $method === 'mpesa' ? 'pending' : 'confirmed'; $statement = $this->db->prepare('INSERT INTO payments (order_id, customer_id, amount, method, status, transaction_reference, paid_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'); $statement->execute([$orderId, $row['customer_id'], $row['total_amount'], $method, $status, $reference, $status === 'confirmed' ? date('Y-m-d H:i:s') : null, $userId]); return ['id' => (int) $this->db->lastInsertId(), 'status' => $status];
    }

    public function finance(): array
    {
        $sales = (float) $this->db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sold_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')")->fetchColumn();
        $payments = (float) $this->db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND paid_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')")->fetchColumn();
        $expenses = (float) $this->db->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')")->fetchColumn();
        return ['salesThisMonth' => $sales, 'confirmedPaymentsThisMonth' => $payments, 'expensesThisMonth' => $expenses, 'estimatedNet' => $sales - $expenses];
    }
}
