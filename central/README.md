# BABARPHANTOM Central v2 — پلتفرم مدیریت چندمستقری (بدون ربات فروش)

**نسخه نهایی قابل نصب** — PHP 8.1+ / MySQL 8.0 / HTTPS — بدون وابستگی به ربات فروش

## ساختار
- `public_html/` → آپلود به `public_html` هاست
- `private/` → خارج از `public_html` (غیرقابل دسترسی وب) — شامل `config`, `database`, `security`, `templates`, `apk`, `uploads`
- `docs/` → مستندات فارسی
- `private/config/config.sample.php` → کپی به `config.php` و پر کنید (DB, ENCRYPTION_KEY_B64, APP_URL)

## نصب سریع (بدون ربات فروش)
1. در phpMyAdmin به‌ترتیب Import کنید: `private/database/schema.sql` → `migration_v2_sales_platform.sql` → `migration_v3_simplify_customer.sql` (ساده‌سازی: نام کاربری یکتا، بدون ایمیل/رمز، حفظ اطلاعات قبلی)
2. `private/config/config.sample.php` را به `config.php` کپی و `DB_*`, `ENCRYPTION_KEY_B64` (`openssl rand -base64 32`), `APP_URL` را پر کنید
3. `public_html` را به `public_html` هاست و `private` را خارج از آن قرار دهید (هرگز `private` داخل `public_html` نگذارید)
4. مجوز: `700 private/telegram_tokens private/logs private/backups private/templates`, `750 private/apk private/uploads public_html/templates`, `640 private/config/config.php`
5. ورود: `https://HOST/admin/` → `admin@local / Admin123!` → از تنظیمات رمز را عوض کنید (ورود مدیر با ایمیل/رمز مستقل، امن)
6. کرون: `0 3 * * * php /home/username/private/scripts/backup.php`
7. APK را در `admin/apk.php` آپلود کنید (فقط یکی فعال)
8. قالب دلخواه (اختیاری): `admin/template.php` → فایل `.html` آپلود → طرح داخل برنامه دقیقاً مثل همون می‌شود (ذخیره در `public_html/templates/custom.html` + `private/templates/custom.html`، `webview_url` خودکار)
9. ساخت مشتری: `admin/delivery_builder.php` → فقط **نام کاربری + توکن ربات** → اعتبارسنجی `getMe` → ذخیره رمزنگاری `AES-256-GCM` → بسته تحویل (`delivery_token`+`api_key` اختصاصی، 30 روز) — بدون ایمیل/رمز

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
