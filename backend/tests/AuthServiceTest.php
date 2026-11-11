<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use PHPUnit\Framework\TestCase;
use Lfmis\Services\AuthService;
use Lfmis\Core\Session;
use PDO;

final class AuthServiceTest extends TestCase
{
    public function testPasswordPolicyRequiresTwelveCharacters(): void
    {
        $this->assertGreaterThanOrEqual(12, strlen('a-secure-password'));
    }

    public function testCustomerAndBuyerRegistrationCreatesLinkedCustomerRecords(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER, full_name TEXT, username TEXT, email TEXT, phone TEXT, email_verified_at TEXT, password_hash TEXT, is_active INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER UNIQUE, name TEXT, phone TEXT, email TEXT)');
        $db->exec("INSERT INTO roles VALUES (1, 'customer'), (2, 'buyer')");
        $service = new AuthService($db);

        $buyer = $service->register(['fullName' => 'Buyer One', 'username' => 'buyer_one', 'email' => 'buyer@example.test', 'phone' => '0711000000', 'password' => 'long-enough-password', 'role' => 'buyer']);

        $this->assertSame('buyer', $buyer['role']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM customers WHERE user_id = 1')->fetchColumn());
        $this->assertSame('0711000000', $db->query('SELECT phone FROM customers WHERE user_id = 1')->fetchColumn());
    }

    public function testSelfRegistrationCannotGrantStaffRoles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('choose customer or livestock buyer access');

        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER, full_name TEXT, username TEXT, email TEXT, phone TEXT, email_verified_at TEXT, password_hash TEXT, is_active INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER UNIQUE, name TEXT, phone TEXT, email TEXT)');
        $db->exec("INSERT INTO roles VALUES (1, 'worker')");

        (new AuthService($db))->register(['fullName' => 'Worker One', 'username' => 'worker_one', 'email' => 'worker@example.test', 'password' => 'long-enough-password', 'role' => 'worker']);
    }

    public function testLoginWithTwoFactorDoesNotCreateAnAuthenticatedSession(): void
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
        $insert = $db->prepare(
            'INSERT INTO users (id, role_id, full_name, username, email, email_verified_at, password_hash, is_active)
             VALUES (1, 1, ?, ?, ?, CURRENT_TIMESTAMP, ?, 1)'
        );
        $insert->execute(['Farm Owner', 'farmer_one', 'farmer@example.test', password_hash('correct-horse-battery', PASSWORD_DEFAULT)]);
        $db->exec("INSERT INTO user_two_factor_methods VALUES (1, 'email')");
        $_SESSION = [];

        $result = (new AuthService($db))->login('farmer_one', 'correct-horse-battery');

        $this->assertTrue($result['twoFactorRequired']);
        $this->assertSame(['email'], $result['methods']);
        $this->assertNull(Session::userId());
    }
}
