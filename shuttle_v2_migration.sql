ALTER TABLE rides
  ADD COLUMN trip_pin VARCHAR(6) NULL,
  ADD COLUMN share_token VARCHAR(64) NULL,
  ADD COLUMN dispatch_state VARCHAR(20) NULL,
  ADD COLUMN dispatch_started_at TIMESTAMP NULL,
  ADD COLUMN dispatch_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN quoted_fare DECIMAL(10,2) NULL,
  ADD COLUMN surge_multiplier DECIMAL(5,2) NULL,
  ADD COLUMN quote_token VARCHAR(64) NULL,
  ADD COLUMN quote_expires_at TIMESTAMP NULL,
  ADD COLUMN fare_breakdown LONGTEXT NULL,
  ADD COLUMN route_distance_km DECIMAL(8,2) NULL,
  ADD COLUMN route_duration_min INT UNSIGNED NULL,
  ADD COLUMN route_geometry LONGTEXT NULL,
  ADD COLUMN eta_updated_at TIMESTAMP NULL,
  ADD COLUMN driver_arrived_at TIMESTAMP NULL,
  ADD COLUMN cancelled_at TIMESTAMP NULL,
  ADD COLUMN cancelled_by VARCHAR(20) NULL,
  ADD COLUMN cancellation_fee DECIMAL(10,2) NULL,
  ADD COLUMN cancellation_fee_status VARCHAR(20) NULL,
  ADD COLUMN patient_rating TINYINT UNSIGNED NULL,
  ADD COLUMN patient_rating_comment TEXT NULL,
  ADD COLUMN simulation TEXT NULL,
  ADD UNIQUE KEY rides_share_token_unique (share_token),
  ADD KEY rides_status_dispatch_state_index (status, dispatch_state);

ALTER TABLE driver_profiles
  ADD COLUMN current_heading DECIMAL(5,1) NULL,
  ADD COLUMN current_speed_kmh DECIMAL(6,1) NULL,
  ADD COLUMN offers_accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN offers_declined_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN offers_missed_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN rides_cancelled_count INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE driver_profiles MODIFY vehicle_type ENUM('sedan', 'suv', 'van', 'wheelchair_accessible', 'stretcher') NOT NULL DEFAULT 'sedan';

CREATE TABLE ride_locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT UNSIGNED NOT NULL,
  driver_profile_id BIGINT UNSIGNED NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  heading DECIMAL(5,1) NULL,
  speed_kmh DECIMAL(6,1) NULL,
  phase VARCHAR(20) NULL,
  recorded_at TIMESTAMP NULL,
  KEY ride_locations_ride_id_recorded_at_index (ride_id, recorded_at),
  KEY ride_locations_driver_profile_id_foreign (driver_profile_id),
  CONSTRAINT ride_locations_ride_id_foreign FOREIGN KEY (ride_id) REFERENCES rides (id) ON DELETE CASCADE,
  CONSTRAINT ride_locations_driver_profile_id_foreign FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE SET NULL
);

CREATE TABLE ride_offers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT UNSIGNED NOT NULL,
  driver_profile_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  distance_km DECIMAL(8,2) NULL,
  eta_minutes INT UNSIGNED NULL,
  offered_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  responded_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY ride_offers_ride_id_status_index (ride_id, status),
  KEY ride_offers_driver_profile_id_status_index (driver_profile_id, status),
  CONSTRAINT ride_offers_ride_id_foreign FOREIGN KEY (ride_id) REFERENCES rides (id) ON DELETE CASCADE,
  CONSTRAINT ride_offers_driver_profile_id_foreign FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE
);

CREATE TABLE ride_charges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id BIGINT UNSIGNED NOT NULL,
  patient_profile_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  method VARCHAR(30) NULL,
  gateway VARCHAR(255) NULL,
  gateway_ref VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  actioned_by_user_id BIGINT UNSIGNED NULL,
  refunded_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY ride_charges_ride_id_type_index (ride_id, type),
  KEY ride_charges_patient_profile_id_foreign (patient_profile_id),
  KEY ride_charges_actioned_by_user_id_foreign (actioned_by_user_id),
  CONSTRAINT ride_charges_ride_id_foreign FOREIGN KEY (ride_id) REFERENCES rides (id) ON DELETE CASCADE,
  CONSTRAINT ride_charges_patient_profile_id_foreign FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id) ON DELETE CASCADE,
  CONSTRAINT ride_charges_actioned_by_user_id_foreign FOREIGN KEY (actioned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
);

INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_osrm_base_url', 'https://router.project-osrm.org', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_osrm_base_url');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_routing_enabled', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_routing_enabled');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_fallback_road_factor', '1.3', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_fallback_road_factor');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_fallback_speed_kmh', '30', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_fallback_speed_kmh');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_eta_refresh_seconds', '30', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_eta_refresh_seconds');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_location_min_interval_seconds', '2', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_location_min_interval_seconds');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_driver_stale_minutes', '10', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_driver_stale_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_pricing_timezone', 'Africa/Johannesburg', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_pricing_timezone');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_booking_fee', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_booking_fee');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_quote_ttl_minutes', '10', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_quote_ttl_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_time_bands', '[{"label":"Morning peak","days":[1,2,3,4,5],"start":"06:00","end":"09:00","multiplier":1.3},{"label":"Evening peak","days":[1,2,3,4,5],"start":"16:00","end":"19:00","multiplier":1.3},{"label":"Night","days":[0,1,2,3,4,5,6],"start":"21:00","end":"05:00","multiplier":1.2}]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_time_bands');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_public_holidays', '[]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_public_holidays');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_holiday_multiplier', '1.25', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_holiday_multiplier');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_surge_enabled', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_surge_enabled');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_surge_radius_km', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_surge_radius_km');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_surge_window_minutes', '15', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_surge_window_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_surge_steps', '[{"ratio":1.5,"multiplier":1.2},{"ratio":2,"multiplier":1.5},{"ratio":3,"multiplier":1.8}]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_surge_steps');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_max_multiplier', '2.5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_max_multiplier');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_free_wait_minutes', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_free_wait_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_wait_rate_per_minute', '2', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_wait_rate_per_minute');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_route_change_threshold_pct', '20', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_route_change_threshold_pct');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_charge_fare_on_completion', '1', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_charge_fare_on_completion');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_free_cancel_minutes', '2', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_free_cancel_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_fee_eta_minutes', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_fee_eta_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_fee_type', 'fixed', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_fee_type');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_fee_amount', '25', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_fee_amount');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_fee_percent', '10', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_fee_percent');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_fee_overrides', '[]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_fee_overrides');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_no_show_wait_minutes', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_no_show_wait_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_no_show_fee_type', 'fixed', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_no_show_fee_type');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_no_show_fee_amount', '40', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_no_show_fee_amount');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_no_show_fee_percent', '20', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_no_show_fee_percent');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_reasons_patient', '["Driver is taking too long","Booked by mistake","Changed my plans","Found another way to get there","Wrong pickup location","Other"]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_reasons_patient');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_cancel_reasons_driver', '["Patient asked me to cancel","Vehicle problem","Cannot reach pickup location","Safety concern","Other"]', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_cancel_reasons_driver');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_offer_timeout_seconds', '20', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_offer_timeout_seconds');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_dispatch_max_attempts', '6', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_dispatch_max_attempts');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_dispatch_radius_km', '8', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_dispatch_radius_km');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_dispatch_max_radius_km', '25', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_dispatch_max_radius_km');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_dispatch_timeout_minutes', '5', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_dispatch_timeout_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_trip_pin_mode', 'web_only', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_trip_pin_mode');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_scheduled_dispatch_lead_minutes', '20', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_scheduled_dispatch_lead_minutes');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_location_retention_days', '90', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_location_retention_days');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'shuttle_simulator_speed_kmh', '45', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'shuttle_simulator_speed_kmh');

INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'tick_secret_key', CONCAT(SUBSTRING(MD5(RAND()), 1, 20), SUBSTRING(MD5(RAND()), 1, 20)), NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'tick_secret_key');

INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100000_add_shuttle_v2_columns_to_rides_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100001_add_shuttle_v2_columns_to_driver_profiles_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100002_create_ride_locations_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100003_create_ride_offers_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100004_create_ride_charges_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100005_seed_shuttle_v2_settings', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_10_07_100006_add_stretcher_to_driver_profiles_vehicle_type', COALESCE(MAX(batch), 0) + 1 FROM migrations;
