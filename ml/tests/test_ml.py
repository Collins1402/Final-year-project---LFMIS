from datetime import date, timedelta

import pytest

from ml.evaluate import baseline_predictions, evaluate_observations, metrics, validate_observations


def observations(days=28):
    start = date(2026, 1, 1)
    return [{"date": (start + timedelta(days=index)).isoformat(), "litres": 20 + index * 0.2, "rain_mm": None, "temp_c": None} for index in range(days)]


def test_validation_rejects_short_history():
    with pytest.raises(ValueError, match="21 consecutive"):
        validate_observations(observations(20))


def test_validation_rejects_gap_and_negative():
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
    values = [10.0 + index for index in range(14)]
    assert baseline_predictions(values, 7, "naive")[0] == 16.0
    assert baseline_predictions(values, 7, "seven_day_mean")[0] == 13.0
    assert baseline_predictions(values, 7, "seasonal_naive")[0] == 10.0


def test_evaluation_returns_model_and_three_baselines():
    results = evaluate_observations(observations())
    assert len(results) == 5
    assert {result["model"] for result in results} >= {"Ridge regression", "Baseline: naive", "Baseline: seven_day_mean", "Baseline: seasonal_naive"}


def test_metrics_are_deterministic_and_non_negative():
    result = metrics([1, 2, 3], [1, 3, 2])
    assert result.mae == pytest.approx(2 / 3)
    assert result.rmse == pytest.approx((2 / 3) ** 0.5)
    assert result.mae >= 0
