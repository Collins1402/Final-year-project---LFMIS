<?php
/**
 * File purpose: implements FeedbackService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Mailer;
use PDO;

final class FeedbackService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}
    /**
 * Returns the matching records for the requested scope.
 * @param ?int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function list(?int $userId = null): array
    {
        $query = 'SELECT feedback.id, feedback.category, feedback.message, feedback.status, feedback.created_at, users.full_name AS user_name
                  FROM feedback LEFT JOIN users ON users.id=feedback.user_id';
        if ($userId !== null) {
            $query .= ' WHERE feedback.user_id = ?';
        }
        $query .= ' ORDER BY feedback.created_at DESC LIMIT 300';
        $statement = $this->db->prepare($query);
        $statement->execute($userId === null ? [] : [$userId]);
        return $statement->fetchAll();
    }
    /**
 * Validates input and creates a new record.
 * @param array $input Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function create(array $input, int $userId): array
    {
        $category=(string)($input['category']??'feedback'); $message=trim((string)($input['message']??''));
        if (!in_array($category,['feedback','complaint','suggestion','compliment'],true)||$message==='') throw new \InvalidArgumentException('Choose a feedback type and enter a message.');
        $s=$this->db->prepare('INSERT INTO feedback (user_id, category, message) VALUES (?, ?, ?)');
        $s->execute([$userId,$category,$message]);
        $id = (int) $this->db->lastInsertId();
        $user = $this->db->prepare('SELECT full_name, email FROM users WHERE id = ?');
        $user->execute([$userId]);
        $sender = $user->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'there', 'email' => ''];
        if (in_array($category, ['feedback', 'compliment'], true)) {
            $subject = 'Thank you for your feedback to Flouri Farm';
            $body = "Hello {$sender['full_name']},\n\nThank you for sharing your feedback with Flouri Farm. We appreciate you taking the time to write to us.\n\nYour message has been received and added to our records.\n\nFlouri Farm";
        } else {
            $subject = 'We are reviewing your feedback to Flouri Farm';
            $body = "Hello {$sender['full_name']},\n\nWe have received your {$category} and our team is currently looking into it. We will follow up if we need more details.\n\nThank you for letting us know.\n\nFlouri Farm";
        }
        $emailSent = filter_var((string) $sender['email'], FILTER_VALIDATE_EMAIL) !== false
            && Mailer::send((string) $sender['email'], $subject, $body);
        return ['id' => $id, 'acknowledgementSent' => $emailSent];
    }
    /**
 * Marks the selected feedback record as resolved.
 * @param int $id Input used by this operation.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function resolve(int $id, int $userId): void
    {
        $s=$this->db->prepare("UPDATE feedback SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=?"); $s->execute([$userId,$id]);
    }

    /** Resolve every open feedback message and record which staff member handled the action. */
    /**
 * Marks all currently open feedback records as resolved.
 * @param int $userId Input used by this operation.
 * @return int Result of this operation.
 */
    public function resolveAll(int $userId): int
    {
        $statement = $this->db->prepare("UPDATE feedback SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE status='open'");
        $statement->execute([$userId]);
        return $statement->rowCount();
    }
}
