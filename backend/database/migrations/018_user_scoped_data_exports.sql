INSERT IGNORE INTO permissions (code, label) VALUES ('data.export', 'Export role-accessible data');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'data.export', 1
FROM roles
WHERE roles.name IN ('worker', 'veterinarian', 'customer', 'buyer');
