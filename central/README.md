# BABARPHANTOM Central v2 — پلتفرم مدیریت چندمستقری (بدون ربات فروش)

**نسخه نهایی قابل نصب** — PHP 8.1+ / MySQL 8.0 / HTTPS — بدون وابستگی به ربات فروش

## ساختار
- `public_html/` → آپلود به `public_html` هاست
- `private/` → خارج از `public_html` (غیرقابل دسترسی وب) — شامل `config`, `database`, `security`, `templates`, `apk`, `uploads`
- `docs/` → مستندات فارسی
- `private/config/config.sample.php` → کپی به `config.php` و پر کنید (DB, ENCRYPTION_KEY_B64, APP_URL)

## نصب فوق‌سریع (توصیه می‌شود — نصاب تحت وب)
> **فقط آپلود + یک صفحه — بدون تنظیم دستی**
1. فایل `BABARPHANTOM_HOST_READY.zip` را در **File Manager → Home (`/home/username`) → Upload → Extract** کن (خودکار `public_html` + `private` سر جایش می‌رود)
2. برو `https://HOST/install.php` → فرم را پر کن: **هاست/نام/یوزر/پسورد دیتابیس** (از cPanel → MySQL Databases) + **آدرس سایت** + **رمز مدیر** → بزن **ساخت خودکار**
   - نصاب خودش جدول‌ها را می‌سازد (`schema`+`migration_v2`+`migration_v3`) + `config.php` با کلیدهای تصادفی + پوشه‌ها را می‌سازد
3. بعد از ✅، **حذف `install.php`** را بزن و برو `https://HOST/admin/` → `admin@local` + رمزی که گذاشتی
4. ادامه: APK در `admin/apk.php`، قالب در `admin/template.php`، ساخت مشتری در `admin/delivery_builder.php` (فقط نام کاربری + توکن)

## نصب دستی (بدون نصاب — قدیمی)
1. در phpMyAdmin به‌ترتیب Import کنید: `private/database/schema.sql` → `migration_v2_sales_platform.sql` → `migration_v3_simplify_customer.sql`
2. `private/config/config.sample.php` را به `config.php` کپی و `DB_*`, `ENCRYPTION_KEY_B64`, `APP_URL` را پر کنید
3. `public_html` را به `public_html` هاست و `private` را خارج از آن قرار دهید
4. مجوز: `700 private/telegram_tokens private/logs private/backups private/templates`, `750 private/apk private/uploads public_html/templates`, `640 private/config/config.php`
5. ورود: `https://HOST/admin/` → `admin@local / Admin123!`
6. کرون: `0 3 * * * php /home/username/private/scripts/backup.php`
7. APK را در `admin/apk.php` آپلود کنید
8. قالب دلخواه: `admin/template.php` → `.html` آپلود
9. ساخت مشتری: `admin/delivery_builder.php` → فقط نام کاربری + توکن

مستندات کامل: `docs/INSTALL_HOST_GUIDE.md` (مرحله‌به‌مرحله فارسی، مطابق نسخه نهایی)

## پنل مدیریت
`docs/ADMIN_GUIDE.md` — داشبورد، مشتریان (فقط نام کاربری، وضعیت ACTIVE/SUSPENDED/DISABLED + قطع ربات + مسدود دستگاه)، کیف پول، پرداخت‌ها، سفارش‌ها، ربات‌ها (توکن مشتری)، دستگاه‌ها، **ساخت دستی** (`delivery_builder.php`), **قالب دلخواه** (`template.php`), APK, گزارش‌ها، تنظیمات.

- هر عملیات در `admin_logs` + `security_events` + `private/logs/security.log` — مدیر مستقل
- هیچ ربات فروشی وجود ندارد — تمام وابستگی‌ها حذف شده

## ساخت دستی
`docs/MANUAL_DELIVERY_GUIDE.md` — فقط **نام کاربری + توکن** → `getMe` → ساخت مشتری (نام یکتا) → ذخیره رمزنگاری → انتخاب APK عمومی → بسته تحویل + لینک دانلود. APK عمومی، بدون کامپایل مجدد، بدون کلید مشترک.

## قالب دلخواه
`admin/template.php` — فایل `.html` آپلود کنید، `webview_url` خودکار به `/templates/custom.html` تغییر می‌کند، APK آن را از `api/config.php` می‌گیرد و لود می‌کند. پیش‌نمایش `iframe` داخل پنل. پنل و همه قابلیت‌ها حفظ.

## امنیت
`docs/SECURITY_REPORT.md` — `AES-256-GCM` برای توکن‌ها، `PDO prepared` (بدون SQLi)، `rate-limit 60/min`، `HTTPS/HSTS`، جداسازی `WHERE customer_id=?`، هر مشتری `api_key` اختصاصی 64hex (نه مشترک)، `revoke`/`reissue`، بکاپ.

## فهرست فایل‌ها
`docs/FILE_LIST.md` — شامل `template.php` و `templates/`

## تست
`docs/TEST_REPORT.md` — تست‌های شبیه‌سازی و واقعی جداگانه

## توجه
هیچ قابلیت نظارتی مخفی، شنود، ثبت کلیدها یا جمع‌آوری بدون رضایت اضافه یا فعال نشده — فقط امکانات مجاز قبلی.
