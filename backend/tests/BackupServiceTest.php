<?php
/**
 * File purpose: proves the expected backupservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\BackupService;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private string $root;

    /**
 * Handles the set up operation for the API router.
 * @return void Result of this operation.
 */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lfmis-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        putenv('LFMIS_DB_HOST=127.0.0.1');
        putenv('LFMIS_DB_PORT=4321');
        putenv('LFMIS_DB_NAME=test_db');
        putenv('LFMIS_DB_USER=test_user');
        putenv('LFMIS_DB_PASSWORD=test_password');
        putenv('LFMIS_DB_PASSWORD_EMPTY=0');
    }

    /**
 * Handles the tear down operation for the API router.
 * @return void Result of this operation.
 */
    protected function tearDown(): void
    {
        foreach (['LFMIS_DB_HOST', 'LFMIS_DB_PORT', 'LFMIS_DB_NAME', 'LFMIS_DB_USER', 'LFMIS_DB_PASSWORD', 'LFMIS_DB_PASSWORD_EMPTY'] as $key) {
            putenv($key);
        }
        $directory = $this->root . DIRECTORY_SEPARATOR . 'backups';
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
        rmdir($this->root);
    }

    /**
 * Runs the test creates dated backup without returning its absolute path behavior under test.
 * @return void Result of this operation.
 */
    public function testCreatesDatedBackupWithoutReturningItsAbsolutePath(): void
    {
        $command = [];
        $service = new BackupService($this->root, static function (array $args, string $path, string $password) use (&$command): int {
            $command = $args;
            file_put_contents($path, '-- mock database dump');
            return $password === 'test_password' ? 0 : 1;
        });

        $backup = $service->create();

        $this->assertMatchesRegularExpression('/^lfmis-\d{8}-\d{6}-\d{6}\.sql$/', $backup['filename']);
        $this->assertArrayNotHasKey('path', $backup);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('test_db', $command);
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $backup['filename']);
    }

    /**
 * Runs the test removes partial backup after dump failure behavior under test.
 * @return void Result of this operation.
 */
    public function testRemovesPartialBackupAfterDumpFailure(): void
    {
        $service = new BackupService($this->root, static function (array $args, string $path): int {
            file_put_contents($path, 'partial output');
            return 1;
        });

        try {
            $service->create();
            $this->fail('A failed dump must not leave a partial backup.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Database backup failed', $error->getMessage());
        }
        $this->assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    /**
 * Runs the test rejects unsafe database name before starting dump behavior under test.
 * @return void Result of this operation.
 */
    public function testRejectsUnsafeDatabaseNameBeforeStartingDump(): void
    {
        putenv('LFMIS_DB_NAME=bad;drop');
        $started = false;
        $service = new BackupService($this->root, static function () use (&$started): int {
            $started = true;
            return 0;
        });

        try {
            $service->create();
            $this->fail('An unsafe database name must be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('Database name or port', $error->getMessage());
        }
        $this->assertFalse($started);
    }
}
