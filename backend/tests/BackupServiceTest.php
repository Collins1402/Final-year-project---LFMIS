<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\BackupService;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private string $root;

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
            $this->assertStringContainsString('MySQL backup failed', $error->getMessage());
        }
        $this->assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*') ?: []);
    }

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
