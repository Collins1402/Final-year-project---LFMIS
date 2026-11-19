# Farm Trends & Agricultural Intelligence

## Architecture

The module is integrated into the existing PHP/MySQL API and Vite JavaScript frontend.

- Live GDELT articles: `backend/src/Services/TrendsService.php`
- Synthetic indicators and events: `backend/src/Services/SyntheticTrendsService.php`
- Actual operational records: `backend/src/Services/FarmMetricsService.php`
- Six-hour job: `backend/src/Jobs/RefreshTrends.php`
- CLI runner: `backend/bin/refresh-trends.php`
- Dashboard module: **Insights > Farm trends**

Live, synthetic and actual-farm snapshots remain separate in API payloads and database history.

## Database

Run all migrations:

```powershell
php backend/bin/migrate.php
```

Migration `016_farm_trends_intelligence.sql` creates trend snapshot history, individual articles, synthetic metrics and refresh logs. It also adds the `trends.view` and `trends.manage` permissions. Existing records are preserved.

## API

- `GET /api/trends`
- `GET /api/trends/history?scope=kenya&type=live&limit=20`
- `GET /api/trends/metrics?scope=kenya&category=dairy&metricName=milk_demand_index&limit=40`
- `GET /api/trends/status`
- `POST /api/trends/refresh` — administrator-only, session-authenticated and rate-limited

Valid scopes are `kenya`, `east_africa`, `global` and `farm`. Valid trend types are `live`, `simulated` and `actual_farm`.

## External feeds

TrendsService retrieves GDELT agriculture news independently for Kenya, East Africa and Global scopes. It canonicalizes URLs, deduplicates articles, rejects missing titles/URLs, preserves valid cached articles on provider failure, and marks stale feeds. Publication dates, retrieval dates and reporting periods are represented independently.

GDELT credentials are not required. Network access and provider limits still apply.

## Synthetic engine

Synthetic generation uses a configurable seed and deterministic scope/metric variation. It produces dairy, poultry, livestock, feed, economy and production indicators for Kenya, East Africa and Global. Each metric includes previous value, percentage change and a prominent simulated disclaimer.

Active events are controlled, synthetic examples such as drought pressure and transport disruption. They are never represented as real events.

## Actual farm data

FarmMetricsService uses recorded production, animals, inventory, sales, expenses and health records. Missing tables or records produce an empty/zero state rather than invented values.

## Schedule

Reporting periods use `Africa/Nairobi`:

- 00:00–06:00
- 06:00–12:00
- 12:00–18:00
- 18:00–00:00

Run manually:

```powershell
php backend/bin/refresh-trends.php
php backend/bin/refresh-trends.php --force
```

### Windows Task Scheduler

Create a task for each six-hour boundary or a single task run every 6 hours aligned to 00:00:

1. Open Task Scheduler.
2. Create Task > Trigger: Daily at `00:00`, repeat every `6 hours` indefinitely.
3. Create another task trigger for the 06:00, 12:00 and 18:00 boundaries if you prefer separate tasks.
4. Action: Start a program.
5. Program: `C:\...\php.exe` or the full XAMPP PHP executable.
6. Arguments: `C:\Final Year Project\Final-year-project---LFMIS\backend\bin\refresh-trends.php`
7. Start in: `C:\Final Year Project\Final-year-project---LFMIS`

Use an account with read/write access to the repository and `.env.local`. Scheduling at the exact clock boundaries satisfies the alignment requirement.

### Linux cron

Run at minute 0 on hours 0, 6, 12 and 18:

```cron
0 0,6,12,18 * * * cd /srv/lfmis && php backend/bin/refresh-trends.php >> /var/log/lfmis-trends.log 2>&1
```

The job checks the reporting period, skips duplicate completed periods, creates a refresh log, persists independent source statuses and detects missed periods.

## Environment variables

- `LFMIS_TRENDS_ENABLED=true`
- `LFMIS_GDELT_MAX_RECORDS=12`
- `LFMIS_GDELT_TIMEOUT_SECONDS=10`
- `LFMIS_SYNTHETIC_ENABLED=true`
- `LFMIS_SYNTHETIC_SEED=<optional integer>`
- `LFMIS_TRENDS_REPORTING_TIMEZONE=Africa/Nairobi`
- `LFMIS_TRENDS_SCHEDULER_ENABLED=true`
- `LFMIS_TRENDS_COUNTRY_CODE=KE`
- `LFMIS_TRENDS_REGION=Kenya`

External provider credentials and private tokens must remain in `.env.local`.

## Testing

From the backend directory:

```powershell
composer install
.\vendor\bin\phpunit --testdox tests/TrendsServiceTest.php
.\vendor\bin\phpunit --testdox tests/SyntheticTrendsServiceTest.php
.\vendor\bin\phpunit --testdox tests/FarmMetricsServiceTest.php
.\vendor\bin\phpunit --testdox tests/RefreshTrendsJobTest.php
.\vendor\bin\phpunit --testdox tests/TrendsApiTest.php
```

External services are mocked in the trends tests. Internet access is not required for passing tests.

## Adding regions

Add a scope constant and GDELT feed query in `TrendsService::current`, add it to `SyntheticTrendsService::SCOPES`, update allowed database ENUM values, and add a frontend scope filter. MySQL migrations must run before ENUM use.

## Adding synthetic indicators

Add a metric definition under the relevant category in `SyntheticTrendsService::definitions`. Add optional event effects in `activeEvents` and update the synthetic history tests.

## Known limitations

- GDELT is a news provider, not a verified market-price source.
- No weather provider request is implemented by the module; only stored `weather_observations` are shown.
- Synthetic values are controlled demonstrations, not real prices or forecasts.
- A provider outage is mitigated by cached/stale handling, but it cannot recover data the system never received.
