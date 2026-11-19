<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AuthService;
use Lfmis\Services\AuditService;
use Lfmis\Services\CommerceService;
use Lfmis\Services\PermissionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testWorkerCannotAccessAdminBackupPermission(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE permissions (code TEXT PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_code TEXT, is_allowed INTEGER)');
        $db->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'worker')");
        $db->exec("INSERT INTO permissions VALUES ('backup.create', 'Create database backup')");
        $db->exec("INSERT INTO role_permissions VALUES (1, 'backup.create', 1)");

        $this->assertFalse((new PermissionService($db))->allows('worker', 'backup.create'));
    }

    public function testCustomerCannotReadAnotherCustomersOrders(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, phone TEXT)');
        $db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, status TEXT, total_amount REAL, ordered_at TEXT)');
        $db->exec("INSERT INTO customers VALUES (1, 11, 'Customer One', '0711000001'), (2, 22, 'Customer Two', '0711000002')");
        $db->exec("INSERT INTO orders VALUES (1, 1, 'pending', 100, '2026-01-01'), (2, 2, 'pending', 200, '2026-01-02')");

        $orders = (new CommerceService($db))->orders(11);

        $this->assertCount(1, $orders);
        $this->assertSame('Customer One', $orders[0]['customer']);
    }

    public function testSqlInjectionLoginInputReturnsNormalCredentialError(): void
    {
        $db = $this->authDatabase();

        try {
            (new AuthService($db))->login("' OR 1=1 --", 'anything');
            $this->fail('Injected login text must not authenticate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Username/email or password is incorrect.', $error->getMessage());
        }
    }

    public function testSqlInjectionSearchFilterRemainsAParameterizedSearch(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)');
        $db->exec("INSERT INTO audit_logs VALUES (1, 1, 'login', 'auth', 1, '{}', NULL, '2026-01-01')");

        $result = (new AuditService($db))->list(['action' => "' OR 1=1 --"]);

        $this->assertSame([], $result);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
    }

    public function testLockedAndExpiredAccountsCannotSignIn(): void
    {
        $db = $this->authDatabase(true);
        $service = new AuthService($db);

        try {
            $service->login('locked_user', 'correct-password-123');
            $this->fail('A locked account must not sign in.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Username/email or password is incorrect.', $error->getMessage());
        }

        $db->exec("UPDATE users SET locked_until = NULL, is_active = 0 WHERE username = 'locked_user'");
        try {
            $service->login('locked_user', 'correct-password-123');
            $this->fail('An expired/inactive account must not sign in.');
        } catch (\RuntimeException $error) {
            $this->assertSame('This account is inactive. Contact the farm administrator.', $error->getMessage());
        }
    }

    private function authDatabase(bool $withUser = false): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                role_id INTEGER,
                full_name TEXT,
                username TEXT,
                email TEXT,
                phone TEXT,
                email_verified_at TEXT,
                password_hash TEXT,
                is_active INTEGER,
                failed_login_attempts INTEGER DEFAULT 0,
                locked_until TEXT NULL,
                last_login_at TEXT NULL
            )'
        );
        $db->exec('CREATE TABLE user_two_factor_methods (user_id INTEGER, method TEXT)');
        $db->exec("INSERT INTO roles VALUES (1, 'customer')");
        if ($withUser) {
            $insert = $db->prepare('INSERT INTO users VALUES (1, 1, ?, ?, ?, NULL, CURRENT_TIMESTAMP, ?, ?, 0, ?, NULL)');
            $insert->execute(['Locked User', 'locked_user', 'locked@example.test', password_hash('correct-password-123', PASSWORD_DEFAULT), 1, date('Y-m-d H:i:s', time() + 600)]);
        }
        return $db;
    }
}
