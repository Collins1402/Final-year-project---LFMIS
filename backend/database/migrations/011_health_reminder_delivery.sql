-- File purpose: applies the 011_health_reminder_delivery database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the health_records structure so it can store the capability introduced here.
ALTER TABLE health_records
  ADD COLUMN reminded_at DATETIME NULL AFTER next_due_date,
  ADD COLUMN reminder_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at,
  ADD COLUMN reminder_error VARCHAR(255) NULL AFTER reminder_attempts;
