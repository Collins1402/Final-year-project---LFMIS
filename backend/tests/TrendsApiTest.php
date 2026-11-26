<?php
/**
 * File purpose: proves the expected trendsapi behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Jobs\RefreshTrends;
use Lfmis\Services\FarmMetricsService;
use Lfmis\Services\PermissionService;
use Lfmis\Services\SyntheticTrendsService;
use Lfmis\Services\TrendsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TrendsApiTest extends TestCase
{
    /**
 * Runs the test trends aggregate payload has all required sections behavior under test.
 * @return void Result of this operation.
 */
    public function testTrendsAggregatePayloadHasAllRequiredSections(): void
    {
        $db = $this->database();
        $http = static function (string $url): string {
            return json_encode(['articles' => [[
                'title' => 'East African livestock trends',
                'url' => 'https://news.example.test/ea-trends',
                'domain' => 'news.example.test',
                'seendate' => '20261119T100000Z',
            ]]]);
        };
        $trends = new TrendsService($db, $http);
        $synthetic = new SyntheticTrendsService($db);
        $farm = new FarmMetricsService($db);
        $refresh = new RefreshTrends($db, $trends, $synthetic, $farm);

        $live = $trends->current();
        $syntheticData = $synthetic->latest();
        $farmData = $farm->summary();
        $status = $refresh->status();

        $payload = [
            'kenya' => $live['kenya'],
            'east_africa' => $live['east_africa'],
            'global' => $live['global'],
            'regional' => $live['regional'],
            'region' => $live['region'],
            'refreshHours' => 6,
            'source' => 'GDELT Project news data',
            'synthetic' => $syntheticData,
            'actualFarm' => $farmData,
            'status' => $status,
        ];

        $this->assertArrayHasKey('kenya', $payload);
        $this->assertArrayHasKey('east_africa', $payload);
        $this->assertArrayHasKey('global', $payload);
        $this->assertArrayHasKey('synthetic', $payload);
        $this->assertArrayHasKey('actualFarm', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertSame(SyntheticTrendsService::DISCLAIMER, $payload['synthetic']['disclaimer']);
        $this->assertSame(FarmMetricsService::DISCLAIMER, $payload['actualFarm']['disclaimer']);
        $this->assertSame('Africa/Nairobi', $payload['status']['timezone']);
    }

    /**
 * Runs the test trends manage permission allowed only for authorized roles behavior under test.
 * @return void Result of this operation.
 */
    public function testTrendsManagePermissionAllowedOnlyForAuthorizedRoles(): void
    {
        $db = $this->database();
        $perms = new PermissionService($db);

        $this->assertTrue($perms->allows('admin', 'trends.manage'));
        $this->assertFalse($perms->allows('customer', 'trends.manage'));
        $this->assertFalse($perms->allows('buyer', 'trends.manage'));
        $this->assertFalse($perms->allows('worker', 'trends.manage'));
    }

    /**
 * Handles the database operation for the API router.
 * @return PDO Result of this operation.
 */
    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('
            CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE permissions (code TEXT PRIMARY KEY, label TEXT);
            CREATE TABLE role_permissions (role_id INTEGER, permission_code TEXT, is_allowed INTEGER, PRIMARY KEY (role_id, permission_code));
            CREATE TABLE trend_snapshots (snapshot_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at TEXT NOT NULL);
            CREATE TABLE trend_snapshot_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trend_type TEXT NOT NULL,
                geographic_scope TEXT NOT NULL,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE trend_refresh_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_start TEXT UNIQUE NOT NULL,
                period_end TEXT NOT NULL,
                started_at TEXT NOT NULL,
                completed_at TEXT,
                live_status TEXT NOT NULL,
                synthetic_status TEXT NOT NULL,
                actual_farm_status TEXT NOT NULL,
                error_message TEXT,
                attempt_count INTEGER DEFAULT 1
            );
            CREATE TABLE synthetic_trend_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_id INTEGER,
                period_start TEXT NOT NULL,
                metric_name TEXT NOT NULL,
                category TEXT NOT NULL,
                geographic_scope TEXT NOT NULL,
                metric_value REAL NOT NULL,
                previous_value REAL NOT NULL,
                change_percent REAL NOT NULL,
                unit TEXT NOT NULL,
                is_simulated INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO roles (id, name) VALUES (1, "admin"), (2, "worker"), (3, "customer"), (4, "buyer");
            INSERT INTO permissions (code, label) VALUES ("trends.view", "View trends"), ("trends.manage", "Refresh trends");
            INSERT INTO role_permissions (role_id, permission_code, is_allowed) VALUES
                (1, "trends.manage", 1),
                (1, "trends.view", 1),
                (2, "trends.view", 1),
                (3, "trends.view", 1),
                (4, "trends.view", 1);
        ');
        return $db;
    }
}
