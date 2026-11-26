<?php
/**
 * File purpose: proves the expected ratelimitservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
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

    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE api_rate_limits (bucket_key TEXT, window_start INTEGER, request_count INTEGER, PRIMARY KEY (bucket_key, window_start))');
        $this->limits = new RateLimitService($this->db);
    }

    /**
 * Runs the test allows requests within the configured limit behavior under test.
 * @return void Result of this operation.
 */
    public function testAllowsRequestsWithinTheConfiguredLimit(): void
    {
        $this->limits->consume('login', 2, 60, 'client-a');
        $this->limits->consume('login', 2, 60, 'client-a');

        $this->assertSame(2, (int) $this->db->query('SELECT SUM(request_count) FROM api_rate_limits')->fetchColumn());
    }

    /**
 * Runs the test rejects requests above the configured limit behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsRequestsAboveTheConfiguredLimit(): void
    {
        $this->limits->consume('login', 1, 60, 'client-a');

        $this->expectException(RateLimitException::class);
        $this->limits->consume('login', 1, 60, 'client-a');
    }

    /**
 * Runs the test rate limit buckets are isolated by scope and identity behavior under test.
 * @return void Result of this operation.
 */
    public function testRateLimitBucketsAreIsolatedByScopeAndIdentity(): void
    {
        $this->limits->consume('login', 1, 60, 'client-a');
        $this->limits->consume('forgot-password', 1, 60, 'client-a');
        $this->limits->consume('login', 1, 60, 'client-b');

        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM api_rate_limits')->fetchColumn());
    }
}
