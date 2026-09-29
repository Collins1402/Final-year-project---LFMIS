import unittest
from datetime import date, timedelta

from forecast_service import forecast_production


class ForecastProductionTests(unittest.TestCase):
    def setUp(self) -> None:
        start = date(2026, 1, 1)
        self.observations = [
            {"date": (start + timedelta(days=day)).isoformat(), "litres": 100 + day}
            for day in range(28)
        ]

    def test_returns_evaluated_seven_day_forecast(self) -> None:
        result = forecast_production(self.observations)

        self.assertEqual(len(result["forecast"]), 7)
        self.assertEqual(result["trainingDays"], 28)
        self.assertEqual(result["backtestDays"], 7)
        self.assertEqual(result["forecast"][0]["date"], "2026-01-29")
        self.assertGreaterEqual(result["backtestMaeLitres"], 0)

    def test_requires_minimum_history(self) -> None:
        with self.assertRaisesRegex(ValueError, "21 consecutive"):
            forecast_production(self.observations[:20])

    def test_rejects_missing_days(self) -> None:
        self.observations[10]["date"] = "2026-01-12"
        with self.assertRaisesRegex(ValueError, "without gaps"):
            forecast_production(self.observations)


if __name__ == "__main__":
    unittest.main()