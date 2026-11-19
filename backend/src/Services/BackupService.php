<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\Env;

final class BackupService
{
    private ?Closure $runDump;

    public function __construct(private string $repositoryRoot, ?Closure $runDump = null)
    {
        $this->runDump = $runDump;
    }

    public function create(): array
    {
        $configuration = $this->databaseConfiguration();
        $directory = rtrim($this->repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The backup directory could not be created.');
        }

        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $filename = 'lfmis-' . $createdAt->format('Ymd-His-u') . '.sql';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $command = [
            'mysqldump',
            '--host=' . $configuration['host'],
            '--port=' . $configuration['port'],
            '--user=' . $configuration['user'],
            '--single-transaction',
            '--routines',
            '--triggers',
            $configuration['database'],
        ];
        $exitCode = $this->runDump !== null
            ? ($this->runDump)($command, $path, $configuration['password'])
            : $this->runMySqlDump($command, $path, $configuration['password']);
        if ($exitCode !== 0 || !is_file($path) || filesize($path) === 0) {
            if (is_file($path)) {
                unlink($path);
            }
            throw new \RuntimeException('MySQL backup failed. Confirm mysqldump is installed and database settings are valid.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }

        return ['filename' => $filename, 'createdAt' => $createdAt->format(DATE_ATOM)];
    }

    private function databaseConfiguration(): array
    {
        $database = (string) Env::required('LFMIS_DB_NAME');
        $port = (string) Env::get('LFMIS_DB_PORT', '4321');
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $database) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Database name or port is invalid for a backup.');
        }
        $password = Env::get('LFMIS_DB_PASSWORD_EMPTY') === '1'
            ? ''
            : Env::required('LFMIS_DB_PASSWORD');
        return [
            'host' => (string) Env::get('LFMIS_DB_HOST', '127.0.0.1'),
            'port' => $port,
            'database' => $database,
            'user' => Env::required('LFMIS_DB_USER'),
            'password' => $password,
        ];
    }

    private function runMySqlDump(array $command, string $path, string $password): int
    {
        $previousPassword = getenv('MYSQL_PWD');
        try {
            putenv('MYSQL_PWD=' . $password);
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = proc_open(
                $command,
                [0 => ['file', $nullDevice, 'r'], 1 => ['file', $path, 'wb'], 2 => ['file', $nullDevice, 'w']],
                $pipes,
                $this->repositoryRoot
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('MySQL backup process could not be started.');
            }
            return proc_close($process);
        } finally {
            putenv($previousPassword === false ? 'MYSQL_PWD' : 'MYSQL_PWD=' . $previousPassword);
        }
    }
}
