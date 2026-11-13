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
npm --prefix frontend run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173/`. On a new database, create the first farm-owner account on the initial setup screen. Passwords must contain at least 12 characters. Public registration is limited to customer and livestock-buyer accounts; administrators create worker and veterinarian accounts under **User accounts**.

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

Password-reset links expire after one hour and are stored as token hashes. Password-reset requests use the same response whether or not the email matches an active, verified account. `LFMIS_DEV_LOG_PASSWORD_RESET_LINK=1` exposes development links in the API response when delivery is unavailable; use this only locally and keep it disabled in production.

### Optional email/SMS two-factor authentication

Users can enable email codes, SMS codes or both under **Account security** and choose a method after entering their password at sign-in. Email uses the verified account email. SMS is limited to verified Kenyan mobile numbers and uses Africa's Talking. Codes are one-time, expire after 10 minutes, allow five attempts and are rate-limited to three sends per method per hour. SMS is less resistant to SIM-swap attacks than authenticator apps; avoid using it as the only factor for highly sensitive accounts.

Create a 32-byte OTP hashing key with PHP, then add it to `.env.local`:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

```dotenv
LFMIS_2FA_OTP_KEY=generated-64-character-hex-key
LFMIS_AT_USERNAME=sandbox
LFMIS_AT_API_KEY=your-africas-talking-api-key
LFMIS_AT_BASE_URL=https://api.sandbox.africastalking.com/version1/messaging
```

Africa's Talking sandbox messages appear in its simulator, not on a real phone. For production, use the production messaging endpoint, account credentials/credits, and a registered sender ID if required by the provider. Keep all API keys server-side in `.env.local`; never put them in frontend code. Apply migration `008_two_factor_email_sms` with `php backend/bin/migrate.php`.

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

```powershell
npm --prefix frontend run build
php -l backend/public/index.php
Push-Location backend; php vendor/bin/phpunit -c phpunit.xml; Pop-Location
python -m pytest ml/tests
```

### Not yet integrated

- Live M-Pesa STK prompts, signed callbacks, and automated confirmation require Daraja credentials and a reachable callback URL. M-Pesa payments remain pending; manually recorded non-M-Pesa payments are confirmed.
- Supplier workflows, offline synchronization, and mobile support remain follow-up work. Email features require a configured SMTP relay.
- Forecast performance must be evaluated with real farm records before operational use. Simulated data is labelled and must not be presented as farm results.
