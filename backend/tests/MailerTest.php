<?php
/**
 * File purpose: proves the expected mailer behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    /**
 * Handles the tear down operation for the API router.
 * @return void Result of this operation.
 */
    protected function tearDown(): void
    {
        Mailer::useTransport(null);
        putenv('LFMIS_MAIL_FROM');
    }

    /**
 * Runs the test builds utf8 multipart message with encoded subject and base64 bodies behavior under test.
 * @return void Result of this operation.
 */
    public function testBuildsUtf8MultipartMessageWithEncodedSubjectAndBase64Bodies(): void
    {
        putenv('LFMIS_MAIL_FROM=lfmis@example.test');
        $captured = null;
        Mailer::useTransport(static function (string $from, string $to, string $subject, string $message) use (&$captured): bool {
            $captured = compact('from', 'to', 'subject', 'message');
            return true;
        });

        self::assertTrue(Mailer::send('person@example.test', 'Résumé', "Plain text\nbody", '<p>Résumé HTML</p>'));
        self::assertSame('lfmis@example.test', $captured['from']);
        self::assertSame('person@example.test', $captured['to']);
        self::assertSame('Résumé', $captured['subject']);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Résumé') . '?=', $captured['message']);
        self::assertStringContainsString('multipart/alternative; boundary=', $captured['message']);
        self::assertStringContainsString(base64_encode("Plain text\nbody"), $captured['message']);
        self::assertStringContainsString(base64_encode('<p>Résumé HTML</p>'), $captured['message']);
    }

    /**
 * Runs the test rejects header injection without calling transport behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsHeaderInjectionWithoutCallingTransport(): void
    {
        putenv('LFMIS_MAIL_FROM=lfmis@example.test');
        $called = false;
        Mailer::useTransport(static function () use (&$called): bool { $called = true; return true; });

        self::assertFalse(Mailer::send("person@example.test\r\nBcc: victim@example.test", 'Hello', 'Body'));
        self::assertFalse(Mailer::send('person@example.test', "Hello\nBcc: victim@example.test", 'Body'));
        self::assertFalse($called);
    }
}
