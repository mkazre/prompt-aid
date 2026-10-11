CREATE TABLE provider_applications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('clinic','doctor','pharmacy','third_party') NOT NULL,
  business_name VARCHAR(255) NOT NULL,
  contact_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(255) NOT NULL,
  registration_no VARCHAR(255) NULL,
  hpcsa_sapc_no VARCHAR(255) NULL,
  status ENUM('submitted','under_review','approved','rejected') NOT NULL DEFAULT 'submitted',
  rejection_reason TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT provider_applications_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE provider_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_application_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(255) NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT provider_documents_provider_application_id_foreign FOREIGN KEY (provider_application_id) REFERENCES provider_applications (id) ON DELETE CASCADE
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_130000_create_provider_applications_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_130001_create_provider_documents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
