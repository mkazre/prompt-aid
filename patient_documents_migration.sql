CREATE TABLE patient_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_profile_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(255) NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT patient_documents_patient_profile_id_foreign FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id) ON DELETE CASCADE
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_120000_create_patient_documents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
