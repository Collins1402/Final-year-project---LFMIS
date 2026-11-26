<?php
/**
 * File purpose: proves the expected loginalertservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\LoginAlertService;
use PHPUnit\Framework\TestCase;

final class LoginAlertServiceTest extends TestCase
{
    /**
 * Runs the test successful login sends security details without credentials behavior under test.
 * @return void Result of this operation.
 */
    public function testSuccessfulLoginSendsSecurityDetailsWithoutCredentials(): void
    {
        $message = '';
        $sent = false;
        $service = new LoginAlertService(static function (string $to, string $subject, string $body) use (&$message, &$sent): bool {
            $sent = $to === 'farmer@example.test' && $subject === 'New sign-in to your Flouri Farm account';
            $message = $body;
            return $sent;
        });

        $this->assertTrue($service->notify(
            ['email' => 'farmer@example.test', 'fullName' => 'Farm Owner', 'password' => 'sample-secret'],
            ['ip' => '203.0.113.8', 'agent' => 'Test Browser']
        ));
        $this->assertTrue($sent);
        $this->assertStringContainsString('203.0.113.8', $message);
        $this->assertStringContainsString('Test Browser', $message);
        $this->assertStringNotContainsString('sample-secret', $message);
    }

    /**
 * Runs the test mail failure does not throw behavior under test.
 * @return void Result of this operation.
 */
    public function testMailFailureDoesNotThrow(): void
    {
        $service = new LoginAlertService(static function (): bool {
            return false;
        });

        $this->assertFalse($service->notify(['email' => 'farmer@example.test']));
    }
}