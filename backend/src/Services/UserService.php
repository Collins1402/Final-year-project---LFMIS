<?php
/**
 * File purpose: implements UserService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Session;
use PDO;

final class UserService
{
    private const ROLES = ['admin', 'worker', 'veterinarian', 'customer', 'buyer'];

    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db)
    {
    }

    /**
 * Returns the matching records for the requested scope.
 * @return array Result of this operation.
 */
    public function list(): array
    {
        return $this->db->query(
            'SELECT users.id, users.full_name, users.username, users.email, users.phone,
                    users.is_active, users.created_at, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.created_at DESC'
        )->fetchAll();
    }

    /**
 * Validates input and creates a new record.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
    public function create(array $input): array
    {
        $name = trim((string) ($input['fullName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string) ($input['phone'] ?? '')) ?: null;
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) || $email === false || strlen($password) < 12 || !in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Enter valid account details, a supported role and a password of at least 12 characters.');
        }
        $roleId = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
        $roleId->execute([$role]);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO users (role_id, full_name, username, email, phone, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $statement->execute([(int) $roleId->fetchColumn(), $name, $username, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $this->db->lastInsertId();
            if (in_array($role, ['customer', 'buyer'], true)) {
                $this->db->prepare('INSERT INTO customers (user_id, name, phone, email) VALUES (?, ?, ?, ?)')->execute([$userId, $name, $phone, $email]);
            }
            $this->db->commit();
            return $this->find($userId);
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /**
 * Validates input and updates the matching record.
 * @param int $id Input used by this operation.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
    public function update(int $id, array $input): array
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid user id.');
        }
        $current = $this->find($id);
        $active = filter_var($input['isActive'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $role = isset($input['role']) ? (string) $input['role'] : null;
        if ($role !== null && !in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unsupported user role.');
        }
        if ($active === false && $id === Session::userId()) {
            throw new \InvalidArgumentException('You cannot deactivate your own account.');
        }
        if ($active === false && $current['role'] === 'admin' && $this->activeAdminCount() <= 1) {
            throw new \InvalidArgumentException('Keep at least one active administrator.');
        }
        $fields = [];
        $values = [];
        if ($active !== null) {
            $fields[] = 'is_active = ?';
            $values[] = $active ? 1 : 0;
        }
        if ($role !== null) {
            $roleId = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
            $roleId->execute([$role]);
            $fields[] = 'role_id = ?';
            $values[] = (int) $roleId->fetchColumn();
        }
        if (!$fields) {
            throw new \InvalidArgumentException('Provide isActive or role to update.');
        }
        $values[] = $id;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
        return $this->find($id);
    }

    /**
 * Removes the requested record after applying this service operation.
 * @param int $id Input used by this operation.
 * @param int $actingUserId Input used by this operation.
 * @return void Result of this operation.
 */
    public function delete(int $id, int $actingUserId): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid user id.');
        }
        if ($id === $actingUserId) {
            throw new \InvalidArgumentException('You cannot remove your own account.');
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT users.is_active, roles.name AS role
                 FROM users JOIN roles ON roles.id = users.role_id
                 WHERE users.id = ?'
            );
            $statement->execute([$id]);
            $user = $statement->fetch();
            if (!$user) {
                throw new \InvalidArgumentException('User not found.');
            }
            if ($user['role'] === 'admin' && $this->activeAdminCount(true) <= (int) $user['is_active']) {
                throw new \InvalidArgumentException('Keep at least one active administrator.');
            }

            $delete = $this->db->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$id]);
            if ($delete->rowCount() !== 1) {
                throw new \RuntimeException('User could not be removed.');
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
 * Loads one record by its identifier.
 * @param int $id Input used by this operation.
 * @return array Result of this operation.
 */
    public function find(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT users.id, users.full_name, users.username, users.email, users.phone,
                    users.is_active, users.created_at, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id WHERE users.id = ?'
        );
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }
        $user['id'] = (int) $user['id'];
        $user['is_active'] = (bool) $user['is_active'];
        return $user;
    }

    /**
 * Performs the active admin count operation for this service.
 * @param bool $lock Input used by this operation.
 * @return int Result of this operation.
 */
    private function activeAdminCount(bool $lock = false): int
    {
        $sql = "SELECT users.id FROM users JOIN roles ON roles.id = users.role_id WHERE roles.name = 'admin' AND users.is_active = 1";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        return count($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }
}
