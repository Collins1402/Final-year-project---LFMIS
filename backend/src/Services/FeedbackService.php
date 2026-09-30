<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class FeedbackService
{
    public function __construct(private PDO $db) {}
    public function list(): array
    {
        return $this->db->query('SELECT feedback.id, feedback.category, feedback.message, feedback.status, feedback.created_at, users.full_name AS user_name FROM feedback LEFT JOIN users ON users.id=feedback.user_id ORDER BY feedback.created_at DESC LIMIT 300')->fetchAll();
    }
    public function create(array $input, int $userId): array
    {
        $category=(string)($input['category']??'feedback'); $message=trim((string)($input['message']??''));
        if (!in_array($category,['feedback','complaint','suggestion'],true)||$message==='') throw new \InvalidArgumentException('Choose a feedback type and enter a message.');
        $s=$this->db->prepare('INSERT INTO feedback (user_id, category, message) VALUES (?, ?, ?)'); $s->execute([$userId,$category,$message]); return ['id'=>(int)$this->db->lastInsertId()];
    }
    public function resolve(int $id, int $userId): void
    {
        $s=$this->db->prepare("UPDATE feedback SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=?"); $s->execute([$userId,$id]);
    }
}
