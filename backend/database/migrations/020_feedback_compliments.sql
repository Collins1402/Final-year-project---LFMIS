ALTER TABLE feedback
  MODIFY COLUMN category ENUM('feedback', 'complaint', 'suggestion', 'compliment') NOT NULL;
