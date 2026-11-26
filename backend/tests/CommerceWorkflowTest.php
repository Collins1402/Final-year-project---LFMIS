<?php
/**
 * File purpose: proves the expected commerceworkflow behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\CommerceService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommerceWorkflowTest extends TestCase
{
    /**
 * Runs the test acceptance reserves produce and fulfillment records sale once behavior under test.
 * @return void Result of this operation.
 */
    public function testAcceptanceReservesProduceAndFulfillmentRecordsSaleOnce(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Milk', 'produce', 'milk', NULL, 'litres', 8, 100, 1)");
        $db->exec("INSERT INTO customers VALUES (1, 11, 'Customer', '0700000000', 'customer@example.test')");
        $db->exec("INSERT INTO orders VALUES (1, 1, 'quoted', 300, NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (1, 1, 'Milk', 3, 'litres', 100)");
        $service = new CommerceService($db);

        $service->updateOrder(1, ['status' => 'accepted'], 7);
        $this->assertSame(5.0, (float) $db->query('SELECT available_quantity FROM products WHERE id = 1')->fetchColumn());
        $service->updateOrder(1, ['status' => 'fulfilled'], 7);
        $service->updateOrder(1, ['status' => 'fulfilled'], 7);

        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM sales WHERE order_id = 1')->fetchColumn());
        $this->assertSame('fulfilled', $db->query('SELECT status FROM orders WHERE id = 1')->fetchColumn());
    }

    /**
 * Runs the test acceptance allocates specific livestock behavior under test.
 * @return void Result of this operation.
 */
    public function testAcceptanceAllocatesSpecificLivestock(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Goat', 'livestock', NULL, 'goat', 'head', 0, 12000, 1)");
        $db->exec("INSERT INTO animals VALUES (51, 'goat', 'active', NULL), (52, 'goat', 'active', NULL)");
        $db->exec("INSERT INTO customers VALUES (1, 11, 'Customer', '0700000000', 'customer@example.test')");
        $db->exec("INSERT INTO orders VALUES (1, 1, 'quoted', 12000, NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (1, 1, 'Goat', 1, 'head', 12000)");

        (new CommerceService($db))->updateOrder(1, ['status' => 'accepted'], 7);

        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM order_animals WHERE order_id = 1')->fetchColumn());
        $this->assertSame('sold', $db->query('SELECT status FROM animals WHERE id = 51')->fetchColumn());
        $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM animals WHERE species = 'goat' AND status = 'active'")->fetchColumn());
    }

    /**
 * Runs the test customer can request listed product behavior under test.
 * @return void Result of this operation.
 */
    public function testCustomerCanRequestListedProduct(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Eggs', 'produce', 'eggs', NULL, 'trays', 4, 350, 1)");

        $order = (new CommerceService($db))->createCustomerOrder(['productId' => 1, 'quantity' => 2], [
            'id' => 11,
            'fullName' => 'Customer One',
            'email' => 'one@example.test',
            'phone' => '0711000000',
            'role' => 'customer',
        ]);

        $this->assertSame('pending', $order['status']);
        $this->assertSame(700.0, $order['totalAmount']);
        $this->assertSame(11, (int) $db->query('SELECT user_id FROM customers')->fetchColumn());
    }

    /**
 * Runs the test admin quote is linked to catalog stock behavior under test.
 * @return void Result of this operation.
 */
    public function testAdminQuoteIsLinkedToCatalogStock(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Milk', 'produce', 'milk', NULL, 'litres', 8, 100, 1)");

        $order = (new CommerceService($db))->createOrder([
            'customerName' => 'Buyer Name',
            'customerPhone' => '0711000000',
            'productId' => 1,
            'quantity' => 2,
        ], 7);

        $this->assertSame('quoted', $order['status']);
        $this->assertSame(200.0, $order['totalAmount']);
        $this->assertSame(1, (int) $db->query('SELECT product_id FROM order_items')->fetchColumn());
    }

    /**
 * Runs the test livestock availability uses active animal records behavior under test.
 * @return void Result of this operation.
 */
    public function testLivestockAvailabilityUsesActiveAnimalRecords(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Goat', 'livestock', NULL, 'goat', 'head', 99, 12000, 1)");
        $db->exec("INSERT INTO animals VALUES (51, 'goat', 'active', NULL), (52, 'goat', 'sold', NULL), (53, 'goat', 'active', NULL)");

        $products = (new CommerceService($db))->products('buyer');

        $this->assertCount(1, $products);
        $this->assertSame(2.0, (float) $products[0]['available_quantity']);
    }

    /**
 * Runs the test customer cannot request livestock listing behavior under test.
 * @return void Result of this operation.
 */
    public function testCustomerCannotRequestLivestockListing(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Goat', 'livestock', NULL, 'goat', 'head', 0, 12000, 1)");
        $db->exec("INSERT INTO animals VALUES (51, 'goat', 'active', NULL)");

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Customer accounts can request produce and other farm listings only.');
        (new CommerceService($db))->createCustomerOrder(['productId' => 1, 'quantity' => 1], [
            'id' => 11, 'fullName' => 'Customer One', 'email' => 'one@example.test', 'role' => 'customer',
        ]);
    }

    /**
 * Runs the test insufficient stock rolls back acceptance behavior under test.
 * @return void Result of this operation.
 */
    public function testInsufficientStockRollsBackAcceptance(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO products VALUES (1, 'Milk', 'produce', 'milk', NULL, 'litres', 2, 100, 1)");
        $db->exec("INSERT INTO customers VALUES (1, 11, 'Customer', '0700000000', 'customer@example.test')");
        $db->exec("INSERT INTO orders VALUES (1, 1, 'quoted', 300, NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (1, 1, 'Milk', 3, 'litres', 100)");

        try {
            (new CommerceService($db))->updateOrder(1, ['status' => 'accepted'], 7);
            $this->fail('Expected insufficient stock to prevent acceptance.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString('not enough listed stock', $error->getMessage());
        }
        $this->assertSame('quoted', $db->query('SELECT status FROM orders WHERE id = 1')->fetchColumn());
        $this->assertSame(2.0, (float) $db->query('SELECT available_quantity FROM products WHERE id = 1')->fetchColumn());
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, product_type TEXT, production_kind TEXT, species TEXT, unit TEXT, available_quantity REAL, unit_price REAL, is_active INTEGER)');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, species TEXT, status TEXT, status_changed_at TEXT)');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, phone TEXT, email TEXT)');
        $db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, status TEXT, total_amount REAL, handled_by INTEGER, ordered_at TEXT DEFAULT CURRENT_TIMESTAMP, accepted_at TEXT, fulfilled_at TEXT)');
        $db->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, item_name TEXT, quantity REAL, unit TEXT, unit_price REAL)');
        $db->exec('CREATE TABLE order_animals (order_id INTEGER, animal_id INTEGER)');
        $db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, customer_id INTEGER, description TEXT, quantity REAL, unit TEXT, unit_price REAL, sold_at TEXT, recorded_by INTEGER)');
        return $db;
    }
}