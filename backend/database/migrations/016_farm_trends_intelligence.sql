-- File purpose: applies the 016_farm_trends_intelligence database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- 016_farm_trends_intelligence.sql: Farm Trends & Agricultural Intelligence tables

-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS trend_snapshot_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trend_type ENUM('live', 'simulated', 'actual_farm') NOT NULL,
  geographic_scope ENUM('kenya', 'east_africa', 'global', 'farm') NOT NULL,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  payload JSON NOT NULL,
  status ENUM('success', 'stale', 'partial', 'failed') NOT NULL DEFAULT 'success',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_trend_history_period (period_start, period_end),
  INDEX idx_trend_history_type_scope (trend_type, geographic_scope),
  CONSTRAINT uq_trend_history_type_scope_period UNIQUE (trend_type, geographic_scope, period_start)
) ENGINE=InnoDB;

-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS trend_articles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  source_domain VARCHAR(120) NULL,
  published_at DATETIME NULL,
  retrieved_at DATETIME NOT NULL,
  country_code VARCHAR(10) NULL,
  geographic_scope ENUM('kenya', 'east_africa', 'global') NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'general',
  external_identifier VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_articles_scope (geographic_scope, retrieved_at),
  INDEX idx_articles_snapshot (snapshot_id),
  CONSTRAINT uq_articles_url_scope UNIQUE (url(190), geographic_scope)
) ENGINE=InnoDB;

-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS synthetic_trend_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NULL,
  period_start DATETIME NOT NULL,
  metric_name VARCHAR(80) NOT NULL,
  category ENUM('dairy', 'poultry', 'livestock', 'animal_feed', 'farm_economy', 'production') NOT NULL,
  geographic_scope ENUM('kenya', 'east_africa', 'global') NOT NULL,
  metric_value DECIMAL(10,2) NOT NULL,
  previous_value DECIMAL(10,2) NOT NULL,
  change_percent DECIMAL(6,2) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  is_simulated TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_synthetic_metric_scope (geographic_scope, category, metric_name, period_start)
) ENGINE=InnoDB;

-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS trend_refresh_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  live_status ENUM('success', 'stale', 'partial', 'failed') NOT NULL DEFAULT 'success',
  synthetic_status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
  actual_farm_status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT uq_refresh_logs_period UNIQUE (period_start),
  INDEX idx_refresh_logs_date (started_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (code, label) VALUES
  ('trends.view', 'View farm trends and market intelligence'),
  ('trends.manage', 'Refresh farm trends data');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT r.id, 'trends.view', 1 FROM roles r;

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT r.id, 'trends.manage', 1 FROM roles r WHERE r.name = 'admin';
