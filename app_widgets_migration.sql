CREATE TABLE app_widgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NULL,
  role VARCHAR(32) NOT NULL,
  screen VARCHAR(32) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  settings JSON NULL,
  image VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY app_widgets_key_unique (`key`),
  KEY app_widgets_role_index (role),
  KEY app_widgets_screen_index (screen)
);

INSERT INTO app_widgets (`key`, title, subtitle, role, screen, is_enabled, sort_order, settings, image, created_at, updated_at) VALUES
('patient_search_shortcuts', 'What do you need today?', NULL, 'patient', 'home', 1, 10, '{"placeholder":"Search doctor, speciality or clinic"}', NULL, NOW(), NOW()),
('patient_promo_banner', 'Early protection for your family health', 'Book a check-up for the whole family in a few taps.', 'patient', 'home', 1, 20, '{"cta_label":"Learn more","cta_target":"Care","web_url":"/doctors","accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('patient_next_appointment', 'Next appointment', NULL, 'patient', 'home', 1, 30, '{}', NULL, NOW(), NOW()),
('patient_latest_triage', 'Your last triage result', NULL, 'patient', 'home', 1, 40, '{"hours":"48"}', NULL, NOW(), NOW()),
('patient_visit_stats', 'My health at a glance', NULL, 'patient', 'home', 1, 50, '{}', NULL, NOW(), NOW()),
('patient_upcoming_ride', 'Your shuttle', NULL, 'patient', 'home', 1, 60, '{}', NULL, NOW(), NOW()),
('patient_clinic_services', 'Clinic services', NULL, 'patient', 'home', 1, 70, '{"limit":"8"}', NULL, NOW(), NOW()),
('patient_top_doctors', 'Top doctors', 'Highest rated near you', 'patient', 'home', 1, 80, '{"limit":"6","cta_label":"Book now"}', NULL, NOW(), NOW()),
('patient_nearby_clinics', 'Nearby clinics', NULL, 'patient', 'home', 1, 90, '{"limit":"4"}', NULL, NOW(), NOW()),
('patient_active_prescriptions', 'Active prescriptions', NULL, 'patient', 'home', 1, 100, '{"days":"30","limit":"5"}', NULL, NOW(), NOW()),
('patient_pending_lab_results', 'Lab results', NULL, 'patient', 'home', 1, 110, '{"limit":"3"}', NULL, NOW(), NOW()),
('patient_recent_orders', 'Recent orders', NULL, 'patient', 'home', 1, 120, '{"limit":"3"}', NULL, NOW(), NOW()),
('patient_outstanding_invoices', 'Outstanding invoices', NULL, 'patient', 'home', 1, 130, '{"limit":"3"}', NULL, NOW(), NOW()),
('patient_medical_aid_card', 'Medical aid', NULL, 'patient', 'home', 1, 140, '{"accent":"#101012"}', NULL, NOW(), NOW()),
('patient_emergency_quickdial', 'Emergency numbers', 'Tap to call', 'patient', 'home', 1, 150, '{"limit":"4"}', NULL, NOW(), NOW()),
('patient_health_articles', 'Health articles', NULL, 'patient', 'home', 1, 160, '{"limit":"4"}', NULL, NOW(), NOW()),
('care_specialties', 'Browse by speciality', NULL, 'patient', 'find_care', 1, 10, '{"limit":"10"}', NULL, NOW(), NOW()),
('care_top_doctors', 'Top rated doctors', NULL, 'patient', 'find_care', 1, 20, '{"limit":"6","cta_label":"Book now"}', NULL, NOW(), NOW()),
('care_nearby_clinics', 'Clinics near you', NULL, 'patient', 'find_care', 1, 30, '{"limit":"3"}', NULL, NOW(), NOW()),
('shop_promo_banner', 'Have a script? We deliver.', 'Upload it and a pharmacist reviews it before dispatch.', 'patient', 'shop', 1, 10, '{"cta_label":"Upload script","cta_target":"Pharmacy/ScriptUpload","web_url":"/shop","accent":"#1F7A4C"}', NULL, NOW(), NOW()),
('shop_categories', 'Shop by category', NULL, 'patient', 'shop', 1, 20, '{"limit":"8"}', NULL, NOW(), NOW()),
('shop_featured_products', 'Featured products', NULL, 'patient', 'shop', 1, 30, '{"limit":"8"}', NULL, NOW(), NOW()),
('record_health_summary', 'Health summary', NULL, 'patient', 'record', 1, 10, '{}', NULL, NOW(), NOW()),
('record_recent_encounters', 'Recent consultations', NULL, 'patient', 'record', 1, 20, '{"limit":"4"}', NULL, NOW(), NOW()),
('doctor_total_patients', 'Total patients', NULL, 'doctor', 'home', 1, 10, '{"size":"half","icon":"users","accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('doctor_total_appointments', 'Total appointments', NULL, 'doctor', 'home', 1, 20, '{"size":"half","icon":"calendar","accent":"#D0211C"}', NULL, NOW(), NOW()),
('doctor_weekly_chart', 'Appointments this week', NULL, 'doctor', 'home', 1, 30, '{"accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('doctor_availability', 'On-demand availability', 'Receive instant consult requests', 'doctor', 'home', 1, 40, '{}', NULL, NOW(), NOW()),
('doctor_pending_requests', 'Waiting for a doctor', 'Patients requesting your speciality now', 'doctor', 'home', 1, 50, '{"limit":"3"}', NULL, NOW(), NOW()),
('doctor_today_appointments', 'Today''s appointments', NULL, 'doctor', 'home', 1, 60, '{}', NULL, NOW(), NOW()),
('doctor_rating', 'Rating', NULL, 'doctor', 'home', 1, 70, '{"size":"half","icon":"star","accent":"#F2C200"}', NULL, NOW(), NOW()),
('doctor_completed_month', 'Completed this month', NULL, 'doctor', 'home', 1, 80, '{"size":"half","icon":"check-circle","accent":"#1F7A4C"}', NULL, NOW(), NOW()),
('doctor_month_calendar', 'Calendar', NULL, 'doctor', 'calendar', 1, 10, '{}', NULL, NOW(), NOW()),
('driver_next_pickup', 'Next pickup', NULL, 'driver', 'home', 1, 10, '{}', NULL, NOW(), NOW()),
('driver_today_trips', 'Trips today', NULL, 'driver', 'home', 1, 20, '{"size":"half","icon":"navigation","accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('driver_week_earnings', 'Earnings this week', NULL, 'driver', 'home', 1, 30, '{"size":"half","icon":"dollar-sign","accent":"#1F7A4C"}', NULL, NOW(), NOW()),
('driver_weekly_chart', 'Trips this week', NULL, 'driver', 'home', 1, 40, '{"accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('driver_rating', 'Your rating', NULL, 'driver', 'home', 1, 50, '{"icon":"star","accent":"#F2C200"}', NULL, NOW(), NOW()),
('pharmacy_pending_orders', 'Pending orders', NULL, 'pharmacy_admin', 'home', 1, 10, '{"size":"half","icon":"shopping-bag","accent":"#D0211C"}', NULL, NOW(), NOW()),
('pharmacy_pending_scripts', 'Scripts to review', NULL, 'pharmacy_admin', 'home', 1, 20, '{"size":"half","icon":"file-text","accent":"#E4701E"}', NULL, NOW(), NOW()),
('pharmacy_revenue_month', 'Revenue this month', NULL, 'pharmacy_admin', 'home', 1, 30, '{"size":"half","icon":"trending-up","accent":"#1F7A4C"}', NULL, NOW(), NOW()),
('pharmacy_low_stock', 'Low stock', NULL, 'pharmacy_admin', 'home', 1, 40, '{"size":"half","icon":"alert-triangle","accent":"#9E1813","threshold":"10"}', NULL, NOW(), NOW()),
('tp_new_requests', 'New requests', NULL, 'third_party', 'home', 1, 10, '{"size":"half","icon":"inbox","accent":"#D0211C"}', NULL, NOW(), NOW()),
('tp_active_requests', 'In progress', NULL, 'third_party', 'home', 1, 20, '{"size":"half","icon":"activity","accent":"#1F4E7A"}', NULL, NOW(), NOW()),
('tp_completed_month', 'Completed this month', NULL, 'third_party', 'home', 1, 30, '{"size":"half","icon":"check-circle","accent":"#1F7A4C"}', NULL, NOW(), NOW()),
('tp_rating', 'Rating', NULL, 'third_party', 'home', 1, 40, '{"size":"half","icon":"star","accent":"#F2C200"}', NULL, NOW(), NOW());

CREATE TABLE health_articles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  category VARCHAR(255) NULL,
  excerpt TEXT NULL,
  body LONGTEXT NULL,
  image VARCHAR(255) NULL,
  read_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  published_at TIMESTAMP NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY health_articles_slug_unique (slug)
);

INSERT INTO health_articles (title, slug, category, excerpt, body, image, read_minutes, published_at, is_published, created_at, updated_at) VALUES
('Spot a stroke fast: remember F.A.S.T.', 'spot-a-stroke-fast', 'Emergency', 'Face drooping, Arm weakness, Speech difficulty — Time to call for help. Every minute counts.', 'A stroke happens when blood flow to part of the brain is cut off. Fast treatment saves brain tissue, so knowing the signs matters.

F — Face: ask the person to smile. Does one side droop?
A — Arms: ask them to raise both arms. Does one drift down?
S — Speech: is their speech slurred or strange?
T — Time: if you see any of these, call an ambulance immediately and note the time symptoms started.

Do not give the person anything to eat or drink, and do not let them drive themselves. Use the Emergency button in Prompt Aid to find the nearest facility that can help.', NULL, 2, NOW(), 1, NOW(), NOW()),
('Staying hydrated in the heat', 'staying-hydrated-in-the-heat', 'Everyday health', 'Children, older adults and people with chronic conditions dehydrate faster. Here is what to watch for.', 'Hot days increase how much water your body loses through sweat. Mild dehydration causes thirst, headaches, tiredness and dark urine.

Tips:
• Drink water regularly through the day — don''t wait until you are thirsty.
• Keep babies and young children out of direct sun and offer fluids often.
• Older adults may not feel thirst as strongly; check in on them.
• Limit alcohol and very sugary drinks.

Seek care urgently for confusion, fainting, no urine for 8 hours or more, or a very high temperature. These can be signs of heat stroke.', NULL, 3, NOW() - INTERVAL 1 DAY, 1, NOW(), NOW()),
('GP, clinic or emergency room?', 'gp-clinic-or-emergency-room', 'Using Prompt Aid', 'Choosing the right place for care gets you seen sooner — and keeps emergency rooms free for emergencies.', 'Not every health problem needs an emergency room.

See a GP or clinic (in person or by video) for: colds and flu, mild fevers, rashes, repeat prescriptions, check-ups and managing chronic conditions.

Go to an emergency unit for: chest pain, difficulty breathing, severe bleeding, signs of stroke, serious injuries, seizures or loss of consciousness.

Not sure? Start emergency triage in the app — it asks a few quick questions and points you to the right level of care, with the nearest facility that can treat you.', NULL, 2, NOW() - INTERVAL 2 DAY, 1, NOW(), NOW());

INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_090000_create_app_widgets_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_090001_create_health_articles_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
