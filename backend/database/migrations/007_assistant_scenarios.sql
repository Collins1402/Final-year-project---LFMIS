-- File purpose: applies the 007_assistant_scenarios database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO permissions (code, label) VALUES ('scenarios.run', 'Run 30-day yield scenarios');

INSERT IGNORE INTO role_permissions (role_id, permission_code, is_allowed)
SELECT roles.id, 'scenarios.run', 1 FROM roles WHERE roles.name IN ('admin', 'worker');