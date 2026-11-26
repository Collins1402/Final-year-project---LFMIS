<?php
/**
 * File purpose: implements ReminderService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Mailer;
use PDO;

final class ReminderService
{
    private ?Closure $sendEmail;

    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @param ?Closure $sendEmail Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db, ?Closure $sendEmail = null)
    {
        $this->sendEmail = $sendEmail;
    }

    /**
 * Performs the send due operation for this service.
 * @param ?string $today Input used by this operation.
 * @return array Result of this operation.
 */
    public function sendDue(?string $today = null): array
    {
        $date = $today ?? date('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Use a valid YYYY-MM-DD reminder date.');
        }

        $due = $this->db->prepare('SELECT id FROM health_records WHERE next_due_date IS NOT NULL AND next_due_date <= ? AND reminded_at IS NULL ORDER BY next_due_date, id');
        $due->execute([$date]);
        $ids = array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN));
        $result = ['selected' => count($ids), 'sent' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $this->db->beginTransaction();
            try {
                $query = 'SELECT hr.id, hr.record_type, hr.description, hr.next_due_date, a.tag_number
                          FROM health_records hr JOIN animals a ON a.id = hr.animal_id
                          WHERE hr.id = ? AND hr.next_due_date <= ? AND hr.reminded_at IS NULL';
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $query .= ' FOR UPDATE';
                }
                $recordQuery = $this->db->prepare($query);
                $recordQuery->execute([$id, $date]);
                $record = $recordQuery->fetch(PDO::FETCH_ASSOC);
                if (!$record) {
                    $this->db->commit();
                    continue;
                }

                $this->db->prepare('UPDATE health_records SET reminder_attempts = reminder_attempts + 1 WHERE id = ?')->execute([$id]);
                $this->notifyStaff($record);
                try {
                    $this->emailAdministrator($record);
                } catch (DeliveryException $error) {
                    $this->db->prepare('UPDATE health_records SET reminder_error = ? WHERE id = ?')
                        ->execute([substr($error->getMessage(), 0, 255), $id]);
                    $this->db->commit();
                    $result['failed']++;
                    continue;
                }

                $this->db->prepare('UPDATE health_records SET reminded_at = CURRENT_TIMESTAMP, reminder_error = NULL WHERE id = ?')->execute([$id]);
                $this->db->commit();
                $result['sent']++;
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }
        return $result;
    }

    /**
 * Performs the notify staff operation for this service.
 * @param array $record Input used by this operation.
 * @return void Result of this operation.
 */
    private function notifyStaff(array $record): void
    {
        $staff = $this->db->query(
            "SELECT users.id FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND roles.name IN ('admin', 'worker', 'veterinarian')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ignore = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $statement = $this->db->prepare(
            "$ignore INTO notifications (user_id, type, entity_type, entity_id, title, message, dedupe_key)
             VALUES (?, 'health_due', 'health_record', ?, 'Health follow-up due', ?, ?)"
        );
        $message = 'Health follow-up due for animal ' . $record['tag_number'] . ' on ' . $record['next_due_date'] . '.';
        foreach ($staff as $userId) {
            $statement->execute([
                $userId,
                (int) $record['id'],
                $message,
                'health-reminder:' . (int) $record['id'],
            ]);
        }
    }

    /**
 * Performs the email administrator operation for this service.
 * @param array $record Input used by this operation.
 * @return void Result of this operation.
 */
    private function emailAdministrator(array $record): void
    {
        $administrator = $this->db->query(
            "SELECT users.email FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND roles.name = 'admin' ORDER BY users.id LIMIT 1"
        )->fetchColumn();
        $email = filter_var((string) $administrator, FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            throw new DeliveryException('No active administrator email address is available for health reminders.');
        }

        $subject = 'Health follow-up due for animal ' . $record['tag_number'];
        $body = sprintf(
            "A health follow-up is due for animal %s.\n\nRecord: %s\nDetails: %s\nDue date: %s\n",
            $record['tag_number'],
            $record['record_type'],
            $record['description'],
            $record['next_due_date']
        );
        $sent = $this->sendEmail !== null
            ? ($this->sendEmail)($email, $subject, $body)
            : Mailer::send($email, $subject, $body);
        if ($sent === false) {
            throw new DeliveryException('Email delivery failed. Check the SMTP settings and provider logs.');
        }
    }
}
