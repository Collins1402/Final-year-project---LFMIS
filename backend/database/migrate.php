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

fwrite(STDOUT, "Password reset schema is ready.\n");