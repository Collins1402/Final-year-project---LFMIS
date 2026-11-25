<?php
declare(strict_types=1);

// Usage:
// php backend/bin/import_synthetic.php
// php backend/bin/import_synthetic.php --reset-only
// Loads only generated synthetic staging SQL; cleanup follows synthetic labels and relationships.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use Lfmis\Core\Database;
use Lfmis\Core\Env;

Env::load(dirname(__DIR__, 2) . '/.env.local');

$resetOnly = in_array('--reset-only', $argv, true);
$root = dirname(__DIR__, 2);
$resetFile = $root . '/seed_data/reset-synthetic-workbook.sql';
$seedFile = $root . '/seed_data/synthetic-workbook.sql';

if (!is_file($resetFile) || (!$resetOnly && !is_file($seedFile))) {
    throw new \RuntimeException('Run python scripts/import_synthetic_workbook.py before applying synthetic data.');
}

function applySqlFile(PDO $db, string $file): int
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new \RuntimeException('Synthetic SQL file could not be read.');
    }
    $statements = 0;
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
        $statements++;
    }
    return $statements;
}

$db = Database::connection();
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$resetCount = applySqlFile($db, $resetFile);
$seedCount = 0;
if (!$resetOnly) {
    $seedCount = applySqlFile($db, $seedFile);
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

fwrite(STDOUT, sprintf(
    "Synthetic data %s: %d reset statements, %d import statements.\n",
    $resetOnly ? 'removed' : 'reset and imported',
    $resetCount,
    $seedCount
));
