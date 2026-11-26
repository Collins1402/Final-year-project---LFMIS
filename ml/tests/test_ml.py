"""Proves the ML forecasting, feature, evaluation, and import behavior covered by this test module."""
from datetime import date, timedelta

import pytest

from ml.evaluate import baseline_predictions, evaluate_observations, evaluation_report, load_csv, metrics, validate_observations
from ml.forecast_service import forecast


def observations(days=28):
    """Runs the observations step and returns the result used by this module.
    :param days: Input used in this calculation.
    :return: The computed result or requested operation outcome.
    """
    start = date(2026, 1, 1)
    return [{"date": (start + timedelta(days=index)).isoformat(), "litres": 20 + index * 0.2, "rain_mm": None, "temp_c": None} for index in range(days)]


def test_validation_rejects_short_history():
    """Runs the test validation rejects short history step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    with pytest.raises(ValueError, match="21 consecutive"):
        validate_observations(observations(20))


def test_validation_rejects_gap_and_negative():
    """Runs the test validation rejects gap and negative step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    values = observations()
    values[5]["date"] = "2026-01-08"
    with pytest.raises(ValueError, match="sorted"):
        validate_observations(values)
    values = observations()
    values[5]["date"] = "2026-01-07"
    with pytest.raises(ValueError, match="consecutive"):
        validate_observations(values)
    values = observations()
    values[0]["litres"] = -1
    with pytest.raises(ValueError, match="negative"):
        validate_observations(values)


def test_baselines_have_expected_values():
    """Runs the test baselines have expected values step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    values = [10.0 + index for index in range(14)]
    assert baseline_predictions(values, 7, "naive")[0] == 16.0
    assert baseline_predictions(values, 7, "seven_day_mean")[0] == 13.0
    assert baseline_predictions(values, 7, "seasonal_naive")[0] == 10.0


def test_evaluation_returns_model_and_three_baselines():
    """Runs the test evaluation returns model and three baselines step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    results = evaluate_observations(observations())
    assert len(results) == 5
    assert {result["model"] for result in results} >= {"Ridge regression", "Baseline: naive", "Baseline: seven_day_mean", "Baseline: seasonal_naive"}


def test_forecast_uses_best_baseline_and_returns_evaluation():
    """Runs the test forecast uses best baseline and returns evaluation step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    result = forecast(observations())
    best_baseline = min(
        (item for item in result["evaluation"] if item["model"].startswith("Baseline: ")),
        key=lambda item: item["maeLitres"],
    )

    assert result["backtest"]["baselineName"] == best_baseline["model"].removeprefix("Baseline: ")
    assert result["backtest"]["baselineMaeLitres"] == best_baseline["maeLitres"]
    assert len(result["evaluation"]) == 5


def test_metrics_are_deterministic_and_non_negative():
    """Runs the test metrics are deterministic and non negative step and returns the result used by this module.
    :return: The computed result or requested operation outcome.
    """
    result = metrics([1, 2, 3], [1, 3, 2])
    assert result.mae == pytest.approx(2 / 3)
    assert result.rmse == pytest.approx((2 / 3) ** 0.5)
    assert result.mae >= 0


def test_real_csv_loader_counts_missing_values_and_evaluation_reports_short_data(tmp_path):
    """Runs the test real csv loader counts missing values and evaluation reports short data step and returns the result used by this module.
    :param tmp_path: Input used in this calculation.
    :return: The computed result or requested operation outcome.
    """
    source = tmp_path / "real_milk.csv"
    source.write_text("date,litres,rain_mm,temp_c\n2026-01-01,18,,\n", encoding="utf-8")

    values, missing, row_count = load_csv(source)
    report = evaluation_report(source, values, missing, row_count)

    assert row_count == 1
    assert missing == {"date": 0, "litres": 0, "rain_mm": 1, "temp_c": 1}
    assert "Cannot be reported as farm accuracy" in report
    assert "only 1 production day(s)" in report
    assert "Baseline: seasonal naive" in report
    assert "Not computed" in report


def test_real_csv_evaluation_includes_ridge_and_each_baseline(tmp_path):
    """Runs the test real csv evaluation includes ridge and each baseline step and returns the result used by this module.
    :param tmp_path: Input used in this calculation.
    :return: The computed result or requested operation outcome.
    """
    source = tmp_path / "real_milk.csv"
    source.write_text(
        "date,litres,rain_mm,temp_c\n"
        + "".join(f"{item['date']},{item['litres']},,\n" for item in observations(28)),
        encoding="utf-8",
    )

    values, missing, row_count = load_csv(source)
    report = evaluation_report(source, values, missing, row_count)

    assert "Evaluation computed from 28 consecutive production days" in report
    assert "| Ridge regression |" in report
    assert "| Baseline: naive |" in report
    assert "| Baseline: seven_day_mean |" in report
    assert "| Baseline: seasonal_naive |" in report
