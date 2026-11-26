-- File purpose: applies the 018_user_scoped_data_exports database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO permissions (code, label) VALUES ('data.export', 'Export role-accessible data');
UPDATE permissions SET label = 'Export role-accessible data' WHERE code = 'data.export';

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'data.export', 1
FROM roles
WHERE roles.name IN ('worker', 'veterinarian', 'customer', 'buyer');
