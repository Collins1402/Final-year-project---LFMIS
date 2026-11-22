<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\PasswordResetService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordResetServiceTest extends TestCase
{
    public function testPasswordResetKeepsTwoFactorEnabledAndRevokesRememberedDevices(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, failed_login_attempts INTEGER DEFAULT 0, locked_until TEXT, totp_secret_enc BLOB, totp_enabled_at TEXT, totp_last_step INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE password_reset_tokens (user_id INTEGER PRIMARY KEY, token_hash TEXT, expires_at TEXT)');
        $db->exec('CREATE TABLE trusted_devices (user_id INTEGER, token_hash TEXT, label TEXT, expires_at TEXT)');
        $oldHash = password_hash('old-password-value', PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (id, password_hash, totp_secret_enc, totp_enabled_at) VALUES (1, ?, ?, ?)')->execute([$oldHash, 'encrypted-test-secret', gmdate('Y-m-d H:i:s')]);
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO password_reset_tokens VALUES (1, ?, ?)')->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $db->exec("INSERT INTO trusted_devices VALUES (1, 'device-hash', 'test browser', '2099-01-01 00:00:00')");

        (new PasswordResetService($db))->reset($token, 'new-secure-password-value');

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM trusted_devices')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        self::assertNotSame($oldHash, $db->query('SELECT password_hash FROM users WHERE id = 1')->fetchColumn());
        self::assertSame('encrypted-test-secret', $db->query('SELECT totp_secret_enc FROM users WHERE id = 1')->fetchColumn());
        self::assertNotNull($db->query('SELECT totp_enabled_at FROM users WHERE id = 1')->fetchColumn());
    }
}
