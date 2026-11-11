INSERT IGNORE INTO permissions (code, label) VALUES ('scenarios.run', 'Run 30-day yield scenarios');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'scenarios.run', 1 FROM roles WHERE roles.name IN ('admin', 'worker');