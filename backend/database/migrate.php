<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$db = database();
$db->exec(
    'CREATE TABLE IF NOT EXISTS password_reset_tokens (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB'
);

$db->exec(
    "ALTER TABLE animals MODIFY species ENUM('cattle', 'sheep', 'goat', 'rabbit', 'chicken', 'other') NOT NULL"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS products (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(140) NOT NULL,
        product_type ENUM('livestock', 'produce', 'other') NOT NULL,
        production_kind ENUM('milk', 'eggs', 'other') NULL,
        species ENUM('cattle', 'sheep', 'goat', 'rabbit', 'chicken', 'other') NULL,
        unit VARCHAR(30) NOT NULL,
        available_quantity DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
        unit_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_products_active_type (is_active, product_type),
        INDEX idx_products_species (species)
    ) ENGINE=InnoDB"
);

$productProductionKind = $db->query("SHOW COLUMNS FROM products LIKE 'production_kind'")->fetch();
if (!$productProductionKind) {
    $db->exec("ALTER TABLE products ADD COLUMN production_kind ENUM('milk', 'eggs', 'other') NULL AFTER product_type");
}

$productionKindIndex = $db->query(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'uq_products_production_kind'"
)->fetchColumn();
if (!$productionKindIndex) {
    $db->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_production_kind (production_kind)');
}

$orderItemProduct = $db->query("SHOW COLUMNS FROM order_items LIKE 'product_id'")->fetch();
if (!$orderItemProduct) {
    $db->exec('ALTER TABLE order_items ADD COLUMN product_id BIGINT UNSIGNED NULL AFTER order_id');
}

$productForeignKey = $db->query(
    "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND CONSTRAINT_NAME = 'fk_order_items_product'"
)->fetchColumn();
if (!$productForeignKey) {
    $db->exec(
        'ALTER TABLE order_items ADD CONSTRAINT fk_order_items_product
         FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL'
    );
}

$db->exec(
    'CREATE TABLE IF NOT EXISTS order_animals (
        order_id BIGINT UNSIGNED NOT NULL,
        animal_id BIGINT UNSIGNED NOT NULL UNIQUE,
        PRIMARY KEY (order_id, animal_id),
        CONSTRAINT fk_order_animals_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_order_animals_animal FOREIGN KEY (animal_id) REFERENCES animals (id)
    ) ENGINE=InnoDB'
);

$db->exec("ALTER TABLE products MODIFY production_kind ENUM('milk', 'eggs', 'wool', 'other') NULL");

if (!$db->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch()) {
    $db->exec('ALTER TABLE users ADD COLUMN username VARCHAR(30) NULL UNIQUE AFTER full_name');
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS admin_transfer_invites (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invited_by BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        new_role_for_current VARCHAR(40) NOT NULL,
        status ENUM('pending', 'confirmed', 'approved', 'cancelled') NOT NULL DEFAULT 'pending',
        recipient_user_id BIGINT UNSIGNED NULL,
        created_user TINYINT(1) NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        confirmed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_invites_status (status, expires_at),
        CONSTRAINT fk_admin_invites_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_admin_invites_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB"
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS wool_production (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        production_date DATE NOT NULL,
        quantity_kg DECIMAL(9,2) UNSIGNED NOT NULL,
        notes VARCHAR(500) NULL,
        recorded_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wool_date (production_date),
        CONSTRAINT fk_wool_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB'
);

$db->exec(
    "INSERT IGNORE INTO roles (name, description) VALUES
        ('admin', 'Full farm management access'),
        ('worker', 'Daily farm activity and production records'),
        ('veterinarian', 'Animal health records'),
        ('customer', 'Farm produce purchases and feedback'),
        ('buyer', 'Livestock purchases')"
);

fwrite(STDOUT, "LFMIS database migrations are ready.\n");