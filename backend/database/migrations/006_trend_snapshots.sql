-- File purpose: applies the 006_trend_snapshots database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS trend_snapshots (
  snapshot_key VARCHAR(20) PRIMARY KEY,
  payload JSON NOT NULL,
  fetched_at DATETIME NOT NULL
) ENGINE=InnoDB;