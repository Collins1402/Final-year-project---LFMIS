ALTER TABLE users
  ADD COLUMN phone_verified_at DATETIME NULL AFTER phone;

CREATE TABLE user_two_factor_methods (
  user_id BIGINT UNSIGNED NOT NULL,
  method ENUM('email', 'sms') NOT NULL,
  enabled_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, method),
  CONSTRAINT fk_user_two_factor_method_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE two_factor_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('enroll', 'login') NOT NULL,
  method ENUM('email', 'sms') NOT NULL,
  destination VARCHAR(190) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_two_factor_challenge (user_id, purpose, method, created_at),
  CONSTRAINT fk_two_factor_challenge_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
