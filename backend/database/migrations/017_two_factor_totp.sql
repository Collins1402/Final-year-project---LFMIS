-- File purpose: applies the 017_two_factor_totp database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the users structure so it can store the capability introduced here.
ALTER TABLE users
  ADD COLUMN totp_secret_enc VARBINARY(255) NULL,
  ADD COLUMN totp_enabled_at DATETIME NULL,
  ADD COLUMN totp_last_step BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- Adds or defines the recovery_codes records required by this migration.
CREATE TABLE recovery_codes (
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (user_id, code_hash),
  INDEX idx_recovery_codes_user_unused (user_id, used_at),
  CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (code, label) VALUES ('account.security', 'Manage personal account security');
INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT id, 'account.security', 1 FROM roles;

-- Adds or defines the trusted_devices records required by this migration.
CREATE TABLE trusted_devices (
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  label VARCHAR(120) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  INDEX idx_trusted_devices_user_expiry (user_id, expires_at),
  CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
