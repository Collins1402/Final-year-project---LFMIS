<?php
/**
 * File purpose: proves the expected mpesaservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\MpesaService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MpesaServiceTest extends TestCase
{
    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        putenv('LFMIS_MPESA_ENV=sandbox');
        putenv('LFMIS_MPESA_CONSUMER_KEY=test-key');
        putenv('LFMIS_MPESA_CONSUMER_SECRET=test-secret');
        putenv('LFMIS_MPESA_SHORTCODE=123456');
        putenv('LFMIS_MPESA_PASSKEY=test-passkey');
        putenv('LFMIS_MPESA_CALLBACK_BASE=https://farm.example.test/api/payments/mpesa/callback');
        putenv('LFMIS_MPESA_CALLBACK_SECRET=0123456789abcdef0123456789abcdef');
    }

    /**
 * Handles the tear down operation for the API router.
 * @return void Result of this operation.
 */
    protected function tearDown(): void
    {
        foreach ([
            'LFMIS_MPESA_ENV',
            'LFMIS_MPESA_CONSUMER_KEY',
            'LFMIS_MPESA_CONSUMER_SECRET',
            'LFMIS_MPESA_SHORTCODE',
            'LFMIS_MPESA_PASSKEY',
            'LFMIS_MPESA_CALLBACK_BASE',
            'LFMIS_MPESA_CALLBACK_SECRET',
        ] as $key) {
            putenv($key);
        }
    }

    /**
 * Runs the test initiate normalizes phone and sends stk push behavior under test.
 * @return void Result of this operation.
 */
    public function testInitiateNormalizesPhoneAndSendsStkPush(): void
    {
        $db = $this->database();
        $calls = [];
        $service = new MpesaService($db, static function (string $method, string $url, array $headers, ?array $payload) use (&$calls): array {
            $calls[] = [$method, $url, $headers, $payload];
            return str_contains($url, '/oauth/')
                ? ['status' => 200, 'body' => '{"access_token":"mock-token"}']
                : ['status' => 200, 'body' => '{"ResponseCode":"0","CheckoutRequestID":"ws_CO_123","MerchantRequestID":"mr_123"}'];
        });

        $result = $service->initiate(1, ['id' => 11, 'role' => 'customer', 'phone' => '0712345678'], null);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('254712345678', $calls[1][3]['PhoneNumber']);
        $this->assertSame('1', $calls[1][3]['AccountReference']);
        $this->assertStringContainsString('token=0123456789abcdef0123456789abcdef', $calls[1][3]['CallBackURL']);
        $payment = $db->query('SELECT status, checkout_request_id, phone FROM payments')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $payment['status']);
        $this->assertSame('ws_CO_123', $payment['checkout_request_id']);
        $this->assertSame('254712345678', $payment['phone']);
    }

    /**
 * Runs the test callback confirms payment records sale and notifies staff only once behavior under test.
 * @return void Result of this operation.
 */
    public function testCallbackConfirmsPaymentRecordsSaleAndNotifiesStaffOnlyOnce(): void
    {
        $db = $this->database();
        $this->addPendingPayment($db);
        $service = new MpesaService($db);

        $first = $service->handleCallback('0123456789abcdef0123456789abcdef', $this->callbackPayload(0));
        $second = $service->handleCallback('0123456789abcdef0123456789abcdef', $this->callbackPayload(0));

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame('confirmed', $db->query('SELECT status FROM payments')->fetchColumn());
        $this->assertSame('paid', $db->query('SELECT status FROM orders')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        $this->assertSame('payment_confirmed', $db->query('SELECT action FROM audit_logs')->fetchColumn());
    }

    /**
 * Runs the test failed callback allows buyer to retry behavior under test.
 * @return void Result of this operation.
 */
    public function testFailedCallbackAllowsBuyerToRetry(): void
    {
        $db = $this->database();
        $this->addPendingPayment($db);

        (new MpesaService($db))->handleCallback('0123456789abcdef0123456789abcdef', $this->callbackPayload(1032, null, 'Cancelled by user.'));

        $this->assertSame('failed', $db->query('SELECT status FROM payments')->fetchColumn());
        $this->assertSame('pending', $db->query('SELECT status FROM orders')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    /**
 * Runs the test callback rejects wrong secret behavior under test.
 * @return void Result of this operation.
 */
    public function testCallbackRejectsWrongSecret(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invalid M-PESA callback authorization.');
        (new MpesaService($this->database()))->handleCallback('wrong-secret', $this->callbackPayload(0));
    }

    /**
 * Runs the test buyer cannot pay another customers order behavior under test.
 * @return void Result of this operation.
 */
    public function testBuyerCannotPayAnotherCustomersOrder(): void
    {
        $service = new MpesaService($this->database(), static fn(): array => ['status' => 200, 'body' => '{}']);

        $this->expectException(\DomainException::class);
        $service->initiate(1, ['id' => 12, 'role' => 'customer'], '0712345678');
    }

    /**
 * Runs the test initiate rejects invalid phone behavior under test.
 * @return void Result of this operation.
 */
    public function testInitiateRejectsInvalidPhone(): void
    {
        $service = new MpesaService($this->database(), static fn(): array => ['status' => 200, 'body' => '{}']);

        $this->expectException(\InvalidArgumentException::class);
        $service->initiate(1, ['id' => 11, 'role' => 'customer'], '07123');
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, user_id INTEGER, phone TEXT)');
        $db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, status TEXT, total_amount REAL)');
        $db->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, item_name TEXT, quantity REAL, unit TEXT, unit_price REAL)');
        $db->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, customer_id INTEGER, amount REAL, method TEXT, status TEXT, transaction_reference TEXT, checkout_request_id TEXT UNIQUE, merchant_request_id TEXT, phone TEXT, result_desc TEXT, paid_at TEXT, recorded_by INTEGER)');
        $db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, customer_id INTEGER, description TEXT, quantity REAL, unit TEXT, unit_price REAL, sold_at TEXT, recorded_by INTEGER)');
        $db->exec('CREATE TABLE animals (id INTEGER PRIMARY KEY, status TEXT, status_changed_at TEXT)');
        $db->exec('CREATE TABLE order_animals (order_id INTEGER, animal_id INTEGER)');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, is_active INTEGER)');
        $db->exec('CREATE TABLE notifications (user_id INTEGER, type TEXT, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, dedupe_key TEXT, UNIQUE(user_id, dedupe_key))');
        $db->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT)');
        $db->exec("INSERT INTO customers VALUES (1, 11, '0712345678')");
        $db->exec("INSERT INTO customers VALUES (2, 12, '0722222222')");
        $db->exec("INSERT INTO orders VALUES (1, 1, 'pending', 300)");
        $db->exec("INSERT INTO order_items VALUES (1, 1, 'Milk', 3, 'litres', 100)");
        $db->exec("INSERT INTO roles VALUES (1, 'admin')");
        $db->exec('INSERT INTO users VALUES (7, 1, 1)');
        return $db;
    }

    /**
 * Handles the add pending payment operation for the API router.
 * @param mixed $db Value supplied to this operation.
 * @return void Result of this operation.
 */
    private function addPendingPayment(PDO $db): void
    {
        $db->exec("INSERT INTO payments (order_id, customer_id, amount, method, status, checkout_request_id) VALUES (1, 1, 300, 'mpesa', 'pending', 'ws_CO_123')");
    }

    /**
 * Handles the callback payload operation for the API router.
 * @param mixed $resultCode Value supplied to this operation.
 * @param mixed $receipt Value supplied to this operation.
 * @param mixed $description Value supplied to this operation.
 * @return array Result of this operation.
 */
    private function callbackPayload(int $resultCode, ?string $receipt = 'ABC123', string $description = 'The service request is processed successfully.'): array
    {
        $items = [];
        if ($receipt !== null) {
            $items[] = ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt];
            $items[] = ['Name' => 'Amount', 'Value' => 300];
        }
        return [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_123',
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $description,
                    'CallbackMetadata' => ['Item' => $items],
                ],
            ],
        ];
    }
}
