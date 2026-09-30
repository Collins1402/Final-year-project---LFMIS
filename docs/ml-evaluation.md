# ML Evaluation

This is a synthetic fixture result for pipeline verification only. It must not be reported as Flouri Farm accuracy. Replace it with `ml/data/real_milk.csv` after at least 21 consecutive real production days.

Sample size: 21 consecutive synthetic days; rolling backtest: 7 days.

| Model | Features | MAE (L) | RMSE (L) | Best baseline MAE (L) | Improvement |
|---|---|---:|---:|---:|---:|
| Ridge regression | 7 lags + day-of-week + without weather | 0.032 | 0.037 | 0.400 | 91.95% |
| Ridge regression | 7 lags + day-of-week + with weather | 0.032 | 0.039 | 0.400 | 91.88% |
| Baseline: naive | Previous day | 0.400 | 0.400 | - | - |
| Baseline: seven-day mean | Previous seven-day mean | 1.600 | 1.600 | - | - |
| Baseline: seasonal naive | Same weekday last week | 2.800 | 2.800 | - | - |

The weather comparison is associated with this synthetic fixture only; it does not establish causation. The real evaluation must state the number of farm days, missing values, and the limits of using data from one farm.
