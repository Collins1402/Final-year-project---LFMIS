# ML Evaluation

Data source: `ml/data/real_milk.csv`
Production rows/days supplied: 0

| Column | Missing values |
|---|---:|
| date | 0 |
| litres | 0 |
| rain_mm | 0 |
| temp_c | 0 |

Status: Cannot be reported as farm accuracy: only 0 production day(s) are available; at least 21 consecutive days are required.

| Model | Features | Backtest days | MAE (L) | RMSE (L) |
|---|---|---:|---:|---:|
| Ridge regression | 7 lags + day-of-week + weather | - | Not computed | Not computed |
| Baseline: naive | Previous day | - | Not computed | Not computed |
| Baseline: seven-day mean | Previous seven-day mean | - | Not computed | Not computed |
| Baseline: seasonal naive | Same weekday last week | - | Not computed | Not computed |

Limits: this model is decision support, not a causal explanation or guarantee of future yield. Results from one farm may not generalize to other farms or seasons. The weather-enabled ridge model uses observed weather where available and historical means for missing weather; association does not establish that weather caused a production change. Rows must represent complete, consecutive calendar days for the backtest to run.
