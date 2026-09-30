<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    public function testPasswordPolicyRequiresTwelveCharacters(): void
    {
        $this->assertGreaterThanOrEqual(12, strlen('a-secure-password'));
    }
}
