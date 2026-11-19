ALTER TABLE orders
  MODIFY status ENUM('pending', 'quoted', 'accepted', 'paid', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending';
