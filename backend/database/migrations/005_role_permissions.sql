CREATE TABLE IF NOT EXISTS permissions (
  code VARCHAR(60) PRIMARY KEY,
  label VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_code VARCHAR(60) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (role_id, permission_code),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (code, label) VALUES
  ('animals.view', 'View livestock'),
  ('animals.create', 'Register livestock'),
  ('animals.status', 'Update livestock status'),
  ('health.view', 'View animal health'),
  ('health.record', 'Record animal care'),
  ('users.manage', 'Manage user accounts'),
  ('roles.manage', 'Manage role permissions'),
  ('events.manage', 'Manage farm events'),
  ('activities.view', 'View activities'),
  ('activities.create', 'Create activities'),
  ('activities.complete', 'Complete activities'),
  ('production.view', 'View production'),
  ('production.record', 'Record production'),
  ('inventory.view', 'View inventory'),
  ('inventory.manage', 'Manage inventory items'),
  ('inventory.transact', 'Record inventory movements'),
  ('products.view', 'View listings'),
  ('products.manage', 'Manage listings'),
  ('orders.view', 'View orders'),
  ('orders.create', 'Create orders'),
  ('orders.manage', 'Manage orders'),
  ('payments.view', 'View payments'),
  ('finance.view', 'View sales and expenses'),
  ('finance.manage', 'Record sales, expenses and payments'),
  ('reports.finance', 'View financial reports'),
  ('reports.operations', 'View operational reports'),
  ('reports.forecast', 'View production forecasts'),
  ('assistant.use', 'Use the farm assistant'),
  ('demo.manage', 'Load demonstration data'),
  ('notifications.view', 'View notifications'),
  ('feedback.view', 'View feedback'),
  ('feedback.create', 'Submit feedback'),
  ('feedback.resolve', 'Resolve feedback');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, permissions.code, 1 FROM roles CROSS JOIN permissions WHERE roles.name = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, permissions.code, 1 FROM roles CROSS JOIN permissions
WHERE (roles.name = 'worker' AND permissions.code IN ('animals.view', 'animals.create', 'animals.status', 'health.view', 'activities.view', 'activities.create', 'activities.complete', 'production.view', 'production.record', 'inventory.view', 'inventory.transact', 'assistant.use', 'notifications.view'))
   OR (roles.name = 'veterinarian' AND permissions.code IN ('animals.view', 'health.view', 'health.record', 'activities.view', 'assistant.use', 'notifications.view'))
   OR (roles.name IN ('customer', 'buyer') AND permissions.code IN ('products.view', 'orders.view', 'orders.create', 'payments.view', 'feedback.view', 'feedback.create', 'assistant.use', 'notifications.view'));