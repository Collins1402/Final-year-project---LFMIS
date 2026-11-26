<?php
/**
 * File purpose: proves the expected totp behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\Env;
use Lfmis\Core\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
 * Runs the test matches rfc6238 six digit vector at time fifty nine behavior under test.
 * @return void Result of this operation.
 */
    public function testMatchesRfc6238SixDigitVectorAtTimeFiftyNine(): void
    {
        self::assertSame('287082', Totp::code(self::SECRET, 59));
    }

    /**
 * Runs the test accepts one step drift and rejects two steps behavior under test.
 * @return void Result of this operation.
 */
    public function testAcceptsOneStepDriftAndRejectsTwoSteps(): void
    {
        self::assertSame(2, Totp::verify(self::SECRET, Totp::code(self::SECRET, 60), 90));
        self::assertSame(4, Totp::verify(self::SECRET, Totp::code(self::SECRET, 120), 90));
        self::assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 30), 90));
        self::assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 150), 90));
    }

    /**
 * Runs the test refuses replay of an already accepted step behavior under test.
 * @return void Result of this operation.
 */
    public function testRefusesReplayOfAnAlreadyAcceptedStep(): void
    {
        $code = Totp::code(self::SECRET, 59);
        self::assertSame(1, Totp::verify(self::SECRET, $code, 59, 0));
        self::assertNull(Totp::verify(self::SECRET, $code, 59, 1));
    }

    /**
 * Runs the test builds otp auth uri and encrypts secret with configured key behavior under test.
 * @return void Result of this operation.
 */
    public function testBuildsOtpAuthUriAndEncryptsSecretWithConfiguredKey(): void
    {
        $previous = getenv('LFMIS_2FA_KEY');
        putenv('LFMIS_2FA_KEY=' . base64_encode(random_bytes(32)));
        try {
            self::assertStringStartsWith('otpauth://totp/LFMIS%3Auser%40example.test?', Totp::otpauthUri(self::SECRET, 'user@example.test'));
            self::assertSame(self::SECRET, Totp::decrypt(Totp::encrypt(self::SECRET)));
        } finally {
            putenv($previous === false ? 'LFMIS_2FA_KEY' : 'LFMIS_2FA_KEY=' . $previous);
        }
    }
}
