<?php
declare(strict_types=1);

namespace Lfmis\Services;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class AnalyticsService
{
    private const METRICS = [
        'milk' => ['title' => 'Milk production', 'unit' => 'litres', 'type' => 'line', 'scope' => 'production'],
        'eggs' => ['title' => 'Egg production', 'unit' => 'recorded units', 'type' => 'line', 'scope' => 'production'],
        'sales' => ['title' => 'Sales', 'unit' => 'KES', 'type' => 'line', 'scope' => 'finance'],
        'expenses' => ['title' => 'Expenses', 'unit' => 'KES', 'type' => 'line', 'scope' => 'finance'],
        'net' => ['title' => 'Net sales less expenses', 'unit' => 'KES', 'type' => 'line', 'scope' => 'finance'],
        'stock_levels' => ['title' => 'Current stock levels', 'unit' => 'on-hand quantity', 'type' => 'bar', 'scope' => 'production'],
        'animals_by_species' => ['title' => 'Animals by species', 'unit' => 'animals', 'type' => 'pie', 'scope' => 'production'],
        'animals_by_status' => ['title' => 'Animals by status', 'unit' => 'animals', 'type' => 'pie', 'scope' => 'production'],
        'health_followups_due' => ['title' => 'Health follow-ups due', 'unit' => 'follow-ups', 'type' => 'bar', 'scope' => 'health'],
        'health_records_by_type' => ['title' => 'Health records by type', 'unit' => 'records', 'type' => 'bar', 'scope' => 'health'],
        'orders_by_status' => ['title' => 'Orders by status', 'unit' => 'orders', 'type' => 'pie', 'scope' => 'production'],
    ];
    private const PERIOD_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];
    private const GROUPS = ['day', 'week', 'month', 'species', 'status', 'category', 'item'];

    public function __construct(
        private PDO $db,
        private ?PermissionService $permissions = null,
        private ?\Closure $clock = null
    ) {
        $this->permissions ??= new PermissionService($db);
    }

    public static function metricNames(): array { return array_keys(self::METRICS); }

    public function series(string $metric, string $period = '30d', ?string $groupBy = null, ?string $chartType = null, ?string $role = null): array
    {
        if (!isset(self::METRICS[$metric])) throw new \InvalidArgumentException('Choose a supported analytics metric.');
        if (!isset(self::PERIOD_DAYS[$period]) && $period !== '12m') throw new \InvalidArgumentException('Choose a supported analytics period: 7d, 30d, 90d or 12m.');
        if ($groupBy !== null && !in_array($groupBy, self::GROUPS, true)) throw new \InvalidArgumentException('Choose a supported group-by value.');
        if ($chartType !== null && !in_array($chartType, ['bar', 'line', 'pie'], true)) throw new \InvalidArgumentException('Choose a supported chart type.');
        $definition = self::METRICS[$metric];
        if ($role !== null && !$this->permissions->canViewAnalytics($role, $definition['scope'])) {
            throw new AnalyticsAccessException('Your role cannot access this farm analytics metric.');
        }
        $groupBy ??= in_array($metric, ['animals_by_species'], true) ? 'species' : (in_array($metric, ['animals_by_status', 'orders_by_status', 'health_followups_due'], true) ? 'status' : (in_array($metric, ['stock_levels'], true) ? 'item' : ($period === '12m' ? 'month' : 'day')));
        $this->validateGrouping($metric, $groupBy);
        $now = $this->now();
        $window = $this->window($period, $now);
        $observations = $this->fetchValues($metric, $window['start'], $window['end'], $groupBy, $now);
        $labels = $this->labels($metric, $window['start'], $window['end'], $groupBy, $observations);
        $values = array_fill_keys($labels, 0.0);
        foreach ($observations as $row) {
            $label = (string) $row['label'];
            if (array_key_exists($label, $values)) $values[$label] += (float) $row['value'];
        }
        if (in_array($metric, ['stock_levels', 'animals_by_species', 'animals_by_status', 'orders_by_status', 'health_records_by_type'], true)) {
            $values = [];
            foreach ($observations as $row) $values[(string) $row['label']] = ($values[(string) $row['label']] ?? 0.0) + (float) $row['value'];
            $labels = array_keys($values);
        }
        if ($metric === 'net') {
            $sales = $this->bucketValues($this->fetchValues('sales', $window['start'], $window['end'], 'day', $now), $labels, $groupBy);
            $expenses = $this->bucketValues($this->fetchValues('expenses', $window['start'], $window['end'], 'day', $now), $labels, $groupBy);
            foreach ($labels as $label) $values[$label] = ($sales[$label] ?? 0.0) - ($expenses[$label] ?? 0.0);
        }
        if ($observations === []) { $labels = []; $values = []; }
        $previous = $this->previousTotal($metric, $window, $groupBy, $now);
        $data = array_map(static fn($value): float|int => round((float) $value, 2), array_values($values));
        $stats = $this->stats($labels, $data, $previous, count($observations));
        return [
            'title' => $definition['title'],
            'type' => $chartType ?? $definition['type'],
            'labels' => $labels,
            'datasets' => [['label' => $definition['title'], 'data' => $data]],
            'unit' => $definition['unit'],
            'hasData' => $observations !== [],
            'message' => $observations === [] ? 'No recorded data is available for this metric and period.' : null,
            'stats' => $stats,
        ];
    }

    public function recordedProductionDays(int $periodDays = 90): int
    {
        $start = $this->now()->modify('-' . max(1, $periodDays - 1) . ' days')->format('Y-m-d');
        $end = $this->now()->modify('+1 day')->format('Y-m-d');
        $query = $this->db->prepare("SELECT COUNT(DISTINCT production_date) FROM farm_produce WHERE production_date >= ? AND production_date < ? AND LOWER(produce_name) IN ('milk','eggs') AND COALESCE(notes, '') NOT LIKE '%SIMULATED DATA%' AND COALESCE(notes, '') NOT LIKE '%TEST DATA%'");
        $query->execute([$start, $end]);
        return (int) $query->fetchColumn();
    }

    public function recordedDays(string $scope, int $periodDays = 90): int
    {
        $start = $this->now()->modify('-' . max(1, $periodDays - 1) . ' days')->format('Y-m-d');
        $end = $this->now()->modify('+1 day')->format('Y-m-d');
        if ($scope === 'production') return $this->recordedProductionDays($periodDays);
        if ($scope === 'health') {
            $q = $this->db->prepare('SELECT COUNT(DISTINCT hr.record_date) FROM health_records hr JOIN animals a ON a.id = hr.animal_id WHERE a.status = ? AND hr.record_date >= ? AND hr.record_date < ?');
            $q->execute(['active', $start, $end]); return (int)$q->fetchColumn();
        }
        if ($scope === 'finance') {
            $dates = [];
            foreach ([['SELECT sold_at AS at FROM sales WHERE sold_at >= ? AND sold_at < ?', [$start,$end]], ['SELECT expense_date AS at FROM expenses WHERE expense_date >= ? AND expense_date < ?', [$start,$end]]] as [$sql,$params]) {
                foreach ($this->rows($sql, $params) as $row) $dates[substr((string)$row['at'],0,10)] = true;
            }
            return count($dates);
        }
        throw new \InvalidArgumentException('Choose a supported analytics scope.');
    }

    private function validateGrouping(string $metric, string $groupBy): void
    {
        $time = ['day', 'week', 'month'];
        $allowed = match ($metric) {
            'milk', 'eggs', 'sales', 'expenses', 'net' => $time,
            'stock_levels' => ['item', 'category'],
            'animals_by_species' => ['species'],
            'animals_by_status', 'orders_by_status' => ['status'],
            'health_followups_due' => array_merge($time, ['status']),
            'health_records_by_type' => ['category'],
            default => [],
        };
        if (!in_array($groupBy, $allowed, true)) throw new \InvalidArgumentException('That group-by value is not valid for the selected metric.');
    }

    private function fetchValues(string $metric, string $start, string $end, string $groupBy, DateTimeImmutable $now): array
    {
        if ($metric === 'milk' || $metric === 'eggs') {
            $product = $metric;
            $unitFilter = $metric === 'milk' ? " AND LOWER(unit) IN ('litre', 'litres', 'l')" : '';
            $query = $this->db->prepare("SELECT production_date AS at, quantity_produced AS value FROM farm_produce WHERE LOWER(produce_name) = ? {$unitFilter} AND production_date >= ? AND production_date < ? AND COALESCE(notes, '') NOT LIKE '%SIMULATED DATA%' AND COALESCE(notes, '') NOT LIKE '%TEST DATA%'");
            $query->execute([$product, $start, $end]);
            return $this->labelRows($query->fetchAll(PDO::FETCH_ASSOC), $groupBy);
        }
        if (in_array($metric, ['sales', 'expenses'], true)) {
            $table = $metric;
            $dateColumn = $metric === 'sales' ? 'sold_at' : 'expense_date';
            $query = $this->db->prepare("SELECT {$dateColumn} AS at, " . ($metric === 'sales' ? 'total_amount' : 'amount') . " AS value FROM {$table} WHERE {$dateColumn} >= ? AND {$dateColumn} < ?");
            $query->execute([$start, $end]);
            return $this->labelRows($query->fetchAll(PDO::FETCH_ASSOC), $groupBy);
        }
        if ($metric === 'net') {
            $sales = $this->labelRows($this->rows("SELECT sold_at AS at, total_amount AS value, 'sales' AS source FROM sales WHERE sold_at >= ? AND sold_at < ?", [$start, $end]), $groupBy);
            $expenses = $this->labelRows($this->rows("SELECT expense_date AS at, amount AS value, 'expense' AS source FROM expenses WHERE expense_date >= ? AND expense_date < ?", [$start, $end]), $groupBy);
            $all = [];
            foreach (array_merge($sales, $expenses) as $row) $all[$row['label']] = ($all[$row['label']] ?? 0.0) + ($row['source'] === 'expense' ? -(float)$row['value'] : (float)$row['value']);
            return array_map(static fn($label, $value): array => ['label' => $label, 'value' => $value], array_keys($all), array_values($all));
        }
        if ($metric === 'stock_levels') {
            $rows = $this->simpleRows('SELECT name, category, unit, quantity_on_hand AS value FROM inventory_items WHERE is_active = 1 ORDER BY category, name');
            $grouped = [];
            foreach ($rows as $row) {
                $label = $groupBy === 'category' ? $row['category'] . ' (' . $row['unit'] . ')' : $row['name'] . ' (' . $row['unit'] . ')';
                $grouped[$label] = ($grouped[$label] ?? 0.0) + (float)$row['value'];
            }
            return array_map(static fn($label, $value): array => ['label'=>$label,'value'=>$value], array_keys($grouped), array_values($grouped));
        }
        if ($metric === 'animals_by_species') return $this->simpleRows('SELECT species AS label, COUNT(*) AS value FROM animals GROUP BY species ORDER BY species');
        if ($metric === 'animals_by_status') return $this->simpleRows('SELECT status AS label, COUNT(*) AS value FROM animals GROUP BY status ORDER BY status');
        if ($metric === 'orders_by_status') {
            $q = $this->db->prepare('SELECT status AS label, COUNT(*) AS value FROM orders WHERE ordered_at >= ? AND ordered_at < ? GROUP BY status ORDER BY status');
            $q->execute([$start, $end]); return $q->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($metric === 'health_followups_due') {
            $query = $this->db->prepare('SELECT hr.next_due_date AS at, COUNT(*) AS value FROM health_records hr JOIN animals a ON a.id = hr.animal_id WHERE ' . HealthService::OPEN_FOLLOW_UP . ' AND hr.next_due_date IS NOT NULL AND hr.next_due_date >= ? AND hr.next_due_date < ? GROUP BY hr.next_due_date ORDER BY hr.next_due_date');
            $query->execute([$start, $end]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($groupBy === 'status') {
                $overdue = $due = 0;
                foreach ($rows as $row) { if ((string)$row['at'] < $now->format('Y-m-d')) $overdue += (int)$row['value']; else $due += (int)$row['value']; }
                $result = []; if ($overdue) $result[] = ['label' => 'overdue', 'value' => $overdue]; if ($due) $result[] = ['label' => 'upcoming', 'value' => $due]; return $result;
            }
            return $this->labelRows($rows, $groupBy, true);
        }
        if ($metric === 'health_records_by_type') {
            $q = $this->db->prepare('SELECT record_type AS label, COUNT(*) AS value FROM health_records WHERE record_date >= ? AND record_date < ? GROUP BY record_type ORDER BY record_type');
            $q->execute([$start, $end]); return $q->fetchAll(PDO::FETCH_ASSOC);
        }
        return [];
    }

    private function previousTotal(string $metric, array $window, string $groupBy, DateTimeImmutable $now): ?float
    {
        if (in_array($metric, ['stock_levels', 'animals_by_species', 'animals_by_status'], true)) return null;
        $days = max(1, (int)$window['days']);
        $previousEnd = $window['start'];
        $previousStart = (new DateTimeImmutable($previousEnd))->modify('-' . $days . ' days')->format('Y-m-d');
        $rows = $this->fetchValues($metric, $previousStart, $previousEnd, $groupBy, $now);
        return array_sum(array_map(static fn(array $row): float => (float)$row['value'], $rows));
    }

    private function stats(array $labels, array $data, ?float $previous, int $recordCount): array
    {
        if ($recordCount === 0 || $data === []) return ['total' => null, 'average' => null, 'changePct' => null, 'trend' => 'no_data', 'movingAvg7' => null, 'bestPeriod' => null, 'worstPeriod' => null, 'anomalies' => []];
        $sum = array_sum($data); $average = $sum / count($data);
        $nonzero = array_values(array_filter($data, static fn($value): bool => (float)$value !== 0.0));
        $first = $nonzero[0] ?? 0; $last = $nonzero[count($nonzero)-1] ?? 0;
        $delta = (float)$last - (float)$first;
        $trend = abs($delta) < 0.00001 ? 'stable' : ($delta > 0 ? 'up' : 'down');
        $change = $previous === null || abs($previous) < 0.00001 ? null : round((($sum - $previous) / abs($previous)) * 100, 2);
        $tail = array_slice($data, -7); $mean7 = array_sum($tail) / max(1, count($tail));
        $bestIndex = array_keys($data, max($data), true)[0] ?? null; $worstIndex = array_keys($data, min($data), true)[0] ?? null;
        $variance = array_sum(array_map(static fn($v): float => ((float)$v - $average) ** 2, $data)) / count($data); $sd = sqrt($variance); $anomalies = [];
        if ($sd > 0) foreach ($data as $i => $value) if (abs((float)$value - $average) > 2 * $sd) $anomalies[] = ['period' => $labels[$i], 'value' => $value];
        return ['total' => round($sum, 2), 'average' => round($average, 2), 'changePct' => $change, 'trend' => $trend, 'movingAvg7' => round($mean7, 2), 'bestPeriod' => $bestIndex === null ? null : ['period' => $labels[$bestIndex], 'value' => $data[$bestIndex]], 'worstPeriod' => $worstIndex === null ? null : ['period' => $labels[$worstIndex], 'value' => $data[$worstIndex]], 'anomalies' => $anomalies];
    }

    private function labelRows(array $rows, string $groupBy, bool $groupedCount = false): array
    {
        $out = [];
        foreach ($rows as $row) {
            $date = new DateTimeImmutable(substr((string)$row['at'], 0, 10));
            $label = match ($groupBy) { 'day' => $date->format('Y-m-d'), 'week' => $date->modify('monday this week')->format('Y-m-d'), 'month' => $date->format('Y-m'), default => (string)$row['at'] };
            $out[] = ['label' => $label, 'value' => (float)$row['value'], 'source' => ($row['source'] ?? '')];
        }
        if (!$groupedCount) return $out;
        $grouped = []; foreach ($out as $row) $grouped[$row['label']] = ($grouped[$row['label']] ?? 0) + $row['value'];
        return array_map(static fn($label, $value): array => ['label' => $label, 'value' => $value], array_keys($grouped), array_values($grouped));
    }

    private function labels(string $metric, string $start, string $end, string $groupBy, array $rows): array
    {
        if (in_array($metric, ['stock_levels', 'animals_by_species', 'animals_by_status', 'orders_by_status', 'health_records_by_type'], true) || ($metric === 'health_followups_due' && $groupBy === 'status')) return array_values(array_unique(array_map(static fn(array $row): string => (string)$row['label'], $rows)));
        $labels = [];
        if ($groupBy === 'month') {
            $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01'); $last = (new DateTimeImmutable($end))->modify('-1 day')->modify('first day of this month');
            while ($cursor <= $last) { $labels[] = $cursor->format('Y-m'); $cursor = $cursor->modify('+1 month'); }
        } else {
            $cursor = new DateTimeImmutable($start); $last = (new DateTimeImmutable($end))->modify('-1 day');
            if ($groupBy === 'week') $cursor = $cursor->modify('monday this week');
            while ($cursor <= $last) { $labels[] = $cursor->format('Y-m-d'); $cursor = $cursor->modify($groupBy === 'week' ? '+1 week' : '+1 day'); }
        }
        return $labels;
    }

    private function bucketValues(array $rows, array $labels, string $groupBy): array
    {
        $out = array_fill_keys($labels, 0.0);
        foreach ($rows as $row) if (isset($out[$row['label']])) $out[$row['label']] += (float)$row['value'];
        return $out;
    }

    private function simpleRows(string $sql): array { return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    private function rows(string $sql, array $params): array { $q = $this->db->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC); }

    private function window(string $period, DateTimeImmutable $now): array
    {
        if ($period === '12m') { $start = $now->modify('first day of this month')->modify('-11 months'); $end = $now->modify('tomorrow')->setTime(0, 0); $days = 365; }
        else { $days = self::PERIOD_DAYS[$period]; $start = $now->setTime(0,0)->modify('-' . ($days - 1) . ' days'); $end = $now->setTime(0,0)->modify('+1 day'); }
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days];
    }

    private function now(): DateTimeImmutable { return $this->clock ? ($this->clock)() : new DateTimeImmutable('today'); }
}
