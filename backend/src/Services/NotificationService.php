<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class NotificationService
{
    public function __construct(private PDO $db) {}

    public function list(int $userId): array
    {
        $statement = $this->db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read, created_at DESC LIMIT 100');
        $statement->execute([$userId]); return $statement->fetchAll();
    }

    public function generateForUser(int $userId): void
    {
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'health_due', 'Health follow-up due', CONCAT(COUNT(*), ' health record(s) need attention.'), CONCAT('health:', CURRENT_DATE()) FROM health_records WHERE next_due_date IS NOT NULL AND next_due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY) HAVING COUNT(*) > 0")->execute([$userId]);
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'low_stock', 'Low stock', CONCAT(COUNT(*), ' inventory item(s) need replenishment.'), CONCAT('stock:', CURRENT_DATE()) FROM inventory_items WHERE is_active=1 AND quantity_on_hand <= reorder_level HAVING COUNT(*) > 0")->execute([$userId]);
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'order_pending', 'Orders need review', CONCAT(COUNT(*), ' order(s) are awaiting action.'), CONCAT('orders:', CURRENT_DATE()) FROM orders WHERE status IN ('pending','quoted') HAVING COUNT(*) > 0")->execute([$userId]);
    }

    public function markRead(int $id, int $userId): void
    {
        $statement = $this->db->prepare('UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?'); $statement->execute([$id, $userId]);
    }
}
