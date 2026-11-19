<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;
use Throwable;

final class FarmMetricsService
{
    public const DISCLAIMER = 'ACTUAL FARM DATA — MEASURED OPERATIONAL RECORDS';

    public function __construct(private PDO $db) {}

    public function summary(): array
    {
        $hasData = false;
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $monthStart = date('Y-m-01');

        $milkTodayTotal = 0.0;
        $milkTodayMorning = 0.0;
        $milkTodayEvening = 0.0;
        $milkMonthTotal = 0.0;
        $eggsToday = 0.0;
        $eggsMonthTotal = 0.0;

        try {
            // Milk today
            $milkStmt = $this->db->prepare("SELECT production_session, COALESCE(SUM(quantity_produced), 0) as total FROM farm_produce WHERE LOWER(produce_name) = 'milk' AND production_date = ? GROUP BY production_session");
            $milkStmt->execute([$today]);
            while ($row = $milkStmt->fetch(PDO::FETCH_ASSOC)) {
                $hasData = true;
                $val = (float) $row['total'];
                $session = strtolower((string) ($row['production_session'] ?? ''));
                if ($session === 'morning') $milkTodayMorning += $val;
                elseif ($session === 'evening') $milkTodayEvening += $val;
                $milkTodayTotal += $val;
            }

            // Milk month
            $milkMonthStmt = $this->db->prepare("SELECT COALESCE(SUM(quantity_produced), 0) FROM farm_produce WHERE LOWER(produce_name) = 'milk' AND production_date >= ? AND production_date < ?");
            $milkMonthStmt->execute([$monthStart, $tomorrow]);
            $milkMonth = $milkMonthStmt->fetchColumn();
            if ($milkMonth !== false && (float) $milkMonth > 0) {
                $hasData = true;
                $milkMonthTotal = (float) $milkMonth;
            }

            // Eggs today
            $eggsTodayStmt = $this->db->prepare("SELECT COALESCE(SUM(quantity_produced), 0) FROM farm_produce WHERE LOWER(produce_name) IN ('eggs', 'egg') AND production_date = ?");
            $eggsTodayStmt->execute([$today]);
            $eggsTodayVal = $eggsTodayStmt->fetchColumn();
            if ($eggsTodayVal !== false && (float) $eggsTodayVal > 0) {
                $hasData = true;
                $eggsToday = (float) $eggsTodayVal;
            }

            // Eggs month
            $eggsMonthStmt = $this->db->prepare("SELECT COALESCE(SUM(quantity_produced), 0) FROM farm_produce WHERE LOWER(produce_name) IN ('eggs', 'egg') AND production_date >= ? AND production_date < ?");
            $eggsMonthStmt->execute([$monthStart, $tomorrow]);
            $eggsMonthVal = $eggsMonthStmt->fetchColumn();
            if ($eggsMonthVal !== false && (float) $eggsMonthVal > 0) {
                $hasData = true;
                $eggsMonthTotal = (float) $eggsMonthVal;
            }
        } catch (Throwable) {
            // Fallback for tests / schema variations
        }

        // Active livestock by species
        $activeAnimals = 0;
        $speciesBreakdown = [];
        try {
            $stmt = $this->db->query("SELECT species, COUNT(*) as cnt FROM animals WHERE status = 'active' GROUP BY species");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hasData = true;
                $sp = (string) $row['species'];
                $count = (int) $row['cnt'];
                $speciesBreakdown[$sp] = $count;
                $activeAnimals += $count;
            }
        } catch (Throwable) {}

        // Inventory shortages
        $shortageCount = 0;
        $shortageItems = [];
        try {
            $stmt = $this->db->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level FROM inventory_items WHERE is_active = 1 AND quantity_on_hand <= reorder_level LIMIT 10');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $hasData = true;
                $shortageCount++;
                $shortageItems[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'category' => (string) $row['category'],
                    'onHand' => (float) $row['quantity_on_hand'],
                    'reorderLevel' => (float) $row['reorder_level'],
                    'unit' => (string) $row['unit'],
                ];
            }
        } catch (Throwable) {}

        // Feed consumption this month
        $feedConsumedMonth = 0.0;
        try {
            $feedStmt = $this->db->prepare("SELECT COALESCE(SUM(ABS(it.quantity_change)), 0) FROM inventory_transactions it JOIN inventory_items ii ON ii.id = it.item_id WHERE ii.category = 'feed' AND it.quantity_change < 0 AND it.transaction_date >= ? AND it.transaction_date < ?");
            $feedStmt->execute([$monthStart, $tomorrow]);
            $feedVal = $feedStmt->fetchColumn();
            if ($feedVal !== false && (float) $feedVal > 0) {
                $hasData = true;
                $feedConsumedMonth = (float) $feedVal;
            }
        } catch (Throwable) {}

        // Finance this month
        $salesMonth = 0.0;
        $expensesMonth = 0.0;
        try {
            $salesStmt = $this->db->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM sales WHERE sold_at >= ? AND sold_at < ?");
            $salesStmt->execute([$monthStart, $tomorrow]);
            $salesVal = $salesStmt->fetchColumn();
            if ($salesVal !== false && (float) $salesVal > 0) {
                $hasData = true;
                $salesMonth = (float) $salesVal;
            }
            $expStmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date < ?");
            $expStmt->execute([$monthStart, $tomorrow]);
            $expVal = $expStmt->fetchColumn();
            if ($expVal !== false && (float) $expVal > 0) {
                $hasData = true;
                $expensesMonth = (float) $expVal;
            }
        } catch (Throwable) {}
        $netMargin = round($salesMonth - $expensesMonth, 2);

        // Health follow-ups
        $healthDueSoon = 0;
        try {
            $dueThrough = date('Y-m-d', strtotime('+7 days'));
            $healthStmt = $this->db->prepare('SELECT COUNT(*) FROM health_records hr JOIN animals a ON a.id = hr.animal_id WHERE ' . HealthService::OPEN_FOLLOW_UP . ' AND hr.next_due_date IS NOT NULL AND hr.next_due_date <= ?');
            $healthStmt->execute([$dueThrough]);
            $healthDueSoon = (int) $healthStmt->fetchColumn();
            if ($healthDueSoon > 0) $hasData = true;
        } catch (Throwable) {}

        // Weather
        $weatherLatest = null;
        try {
            $wStmt = $this->db->query('SELECT observation_date, rain_mm, temp_c, source, fetched_at FROM weather_observations ORDER BY observation_date DESC LIMIT 1');
            $weatherLatest = $wStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {}

        return [
            'hasData' => $hasData,
            'disclaimer' => self::DISCLAIMER,
            'sourceType' => 'actual_farm',
            'updatedAt' => gmdate('Y-m-d H:i:s'),
            'metrics' => [
                'milk' => [
                    'todayTotal' => round($milkTodayTotal, 2),
                    'morning' => round($milkTodayMorning, 2),
                    'evening' => round($milkTodayEvening, 2),
                    'monthTotal' => round($milkMonthTotal, 2),
                    'unit' => 'litres',
                ],
                'eggs' => [
                    'todayTotal' => round($eggsToday, 2),
                    'monthTotal' => round($eggsMonthTotal, 2),
                    'unit' => 'trays',
                ],
                'livestock' => [
                    'activeCount' => $activeAnimals,
                    'bySpecies' => $speciesBreakdown,
                ],
                'inventory' => [
                    'shortagesCount' => $shortageCount,
                    'shortages' => $shortageItems,
                    'feedConsumedMonth' => round($feedConsumedMonth, 2),
                ],
                'finance' => [
                    'salesThisMonth' => round($salesMonth, 2),
                    'expensesThisMonth' => round($expensesMonth, 2),
                    'netMarginThisMonth' => $netMargin,
                    'currency' => 'KES',
                ],
                'health' => [
                    'followUpsDue7Days' => $healthDueSoon,
                ],
                'weather' => $weatherLatest,
            ],
        ];
    }
}
