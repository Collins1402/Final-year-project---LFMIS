<?php
/**
 * File purpose: implements NotificationService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class NotificationService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}

    /**
 * Returns the matching records for the requested scope.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function list(int $userId): array
    {
        $statement = $this->db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read, created_at DESC LIMIT 100');
        $statement->execute([$userId]); return $statement->fetchAll();
    }

    /**
 * Performs the generate for user operation for this service.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function generateForUser(int $userId): void
    {
        $dueThrough = date('Y-m-d', strtotime('+7 days'));
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'health_due', 'Health follow-up due', CONCAT(COUNT(*), ' health record(s) need attention.'), CONCAT('health:', CURRENT_DATE()) FROM health_records hr JOIN animals a ON a.id = hr.animal_id WHERE " . HealthService::OPEN_FOLLOW_UP . " AND hr.next_due_date IS NOT NULL AND hr.next_due_date <= ? HAVING COUNT(*) > 0")->execute([$userId, $dueThrough]);
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'low_stock', 'Low stock', CONCAT(COUNT(*), ' inventory item(s) need replenishment.'), CONCAT('stock:', CURRENT_DATE()) FROM inventory_items WHERE is_active=1 AND quantity_on_hand <= reorder_level HAVING COUNT(*) > 0")->execute([$userId]);
        $this->db->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, dedupe_key) SELECT ?, 'order_pending', 'Orders need review', CONCAT(COUNT(*), ' order(s) are awaiting action.'), CONCAT('orders:', CURRENT_DATE()) FROM orders WHERE status IN ('pending','quoted') HAVING COUNT(*) > 0")->execute([$userId]);
    }

    /**
 * Performs the add feedback notification operation for this service.
 * @param int $feedbackId Input used by this operation.
 * @param string $category Input used by this operation.
 * @param string $senderName Input used by this operation.
 * @param string $message Input used by this operation.
 * @return void Result of this operation.
 */
    public function addFeedbackNotification(int $feedbackId, string $category, string $senderName, string $message): void
    {
        $admins = $this->db->query(
            "SELECT users.id FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND roles.name = 'admin'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ignore = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $statement = $this->db->prepare(
            "$ignore INTO notifications (user_id, type, entity_type, entity_id, title, message, dedupe_key)
             VALUES (?, 'feedback', 'feedback', ?, ?, ?, ?)"
        );
        $title = ucfirst($category) . ' received';
        $preview = function_exists('mb_substr') ? mb_substr(trim($message), 0, 260) : substr(trim($message), 0, 260);
        foreach ($admins as $adminId) {
            $statement->execute([
                $adminId,
                $feedbackId,
                $title,
                $senderName . ': ' . $preview,
                'feedback:' . $feedbackId,
            ]);
        }
    }

    /**
 * Performs the mark read operation for this service.
 * @param int $id Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function markRead(int $id, int $userId): void
    {
        $statement = $this->db->prepare('UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?'); $statement->execute([$id, $userId]);
    }
}
