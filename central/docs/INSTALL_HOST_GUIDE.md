# آموزش نصب مرحله‌به‌مرحله روی هاست — BABARPHANTOM Central v2 (نهایی — بدون ربات فروش + قالب دلخواه)

> هاست اشتراکی (cPanel) یا VPS (Ubuntu/Debian + Apache/Nginx). تمام مسیرها دقیق، مطابق نسخه نهایی (ایمیل/رمز مشتری حذف، ربات فروش حذف، قالب دلخواه).

---

## پیش‌نیازها

| مورد | مقدار |
|---|---|
| PHP | 8.1 / 8.2 / 8.3 |
| افزونه | `pdo_mysql, openssl, curl, json, mbstring, fileinfo` |
| MySQL | 8.0 / MariaDB 10.6+ |
| HTTPS | Let's Encrypt (الزامی — فرم توکن و تحویل) |
| دسترسی | File Manager + phpMyAdmin + Cron |
| فضا | 200MB کد + APK (~20MB) + قالب |

---

## گام 1 — دیتابیس (بدون حذف اطلاعات)

1. **cPanel → MySQL® Databases** → دیتابیس `babar_central` + کاربر `babar_user` + رمز قوی → ALL PRIVILEGES.
2. **phpMyAdmin → Import** به‌ترتیب:
   1. `private/database/schema.sql` (جداول پایه 8)
   2. `private/database/migration_v2_sales_platform.sql` (10 جدول فروش + کیف پول)
   3. `private/database/migration_v3_simplify_customer.sql` **(ساده‌سازی نهایی)**
      - `ALTER customers MODIFY email NULL, password_hash NULL` — ایمیل/رمز مشتری نال
      - `DROP INDEX uniq_email` — حذف یکتایی ایمیل
      - `UPDATE name='' → user_id` + `ADD UNIQUE uniq_name(name)` — نام کاربری یکتا
      - `INSERT IGNORE wallets` — حفظ کیف پول‌های قبلی
      - **هیچ داده‌ای حذف نمی‌شود** — `admin@local` با ایمیل/رمز و `password_hash` محفوظ می‌ماند
3. تست:
   ```sql
   SHOW TABLES; -- 18 جدول
   SELECT id,name,email,status FROM customers; -- admin@local با email دارد، بقیه name یکتا و email NULL
   SELECT k FROM settings WHERE k='migration_v3'; -- done
   ```

---

## گام 2 — آپلود ZIP

> فایل: `BABARPHANTOM_Central_v2.zip` (بدون ربات فروش، با `admin/template.php` و `public_html/templates/`)

1. **File Manager → public_html** → Upload ZIP → Extract به `public_html` (یا پوشه موقت).
2. ساختار پس از Extract:
   ```
   BABARPHANTOM_Central_v2/
   ├── public_html/ (api, admin, templates, token.php, .htaccess)
   ├── private/ (config, database, security, templates, apk, uploads, logs, scripts)
   └── docs/
   ```
3. **محتوی `public_html/` داخل ZIP را** به ریشه `public_html` هاست منتقل کنید.
4. **`private/` را خارج از `public_html` قرار دهید** — بسیار مهم:
   - یک سطح بالا (Up One Level) → Move `private` به `/home/username/private`
   - هرگز `private` داخل `public_html` نگذارید (توکن‌ها، `config.php` لو می‌رود)
   ```
   [HOST ROOT]
   ├── private/  <-- اینجا (700)
   └── public_html/ <-- اینجا
   ```

---

## گام 3 — config.php

1. `private/config/config.sample.php` → **Copy** به `config.php` (همان پوشه)
2. Edit و پر کنید:
```php
define('DB_HOST','localhost');
define('DB_NAME','babar_central');
define('DB_USER','babar_user');
define('DB_PASS','رمز_قوی');
define('ENCRYPTION_KEY_B64','...'); // گام 4
define('JWT_SECRET','...');
define('APP_URL','https://your-host.example.com'); // با https، بدون / انتها
```
3. ذخیره. **SALES_BOT_TOKEN وجود ندارد** — ربات فروش حذف شده.

### گام 4 — کلید رمزنگاری
```bash
openssl rand -base64 32  # -> ENCRYPTION_KEY_B64 (44 کاراکتر)
openssl rand -base64 64  # -> JWT_SECRET
```
بدون SSH: از `https://generate-random.org/encryption-key`

---

## گام 5 — مجوزها

File Manager → Permissions یا SSH:
```
chmod 700 private/telegram_tokens private/logs private/backups private/templates
chmod 750 private/apk private/uploads public_html/templates
chmod 640 private/config/config.php
chmod 644 public_html/.htaccess public_html/templates/.htaccess
```

---

## گام 6 — HTTPS

cPanel → SSL/TLS Status → AutoSSL → در `public_html/.htaccess` این را Uncomment:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

---

## گام 7 — مدیر اولیه (مستقل)

1. بروید `https://HOST/admin/` → `admin@local` / `Admin123!`
2. **تنظیمات → تغییر رمز مدیر** → رمز قوی (bcrypt cost 12، `is_admin` با `email===admin@local` امن)
3. ورود مدیر کاملاً مستقل — مشتریان با نام کاربری ساخته می‌شوند، ورود آنها با API Key (نه ایمیل/رمز)

---

## گام 8 — قالب دلخواه HTML (جدید)

> APK طرح را از `api/config.php → webview_url` می‌گیرد.

1. در پنل: **قالب دلخواه** (`admin/template.php`)
2. فایل `.html` (تا 5MB، بدون `<?php`) آپلود → خودکار ذخیره در `public_html/templates/custom.html` + پشتیبان `private/templates/custom.html` + `webview_url` به `https://HOST/templates/custom.html`
3. پیش‌نمایش `iframe` همانجا — طرح داخل برنامه دقیقاً مثل همین HTML می‌شود.
4. بعد توکن را در ساخت دستی بگذارید و نصب کنید — پنل و همه قابلیت‌ها حفظ.

---

## گام 9 — APK

`admin/apk.php` → نسخه مثل `1.0.0` + `versionCode 1` → تیک `فعال` → آپلود APK (~20MB، PK header، SHA256)

---

## گام 10 — ساخت مشتری و تحویل (فقط نام کاربری + توکن)

1. **ساخت دستی** → `نام کاربری (مثال ali_123)` + `توکن ربات تلگرام مشتری (123456:AAH...)` + انتخاب APK → **ساخت مشتری و بسته تحویل**
   - اعتبارسنجی `getMe` (curl به `api.telegram.org`)
   - نام تکراری → `نام کاربری تکراری است`
   - ایمیل/رمز **کاملاً حذف** — `INSERT customers(uuid,null,null,name,ACTIVE)` + `wallets` + `api_keys` (64hex اختصاصی)
   - توکن `AES-256-GCM` → `telegram_bots` + `.enc` (0600)
   - سفارش `manual_delivery` + `apk_deliveries` (`delivery_token`+`api_key` اختصاصی، 30 روز) — **نه کلید مشترک**
2. نتیجه: لینک `https://HOST/api/delivery.php?t=...` + API Key → دانلود دستی (ربات فروش وجود ندارد)

---

## گام 11 — مدیریت دسترسی

- `مشتریان` → `ACTIVE` / `SUSPENDED` / `DISABLED` (همراه `devices is_active` خودکار)
- `ربات‌ها` → قطع ربات ( `is_connected=0` → پروکسی 403)
- `دستگاه‌ها` → مسدود/فعال تکی
- همه سرور-side — APK 403/410 می‌گیرد

---

## گام 12 — Cron

cPanel → Cron Jobs:
```
0 3 * * * php /home/username/private/scripts/backup.php >> /home/username/private/logs/backup.log 2>&1
```
تست: `admin/settings.php → بکاپ فوری`

---

## تست نهایی

| تست | چگونه | انتظار |
|---|---|---|
| سلامت | `https://HOST/api/status.php` | `{"ok":true}` |
| ورود مدیر | `admin@local / Admin123!` | 200 + داشبورد |
| ایمیل مشتری حذف | `SELECT email FROM customers WHERE name='ali_123'` | `NULL` |
| ساخت مشتری API | `POST /api/customers.php {name, status}` | 201 + `api_key` 64hex یکتا |
| تکراری username | دوباره همان `name` | 400 `نام کاربری تکراری است` |
| توکن getMe | توکن نامعتبر در ساخت دستی | خطای `توکن نامعتبر` و عدم ذخیره |
| API Key اختصاصی | دو مشتری بساز، `api_keys` را ببین | دو کلید متفاوت 64hex |
| قالب | `template.php` → `test.html` آپلود → `GET /api/config.php` با `X-API-Key` | `webview_url` = `https://HOST/templates/custom.html` + `GET /templates/custom.html` 200 |
| تعلیق | `customers.php → SUSPENDED` → `POST /api/telegram.php` با همان `X-API-Key` | 403 |
| Migration بدون حذف | قبل و بعد `SELECT COUNT(*) FROM customers` | برابر + admin حفظ |

لاگ: `private/logs/security.log`

---

## فهرست فایل → مقصد

| در ZIP | مقصد |
|---|---|
| `public_html/api/*` | `/public_html/api/` |
| `public_html/admin/*` (شامل `template.php`) | `/public_html/admin/` |
| `public_html/templates/.htaccess` | `/public_html/templates/` |
| `public_html/token.php` | `/public_html/token.php` |
| `private/config/config.sample.php` | `/private/config/` → کپی به `config.php` |
| `private/database/*.sql` (3 فایل) | Import به‌ترتیب |
| `private/security/helpers.php` | `/private/security/` |
| `private/templates/.htaccess` | `/private/templates/` |
| `private/scripts/backup.php` | `/private/scripts/` |

**پس از نصب:** `docs/` را لازم نیست آپلود کنید.

---

## عیب‌یابی

- `Invalid API key` → `api_keys` خالی یا `status != ACTIVE`
- `نام کاربری تکراری` → نام دیگری
- `توکن نامعتبر` → `@BotFather` را چک کن
- `HTTPS required` → روی http تست می‌کنی — `ENV=development` موقت
- دانلود 410 → لینک منقضی — `صدور مجدد`
- قالب لود نشد → `GET /templates/custom.html` را مستقیم تست کن (باید 200 + HTML)
