CREATE TABLE IF NOT EXISTS api_rate_limits (
  bucket_key CHAR(64) NOT NULL,
  window_start BIGINT UNSIGNED NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  INDEX idx_api_rate_limits_window (window_start)
) ENGINE=InnoDB;
