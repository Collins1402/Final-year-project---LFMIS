<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class CommerceService
{
    public function __construct(private PDO $db) {}

    public function products(?string $role = null): array
    {
        $query = "SELECT products.id, products.name, products.product_type, products.production_kind,
                 products.species, products.unit, products.unit_price, products.is_active,
                         CASE WHEN products.product_type = 'livestock'
                              THEN (SELECT COUNT(*) FROM animals WHERE animals.status = 'active' AND animals.species = products.species)
                              ELSE products.available_quantity END AS available_quantity
                  FROM products WHERE products.is_active = 1";
        if ($role === 'buyer') $query .= " AND products.product_type = 'livestock'";
        if ($role === 'customer') $query .= " AND products.product_type <> 'livestock'";
        $query .= ' ORDER BY products.name';
        return $this->db->query($query)->fetchAll();
    }

    public function createProduct(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = (string) ($input['productType'] ?? '');
        $unit = trim((string) ($input['unit'] ?? ''));
        $price = (float) ($input['unitPrice'] ?? 0);
        $species = trim((string) ($input['species'] ?? '')) ?: null;
        $productionKind = trim((string) ($input['productionKind'] ?? '')) ?: null;
        $quantity = max(0, (float) ($input['availableQuantity'] ?? 0));
        if ($name === '' || $unit === '' || !in_array($type, ['livestock', 'produce', 'other'], true) || !is_finite($price) || $price < 0 || !is_finite($quantity)) throw new \InvalidArgumentException('Enter a valid product name, type, unit and price.');
        if ($type === 'livestock' && ($species === null || !in_array($species, ['cattle', 'goat', 'sheep', 'rabbit', 'chicken', 'other'], true))) throw new \InvalidArgumentException('Choose a livestock species for this listing.');
        if ($productionKind !== null && !in_array($productionKind, ['milk', 'eggs', 'wool'], true)) throw new \InvalidArgumentException('Choose a supported produce listing type.');
        if ($productionKind !== null && $type !== 'produce') throw new \InvalidArgumentException('Only produce listings can be linked to production records.');
        if ($productionKind !== null) {
            $stock = $this->db->prepare('SELECT COALESCE(SUM(quantity_produced - quantity_sold - quantity_damaged), 0) FROM farm_produce WHERE LOWER(produce_name) = ?');
            $stock->execute([$productionKind]);
            $quantity = max(0, (float) $stock->fetchColumn());
        }
        $statement = $this->db->prepare('INSERT INTO products (name, product_type, production_kind, species, unit, available_quantity, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $type, $productionKind, $species, $unit, $quantity, $price]);
        $id = (int) $this->db->lastInsertId();
        $result = $this->db->prepare('SELECT * FROM products WHERE id = ?'); $result->execute([$id]); return $result->fetch();
    }

    public function createCustomerOrder(array $input, array $user): array
    {
        $productId = filter_var($input['productId'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$productId || $quantity === false || $quantity <= 0 || !is_finite((float) $quantity)) throw new \InvalidArgumentException('Choose a product and enter a positive quantity.');
        $this->db->beginTransaction();
        try {
            $product = $this->findProduct((int) $productId, true);
            if (!(int) $product['is_active']) throw new \DomainException('This listing is no longer available.');
            if (($user['role'] ?? '') === 'buyer' && $product['product_type'] !== 'livestock') throw new \DomainException('Livestock buyer accounts can request livestock listings only.');
            if (($user['role'] ?? '') === 'customer' && $product['product_type'] === 'livestock') throw new \DomainException('Customer accounts can request produce and other farm listings only.');
            if ((float) $quantity > $this->availableQuantity($product)) throw new \DomainException('The requested quantity is not currently available.');
            if ($product['product_type'] === 'livestock' && floor((float) $quantity) !== (float) $quantity) throw new \InvalidArgumentException('Livestock orders must use a whole-animal quantity.');

            $customerQuery = $this->db->prepare('SELECT id FROM customers WHERE user_id = ?');
            $customerQuery->execute([(int) $user['id']]);
            $customerId = (int) $customerQuery->fetchColumn();
            if (!$customerId) {
                $customer = $this->db->prepare('INSERT INTO customers (user_id, name, phone, email) VALUES (?, ?, ?, ?)');
                $customer->execute([(int) $user['id'], (string) $user['fullName'], $user['phone'] ?? null, (string) $user['email']]);
                $customerId = (int) $this->db->lastInsertId();
            }
            $total = (float) $quantity * (float) $product['unit_price'];
            $order = $this->db->prepare("INSERT INTO orders (customer_id, status, total_amount) VALUES (?, 'pending', ?)");
            $order->execute([$customerId, $total]);
            $orderId = (int) $this->db->lastInsertId();
            $item = $this->db->prepare('INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (?, ?, ?, ?, ?, ?)');
            $item->execute([$orderId, $product['id'], $product['name'], $quantity, $product['unit'], $product['unit_price']]);
            $this->db->commit();
            return ['id' => $orderId, 'status' => 'pending', 'totalAmount' => $total];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function orders(?int $userId = null): array
    {
        $query = 'SELECT orders.id, orders.status, orders.total_amount, orders.ordered_at, customers.name AS customer, customers.phone
                  FROM orders JOIN customers ON customers.id = orders.customer_id';
        if ($userId !== null) {
            $query .= ' WHERE customers.user_id = ?';
        }
        $query .= ' ORDER BY orders.ordered_at DESC LIMIT 300';
        $statement = $this->db->prepare($query);
        $statement->execute($userId === null ? [] : [$userId]);
        return $statement->fetchAll();
    }

    public function createOrder(array $input, int $userId): array
    {
        $name = trim((string) ($input['customerName'] ?? ''));
        $phone = trim((string) ($input['customerPhone'] ?? '')) ?: null;
        $productId = filter_var($input['productId'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '' || !$productId || $quantity === false || $quantity <= 0 || !is_finite((float) $quantity)) throw new \InvalidArgumentException('Enter customer details, choose a listed product and enter a positive quantity.');
        $this->db->beginTransaction();
        try {
            $product = $this->findProduct((int) $productId, true);
            if (!(int) $product['is_active']) throw new \DomainException('This listing is no longer active.');
            if ($product['product_type'] === 'livestock' && floor((float) $quantity) !== (float) $quantity) throw new \InvalidArgumentException('Livestock orders must use a whole-animal quantity.');
            $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $customer = $this->db->prepare('SELECT id FROM customers WHERE (? IS NOT NULL AND email = ?) OR (? IS NOT NULL AND phone = ?) LIMIT 1');
            $customer->execute([$email ?: null, $email ?: null, $phone, $phone]);
            $customerId = (int) $customer->fetchColumn();
            if (!$customerId) {
                $create = $this->db->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
                $create->execute([$name, $phone, $email ?: null]);
                $customerId = (int) $this->db->lastInsertId();
            }
            $unitPrice = isset($input['unitPrice']) && $input['unitPrice'] !== '' ? (float) $input['unitPrice'] : (float) $product['unit_price'];
            if (!is_finite($unitPrice) || $unitPrice < 0) throw new \InvalidArgumentException('Enter a valid quoted unit price.');
            $total = (float) $quantity * $unitPrice;
            $order = $this->db->prepare("INSERT INTO orders (customer_id, status, total_amount, handled_by) VALUES (?, 'quoted', ?, ?)");
            $order->execute([$customerId, $total, $userId]);
            $orderId = (int) $this->db->lastInsertId();
            $itemStatement = $this->db->prepare('INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (?, ?, ?, ?, ?, ?)');
            $itemStatement->execute([$orderId, $product['id'], $product['name'], $quantity, $product['unit'], $unitPrice]);
            $this->db->commit();
            return ['id' => $orderId, 'customer' => $name, 'totalAmount' => $total, 'status' => 'quoted'];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function updateOrder(int $id, array $input, int $userId): array
    {
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['pending', 'quoted', 'accepted', 'fulfilled', 'cancelled'], true)) throw new \InvalidArgumentException('Unsupported order status.');
        $total = isset($input['totalAmount']) ? filter_var($input['totalAmount'], FILTER_VALIDATE_FLOAT) : null;
        if ($total !== null && ($total === false || !is_finite((float) $total) || $total < 0)) throw new \InvalidArgumentException('Order total must be a non-negative amount.');
        $this->db->beginTransaction();
        try {
            $orderQuery = $this->db->prepare('SELECT id, customer_id, status FROM orders WHERE id = ?');
            $orderQuery->execute([$id]);
            $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new \RuntimeException('Order not found.');
            $currentStatus = (string) $order['status'];
            if ($currentStatus !== $status) {
                $transitions = ['pending' => ['quoted', 'cancelled'], 'quoted' => ['accepted', 'cancelled'], 'accepted' => ['fulfilled', 'cancelled'], 'fulfilled' => [], 'cancelled' => []];
                if (!in_array($status, $transitions[$currentStatus] ?? [], true)) throw new \DomainException('This order cannot move from ' . $currentStatus . ' to ' . $status . '.');
                if ($status === 'accepted') $this->reserveOrderItems($id);
                if ($status === 'cancelled' && $currentStatus === 'accepted') $this->releaseOrderItems($id);
                if ($status === 'fulfilled') $this->recordOrderSales($id, (int) $order['customer_id']);
            }
            $update = $this->db->prepare(
                "UPDATE orders SET status = ?, total_amount = COALESCE(?, total_amount), handled_by = ?,
                   accepted_at = CASE WHEN ? = 'accepted' THEN CURRENT_TIMESTAMP ELSE accepted_at END,
                   fulfilled_at = CASE WHEN ? = 'fulfilled' THEN CURRENT_TIMESTAMP ELSE fulfilled_at END WHERE id = ?"
            );
            $update->execute([$status, $total, $userId, $status, $status, $id]);
            $this->db->commit();
            return ['id' => $id, 'status' => $status];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function updateOrdersBulk(array $orderIds, string $status, int $userId): array
    {
        if (!in_array($status, ['fulfilled', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Bulk actions can only fulfill or cancel orders.');
        }
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === [] || count($orderIds) > 300 || min($orderIds) < 1) {
            throw new \InvalidArgumentException('Select between 1 and 300 valid orders.');
        }

        $this->db->beginTransaction();
        try {
            $updated = [];
            foreach ($orderIds as $id) {
                $query = 'SELECT id, customer_id, status FROM orders WHERE id = ?';
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $query .= ' FOR UPDATE';
                $orderQuery = $this->db->prepare($query);
                $orderQuery->execute([$id]);
                $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
                if (!$order) throw new \DomainException('Order #' . $id . ' was not found. No orders were changed.');

                $currentStatus = (string) $order['status'];
                $transitions = [
                    'pending' => ['cancelled'],
                    'quoted' => ['cancelled'],
                    'accepted' => ['fulfilled', 'cancelled'],
                ];
                if (!in_array($status, $transitions[$currentStatus] ?? [], true)) {
                    throw new \DomainException('Order #' . $id . ' cannot move from ' . $currentStatus . ' to ' . $status . '. No orders were changed.');
                }

                if ($status === 'cancelled' && $currentStatus === 'accepted') $this->releaseOrderItems($id);
                if ($status === 'fulfilled') $this->recordOrderSales($id, (int) $order['customer_id']);
                $statement = $this->db->prepare(
                    "UPDATE orders SET status = ?, handled_by = ?,
                     fulfilled_at = CASE WHEN ? = 'fulfilled' THEN CURRENT_TIMESTAMP ELSE fulfilled_at END
                     WHERE id = ?"
                );
                $statement->execute([$status, $userId, $status, $id]);
                $updated[] = ['id' => $id, 'from' => $currentStatus, 'to' => $status];
            }
            $this->db->commit();
            return $updated;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
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

    public function payments(?int $userId = null): array
    {
        $query = 'SELECT payments.*, customers.name AS customer FROM payments LEFT JOIN customers ON customers.id = payments.customer_id';
        if ($userId !== null) $query .= ' WHERE customers.user_id = ?';
        $query .= ' ORDER BY payments.created_at DESC LIMIT 300';
        $statement = $this->db->prepare($query);
        $statement->execute($userId === null ? [] : [$userId]);
        return $statement->fetchAll();
    }
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
    private function findProduct(int $id, bool $lock = false): array
    {
        $query = 'SELECT id, name, product_type, production_kind, species, unit, available_quantity, unit_price, is_active FROM products WHERE id = ?';
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $query .= ' FOR UPDATE';
        $statement = $this->db->prepare($query);
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: throw new \DomainException('Product listing not found.');
    }

    private function availableQuantity(array $product): float
    {
        if ($product['product_type'] !== 'livestock') return (float) $product['available_quantity'];
        $statement = $this->db->prepare("SELECT COUNT(*) FROM animals WHERE species = ? AND status = 'active'");
        $statement->execute([$product['species']]);
        return (float) $statement->fetchColumn();
    }

    private function reserveOrderItems(int $orderId): void
    {
        $items = $this->db->prepare('SELECT order_items.*, products.product_type, products.species, products.is_active FROM order_items LEFT JOIN products ON products.id = order_items.product_id WHERE order_items.order_id = ?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['product_id'] === null || $item['product_type'] === null) throw new \DomainException('Link each order item to an active product listing before accepting it.');
            if (!(int) $item['is_active']) throw new \DomainException('An order listing is no longer active.');
            $quantity = (float) $item['quantity'];
            if ($item['product_type'] === 'livestock') {
                if (floor($quantity) !== $quantity || !$item['species']) throw new \DomainException('Livestock orders require a species and whole-animal quantity.');
                $sql = "SELECT id FROM animals WHERE species = ? AND status = 'active' ORDER BY id LIMIT " . (int) $quantity;
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
                $animals = $this->db->prepare($sql);
                $animals->execute([$item['species']]);
                $animalIds = array_map('intval', $animals->fetchAll(PDO::FETCH_COLUMN));
                if (count($animalIds) !== (int) $quantity) throw new \DomainException('There are not enough active animals to accept this order.');
                $link = $this->db->prepare('INSERT INTO order_animals (order_id, animal_id) VALUES (?, ?)');
                $markSold = $this->db->prepare("UPDATE animals SET status = 'sold', status_changed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active'");
                foreach ($animalIds as $animalId) {
                    $link->execute([$orderId, $animalId]);
                    $markSold->execute([$animalId]);
                }
            } else {
                $product = $this->findProduct((int) $item['product_id'], true);
                if ((float) $product['available_quantity'] < $quantity) throw new \DomainException('There is not enough listed stock to accept this order.');
                $this->db->prepare('UPDATE products SET available_quantity = available_quantity - ? WHERE id = ?')->execute([$quantity, $product['id']]);
            }
        }
    }

    private function releaseOrderItems(int $orderId): void
    {
        $items = $this->db->prepare('SELECT order_items.product_id, order_items.quantity, products.product_type FROM order_items LEFT JOIN products ON products.id = order_items.product_id WHERE order_items.order_id = ?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['product_id'] === null) continue;
            if ($item['product_type'] === 'livestock') {
                $animals = $this->db->prepare('SELECT animal_id FROM order_animals WHERE order_id = ?');
                $animals->execute([$orderId]);
                $restore = $this->db->prepare("UPDATE animals SET status = 'active', status_changed_at = NULL WHERE id = ? AND status = 'sold'");
                foreach ($animals->fetchAll(PDO::FETCH_COLUMN) as $animalId) $restore->execute([$animalId]);
                $this->db->prepare('DELETE FROM order_animals WHERE order_id = ?')->execute([$orderId]);
            } else {
                $this->db->prepare('UPDATE products SET available_quantity = available_quantity + ? WHERE id = ?')->execute([$item['quantity'], $item['product_id']]);
            }
        }
    }

    private function recordOrderSales(int $orderId, int $customerId): void
    {
        $existing = $this->db->prepare('SELECT COUNT(*) FROM sales WHERE order_id = ?');
        $existing->execute([$orderId]);
        if ((int) $existing->fetchColumn() > 0) return;
        $items = $this->db->prepare('SELECT item_name, quantity, unit, unit_price FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $sale = $this->db->prepare('INSERT INTO sales (order_id, customer_id, description, quantity, unit, unit_price, sold_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL)');
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) $sale->execute([$orderId, $customerId, $item['item_name'], $item['quantity'], $item['unit'], $item['unit_price']]);
    }
}
