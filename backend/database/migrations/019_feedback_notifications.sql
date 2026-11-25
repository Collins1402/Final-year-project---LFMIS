ALTER TABLE notifications
  MODIFY COLUMN type ENUM('health_due', 'low_stock', 'order_pending', 'payment_pending', 'production_drop', 'maintenance_due', 'feedback', 'system') NOT NULL;
