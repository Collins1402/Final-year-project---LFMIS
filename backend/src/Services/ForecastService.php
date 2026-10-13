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
        $statement = $this->db->prepare(
            "SELECT production_date AS date, SUM(quantity_produced) AS litres,
                    MAX(CASE WHEN notes = 'SIMULATED DATA' THEN 1 ELSE 0 END) AS simulated
             FROM farm_produce
             WHERE LOWER(produce_name) = 'milk'
               AND LOWER(unit) IN ('litre', 'litres', 'l')
               AND production_date < ?
             GROUP BY production_date
             ORDER BY production_date DESC
             LIMIT 365"
        );
        $statement->execute([date('Y-m-d')]);
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

        return ['observations' => $observations, 'includesSimulatedData' => $includesSimulatedData];
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