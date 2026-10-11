ALTER TABLE doctor_profiles ADD COLUMN last_seen_at TIMESTAMP NULL AFTER location_updated_at;
ALTER TABLE doctor_profiles ADD COLUMN last_offered_at TIMESTAMP NULL AFTER last_seen_at;
ALTER TABLE doctor_profiles ADD COLUMN last_assigned_at TIMESTAMP NULL AFTER last_offered_at;
ALTER TABLE doctor_profiles ADD KEY doctor_profiles_last_seen_at_index (last_seen_at);

CREATE TABLE doctor_match_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id BIGINT UNSIGNED NOT NULL,
  patient_profile_id BIGINT UNSIGNED NOT NULL,
  specialty VARCHAR(255) NOT NULL,
  mode VARCHAR(20) NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  status ENUM('searching', 'matched', 'expired', 'cancelled') NOT NULL DEFAULT 'searching',
  outcome_reason VARCHAR(255) NULL,
  base_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  multiplier DECIMAL(5,2) NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  price_breakdown TEXT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NULL,
  matched_at TIMESTAMP NULL,
  doctor_profile_id BIGINT UNSIGNED NULL,
  clinic_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  cancelled_at TIMESTAMP NULL,
  cancel_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY doctor_match_requests_appointment_id_unique (appointment_id),
  KEY doctor_match_requests_patient_profile_id_foreign (patient_profile_id),
  KEY doctor_match_requests_doctor_profile_id_foreign (doctor_profile_id),
  KEY doctor_match_requests_clinic_id_foreign (clinic_id),
  KEY doctor_match_requests_invoice_id_foreign (invoice_id),
  KEY doctor_match_requests_status_specialty_index (status, specialty),
  CONSTRAINT doctor_match_requests_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
  CONSTRAINT doctor_match_requests_patient_profile_id_foreign FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id) ON DELETE CASCADE,
  CONSTRAINT doctor_match_requests_doctor_profile_id_foreign FOREIGN KEY (doctor_profile_id) REFERENCES doctor_profiles (id) ON DELETE SET NULL,
  CONSTRAINT doctor_match_requests_clinic_id_foreign FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE SET NULL,
  CONSTRAINT doctor_match_requests_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL
);

CREATE TABLE doctor_match_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doctor_match_request_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NOT NULL,
  doctor_profile_id BIGINT UNSIGNED NOT NULL,
  status ENUM('offered', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'offered',
  distance_km DECIMAL(8,2) NULL,
  offered_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  responded_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY doctor_match_offers_doctor_match_request_id_foreign (doctor_match_request_id),
  KEY doctor_match_offers_appointment_id_foreign (appointment_id),
  KEY doctor_match_offers_doctor_profile_id_status_index (doctor_profile_id, status),
  KEY doctor_match_offers_status_expires_at_index (status, expires_at),
  CONSTRAINT doctor_match_offers_doctor_match_request_id_foreign FOREIGN KEY (doctor_match_request_id) REFERENCES doctor_match_requests (id) ON DELETE CASCADE,
  CONSTRAINT doctor_match_offers_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
  CONSTRAINT doctor_match_offers_doctor_profile_id_foreign FOREIGN KEY (doctor_profile_id) REFERENCES doctor_profiles (id) ON DELETE CASCADE
);

CREATE TABLE doctor_match_waitlist (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_profile_id BIGINT UNSIGNED NOT NULL,
  doctor_match_request_id BIGINT UNSIGNED NULL,
  specialty VARCHAR(255) NOT NULL,
  mode VARCHAR(20) NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  status ENUM('waiting', 'notified', 'cancelled', 'expired') NOT NULL DEFAULT 'waiting',
  notified_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY doctor_match_waitlist_patient_profile_id_foreign (patient_profile_id),
  KEY doctor_match_waitlist_doctor_match_request_id_foreign (doctor_match_request_id),
  KEY doctor_match_waitlist_status_specialty_index (status, specialty),
  CONSTRAINT doctor_match_waitlist_patient_profile_id_foreign FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id) ON DELETE CASCADE,
  CONSTRAINT doctor_match_waitlist_doctor_match_request_id_foreign FOREIGN KEY (doctor_match_request_id) REFERENCES doctor_match_requests (id) ON DELETE SET NULL
);

ALTER TABLE invoices MODIFY clinic_id BIGINT UNSIGNED NULL;

INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_enabled', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_enabled');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_offer_timeout_seconds', '45', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_offer_timeout_seconds');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_max_attempts', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_max_attempts');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_request_timeout_minutes', '10', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_request_timeout_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_heartbeat_stale_seconds', '120', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_heartbeat_stale_seconds');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_inperson_radius_km', '15', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_inperson_radius_km');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_location_stale_minutes', '60', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_location_stale_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_waitlist_hours', '4', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_waitlist_hours');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_timezone', 'Africa/Johannesburg', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_timezone');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_default_fee', '450', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_default_fee');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_specialty_fees', '{}', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_specialty_fees');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_time_bands', '[{"label":"After hours","days":[1,2,3,4,5],"start":"18:00","end":"07:00","multiplier":1.3},{"label":"Weekend","days":[0,6],"start":"00:00","end":"23:59","multiplier":1.4}]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_time_bands');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_public_holidays', '["2026-01-01","2026-03-21","2026-04-03","2026-04-06","2026-04-27","2026-05-01","2026-06-16","2026-08-09","2026-08-10","2026-09-24","2026-12-16","2026-12-25","2026-12-26","2027-01-01","2027-03-22","2027-03-26","2027-03-29","2027-04-27","2027-05-01","2027-06-16","2027-08-09","2027-09-24","2027-12-16","2027-12-25","2027-12-27"]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_public_holidays');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_holiday_multiplier', '1.5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_holiday_multiplier');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_surge_enabled', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_surge_enabled');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_surge_steps', '[{"ratio":1.5,"multiplier":1.15},{"ratio":2,"multiplier":1.3},{"ratio":3,"multiplier":1.5}]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_surge_steps');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_max_multiplier', '2', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_max_multiplier');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_free_cancel_minutes', '2', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_free_cancel_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_cancel_fee_type', 'fixed', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_cancel_fee_type');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_cancel_fee_amount', '100', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_cancel_fee_amount');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'doctor_now_cancel_fee_percent', '25', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'doctor_now_cancel_fee_percent');

INSERT INTO role_permissions (role_id, permission_key, created_at, updated_at)
SELECT r.id, k.permission_key, NOW(), NOW()
FROM roles r
JOIN (
  SELECT 'doctor-matches.view' AS permission_key UNION ALL
  SELECT 'doctor-matches.create' UNION ALL
  SELECT 'doctor-matches.edit' UNION ALL
  SELECT 'doctor-matches.delete' UNION ALL
  SELECT 'doctor-now-settings.manage'
) k
WHERE r.slug = 'super-admin'
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_key = k.permission_key);

INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120000_add_doctor_now_columns_to_doctor_profiles_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120001_create_doctor_match_requests_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120002_create_doctor_match_offers_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120003_create_doctor_match_waitlist_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120004_seed_doctor_now_settings_and_permissions', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_120005_make_invoices_clinic_id_nullable', COALESCE(MAX(batch), 0) + 1 FROM migrations;
