-- File purpose: applies the 019_feedback_notifications database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the notifications structure so it can store the capability introduced here.
ALTER TABLE notifications
  MODIFY COLUMN type ENUM('health_due', 'low_stock', 'order_pending', 'payment_pending', 'production_drop', 'maintenance_due', 'feedback', 'system') NOT NULL;
