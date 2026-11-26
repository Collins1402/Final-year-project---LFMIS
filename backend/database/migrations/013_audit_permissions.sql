-- File purpose: applies the 013_audit_permissions database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO permissions (code, label) VALUES
  ('audit.view', 'View audit log'),
  ('data.export', 'Export farm data');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, permissions.code, 1
FROM roles CROSS JOIN permissions
WHERE roles.name = 'admin' AND permissions.code IN ('audit.view', 'data.export');
