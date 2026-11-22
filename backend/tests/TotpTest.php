<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\Env;
use Lfmis\Core\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testMatchesRfc6238SixDigitVectorAtTimeFiftyNine(): void
    {
        self::assertSame('287082', Totp::code(self::SECRET, 59));
    }

    public function testAcceptsOneStepDriftAndRejectsTwoSteps(): void
    {
        self::assertSame(2, Totp::verify(self::SECRET, Totp::code(self::SECRET, 60), 90));
        self::assertSame(4, Totp::verify(self::SECRET, Totp::code(self::SECRET, 120), 90));
        self::assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 30), 90));
        self::assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 150), 90));
    }

    public function testRefusesReplayOfAnAlreadyAcceptedStep(): void
    {
        $code = Totp::code(self::SECRET, 59);
        self::assertSame(1, Totp::verify(self::SECRET, $code, 59, 0));
        self::assertNull(Totp::verify(self::SECRET, $code, 59, 1));
    }

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
