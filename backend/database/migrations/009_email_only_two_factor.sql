-- File purpose: applies the 009_email_only_two_factor database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
DELETE FROM two_factor_challenges WHERE method = 'sms';
DELETE FROM user_two_factor_methods WHERE method = 'sms';

-- Updates the user_two_factor_methods structure so it can store the capability introduced here.
ALTER TABLE user_two_factor_methods
  MODIFY method ENUM('email') NOT NULL;

-- Updates the two_factor_challenges structure so it can store the capability introduced here.
ALTER TABLE two_factor_challenges
  MODIFY method ENUM('email') NOT NULL;
