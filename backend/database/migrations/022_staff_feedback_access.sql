-- File purpose: applies the 022_staff_feedback_access database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, permissions.code, 1
FROM roles CROSS JOIN permissions
WHERE roles.name IN ('worker', 'veterinarian')
  AND permissions.code IN ('feedback.view', 'feedback.create');
