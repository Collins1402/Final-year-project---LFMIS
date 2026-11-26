-- File purpose: applies the 003_unified_farm_produce database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Adds or defines the IF records required by this migration.
CREATE TABLE IF NOT EXISTS farm_produce (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produce_name VARCHAR(120) NOT NULL,
  production_date DATE NOT NULL,
  production_session VARCHAR(30) NULL,
  animal_id BIGINT UNSIGNED NULL,
  quantity_produced DECIMAL(12,2) NOT NULL,
  quantity_sold DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity_damaged DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit VARCHAR(30) NOT NULL,
  notes VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_farm_produce_amounts CHECK (quantity_produced >= 0 AND quantity_sold >= 0 AND quantity_damaged >= 0 AND quantity_sold + quantity_damaged <= quantity_produced),
  INDEX idx_farm_produce_date_name (production_date, produce_name),
  CONSTRAINT fk_farm_produce_animal FOREIGN KEY (animal_id) REFERENCES animals (id) ON DELETE SET NULL,
  CONSTRAINT fk_farm_produce_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @milk_copy_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'milk_production'),
  'INSERT INTO farm_produce (produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, recorded_by, created_at) SELECT ''Milk'', source.production_date, source.milking_session, source.animal_id, source.quantity_litres, 0, 0, ''litres'', source.notes, source.recorded_by, source.created_at FROM milk_production source WHERE NOT EXISTS (SELECT 1 FROM farm_produce target WHERE target.produce_name = ''Milk'' AND target.production_date = source.production_date AND target.production_session = source.milking_session AND target.animal_id <=> source.animal_id AND target.quantity_produced = source.quantity_litres AND target.unit = ''litres'')',
  'SELECT 1'
);
PREPARE copy_milk FROM @milk_copy_sql;
EXECUTE copy_milk;
DEALLOCATE PREPARE copy_milk;

SET @egg_quantity_expression = IF(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'egg_production' AND column_name = 'quantity_trays'),
  'source.quantity_trays',
  'source.eggs_collected'
);
SET @egg_damaged_expression = IF(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'egg_production' AND column_name = 'eggs_broken'),
  'source.eggs_broken',
  '0'
);
SET @egg_unit = IF(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'egg_production' AND column_name = 'quantity_trays'),
  'trays',
  'eggs'
);
SET @egg_copy_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'egg_production'),
  CONCAT('INSERT INTO farm_produce (produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, recorded_by, created_at) SELECT ''Eggs'', source.production_date, NULL, NULL, ', @egg_quantity_expression, ', 0, ', @egg_damaged_expression, ', ''', @egg_unit, ''', source.notes, source.recorded_by, source.created_at FROM egg_production source WHERE NOT EXISTS (SELECT 1 FROM farm_produce target WHERE target.produce_name = ''Eggs'' AND target.production_date = source.production_date AND target.quantity_produced = ', @egg_quantity_expression, ' AND target.quantity_damaged = ', @egg_damaged_expression, ' AND target.unit = ''', @egg_unit, ''')'),
  'SELECT 1'
);
PREPARE copy_eggs FROM @egg_copy_sql;
EXECUTE copy_eggs;
DEALLOCATE PREPARE copy_eggs;

SET @wool_copy_sql = IF(
  EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wool_production'),
  'INSERT INTO farm_produce (produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, recorded_by, created_at) SELECT ''Wool'', source.production_date, NULL, NULL, source.quantity_kg, 0, 0, ''kg'', source.notes, source.recorded_by, source.created_at FROM wool_production source WHERE NOT EXISTS (SELECT 1 FROM farm_produce target WHERE target.produce_name = ''Wool'' AND target.production_date = source.production_date AND target.quantity_produced = source.quantity_kg AND target.unit = ''kg'')',
  'SELECT 1'
);
PREPARE copy_wool FROM @wool_copy_sql;
EXECUTE copy_wool;
DEALLOCATE PREPARE copy_wool;

DROP TABLE IF EXISTS milk_production;
DROP TABLE IF EXISTS egg_production;
DROP TABLE IF EXISTS wool_production;