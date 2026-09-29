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
- XAMPP with Apache/PHP and MySQL. Start MySQL from the XAMPP Control Panel.
- Python 3.10 or later for the milk forecast service.

### Database

Import the schema once into the local MySQL server:

```powershell
Get-Content -Raw backend/database/lfmis.sql | C:\xampp\mysql\bin\mysql.exe -u root
```

The development defaults use MySQL at `127.0.0.1:3306`, database `lfmis`, and the XAMPP `root` account with no password. For a configured account, set `LFMIS_DB_HOST`, `LFMIS_DB_PORT`, `LFMIS_DB_NAME`, `LFMIS_DB_USER`, and `LFMIS_DB_PASSWORD` in the PHP server terminal before starting it. Never commit real credentials.

### Old Mark Donald assistant

The staff workspace includes **Old Mark Donald**, a farm assistant available to administrators, workers, and veterinarians. It works with a local fallback by default. To connect it to an OpenAI-compatible chat model, set these variables in the PHP server terminal before starting the API:

```powershell
$env:LFMIS_AI_API_URL = 'https://api.openai.com/v1'
$env:LFMIS_AI_API_KEY = 'replace-with-your-key'
$env:LFMIS_AI_MODEL = 'gpt-4o-mini'
```

The API key is server-side only and must never be placed in frontend code or committed to the repository. Without these variables, the assistant answers common production, livestock, and task questions from the live database summary.

### Start the services

Open three terminals at the repository root and leave each running:

```powershell
php -S 127.0.0.1:8000 backend/api/index.php
```

```powershell
python -m pip install -r backend/ml/requirements.txt
python backend/ml/forecast_service.py
```

```powershell
npm --prefix frontend install
npm --prefix frontend run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173/`. On a new database, create the first farm-owner account on the initial setup screen. It requires a password with at least 12 characters. An administrator can create staff and customer accounts under **User accounts**.

To switch roles, sign in as an admin, open **User accounts**, and create a worker or customer login with its own email and password. Use the header sign-out button, then sign in using those new credentials. Customers land in a separate farm shop; they do not see staff, inventory, or finance screens.

Admins publish items under **Products**. Livestock availability is computed from active animal records, including cattle, sheep, goats, rabbits, and chickens. Milk/egg listings can be linked to production records so daily entries add to listed stock; other produce such as wool can be listed with manually managed stock. Buyers can request only listed products and available quantities. Stock is checked again when the admin accepts an order; accepted livestock orders record the specific animals sold. Buyers can download their requests and assigned animal identifiers as CSV.

Farm workers and admins can enter daily milk/egg production, review the recorded seven-day totals and daily figures, and share that report through WhatsApp. The same seven-day totals appear on their dashboard.

### Password recovery

The sign-in page has a **Forgot password?** flow. Reset links are single-use, expire after 30 minutes, and are stored as token hashes. Requests use the same response whether or not the email matches an active account.

For email delivery, configure PHP's `mail()` transport to use your SMTP relay, set `LFMIS_MAIL_FROM` to a valid sender address, and set `LFMIS_APP_URL` to the frontend origin. For local testing without email, set `LFMIS_DEV_LOG_PASSWORD_RESET_LINK=true` in the environment before running `backend/start-dev.sh`; the reset URL is then written to the PHP server terminal. This option is for local development only and must remain disabled in production.

### Current working modules

- First-admin setup, password-hashed login, logout, sessions, role checks, and audit entries.
- Forgot-password requests and single-use, expiring reset links.
- Admin, farm-worker, veterinarian, and customer roles, with staff/customer account creation.
- Livestock registration, health records, farm activities/tasks, milk and egg production, and stock movements.
- Product catalog, availability-checked customer orders, admin order acceptance/stock allocation, and buyer CSV exports.
- Sales and expenses, admin order quoting/status updates, payment ledger, and customer feedback.
- Daily/weekly production summaries with WhatsApp sharing for farm workers and admins.
- Customer feedback, live dashboard totals, monthly financial summaries, and milk forecasts with a seven-day backtest.

The ML service requires at least 21 consecutive daily production records. It rejects missing-day histories rather than treating unrecorded days as zero. No sample farm data is seeded.

### Tests

```powershell
npm --prefix frontend run build
php -l backend/api/index.php
php -l backend/config/database.php
python -m unittest discover -s backend/ml -p "test_*.py"
```

### Not yet integrated

- M-Pesa STK prompts, signed payment callbacks, and automated payment confirmation need real Daraja credentials and a reachable callback URL. M-Pesa payments are stored as pending; non-M-Pesa payments entered by an admin are recorded as confirmed.
- Live M-Pesa prompts and signed payment callbacks need Daraja credentials and a reachable callback URL. Payment receipts, notification delivery, supplier workflows, offline synchronization, account deactivation, and broader reporting remain follow-up work.
- The forecast is a baseline decision-support model, not a veterinary diagnosis. Its measured error must be reviewed against real farm data before relying on it.
