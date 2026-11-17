# BABARPHANTOM Central v2 — پلتفرم فروش خودکار

**نسخه نهایی قابل نصب** — PHP 8.1+ / MySQL 8.0 / HTTPS

## ساختار
- `public_html/` → آپلود به `public_html` هاست
- `private/` → خارج از `public_html` (غیرقابل دسترسی وب)
- `docs/` → مستندات
- `private/config/config.sample.php` → کپی به `config.php` و پر کنید

## نصب سریع
1. `private/database/schema.sql` سپس `migration_v2_sales_platform.sql` را در phpMyAdmin Import کنید.
2. `private/config/config.sample.php` را به `config.php` کپی و `DB_*`, `ENCRYPTION_KEY_B64` (`openssl rand -base64 32`), `APP_URL`, `SALES_BOT_TOKEN` را پر کنید.
3. `public_html` را به `public_html` هاست و `private` را خارج از آن قرار دهید.
4. مجوز: `700 private/telegram_tokens private/logs private/backups`, `750 private/apk private/uploads`, `640 private/config/config.php`
5. وب‌هوک: `https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://HOST/api/sales_webhook.php`
6. ورود: `https://HOST/admin/` → `admin@local / Admin123!` → تغییر رمز
7. کرون: `0 3 * * * php /path/private/scripts/backup.php`
8. APK را در `admin/apk.php` آپلود کنید.

مستندات کامل: `docs/INSTALL_HOST_GUIDE.md` (مرحله‌به‌مرحله با مسیر دقیق)

## پنل مدیریت
`docs/ADMIN_GUIDE.md` — داشبورد، مشتریان (ACTIVE/SUSPENDED/DISABLED), کیف پول, پرداخت‌ها, سفارش‌ها, ربات‌ها, دستگاه‌ها, **ساخت دستی** (`delivery_builder.php`), APK, گزارش‌ها, تنظیمات.

## ساخت دستی
`docs/MANUAL_DELIVERY_GUIDE.md` — انتخاب مشتری + ورود توکن (getMe + AES-256-GCM) + انتخاب APK عمومی + ساخت بسته (`delivery_token`+`api_key`) + لینک دانلود + ارسال via ربات فروش. APK عمومی است، نیازی به کامپایل مجدد نیست (توضیح شفاف در راهنما).

## امنیت
`docs/SECURITY_REPORT.md` — رمزنگاری, جداسازی `customer_id`, 403/410, revoke, بکاپ, لاگ.

## فهرست فایل‌ها
`docs/FILE_LIST.md`
