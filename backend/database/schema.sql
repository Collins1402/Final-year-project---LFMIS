-- Flouri Farm LFMIS: complete schema (MySQL 8.4, utf8mb4).
-- Load once into an empty database. Later changes go in database/migrations/.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- Access

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(120) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL,
  description VARCHAR(255) NOT NULL,
  CONSTRAINT uq_roles_name UNIQUE (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id TINYINT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(30) NOT NULL,
  email VARCHAR(190) NOT NULL,
  email_verified_at DATETIME NULL,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_users_username UNIQUE (username),
  CONSTRAINT uq_users_email UNIQUE (email),
  INDEX idx_users_email_verified (email_verified_at, is_active),
  INDEX idx_users_role_active (role_id, is_active),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_email_verification_token UNIQUE (token_hash),
  CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_password_reset_token UNIQUE (token_hash),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_transfer_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invited_by BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  new_role_for_current VARCHAR(30) NOT NULL,
  status ENUM('pending', 'confirmed', 'approved', 'cancelled') NOT NULL DEFAULT 'pending',
  recipient_user_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_admin_invites_token UNIQUE (token_hash),
  INDEX idx_admin_invites_status (status, expires_at),
  CONSTRAINT fk_admin_invites_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_invites_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Livestock

CREATE TABLE IF NOT EXISTS animals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag_number VARCHAR(40) NOT NULL,
  species ENUM('cattle', 'goat', 'rabbit', 'chicken', 'sheep', 'other') NOT NULL,
  breed VARCHAR(80) NULL,
  name VARCHAR(80) NULL,
  sex ENUM('female', 'male') NOT NULL,
  date_of_birth DATE NULL,
  weight_kg DECIMAL(7,2) NULL,
  status ENUM('active', 'sold', 'deceased', 'transferred') NOT NULL DEFAULT 'active',
  status_changed_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_animals_tag UNIQUE (tag_number),
  CONSTRAINT chk_animals_weight CHECK (weight_kg IS NULL OR weight_kg > 0),
  INDEX idx_animals_species_status (species, status),
  INDEX idx_animals_status (status),
  CONSTRAINT fk_animals_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  animal_id BIGINT UNSIGNED NOT NULL,
  record_type ENUM('vaccination', 'treatment', 'deworming', 'checkup', 'illness', 'other') NOT NULL,
  description VARCHAR(255) NOT NULL,
  medication VARCHAR(120) NULL,
  dosage VARCHAR(60) NULL,
  cost DECIMAL(12,2) NULL,
  record_date DATE NOT NULL,
  next_due_date DATE NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_health_cost CHECK (cost IS NULL OR cost >= 0),
  CONSTRAINT chk_health_due CHECK (next_due_date IS NULL OR next_due_date >= record_date),
  INDEX idx_health_animal_date (animal_id, record_date),
  INDEX idx_health_next_due (next_due_date),
  CONSTRAINT fk_health_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE RESTRICT,
  CONSTRAINT fk_health_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS farm_activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_type ENUM('feeding', 'milking', 'cleaning', 'breeding', 'health', 'maintenance', 'other') NOT NULL,
  description VARCHAR(255) NOT NULL,
  animal_id BIGINT UNSIGNED NULL,
  assigned_to BIGINT UNSIGNED NULL,
  activity_at DATETIME NOT NULL,
  status ENUM('planned', 'completed', 'cancelled') NOT NULL DEFAULT 'planned',
  completed_at DATETIME NULL,
  completed_by BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activities_status_date (status, activity_at),
  INDEX idx_activities_assigned (assigned_to, status),
  CONSTRAINT fk_activities_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE SET NULL,
  CONSTRAINT fk_activities_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_activities_completed_by FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_name VARCHAR(120) NOT NULL,
  asset_type ENUM('equipment', 'housing', 'water', 'vehicle', 'other') NOT NULL,
  maintenance_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  next_due_date DATE NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_maintenance_cost CHECK (cost >= 0),
  INDEX idx_maintenance_date (maintenance_date),
  INDEX idx_maintenance_next_due (next_due_date),
  CONSTRAINT fk_maintenance_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Produce

CREATE TABLE IF NOT EXISTS milk_production (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_date DATE NOT NULL,
  milking_session ENUM('morning', 'evening') NOT NULL,
  animal_id BIGINT UNSIGNED NULL,
  -- 0 means a herd-level total, so the unique key also blocks duplicate herd entries.
  animal_key BIGINT UNSIGNED AS (IFNULL(animal_id, 0)) STORED,
  quantity_litres DECIMAL(8,2) NOT NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_milk_date_session_animal UNIQUE (production_date, milking_session, animal_key),
  CONSTRAINT chk_milk_quantity CHECK (quantity_litres >= 0),
  INDEX idx_milk_date (production_date),
  CONSTRAINT fk_milk_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE RESTRICT,
  CONSTRAINT fk_milk_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS egg_production (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_date DATE NOT NULL,
  eggs_collected INT UNSIGNED NOT NULL,
  eggs_broken INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_egg_date UNIQUE (production_date),
  CONSTRAINT chk_egg_broken CHECK (eggs_broken <= eggs_collected),
  CONSTRAINT fk_egg_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wool_production (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_date DATE NOT NULL,
  quantity_kg DECIMAL(8,2) NOT NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_wool_quantity CHECK (quantity_kg > 0),
  INDEX idx_wool_date (production_date),
  CONSTRAINT fk_wool_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Stock

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category ENUM('feed', 'medicine', 'equipment', 'supplies', 'other') NOT NULL,
  unit VARCHAR(30) NOT NULL,
  quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_inventory_name UNIQUE (name),
  CONSTRAINT chk_inventory_quantities CHECK (quantity_on_hand >= 0 AND reorder_level >= 0),
  INDEX idx_inventory_active_category (is_active, category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM('in', 'out', 'adjustment') NOT NULL,
  -- Signed: negative for 'out' and downward adjustments.
  quantity_change DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NULL,
  reason VARCHAR(255) NULL,
  transaction_date DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_inventory_tx_nonzero CHECK (quantity_change <> 0),
  INDEX idx_inventory_tx_item_date (item_id, transaction_date),
  CONSTRAINT fk_inventory_tx_item FOREIGN KEY (item_id) REFERENCES inventory_items (id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_tx_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Commerce

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_customers_user UNIQUE (user_id),
  INDEX idx_customers_email (email),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  product_type ENUM('livestock', 'produce', 'other') NOT NULL,
  production_kind ENUM('milk', 'eggs', 'wool') NULL,
  species ENUM('cattle', 'goat', 'rabbit', 'chicken', 'sheep', 'other') NULL,
  unit VARCHAR(30) NOT NULL,
  available_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_products_production_kind UNIQUE (production_kind),
  CONSTRAINT chk_products_amounts CHECK (available_quantity >= 0 AND unit_price >= 0),
  INDEX idx_products_active_type (is_active, product_type),
  INDEX idx_products_species (species)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending', 'quoted', 'accepted', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  handled_by BIGINT UNSIGNED NULL,
  ordered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  fulfilled_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_orders_total CHECK (total_amount >= 0),
  INDEX idx_orders_status_date (status, ordered_at),
  INDEX idx_orders_customer (customer_id, ordered_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_handled_by FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(140) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT chk_order_items_amounts CHECK (quantity > 0 AND unit_price >= 0),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_animals (
  order_id BIGINT UNSIGNED NOT NULL,
  animal_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (order_id, animal_id),
  CONSTRAINT uq_order_animals_animal UNIQUE (animal_id),
  CONSTRAINT fk_order_animals_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_animals_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  total_amount DECIMAL(12,2) AS (ROUND(quantity * unit_price, 2)) STORED,
  sold_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_sales_amounts CHECK (quantity > 0 AND unit_price >= 0),
  INDEX idx_sales_date (sold_at),
  CONSTRAINT fk_sales_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash', 'mpesa', 'bank', 'other') NOT NULL,
  status ENUM('pending', 'confirmed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  transaction_reference VARCHAR(120) NULL,
  checkout_request_id VARCHAR(80) NULL,
  merchant_request_id VARCHAR(80) NULL,
  phone VARCHAR(20) NULL,
  result_desc VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_payments_reference UNIQUE (transaction_reference),
  CONSTRAINT uq_payments_checkout UNIQUE (checkout_request_id),
  CONSTRAINT chk_payments_amount CHECK (amount > 0),
  INDEX idx_payments_status_date (status, created_at),
  INDEX idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('feed', 'veterinary', 'labour', 'equipment', 'utilities', 'transport', 'maintenance', 'other') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_expenses_amount CHECK (amount > 0),
  INDEX idx_expenses_date (expense_date),
  CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Service

CREATE TABLE IF NOT EXISTS feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  category ENUM('feedback', 'complaint', 'suggestion') NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_feedback_status_date (status, created_at),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_feedback_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('health_due', 'low_stock', 'order_pending', 'payment_pending', 'production_drop', 'maintenance_due', 'system') NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id BIGINT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  message VARCHAR(500) NOT NULL,
  -- type:entity:id:date, so the daily generator never creates duplicates.
  dedupe_key VARCHAR(160) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_notifications_user_dedupe UNIQUE (user_id, dedupe_key),
  INDEX idx_notifications_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_user_date (user_id, created_at),
  INDEX idx_audit_date (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- ML support

CREATE TABLE IF NOT EXISTS weather_observations (
  observation_date DATE PRIMARY KEY,
  rain_mm DECIMAL(6,2) NULL,
  temp_c DECIMAL(5,2) NULL,
  source VARCHAR(60) NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_weather_rain CHECK (rain_mm IS NULL OR rain_mm >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS forecast_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  method VARCHAR(120) NOT NULL,
  training_days INT UNSIGNED NOT NULL,
  backtest_days INT UNSIGNED NOT NULL,
  mae_litres DECIMAL(8,3) NOT NULL,
  rmse_litres DECIMAL(8,3) NULL,
  baseline_mae_litres DECIMAL(8,3) NOT NULL,
  uses_weather TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  requested_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_forecast_runs_date (created_at),
  CONSTRAINT fk_forecast_runs_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- Seed

INSERT IGNORE INTO roles (name, description) VALUES
  ('admin', 'Farm owner: full management access'),
  ('worker', 'Daily farm activities, production and stock records'),
  ('veterinarian', 'Animal health records and follow-ups'),
  ('customer', 'Buys farm produce and livestock, tracks orders and payments');
