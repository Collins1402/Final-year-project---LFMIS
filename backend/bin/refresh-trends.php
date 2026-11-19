<?php
declare(strict_types=1);

// CLI Job Runner: php backend/bin/refresh-trends.php [--force]
// Runs the scheduled 6-hour agricultural trends and market intelligence update aligned with Africa/Nairobi.

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/TrendsService.php';
require_once __DIR__ . '/../src/Services/SyntheticTrendsService.php';
require_once __DIR__ . '/../src/Services/FarmMetricsService.php';
require_once __DIR__ . '/../src/Jobs/RefreshTrends.php';

use Lfmis\Core\Database;
use Lfmis\Core\Env;
use Lfmis\Jobs\RefreshTrends;

Env::load(dirname(__DIR__, 2) . '/.env.local');
$db = Database::connection();
$job = new RefreshTrends($db);
$force = in_array('--force', $argv, true);
$result = $job->run($force);

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit($result['status'] === 'failed' ? 1 : 0);
