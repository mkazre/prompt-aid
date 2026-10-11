CREATE TABLE service_service_category (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id BIGINT UNSIGNED NOT NULL,
  service_category_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY service_service_category_unique (service_id, service_category_id),
  CONSTRAINT ssc_service_id_foreign FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE,
  CONSTRAINT ssc_service_category_id_foreign FOREIGN KEY (service_category_id) REFERENCES service_categories (id) ON DELETE CASCADE
);

CREATE TABLE product_service_category (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  service_category_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY product_service_category_unique (product_id, service_category_id),
  CONSTRAINT psc_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT psc_service_category_id_foreign FOREIGN KEY (service_category_id) REFERENCES service_categories (id) ON DELETE CASCADE
);

-- Backfill: create any missing categories from existing free-text values (services)
INSERT INTO service_categories (name, slug, created_at, updated_at)
SELECT DISTINCT TRIM(s.category), LOWER(REPLACE(REPLACE(TRIM(s.category), ' ', '-'), '/', '-')), NOW(), NOW()
FROM services s
WHERE s.category IS NOT NULL AND TRIM(s.category) != ''
  AND NOT EXISTS (SELECT 1 FROM service_categories sc WHERE LOWER(sc.name) = LOWER(TRIM(s.category)));

-- Backfill: create any missing categories from existing free-text values (products)
INSERT INTO service_categories (name, slug, created_at, updated_at)
SELECT DISTINCT TRIM(p.category), LOWER(REPLACE(REPLACE(TRIM(p.category), ' ', '-'), '/', '-')), NOW(), NOW()
FROM products p
WHERE p.category IS NOT NULL AND TRIM(p.category) != ''
  AND NOT EXISTS (SELECT 1 FROM service_categories sc WHERE LOWER(sc.name) = LOWER(TRIM(p.category)));

-- Attach services to categories via their old FK column
INSERT IGNORE INTO service_service_category (service_id, service_category_id, created_at, updated_at)
SELECT s.id, s.service_category_id, NOW(), NOW()
FROM services s
WHERE s.service_category_id IS NOT NULL;

-- Attach services to categories via their old free-text column
INSERT IGNORE INTO service_service_category (service_id, service_category_id, created_at, updated_at)
SELECT s.id, sc.id, NOW(), NOW()
FROM services s
JOIN service_categories sc ON LOWER(sc.name) = LOWER(TRIM(s.category))
WHERE s.category IS NOT NULL AND TRIM(s.category) != '';

-- Attach products to categories via their old FK column
INSERT IGNORE INTO product_service_category (product_id, service_category_id, created_at, updated_at)
SELECT p.id, p.service_category_id, NOW(), NOW()
FROM products p
WHERE p.service_category_id IS NOT NULL;

-- Attach products to categories via their old free-text column
INSERT IGNORE INTO product_service_category (product_id, service_category_id, created_at, updated_at)
SELECT p.id, sc.id, NOW(), NOW()
FROM products p
JOIN service_categories sc ON LOWER(sc.name) = LOWER(TRIM(p.category))
WHERE p.category IS NOT NULL AND TRIM(p.category) != '';

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_090000_create_service_category_pivot_tables', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_090001_backfill_service_category_pivots', COALESCE(MAX(batch), 0) + 1 FROM migrations;
