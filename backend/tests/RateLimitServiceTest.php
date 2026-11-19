<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\RateLimitException;
use Lfmis\Services\RateLimitService;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateLimitServiceTest extends TestCase
{
    private PDO $db;
    private RateLimitService $limits;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE api_rate_limits (bucket_key TEXT, window_start INTEGER, request_count INTEGER, PRIMARY KEY (bucket_key, window_start))');
        $this->limits = new RateLimitService($this->db);
    }

    public function testAllowsRequestsWithinTheConfiguredLimit(): void
    {
        $this->limits->consume('login', 2, 60, 'client-a');
        $this->limits->consume('login', 2, 60, 'client-a');

        $this->assertSame(2, (int) $this->db->query('SELECT SUM(request_count) FROM api_rate_limits')->fetchColumn());
    }

    public function testRejectsRequestsAboveTheConfiguredLimit(): void
    {
        $this->limits->consume('login', 1, 60, 'client-a');

        $this->expectException(RateLimitException::class);
        $this->limits->consume('login', 1, 60, 'client-a');
    }

    public function testRateLimitBucketsAreIsolatedByScopeAndIdentity(): void
    {
        $this->limits->consume('login', 1, 60, 'client-a');
        $this->limits->consume('forgot-password', 1, 60, 'client-a');
        $this->limits->consume('login', 1, 60, 'client-b');

        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM api_rate_limits')->fetchColumn());
    }
}
