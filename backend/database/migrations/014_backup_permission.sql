INSERT IGNORE INTO permissions (code, label) VALUES ('backup.create', 'Create database backup');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'backup.create', 1 FROM roles WHERE roles.name = 'admin';
