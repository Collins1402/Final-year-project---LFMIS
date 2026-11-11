CREATE TABLE IF NOT EXISTS trend_snapshots (
  snapshot_key VARCHAR(20) PRIMARY KEY,
  payload JSON NOT NULL,
  fetched_at DATETIME NOT NULL
) ENGINE=InnoDB;