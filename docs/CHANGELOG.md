# Changelog

One line per phase gate.

- 2026-09-30 · Rebuild started: old code removed (kept in Git history at `6a79c7b`), skeleton created.
- 2026-10-13 · Demo-ready workflows: integrated milk forecasting and 12-month reports; added role workspaces, customer data scoping, order stock allocation, inventory history, live overview, production summaries, simulated SQL data generation, and corrected local run instructions.
- 2026-11-19 · M-PESA: added Daraja STK initiation, secret-protected idempotent callbacks, payment/order settlement, and buyer-side payment polling.
- 2026-11-19 · Health reminders: added daily due-record notifications and retryable administrator email delivery with attempt tracking.
- 2026-11-19 · Activity health concerns: worker activity entries can create issue records and notify administrators and veterinarians.
- 2026-11-19 · Audit trail: added centralized event logging, admin-filtered audit history and an audited farm-data export.
- 2026-11-19 · Database backups: added an admin-triggered, server-side mysqldump job, ignored backup storage and restore instructions.
- 2026-11-19 · Forecast evaluation: added the real-milk CSV input and generated-report workflow, with short or invalid history explicitly excluded from farm-accuracy claims.
