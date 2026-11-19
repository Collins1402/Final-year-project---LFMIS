ALTER TABLE health_records
  MODIFY record_type ENUM('vaccination', 'treatment', 'deworming', 'checkup', 'illness', 'issue', 'other') NOT NULL;
