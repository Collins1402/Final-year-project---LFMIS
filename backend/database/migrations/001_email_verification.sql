SET @email_verified_column_sql = IF(
  EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'email_verified_at'
  ),
  'SELECT 1',
  'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email'
);
PREPARE email_verified_column_stmt FROM @email_verified_column_sql;
EXECUTE email_verified_column_stmt;
DEALLOCATE PREPARE email_verified_column_stmt;

SET @email_verified_index_sql = IF(
  EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'idx_users_email_verified'
  ),
  'SELECT 1',
  'ALTER TABLE users ADD INDEX idx_users_email_verified (email_verified_at, is_active)'
);
PREPARE email_verified_index_stmt FROM @email_verified_index_sql;
EXECUTE email_verified_index_stmt;
DEALLOCATE PREPARE email_verified_index_stmt;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_email_verification_token UNIQUE (token_hash),
  CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
