CREATE TABLE consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(50) NOT NULL,
  version VARCHAR(20) NULL,
  legal_document_id BIGINT UNSIGNED NULL,
  granted_at TIMESTAMP NULL,
  withdrawn_at TIMESTAMP NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  channel ENUM('web', 'app', 'admin') NOT NULL DEFAULT 'web',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY consents_user_id_foreign (user_id),
  KEY consents_user_id_purpose_withdrawn_at_index (user_id, purpose, withdrawn_at),
  KEY consents_purpose_index (purpose),
  CONSTRAINT consents_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE legal_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose VARCHAR(50) NOT NULL,
  version VARCHAR(20) NOT NULL,
  title VARCHAR(255) NOT NULL,
  page_slug VARCHAR(255) NULL,
  summary_of_changes TEXT NULL,
  effective_at TIMESTAMP NULL,
  published_at TIMESTAMP NULL,
  requires_reconsent TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY legal_documents_purpose_version_unique (purpose, version),
  KEY legal_documents_purpose_published_at_effective_at_index (purpose, published_at, effective_at),
  KEY legal_documents_created_by_foreign (created_by),
  CONSTRAINT legal_documents_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
);

-- v1 of each required document, requires_reconsent = 0: deploying this does NOT
-- prompt existing users. They are prompted only when an admin publishes a NEW
-- version with "Require re-consent" switched on (Staff -> Privacy -> Legal documents).
INSERT INTO legal_documents (purpose, version, title, page_slug, summary_of_changes, effective_at, published_at, requires_reconsent, created_at, updated_at) VALUES
('terms', '1', 'Terms of use', 'terms', 'Initial version.', NOW(), NOW(), 0, NOW(), NOW()),
('privacy', '1', 'Privacy policy', 'privacy', 'Initial version.', NOW(), NOW(), 0, NOW(), NOW()),
('health_data_processing', '1', 'POPIA notice: processing of health information', 'legal-popia', 'Initial version.', NOW(), NOW(), 0, NOW(), NOW());

CREATE TABLE dsr_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  type ENUM('access', 'correction', 'deletion', 'objection') NOT NULL,
  status ENUM('received', 'in_progress', 'completed', 'rejected') NOT NULL DEFAULT 'received',
  details TEXT NULL,
  channel ENUM('web', 'app', 'admin') NOT NULL DEFAULT 'web',
  due_at TIMESTAMP NULL,
  handled_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  retention_summary TEXT NULL,
  export_path VARCHAR(255) NULL,
  export_size BIGINT UNSIGNED NULL,
  export_expires_at TIMESTAMP NULL,
  anonymised_at TIMESTAMP NULL,
  retain_clinical_until DATE NULL,
  fully_anonymised_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY dsr_requests_user_id_foreign (user_id),
  KEY dsr_requests_handled_by_foreign (handled_by),
  KEY dsr_requests_status_due_at_index (status, due_at),
  KEY dsr_requests_user_id_type_index (user_id, type),
  CONSTRAINT dsr_requests_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT dsr_requests_handled_by_foreign FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE data_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  role VARCHAR(50) NULL,
  subject_type VARCHAR(100) NOT NULL,
  subject_id BIGINT UNSIGNED NULL,
  patient_profile_id BIGINT UNSIGNED NULL,
  action ENUM('view', 'export', 'update') NOT NULL DEFAULT 'view',
  context VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NULL,
  KEY data_access_logs_user_id_foreign (user_id),
  KEY data_access_logs_patient_profile_id_created_at_index (patient_profile_id, created_at),
  KEY data_access_logs_subject_type_subject_id_index (subject_type, subject_id),
  KEY data_access_logs_user_id_created_at_index (user_id, created_at),
  KEY data_access_logs_created_at_index (created_at),
  CONSTRAINT data_access_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE security_incidents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NULL,
  occurred_at TIMESTAMP NULL,
  discovered_at TIMESTAMP NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  data_affected TEXT NULL,
  users_affected_count INT UNSIGNED NOT NULL DEFAULT 0,
  severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  status ENUM('open', 'investigating', 'contained', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  regulator_notified_at TIMESTAMP NULL,
  users_notified_at TIMESTAMP NULL,
  remediation TEXT NULL,
  reported_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY security_incidents_reference_unique (reference),
  KEY security_incidents_status_occurred_at_index (status, occurred_at),
  KEY security_incidents_reported_by_foreign (reported_by),
  CONSTRAINT security_incidents_reported_by_foreign FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL
);

INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_io_name', '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_io_name');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_io_email', '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_io_email');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_io_phone', '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_io_phone');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_io_show_in_footer', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_io_show_in_footer');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_regulator_contact', 'Information Regulator (South Africa): enquiries@inforegulator.org.za / complaints.IR@inforegulator.org.za', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_regulator_contact');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_clinical_retention_years', '6', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_clinical_retention_years');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_messages_days', '2190', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_messages_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_notifications_days', '180', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_notifications_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_audit_logs_days', '1825', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_audit_logs_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_access_logs_days', '1825', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_access_logs_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_dsr_exports_days', '7', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_dsr_exports_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_retention_anon_triage_days', '90', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_retention_anon_triage_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_dsr_sla_days', '30', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_dsr_sla_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_export_link_minutes', '30', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_export_link_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_cookie_notice_enabled', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_cookie_notice_enabled');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'privacy_cookie_notice_text', 'We only use essential cookies to keep you signed in and keep the site secure. We do not use advertising or tracking cookies.', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'privacy_cookie_notice_text');

INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200000_create_consents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200001_create_legal_documents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200002_create_dsr_requests_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200003_create_data_access_logs_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200004_create_security_incidents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_200005_seed_privacy_settings', COALESCE(MAX(batch), 0) + 1 FROM migrations;
