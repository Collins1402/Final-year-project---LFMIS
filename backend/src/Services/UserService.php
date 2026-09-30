<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Session;
use PDO;

final class UserService
{
    private const ROLES = ['admin', 'worker', 'veterinarian', 'customer'];

    public function __construct(private PDO $db)
    {
    }

    public function list(): array
    {
        return $this->db->query(
            'SELECT users.id, users.full_name, users.username, users.email, users.phone,
                    users.is_active, users.created_at, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.created_at DESC'
        )->fetchAll();
    }

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
        $statement = $this->db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, phone, password_hash) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([(int) $roleId->fetchColumn(), $name, $username, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
        return $this->find((int) $this->db->lastInsertId());
    }

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

    private function activeAdminCount(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM users JOIN roles ON roles.id = users.role_id WHERE roles.name = 'admin' AND users.is_active = 1"
        )->fetchColumn();
    }
}
