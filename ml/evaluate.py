"""Forecast daily milk production with lagged values, weather features, rolling backtests, and simple baselines. The service validates observations, compares Ridge regression against baselines, and reports decision-support forecasts with limitations."""

from __future__ import annotations

from dataclasses import dataclass
from math import sqrt
from datetime import date
from typing import Any
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

REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
REAL_DATA_PATH = REPOSITORY_ROOT / "ml" / "data" / "real_milk.csv"
REPORT_PATH = REPOSITORY_ROOT / "docs" / "ml-evaluation.md"
CSV_FIELDS = ("date", "litres", "rain_mm", "temp_c")


@dataclass(frozen=True)
class Metrics:
    mae: float
    rmse: float
    mape: float | None


def validate_observations(observations: list[dict[str, Any]], minimum_days: int = 21) -> None:
    """Explain the validate observations operation and return its computed result."""
    if len(observations) < minimum_days:
        raise ValueError(f"At least {minimum_days} consecutive recorded production days are required.")
    dates = []
    for item in observations:
        try:
            current = date.fromisoformat(str(item["date"]))
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
    """Explain the metrics operation and return its computed result."""
    errors = np.subtract(actual, predicted)
    non_zero = [abs(value) for value in actual if abs(value) > 1e-9]
    mape = None if not non_zero else float(np.mean([abs(error / value) for error, value in zip(errors, actual) if abs(value) > 1e-9]) * 100)
    return Metrics(float(np.mean(np.abs(errors))), float(sqrt(np.mean(errors ** 2))), mape)


def baseline_predictions(values: list[float], start: int, kind: str) -> list[float]:
    """Explain the baseline predictions operation and return its computed result."""
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
    """Explain the model backtest operation and return its computed result."""
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
    """Explain the evaluate observations operation and return its computed result."""
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


def load_csv(path: Path) -> tuple[list[dict[str, Any]], dict[str, int], int]:
    """Explain the load csv operation and return its computed result."""
    missing = {field: 0 for field in CSV_FIELDS}
    observations: list[dict[str, Any]] = []
    with path.open(newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None or any(field not in reader.fieldnames for field in CSV_FIELDS):
            raise ValueError("CSV must contain date, litres, rain_mm, and temp_c columns.")
        for row in reader:
            observation: dict[str, Any] = {}
            for field in CSV_FIELDS:
                value = (row.get(field) or "").strip()
                if not value:
                    missing[field] += 1
                    observation[field] = None
                elif field in {"litres", "rain_mm", "temp_c"}:
                    try:
                        observation[field] = float(value)
                    except ValueError as error:
                        raise ValueError(f"CSV has a non-numeric {field} value on row {reader.line_num}.") from error
                else:
                    observation[field] = value
            observations.append(observation)
    return observations, missing, len(observations)


def evaluation_report(source: Path, observations: list[dict[str, Any]], missing: dict[str, int], row_count: int) -> str:
    """Explain the evaluation report operation and return its computed result."""
    lines = [
        "# ML Evaluation",
        "",
        f"Data source: `{source.as_posix()}`",
        f"Production rows/days supplied: {row_count}",
        "",
        "| Column | Missing values |",
        "|---|---:|",
    ]
    lines.extend(f"| {field} | {missing[field]} |" for field in CSV_FIELDS)
    results: list[dict[str, Any]] = []
    status = ""
    try:
        validate_observations(observations)
        results = evaluate_observations(observations)
        status = f"Evaluation computed from {row_count} consecutive production days. Metrics describe this dataset only."
    except ValueError as error:
        if row_count < 21:
            status = f"Cannot be reported as farm accuracy: only {row_count} production day(s) are available; at least 21 consecutive days are required."
        else:
            status = f"Cannot be reported as farm accuracy: the supplied history is invalid for evaluation ({error})."
    lines.extend(["", f"Status: {status}", ""])
    if results:
        lines.extend([
            "| Model | Features | Backtest days | MAE (L) | RMSE (L) |",
            "|---|---|---:|---:|---:|",
        ])
        for result in results:
            lines.append(
                f"| {result['model']} | {result['features']} | {result['days']} | "
                f"{result['maeLitres']:.3f} | {result['rmseLitres']:.3f} |"
            )
    else:
        lines.extend([
            "| Model | Features | Backtest days | MAE (L) | RMSE (L) |",
            "|---|---|---:|---:|---:|",
            "| Ridge regression | 7 lags + day-of-week + weather | - | Not computed | Not computed |",
            "| Baseline: naive | Previous day | - | Not computed | Not computed |",
            "| Baseline: seven-day mean | Previous seven-day mean | - | Not computed | Not computed |",
            "| Baseline: seasonal naive | Same weekday last week | - | Not computed | Not computed |",
        ])
    lines.extend([
        "",
        "Limits: this model is decision support, not a causal explanation or guarantee of future yield. Results from one farm may not generalize to other farms or seasons. The weather-enabled ridge model uses observed weather where available and historical means for missing weather; association does not establish that weather caused a production change. Rows must represent complete, consecutive calendar days for the backtest to run.",
        "",
    ])
    return "\n".join(lines)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--csv", default=REAL_DATA_PATH, type=Path)
    parser.add_argument("--output", default=REPORT_PATH, type=Path)
    args = parser.parse_args()
    data_path = args.csv.resolve()
    try:
        observations, missing, row_count = load_csv(data_path)
    except FileNotFoundError:
        observations, missing, row_count = [], {field: 0 for field in CSV_FIELDS}, 0
    report = evaluation_report(data_path.relative_to(REPOSITORY_ROOT) if data_path.is_relative_to(REPOSITORY_ROOT) else data_path, observations, missing, row_count)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(report, encoding="utf-8")
    try:
        results = evaluate_observations(observations)
        status = "evaluated"
    except ValueError:
        results = []
        status = "insufficient_or_invalid_data"
    print(json.dumps({"status": status, "days": row_count, "missingValues": missing, "results": results, "report": str(args.output)}, indent=2))
