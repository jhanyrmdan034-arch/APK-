-- Migration V2 — پلتفرم فروش خودکار BABARPHANTOM
-- اجرا پس از schema.sql : mysql -u babar_user -p babar_central < migration_v2_sales_platform.sql
-- شامل: کیف پول، پرداخت، سفارش، APK، فرم توکن امن، ربات فروش

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- 1) Customers: افزودن فیلدهای تلگرام فروش + تغییر وضعیت به ACTIVE/SUSPENDED/DISABLED
-- برای سازگاری، ENUM را گسترش می‌دهیم (در MySQL 8 تغییر ENUM امن است اگر فقط مقدار اضافه شود)
-- اول مقادیر جدید را اضافه می‌کنیم، سپس داده‌های قدیمی را نگاشت می‌کنیم
ALTER TABLE `customers` MODIFY COLUMN `status` ENUM('active','blocked','pending','ACTIVE','SUSPENDED','DISABLED') NOT NULL DEFAULT 'ACTIVE';
UPDATE `customers` SET `status`='ACTIVE'   WHERE `status`='active';
UPDATE `customers` SET `status`='DISABLED' WHERE `status`='blocked';
UPDATE `customers` SET `status`='SUSPENDED' WHERE `status`='pending';
ALTER TABLE `customers` MODIFY COLUMN `status` ENUM('ACTIVE','SUSPENDED','DISABLED') NOT NULL DEFAULT 'ACTIVE';
ALTER TABLE `customers` ADD COLUMN `telegram_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Telegram user id (sales bot)' AFTER `name`;
ALTER TABLE `customers` ADD COLUMN `phone` VARCHAR(32) DEFAULT NULL AFTER `telegram_id`;
ALTER TABLE `customers` ADD COLUMN `telegram_username` VARCHAR(64) DEFAULT NULL AFTER `phone`;
ALTER TABLE `customers` ADD UNIQUE KEY `uniq_telegram_id` (`telegram_id`);
ALTER TABLE `customers` ADD KEY `idx_phone` (`phone`);

-- 2) Wallets
CREATE TABLE IF NOT EXISTS `wallets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `balance` BIGINT NOT NULL DEFAULT 0 COMMENT 'موجودی به ریال/تومان (عدد صحیح)',
  `currency` VARCHAR(8) NOT NULL DEFAULT 'IRT',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer` (`customer_id`),
  CONSTRAINT `fk_wallets_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Wallet transactions
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `type` ENUM('charge','purchase','refund','adjustment','payment_approved','payment_rejected') NOT NULL,
  `amount` BIGINT NOT NULL COMMENT 'مثبت = بستانکار، منفی = بدهکار',
  `balance_after` BIGINT NOT NULL,
  `reference_type` VARCHAR(64) DEFAULT NULL COMMENT 'payment_request|order|manual',
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(512) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'admin که ایجاد کرد',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_type` (`type`),
  CONSTRAINT `fk_wt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Payment requests (درخواست شارژ)
CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `amount` BIGINT NOT NULL,
  `method` VARCHAR(32) NOT NULL DEFAULT 'card_to_card' COMMENT 'card_to_card, online, crypto, manual',
  `receipt_path` VARCHAR(512) DEFAULT NULL COMMENT 'مسیر تصویر رسید در private/uploads',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(512) DEFAULT NULL,
  `telegram_message_id` BIGINT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Products / Plans
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `price` BIGINT NOT NULL COMMENT 'قیمت واحد',
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`code`,`name`,`price`,`description`) VALUES
('phantom_basic','BABARPHANTOM پایه','1500000','دسترسی کامل + 1 ربات + APK اختصاصی'),
('phantom_pro','BABARPHANTOM حرفه‌ای','2500000','دسترسی کامل + 3 ربات + پشتیبانی')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- 6) Orders
CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `product_code` VARCHAR(64) NOT NULL,
  `amount` BIGINT NOT NULL,
  `status` ENUM('pending','paid','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `payment_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `api_key_id` INT UNSIGNED DEFAULT NULL COMMENT 'کلید اختصاصی این سفارش',
  `notes` VARCHAR(512) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) APK files
CREATE TABLE IF NOT EXISTS `apk_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(32) NOT NULL COMMENT 'e.g. 1.0.0',
  `version_code` INT UNSIGNED NOT NULL DEFAULT 1,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(512) NOT NULL COMMENT 'مسیر private/apk/',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'نسخهٔ فعال برای تحویل',
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(512) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) APK deliveries
CREATE TABLE IF NOT EXISTS `apk_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `apk_file_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `delivery_token` CHAR(64) NOT NULL COMMENT 'توکن دانلود یکبارمصرف/چندبار',
  `api_key` CHAR(64) DEFAULT NULL COMMENT 'کلید اختصاصی مشتری برای این تحویل',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_download_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`delivery_token`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_del_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_del_apk` FOREIGN KEY (`apk_file_id`) REFERENCES `apk_files`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Token forms (فرم امن تحویل توکن)
CREATE TABLE IF NOT EXISTS `token_forms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `token` CHAR(64) NOT NULL COMMENT 'one-time token برای لینک فرم',
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_tf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) Sales bot sessions (وضعیت گفتگو هر کاربر تلگرام فروش)
CREATE TABLE IF NOT EXISTS `sales_sessions` (
  `telegram_id` BIGINT NOT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `state` VARCHAR(64) NOT NULL DEFAULT 'idle' COMMENT 'idle, awaiting_amount, awaiting_receipt, awaiting_token',
  `state_data` JSON DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11) Admin audit (لاگ عملیات مدیر جدا از security_events)
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL COMMENT 'approve_payment, suspend_customer, block_device...',
  `target_type` VARCHAR(32) DEFAULT NULL COMMENT 'customer|device|bot|order|apk',
  `target_id` VARCHAR(64) DEFAULT NULL,
  `details` JSON DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12) Ensure wallets for existing customers
INSERT IGNORE INTO `wallets` (`customer_id`,`balance`) SELECT `id`,0 FROM `customers`;

-- 13) Settings additions
INSERT INTO `settings` (`k`,`v`) VALUES
('sales_bot_token',''),
('sales_bot_username',''),
('card_number','6037-xxxx-xxxx-xxxx'),
('card_holder','BABARPHANTOM'),
('support_telegram','@support'),
('apk_auto_delivery','1')
ON DUPLICATE KEY UPDATE k=k;

SET FOREIGN_KEY_CHECKS=1;
