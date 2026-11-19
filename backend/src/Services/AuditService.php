<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class AuditService
{
    public function __construct(private PDO $db)
    {
    }

    public function record(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $details = [],
        ?string $ipAddress = null
    ): void {
        $action = trim($action);
        $entityType = trim($entityType);
        if ($action === '' || strlen($action) > 40 || $entityType === '' || strlen($entityType) > 60) {
            throw new \InvalidArgumentException('Audit action and entity type must be valid.');
        }
        if (($userId !== null && $userId < 1) || ($entityId !== null && $entityId < 1)) {
            throw new \InvalidArgumentException('Audit identifiers must be positive.');
        }
        $ipAddress ??= isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
        if ($ipAddress !== null && strlen($ipAddress) > 45) {
            $ipAddress = null;
        }
        $encodedDetails = $details === []
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $action, $entityType, $entityId, $encodedDetails, $ipAddress]);
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $values = [];
        if (isset($filters['userId']) && $filters['userId'] !== '') {
            $userId = filter_var($filters['userId'], FILTER_VALIDATE_INT);
            if ($userId === false || $userId < 1) {
                throw new \InvalidArgumentException('Choose a valid user filter.');
            }
            $where[] = 'audit_logs.user_id = ?';
            $values[] = $userId;
        }
        if (isset($filters['action']) && trim((string) $filters['action']) !== '') {
            $action = trim((string) $filters['action']);
            if (strlen($action) > 40) {
                throw new \InvalidArgumentException('Audit action filters cannot exceed 40 characters.');
            }
            $where[] = 'audit_logs.action LIKE ?';
            $values[] = '%' . $action . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && trim((string) $filters[$key]) !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filters[$key]);
                if ($date === false || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new \InvalidArgumentException('Audit date filters must use YYYY-MM-DD.');
                }
                $where[] = 'audit_logs.created_at ' . $operator . ' ?';
                $values[] = $key === 'from' ? $date->format('Y-m-d 00:00:00') : $date->format('Y-m-d 23:59:59');
            }
        }
        $sql = 'SELECT audit_logs.id, audit_logs.user_id, users.full_name AS user_name, audit_logs.action,
                       audit_logs.entity_type, audit_logs.entity_id, audit_logs.details, audit_logs.ip_address, audit_logs.created_at
                FROM audit_logs LEFT JOIN users ON users.id = audit_logs.user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY audit_logs.created_at DESC, audit_logs.id DESC LIMIT 500';
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
