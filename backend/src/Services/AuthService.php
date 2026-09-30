<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Database;
use Lfmis\Core\Session;
use PDO;

final class AuthService
{
    public function __construct(private PDO $db)
    {
    }

    public function currentUser(): ?array
    {
        $userId = Session::userId();
        if ($userId === null) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT users.id, users.full_name, users.username, users.email, users.phone,
                    users.email_verified_at, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.is_active = 1 AND users.email_verified_at IS NOT NULL'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user) {
            Session::logout();
            return null;
        }
        return $this->formatUser($user);
    }

    public function createFirstAdmin(array $input): array
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
            throw new \DomainException('Initial setup has already been completed.');
        }
        $name = trim((string) ($input['fullName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($input['password'] ?? '');
        $this->validateIdentity($name, $username, $email, $password);
        $roleId = $this->roleId('admin');
        $statement = $this->db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?, 0)'
        );
        $statement->execute([$roleId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->db->lastInsertId();
        return $this->findUser($id);
    }

    public function login(string $login, string $password): array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            throw new \InvalidArgumentException('Enter your username or email and password.');
        }
        $column = strpos($login, '@') !== false ? 'email' : 'username';
        $statement = $this->db->prepare(
            "SELECT users.*, roles.name AS role FROM users JOIN roles ON roles.id = users.role_id WHERE users.{$column} = ? LIMIT 1"
        );
        $statement->execute([$login]);
        $user = $statement->fetch();
        if (!$user || ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            if ($user) {
                $this->recordFailedLogin($user);
            }
            throw new \RuntimeException('Username/email or password is incorrect.');
        }
        if (!(int) $user['is_active']) {
            throw new \RuntimeException('This account is inactive. Contact the farm administrator.');
        }
        if ($user['email_verified_at'] === null) {
            throw new \RuntimeException('Confirm your email address before signing in.');
        }
        $this->db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        Session::login((int) $user['id']);
        return $this->currentUser() ?? throw new \RuntimeException('Could not load your account.');
    }

    public function register(array $input): array
    {
        $name = trim((string) ($input['fullName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($input['password'] ?? '');
        $role = 'customer';
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) || $email === false || strlen($password) < 12 || !in_array($role, ['customer', 'buyer', 'worker', 'veterinarian'], true)) {
            throw new \InvalidArgumentException('Enter valid details, choose a supported account type and use a password of at least 12 characters.');
        }
        $roleId = $this->roleId($role);
        $statement = $this->db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?, 0)'
        );
        $statement->execute([$roleId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return $this->findUser((int) $this->db->lastInsertId());
    }

    private function recordFailedLogin(array $user): void
    {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $this->db->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')->execute([$attempts, $lockedUntil, $user['id']]);
    }

    private function validateIdentity(string $name, string $username, mixed $email, string $password): void
    {
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) || $email === false || strlen($password) < 12) {
            throw new \InvalidArgumentException('Enter a name, valid username, valid email and a password of at least 12 characters.');
        }
    }

    private function roleId(string $role): int
    {
        $statement = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
        $statement->execute([$role]);
        return (int) $statement->fetchColumn();
    }

    private function formatUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'fullName' => $user['full_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'emailVerified' => $user['email_verified_at'] !== null,
            'phone' => $user['phone'],
            'role' => $user['role'],
        ];
    }

    private function findUser(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT users.id, users.full_name, users.username, users.email, users.phone,
                    users.email_verified_at, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id WHERE users.id = ?'
        );
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new \RuntimeException('Could not load the account.');
        }
        return $this->formatUser($user);
    }
}
