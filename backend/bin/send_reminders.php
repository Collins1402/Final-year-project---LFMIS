<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/DeliveryException.php';
require_once __DIR__ . '/../src/Core/Mailer.php';
require_once __DIR__ . '/../src/Services/ReminderService.php';

use Lfmis\Core\Database;
use Lfmis\Core\Env;
use Lfmis\Services\ReminderService;

Env::load(dirname(__DIR__, 2) . '/.env.local');
$result = (new ReminderService(Database::connection()))->sendDue();
fwrite(STDOUT, sprintf(
    "Health reminders: %d due, %d email(s) sent, %d failed.\n",
    $result['selected'],
    $result['sent'],
    $result['failed']
));
if ($result['failed'] > 0) {
    fwrite(STDERR, "Some emails failed; they will be retried on the next run.\n");
    exit(1);
}
