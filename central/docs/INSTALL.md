# راهنمای نصب — BABARPHANTOM Central Sales Platform

**نسخه:** 2.0 (پلتفرم فروش خودکار)  
**هاست مورد نیاز:** Linux, Apache/Nginx, PHP 8.1+, MySQL 8.0+, HTTPS, cron

---

## 1) ساختار فایل روی هاست

```
/
├── public_html/          ← محتوای central/public_html را اینجا بریزید
│   ├── api/              (auth, customers, devices, telegram, config, status, wallet, orders, payments, apk, delivery, sales_webhook)
│   ├── admin/            (index, customers, wallets, payments, orders, bots, devices, apk, logs, settings)
│   ├── token.php         (فرم امن HTTPS ثبت توکن)
│   └── .htaccess
└── private/              ← خارج از public_html، غیرقابل دسترسی از وب
    ├── config/config.php
    ├── database/
    ├── security/
    ├── telegram/sales_bot.php
    ├── apk/              (فایل‌های APK)
    ├── uploads/          (رسیدها)
    ├── telegram_tokens/  (600)
    ├── logs/
    ├── backups/
    └── scripts/backup.php
```

> **مهم:** `private/` هرگز داخل `public_html` نباشد. اگر هاست فقط `public_html` می‌دهد، `private` را یک سطح بالاتر (مثل `/home/user/private/`) بگذارید و در `config.php` ثابت `PRIVATE_ROOT` را ویرایش کنید.

---

## 2) پایگاه داده

```bash
# 1) دیتابیس و کاربر بسازید (در cPanel یا phpMyAdmin)
# DB: babar_central  USER: babar_user  PASS: StrongPass!

# 2) ایمپورت اسکیما
mysql -h localhost -u babar_user -p babar_central < private/database/schema.sql
mysql -h localhost -u babar_user -p babar_central < private/database/migration_v2_sales_platform.sql

# 3) بررسی
mysql -u babar_user -p -e "SHOW TABLES" babar_central
# باید 17 جدول ببینید: customers, wallets, wallet_transactions, payment_requests, orders, products, apk_files, apk_deliveries, token_forms, sales_sessions, telegram_bots, devices, device_data, api_keys, security_events, admin_logs, rate_limits, settings
```

کاربر پیش‌فرض مدیر: `admin@local / Admin123!` — پس از اولین ورود رمز را از `admin/settings.php` عوض کنید.

---

## 3) تنظیمات

```bash
cp private/config/config.sample.php private/config/config.php
nano private/config/config.php
```

پر کنید:

```php
define('DB_HOST','localhost');
define('DB_NAME','babar_central');
define('DB_USER','babar_user');
define('DB_PASS','...');

define('ENCRYPTION_KEY_B64','PUT_openssl_rand_-base64_32_HERE');
define('JWT_SECRET','PUT_openssl_rand_-base64_64_HERE');

define('APP_URL','https://your-host.example.com');
define('SALES_BOT_TOKEN','123456789:AAH_YOUR_SALES_BOT_TOKEN'); // از @BotFather
define('SALES_BOT_USERNAME','YourSalesBot');
```

کلیدها:

```bash
openssl rand -base64 32   # → ENCRYPTION_KEY_B64
openssl rand -base64 64   # → JWT_SECRET
```

سطح دسترسی:

```bash
chmod 700 private/telegram_tokens private/logs private/backups
chmod 750 private/apk private/uploads
chmod 640 private/config/config.php
```

---

## 4) وب‌هوک ربات فروش

بعد از قرار دادن فایل‌ها و تنظیم `SALES_BOT_TOKEN`:

```bash
# ست کردن وب‌هوک (یک بار)
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://your-host.example.com/api/sales_webhook.php"

# بررسی
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
# باید url = https://.../api/sales_webhook.php ببینید

# حذف برای تست پولینگ
# curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

در `admin/settings.php` مقدار `sales_bot_username` و `card_number` را پر کنید تا ربات درست کار کند.

---

## 5) APK

1. APK تأییدشده را در `admin/apk.php` آپلود کنید (نسخه، versionCode، یادداشت، تیک «فعال»).
2. نسخهٔ فعال خودکار به سفارش‌های `paid` تحویل داده می‌شود (اگر `settings.apk_auto_delivery=1`).
3. مسیر ذخیره: `private/apk/BABARPHANTOM_<version>_<time>.apk` — خارج از `public_html`، فقط از طریق `api/delivery.php?t=<token>` قابل دانلود.

**نکته امنیت:** APK هیچ توکتی ندارد. فقط `X-API-Key` اختصاصی هر تحویل به سفارش متصل است و روی سرور نگهداری می‌شود.

---

## 6) بکاپ خودکار و کرون

```bash
crontab -e
# اضافه کنید:
0 3 * * * php /home/user/private/scripts/backup.php >> /home/user/private/logs/backup.log 2>&1
# لاگ‌روتیت هفتگی:
0 4 * * 0 find /home/user/private/backups -type f -mtime +30 -delete
```

بکاپ دستی: `admin/settings.php → بکاپ فوری` یا اجرای مستقیم `php private/scripts/backup.php`.

---

## 7) HTTPS و هدرها

- گواهی Let's Encrypt فعال کنید.
- در `public_html/.htaccess` اگر HTTPS آماده است، ریدایرکت را Uncomment کنید:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

---

## 8) تست سلامت

```bash
curl https://your-host.example.com/api/status.php
# {"ok":true,"checks":{"database":"ok","token_store":"ok","logs":"ok","encryption":"ok","https":"ok"},...}
```

ورود ادمین: `https://your-host.example.com/admin/` → `admin@local / Admin123!`

---

## 9) عیب‌یابی

| مشکل | دلیل |
|---|---|
| `Invalid API key` | `api_keys` خالی یا `status != ACTIVE` یا `SALES_BOT_TOKEN` اشتباه |
| `HTTPS required` | روی http تست می‌کنید — `ENV=production` را موقتاً `development` کنید یا https بگیرید |
| وب‌هوک کار نمی‌کند | `SALES_BOT_TOKEN` خالی یا فایل `private/telegram/sales_bot.php` پرمیشن ندارد یا گواهی https نامعتبر |
| APK دانلود 410 | لینک `expires_at` گذشته — در `apk_deliveries` تمدید کنید |
| پرداخت pending نمی‌شود approved | `payment_requests` را در `admin/payments.php` تأیید کنید — تراکنش کیف پول خودکار است |

لاگ‌ها: `private/logs/security.log` و `private/logs/sales_bot.log` و `private/logs/backup.log`
