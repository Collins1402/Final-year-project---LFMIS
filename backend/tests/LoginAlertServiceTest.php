<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\LoginAlertService;
use PHPUnit\Framework\TestCase;

final class LoginAlertServiceTest extends TestCase
{
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

    public function testMailFailureDoesNotThrow(): void
    {
        $service = new LoginAlertService(static function (): bool {
            return false;
        });

        $this->assertFalse($service->notify(['email' => 'farmer@example.test']));
    }
}