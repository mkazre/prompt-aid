ALTER TABLE claims ADD COLUMN resubmission_count INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE claim_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  CONSTRAINT claim_documents_claim_id_foreign FOREIGN KEY (claim_id) REFERENCES claims (id) ON DELETE CASCADE,
  CONSTRAINT claim_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_100000_add_resubmission_count_to_claims_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_100001_create_claim_documents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
