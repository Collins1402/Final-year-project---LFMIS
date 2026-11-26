<?php
/**
 * File purpose: proves the expected customerdataisolation behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\CommerceService;
use Lfmis\Services\FeedbackService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CustomerDataIsolationTest extends TestCase
{
    /**
 * Runs the test orders can be scoped to the signed in customer behavior under test.
 * @return void Result of this operation.
 */
    public function testOrdersCanBeScopedToTheSignedInCustomer(): void
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

    /**
 * Runs the test feedback can be scoped to the signed in customer behavior under test.
 * @return void Result of this operation.
 */
    public function testFeedbackCanBeScopedToTheSignedInCustomer(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)');
        $db->exec('CREATE TABLE feedback (id INTEGER PRIMARY KEY, user_id INTEGER, category TEXT, message TEXT, status TEXT, created_at TEXT)');
        $db->exec("INSERT INTO users VALUES (11, 'Customer One'), (22, 'Customer Two')");
        $db->exec("INSERT INTO feedback VALUES (1, 11, 'feedback', 'One', 'open', '2026-01-01'), (2, 22, 'complaint', 'Two', 'open', '2026-01-02')");

        $feedback = (new FeedbackService($db))->list(11);

        $this->assertCount(1, $feedback);
        $this->assertSame('One', $feedback[0]['message']);
        $this->assertSame('Customer One', $feedback[0]['user_name']);
    }
}