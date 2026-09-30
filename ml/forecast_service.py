from __future__ import annotations

import json
import os
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any

import numpy as np
from sklearn.linear_model import Ridge

try:
    from .evaluate import evaluate_observations, metrics, validate_observations
    from .features import build_features, weather_means
except ImportError:
    from evaluate import evaluate_observations, metrics, validate_observations
    from features import build_features, weather_means


def forecast(observations: list[dict[str, Any]], horizon: int = 7) -> dict[str, Any]:
    validate_observations(observations)
    if horizon < 1 or horizon > 30:
        raise ValueError("horizon must be between 1 and 30 days.")
    history = [dict(item) for item in observations]
    values = [float(item["litres"]) for item in history]
    x_train = [build_features(history, index, True, *weather_means(history, len(history))) for index in range(7, len(history))]
    model = Ridge(alpha=1.0).fit(x_train, values[7:])
    evaluation = evaluate_observations(observations)
    weather_result = next(result for result in evaluation if result["features"].endswith("with weather"))
    baseline = next(result for result in evaluation if result["model"] == "Baseline: seven_day_mean")
    forecast_values: list[dict[str, Any]] = []
    last_date = date.fromisoformat(str(history[-1]["date"]))
    for step in range(1, horizon + 1):
        future_date = last_date + timedelta(days=step)
        history.append({"date": future_date.isoformat(), "litres": values[-1], "rain_mm": None, "temp_c": None})
        rain_mean, temp_mean = weather_means(history, len(history) - 1)
        prediction = max(0.0, float(model.predict([build_features(history, len(history) - 1, True, rain_mean, temp_mean)])[0]))
        history[-1]["litres"] = prediction
        values.append(prediction)
        forecast_values.append({"date": future_date.isoformat(), "litres": round(prediction, 2)})
    return {
        "method": "Ridge regression, 7-day lags + weather",
        "trainingDays": len(observations),
        "backtest": {
            "days": weather_result["days"],
            "maeLitres": weather_result["maeLitres"],
            "rmseLitres": weather_result["rmseLitres"],
            "baselineMaeLitres": baseline["maeLitres"],
            "improvementPct": weather_result["improvementPct"],
        },
        "forecast": forecast_values,
        "limitations": "Decision support only. Weather is associated with production in this model; this does not establish causation.",
    }


def insights(observations: list[dict[str, Any]], signals: dict[str, Any] | None = None) -> dict[str, Any]:
    signals = signals or {}
    checks: list[dict[str, str]] = []
    try:
        validate_observations(observations, minimum_days=1)
    except ValueError as error:
        checks.append({"priority": "high", "title": "Build production history", "detail": str(error), "action": "Record complete daily production."})
        return {"checks": checks, "summary": "More production records are needed."}
    values = [float(item["litres"]) for item in observations]
    dates = [date.fromisoformat(str(item["date"])) for item in observations]
    if len(values) >= 14:
        previous = float(np.mean(values[-14:-7]))
        recent = float(np.mean(values[-7:]))
        change = 0.0 if previous == 0 else (recent - previous) / previous * 100
        title = "Production is trending upward" if change >= 15 else "Investigate the milk production drop" if change <= -15 else "Production is broadly stable"
        priority = "high" if change <= -15 else "low"
        checks.append({"priority": priority, "title": title, "detail": f"The latest seven-day average changed by {change:.1f}%.", "action": "Review animal health, feed, water and record accuracy."})
    if len(values) < 21:
        checks.append({"priority": "medium", "title": "Build more history", "detail": f"Only {len(values)} production days are available; the forecast needs 21 consecutive days.", "action": "Keep recording daily production."})
    for key, title, priority in (("openTasks", "Review planned farm activities", "medium"), ("stockAlerts", "Check low inventory", "high"), ("healthDue", "Review animal health follow-ups", "high")):
        count = int(signals.get(key, 0) or 0)
        if count:
            checks.append({"priority": priority, "title": title, "detail": f"There are {count} item(s) needing attention.", "action": "Open the relevant farm section and follow up."})
    return {"checks": checks or [{"priority": "low", "title": "No immediate checks detected", "detail": "The available records show no strong warning signal.", "action": "Continue recording complete daily data."}], "trend": [{"date": item["date"], "litres": round(float(item["litres"]), 2)} for item in observations[-14:]], "summary": f"{len(checks)} checks generated from {len(values)} production day(s)."}


class ForecastHandler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path == "/health":
            self.respond(200, {"status": "ok"})
            return
        self.respond(404, {"error": "Endpoint not found."})

    def do_POST(self) -> None:
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 1_000_000:
                raise ValueError("Request body must be between 1 byte and 1 MB.")
            payload = json.loads(self.rfile.read(length))
            if self.path == "/forecast":
                result = forecast(payload.get("observations", []), int(payload.get("horizon", 7)))
            elif self.path == "/insights":
                result = insights(payload.get("observations", []), payload.get("signals", {}))
            elif self.path == "/evaluate":
                result = {"results": evaluate_observations(payload.get("observations", []))}
            else:
                self.respond(404, {"error": "Endpoint not found."})
                return
            self.respond(200, result)
        except (ValueError, KeyError, TypeError, json.JSONDecodeError) as error:
            self.respond(422, {"error": str(error)})

    def respond(self, status: int, payload: dict[str, Any]) -> None:
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, format: str, *args: Any) -> None:
        return


if __name__ == "__main__":
    port = int(os.getenv("LFMIS_ML_PORT", "5000"))
    server = HTTPServer(("127.0.0.1", port), ForecastHandler)
    print(f"LFMIS production forecast listening on http://127.0.0.1:{port}")
    server.serve_forever()
