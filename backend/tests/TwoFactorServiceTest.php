<?php
/**
 * File purpose: proves the expected twofactorservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\RateLimitException;
use Lfmis\Services\TwoFactorService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TwoFactorServiceTest extends TestCase
{
    private PDO $db;

    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        putenv('LFMIS_2FA_OTP_KEY=' . str_repeat('a', 64));
        $_SESSION = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL,
                full_name TEXT NOT NULL,
                phone TEXT NULL,
                phone_verified_at TEXT NULL,
                password_hash TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE TABLE customers (user_id INTEGER PRIMARY KEY, phone TEXT NULL)');
        $this->db->exec(
            'CREATE TABLE user_two_factor_methods (
                user_id INTEGER NOT NULL,
                method TEXT NOT NULL,
                enabled_at TEXT NOT NULL,
                PRIMARY KEY (user_id, method)
            )'
        );
        $this->db->exec(
            'CREATE TABLE two_factor_challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                purpose TEXT NOT NULL,
                method TEXT NOT NULL,
                destination TEXT NOT NULL,
                code_hash TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                expires_at TEXT NOT NULL,
                consumed_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $insert = $this->db->prepare('INSERT INTO users (id, email, full_name, password_hash) VALUES (1, ?, ?, ?)');
        $insert->execute(['farmer@example.test', 'Farm Owner', password_hash('correct-horse-battery', PASSWORD_DEFAULT)]);
    }

    /**
 * Runs the test email enrollment and login code are single use behavior under test.
 * @return void Result of this operation.
 */
    public function testEmailEnrollmentAndLoginCodeAreSingleUse(): void
    {
        $codes = [];
        $service = new TwoFactorService($this->db, static function (string $to, string $subject, string $body) use (&$codes): bool {
            self::assertSame('farmer@example.test', $to);
            self::assertNotSame('', $subject);
            preg_match('/\b(\d{6})\b/', $body, $matches);
            $codes[] = $matches[1];
            return true;
        });

        $service->startEnrollment(1, 'email');
        $service->completeEnrollment(1, 'email', $codes[0]);
        $this->assertSame(['email'], $service->status(1)['methods']);

        $service->beginLogin(1, ['email']);
        $service->sendLoginCode('email');
        $this->assertSame(1, $service->verifyLoginCode('email', $codes[1]));
        $this->assertArrayNotHasKey('pending_2fa', $_SESSION);
    }

    /**
 * Runs the test sms enrollment is rejected behavior under test.
 * @return void Result of this operation.
 */
    public function testSmsEnrollmentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose email verification.');

        (new TwoFactorService($this->db))->startEnrollment(1, 'sms');
    }

    /**
 * Runs the test five incorrect codes lock the challenge behavior under test.
 * @return void Result of this operation.
 */
    public function testFiveIncorrectCodesLockTheChallenge(): void
    {
        $code = '';
        $service = new TwoFactorService($this->db, static function (string $to, string $subject, string $body) use (&$code): bool {
            preg_match('/\b(\d{6})\b/', $body, $matches);
            $code = $matches[1];
            return true;
        });
        $service->startEnrollment(1, 'email');
        $wrongCode = $code === '999999' ? '000000' : '999999';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $service->completeEnrollment(1, 'email', $wrongCode);
                $this->fail('An incorrect code must be rejected.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('invalid or has expired', $error->getMessage());
            }
        }

        try {
            $service->completeEnrollment(1, 'email', $code);
            $this->fail('A locked challenge must not accept its correct code.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('invalid or has expired', $error->getMessage());
        }
        $this->assertSame([], $service->status(1)['methods']);
    }

    /**
 * Runs the test fourth code request in an hour is rate limited behavior under test.
 * @return void Result of this operation.
 */
    public function testFourthCodeRequestInAnHourIsRateLimited(): void
    {
        $service = new TwoFactorService($this->db, static function (): bool {
            return true;
        });
        for ($request = 0; $request < 3; $request++) {
            $service->startEnrollment(1, 'email');
        }

        $this->expectException(RateLimitException::class);
        $service->startEnrollment(1, 'email');
    }

    /**
 * Runs the test expired code cannot enable amethod behavior under test.
 * @return void Result of this operation.
 */
    public function testExpiredCodeCannotEnableAMethod(): void
    {
        $service = new TwoFactorService($this->db, static function (): bool {
            return true;
        });
        $service->startEnrollment(1, 'email');
        $this->db->exec("UPDATE two_factor_challenges SET expires_at = '2000-01-01 00:00:00'");

        $this->expectException(\InvalidArgumentException::class);
        $service->completeEnrollment(1, 'email', '123456');
    }

    /**
 * Runs the test legacy sms only login is rejected behavior under test.
 * @return void Result of this operation.
 */
    public function testLegacySmsOnlyLoginIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No two-factor method is enabled');

        (new TwoFactorService($this->db))->beginLogin(1, ['sms']);
    }
}
