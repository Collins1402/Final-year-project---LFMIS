<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\InventoryService;
use PDO;
use PHPUnit\Framework\TestCase;

final class InventoryServiceTest extends TestCase
{
    public function testTransactionUpdatesBalanceAndStoresHistory(): void
    {
        $db = $this->database();
        $service = new InventoryService($db);

        $item = $service->transact(1, ['transactionType' => 'out', 'quantityChange' => '-3', 'reason' => 'Feed use'], 8);

        $this->assertSame(7.0, (float) $item['quantity_on_hand']);
        $history = $service->transactions(1);
        $this->assertCount(1, $history);
        $this->assertSame('Feed use', $history[0]['reason']);
    }

    public function testTransactionCannotMakeStockNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Inventory cannot become negative.');

        (new InventoryService($this->database()))->transact(1, ['transactionType' => 'out', 'quantityChange' => '-11'], 8);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, name TEXT, quantity_on_hand REAL, reorder_level REAL, category TEXT, unit TEXT, unit_cost REAL, is_active INTEGER)');
        $db->exec('CREATE TABLE inventory_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, transaction_type TEXT, quantity_change REAL, reason TEXT, transaction_date TEXT, recorded_by INTEGER)');
        $db->exec("INSERT INTO inventory_items VALUES (1, 'Hay', 10, 2, 'feed', 'bales', 50, 1)");
        return $db;
    }
}