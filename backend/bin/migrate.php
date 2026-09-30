<?php
declare(strict_types=1);

// Usage: php backend/bin/migrate.php
// Loads schema.sql into an empty database, then applies database/migrations/NNN_*.sql once each, in order.

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use Lfmis\Core\Database;
use Lfmis\Core\Env;

Env::load(dirname(__DIR__, 2) . '/.env.local');
$db = Database::connection();
$databaseDir = dirname(__DIR__) . '/database';

function runSqlFile(PDO $db, string $file): void
{
    $sql = (string) file_get_contents($file);
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
}

$hasSchema = (bool) $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
if (!$hasSchema) {
    runSqlFile($db, $databaseDir . '/schema.sql');
    fwrite(STDOUT, "Loaded schema.sql\n");
}

$applied = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$migrations = glob($databaseDir . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
sort($migrations, SORT_STRING);
foreach ($migrations as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    // MySQL DDL auto-commits, so a failed migration must be fixed and re-run by hand.
    runSqlFile($db, $file);
    $db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    fwrite(STDOUT, "Applied {$version}\n");
}

fwrite(STDOUT, "Database is up to date.\n");
