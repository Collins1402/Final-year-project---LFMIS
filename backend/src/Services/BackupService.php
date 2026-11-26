<?php
/**
 * File purpose: implements BackupService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\Env;
use PDO;
use Throwable;

final class BackupService
{
    private ?Closure $runDump;

    /**
 * Connects this service to its required dependency.
 * @param string $repositoryRoot Input used by this operation.
 * @param ?Closure $runDump Input used by this operation.
 * @param ?PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private string $repositoryRoot, ?Closure $runDump = null, private ?PDO $db = null)
    {
        $this->runDump = $runDump;
    }

    /**
 * Validates input and creates a new record.
 * @return array Result of this operation.
 */
    public function create(): array
    {
        $configuration = $this->databaseConfiguration();
        $directory = rtrim($this->repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new BackupException('The backup directory could not be created.');
        }

        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $filename = 'lfmis-' . $createdAt->format('Ymd-His-u') . '.sql';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $command = [
            $this->dumpExecutable(),
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
            if ($this->db !== null) {
                try {
                    $this->writePdoDump($path);
                } catch (Throwable $error) {
                    if (is_file($path)) unlink($path);
                    error_log('PDO database backup fallback failed: ' . $error->getMessage());
                }
            }
            if (!is_file($path) || filesize($path) === 0) {
                throw new BackupException('Database backup failed. Check the database connection and server logs.');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }

        return ['filename' => $filename, 'createdAt' => $createdAt->format(DATE_ATOM)];
    }

    /**
 * Performs the database configuration operation for this service.
 * @return array Result of this operation.
 */
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

    /**
 * Performs the run my sql dump operation for this service.
 * @param array $command Input used by this operation.
 * @param string $path Input used by this operation.
 * @param string $password Input used by this operation.
 * @return int Result of this operation.
 */
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
                throw new BackupException('MySQL backup process could not be started.');
            }
            return proc_close($process);
        } finally {
            putenv($previousPassword === false ? 'MYSQL_PWD' : 'MYSQL_PWD=' . $previousPassword);
        }
    }

    /**
 * Performs the dump executable operation for this service.
 * @return string Result of this operation.
 */
    private function dumpExecutable(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $xamppDump = realpath(dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe');
            if ($xamppDump !== false && is_file($xamppDump)) {
                return $xamppDump;
            }
        }
        return 'mysqldump';
    }

    /**
 * Performs the write pdo dump operation for this service.
 * @param string $path Input used by this operation.
 * @return void Result of this operation.
 */
    private function writePdoDump(string $path): void
    {
        if ($this->db === null) throw new BackupException('A database connection is required for the SQL backup fallback.');
        $stream = fopen($path, 'wb');
        if ($stream === false) throw new BackupException('The SQL backup file could not be opened.');
        try {
            $this->write($stream, "-- LFMIS SQL backup created " . gmdate(DATE_ATOM) . "\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $tableRow) {
                $table = (string) $tableRow[0];
                $quotedTable = $this->quoteIdentifier($table);
                $create = $this->db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
                if (!isset($create[1])) throw new BackupException('A database table definition could not be read.');
                $this->write($stream, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $create[1] . ";\n\n");

                $columnRows = $this->db->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_map(static fn(array $column): string => (string) $column['Field'], $columnRows);
                $binaryColumns = [];
                foreach ($columnRows as $column) {
                    $type = strtolower((string) $column['Type']);
                    if (preg_match('/^(?:tiny|medium|long)?blob|^(?:var)?binary|^bit\\b|^geometry\\b/', $type)) {
                        $binaryColumns[] = true;
                    } else {
                        $binaryColumns[] = false;
                    }
                }
                if ($columns === []) continue;
                $data = $this->db->query('SELECT * FROM ' . $quotedTable);
                while (($values = $data->fetch(PDO::FETCH_NUM)) !== false) {
                    $encoded = [];
                    foreach ($values as $index => $value) {
                        if ($value === null) {
                            $encoded[] = 'NULL';
                        } elseif ($binaryColumns[$index] ?? false) {
                            $encoded[] = "X'" . bin2hex((string) $value) . "'";
                        } else {
                            $quoted = $this->db->quote((string) $value, PDO::PARAM_STR);
                            if ($quoted === false) throw new BackupException('A database value could not be encoded for backup.');
                            $encoded[] = $quoted;
                        }
                    }
                    $quotedColumns = implode(', ', array_map(fn(string $column): string => $this->quoteIdentifier($column), $columns));
                    $this->write($stream, 'INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES (' . implode(', ', $encoded) . ");\n");
                }
                $this->write($stream, "\n");
            }
            $this->write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($stream);
        }
    }

    /**
 * Performs the quote identifier operation for this service.
 * @param string $identifier Input used by this operation.
 * @return string Result of this operation.
 */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
 * Performs the write operation for this service.
 * @param mixed $stream Input used by this operation.
 * @param string $contents Input used by this operation.
 * @return void Result of this operation.
 */
    private function write($stream, string $contents): void
    {
        if (fwrite($stream, $contents) !== strlen($contents)) {
            throw new BackupException('The SQL backup file could not be written completely.');
        }
    }
}
