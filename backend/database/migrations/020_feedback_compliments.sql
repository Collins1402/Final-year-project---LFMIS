-- File purpose: applies the 020_feedback_compliments database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the feedback structure so it can store the capability introduced here.
ALTER TABLE feedback
  MODIFY COLUMN category ENUM('feedback', 'complaint', 'suggestion', 'compliment') NOT NULL;
