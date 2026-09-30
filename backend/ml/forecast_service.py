import json
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any

from sklearn.linear_model import LinearRegression


def predictive_checks(
    observations: list[dict[str, Any]], signals: dict[str, Any] | None = None
) -> dict[str, Any]:
    signals = signals or {}
    parsed = sorted(
        ({"date": date.fromisoformat(str(item["date"])), "litres": float(item["litres"])} for item in observations),
        key=lambda item: item["date"],
    )
    checks: list[dict[str, str]] = []
    if not parsed:
        checks.append({
            "priority": "high",
            "title": "Production records are missing",
            "detail": "There is no milk history for the model to inspect.",
            "action": "Record daily production before relying on predictions.",
        })
        return {"checks": checks, "trend": [], "summary": "More production records are needed."}

    values = [item["litres"] for item in parsed]
    dates = [item["date"] for item in parsed]
    gaps = any(current - previous != timedelta(days=1) for previous, current in zip(dates, dates[1:]))
    if gaps:
        checks.append({
            "priority": "high",
            "title": "Check missing production days",
            "detail": "The recent history contains calendar gaps, so trend confidence is reduced.",
            "action": "Review the Production page and fill genuine records; do not enter zero unless nothing was produced.",
        })

    if len(values) >= 14:
        previous_average = sum(values[-14:-7]) / 7
        recent_average = sum(values[-7:]) / 7
        change = ((recent_average - previous_average) / previous_average * 100) if previous_average else 0
        if change <= -15:
            checks.append({
                "priority": "high",
                "title": "Investigate the milk production drop",
                "detail": f"The latest seven-day average is {abs(change):.1f}% below the previous seven-day average.",
                "action": "Check animal health, feed, water, milking equipment, and record accuracy.",
            })
        elif change >= 15:
            checks.append({
                "priority": "low",
                "title": "Production is trending upward",
                "detail": f"The latest seven-day average is {change:.1f}% above the previous week.",
                "action": "Verify the increase is supported by complete records and adequate storage capacity.",
            })
        else:
            checks.append({
                "priority": "low",
                "title": "Production is broadly stable",
                "detail": f"The latest seven-day average changed by {change:.1f}%.",
                "action": "Continue daily recording and review the trend weekly.",
            })

    if len(values) < 21:
        checks.append({
            "priority": "medium",
            "title": "Build more history",
            "detail": f"Only {len(values)} production days are available; the forecast needs 21 consecutive days.",
            "action": "Keep recording daily production to improve prediction confidence.",
        })

    open_tasks = int(signals.get("openTasks", 0) or 0)
    if open_tasks:
        checks.append({
            "priority": "medium",
            "title": "Review planned farm activities",
            "detail": f"There are {open_tasks} planned activities needing attention.",
            "action": "Open Activities and confirm what should be completed today.",
        })
    stock_alerts = int(signals.get("stockAlerts", 0) or 0)
    if stock_alerts:
        checks.append({
            "priority": "high",
            "title": "Check low inventory",
            "detail": f"{stock_alerts} inventory item(s) are at or below their reorder level.",
            "action": "Review Inventory and arrange replenishment before supplies run out.",
        })
    health_due = int(signals.get("healthDue", 0) or 0)
    if health_due:
        checks.append({
            "priority": "high",
            "title": "Review animal health follow-ups",
            "detail": f"{health_due} health record(s) have a follow-up due today or earlier.",
            "action": "Open Animal health and arrange the required veterinary follow-up.",
        })

    if not checks:
        checks.append({
            "priority": "low",
            "title": "No immediate checks detected",
            "detail": "The available records do not show a strong warning signal.",
            "action": "Continue recording complete daily data.",
        })

    trend = [{"date": item["date"].isoformat(), "litres": round(item["litres"], 2)} for item in parsed[-14:]]
    return {
        "checks": checks,
        "trend": trend,
        "summary": f"{len(checks)} checks generated from {len(values)} milk production day(s).",
    }


def forecast_production(observations: list[dict[str, Any]]) -> dict[str, Any]:
    if len(observations) < 21:
        raise ValueError("At least 21 consecutive recorded production days are required.")

    daily_values: list[float] = []
    dates: list[date] = []
    for observation in observations:
        dates.append(date.fromisoformat(str(observation["date"])))
        value = float(observation["litres"])
        if value < 0:
            raise ValueError("Production values cannot be negative.")
        daily_values.append(value)

    if dates != sorted(dates) or any(
        current - previous != timedelta(days=1)
        for previous, current in zip(dates, dates[1:])
    ):
        raise ValueError("Production records must cover consecutive calendar days without gaps.")

    def features(values: list[float], target_index: int) -> list[float]:
        return [float(target_index), *values[target_index - 7:target_index][::-1]]

    holdout_start = len(daily_values) - 7
    training_x = [features(daily_values, index) for index in range(7, holdout_start)]
    training_y = daily_values[7:holdout_start]
    if not training_x:
        raise ValueError("There is not enough history to train and evaluate this forecast.")

    evaluation_model = LinearRegression().fit(training_x, training_y)
    evaluation_values = daily_values[:holdout_start]
    predictions: list[float] = []
    for index in range(holdout_start, len(daily_values)):
        prediction = max(0.0, float(evaluation_model.predict([features(evaluation_values, index)])[0]))
        predictions.append(prediction)
        evaluation_values.append(daily_values[index])

    actual_values = daily_values[holdout_start:]
    mae = sum(abs(actual - predicted) for actual, predicted in zip(actual_values, predictions)) / len(actual_values)

    final_x = [features(daily_values, index) for index in range(7, len(daily_values))]
    final_y = daily_values[7:]
    model = LinearRegression().fit(final_x, final_y)
    forecast_values: list[float] = []
    forecast_history = daily_values.copy()
    for index in range(len(daily_values), len(daily_values) + 7):
        prediction = max(0.0, float(model.predict([features(forecast_history, index)])[0]))
        forecast_values.append(round(prediction, 2))
        forecast_history.append(prediction)

    return {
        "method": "Linear regression with seven-day lag features",
        "trainingDays": len(daily_values),
        "backtestDays": len(actual_values),
        "backtestMaeLitres": round(mae, 2),
        "forecast": [
            {"date": (dates[-1] + timedelta(days=step)).isoformat(), "litres": litres}
            for step, litres in enumerate(forecast_values, start=1)
        ],
        "limitations": "Decision support only. Forecast quality depends on complete, representative records; it is not veterinary advice.",
    }


class ForecastHandler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:
        if self.path not in ("/forecast", "/insights"):
            self.send_error(404)
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 1_000_000:
                raise ValueError("Request body must be between 1 byte and 1 MB.")
            payload = json.loads(self.rfile.read(length))
            if self.path == "/insights":
                result = predictive_checks(payload.get("observations", []), payload.get("signals", {}))
            else:
                result = forecast_production(payload.get("observations", []))
            self._respond(200, result)
        except (ValueError, KeyError, TypeError, json.JSONDecodeError) as error:
            self._respond(422, {"error": str(error)})

    def _respond(self, status: int, payload: dict[str, Any]) -> None:
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, format: str, *args: Any) -> None:
        return


if __name__ == "__main__":
    server = HTTPServer(("127.0.0.1", 5000), ForecastHandler)
    print("LFMIS production forecast listening on http://127.0.0.1:5000")
    server.serve_forever()