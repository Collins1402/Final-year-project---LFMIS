<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ActivityService
{
    public function __construct(private PDO $db) {}

    public function list(?int $userId, string $role): array
    {
        $sql = 'SELECT farm_activities.id, activity_type, description, animal_id, assigned_to, activity_at, status, completed_at,
                       animals.tag_number, users.full_name AS assigned_name
                FROM farm_activities LEFT JOIN animals ON animals.id = farm_activities.animal_id
                LEFT JOIN users ON users.id = farm_activities.assigned_to';
        if ($role === 'worker') {
            $sql .= ' WHERE assigned_to = ? OR assigned_to IS NULL';
        }
        $sql .= ' ORDER BY activity_at DESC LIMIT 300';
        $statement = $this->db->prepare($sql);
        $statement->execute($role === 'worker' ? [$userId] : []);
        return $statement->fetchAll();
    }

    public function create(array $input, int $userId): array
    {
        $type = (string) ($input['activityType'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        $at = date_create((string) ($input['activityAt'] ?? ''));
        $animalId = filter_var($input['animalId'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $allowed = ['feeding', 'milking', 'cleaning', 'breeding', 'health', 'maintenance', 'other'];
        if (!in_array($type, $allowed, true) || $description === '' || !$at) {
            throw new \InvalidArgumentException('Enter a valid activity type, description and date.');
        }
        $statement = $this->db->prepare(
            'INSERT INTO farm_activities (activity_type, description, animal_id, assigned_to, activity_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$type, $description, $animalId, $input['assignedTo'] ?? null, $at->format('Y-m-d H:i:s'), $userId]);
        return $this->find((int) $this->db->lastInsertId());
    }

    public function complete(int $id, int $userId): array
    {
        $statement = $this->db->prepare(
            "UPDATE farm_activities SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ? AND status = 'planned'"
        );
        $statement->execute([$userId, $id]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException('Planned activity not found.');
        }
        return $this->find($id);
    }

    private function find(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM farm_activities WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: throw new \RuntimeException('Activity not found.');
    }
}
