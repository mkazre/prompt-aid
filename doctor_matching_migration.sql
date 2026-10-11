ALTER TABLE doctor_profiles
  ADD COLUMN availability ENUM('offline','available','busy') NOT NULL DEFAULT 'offline' AFTER is_accepting_appointments,
  ADD COLUMN current_lat DECIMAL(10,7) NULL AFTER availability,
  ADD COLUMN current_lng DECIMAL(10,7) NULL AFTER current_lat,
  ADD COLUMN location_updated_at TIMESTAMP NULL AFTER current_lng;

ALTER TABLE appointments
  ADD COLUMN requested_specialty VARCHAR(255) NULL AFTER mode;

ALTER TABLE appointments MODIFY doctor_profile_id BIGINT UNSIGNED NULL;
ALTER TABLE appointments MODIFY clinic_id BIGINT UNSIGNED NULL;
ALTER TABLE appointments MODIFY status ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show', 'matching') NOT NULL DEFAULT 'pending';

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_110000_add_availability_to_doctor_profiles_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_110001_add_matching_support_to_appointments_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
