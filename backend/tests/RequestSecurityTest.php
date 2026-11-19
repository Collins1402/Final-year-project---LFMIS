<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Core\RequestSecurity;
use PHPUnit\Framework\TestCase;

final class RequestSecurityTest extends TestCase
{
    public function testTrustedOriginMustMatchConfiguredApplicationOrigin(): void
    {
        $this->assertTrue(RequestSecurity::isTrustedOrigin('http://localhost:5173', null, 'http://localhost:5173'));
        $this->assertFalse(RequestSecurity::isTrustedOrigin('https://attacker.example', null, 'http://localhost:5173'));
        $this->assertFalse(RequestSecurity::isTrustedOrigin(null, null, 'http://localhost:5173'));
    }

    public function testTrustedRefererMaySupplyTheApplicationOrigin(): void
    {
        $this->assertTrue(RequestSecurity::isTrustedOrigin(null, 'http://localhost:5173/orders/1', 'http://localhost:5173'));
        $this->assertFalse(RequestSecurity::isTrustedOrigin(null, 'http://localhost:5173.attacker.example/', 'http://localhost:5173'));
    }

    public function testLanPrivateOriginsAreAcceptedForDevelopment(): void
    {
        $this->assertTrue(RequestSecurity::isTrustedOrigin('http://192.168.1.50:5173', null, 'http://192.168.1.50:5173'));
        $this->assertTrue(RequestSecurity::isTrustedOrigin('http://192.168.1.50:5173', null, 'http://127.0.0.1:5173'));
        $this->assertFalse(RequestSecurity::isTrustedOrigin('http://203.0.113.20:5173', null, 'http://127.0.0.1:5173'));
    }
}
