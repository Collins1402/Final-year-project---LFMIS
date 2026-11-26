<?php
/**
 * File purpose: implements RateLimitService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class RateLimitService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db)
    {
    }

    /**
 * Performs the consume operation for this service.
 * @param string $scope Input used by this operation.
 * @param int $maximum Input used by this operation.
 * @param int $windowSeconds Input used by this operation.
 * @param ?string $identity Input used by this operation.
 * @return void Result of this operation.
 */
    public function consume(string $scope, int $maximum, int $windowSeconds, ?string $identity = null): void
    {
        if ($scope === '' || $maximum < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit settings are invalid.');
        }
        $identity = $identity ?? (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = hash('sha256', $scope . "\0" . $identity);
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->db->prepare(
                'INSERT INTO api_rate_limits (bucket_key, window_start, request_count) VALUES (?, ?, 1)
                 ON CONFLICT(bucket_key, window_start) DO UPDATE SET request_count = request_count + 1'
            );
        } else {
            $statement = $this->db->prepare(
                'INSERT INTO api_rate_limits (bucket_key, window_start, request_count) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE request_count = request_count + 1'
            );
        }
        $statement->execute([$key, $windowStart]);

        $count = $this->db->prepare('SELECT request_count FROM api_rate_limits WHERE bucket_key = ? AND window_start = ?');
        $count->execute([$key, $windowStart]);
        if ((int) $count->fetchColumn() > $maximum) {
            throw new RateLimitException('Too many requests. Please wait before trying again.');
        }
        $this->db->prepare('DELETE FROM api_rate_limits WHERE window_start < ?')->execute([$windowStart - $windowSeconds]);
    }
}
