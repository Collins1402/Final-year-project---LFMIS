"""
Forecast features: constructs calendar, lagged production, and optional weather inputs.
The forecasting service calls these helpers when training and predicting milk output.
The calculations depend on ordered daily observations and optional weather measurements.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import numpy as np

LAG_DAYS = 7


def weather_values(observation: dict[str, Any]) -> tuple[float | None, float | None]:
    """Explain the weather values operation and return its computed result."""
    rain = observation.get("rain_mm")
    temp = observation.get("temp_c")
    return (None if rain is None else float(rain), None if temp is None else float(temp))


def build_features(
    observations: list[dict[str, Any]],
    target_index: int,
    use_weather: bool = True,
    rain_mean: float | None = None,
    temp_mean: float | None = None,
) -> list[float]:
    """Explain the build features operation and return its computed result."""
    if target_index < LAG_DAYS or target_index >= len(observations):
        raise ValueError("A feature row needs seven previous observations.")
    values = [float(item["litres"]) for item in observations]
    target_date = date.fromisoformat(str(observations[target_index]["date"]))
    features = [*values[target_index - LAG_DAYS:target_index][::-1], float(target_date.weekday())]
    if use_weather:
        rain, temp = weather_values(observations[target_index])
        features.extend([
            rain_mean if rain is None else rain,
            temp_mean if temp is None else temp,
        ])
    return features


def weather_means(observations: list[dict[str, Any]], end_index: int) -> tuple[float, float]:
    """Explain the weather means operation and return its computed result."""
    rains: list[float] = []
    temps: list[float] = []
    for item in observations[:end_index]:
        rain, temp = weather_values(item)
        if rain is not None:
            rains.append(rain)
        if temp is not None:
            temps.append(temp)
    return (
        float(np.mean(rains)) if rains else 0.0,
        float(np.mean(temps)) if temps else 0.0,
    )
