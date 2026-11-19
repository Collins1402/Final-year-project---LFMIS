<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Services/BackupService.php';

use Lfmis\Core\Env;
use Lfmis\Services\BackupService;

Env::load(dirname(__DIR__, 2) . '/.env.local');
$backup = (new BackupService(dirname(__DIR__, 2)))->create();
fwrite(STDOUT, sprintf("Backup created: %s (%s)\n", $backup['filename'], $backup['createdAt']));
