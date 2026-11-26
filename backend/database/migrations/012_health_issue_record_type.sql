-- File purpose: applies the 012_health_issue_record_type database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the health_records structure so it can store the capability introduced here.
ALTER TABLE health_records
  MODIFY record_type ENUM('vaccination', 'treatment', 'deworming', 'checkup', 'illness', 'issue', 'other') NOT NULL;
