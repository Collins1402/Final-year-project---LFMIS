CREATE DATABASE IF NOT EXISTS lfmis
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lfmis;

CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id SMALLINT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS animals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag_number VARCHAR(80) NULL UNIQUE,
  species ENUM('cattle', 'goat', 'rabbit', 'chicken', 'other') NOT NULL,
  breed VARCHAR(100) NULL,
  name VARCHAR(100) NULL,
  sex ENUM('female', 'male', 'unknown') NOT NULL DEFAULT 'unknown',
  date_of_birth DATE NULL,
  weight_kg DECIMAL(8,2) UNSIGNED NULL,
  status ENUM('active', 'sold', 'deceased', 'transferred') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_animals_species_status (species, status),
  CONSTRAINT fk_animals_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  animal_id BIGINT UNSIGNED NOT NULL,
  record_type ENUM('vaccination', 'treatment', 'deworming', 'diagnosis', 'medication', 'incident', 'other') NOT NULL,
  recorded_at DATETIME NOT NULL,
  next_due_at DATETIME NULL,
  description TEXT NOT NULL,
  veterinarian VARCHAR(120) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_health_animal_date (animal_id, recorded_at),
  INDEX idx_health_due (next_due_at),
  CONSTRAINT fk_health_animal FOREIGN KEY (animal_id) REFERENCES animals (id),
  CONSTRAINT fk_health_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS farm_activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_type VARCHAR(60) NOT NULL,
  description TEXT NOT NULL,
  activity_at DATETIME NOT NULL,
  status ENUM('planned', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
  animal_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_date_status (activity_at, status),
  CONSTRAINT fk_activity_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE SET NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS milk_production (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_date DATE NOT NULL,
  milking_session ENUM('morning', 'evening', 'other') NOT NULL,
  quantity_litres DECIMAL(9,2) UNSIGNED NOT NULL,
  animal_id BIGINT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_milk_animal_session (production_date, milking_session, animal_id),
  INDEX idx_milk_date (production_date),
  CONSTRAINT fk_milk_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE SET NULL,
  CONSTRAINT fk_milk_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS egg_production (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_date DATE NOT NULL,
  quantity_trays DECIMAL(9,2) UNSIGNED NOT NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eggs_date (production_date),
  CONSTRAINT fk_eggs_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  unit VARCHAR(30) NOT NULL,
  quantity_on_hand DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  reorder_level DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM('received', 'used', 'adjustment', 'waste') NOT NULL,
  quantity DECIMAL(10,2) UNSIGNED NOT NULL,
  transaction_at DATETIME NOT NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_transaction_date (transaction_at),
  CONSTRAINT fk_inventory_transaction_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id),
  CONSTRAINT fk_inventory_transaction_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customers_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending', 'accepted', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
  total_amount DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  ordered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_orders_status_date (status, ordered_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  quantity DECIMAL(10,2) UNSIGNED NOT NULL,
  unit VARCHAR(30) NOT NULL,
  unit_price DECIMAL(12,2) UNSIGNED NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) UNSIGNED NOT NULL,
  unit VARCHAR(30) NOT NULL,
  unit_price DECIMAL(12,2) UNSIGNED NOT NULL,
  sold_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sales_date (sold_at),
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) UNSIGNED NOT NULL,
  method ENUM('cash', 'mpesa', 'bank', 'other') NOT NULL,
  status ENUM('pending', 'confirmed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  transaction_reference VARCHAR(120) NULL UNIQUE,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_status_date (status, paid_at),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expenses_date (expense_date),
  CONSTRAINT fk_expenses_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NULL,
  category ENUM('complaint', 'feedback', 'suggestion', 'other') NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_feedback_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user_read (user_id, read_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_user_date (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name, description) VALUES
  ('admin', 'Full farm management access'),
  ('worker', 'Daily farm activity and production records'),
  ('veterinarian', 'Animal health records'),
  ('customer', 'Customer orders and feedback');