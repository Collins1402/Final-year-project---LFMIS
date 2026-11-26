-- File purpose: applies the 021_livestock_listings_and_payment_methods database change while upgrading an existing LFMIS installation.
-- The database migration runner applies this file after the preceding migration.
-- It depends on the earlier schema and preserves existing records while adding this capability.
-- Updates the payments structure so it can store the capability introduced here.
ALTER TABLE payments
  MODIFY method ENUM('cash', 'mpesa', 'bank', 'other', 'pay_on_premise', 'cheque') NOT NULL;

INSERT INTO products (name, product_type, production_kind, species, unit, available_quantity, unit_price, is_active)
SELECT listing.name, 'livestock', NULL, listing.species, 'head', 0, listing.unit_price, 1
FROM (
  SELECT 'SYN-Demo cattle' AS name, 'cattle' AS species, 75000 AS unit_price
  UNION ALL SELECT 'SYN-Demo goat', 'goat', 12000
  UNION ALL SELECT 'SYN-Demo sheep', 'sheep', 15000
  UNION ALL SELECT 'SYN-Demo rabbit', 'rabbit', 4000
  UNION ALL SELECT 'SYN-Demo chicken', 'chicken', 2000
) AS listing
WHERE EXISTS (SELECT 1 FROM animals WHERE animals.species = listing.species AND animals.status = 'active')
  AND NOT EXISTS (
    SELECT 1 FROM products
    WHERE products.product_type = 'livestock'
      AND products.species = listing.species
      AND products.name = listing.name
  );
