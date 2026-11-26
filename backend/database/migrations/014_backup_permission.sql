-- File purpose: applies the 014_backup_permission database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO permissions (code, label) VALUES ('backup.create', 'Create database backup');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'backup.create', 1 FROM roles WHERE roles.name = 'admin';
