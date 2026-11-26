-- File purpose: applies the 010_order_paid_status database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the orders structure so it can store the capability introduced here.
ALTER TABLE orders
  MODIFY status ENUM('pending', 'quoted', 'accepted', 'paid', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending';
