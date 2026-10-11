CREATE TABLE conditions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  synonyms TEXT NULL,
  specialization VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY conditions_slug_unique (slug),
  KEY conditions_is_active_index (is_active)
);

INSERT INTO conditions (name, slug, synonyms, specialization, is_active, sort_order, created_at, updated_at) VALUES
('Flu', 'flu', 'influenza, cold, common cold, runny nose, blocked nose', 'General Practitioner', 1, 10, NOW(), NOW()),
('Fever', 'fever', 'high temperature, chills', 'General Practitioner', 1, 20, NOW(), NOW()),
('Cough', 'cough', 'chesty cough, dry cough', 'General Practitioner', 1, 30, NOW(), NOW()),
('Sore throat', 'sore-throat', 'tonsillitis, strep throat, pharyngitis', 'ENT Specialist', 1, 40, NOW(), NOW()),
('Headache', 'headache', 'migraine, tension headache', 'General Practitioner', 1, 50, NOW(), NOW()),
('High blood pressure', 'high-blood-pressure', 'hypertension, bp', 'General Practitioner', 1, 60, NOW(), NOW()),
('Diabetes', 'diabetes', 'sugar, blood sugar, type 2 diabetes', 'General Practitioner', 1, 70, NOW(), NOW()),
('Back pain', 'back-pain', 'lower back pain, backache', 'General Practitioner', 1, 80, NOW(), NOW()),
('Urinary tract infection', 'urinary-tract-infection', 'uti, bladder infection, burning urine', 'General Practitioner', 1, 90, NOW(), NOW()),
('Stomach ache', 'stomach-ache', 'abdominal pain, cramps, indigestion', 'General Practitioner', 1, 100, NOW(), NOW()),
('Diarrhoea', 'diarrhoea', 'diarrhea, runny stomach, gastro', 'General Practitioner', 1, 110, NOW(), NOW()),
('Vomiting', 'vomiting', 'nausea, being sick', 'General Practitioner', 1, 120, NOW(), NOW()),
('Asthma', 'asthma', 'wheezing, shortness of breath, inhaler', 'General Practitioner', 1, 130, NOW(), NOW()),
('Allergies', 'allergies', 'hay fever, allergic rhinitis, sneezing', 'General Practitioner', 1, 140, NOW(), NOW()),
('Sinusitis', 'sinusitis', 'sinus infection, sinus pain', 'ENT Specialist', 1, 150, NOW(), NOW()),
('Ear infection', 'ear-infection', 'earache, ear pain, otitis', 'ENT Specialist', 1, 160, NOW(), NOW()),
('Hearing loss', 'hearing-loss', 'deafness, blocked ears, tinnitus', 'ENT Specialist', 1, 170, NOW(), NOW()),
('Snoring', 'snoring', 'sleep apnoea, sleep apnea', 'ENT Specialist', 1, 180, NOW(), NOW()),
('Acne', 'acne', 'pimples, breakouts', 'Dermatologist', 1, 190, NOW(), NOW()),
('Eczema', 'eczema', 'dermatitis, itchy skin, dry skin', 'Dermatologist', 1, 200, NOW(), NOW()),
('Skin rash', 'skin-rash', 'hives, rash, itching', 'Dermatologist', 1, 210, NOW(), NOW()),
('Psoriasis', 'psoriasis', 'scaly skin, plaques', 'Dermatologist', 1, 220, NOW(), NOW()),
('Hair loss', 'hair-loss', 'alopecia, thinning hair', 'Dermatologist', 1, 230, NOW(), NOW()),
('Moles', 'moles', 'skin lesion, mole check, skin cancer screening', 'Dermatologist', 1, 240, NOW(), NOW()),
('Chest pain', 'chest-pain', 'angina, heart pain', 'Cardiologist', 1, 250, NOW(), NOW()),
('Heart palpitations', 'heart-palpitations', 'irregular heartbeat, arrhythmia, racing heart', 'Cardiologist', 1, 260, NOW(), NOW()),
('High cholesterol', 'high-cholesterol', 'cholesterol, lipids', 'Cardiologist', 1, 270, NOW(), NOW()),
('Heart failure', 'heart-failure', 'swollen ankles, breathlessness', 'Cardiologist', 1, 280, NOW(), NOW()),
('Pregnancy care', 'pregnancy-care', 'antenatal, prenatal, pregnant, pregnancy check-up', 'Gynaecologist', 1, 290, NOW(), NOW()),
('Period problems', 'period-problems', 'irregular periods, painful periods, heavy periods', 'Gynaecologist', 1, 300, NOW(), NOW()),
('Contraception', 'contraception', 'family planning, birth control, the pill', 'Gynaecologist', 1, 310, NOW(), NOW()),
('Pap smear', 'pap-smear', 'cervical screening, pap test', 'Gynaecologist', 1, 320, NOW(), NOW()),
('Menopause', 'menopause', 'hot flushes, perimenopause', 'Gynaecologist', 1, 330, NOW(), NOW()),
('Fertility', 'fertility', 'trying to conceive, infertility', 'Gynaecologist', 1, 340, NOW(), NOW()),
('Child vaccinations', 'child-vaccinations', 'immunisation, immunization, baby shots', 'Pediatrician', 1, 350, NOW(), NOW()),
('Child fever', 'child-fever', 'sick child, baby fever', 'Pediatrician', 1, 360, NOW(), NOW()),
('Colic', 'colic', 'crying baby, infant colic', 'Pediatrician', 1, 370, NOW(), NOW()),
('Child growth check', 'child-growth-check', 'developmental milestones, well baby check', 'Pediatrician', 1, 380, NOW(), NOW()),
('Anxiety', 'anxiety', 'stress, panic attacks, worry', 'General Practitioner', 1, 390, NOW(), NOW()),
('Depression', 'depression', 'low mood, feeling down', 'General Practitioner', 1, 400, NOW(), NOW()),
('Insomnia', 'insomnia', 'trouble sleeping, sleeplessness', 'General Practitioner', 1, 410, NOW(), NOW()),
('Joint pain', 'joint-pain', 'arthritis, sore joints, knee pain', 'General Practitioner', 1, 420, NOW(), NOW());

ALTER TABLE doctor_time_off ADD COLUMN end_date DATE NULL AFTER `date`;

INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'app_terms_page_slug', 'terms', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'app_terms_page_slug');
INSERT INTO settings (`key`, value, created_at, updated_at)
SELECT 'app_about_page_slug', 'about', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'app_about_page_slug');

CREATE TABLE driver_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_profile_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(255) NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  expires_at DATE NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY driver_documents_driver_profile_id_foreign (driver_profile_id),
  CONSTRAINT driver_documents_driver_profile_id_foreign FOREIGN KEY (driver_profile_id) REFERENCES driver_profiles (id) ON DELETE CASCADE
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_140000_create_conditions_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_140001_add_end_date_to_doctor_time_off_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_140002_seed_starter_conditions_and_app_page_settings', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_140003_create_driver_documents_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
