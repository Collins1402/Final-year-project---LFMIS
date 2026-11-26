-- File purpose: applies the 002_buyer_role database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
INSERT IGNORE INTO roles (name, description)
VALUES ('buyer', 'Purchases livestock and tracks livestock orders');
