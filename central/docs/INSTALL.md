# راهنمای نصب — BABARPHANTOM Central (نهایی — بدون ربات فروش + قالب دلخواه)

**نسخه:** 2.1 نهایی (بدون ربات فروش، با قالب HTML)  
**هاست:** Linux, Apache/Nginx, PHP 8.1+, MySQL 8.0+, HTTPS, Cron

---

## 1) ساختار روی هاست

```
/ (Home)
├── private/              ← خارج از public_html، غیرقابل دسترسی وب
│   ├── config/config.php (خارج از گیت، 640)
│   ├── database/ (schema + migration_v2 + migration_v3)
│   ├── security/helpers.php (AES-256-GCM، بدون sales bot)
│   ├── templates/ (custom.html پشتیبان، 700)
│   ├── apk/ (750)
│   ├── uploads/ (750)
│   ├── telegram_tokens/ (700)
│   ├── logs/ (700)
│   └── scripts/backup.php
└── public_html/          ← محتوای central/public_html
    ├── api/ (auth, customers, devices, telegram, config, status, wallet, orders, payments, apk, delivery)
    ├── admin/ (index, customers, wallets, payments, orders, bots, devices, delivery_builder, apk, template, logs, settings)
    ├── templates/ (custom.html عمومی، 750، deny php)
    ├── token.php (فرم امن HTTPS ثبت توکن)
    └── .htaccess
```

> `private/` هرگز داخل `public_html` نباشد.

---

## 2) پایگاه داده (بدون حذف)

```bash
mysql -h localhost -u babar_user -p babar_central < private/database/schema.sql
mysql -h localhost -u babar_user -p babar_central < private/database/migration_v2_sales_platform.sql
mysql -h localhost -u babar_user -p babar_central < private/database/migration_v3_simplify_customer.sql
# v3: email/password NULL برای مشتریان، name UNIQUE، حفظ admin@local، INSERT IGNORE wallets
mysql -u babar_user -p -e "SHOW TABLES" babar_central
# 18 جدول + admin@local ACTIVE
```

مدیر پیش‌فرض: `admin@local / Admin123!` — پس از ورود از `admin/settings.php` عوض کنید. ورود مدیر با ایمیل/رمز مستقل و امن (bcrypt cost 12، `is_admin` با `email===admin@local`).

---

## 3) تنظیمات

```bash
cp private/config/config.sample.php private/config/config.php
nano private/config/config.php
```

```php
define('DB_HOST','localhost');
define('DB_NAME','babar_central');
define('DB_USER','babar_user');
define('DB_PASS','...');
define('ENCRYPTION_KEY_B64','PUT_openssl_rand_-base64_32_HERE');
define('JWT_SECRET','PUT_openssl_rand_-base64_64_HERE');
define('APP_URL','https://your-host.example.com'); // بدون / انتها
```

```bash
openssl rand -base64 32  # ENCRYPTION_KEY_B64
openssl rand -base64 64  # JWT_SECRET
chmod 700 private/telegram_tokens private/logs private/backups private/templates
chmod 750 private/apk private/uploads public_html/templates
chmod 640 private/config/config.php
```

**SALES_BOT_TOKEN وجود ندارد** — ربات فروش کامل حذف شده، هیچ وابستگی نیست.

---

## 4) قالب دلخواه (جدید)

> APK طرح را از `api/config.php → webview_url` می‌گیرد.

- `admin/template.php` → فایل `.html` آپلود (5MB، بدون `<?php`) → ذخیره در `public_html/templates/custom.html` + `private/templates/custom.html` → `webview_url` خودکار به `https://HOST/templates/custom.html`
- پیش‌نمایش `iframe` داخل پنل

---

## 5) APK

`admin/apk.php` → `version`, `versionCode`, فعال → آپلود → SHA256. مسیر `private/apk/` خارج از وب، فقط از `api/delivery.php?t=<token>` قابل دانلود. توکن هرگز داخل APK نیست — هر تحویل `api_key` اختصاصی 64hex.

---

## 6) Cron

```bash
crontab -e
0 3 * * * php /home/user/private/scripts/backup.php >> /home/user/private/logs/backup.log 2>&1
```

---

## 7) HTTPS

Let's Encrypt فعال → در `.htaccess` Uncomment:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

---

## 8) تست سلامت

```bash
curl https://HOST/api/status.php  # {"ok":true}
# ورود: https://HOST/admin/ → admin@local / Admin123!
```

---

## 9) عیب‌یابی

| مشکل | دلیل |
|---|---|
| `Invalid API key` | `api_keys` خالی یا `status != ACTIVE` |
| `HTTPS required` | روی http — ENV=development موقت |
| قالب لود نشد | `GET /templates/custom.html` را مستقیم تست کن (باید 200) |
| دانلود 410 | `expires_at` گذشته — `صدور مجدد` |
| پرداخت pending | در `admin/payments.php` تأیید کن |

لاگ: `private/logs/security.log` و `backup.log` — هیچ `sales_bot.log` نیست (حذف شده).

---

## 10) ساخت مشتری و تحویل (فقط نام کاربری + توکن)

`admin/delivery_builder.php` → نام کاربری یکتا (3-32) + توکن ربات → `getMe` → `create_customer_by_username` (uuid,null,null) → `encrypt_token` → `telegram_bots` + `.enc` → انتخاب APK → `apk_deliveries` (`delivery_token` + `api_key` اختصاصی) → لینک دستی. بدون ایمیل/رمز.

