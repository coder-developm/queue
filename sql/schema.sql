SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS queues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  require_user_id TINYINT(1) NOT NULL DEFAULT 1,
  user_prompt VARCHAR(255) NULL,
  input_mask VARCHAR(16) NOT NULL DEFAULT 'uuid',
  allow_self_registration TINYINT(1) NOT NULL DEFAULT 1,
  max_capacity INT NOT NULL DEFAULT 1000,
  status_lang VARCHAR(8) NOT NULL DEFAULT 'auto',
  tts_lang VARCHAR(10) NOT NULL DEFAULT 'auto',
  brand_primary VARCHAR(16) NULL,
  brand_accent VARCHAR(16) NULL,
  logo_queue VARCHAR(255) NULL,
  logo_admin VARCHAR(255) NULL,
  logo_poster VARCHAR(255) NULL,
  texts_json TEXT NULL,
  admin_token VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NOT NULL,
  code VARCHAR(32) NOT NULL,
  label VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_service(queue_id, code),
  FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cashiers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NOT NULL,
  idx INT NOT NULL,
  name VARCHAR(64) NOT NULL,
  paused TINYINT(1) NOT NULL DEFAULT 0,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  allowed_services TEXT NULL,
  current_ticket_number INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cashier(queue_id, idx),
  FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NOT NULL,
  uuid CHAR(36) NOT NULL UNIQUE,
  user_id VARCHAR(128) NULL,
  service_code VARCHAR(32) NULL,
  number INT NOT NULL,
  status ENUM('waiting','called','served','left','removed') NOT NULL DEFAULT 'waiting',
  called_cashier_idx INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_queue_status(queue_id, status, number),
  FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS call_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NOT NULL,
  cashier_idx INT NOT NULL,
  ticket_number INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'admin',
  permissions_json TEXT NULL,
  comment VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS create_tokens (
  token VARCHAR(64) PRIMARY KEY,
  label VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_create_tokens_user(created_by),
  FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS auth_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  username VARCHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_ip_time(ip, created_at),
  INDEX idx_auth_user_time(username, created_at)
) ENGINE=InnoDB;

-- Persistent sessions for "Remember me"
CREATE TABLE IF NOT EXISTS admin_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'long',
  session_id VARCHAR(128) NULL,
  selector CHAR(32) NULL UNIQUE,
  token_hash CHAR(64) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_admin_sessions_user(user_id),
  INDEX idx_admin_sessions_expires(expires_at),
  FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Global branding per page (applies to all queues)
CREATE TABLE IF NOT EXISTS site_branding (
  page_key VARCHAR(32) PRIMARY KEY,
  primary_color VARCHAR(16) NULL,
  accent_color VARCHAR(16) NULL,
  logo_url VARCHAR(255) NULL,
  favicon_url VARCHAR(255) NULL,
  theme_mode VARCHAR(8) NULL,
  notify_sound_url VARCHAR(255) NULL,
  texts_json TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Defaults
INSERT IGNORE INTO site_branding(page_key, primary_color, accent_color, logo_url, favicon_url, theme_mode, notify_sound_url, texts_json) VALUES
('global', NULL, NULL, NULL, '/img/logo.svg', 'auto', '/sounds/notify.wav', NULL),
('queue',  '#7C5CFA', '#E9E2FF', '/img/logo.svg', NULL, NULL, NULL, NULL),
('status', '#7C5CFA', '#E9E2FF', '/img/logo.svg', NULL, NULL, NULL, NULL),
('poster', '#7C5CFA', '#E9E2FF', '/poster/img/logo.svg', NULL, NULL, NULL, NULL),
('manage', '#7C5CFA', '#E9E2FF', '/img/logo.svg', NULL, NULL, NULL, NULL),
('create', '#7C5CFA', '#E9E2FF', '/create/img/logo.svg', NULL, NULL, NULL, NULL),
('success','#7C5CFA', '#E9E2FF', '/create/img/logo.svg', NULL, NULL, NULL, NULL),
('miniadmin','#7C5CFA', '#E9E2FF', '/img/logo.svg', '/img/logo.svg', NULL, NULL, NULL);


-- Server logs (API errors, events)
CREATE TABLE IF NOT EXISTS server_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(16) NOT NULL,
  source VARCHAR(64) NULL,
  message TEXT NOT NULL,
  context_json LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_server_logs_created(created_at),
  INDEX idx_server_logs_level(level),
  INDEX idx_server_logs_source(source)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS log_settings (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  auto_delete TINYINT(1) NOT NULL DEFAULT 0,
  keep_days INT NOT NULL DEFAULT 30,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_log_settings_updated(updated_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO log_settings(id, auto_delete, keep_days) VALUES (1, 0, 30);

-- Auth history (append-only)
CREATE TABLE IF NOT EXISTS auth_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(64) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  success TINYINT(1) NOT NULL,
  reason VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_history_created(created_at),
  INDEX idx_auth_history_user(user_id)
) ENGINE=InnoDB;

-- Backup settings and entries
CREATE TABLE IF NOT EXISTS backup_settings (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  frequency_minutes INT NOT NULL DEFAULT 1440,
  auto_delete TINYINT(1) NOT NULL DEFAULT 0,
  keep_days INT NOT NULL DEFAULT 30,
  last_run_at DATETIME NULL,
  cleanup_last_run_at DATETIME NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO backup_settings(id, enabled, frequency_minutes, auto_delete, keep_days, last_run_at, cleanup_last_run_at)
VALUES (1, 0, 1440, 0, 30, NULL, NULL);

CREATE TABLE IF NOT EXISTS backups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  -- sent_to_telegram: 0=не настроено/пропущено, 1=отправлено, 2=ошибка
  sent_to_telegram TINYINT(1) NOT NULL DEFAULT 0,
  telegram_error TEXT NULL
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS speech_settings (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  provider VARCHAR(32) NOT NULL DEFAULT 'yandex',
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  api_key VARCHAR(255) NULL,
  folder_id VARCHAR(255) NULL,
  voice VARCHAR(64) NOT NULL DEFAULT 'filipp',
  emotion VARCHAR(32) NULL,
  speed DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  template_text TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO speech_settings(id, provider, enabled, api_key, folder_id, voice, emotion, speed, template_text)
VALUES (1, 'yandex', 0, NULL, NULL, 'filipp', NULL, 1.00, 'Номер {number}. Пройдите к кассе {cashier}');

SET FOREIGN_KEY_CHECKS=1;


CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  queue_id INT UNSIGNED NOT NULL,
  ticket_uuid CHAR(36) NULL,
  endpoint TEXT NOT NULL,
  subscription_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint(190)),
  KEY idx_push_queue_ticket (queue_id, ticket_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
