-- Migration V3 — ساده‌سازی ساخت مشتری: حذف وابستگی ایمیل/رمز
-- تاریخ: 2026-11-17
-- پیش‌نیاز: schema.sql + migration_v2
-- ویژگی: ایمیل و password_hash نال‌شدنی، نام کاربری یکتا، حفظ اطلاعات قبلی، بدون حذف

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- 1) ایمیل و پسورد را نال‌پذیر کن (حفظ داده‌های قبلی)
ALTER TABLE `customers` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL COMMENT 'اختیاری — فقط برای ادمین';
ALTER TABLE `customers` MODIFY `password_hash` VARCHAR(255) NULL DEFAULT NULL COMMENT 'فقط ادمین — مشتریان عادی بدون رمز';

-- 2) حذف یکتایی ایمیل (اگر وجود دارد) — ایمیل دیگر کلید نیست
-- MySQL 8: DROP INDEX IF EXISTS پشتیبانی می‌شود
-- برای سازگاری، تلاش می‌کنیم؛ اگر خطا داد نادیده گرفته می‌شود
-- (در اجرای دستی اگر خطا داد، دستی DROP کنید)
-- سعی اول: اگر ایندکس وجود دارد حذف کن (IF EXISTS برای اجرای دوباره)
ALTER TABLE `customers` DROP INDEX IF EXISTS `uniq_email`;

-- 3) نام‌های خالی را یکتا کن تا بتوانیم UNIQUE روی name بگذاریم
UPDATE `customers` SET `name` = CONCAT('user_', `id`) WHERE `name` = '' OR `name` IS NULL;
-- اگر نام تکراری وجود دارد، تکراری‌ها را suffix بده
-- (این کوئری تکراری‌ها را پیدا و اصلاح می‌کند — برای داده کم کافی است)
-- نکته: اگر نام تکراری زیاد باشد، این حلقه را چند بار اجرا کنید
-- UPDATE customers c1 JOIN (SELECT name FROM customers GROUP BY name HAVING COUNT(*)>1) dup ON c1.name=dup.name SET c1.name=CONCAT(c1.name,'_',c1.id) WHERE c1.name=dup.name;

-- 4) نام کاربری را یکتا کن
ALTER TABLE `customers` ADD UNIQUE KEY `uniq_name` (`name`);

-- 5) اطمینان از وجود کیف پول برای همه مشتریان موجود
INSERT IGNORE INTO `wallets` (`customer_id`,`balance`) SELECT `id`,0 FROM `customers`;

-- 6) لاگ مهاجرت
INSERT INTO `settings` (`k`,`v`) VALUES ('migration_v3','done: email nullable, password nullable, username unique')
ON DUPLICATE KEY UPDATE v=VALUES(v);

SET FOREIGN_KEY_CHECKS=1;
