# Design and Development of a Web-Based Livestock Farm Management Information System

## Project Overview

This project involves the design and development of a web-based Livestock Farm Management Information System (LFMIS) for Flouri Farm. The system will provide one centralized platform for recording livestock information, monitoring animal health and farm activities, managing farm produce and materials, processing customer orders and payments, and producing useful operational and financial reports.

The project responds to the problem of livestock farmers relying on fragmented manual records. These records can make it difficult to trace an animal's history, monitor vaccinations and treatment, track produce, manage inventory, verify transactions, and make timely business decisions. The proposed system is intended to improve record accessibility, livestock traceability, customer service, and farm management without adding unnecessary complexity for users with limited digital literacy.

## Proposed Scope

The system will cover:

- Animal profiles and livestock inventory for cows, goats, rabbits, and chickens.
- Health and maintenance records, including deworming, treatment, vaccinations, medication, and incidents.
- Farm activities such as feeding, cleaning, observation, breeding, and animal movement.
- Produce records for milk in litres, including morning and evening quantities by date and month, and eggs in trays.
- Materials and stock records for items such as hay and salt lick.
- Sales, income, expenses, customer orders, receipts, M-Pesa payments, till number generation, prompts, and loyalty points.
- Customer service through complaints, feedback, suggestions, and frequently asked questions.
- Livestock and produce sales, including the sale of male and old cows, hens and chicks, and rabbits.
- Reports that support farm performance, profitability, and decision-making.

The current prototype frontend uses Vite and JavaScript. The backend uses PHP, with MySQL during development through XAMPP. The design will also consider a future mobile application and low-bandwidth or offline use by farm workers.

## Users and External Systems

The main users and external actors will be:

- **Admin (Farmer/Farm Owner):** manages livestock, farm records, users, sales, and reports.
- **Farm Worker/Laborer:** records daily activities, observations, incidents, and stock usage.
- **Veterinarian:** views animal health history and records diagnoses, treatment, and vaccinations where applicable.
- **Livestock Buyer/Market:** views available livestock or produce and submits orders.
- **Feed Supplier:** responds to feed requirements and provides delivery or invoice information.
- **System Administrator:** manages accounts, permissions, backups, and system monitoring.
- **M-Pesa:** external payment gateway that processes and confirms customer payments.
- **Bank/Financial Institution:** external stakeholder that may receive authorized financial or loan-related reports.

In UML use case diagrams, people will be represented as actor stick figures, while external systems such as M-Pesa will use system notation such as `<<M-Pesa>>`. The system boundary will clearly separate LFMIS functionality from external actors.

## Data Collection and Analysis

Data will come from farm records and activities, including animal registration, worker observations, veterinary entries, produce measurements, stock updates, customer orders, payment confirmations, expenses, sales, and customer-service interactions. Additional contextual data may include daily weather information such as rainfall, temperature, and sunshine conditions from a suitable weather service or approved public dataset.

Data will be collected through system forms, farm observation, interviews with the farmer and workers, review of existing records, veterinary records, customer transactions, supplier documents, and payment-gateway responses. Data will be entered as it is generated and validated before storage. The project will not manipulate, massage, or fabricate data; analysis will clearly distinguish between recorded data, derived measures, and predictions.

Analysis may use spreadsheets, SQL queries, dashboards, descriptive statistics, and visualizations. These tools will help identify production trends, animal-health patterns, stock usage, sales performance, customer activity, and the relationship between weather conditions and farm income or produce output.

## Machine Learning and Decision Support

To give the project an appropriate fourth-year level, the system will include a small, clearly scoped machine-learning or predictive analytics component. Possible applications include:

- Predicting expected milk or egg production from historical production, animal, and weather data.
- Identifying unusual changes in production or animal-health records for early attention.
- Forecasting feed or material requirements from previous usage and livestock numbers.
- Estimating likely high-demand periods to support pricing and sales planning.

The model will be treated as decision support, not as a replacement for the farmer or veterinarian. Its performance will be evaluated using real collected data where sufficient data is available, and its limitations will be documented. Weather data will be used to investigate whether rainy, sunny, or unusually hot periods are associated with changes in production, sales, expenses, or profit. The project will report observed relationships carefully and will not claim that weather causes a business outcome without adequate evidence.

## Requirements and Design Deliverables

The project will define functional requirements describing what each actor can do and non-functional requirements describing quality expectations such as usability, security, availability, response time, accessibility, low-bandwidth support, backup, and data protection. At least two key functional requirements will be specified for each actor.

The analysis and design stage will include:

- UML use case diagram with actors, system actors, system boundary, and use cases.
- Two or three key use case narratives, such as recording animal treatment, placing an order, and confirming payment.
- System sequence diagrams and detailed sequence diagrams for selected workflows.
- Entity Relationship Diagram (ERD) showing singular entity names, primary keys, foreign keys, cardinalities, and explicit relationships such as one-to-one, one-to-many, many-to-one, many-to-many, and zero-to-many where applicable.
- Database design and implementation in MySQL.
- Interface designs that account for users with limited literacy through simple navigation, clear labels, familiar icons, validation, and accessible feedback.

## Development Plan

The first semester will focus on the concept note, project proposal, literature and problem analysis, requirements, and design diagrams. Proposal submission, defence, and feedback will be incorporated before implementation.

The second semester will use progressive sprints:

- **Sprint 1:** approximately 30% of the final system, covering the core database, authentication, livestock records, and basic farm activities.
- **Sprint 2:** approximately 75% of the final system, adding health records, produce, materials, orders, payments, and reporting.
- **Sprint 3:** approximately 90% of the final system, refining customer service, notifications, analytics or machine learning, testing, usability, and final documentation.

The final evaluation will consider whether LFMIS provides a better solution than fragmented manual methods by measuring record accessibility, traceability, transaction efficiency, reporting usefulness, usability, and the quality of decisions supported by the system.

## Run the Local System

### Prerequisites

- Node.js and npm.
- Composer 2 or later.
- PHP 8.0 or later with PDO MySQL, cURL and OpenSSL extensions.
- MySQL 8-compatible database. The development Docker container is `flouri-mysql` on host port `4321`.
- Python 3.10 or later for the milk forecast service.

### Database

Copy `.env.example` to `.env.local` and set the database credentials for your local MySQL instance. Never commit `.env.local` or share its password. Start the Docker database if it is stopped, then apply the schema and migrations:

```powershell
docker start flouri-mysql
composer install --working-dir=backend
php backend/bin/migrate.php
```

The migration command is idempotent. The schema lives in `backend/database/schema.sql`; later changes are applied from `backend/database/migrations/`.

### Old Mark Donald assistant

The staff workspace includes **Old Mark Donald**, a farm assistant available to administrators, workers, and veterinarians. It works with a local fallback by default. To connect it to an OpenAI-compatible chat model, set these variables in the PHP server terminal before starting the API:

```powershell
$env:LFMIS_AI_API_URL = 'https://api.openai.com/v1'
$env:LFMIS_AI_API_KEY = 'replace-with-your-key'
$env:LFMIS_AI_MODEL = 'gpt-4o-mini'
```

The API key is server-side only and must never be placed in frontend code or committed to the repository. Without these variables, the assistant gives rule-based operational advice from records allowed by the user's role. It can summarize recent public agriculture headlines, but headlines are signals rather than measured farm results.

The sign-in page includes a public agriculture-news tab. It uses GDELT Project news data for country-level and worldwide coverage, cached for six hours. Defaults are Kenya (`LFMIS_TRENDS_COUNTRY_CODE=KE`, `LFMIS_TRENDS_REGION=Kenya`); these settings can be changed for the farm's country or region. This is not a nearby-farm production feed. A nearby-farm comparison requires participating farms to share data with consent.

Administrators can manage and remove accounts under **User accounts**. Removing an account revokes its sign-in access while retaining farm records; administrators cannot remove their own account or the last active administrator. Administrators can manage role permissions under **Role permissions**. Current permissions are seeded to preserve the existing admin, worker, veterinarian, customer and buyer access. Administrator permissions cannot be removed. Each user can review their grants and personal-data request options under **Your rights**.

The assistant's 30-day scenario tool compares milk and egg forecasts when additional dairy cattle or laying hens are entered, and estimates feed stock-outs from the last 30 days of inventory movements. Each produce type needs at least 21 consecutive production days. The scenario assumes new animals perform like the existing group; recorded stock-outs may not equal feed consumed. It does not claim that a feed change causes a specific yield change. Simulated demo records are flagged.

Successful sign-ins trigger a security email containing the time, IP address and browser/device string. Email, password-reset, account-confirmation and two-factor messages use the shared SMTP mailer. Configure the SMTP settings described below; PHP's native `mail()` is not a mail server and normally cannot deliver messages on a Windows development machine.

On an empty animal register and empty produce table, an administrator can use **Load demo records** on the Overview page for a small labelled sample. For a larger forecast demonstration, generate SQL with `python scripts/seed_demo_data.py`; it writes `seed_data/seed.sql` and `seed_data/reset.sql`. Review and load those files with a MySQL client configured for the database in `.env.local`. The generated records are simulated demo data, not farm results. Run the seed once; use `reset.sql` to remove only its labelled rows before regenerating.

### Start the services

After the database is running and migrations are applied, open three terminals at the repository root and leave each running:

```powershell
php -S 127.0.0.1:8000 backend/public/index.php
```

```powershell
python -m pip install -r ml/requirements.txt
python ml/forecast_service.py
```

```powershell
npm --prefix frontend install
npm --prefix frontend run dev
```

Open `http://127.0.0.1:5173/`. On a new database, create the first farm-owner account on the initial setup screen. Passwords must contain at least 12 characters. Public registration is limited to customer and livestock-buyer accounts; administrators create worker and veterinarian accounts under **User accounts**.

#### Open the development app from another computer on your private Wi-Fi

Run the backend and database on the host computer as above. Stop the local Vite server if it is already using port `5173`, then start the LAN frontend:

```powershell
npm --prefix frontend run dev:lan
```

Find the host computer's private IPv4 address with `ipconfig` (for example, `192.168.1.20`). On the other computer, open `http://<host-private-ip>:5173/` using that address. Both computers must be on the same trusted private network. If Windows Firewall prompts, allow access on **Private networks only**. The Vite frontend proxies `/api` requests to PHP on the host, so PHP and MySQL remain bound to the host computer; no database port needs to be exposed. Do not router-port-forward this development server or use it as an internet-facing deployment. If the host's address changes, use its current IPv4 address; reserve a private address in your router if you need a stable URL.

Workers can record production, complete assigned activities and record stock movements. Veterinarians can review animal health history and record care. Customers can browse produce listings; livestock buyers can browse livestock listings. Each customer or buyer sees only their own orders, payments and feedback.

Admins manage listings, quote and accept orders, record payments and expenses, and fulfill or cancel orders. Product stock is checked again at acceptance; livestock orders allocate specific active animals and mark them sold. Milk, egg and wool listings can be linked to production records so new unsold production adds to stock.

Farm workers and admins can enter daily production, review seven-day and month-to-date totals with daily figures, and share the summary through WhatsApp. The admin overview includes live production, livestock, tasks, low stock, follow-ups, orders and monthly finance indicators. Reports include 12-month production/finance trends, health follow-ups and the seven-day milk forecast.

### Password recovery and sign-in alerts

The sign-in page has a **Forgot password?** flow. Reset links are single-use, expire after one hour, and are stored as token hashes. Requests use the same response whether or not the email matches an active account.

Configure real email delivery by adding your provider's values to `.env.local`:

```dotenv
LFMIS_SMTP_HOST=smtp.gmail.com
LFMIS_SMTP_PORT=587
LFMIS_SMTP_SECURE=tls
LFMIS_SMTP_USER=your-account@gmail.com
LFMIS_SMTP_PASSWORD=your-provider-app-password
LFMIS_MAIL_FROM=your-account@gmail.com
LFMIS_MAIL_FROM_NAME=Flouri Farm LFMIS
LFMIS_APP_URL=http://127.0.0.1:5173
```

For Gmail, enable Google 2-Step Verification and create an App Password; do not use your regular Google password. Other SMTP services (for example Brevo, Postmark or SendGrid) work with their SMTP host, port, username, password and verified sender address. For local development, Mailpit can capture email without sending it to a real inbox. Never commit `.env.local`. If messages still do not arrive, check the provider's delivery/bounce logs and the recipient's spam folder; production senders may also need SPF/DKIM records. Email confirmation, password reset and login verification now return a service-unavailable error when delivery is not configured rather than silently pretending to send.

Password-reset links expire after one hour and are stored as token hashes. Password-reset requests use the same response whether or not the email matches an active, verified account. Keep `LFMIS_DEV_LOG_PASSWORD_RESET_LINK=0`; setting it to `1` exposes password-reset links in API responses.

For a trusted, LAN-only development setup without SMTP, `LFMIS_DEV_LOG_EMAIL_VERIFICATION_LINK=1` returns email-verification links in API responses so the first administrator can activate the account. Open the development confirmation link shown after setup, then set the flag back to `0` and restart the PHP server. This flag is separate from password-reset links; keep password-reset links disabled. Anyone with access to the development API response could use a returned verification link, so configure SMTP and disable this flag before broader use.

### Optional email two-factor authentication

Users can enable email codes under **Account security**. A one-time code is sent to the verified account email at sign-in. Codes expire after 10 minutes, allow five attempts and are rate-limited to three sends per hour. Email two-factor requires working SMTP settings.

Create a 32-byte OTP hashing key with PHP, then add it to `.env.local`:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

```dotenv
LFMIS_2FA_OTP_KEY=generated-64-character-hex-key
```

Keep the key private in `.env.local` and use the SMTP settings described above for email delivery. Apply migration `009_email_only_two_factor` with `php backend/bin/migrate.php`.

### Current working modules

- First-admin setup, password-hashed login, logout, sessions, role checks, and audit entries.
- Forgot-password requests and single-use, expiring reset links.
- Admin, farm-worker, veterinarian, customer, and livestock-buyer workspaces with role-checked APIs.
- Livestock registration/status, latest-open health follow-ups, farm activities, production entries, and inventory movement history.
- Role-filtered product catalogue, customer order requests, order quoting/acceptance/fulfillment/cancellation, stock validation, and animal allocation.
- Sales, expenses, payment ledger, customer feedback, notifications, and customer-owned data scoping.
- Live admin overview, seven-day and month-to-date production summaries, daily figures, and WhatsApp sharing.
- Twelve-month production/finance reports, health follow-up indicators, and milk forecasts with a one-day-ahead backtest.
- Admin-managed role permissions, user-facing access/data-rights information, and successful-login email alerts.
- Six-hour cached country/global agriculture news signals and 30-day milk/egg/feed planning scenarios with explicit data limitations.

The ML service requires at least 21 consecutive daily production totals. It uses the latest gap-free run, excludes today, and does not treat missing days as zero. Forecasts are decision support only; inspect the baseline comparison and simulated-data warning before presenting results.

### Tests

Run from the repository root:

```powershell
npm --prefix frontend run build
php -l backend/public/index.php
Push-Location backend; composer test; Pop-Location
python -m pytest ml/tests
```

For timestamped test logs, run `bash scripts/export_evidence.sh` from Git Bash or another Bash shell. It stores PHPUnit `--testdox` and pytest `-v` output in `docs/test-evidence/`. The authenticated API rejects write requests from untrusted origins, applies basic per-IP/per-user request limits to login, password reset and M-PESA initiation, and sends browser security headers/CSP. Worker and veterinarian workspaces provide short English/Kiswahili labels and large home-screen shortcuts.

The timed, staging-only defence sequence and expected API/database results are in [scripts/demo_walkthrough.md](scripts/demo_walkthrough.md). M-PESA is simulated locally for buyers and staff; no Daraja credentials, phone prompt, or real charge are used.

### Scheduled health reminders

The daily reminder job creates a staff notification for health records due today or earlier, then emails the active administrator. A successful email is sent once. If delivery fails, the attempt count and error are recorded and the next run retries it.

On Windows, create a daily task in Task Scheduler. Set **Program/script** to your PHP executable (for example `C:\xampp\php\php.exe`), **Add arguments** to the full path of `backend\bin\send_reminders.php`, and **Start in** to the repository root. The job must run with access to the same `.env.local` database and SMTP configuration as the web API.

On a Linux host, add a daily cron entry, replacing the paths with the deployment paths:

```cron
0 7 * * * /usr/bin/php /path/to/lfmis/backend/bin/send_reminders.php
```

### Database backups and restore

The admin **Audit log** screen has a **Back up now** action. It runs `mysqldump` on the server and writes a timestamped SQL file under the repository's ignored `backups/` directory. The API returns only the filename and timestamp; backup files are not served over HTTP. The server must have the MySQL client utility `mysqldump` on its `PATH`. You can also create a backup from the repository root with:

```powershell
php backend/bin/backup.php
```

To restore, stop the PHP API and frontend first. Make a separate copy of the SQL file and ensure the target database exists and is empty or that you intend to replace its contents. Open a MySQL client using the same host, port and database settings as the application, then run `SOURCE` with the full backup-file path:

```text
mysql --host=127.0.0.1 --port=4321 --user=flouri_admin -p lfmis
SOURCE C:/path/to/lfmis/backups/lfmis-YYYYMMDD-HHMMSS-uuuuuu.sql;
```

The `-p` option prompts for the database password; do not put it in the command. Verify the restored tables and latest records before restarting the application. Keep backups private and store an additional copy in a protected location outside the repository.

### Milk forecast evaluation

The evaluation command reads `ml/data/real_milk.csv` with columns `date,litres,rain_mm,temp_c` and writes the result to `docs/ml-evaluation.md`:

```powershell
python ml/evaluate.py
```

The checked-in CSV is an empty template because no verified farm production dataset was supplied. The generated report therefore records zero available days and does not present synthetic fixture metrics as farm accuracy. Populate the CSV with real, consecutive daily records before reporting accuracy; the test-only synthetic data remains under `ml/tests/`.

### Synthetic Excel dataset

The supplied `LFMIS_Extensive_Synthetic_Dataset_12_Months.xlsx` workbook is intended for staging or UI demonstration only. It contains placeholder passwords and payment references and must not be imported as real data. Install the workbook reader and generate a safe, remapped SQL import:

```powershell
python -m pip install -r scripts/requirements.txt
python scripts/import_synthetic_workbook.py "C:\Users\colli\Downloads\LFMIS_Extensive_Synthetic_Dataset_12_Months.xlsx"
```

By default this generates `seed_data/synthetic-workbook.sql` and `seed_data/reset-synthetic-workbook.sql`. Apply it only to a staging or demo database after migrations:

```powershell
php backend/bin/import_synthetic.php
```

Use `php backend/bin/import_synthetic.php --reset-only` to remove only the synthetic-ID/tag range without changing real accounts or records. The importer does not copy workbook password placeholders or synthetic user-account credentials.

The converter remaps workbook IDs, creates separate synthetic order records instead of repeating order ID `1`, uses the workbook's synthetic animal and customer records only with `SIMULATED DEMONSTRATION` labels, excludes the placeholder account passwords, and omits synthetic forecast/audit records that must not be presented as real system evidence.

### Not yet integrated

- Supplier workflows, offline synchronization, and mobile support remain follow-up work. Email features require a configured SMTP relay.
- Forecast performance must be evaluated with real farm records before operational use. Simulated data is labelled and must not be presented as farm results.
