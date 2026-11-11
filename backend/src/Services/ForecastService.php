<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use DateTimeImmutable;
use Lfmis\Core\Env;
use PDO;

final class ForecastService
{
    private ?Closure $httpRequest;

    public function __construct(private PDO $db, ?Closure $httpRequest = null)
    {
        $this->httpRequest = $httpRequest;
    }

    public function trainingData(): array
    {
        return $this->trainingDataFor('milk');
    }

    public function scenario(int $additionalCows, int $additionalHens): array
    {
        if ($additionalCows < 0 || $additionalCows > 250 || $additionalHens < 0 || $additionalHens > 250) {
            throw new \InvalidArgumentException('Additional animal counts must be between 0 and 250.');
        }
        $animalCounts = [];
        foreach ($this->db->query("SELECT LOWER(species) AS species, COUNT(*) AS count FROM animals WHERE status = 'active' GROUP BY LOWER(species)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $animalCounts[$row['species']] = (int) $row['count'];
        }
        $results = [];
        foreach ([
            'milk' => ['species' => 'cattle', 'additional' => $additionalCows],
            'eggs' => ['species' => 'chicken', 'additional' => $additionalHens],
        ] as $product => $plan) {
            $history = $this->trainingDataFor($product);
            $animalCount = $animalCounts[$plan['species']] ?? 0;
            $result = [
                'product' => $product,
                'unit' => $history['unit'],
                'recordingDays' => count($history['observations']),
                'activeAnimals' => $animalCount,
                'additionalAnimals' => $plan['additional'],
                'includesSimulatedData' => $history['includesSimulatedData'],
                'status' => 'insufficient_data',
            ];
            if (count($history['observations']) >= 21) {
                $forecast = $this->post('/forecast', ['observations' => $history['observations'], 'horizon' => 30]);
                $baseline = array_sum(array_map(static fn(array $item): float => (float) ($item['litres'] ?? 0), $forecast['forecast'] ?? []));
                $result['baselineTotal'] = round($baseline, 2);
                if ($animalCount > 0) {
                    $result['estimatedTotal'] = round($baseline * (($animalCount + $plan['additional']) / $animalCount), 2);
                    $result['change'] = round($result['estimatedTotal'] - $baseline, 2);
                    $result['status'] = 'ready';
                } else {
                    $result['status'] = 'no_active_animals';
                }
            }
            $results[] = $result;
        }
        $activeAnimalCount = array_sum($animalCounts);
        $additionalAnimalCount = $additionalCows + $additionalHens;
        $feedUsageQuery = $this->db->prepare(
            "SELECT i.id, i.name, i.unit, COALESCE(SUM(CASE WHEN t.transaction_type = 'out' THEN ABS(t.quantity_change) ELSE 0 END), 0) AS recent_use
             FROM inventory_items i LEFT JOIN inventory_transactions t ON t.item_id = i.id AND t.transaction_date >= ? AND t.transaction_date < ?
             WHERE i.category = 'feed' AND i.is_active = 1 GROUP BY i.id, i.name, i.unit ORDER BY i.name"
        );
        $feedUsageQuery->execute([date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('tomorrow'))]);
        $feedUse = [];
        foreach ($feedUsageQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $recentUse = (float) $item['recent_use'];
            $incrementalUse = $activeAnimalCount > 0 ? $recentUse * $additionalAnimalCount / $activeAnimalCount : 0.0;
            $feedUse[] = [
                'item' => $item['name'],
                'unit' => $item['unit'],
                'recordedUse30Days' => round($recentUse, 2),
                'estimatedUseWithAddedAnimals' => round($recentUse + $incrementalUse, 2),
                'status' => $recentUse > 0 ? 'ready' : 'no_recent_usage',
            ];
        }
        return [
            'horizonDays' => 30,
            'scenarios' => $results,
            'feedUse' => $feedUse,
            'limitations' => 'Yield estimates scale recorded production in proportion to active animals and assume added animals perform like the current group. Feed-use estimates scale the latest 30 days of recorded feed stock-outs by total animal count; stock movements may not equal feed consumed. Neither estimate models health, weather or management effects, and feed changes are not assumed to cause a yield change.',
        ];
    }

    private function trainingDataFor(string $produceName): array
    {
        $unitsByProduct = [
            'milk' => ['litre', 'litres', 'l'],
            'eggs' => ['tray', 'trays', 'egg', 'eggs'],
        ];
        if (!isset($unitsByProduct[$produceName])) {
            throw new \InvalidArgumentException('Unsupported produce type.');
        }
        $unitQuery = $this->db->prepare('SELECT unit FROM farm_produce WHERE LOWER(produce_name) = ? ORDER BY production_date DESC LIMIT 1');
        $unitQuery->execute([$produceName]);
        $unit = strtolower((string) $unitQuery->fetchColumn());
        if (!in_array($unit, $unitsByProduct[$produceName], true)) {
            return ['observations' => [], 'includesSimulatedData' => false, 'unit' => null];
        }
        $statement = $this->db->prepare(
            "SELECT production_date AS date, SUM(quantity_produced) AS litres,
                    MAX(CASE WHEN notes = 'SIMULATED DATA' THEN 1 ELSE 0 END) AS simulated
             FROM farm_produce
             WHERE LOWER(produce_name) = ? AND LOWER(unit) = ?
               AND production_date < ?
             GROUP BY production_date
             ORDER BY production_date DESC
             LIMIT 365"
        );
        $statement->execute([$produceName, $unit, date('Y-m-d')]);
        $rows = array_reverse($statement->fetchAll());
        $observations = [];
        $includesSimulatedData = false;
        $previousDate = null;
        foreach ($rows as $row) {
            $currentDate = new DateTimeImmutable((string) $row['date']);
            if ($previousDate !== null && $previousDate->modify('+1 day')->format('Y-m-d') !== $currentDate->format('Y-m-d')) {
                $observations = [];
                $includesSimulatedData = false;
            }
            $observations[] = [
                'date' => $currentDate->format('Y-m-d'),
                'litres' => (float) $row['litres'],
                'rain_mm' => null,
                'temp_c' => null,
            ];
            $includesSimulatedData = $includesSimulatedData || (int) $row['simulated'] === 1;
            $previousDate = $currentDate;
        }

        if ($observations !== []) {
            $weather = $this->db->prepare('SELECT observation_date, rain_mm, temp_c FROM weather_observations WHERE observation_date BETWEEN ? AND ?');
            $weather->execute([$observations[0]['date'], $observations[count($observations) - 1]['date']]);
            $weatherByDate = [];
            foreach ($weather->fetchAll() as $row) {
                $weatherByDate[$row['observation_date']] = $row;
            }
            foreach ($observations as &$observation) {
                $weatherRow = $weatherByDate[$observation['date']] ?? null;
                if ($weatherRow !== null) {
                    $observation['rain_mm'] = $weatherRow['rain_mm'] === null ? null : (float) $weatherRow['rain_mm'];
                    $observation['temp_c'] = $weatherRow['temp_c'] === null ? null : (float) $weatherRow['temp_c'];
                }
            }
            unset($observation);
        }

        return ['observations' => $observations, 'includesSimulatedData' => $includesSimulatedData, 'unit' => $unit];
    }

    public function report(int $userId): array
    {
        $trainingData = $this->trainingData();
        $observations = $trainingData['observations'];
        if (count($observations) < 21) {
            throw new \InvalidArgumentException(sprintf(
                'At least 21 consecutive recorded production days are required; %d are available.',
                count($observations)
            ));
        }

        $forecast = $this->post('/forecast', ['observations' => $observations, 'horizon' => 7]);
        $insights = $this->post('/insights', ['observations' => $observations, 'signals' => $this->signals()]);
        $this->storeBacktest($forecast, $userId, $trainingData['includesSimulatedData']);

        return [
            'observations' => $observations,
            'forecast' => $forecast,
            'insights' => $insights,
            'includesSimulatedData' => $trainingData['includesSimulatedData'],
        ];
    }

    public function history(): array
    {
        return $this->db->query(
            'SELECT id, method, training_days, backtest_days, mae_litres, rmse_litres,
                    baseline_mae_litres, uses_weather, notes, requested_by, created_at
             FROM forecast_runs ORDER BY created_at DESC, id DESC LIMIT 100'
        )->fetchAll();
    }

    private function signals(): array
    {
        $openTasks = (int) $this->db->query("SELECT COUNT(*) FROM farm_activities WHERE status = 'planned'")->fetchColumn();
        $stockAlerts = (int) $this->db->query('SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity_on_hand <= reorder_level')->fetchColumn();
        $dueThrough = date('Y-m-d', strtotime('+7 days'));
        $health = $this->db->prepare(
            'SELECT COUNT(*) FROM health_records hr JOIN animals a ON a.id = hr.animal_id
             WHERE ' . HealthService::OPEN_FOLLOW_UP . ' AND hr.next_due_date IS NOT NULL AND hr.next_due_date <= ?'
        );
        $health->execute([$dueThrough]);

        return ['openTasks' => $openTasks, 'stockAlerts' => $stockAlerts, 'healthDue' => (int) $health->fetchColumn()];
    }

    private function post(string $path, array $payload): array
    {
        $url = rtrim(Env::get('LFMIS_ML_URL', 'http://127.0.0.1:5000'), '/') . $path;
        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, $payload);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new ForecastUnavailableException('Milk forecasting is temporarily unavailable. Start the Python forecast service and try again. ' . $error);
        }
        $data = json_decode($response, true);
        if ($status === 422 && is_array($data) && isset($data['error'])) {
            throw new \InvalidArgumentException((string) $data['error']);
        }
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new ForecastUnavailableException('Milk forecasting is temporarily unavailable. Start the Python forecast service and try again.');
        }
        return $data;
    }

    private function storeBacktest(array $result, int $userId, bool $includesSimulatedData): void
    {
        $backtest = $result['backtest'] ?? [];
        $method = (string) ($result['method'] ?? '');
        $trainingDays = (int) ($result['trainingDays'] ?? 0);
        $backtestDays = (int) ($backtest['days'] ?? 0);
        $mae = (float) ($backtest['maeLitres'] ?? 0);
        $rmse = (float) ($backtest['rmseLitres'] ?? 0);
        $baselineMae = (float) ($backtest['baselineMaeLitres'] ?? 0);
        $startOfDay = date('Y-m-d 00:00:00');
        $startOfNextDay = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $existing = $this->db->prepare(
            'SELECT COUNT(*) FROM forecast_runs
             WHERE created_at >= ? AND created_at < ? AND method = ? AND training_days = ?
               AND backtest_days = ? AND mae_litres = ? AND rmse_litres = ?
               AND baseline_mae_litres = ? AND uses_weather = 1'
        );
        $existing->execute([$startOfDay, $startOfNextDay, $method, $trainingDays, $backtestDays, $mae, $rmse, $baselineMae]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO forecast_runs (method, training_days, backtest_days, mae_litres, rmse_litres,
                                        baseline_mae_litres, uses_weather, notes, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $insert->execute([
            $method,
            $trainingDays,
            $backtestDays,
            $mae,
            $rmse,
            $baselineMae,
            $includesSimulatedData ? 'Training data includes simulated demo records.' : null,
            $userId,
        ]);
    }
}