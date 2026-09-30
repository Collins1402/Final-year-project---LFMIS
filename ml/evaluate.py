from __future__ import annotations

from dataclasses import dataclass
from math import sqrt
from typing import Any, Callable
import argparse
import csv
import json
from pathlib import Path

import numpy as np
from sklearn.linear_model import Ridge

try:
    from .features import build_features, weather_means
except ImportError:
    from features import build_features, weather_means


@dataclass(frozen=True)
class Metrics:
    mae: float
    rmse: float
    mape: float | None


def validate_observations(observations: list[dict[str, Any]], minimum_days: int = 21) -> None:
    if len(observations) < minimum_days:
        raise ValueError(f"At least {minimum_days} consecutive recorded production days are required.")
    dates = []
    for item in observations:
        try:
            current = __import__("datetime").date.fromisoformat(str(item["date"]))
            litres = float(item["litres"])
        except (KeyError, TypeError, ValueError) as error:
            raise ValueError("Each observation needs a valid date and litres value.") from error
        if litres < 0:
            raise ValueError("Production values cannot be negative.")
        dates.append(current)
    if dates != sorted(dates):
        raise ValueError("Production records must be sorted by date.")
    if len(set(dates)) != len(dates) or any((right - left).days != 1 for left, right in zip(dates, dates[1:])):
        raise ValueError("Production records must cover consecutive calendar days without gaps.")


def metrics(actual: list[float], predicted: list[float]) -> Metrics:
    errors = np.subtract(actual, predicted)
    non_zero = [abs(value) for value in actual if abs(value) > 1e-9]
    mape = None if not non_zero else float(np.mean([abs(error / value) for error, value in zip(errors, actual) if abs(value) > 1e-9]) * 100)
    return Metrics(float(np.mean(np.abs(errors))), float(sqrt(np.mean(errors ** 2))), mape)


def baseline_predictions(values: list[float], start: int, kind: str) -> list[float]:
    predictions: list[float] = []
    for index in range(start, len(values)):
        if kind == "naive":
            predictions.append(values[index - 1])
        elif kind == "seven_day_mean":
            predictions.append(float(np.mean(values[index - 7:index])))
        elif kind == "seasonal_naive":
            predictions.append(values[index - 7])
        else:
            raise ValueError(f"Unknown baseline: {kind}")
    return predictions


def model_backtest(observations: list[dict[str, Any]], use_weather: bool) -> tuple[Metrics, int]:
    values = [float(item["litres"]) for item in observations]
    start = max(14, 7)
    predictions: list[float] = []
    actual: list[float] = []
    for index in range(start, len(values)):
        train_end = index
        rain_mean, temp_mean = weather_means(observations, train_end)
        x_train = [build_features(observations, row, use_weather, rain_mean, temp_mean) for row in range(7, train_end)]
        y_train = values[7:train_end]
        model = Ridge(alpha=1.0).fit(x_train, y_train)
        prediction = float(model.predict([build_features(observations, index, use_weather, rain_mean, temp_mean)])[0])
        predictions.append(max(0.0, prediction))
        actual.append(values[index])
    return metrics(actual, predictions), len(actual)


def evaluate_observations(observations: list[dict[str, Any]]) -> list[dict[str, Any]]:
    validate_observations(observations)
    values = [float(item["litres"]) for item in observations]
    start = 14
    results: list[dict[str, Any]] = []
    baseline_metrics = {kind: metrics(values[start:], baseline_predictions(values, start, kind)) for kind in ("naive", "seven_day_mean", "seasonal_naive")}
    best_baseline = min(baseline_metrics.values(), key=lambda item: item.mae)
    for label, use_weather in (("without weather", False), ("with weather", True)):
        result, days = model_backtest(observations, use_weather)
        results.append({
            "model": "Ridge regression",
            "features": f"7 lags + day-of-week + {label}",
            "days": days,
            "maeLitres": round(result.mae, 3),
            "rmseLitres": round(result.rmse, 3),
            "mapePercent": None if result.mape is None else round(result.mape, 2),
            "bestBaselineMaeLitres": round(best_baseline.mae, 3),
            "improvementPct": round((best_baseline.mae - result.mae) / best_baseline.mae * 100, 2) if best_baseline.mae else 0.0,
        })
    results.extend({"model": f"Baseline: {kind}", "features": "lagged production only", "days": len(values) - start, "maeLitres": round(result.mae, 3), "rmseLitres": round(result.rmse, 3), "mapePercent": None if result.mape is None else round(result.mape, 2)} for kind, result in baseline_metrics.items())
    return results


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--csv", required=True, type=Path)
    args = parser.parse_args()
    with args.csv.open(newline="", encoding="utf-8") as handle:
        observations = [
            {
                key: None if value == "" else float(value) if key in {"litres", "rain_mm", "temp_c"} else value
                for key, value in row.items()
            }
            for row in csv.DictReader(handle)
        ]
    print(json.dumps(evaluate_observations(observations), indent=2))
