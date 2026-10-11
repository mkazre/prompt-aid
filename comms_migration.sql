CREATE TABLE conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_profile_id BIGINT UNSIGNED NOT NULL,
  doctor_profile_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  last_message_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY conversations_patient_profile_id_doctor_profile_id_unique (patient_profile_id, doctor_profile_id),
  KEY conversations_last_message_at_index (last_message_at),
  CONSTRAINT conversations_patient_profile_id_foreign FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id) ON DELETE CASCADE,
  CONSTRAINT conversations_doctor_profile_id_foreign FOREIGN KEY (doctor_profile_id) REFERENCES doctor_profiles (id) ON DELETE CASCADE,
  CONSTRAINT conversations_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL
);

CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NULL,
  attachment_path VARCHAR(255) NULL,
  attachment_name VARCHAR(255) NULL,
  attachment_mime VARCHAR(100) NULL,
  attachment_size INT UNSIGNED NULL,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY messages_conversation_id_id_index (conversation_id, id),
  KEY messages_conversation_id_read_at_index (conversation_id, read_at),
  CONSTRAINT messages_conversation_id_foreign FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT messages_sender_user_id_foreign FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE user_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  data JSON NULL,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY user_notifications_user_id_read_at_index (user_id, read_at),
  KEY user_notifications_user_id_id_index (user_id, id),
  CONSTRAINT user_notifications_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

ALTER TABLE appointments
  ADD COLUMN video_room VARCHAR(80) NULL AFTER meet_created_at,
  ADD COLUMN video_ended_at TIMESTAMP NULL AFTER video_room,
  ADD UNIQUE KEY appointments_video_room_unique (video_room);

INSERT INTO role_permissions (role_id, permission_key, created_at, updated_at)
SELECT id, k.permission_key, NOW(), NOW() FROM roles, (SELECT 'conversations.view' AS permission_key UNION ALL SELECT 'conversations.create' AS permission_key) k WHERE roles.slug = 'doctor'
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = roles.id AND rp.permission_key = k.permission_key);

INSERT INTO role_permissions (role_id, permission_key, created_at, updated_at)
SELECT id, k.permission_key, NOW(), NOW() FROM roles, (SELECT 'conversations.view' AS permission_key UNION ALL SELECT 'conversations.create' AS permission_key UNION ALL SELECT 'conversations.edit' AS permission_key UNION ALL SELECT 'conversations.delete' AS permission_key UNION ALL SELECT 'broadcast-notifications.send' AS permission_key) k WHERE roles.slug = 'super-admin'
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = roles.id AND rp.permission_key = k.permission_key);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_160000_create_comms_tables', COALESCE(MAX(batch), 0) + 1 FROM migrations;
INSERT INTO migrations (migration, batch)
SELECT '2026_09_29_160001_grant_comms_permissions', COALESCE(MAX(batch), 0) + 1 FROM migrations;
