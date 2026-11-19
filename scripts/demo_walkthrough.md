# Flouri Farm LFMIS: 10-minute defence demo

Use a staging database and Daraja sandbox account. Before the timed demo, apply migrations, start the PHP API, Vite frontend and ML service, and prepare active admin, worker, veterinarian, buyer, and throwaway lockout accounts. Configure email delivery and enroll email two-factor authentication for the demo admin. Have one active livestock/produce listing and an M-PESA sandbox phone ready. Do not use real customer data or production credentials.

Set these PowerShell variables for local commands. Replace only the demo account names/passwords with credentials created for staging; do not copy credentials from `.env.local`.

```powershell
$Api = 'http://127.0.0.1:8000'
$AppOrigin = 'http://127.0.0.1:5173'
$WorkerUser = 'demo_worker'
$WorkerPassword = 'replace-with-staging-worker-password'
$WorkerCookie = Join-Path $env:TEMP 'lfmis-worker.cookies'
$LockoutUser = 'demo_lockout'
$LockoutCookie = Join-Path $env:TEMP 'lfmis-lockout.cookies'
```

| Time | Demo steps and expected results |
|---|---|
| 0:00–1:00 | Open the Vite site and sign in as the admin. Show the emailed one-time code and complete two-factor verification. Expected: admin workspace opens only after the code is accepted. |
| 1:00–2:30 | Sign out and sign in as the worker. From **Home**, switch English/Kiswahili and choose **Milk & eggs / Maziwa na mayai**. Record today's milk or egg quantity and save. Expected: the new production record appears at the top of Recent records. |
| 2:30–3:30 | Show the API and saved production row. Run `curl.exe -sS "$Api/api/health"`; expected JSON contains `"status":"ok"` and `"database":"ok"` (the ML value depends on whether the forecast service is running). Then query MySQL: |

```powershell
mysql.exe --host=127.0.0.1 --port=4321 --user=flouri_admin -p lfmis -e "SELECT id, produce_name, production_date, quantity_produced, unit, recorded_by FROM farm_produce ORDER BY id DESC LIMIT 1;"
```

Expected: one row for the production entry, with `recorded_by` set to the worker user ID. MySQL prompts for the database password.

| Time | Demo steps and expected results |
|---|---|
| 3:30–5:00 | Sign in as the veterinarian, choose **Home → Add animal care / Nyumbani → Ongeza matibabu**, add a treatment for an animal and save. Expected: the health history shows the new record and the language toggle switches the short labels. |
| 5:00–6:00 | Query the latest care row: |

```powershell
mysql.exe --host=127.0.0.1 --port=4321 --user=flouri_admin -p lfmis -e "SELECT id, animal_id, record_type, description, record_date, recorded_by FROM health_records ORDER BY id DESC LIMIT 1;"
```

Expected: a `treatment` row for the selected animal, recorded by the veterinarian.

| Time | Demo steps and expected results |
|---|---|
| 6:00–7:15 | Sign in as the buyer, select an available listing, place an order, and open **My orders**. Choose **Pay with M-PESA** using the sandbox test phone. The prompt should appear on the phone; approve it with the sandbox PIN. Expected: the UI first says to check the phone, then shows paid after the Daraja callback; if sandbox credentials/callback connectivity are unavailable, show the pending/failure state and explicitly call out that live gateway delivery was not demonstrated. |
| 7:15–8:00 | Confirm settlement records with MySQL: |

```powershell
mysql.exe --host=127.0.0.1 --port=4321 --user=flouri_admin -p lfmis -e "SELECT id, order_id, method, status, transaction_reference, paid_at FROM payments ORDER BY id DESC LIMIT 1; SELECT id, status, total_amount FROM orders ORDER BY id DESC LIMIT 1; SELECT id, order_id, description, total_amount FROM sales ORDER BY id DESC LIMIT 1;"
```

Expected after a confirmed sandbox callback: payment status `confirmed` with a receipt reference and paid time, order status `paid`, and a sale linked to the order. The callback is idempotent, so repeating it does not add another sale.

| Time | Demo steps and expected results |
|---|---|
| 8:00–8:45 | Create a worker API session and demonstrate role denial. Expected: login returns the worker user; the backup request returns HTTP `403`. |

```powershell
$WorkerLoginBody = @{ login = $WorkerUser; password = $WorkerPassword } | ConvertTo-Json -Compress
curl.exe -sS -c $WorkerCookie -b $WorkerCookie -H "Content-Type: application/json" -X POST "$Api/api/login" -d $WorkerLoginBody
curl.exe -i -b $WorkerCookie -H "Origin: $AppOrigin" -H "Content-Type: application/json" -X POST "$Api/api/admin/backup" -d "{}"
```

| 8:45–9:15 | Show SQL-injection resistance by submitting an injection-shaped login. Expected HTTP `401` and the ordinary credential error; no account is authenticated. |

```powershell
curl.exe -i -H "Content-Type: application/json" -X POST "$Api/api/login" -d "{`"login`":`"' OR 1=1 --`",`"password`":`"not-a-password`"}"
```

| 9:15–10:00 | Demonstrate account lockout with a throwaway staging user only. Five wrong passwords should return ordinary HTTP `401` errors; the account is then locked for 15 minutes. Query the stored hash prefix and lock state without displaying the full password hash: |

```powershell
1..5 | ForEach-Object { curl.exe -sS -o NUL -w "%{http_code}`n" -H "Content-Type: application/json" -X POST "$Api/api/login" -d "{`"login`":`"$LockoutUser`",`"password`":`"wrong-demo-only`"}" }
mysql.exe --host=127.0.0.1 --port=4321 --user=flouri_admin -p lfmis -e "SELECT username, LEFT(password_hash, 4) AS hash_prefix, failed_login_attempts, locked_until FROM users WHERE username = 'demo_lockout';"
```

Expected: five `401` responses, a hash prefix such as `$2y$` (not a plaintext password), at least five failed attempts, and a future `locked_until`. Reset or remove only this throwaway account after the demo; never use the lockout steps on an administrator or a real user's account.
