<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ActivityService
{
    public function __construct(private PDO $db) {}

    public function list(?int $userId, string $role): array
    {
        $sql = 'SELECT farm_activities.id, farm_activities.activity_type, farm_activities.description, farm_activities.animal_id,
                   farm_activities.assigned_to, farm_activities.activity_at, farm_activities.status, farm_activities.completed_at,
                       animals.tag_number, users.full_name AS assigned_name
                FROM farm_activities LEFT JOIN animals ON animals.id = farm_activities.animal_id
                LEFT JOIN users ON users.id = farm_activities.assigned_to';
        if ($role === 'worker') {
            $sql .= ' WHERE farm_activities.assigned_to = ? OR farm_activities.assigned_to IS NULL';
        }
        $sql .= ' ORDER BY farm_activities.activity_at DESC LIMIT 300';
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
        $healthConcern = in_array($input['healthConcern'] ?? false, [true, 1, '1', 'yes', 'on'], true);
        $symptoms = trim((string) ($input['symptoms'] ?? ''));
        $allowed = ['feeding', 'milking', 'cleaning', 'breeding', 'health', 'maintenance', 'other'];
        if (!in_array($type, $allowed, true) || $description === '' || !$at) {
            throw new \InvalidArgumentException('Enter a valid activity type, description and date.');
        }
        if ($healthConcern && ($animalId === null || $symptoms === '' || strlen($symptoms) > 220)) {
            throw new \InvalidArgumentException('Choose an animal and describe the health symptoms (up to 220 characters).');
        }

        $this->db->beginTransaction();
        try {
            if ($healthConcern) {
                $animal = $this->db->prepare("SELECT COUNT(*) FROM animals WHERE id = ? AND status = 'active'");
                $animal->execute([$animalId]);
                if ((int) $animal->fetchColumn() === 0) {
                    throw new \InvalidArgumentException('Choose an active animal for the health concern.');
                }
            }

            $statement = $this->db->prepare(
                'INSERT INTO farm_activities (activity_type, description, animal_id, assigned_to, activity_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$type, $description, $animalId, $input['assignedTo'] ?? null, $at->format('Y-m-d H:i:s'), $userId]);
            $activityId = (int) $this->db->lastInsertId();
            $healthRecordId = null;
            if ($healthConcern) {
                $record = $this->db->prepare(
                    "INSERT INTO health_records (animal_id, record_type, description, record_date, recorded_by)
                     VALUES (?, 'issue', ?, ?, ?)"
                );
                $record->execute([$animalId, $symptoms, $at->format('Y-m-d'), $userId]);
                $healthRecordId = (int) $this->db->lastInsertId();
                $this->notifyHealthStaff($activityId, $healthRecordId, (string) $animalId, $symptoms);
            }
            $activity = $this->find($activityId);
            if ($healthRecordId !== null) {
                $activity['health_record_id'] = $healthRecordId;
            }
            $this->db->commit();
            return $activity;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
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

    private function notifyHealthStaff(int $activityId, int $healthRecordId, string $animalId, string $symptoms): void
    {
        $staff = $this->db->prepare(
            "SELECT users.id FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND roles.name IN ('admin', 'veterinarian')"
        );
        $staff->execute();
        $ignore = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $notification = $this->db->prepare(
            "$ignore INTO notifications (user_id, type, entity_type, entity_id, title, message, dedupe_key)
             VALUES (?, 'system', 'health_record', ?, 'Animal health concern recorded', ?, ?)"
        );
        $message = 'Activity #' . $activityId . ' reported symptoms for animal #' . $animalId . ': ' . $symptoms;
        foreach ($staff->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $notification->execute([
                $userId,
                $healthRecordId,
                $message,
                'activity-health-concern:' . $activityId,
            ]);
        }
    }
}
