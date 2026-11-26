<?php
/**
 * File purpose: implements Totp for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

use RuntimeException;

final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /**
 * Creates a cryptographically random Base32 TOTP secret.
 * @param int $bytes Input used by this operation.
 * @return string Result of this operation.
 */

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16 || $bytes > 64) throw new RuntimeException('TOTP secret length must be between 16 and 64 bytes.');
        return self::base32Encode(random_bytes($bytes));
    }

    /**
 * Calculates a time-based one-time password for the supplied time step.
 * @param string $secret Input used by this operation.
 * @param int $time Input used by this operation.
 * @return string Result of this operation.
 */

    public static function code(string $secret, int $time): string
    {
        if ($time < 0) throw new RuntimeException('TOTP time cannot be negative.');
        $key = self::base32Decode($secret);
        $step = intdiv($time, self::PERIOD);
        $counter = pack('N2', intdiv($step, 0x100000000), $step % 0x100000000);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step, or null for an invalid, expired, or replayed code. */
    /**
 * Checks a TOTP code within the allowed time window and rejects replayed steps.
 * @param string $secret Input used by this operation.
 * @param string $code Input used by this operation.
 * @param int $time Input used by this operation.
 * @param int $lastStep Input used by this operation.
 * @return ?int Result of this operation.
 */
    public static function verify(string $secret, string $code, int $time, int $lastStep = 0): ?int
    {
        if (!preg_match('/^\d{6}$/', $code) || $time < 0 || $lastStep < 0) return null;
        $currentStep = intdiv($time, self::PERIOD);
        for ($step = max(0, $currentStep - 1); $step <= $currentStep + 1; $step++) {
            if ($step <= $lastStep) continue;
            if (hash_equals(self::code($secret, $step * self::PERIOD), $code)) return $step;
        }
        return null;
    }

    /**
 * Performs the otpauth uri operation for this service.
 * @param string $secret Input used by this operation.
 * @param string $account Input used by this operation.
 * @param string $issuer Input used by this operation.
 * @return string Result of this operation.
 */

    public static function otpauthUri(string $secret, string $account, string $issuer = 'LFMIS'): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret' => strtoupper($secret),
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
 * Encrypts a TOTP secret using the configured sodium key.
 * @param string $secret Input used by this operation.
 * @return string Result of this operation.
 */

    public static function encrypt(string $secret): string
    {
        self::requireSodium();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($secret, $nonce, self::encryptionKey());
    }

    /**
 * Authenticates and decrypts a stored TOTP secret.
 * @param string $encrypted Input used by this operation.
 * @return string Result of this operation.
 */

    public static function decrypt(string $encrypted): string
    {
        self::requireSodium();
        if (strlen($encrypted) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Encrypted TOTP secret is invalid.');
        }
        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($encrypted, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::encryptionKey());
        if ($plaintext === false) throw new RuntimeException('Encrypted TOTP secret could not be authenticated.');
        return $plaintext;
    }

    /**
 * Performs the encryption key operation for this service.
 * @return string Result of this operation.
 */

    private static function encryptionKey(): string
    {
        $encoded = Env::required('LFMIS_2FA_KEY');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('LFMIS_2FA_KEY must be base64 encoding of exactly 32 random bytes.');
        }
        return $key;
    }

    /**
 * Performs the require sodium operation for this service.
 * @return void Result of this operation.
 */

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('The PHP sodium extension is required for TOTP secret encryption.');
        }
    }

    /**
 * Performs the base32 decode operation for this service.
 * @param string $value Input used by this operation.
 * @return string Result of this operation.
 */

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(rtrim($value, '='));
        if ($value === '' || !preg_match('/^[A-Z2-7]+$/', $value)) throw new RuntimeException('TOTP secret is not valid base32.');
        $bits = '';
        foreach (str_split($value) as $character) {
            $index = strpos(self::BASE32, $character);
            if ($index === false) throw new RuntimeException('TOTP secret is not valid base32.');
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }

    /**
 * Performs the base32 encode operation for this service.
 * @param string $bytes Input used by this operation.
 * @return string Result of this operation.
 */

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }
}
