<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\DeliveryException;
use Lfmis\Core\Env;

final class SmsService
{
    public static function normalisePhone(string $raw): ?string
    {
        $digits = preg_replace('/[\s\-()]/', '', trim($raw));
        if (!is_string($digits)) {
            return null;
        }
        if (preg_match('/^\+254[17]\d{8}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^254([17]\d{8})$/', $digits, $matches)) {
            return '+254' . $matches[1];
        }
        if (preg_match('/^0([17]\d{8})$/', $digits, $matches)) {
            return '+254' . $matches[1];
        }
        return null;
    }

    public function send(string $phone, string $text): void
    {
        $username = Env::get('LFMIS_AT_USERNAME');
        $apiKey = Env::get('LFMIS_AT_API_KEY');
        if ($username === null || $apiKey === null) {
            throw new DeliveryException('SMS delivery is not configured. Set the Africa’s Talking username and API key in .env.local.');
        }

        $url = Env::get('LFMIS_AT_BASE_URL', 'https://api.sandbox.africastalking.com/version1/messaging');
        $fields = ['username' => $username, 'to' => $phone, 'message' => $text];
        $senderId = Env::get('LFMIS_AT_SENDER_ID');
        if ($senderId !== null) {
            $fields['from'] = $senderId;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new DeliveryException('SMS delivery could not be started.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['apiKey: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log("Africa's Talking SMS delivery failed (HTTP {$status}).");
            throw new DeliveryException('SMS delivery failed. Check the Africa’s Talking settings and provider logs.');
        }

        $response = json_decode($body, true);
        $recipient = $response['SMSMessageData']['Recipients'][0] ?? null;
        if (!is_array($recipient) || (($recipient['status'] ?? '') !== 'Success' && (string) ($recipient['statusCode'] ?? '') !== '101')) {
            error_log("Africa's Talking did not accept the SMS.");
            throw new DeliveryException('SMS delivery failed. Check the phone number and Africa’s Talking account.');
        }
    }
}
