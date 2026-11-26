-- File purpose: applies the 015_rate_limits database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS api_rate_limits (
  bucket_key CHAR(64) NOT NULL,
  window_start BIGINT UNSIGNED NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  INDEX idx_api_rate_limits_window (window_start)
) ENGINE=InnoDB;
