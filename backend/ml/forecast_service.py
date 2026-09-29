import json
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any

from sklearn.linear_model import LinearRegression


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
        if self.path != "/forecast":
            self.send_error(404)
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 1_000_000:
                raise ValueError("Request body must be between 1 byte and 1 MB.")
            payload = json.loads(self.rfile.read(length))
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