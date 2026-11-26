<?php
/**
 * File purpose: implements AuthService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Database;
use Lfmis\Core\Session;
use PDO;

final class AuthService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db)
    {
    }

    /**
 * Performs the current user operation for this service.
 * @return ?array Result of this operation.
 */
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
        $formatted = $this->formatUser($user);
        if ($formatted['role'] === 'admin') {
            try {
                $enabled = $this->db->prepare('SELECT totp_enabled_at FROM users WHERE id = ?');
                $enabled->execute([$userId]);
                $formatted['mustEnable2fa'] = $enabled->fetchColumn() === null;
            } catch (\PDOException) { $formatted['mustEnable2fa'] = false; }
        }
        return $formatted;
    }

    /**
 * Performs the create first admin operation for this service.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
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
        // Store only a one-way password hash so a database read cannot reveal the submitted password.
        $statement->execute([$roleId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->db->lastInsertId();
        return $this->findUser($id);
    }

    /**
 * Validates credentials and starts or prepares a secure sign-in.
 * @param string $login Input used by this operation.
 * @param string $password Input used by this operation.
 * @param ?string $trustedDeviceToken Input used by this operation.
 * @return array Result of this operation.
 */
    public function login(string $login, string $password, ?string $trustedDeviceToken = null): array
    {
        unset($_SESSION['pending_2fa']);
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
        // Verify the password hash and honor the temporary lockout before revealing account state.
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
        if (!empty($user['totp_enabled_at'])) {
            $device = $this->db->prepare('SELECT 1 FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > ?');
            $device->execute([(int) $user['id'], $trustedDeviceToken && preg_match('/^[a-f0-9]{64}$/', $trustedDeviceToken) ? hash('sha256', $trustedDeviceToken) : '', gmdate('Y-m-d H:i:s')]);
            if (!$device->fetchColumn()) {
                unset($_SESSION['user_id']);
                return ['twoFactorRequired' => true, 'methods' => ['totp'], 'pendingUserId' => (int) $user['id']];
            }
        }
        $methods = $this->db->prepare('SELECT method FROM user_two_factor_methods WHERE user_id = ? ORDER BY method');
        $methods->execute([(int) $user['id']]);
        $enabledMethods = $methods->fetchAll(PDO::FETCH_COLUMN);
        // A configured second factor must be completed before the session is authenticated.
        if ($enabledMethods !== []) {
            unset($_SESSION['user_id']);
            return [
                'twoFactorRequired' => true,
                'methods' => $enabledMethods,
                'pendingUserId' => (int) $user['id'],
            ];
        }
        return $this->completeLogin((int) $user['id']);
    }

    /**
 * Rechecks account eligibility, resets lockout state, and starts the session.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function completeLogin(int $userId): array
    {
        $account = $this->db->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND email_verified_at IS NOT NULL');
        $account->execute([$userId]);
        if (!$account->fetchColumn()) {
            throw new \RuntimeException('This account is no longer available for sign-in.');
        }
        $this->db->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = ?
             WHERE id = ? AND is_active = 1 AND email_verified_at IS NOT NULL'
        )->execute([gmdate('Y-m-d H:i:s'), $userId]);
        Session::login($userId);
        return $this->currentUser() ?? throw new \RuntimeException('Could not load your account.');
    }

    /**
 * Validates and creates a new account.
 * @param array $input Input used by this operation.
 * @return array Result of this operation.
 */
    public function register(array $input): array
    {
        $name = trim((string) ($input['fullName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? 'customer');
        $phone = trim((string) ($input['phone'] ?? '')) ?: null;
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) || $email === false || strlen($password) < 12 || !in_array($role, ['customer', 'buyer'], true)) {
            throw new \InvalidArgumentException('Enter valid details, choose customer or livestock buyer access and use a password of at least 12 characters.');
        }
        $roleId = $this->roleId($role);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO users (role_id, full_name, username, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            // Passwords are persisted as adaptive hashes, never as readable text.
            $statement->execute([$roleId, $name, $username, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $this->db->lastInsertId();
            if (in_array($role, ['customer', 'buyer'], true)) {
                $this->db->prepare('INSERT INTO customers (user_id, name, phone, email) VALUES (?, ?, ?, ?)')->execute([$userId, $name, $phone, $email]);
            }
            $this->db->commit();
            return $this->findUser($userId);
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /**
 * Performs the record failed login operation for this service.
 * @param array $user Input used by this operation.
 * @return void Result of this operation.
 */
    private function recordFailedLogin(array $user): void
    {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $this->db->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')->execute([$attempts, $lockedUntil, $user['id']]);
    }

    /**
 * Performs the validate identity operation for this service.
 * @param string $name Input used by this operation.
 * @param string $username Input used by this operation.
 * @param mixed $email Input used by this operation.
 * @param string $password Input used by this operation.
 * @return void Result of this operation.
 */
    private function validateIdentity(string $name, string $username, mixed $email, string $password): void
    {
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) || $email === false || strlen($password) < 12) {
            throw new \InvalidArgumentException('Enter a name, valid username, valid email and a password of at least 12 characters.');
        }
    }

    /**
 * Performs the role id operation for this service.
 * @param string $role Input used by this operation.
 * @return int Result of this operation.
 */
    private function roleId(string $role): int
    {
        $statement = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
        $statement->execute([$role]);
        return (int) $statement->fetchColumn();
    }

    /**
 * Performs the format user operation for this service.
 * @param array $user Input used by this operation.
 * @return array Result of this operation.
 */
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

    /**
 * Performs the find user operation for this service.
 * @param int $id Input used by this operation.
 * @return array Result of this operation.
 */
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
