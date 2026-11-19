ALTER TABLE health_records
  ADD COLUMN reminded_at DATETIME NULL AFTER next_due_date,
  ADD COLUMN reminder_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at,
  ADD COLUMN reminder_error VARCHAR(255) NULL AFTER reminder_attempts;
