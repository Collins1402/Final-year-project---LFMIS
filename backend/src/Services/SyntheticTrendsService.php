<?php
declare(strict_types=1);

namespace Lfmis\Services;

use DateTimeImmutable;
use DateTimeZone;
use Lfmis\Core\Env;
use PDO;
use Throwable;

final class SyntheticTrendsService
{
    public const DISCLAIMER = 'SIMULATED DATA — NOT REAL MARKET INFORMATION';
    public const EVENT_DISCLAIMER = 'SIMULATED EVENT — NOT REAL';

    public const CATEGORY_DAIRY = 'dairy';
    public const CATEGORY_POULTRY = 'poultry';
    public const CATEGORY_LIVESTOCK = 'livestock';
    public const CATEGORY_ANIMAL_FEED = 'animal_feed';
    public const CATEGORY_FARM_ECONOMY = 'farm_economy';
    public const CATEGORY_PRODUCTION = 'production';

    public const SCOPES = ['kenya', 'east_africa', 'global'];

    public function __construct(private PDO $db) {}

    public function generate(string $periodStart, ?int $seed = null): array
    {
        // Deterministic seed if specified or derived from periodStart
        if ($seed === null) {
            $configured = Env::get('LFMIS_SYNTHETIC_SEED');
            $seed = $configured !== null && is_numeric($configured)
                ? (int) $configured
                : abs((int) crc32($periodStart));
        }

        $events = $this->activeEvents($periodStart, $seed);
        $result = [];

        foreach (self::SCOPES as $scope) {
            $previousMetrics = $this->loadPreviousMetrics($scope);
            $metricsByCategory = [];

            foreach ($this->definitions() as $category => $defs) {
                $metricsByCategory[$category] = [];
                foreach ($defs as $metricKey => $meta) {
                    $baseVal = $previousMetrics[$metricKey]['value'] ?? $meta['default'];
                    $variation = $this->calculateVariation($metricKey, $scope, $seed);
                    $eventMultiplier = $this->evaluateEventMultiplier($metricKey, $scope, $events);

                    $rawVal = $baseVal * (1.0 + $variation) * $eventMultiplier;
                    // Clamp to realistic bounds
                    $newVal = round(max($meta['min'], min($meta['max'], $rawVal)), 2);
                    $prevVal = round($baseVal, 2);

                    $changePct = $prevVal > 0.0
                        ? round((($newVal - $prevVal) / $prevVal) * 100.0, 2)
                        : 0.0;

                    $metricRecord = [
                        'metricName' => $metricKey,
                        'label' => $meta['label'],
                        'category' => $category,
                        'geographicScope' => $scope,
                        'metricValue' => $newVal,
                        'previousValue' => $prevVal,
                        'changePercent' => $changePct,
                        'unit' => $meta['unit'],
                        'isSimulated' => true,
                        'disclaimer' => self::DISCLAIMER,
                    ];

                    $metricsByCategory[$category][$metricKey] = $metricRecord;
                    $this->persistMetric($periodStart, $metricRecord);
                }
            }

            $result[$scope] = [
                'scope' => $scope,
                'categories' => $metricsByCategory,
                'periodStart' => $periodStart,
                'disclaimer' => self::DISCLAIMER,
            ];
        }

        return [
            'scopes' => $result,
            'events' => $events,
            'periodStart' => $periodStart,
            'disclaimer' => self::DISCLAIMER,
            'generatedAt' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function latest(): array
    {
        $scopesData = [];
        foreach (self::SCOPES as $scope) {
            $scopesData[$scope] = [
                'scope' => $scope,
                'categories' => $this->loadLatestGrouped($scope),
                'disclaimer' => self::DISCLAIMER,
            ];
        }

        // Active events for current 6-hour window
        $nowPeriod = $this->currentReportingPeriodStart();
        $seed = abs((int) crc32($nowPeriod));
        $events = $this->activeEvents($nowPeriod, $seed);

        return [
            'scopes' => $scopesData,
            'events' => $events,
            'periodStart' => $nowPeriod,
            'disclaimer' => self::DISCLAIMER,
            'updatedAt' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function historyMetrics(string $scope = 'kenya', ?string $category = null, ?string $metricName = null, int $limit = 40): array
    {
        try {
            $conditions = ['geographic_scope = ?'];
            $params = [$scope];

            if ($category !== null && $category !== 'all') {
                $conditions[] = 'category = ?';
                $params[] = $category;
            }
            if ($metricName !== null && $metricName !== '') {
                $conditions[] = 'metric_name = ?';
                $params[] = $metricName;
            }

            $whereSql = implode(' AND ', $conditions);
            $limit = max(1, min(200, $limit));

            $stmt = $this->db->prepare(
                "SELECT period_start, metric_name, category, geographic_scope, metric_value, previous_value, change_percent, unit, is_simulated
                 FROM synthetic_trend_metrics
                 WHERE {$whereSql}
                 ORDER BY period_start ASC, metric_name ASC
                 LIMIT {$limit}"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by metric_name for simple chart plotting
            $series = [];
            foreach ($rows as $row) {
                $m = $row['metric_name'];
                if (!isset($series[$m])) {
                    $series[$m] = [
                        'metricName' => $m,
                        'category' => $row['category'],
                        'unit' => $row['unit'],
                        'isSimulated' => (bool) $row['is_simulated'],
                        'disclaimer' => self::DISCLAIMER,
                        'points' => [],
                    ];
                }
                $series[$m]['points'][] = [
                    'period' => $row['period_start'],
                    'value' => (float) $row['metric_value'],
                    'change' => (float) $row['change_percent'],
                ];
            }
            return array_values($series);
        } catch (Throwable) {
            return [];
        }
    }

    public function activeEvents(string $periodStart, int $seed): array
    {
        $pool = [
            [
                'id' => 'evt_drought_pressure',
                'name' => 'Seasonal Dry Spell Pressure',
                'description' => 'Simulated dry spell reducing pasture availability and pushing up dairy feed costs.',
                'geographicScope' => 'kenya',
                'affectedIndicators' => ['dairy_feed_cost_index', 'milk_supply_pressure_index', 'hay_cost_index'],
                'multiplier' => 1.035,
                'durationHours' => 48,
                'sourceLabel' => self::EVENT_DISCLAIMER,
            ],
            [
                'id' => 'evt_holiday_demand',
                'name' => 'Urban Holiday Milk & Poultry Demand',
                'description' => 'Simulated seasonal demand surge for fluid milk, eggs and broiler poultry in regional hubs.',
                'geographicScope' => 'east_africa',
                'affectedIndicators' => ['milk_demand_index', 'egg_demand_index', 'chicken_demand_index'],
                'multiplier' => 1.028,
                'durationHours' => 72,
                'sourceLabel' => self::EVENT_DISCLAIMER,
            ],
            [
                'id' => 'evt_fodder_surplus',
                'name' => 'Conserved Fodder & Silage Harvest Surge',
                'description' => 'Simulated abundant Rhodes and Napier grass silage moderating local feed meal costs.',
                'geographicScope' => 'kenya',
                'affectedIndicators' => ['feed_availability_index', 'dairy_meal_cost_index'],
                'multiplier' => 0.975,
                'durationHours' => 48,
                'sourceLabel' => self::EVENT_DISCLAIMER,
            ],
            [
                'id' => 'evt_transport_disruption',
                'name' => 'Cross-Border Transport Corridor Delays',
                'description' => 'Simulated logistics bottlenecks temporarily tightening grain and mineral salt distribution.',
                'geographicScope' => 'east_africa',
                'affectedIndicators' => ['mineral_salt_cost_index', 'poultry_feed_cost_index'],
                'multiplier' => 1.022,
                'durationHours' => 36,
                'sourceLabel' => self::EVENT_DISCLAIMER,
            ],
            [
                'id' => 'evt_global_grain_market',
                'name' => 'Global Feed Grain Price Realignment',
                'description' => 'Simulated international grain market fluctuations affecting commercial feed import parity.',
                'geographicScope' => 'global',
                'affectedIndicators' => ['dairy_feed_cost_index', 'poultry_feed_cost_index', 'simulated_operating_cost_index'],
                'multiplier' => 1.018,
                'durationHours' => 96,
                'sourceLabel' => self::EVENT_DISCLAIMER,
            ],
        ];

        // Pick 1 or 2 deterministic events based on the seed
        $index1 = $seed % count($pool);
        $index2 = ($seed + 2) % count($pool);

        $selected = [$pool[$index1]];
        if ($index1 !== $index2) {
            $selected[] = $pool[$index2];
        }

        foreach ($selected as &$ev) {
            $ev['startPeriod'] = $periodStart;
        }
        return $selected;
    }

    public function currentReportingPeriodStart(): string
    {
        $tz = new DateTimeZone('Africa/Nairobi');
        $now = new DateTimeImmutable('now', $tz);
        $hour = (int) $now->format('G');
        $baseHour = intdiv($hour, 6) * 6;
        $periodStart = $now->setTime($baseHour, 0, 0);
        return $periodStart->format('Y-m-d H:i:s');
    }

    private function calculateVariation(string $metricKey, string $scope, int $seed): float
    {
        // Deterministic pseudo-random variation in range [-0.025, +0.025]
        $hash = crc32("{$metricKey}:{$scope}:{$seed}");
        $val = ($hash % 500) - 250; // -250 to +249
        return (float) ($val / 10000.0); // -0.025 to +0.0249
    }

    private function evaluateEventMultiplier(string $metricKey, string $scope, array $events): float
    {
        $mult = 1.0;
        foreach ($events as $event) {
            if ($event['geographicScope'] === $scope && in_array($metricKey, $event['affectedIndicators'], true)) {
                $mult *= (float) $event['multiplier'];
            }
        }
        return $mult;
    }

    private function loadPreviousMetrics(string $scope): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT metric_name, metric_value FROM synthetic_trend_metrics WHERE geographic_scope = ? ORDER BY period_start DESC LIMIT 60'
            );
            $stmt->execute([$scope]);
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $k = (string) $row['metric_name'];
                if (!isset($results[$k])) {
                    $results[$k] = ['value' => (float) $row['metric_value']];
                }
            }
            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    private function loadLatestGrouped(string $scope): array
    {
        $grouped = [];
        $previous = $this->loadPreviousMetrics($scope);
        foreach ($this->definitions() as $cat => $defs) {
            $grouped[$cat] = [];
            foreach ($defs as $key => $meta) {
                $val = $previous[$key]['value'] ?? $meta['default'];
                $grouped[$cat][$key] = [
                    'metricName' => $key,
                    'label' => $meta['label'],
                    'category' => $cat,
                    'geographicScope' => $scope,
                    'metricValue' => round($val, 2),
                    'previousValue' => round($val, 2),
                    'changePercent' => 0.0,
                    'unit' => $meta['unit'],
                    'isSimulated' => true,
                    'disclaimer' => self::DISCLAIMER,
                ];
            }
        }
        return $grouped;
    }

    private function persistMetric(string $periodStart, array $metric): void
    {
        try {
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $sql = $isSqlite
                ? 'INSERT INTO synthetic_trend_metrics (snapshot_id, period_start, metric_name, category, geographic_scope, metric_value, previous_value, change_percent, unit, is_simulated) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                : 'INSERT INTO synthetic_trend_metrics (snapshot_id, period_start, metric_name, category, geographic_scope, metric_value, previous_value, change_percent, unit, is_simulated) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1)';

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $periodStart,
                $metric['metricName'],
                $metric['category'],
                $metric['geographicScope'],
                $metric['metricValue'],
                $metric['previousValue'],
                $metric['changePercent'],
                $metric['unit'],
            ]);
        } catch (Throwable) {
            // Ignore if table does not exist in testing
        }
    }

    public function definitions(): array
    {
        return [
            self::CATEGORY_DAIRY => [
                'milk_demand_index' => ['label' => 'Milk Demand Index', 'unit' => 'Index', 'default' => 102.5, 'min' => 70.0, 'max' => 150.0],
                'milk_supply_pressure_index' => ['label' => 'Milk Supply Pressure Index', 'unit' => 'Index', 'default' => 99.4, 'min' => 70.0, 'max' => 150.0],
                'indicative_milk_price_kes' => ['label' => 'Indicative Milk Price (KES/L)', 'unit' => 'KES/L', 'default' => 56.50, 'min' => 40.0, 'max' => 85.0],
                'dairy_feed_cost_index' => ['label' => 'Dairy Feed Cost Index', 'unit' => 'Index', 'default' => 104.2, 'min' => 75.0, 'max' => 160.0],
                'dairy_market_activity_index' => ['label' => 'Dairy Market Activity Index', 'unit' => 'Index', 'default' => 101.0, 'min' => 70.0, 'max' => 150.0],
            ],
            self::CATEGORY_POULTRY => [
                'egg_demand_index' => ['label' => 'Egg Demand Index', 'unit' => 'Index', 'default' => 103.0, 'min' => 70.0, 'max' => 150.0],
                'egg_supply_index' => ['label' => 'Egg Supply Index', 'unit' => 'Index', 'default' => 98.7, 'min' => 70.0, 'max' => 150.0],
                'poultry_feed_cost_index' => ['label' => 'Poultry Feed Cost Index', 'unit' => 'Index', 'default' => 106.1, 'min' => 75.0, 'max' => 165.0],
                'poultry_market_activity_index' => ['label' => 'Poultry Market Activity Index', 'unit' => 'Index', 'default' => 102.3, 'min' => 70.0, 'max' => 150.0],
            ],
            self::CATEGORY_LIVESTOCK => [
                'cattle_demand_index' => ['label' => 'Cattle Demand Index', 'unit' => 'Index', 'default' => 101.5, 'min' => 70.0, 'max' => 150.0],
                'goat_demand_index' => ['label' => 'Goat Demand Index', 'unit' => 'Index', 'default' => 104.0, 'min' => 70.0, 'max' => 150.0],
                'rabbit_demand_index' => ['label' => 'Rabbit Demand Index', 'unit' => 'Index', 'default' => 100.2, 'min' => 70.0, 'max' => 150.0],
                'chicken_demand_index' => ['label' => 'Chicken Demand Index', 'unit' => 'Index', 'default' => 105.4, 'min' => 70.0, 'max' => 150.0],
                'sheep_demand_index' => ['label' => 'Sheep Demand Index', 'unit' => 'Index', 'default' => 100.8, 'min' => 70.0, 'max' => 150.0],
            ],
            self::CATEGORY_ANIMAL_FEED => [
                'hay_cost_index' => ['label' => 'Hay Cost Index', 'unit' => 'Index', 'default' => 102.0, 'min' => 70.0, 'max' => 155.0],
                'dairy_meal_cost_index' => ['label' => 'Dairy Meal Cost Index', 'unit' => 'Index', 'default' => 105.5, 'min' => 75.0, 'max' => 160.0],
                'poultry_feed_cost_index' => ['label' => 'Layers & Broiler Feed Index', 'unit' => 'Index', 'default' => 106.8, 'min' => 75.0, 'max' => 165.0],
                'mineral_salt_cost_index' => ['label' => 'Mineral Salt Cost Index', 'unit' => 'Index', 'default' => 101.2, 'min' => 70.0, 'max' => 150.0],
                'feed_availability_index' => ['label' => 'Feed Availability Index', 'unit' => 'Index', 'default' => 97.6, 'min' => 60.0, 'max' => 140.0],
            ],
            self::CATEGORY_FARM_ECONOMY => [
                'simulated_revenue_index' => ['label' => 'Simulated Revenue Index', 'unit' => 'Index', 'default' => 103.4, 'min' => 70.0, 'max' => 150.0],
                'simulated_operating_cost_index' => ['label' => 'Simulated Operating Cost Index', 'unit' => 'Index', 'default' => 104.8, 'min' => 70.0, 'max' => 160.0],
                'simulated_profitability_index' => ['label' => 'Simulated Profitability Index', 'unit' => 'Index', 'default' => 98.7, 'min' => 60.0, 'max' => 140.0],
            ],
            self::CATEGORY_PRODUCTION => [
                'simulated_milk_production_index' => ['label' => 'Simulated Milk Production Index', 'unit' => 'Index', 'default' => 101.8, 'min' => 70.0, 'max' => 150.0],
                'simulated_egg_production_index' => ['label' => 'Simulated Egg Production Index', 'unit' => 'Index', 'default' => 100.5, 'min' => 70.0, 'max' => 150.0],
                'simulated_livestock_production_index' => ['label' => 'Simulated Livestock Production Index', 'unit' => 'Index', 'default' => 102.0, 'min' => 70.0, 'max' => 150.0],
            ],
        ];
    }
}
