<?php
/**
 * File purpose: proves the expected userservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    /**
 * Runs the test removing customer login preserves linked customer record behavior under test.
 * @return void Result of this operation.
 */
    public function testRemovingCustomerLoginPreservesLinkedCustomerRecord(): void
    {
        $db = $this->database();
        $this->addUser($db, 1, 'admin');
        $this->addUser($db, 2, 'customer');
        $db->exec("INSERT INTO customers (id, user_id, name) VALUES (1, 2, 'Customer')");

        (new UserService($db))->delete(2, 1);

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users WHERE id = 2')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM customers WHERE id = 1 AND user_id IS NULL')->fetchColumn());
    }

    /**
 * Runs the test cannot remove own account behavior under test.
 * @return void Result of this operation.
 */
    public function testCannotRemoveOwnAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot remove your own account.');

        (new UserService($this->database()))->delete(1, 1);
    }

    /**
 * Runs the test cannot remove last active administrator behavior under test.
 * @return void Result of this operation.
 */
    public function testCannotRemoveLastActiveAdministrator(): void
    {
        $db = $this->database();
        $this->addUser($db, 1, 'admin');
        $this->addUser($db, 2, 'worker');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keep at least one active administrator.');

        (new UserService($db))->delete(1, 2);
    }

    /**
 * Runs the test can remove administrator when another active administrator remains behavior under test.
 * @return void Result of this operation.
 */
    public function testCanRemoveAdministratorWhenAnotherActiveAdministratorRemains(): void
    {
        $db = $this->database();
        $this->addUser($db, 1, 'admin');
        $this->addUser($db, 2, 'admin');

        (new UserService($db))->delete(2, 1);

        $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM users JOIN roles ON roles.id = users.role_id WHERE roles.name = 'admin' AND users.is_active = 1")->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users WHERE id = 2')->fetchColumn());
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                role_id INTEGER NOT NULL,
                full_name TEXT NOT NULL,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (role_id) REFERENCES roles (id)
            )'
        );
        $db->exec(
            'CREATE TABLE customers (
                id INTEGER PRIMARY KEY,
                user_id INTEGER UNIQUE,
                name TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            )'
        );
        $db->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'worker'), (3, 'customer')");
        return $db;
    }

    /**
 * Handles the add user operation for the API router.
 * @param mixed $db Value supplied to this operation.
 * @param mixed $id Value supplied to this operation.
 * @param mixed $role Value supplied to this operation.
 * @param mixed $active Value supplied to this operation.
 * @return void Result of this operation.
 */
    private function addUser(PDO $db, int $id, string $role, bool $active = true): void
    {
        $roleId = ['admin' => 1, 'worker' => 2, 'customer' => 3][$role];
        $statement = $db->prepare(
            'INSERT INTO users (id, role_id, full_name, username, email, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, $roleId, "User {$id}", "user{$id}", "user{$id}@example.test", $active ? 1 : 0]);
    }
}
