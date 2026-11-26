<?php
/**
 * File purpose: implements ReportService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class ReportService
{
    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db)
    {
    }

    /**
 * Performs the summary operation for this service.
 * @param bool $includeFinance Input used by this operation.
 * @return array Result of this operation.
 */
    public function summary(bool $includeFinance = true): array
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $monthStart = date('Y-m-01', strtotime('-11 months'));
        $months = [];
        $cursor = new \DateTimeImmutable($monthStart);
        for ($index = 0; $index < 12; $index++) {
            $month = $cursor->modify('+' . $index . ' months')->format('Y-m');
            $months[$month] = ['month' => $month, 'sales' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'production' => []];
        }

        if ($includeFinance) {
            $sales = $this->db->prepare('SELECT sold_at, total_amount FROM sales WHERE sold_at >= ? AND sold_at < ?');
            $sales->execute([$monthStart, $tomorrow]);
            foreach ($sales->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $month = substr((string) $row['sold_at'], 0, 7);
                if (isset($months[$month])) $months[$month]['sales'] += (float) $row['total_amount'];
            }
            $expenses = $this->db->prepare('SELECT expense_date, amount FROM expenses WHERE expense_date >= ? AND expense_date < ?');
            $expenses->execute([$monthStart, $tomorrow]);
            foreach ($expenses->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $month = substr((string) $row['expense_date'], 0, 7);
                if (isset($months[$month])) $months[$month]['expenses'] += (float) $row['amount'];
            }
        }
        $production = $this->db->prepare(
            'SELECT production_date, produce_name, unit, SUM(quantity_produced) AS produced
             FROM farm_produce WHERE production_date >= ? AND production_date < ?
             GROUP BY production_date, produce_name, unit'
        );
        $production->execute([$monthStart, $tomorrow]);
        foreach ($production->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month = substr((string) $row['production_date'], 0, 7);
            $product = $row['produce_name'] . ' (' . $row['unit'] . ')';
            if (!isset($months[$month])) continue;
            if (!isset($months[$month]['production'][$product])) $months[$month]['production'][$product] = 0.0;
            $months[$month]['production'][$product] += (float) $row['produced'];
        }
        foreach ($months as &$values) {
            $values['net'] = $values['sales'] - $values['expenses'];
            ksort($values['production']);
        }
        unset($values);

        $dueThrough = date('Y-m-d', strtotime('+7 days'));
        $health = $this->db->prepare(
            'SELECT COUNT(*) AS due, SUM(CASE WHEN hr.next_due_date < ? THEN 1 ELSE 0 END) AS overdue
             FROM health_records hr JOIN animals a ON a.id = hr.animal_id
             WHERE ' . HealthService::OPEN_FOLLOW_UP . ' AND hr.next_due_date IS NOT NULL AND hr.next_due_date <= ?'
        );
        $health->execute([$today, $dueThrough]);
        $healthSummary = $health->fetch(PDO::FETCH_ASSOC);
        $recentHealth = $this->db->prepare('SELECT COUNT(*) FROM health_records WHERE record_date >= ? AND record_date < ?');
        $recentHealth->execute([date('Y-m-01'), $tomorrow]);

        return [
            'months' => array_values($months),
            'health' => [
                'followUpsDue' => (int) ($healthSummary['due'] ?? 0),
                'overdueFollowUps' => (int) ($healthSummary['overdue'] ?? 0),
                'healthRecordsThisMonth' => (int) $recentHealth->fetchColumn(),
            ],
        ];
    }
}
