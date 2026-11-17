# آموزش نصب مرحله‌به‌مرحله روی هاست — BABARPHANTOM Central v2

> این راهنما برای هاست اشتراکی (cPanel) یا VPS (Ubuntu/Debian + Apache/Nginx) کاربرد دارد. تمام مسیرها دقیق گفته شده.

---

## پیش‌نیازها

| مورد | مقدار |
|---|---|
| PHP | 8.1 یا 8.2 یا 8.3 (ترجیحاً 8.1+) |
| افزونه PHP | `pdo_mysql, openssl, curl, json, mbstring, fileinfo` (معمولاً پیش‌فرض فعال) |
| MySQL | 8.0 / MariaDB 10.6+ |
| HTTPS | گواهی Let's Encrypt (اجباری برای فرم توکن و وب‌هوک) |
| دسترسی | File Manager + phpMyAdmin + Cron Jobs |
| فضا | 200MB برای کد + فضای APK (هر APK ~20MB) |

بررسی سریع: در هاست یک فایل `phpinfo.php` با `<?php phpinfo();` بسازید → نسخه و افزونه‌ها را ببینید سپس حذف کنید.

---

## گام 1 — ساخت دیتابیس

1. وارد **cPanel → MySQL® Databases** شوید.
2. یک دیتابیس بسازید: `babar_central` (یا هر نامی).
3. یک کاربر بسازید: `babar_user` با رمز قوی (مثلاً `StrongPass_123!` — یادداشت کنید).
4. کاربر را به دیتابیس اضافه و تیک **ALL PRIVILEGES** بزنید.
5. وارد **phpMyAdmin** → دیتابیس جدید را انتخاب → تب **Import** → فایل `private/database/schema.sql` را آپلود → **Go**.
6. دوباره **Import** → فایل `private/database/migration_v2_sales_platform.sql` → **Go**.
7. در **SQL** این را بزنید برای اطمینان:
   ```sql
   SHOW TABLES;
   -- باید 17 جدول ببینید
   SELECT email,status FROM customers;
   -- باید admin@local ACTIVE باشد
   ```

---

## گام 2 — آپلود و استخراج ZIP

> فایل دریافتی: `BABARPHANTOM_Central_v2.zip` (آماده نصب، بدون رمز/توکن)

1. در cPanel → **File Manager** → به `public_html` بروید (اگر هاست چند دامنه دارد، به `public_html/your-domain/`).
2. **Upload** → ZIP را آپلود کنید.
3. راست‌کلیک → **Extract** → مقصد را `public_html` بگذارید (یا یک پوشه موقت).
4. پس از استخراج، ساختار زیر را می‌بینید:
   ```
   BABARPHANTOM_Central_v2/
   ├── public_html/   (api, admin, token.php, .htaccess)
   ├── private/       (config, database, security, telegram, apk, uploads, logs, scripts)
   └── docs/
   ```
5. **محتوای `public_html/` داخل ZIP را** به ریشه `public_html` هاست منتقل کنید (Drag & Drop):
   - `public_html/api/` → `/public_html/api/`
   - `public_html/admin/` → `/public_html/admin/`
   - `public_html/token.php` → `/public_html/token.php`
   - `public_html/.htaccess` → `/public_html/.htaccess`
6. **پوشه `private/` را خارج از `public_html` قرار دهید** — بسیار مهم:
   - در File Manager یک سطح بالا بروید (دکمه **Up One Level**).
   - اگر قبلاً `private/` آنجا نیست، پوشه را از محل استخراج به آنجا **Move** کنید → مسیر نهایی `/home/username/private/` (یا `/home/username/BABARPHANTOM_Central_v2/private/` اگر هاست اجازه فقط داخل public_html می‌دهد، با پشتیبانی تماس بگیرید — اما هرگز `private` را داخل `public_html` نگذارید).
   - اگر هاست فقط `public_html` می‌دهد، `private` را در `public_html/../private` بسازید و در `public_html/api/*.php` ثابت `PRIVATE_ROOT` را در `config.php` تنظیم کنید.

**تصویر ذهنی:**
```
[HOST ROOT]
├── private/  <-- اینجا (غیرقابل دسترسی از وب)
│   ├── config/config.php
│   ├── apk/
│   └── ...
└── public_html/  <-- اینجا (قابل دسترسی)
    ├── api/
    ├── admin/
    └── token.php
```

---

## گام 3 — تنظیم config.php

1. در File Manager به `private/config/` بروید.
2. فایل `config.sample.php` را **Copy** کنید به نام `config.php` (راست‌کلیک → Copy).
3. `config.php` را **Edit** کنید و مقادیر زیر را پر کنید:

```php
define('DB_HOST','localhost'); // معمولاً localhost
define('DB_NAME','babar_central'); // نام ساخته‌شده در گام 1
define('DB_USER','babar_user');
define('DB_PASS','StrongPass_123!'); // رمز همان کاربر

define('ENCRYPTION_KEY_B64','...'); // گام 4
define('JWT_SECRET','...');

define('APP_URL','https://your-host.example.com'); // دامنه با https
define('SALES_BOT_TOKEN','123456789:AAH...'); // از @BotFather — گام 8
define('SALES_BOT_USERNAME','YourSalesBot');
```

4. ذخیره.

### گام 4 — تولید کلید رمزنگاری

در ترمینال هاست (یا لوکال) بزنید:

```bash
openssl rand -base64 32
# خروجی مثل: q1a2b3c4... (44 کاراکتر با =) → در ENCRYPTION_KEY_B64 بگذارید

openssl rand -base64 64
# → در JWT_SECRET
```

در cPanel بدون SSH: از سایت `https://generate-random.org/encryption-key` یا در لوکال Windows `openssl` از Git Bash.

> هرگز کلید را در گیت/ZIP عمومی نگذارید.

---

## گام 5 — مجوز پوشه‌ها

در File Manager راست‌کلیک → **Permissions**:

```
private/telegram_tokens  700
private/logs             700
private/backups          700
private/apk              750
private/uploads          750
private/config/config.php 640
public_html/.htaccess    644
```

یا via SSH:
```bash
chmod 700 private/telegram_tokens private/logs private/backups
chmod 750 private/apk private/uploads
chmod 640 private/config/config.php
```

---

## گام 6 — HTTPS

1. cPanel → **SSL/TLS Status** → **AutoSSL** یا **Let's Encrypt** → دامنه را فعال کنید.
2. پس از فعال‌شدن، در `public_html/.htaccess` این دو خط را **Uncomment** کنید (حذف #):
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```
3. تست: `http://your-host` باید به `https://` ریدایرکت شود.

---

## گام 7 — وب‌هوک ربات فروش

1. در تلگرام به @BotFather → `/newbot` → نام و username بسازید → توکن را کپی.
2. توکن را در `private/config/config.php` در `SALES_BOT_TOKEN` و `SALES_BOT_USERNAME` بگذارید.
3. وب‌هوک را ست کنید (مرورگر):
```
https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://your-host.example.com/api/sales_webhook.php
```
پاسخ باید `{"ok":true}` باشد.

4. بررسی:
```
https://api.telegram.org/bot<TOKEN>/getWebhookInfo
```
باید `url` درست را نشان دهد.

5. در `admin/settings.php` مقدار `sales_bot_username` و `card_number` (برای شارژ) را پر کنید.

---

## گام 8 — مدیر اولیه و تغییر رمز

1. بروید `https://your-host.example.com/admin/`
2. ورود: `admin@local` / `Admin123!`
3. بلافاصله **تنظیمات → تغییر رمز مدیر** → رمز قوی جدید.
4. در **تنظیمات** سایر مقادیر: `app_name, webview_url, card_number, support_telegram, apk_auto_delivery` را پر و ذخیره.

---

## گام 9 — Cron Job (بکاپ خودکار)

cPanel → **Cron Jobs** → **Add**:

```
Minute: 0   Hour: 3   Day: *   Month: *   Weekday: *
Command: php /home/username/private/scripts/backup.php >> /home/username/private/logs/backup.log 2>&1
```

مسیر دقیق را از File Manager (ستون Path) کپی کنید.

تست دستی: دکمه **بکاپ فوری** در `admin/settings.php` یا SSH:
```bash
php private/scripts/backup.php
# باید در private/backups/backup_*.sql.gz بسازد
```

---

## گام 10 — بارگذاری APK

1. `admin/apk.php` → `انتخاب فایل` (APK ~20MB تأییدشده) → نسخه `1.0.0`, `versionCode 1` → تیک `فعال` → آپلود.
2. بررسی: نسخه فعال سبز شود + SHA256 نمایش داده شود.
3. APK عمومی است — نیازی به کامپایل مجدد برای هر مشتری نیست.

---

## گام 11 — تست نهایی

| تست | چگونه |
|---|---|
| سلامت | `https://HOST/api/status.php` → `ok:true` |
| ربات فروش | در تلگرام `/start` → منو ظاهر شود |
| شارژ | منو `شارژ 10000` → رسید بفرست → در `payments.php` تأیید → کیف پول شارژ شود |
| سفارش | `سفارش phantom_basic` (یا در `orders.php`) → اگر موجودی کافی، تحویل خودکار |
| فرم توکن | `bots.php → ایجاد لینک امن` → کلیک → توکن @BotFather را وارد → `@username متصل` |
| تحویل دستی | `ساخت دستی → انتخاب مشتری + توکن + APK → ساخت بسته → لینک دانلود → ارسال از طریق ربات` |
| جداسازی | با دو مشتری تست: هر کدام فقط داده خود را ببیند |
| دسترسی | در `customers.php` یک مشتری را `SUSPENDED` → API آن 403 → دوباره `ACTIVE` → درست شود |
| لغو لینک | در `ساخت دستی → لغو لینک` → لینک قبلی 410 شود |

لاگ: `private/logs/security.log` و `private/logs/sales_bot.log`

---

## فهرست فایل → کجا قرار بدهم (چک‌لیست)

| فایل در ZIP | مقصد روی هاست |
|---|---|
| `public_html/api/*` | `/public_html/api/` |
| `public_html/admin/*` | `/public_html/admin/` |
| `public_html/token.php` | `/public_html/token.php` |
| `public_html/.htaccess` | `/public_html/.htaccess` |
| `private/config/config.sample.php` | `/private/config/config.sample.php` → کپی به `config.php` |
| `private/database/*.sql` | `/private/database/` (فقط برای Import اولیه) |
| `private/security/helpers.php` | `/private/security/` |
| `private/telegram/sales_bot.php` | `/private/telegram/` |
| `private/scripts/backup.php` | `/private/scripts/` |
| `private/.htaccess` | `/private/.htaccess` (Require all denied) |
| `docs/*` | نیازی به آپلود نیست (مستندات) |

**پس از نصب:** پوشه `docs` و فایل‌های `*.sql` را می‌توانید از هاست حذف کنید (اختیاری).

---

## عیب‌یابی رایج

- `Invalid API key` → `api_keys` خالی یا `status != ACTIVE`
- `HTTPS required` → روی http تست می‌کنید — `ENV=development` موقت کنید
- وب‌هوک 500 → `SALES_BOT_TOKEN` خالی یا پرمیشن `private/telegram/sales_bot.php`
- دانلود 410 → لینک منقضی — `صدور مجدد`
- آپلود APK خطا → حجم >100MB یا پرمیشن `private/apk`
