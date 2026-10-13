<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class OverviewService
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(): array
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $monthStart = date('Y-m-01');
        $milk = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN production_session = 'morning' THEN quantity_produced ELSE 0 END), 0) AS morning,
                    COALESCE(SUM(CASE WHEN production_session = 'evening' THEN quantity_produced ELSE 0 END), 0) AS evening
             FROM farm_produce WHERE LOWER(produce_name) = 'milk' AND production_date = ? AND LOWER(unit) IN ('litre', 'litres', 'l')"
        );
        $milk->execute([$today]);
        $milkToday = $milk->fetch(PDO::FETCH_ASSOC);
        $eggs = $this->db->prepare("SELECT COALESCE(SUM(quantity_produced), 0) FROM farm_produce WHERE LOWER(produce_name) = 'eggs' AND production_date = ?");
        $eggs->execute([$today]);

        $speciesCounts = array_fill_keys(['cattle', 'goat', 'sheep', 'rabbit', 'chicken', 'other'], 0);
        foreach ($this->db->query("SELECT species, COUNT(*) AS total FROM animals WHERE status = 'active' GROUP BY species")->fetchAll() as $row) {
            if (array_key_exists($row['species'], $speciesCounts)) {
                $speciesCounts[$row['species']] = (int) $row['total'];
            }
        }

        $dueThrough = date('Y-m-d', strtotime('+7 days'));
        $health = $this->db->prepare(
            'SELECT COUNT(*) FROM health_records hr JOIN animals a ON a.id = hr.animal_id
             WHERE ' . HealthService::OPEN_FOLLOW_UP . ' AND hr.next_due_date IS NOT NULL AND hr.next_due_date <= ?'
        );
        $health->execute([$dueThrough]);
        $monthSales = $this->db->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sold_at >= ? AND sold_at < ?');
        $monthSales->execute([$monthStart, $tomorrow]);
        $monthExpenses = $this->db->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date < ?');
        $monthExpenses->execute([$monthStart, $tomorrow]);

        return [
            'today' => $today,
            'milkToday' => ['morning' => (float) $milkToday['morning'], 'evening' => (float) $milkToday['evening']],
            'eggsToday' => (float) $eggs->fetchColumn(),
            'activeAnimalsBySpecies' => $speciesCounts,
            'activeAnimals' => array_sum($speciesCounts),
            'openTasks' => (int) $this->db->query("SELECT COUNT(*) FROM farm_activities WHERE status = 'planned'")->fetchColumn(),
            'lowStockCount' => (int) $this->db->query('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity_on_hand <= reorder_level')->fetchColumn(),
            'healthFollowUpsDue' => (int) $health->fetchColumn(),
            'pendingOrders' => (int) $this->db->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'quoted')")->fetchColumn(),
            'salesThisMonth' => (float) $monthSales->fetchColumn(),
            'expensesThisMonth' => (float) $monthExpenses->fetchColumn(),
        ];
    }
}