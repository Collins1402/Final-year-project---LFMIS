INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, permissions.code, 1
FROM roles CROSS JOIN permissions
WHERE roles.name IN ('worker', 'veterinarian')
  AND permissions.code IN ('feedback.view', 'feedback.create');
