CREATE TABLE emergency_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(255) NOT NULL,
  phone VARCHAR(255) NOT NULL,
  tel_url VARCHAR(255) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

INSERT INTO emergency_contacts (label, phone, tel_url, is_primary, sort_order, is_active, created_at, updated_at) VALUES
('Ambulance', '10177', 'tel:10177', 1, 1, 1, NOW(), NOW()),
('Netcare 911', '082 911', 'tel:082911', 0, 2, 1, NOW(), NOW()),
('ER24', '084 124', 'tel:084124', 0, 3, 1, NOW(), NOW()),
('Poison Information', '0861 555 777', 'tel:0861555777', 0, 4, 1, NOW(), NOW()),
('Childline', '116', 'tel:116', 0, 5, 1, NOW(), NOW()),
('GBV Command Centre', '0800 428 428', 'tel:0800428428', 0, 6, 1, NOW(), NOW()),
('Suicide Crisis Line', '0800 567 567', 'tel:0800567567', 0, 7, 1, NOW(), NOW());

ALTER TABLE notifications_log MODIFY channel VARCHAR(255) NOT NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_090000_create_emergency_contacts_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_28_090001_add_whatsapp_channel_to_notifications_log_table', COALESCE(MAX(batch), 0) + 1 FROM migrations;
