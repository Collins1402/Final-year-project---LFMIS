<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Env;
use PDO;
use Throwable;

final class MpesaService
{
    private ?Closure $httpRequest;

    public function __construct(private PDO $db, ?Closure $httpRequest = null)
    {
        $this->httpRequest = $httpRequest;
    }

    public function initiate(int $orderId, array $user, ?string $phone): array
    {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('Choose a valid order.');
        }

        $orderQuery = $this->db->prepare(
            'SELECT o.id, o.customer_id, o.status, o.total_amount, c.user_id, c.phone AS customer_phone
             FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?'
        );
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$order || (in_array($user['role'] ?? '', ['customer', 'buyer'], true) && (int) $order['user_id'] !== (int) ($user['id'] ?? 0))) {
            throw new \DomainException('Order not found.');
        }
        if (in_array($order['status'], ['paid', 'fulfilled', 'cancelled'], true)) {
            throw new \DomainException('This order is not awaiting payment.');
        }
        if ((float) $order['total_amount'] <= 0 || (int) round((float) $order['total_amount']) < 1) {
            throw new \InvalidArgumentException('The order must have a positive M-PESA amount.');
        }
        $itemCount = $this->db->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
        $itemCount->execute([$orderId]);
        if ((int) $itemCount->fetchColumn() === 0) {
            throw new \DomainException('The order has no items to pay for.');
        }

        $pending = $this->db->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND method = 'mpesa' AND status = 'pending'");
        $pending->execute([$orderId]);
        if ((int) $pending->fetchColumn() > 0) {
            throw new \DomainException('An M-PESA payment request is already in progress.');
        }

        $normalizedPhone = self::normalizePhone($phone ?: (string) ($order['customer_phone'] ?: ($user['phone'] ?? '')));
        $callbackUrl = $this->callbackUrl();
        $amount = (int) round((float) $order['total_amount']);
        $payment = $this->db->prepare(
            "INSERT INTO payments (order_id, customer_id, amount, method, status, phone, recorded_by)
             VALUES (?, ?, ?, 'mpesa', 'pending', ?, ?)"
        );
        $payment->execute([$orderId, $order['customer_id'], $amount, $normalizedPhone, $user['id'] ?? null]);
        $paymentId = (int) $this->db->lastInsertId();

        try {
            $response = $this->sendStkPush($orderId, $amount, $normalizedPhone, $callbackUrl);
            $checkoutRequestId = trim((string) ($response['CheckoutRequestID'] ?? ''));
            if (!in_array($response['ResponseCode'] ?? null, ['0', 0], true) || $checkoutRequestId === '') {
                throw new DeliveryException('M-PESA did not accept the payment request. Please try again.');
            }
            $update = $this->db->prepare(
                'UPDATE payments SET checkout_request_id = ?, merchant_request_id = ?, result_desc = ? WHERE id = ?'
            );
            $update->execute([
                $checkoutRequestId,
                isset($response['MerchantRequestID']) ? (string) $response['MerchantRequestID'] : null,
                isset($response['ResponseDescription']) ? substr((string) $response['ResponseDescription'], 0, 255) : null,
                $paymentId,
            ]);
        } catch (Throwable $error) {
            $failed = $this->db->prepare("UPDATE payments SET status = 'failed', result_desc = ? WHERE id = ? AND status = 'pending'");
            $failed->execute([substr($error->getMessage(), 0, 255), $paymentId]);
            throw $error;
        }

        return [
            'paymentId' => $paymentId,
            'status' => 'pending',
            'checkoutRequestId' => $checkoutRequestId,
            'message' => 'Check your phone and enter your M-PESA PIN to complete payment.',
        ];
    }

    /** Complete a clearly identified local payment simulation without contacting Daraja. */
    public function simulate(int $orderId, array $user): array
    {
        $orderQuery = $this->db->prepare(
            'SELECT o.id, o.customer_id, o.status, o.total_amount, c.user_id
             FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?'
        );
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$order || (in_array($user['role'] ?? '', ['customer', 'buyer'], true) && (int) $order['user_id'] !== (int) ($user['id'] ?? 0))) {
            throw new \DomainException('Order not found.');
        }
        if (in_array($order['status'], ['paid', 'fulfilled', 'cancelled'], true)) {
            throw new \DomainException('This order is not awaiting payment.');
        }
        if ((float) $order['total_amount'] <= 0) {
            throw new \InvalidArgumentException('The order must have a positive M-PESA amount.');
        }
        $items = $this->db->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        if ((int) $items->fetchColumn() === 0) {
            throw new \DomainException('The order has no items to pay for.');
        }

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                "INSERT INTO payments (order_id, customer_id, amount, method, status, transaction_reference, result_desc, paid_at, recorded_by)
                 VALUES (?, ?, ?, 'mpesa', 'pending', NULL, 'Local M-PESA simulation', NULL, ?)"
            );
            $insert->execute([$orderId, $order['customer_id'], round((float) $order['total_amount']), $user['id'] ?? null]);
            $payment = ['id' => (int) $this->db->lastInsertId(), 'order_id' => $orderId, 'amount' => $order['total_amount']];
            $this->confirmPayment($payment, ['MpesaReceiptNumber' => 'SIM-' . strtoupper(bin2hex(random_bytes(5))), 'Amount' => round((float) $order['total_amount'])], 'Simulated locally; no real M-PESA payment was made.');
            $this->db->commit();
            return ['paymentId' => $payment['id'], 'status' => 'confirmed', 'simulated' => true];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function handleCallback(string $providedSecret, array $payload): array
    {
        $secret = Env::get('LFMIS_MPESA_CALLBACK_SECRET', '');
        if ($secret === '' || !hash_equals($secret, $providedSecret)) {
            throw new \DomainException('Invalid M-PESA callback authorization.');
        }

        $callback = $payload['Body']['stkCallback'] ?? null;
        if (!is_array($callback)) {
            throw new \InvalidArgumentException('Invalid M-PESA callback payload.');
        }
        $checkoutRequestId = trim((string) ($callback['CheckoutRequestID'] ?? ''));
        $parsedResultCode = isset($callback['ResultCode'])
            ? filter_var((string) $callback['ResultCode'], FILTER_VALIDATE_INT)
            : false;
        if ($checkoutRequestId === '' || $parsedResultCode === false) {
            throw new \InvalidArgumentException('M-PESA callback is missing its request result.');
        }
        $resultCode = (int) $parsedResultCode;
        $metadata = [];
        foreach (($callback['CallbackMetadata']['Item'] ?? []) as $item) {
            if (is_array($item) && isset($item['Name'])) {
                $metadata[(string) $item['Name']] = $item['Value'] ?? null;
            }
        }

        $this->db->beginTransaction();
        try {
            $query = 'SELECT id, order_id, amount, status FROM payments WHERE checkout_request_id = ?';
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $query .= ' FOR UPDATE';
            }
            $paymentQuery = $this->db->prepare($query);
            $paymentQuery->execute([$checkoutRequestId]);
            $payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new \DomainException('M-PESA payment request not found.');
            }
            if ($payment['status'] !== 'pending') {
                $this->db->commit();
                return ['ok' => true, 'duplicate' => true];
            }

            if ($resultCode === 0 && $this->validSuccessMetadata($payment, $metadata)) {
                $this->confirmPayment($payment, $metadata, (string) ($callback['ResultDesc'] ?? 'Payment confirmed.'));
            } else {
                $description = $resultCode === 0
                    ? 'M-PESA confirmation details were incomplete or did not match the payment.'
                    : (string) ($callback['ResultDesc'] ?? 'M-PESA payment failed.');
                $this->failPayment($payment, $description);
            }
            $this->db->commit();
            return ['ok' => true, 'duplicate' => false];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[\s()+-]/', '', trim($phone));
        if ($digits === null) {
            throw new \InvalidArgumentException('Enter a valid Kenyan M-PESA phone number.');
        }
        if (preg_match('/^07\d{8}$/', $digits)) {
            $digits = '254' . substr($digits, 1);
        } elseif (preg_match('/^7\d{8}$/', $digits)) {
            $digits = '254' . $digits;
        } elseif (preg_match('/^2547\d{8}$/', $digits)) {
            // Already in Daraja format.
        } else {
            throw new \InvalidArgumentException('Enter a valid Kenyan M-PESA phone number (2547XXXXXXXX).');
        }
        return $digits;
    }

    private function confirmPayment(array $payment, array $metadata, string $description): void
    {
        $receipt = trim((string) ($metadata['MpesaReceiptNumber'] ?? ''));
        $orderQuery = $this->db->prepare('SELECT customer_id FROM orders WHERE id = ?');
        $orderQuery->execute([(int) $payment['order_id']]);
        $customerId = $orderQuery->fetchColumn();
        if ($customerId === false) {
            throw new \DomainException('The order for this payment no longer exists.');
        }

        $this->db->prepare(
            "UPDATE payments SET status = 'confirmed', transaction_reference = ?, result_desc = ?, paid_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'"
        )->execute([$receipt, substr($description, 0, 255), (int) $payment['id']]);
        $this->db->prepare("UPDATE orders SET status = 'paid' WHERE id = ?")->execute([(int) $payment['order_id']]);
        $this->db->prepare(
            "UPDATE animals SET status = 'sold', status_changed_at = CURRENT_TIMESTAMP
             WHERE id IN (SELECT animal_id FROM order_animals WHERE order_id = ?) AND status = 'active'"
        )->execute([(int) $payment['order_id']]);

        $existingSales = $this->db->prepare('SELECT COUNT(*) FROM sales WHERE order_id = ?');
        $existingSales->execute([(int) $payment['order_id']]);
        if ((int) $existingSales->fetchColumn() === 0) {
            $items = $this->db->prepare('SELECT item_name, quantity, unit, unit_price FROM order_items WHERE order_id = ?');
            $items->execute([(int) $payment['order_id']]);
            $sale = $this->db->prepare(
                'INSERT INTO sales (order_id, customer_id, description, quantity, unit, unit_price, sold_at, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL)'
            );
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $sale->execute([
                    (int) $payment['order_id'],
                    (int) $customerId,
                    $item['item_name'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price'],
                ]);
            }
        }

        (new AuditService($this->db))->record(
            null,
            'payment_confirmed',
            'payment',
            (int) $payment['id'],
            ['order_id' => (int) $payment['order_id']]
        );
        $this->notifyStaff((int) $payment['id'], (int) $payment['order_id']);
    }

    private function failPayment(array $payment, string $description): void
    {
        $this->db->prepare(
            "UPDATE payments SET status = 'failed', result_desc = ? WHERE id = ? AND status = 'pending'"
        )->execute([substr($description, 0, 255), (int) $payment['id']]);
        $this->db->prepare("UPDATE orders SET status = 'pending' WHERE id = ? AND status <> 'paid'")
            ->execute([(int) $payment['order_id']]);
    }

    private function validSuccessMetadata(array $payment, array $metadata): bool
    {
        if (trim((string) ($metadata['MpesaReceiptNumber'] ?? '')) === '') {
            return false;
        }
        return !isset($metadata['Amount']) || (int) round((float) $metadata['Amount']) === (int) round((float) $payment['amount']);
    }

    private function notifyStaff(int $paymentId, int $orderId): void
    {
        $staff = $this->db->query(
            "SELECT users.id FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND roles.name IN ('admin', 'worker', 'veterinarian')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ignore = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $statement = $this->db->prepare(
            "$ignore INTO notifications (user_id, type, entity_type, entity_id, title, message, dedupe_key)
             VALUES (?, 'system', 'payment', ?, 'M-PESA payment confirmed', ?, ?)"
        );
        foreach ($staff as $userId) {
            $statement->execute([$userId, $orderId, 'Order #' . $orderId . ' has been paid.', 'mpesa-payment:' . $paymentId]);
        }
    }

    private function sendStkPush(int $orderId, int $amount, string $phone, string $callbackUrl): array
    {
        $environment = Env::get('LFMIS_MPESA_ENV', 'sandbox');
        $baseUrl = match ($environment) {
            'sandbox' => 'https://sandbox.safaricom.co.ke',
            'production' => 'https://api.safaricom.co.ke',
            default => throw new DeliveryException('M-PESA environment must be sandbox or production.'),
        };
        $consumerKey = Env::get('LFMIS_MPESA_CONSUMER_KEY', '');
        $consumerSecret = Env::get('LFMIS_MPESA_CONSUMER_SECRET', '');
        $shortcode = Env::get('LFMIS_MPESA_SHORTCODE', '');
        $passkey = Env::get('LFMIS_MPESA_PASSKEY', '');
        if ($consumerKey === '' || $consumerSecret === '' || $shortcode === '' || $passkey === '') {
            throw new DeliveryException('M-PESA is not configured. Set the Daraja credentials in the server environment.');
        }

        $tokenResponse = $this->request(
            'GET',
            $baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret)]
        );
        $token = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($token === '') {
            throw new DeliveryException('M-PESA authentication failed. Check the Daraja credentials.');
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);
        $response = $this->request(
            'POST',
            $baseUrl . '/mpesa/stkpush/v1/processrequest',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => Env::get('LFMIS_MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => Env::get('LFMIS_MPESA_PARTY_B', $shortcode),
                'PhoneNumber' => $phone,
                'CallBackURL' => $callbackUrl,
                'AccountReference' => (string) $orderId,
                'TransactionDesc' => 'Farm order payment ' . $orderId,
            ]
        );
        return $response;
    }

    private function callbackUrl(): string
    {
        $base = trim((string) Env::get('LFMIS_MPESA_CALLBACK_BASE', ''));
        $secret = (string) Env::get('LFMIS_MPESA_CALLBACK_SECRET', '');
        if ($base === '' || !str_starts_with($base, 'https://') || strlen($secret) < 32) {
            throw new DeliveryException('Set an HTTPS M-PESA callback URL and a callback secret of at least 32 characters.');
        }
        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(['token' => $secret]);
    }

    private function request(string $method, string $url, array $headers, ?array $payload = null): array
    {
        if ($this->httpRequest !== null) {
            $result = ($this->httpRequest)($method, $url, $headers, $payload);
            $status = (int) ($result['status'] ?? 0);
            $body = $result['body'] ?? '';
        } else {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new DeliveryException('Unable to initialize the M-PESA connection.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
            ];
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($body === false) {
                throw new DeliveryException('M-PESA connection failed: ' . $error);
            }
        }
        $decoded = is_array($body) ? $body : json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new DeliveryException('M-PESA returned an invalid response. Check Daraja status and configuration.');
        }
        return $decoded;
    }
}
