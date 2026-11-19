DELETE FROM two_factor_challenges WHERE method = 'sms';
DELETE FROM user_two_factor_methods WHERE method = 'sms';

ALTER TABLE user_two_factor_methods
  MODIFY method ENUM('email') NOT NULL;

ALTER TABLE two_factor_challenges
  MODIFY method ENUM('email') NOT NULL;
