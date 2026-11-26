<?php
/**
 * File purpose: proves the expected totpservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\Totp;
use Lfmis\Core\Mailer;
use Lfmis\Services\AuthService;
use Lfmis\Services\TotpService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    private PDO $db;
    private TotpService $service;

    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        putenv('LFMIS_2FA_KEY=' . base64_encode(random_bytes(32)));
        putenv('LFMIS_MAIL_FROM=test@example.test');
        Mailer::useTransport(static fn(): bool => true);
        $_SESSION = [];
        $_COOKIE = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role_id INTEGER, full_name TEXT, username TEXT, email TEXT, phone TEXT, email_verified_at TEXT, password_hash TEXT, is_active INTEGER, failed_login_attempts INTEGER DEFAULT 0, locked_until TEXT, last_login_at TEXT, totp_secret_enc BLOB, totp_enabled_at TEXT, totp_last_step INTEGER DEFAULT 0)');
        $this->db->exec('CREATE TABLE recovery_codes (user_id INTEGER, code_hash TEXT, used_at TEXT, PRIMARY KEY(user_id, code_hash))');
        $this->db->exec('CREATE TABLE trusted_devices (user_id INTEGER, token_hash TEXT PRIMARY KEY, label TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec('CREATE TABLE user_two_factor_methods (user_id INTEGER, method TEXT)');
        $this->db->exec("INSERT INTO roles VALUES (1, 'customer'), (2, 'admin')");
        $insert = $this->db->prepare('INSERT INTO users (id, role_id, full_name, username, email, email_verified_at, password_hash, is_active) VALUES (1, 2, ?, ?, ?, CURRENT_TIMESTAMP, ?, 1)');
        $insert->execute(['Test User', 'tester', 'tester@example.test', password_hash('a-strong-test-password', PASSWORD_DEFAULT)]);
        $this->service = new TotpService($this->db);
    }

    /**
 * Runs the test login with totp does not create session until valid code behavior under test.
 * @return void Result of this operation.
 */
    public function testLoginWithTotpDoesNotCreateSessionUntilValidCode(): void
    {
        $setup = $this->service->beginSetup(1);
        $this->db->prepare('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = 0 WHERE id = 1')->execute([Totp::encrypt($setup['secret']), gmdate('Y-m-d H:i:s')]);
        $_SESSION = [];
        $login = (new AuthService($this->db))->login('tester', 'a-strong-test-password');
        self::assertTrue($login['twoFactorRequired']);
        self::assertNull($_SESSION['user_id'] ?? null);
        $this->service->beginLogin(1);
        $this->service->verifyPending(Totp::code($setup['secret'], time()), false);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
 * Runs the test five wrong codes lock the pending attempt behavior under test.
 * @return void Result of this operation.
 */
    public function testFiveWrongCodesLockThePendingAttempt(): void
    {
        $setup = $this->service->beginSetup(1);
        $this->service->enable(1, Totp::code($setup['secret'], time()));
        $this->service->beginLogin(1);
        for ($i = 0; $i < 5; $i++) {
            try { $this->service->verifyPending('000000', false); } catch (\InvalidArgumentException) {}
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many incorrect codes');
        $this->service->verifyPending('000000', false);
    }

    /**
 * Runs the test recovery code can only be used once and status contains no secrets behavior under test.
 * @return void Result of this operation.
 */
    public function testRecoveryCodeCanOnlyBeUsedOnceAndStatusContainsNoSecrets(): void
    {
        $setup = $this->service->beginSetup(1);
        $codes = $this->service->enable(1, Totp::code($setup['secret'], time()))['recoveryCodes'];
        $this->service->beginLogin(1);
        self::assertTrue($this->service->verifyPending($codes[0], false)['recoveryCodeUsed']);
        $this->service->beginLogin(1);
        try { $this->service->verifyPending($codes[0], false); self::fail('A used recovery code must be refused.'); }
        catch (\InvalidArgumentException) { self::assertSame(9, $this->service->status(1)['recoveryCodesRemaining']); }
        $json = json_encode($this->service->status(1));
        self::assertStringNotContainsString($setup['secret'], $json);
        self::assertStringNotContainsString('code_hash', $json);
        self::assertStringNotContainsString('token_hash', $json);
    }

    /**
 * Runs the test trusted device is scoped to user and revocation removes it behavior under test.
 * @return void Result of this operation.
 */
    public function testTrustedDeviceIsScopedToUserAndRevocationRemovesIt(): void
    {
        $token = bin2hex(random_bytes(32));
        $insert = $this->db->prepare('INSERT INTO trusted_devices (user_id, token_hash, label, expires_at) VALUES (?, ?, ?, ?)');
        $insert->execute([1, hash('sha256', $token), 'test browser', gmdate('Y-m-d H:i:s', time() + 1000)]);
        self::assertTrue($this->service->hasTrustedDevice(1, $token));
        self::assertFalse($this->service->hasTrustedDevice(2, $token));
        $this->service->revokeDevices(1);
        self::assertFalse($this->service->hasTrustedDevice(1, $token));
    }

    /**
 * Runs the test trusted cookie skips challenge only for the matching user behavior under test.
 * @return void Result of this operation.
 */
    public function testTrustedCookieSkipsChallengeOnlyForTheMatchingUser(): void
    {
        $secret = Totp::generateSecret();
        $this->db->prepare('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = 0 WHERE id = 1')->execute([Totp::encrypt($secret), gmdate('Y-m-d H:i:s')]);
        $token = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO trusted_devices (user_id, token_hash, label, expires_at) VALUES (1, ?, ?, ?)')->execute([hash('sha256', $token), 'browser', gmdate('Y-m-d H:i:s', time() + 3600)]);
        set_error_handler(static fn(): bool => true);
        try {
            $result = (new AuthService($this->db))->login('tester', 'a-strong-test-password', $token);
        } finally { restore_error_handler(); }
        self::assertArrayNotHasKey('twoFactorRequired', $result);

        $_SESSION = [];
        $otherUserAttempt = (new AuthService($this->db))->login('tester', 'a-strong-test-password', bin2hex(random_bytes(32)));
        self::assertTrue($otherUserAttempt['twoFactorRequired']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
 * Handles the tear down operation for the API router.
 * @return void Result of this operation.
 */
    protected function tearDown(): void { Mailer::useTransport(null); }
}
