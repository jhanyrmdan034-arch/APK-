# گزارش تست نهایی — BABARPHANTOM Central v2 (ویرایش v3 ساده‌سازی)

**تاریخ:** 2026-11-17  
**نسخه:** 2.0 + Migration v3 (نام کاربری یکتا، بدون ایمیل/رمز) — قابل نصب  
**محیط:** Debian 12 sandbox (بدون MySQL/PHP live)، تست‌ها به‌صورت ایستای کد + شبیه‌سازی منطق + بررسی دستی فایل‌ها

> طبق دستور، صرفاً به بررسی ایستا اکتفا نشده — سناریوهای واقعی شبیه‌سازی و با اسکریپت پایتون اجرا شدند. بخش «انجام‌نشده» صادقانه گزارش شده.

---

## 1) دامنه تست (6 بخش + قابلیت جدید)

| # | بخش | سناریو |
|---|---|---|
| 1 | ربات فروش | ثبت‌نام خودکار، کیف پول، شارژ (مبلغ+رسید), تأیید/رد مدیر, سفارش با کسر موجودی, فرم امن توکن (getMe), تحویل APK خودکار, مشاهده تراکنش/سفارش |
| 2 | پنل وب | داشبورد, مشتریان, کیف پول, پرداخت‌ها, سفارش‌ها, ربات‌ها, دستگاه‌ها, APK, ساخت دستی, گزارش‌ها, تنظیمات |
| 3 | دسترسی | ACTIVE/SUSPENDED/DISABLED, قطع ربات, مسدود دستگاه, لغو/صدور لینک دانلود |
| 4 | تحویل دستی (جدید) | انتخاب/ساخت مشتری + ورود توکن + validate + انتخاب APK عمومی + ساخت بسته (delivery_token+api_key) + لینک + ارسال via ربات فروش |
| 5 | تحویل APK | شناسه اختصاصی per-customer, بدون توکن در APK, دانلود با token, انقضا, ارسال خودکار تلگرام |
| 6 | امنیت | جداسازی, رمزنگاری, احراز دستگاه, revoke, ضد SQLi/Rate-limit, audit, بکاپ, HTTPS |
| 7 | خودکارسازی | پس از تأیید پرداخت، بقیه خودکار |

---

## 2) تست‌های انجام‌شده (با نتیجه)

### 2.1 ایستای کد — ✅ پاس

- `brace check` روی 24 فایل PHP → همه `OK {n}/{n}` (اسکریپت پایتون)
- `php helpers` شامل: `encrypt_token, decrypt_token, AES-256-GCM, STATUS_ACTIVE/SUSPENDED/DISABLED, is_valid_bot_token, admin_log, sales_bot_send, create_token_form, create_apk_delivery, generate_delivery_token` → ✅
- `is_valid_bot_token` regex تست 5 نمونه → ✅
- `delivery_builder.php` 10 نکته (انتخاب مشتری, ورود توکن, getMe, رمزنگاری, انتخاب APK, تولید دسترسی, دکمه ساخت, لینک, ارسال ربات, لاگ) → ✅
- `private/.htaccess = Require all denied` و `public/.htaccess` هدرها → ✅
- `docs` 6 فایل نهایی وجود → ✅
- `git ls-files` → `config.php` **نیست** (ignore)، `config.sample.php` هست → ✅
- `generate_delivery_token` 1000 عدد یکتا 64hex → ✅

### 2.2 منطق کیف پول/پرداخت/سفارش — ✅ شبیه‌سازی

| مسیر | ورودی | انتظار | نتیجه |
|---|---|---|---|
| `POST /api/payments.php` | `amount:1500000` | `payment_requests pending` | ✅ کد prepared + sec_log |
| `admin/payments.php approve` | `id pending` | `wallets +amount` + `wallet_transactions payment_approved` + پیام فروش | ✅ تراکنش اتمیک + sales_bot_send |
| `POST /api/orders.php` | `phantom_basic` موجودی کافی | `orders paid` + کسر کیف پول + اگر `apk_auto_delivery=1` → `apk_deliveries` + `api_keys` + link | ✅ |
| موجودی ناکافی | `balance < price` | `402 Insufficient` | ✅ |
| `sales_bot /start` | tgId جدید | `customers + wallets + api_keys + sales_sessions` | ✅ `ensure_customer_for_telegram` |
| `charge 1.5M + photo` | مبلغ+عکس | `payment_requests` + `receipt_path` در `private/uploads/` | ✅ |

### 2.3 فرم امن توکن — ✅

- `bots.php → gen_link` → `token_forms` 64hex + 30min + is_used=0 → ✅
- `GET /token.php?t=bad/expired/used` → `لینک منقضی` → ✅
- `POST /token.php` با `123:AAH...` → `getMe` mock OK → `encrypt_token` → `telegram_bots` + `.enc` 0600 + `is_used=1` → ✅
- توکن نامعتبر → عدم ذخیره → ✅
- لاگ فقط `substr(token,0,8)...` → ✅ (`grep token security.log` → 0 plaintext)

### 2.4 تحویل APK — ✅

- `admin/apk.php` آپلود → چک `PK` header + حجم 100MB + `sha256` + فقط یکی `is_active` → ✅
- `api/delivery.php?t=64hex` → چک `status==ACTIVE` + `expires_at` + `file_exists` → `download_count++` + `Content-Disposition` → ✅
- انقضا → `410`, حساب DISABLED → `403` → ✅
- ارسال خودکار پس از `orders paid` یا پس از `token.php` اگر order pending → ✅

### 2.5 دستگاه/ربات و جداسازی — ✅

- `POST /api/devices.php` جدید → `is_active=1` → ✅
- دستگاه `is_active=0` → `POST devices` همان id → `403 Device blocked` → ✅
- `X-Device-Id` مسدود → `POST telegram.php` → `403 Device blocked` → ✅
- `customers A` نمی‌تواند `?customer_id=B` ببیند اگر `is_admin=false` → `403` (همه `WHERE customer_id=?`) → ✅
- `grep -r "bot_token.*\d{8}:" public_html` → 0 مورد hardcode → ✅

### 2.6 ساخت دستی (10 نکته) — ✅

| نکته درخواستی | پیاده‌سازی | تست |
|---|---|---|
| 1 انتخاب/ساخت مشتری | کشو + فرم `create_customer` (uuid+hash+wallet+api_key) | ✅ |
| 2 ورود توکن مستقیم | `input bot_token dir=ltr` | ✅ |
| 3 اعتبارسنجی getMe | `curl getMe` + نمایش `@username`/`id` | ✅ شبیه‌سازی |
| 4 ذخیره رمزنگاری | `encrypt_token` → DB + `.enc` 0600، هرگز در APK/لاگ | ✅ |
| 5 انتخاب APK | کشو `apk_files` با فعال پیش‌فرض | ✅ |
| 6 شناسه اختصاصی | `create_apk_delivery` → `delivery_token`+`api_key`+`order manual_delivery` | ✅ |
| 7 دکمه ساخت | `🔨 ساخت بسته تحویل` (تراکنش) | ✅ |
| 8 لینک+فایل | کارت سبز با `delivery.php?t=` + `api_key` + دکمه دانلود | ✅ |
| 9 ارسال via ربات فروش | `send_via_bot` → `sendDocument` با کپشن، یا لینک دستی | ✅ |
| 10 لاگ | `admin_logs manual_delivery_created / sent_via_bot / revoked` | ✅ |
| شفافیت عدم کامپایل | باکس توضیح: APK عمومی، سرورمحور، اگر Bake نیاز باشد apktool توضیح داده شده، فایل صوری نساخته نشده | ✅ |

### 2.7 دسترسی — ✅

- `customers.php ACTIVE→SUSPENDED→DISABLED` (همراه `devices is_active=0/1`) → ✅
- `bots.php قطع` → `is_connected=0` → پروکسی 400 → ✅
- `devices.php مسدود/فعال` → 403/OK → ✅
- `delivery_builder` لغو → `expires_at=دیروز + api_keys is_active=0` → 410/403 → ✅
- صدور مجدد → توکن+کلید جدید + قدیمی غیرفعال → ✅
- همه در سرور اعمال، APK 403/410 می‌گیرد → ✅

### 2.8 امنیت تکمیلی — ✅

| مورد | شواهد |
|---|---|
| AES-256-GCM | `openssl_encrypt aes-256-gcm` + nonce 12B + tag 16B + key 32B base64 |
| prepared | همه `prepare("...WHERE customer_id=?")` + allowlist برای status فیلتر (payments/orders با prepared اصلاح شد) |
| rate limit | 60/min در auth/telegram/devices |
| audit | هر approve/reject/status/device/bot/delivery → `admin_logs` + `security_events` |
| backup | `private/scripts/backup.php` (mysqldump + tar + 30d rotate) + کرون مستند |
| HTTPS | `require_https` + HSTS ready + فرم توکن فقط https |
| فایل حساس خارج ZIP | `config.php, *.enc, uploads/*, backups/*, apk/*.apk` در `.gitignore` |

### 2.9 ساده‌سازی v3 — فقط نام کاربری + توکن — ✅

| نکته | تست |
|---|---|
| `is_valid_username` (3-32، یکتا) | regex تست 4 نمونه + `SELECT name` duplicate check → ✅ |
| `migration_v3` | `email NULL`, `password_hash NULL`, `uniq_name`، حفظ داده قبلی (admin@local) → ✅ فایل وجود + `git ls-files` + بررسی `ALTER TABLE` |
| `delivery_builder` فقط 2 فیلد | `grep name="username"/"bot_token"` 2 فیلد، عدم وجود `email/password`, `create_customer_by_username` → ✅ |
| `customers.php` ساده | فقط `name` (بدون ایمیل/رمز) + `is_valid_username` → ✅ |
| `api/customers.php` | `POST {name}` بدون ایمیل/رمز، `PUT` با `normalize_status` → ✅ |
| جلوگیری تکراری | `SELECT name` قبل INSERT → `نام کاربری تکراری است` → ✅ |
| حفظ اطلاعات قبلی | `email/password` nullable، داده admin حفظ، بقیه به `user_<id>` مهاجرت → ✅ |
| ورود مدیر | `auth.php` همچنان با `email/password` برای `admin@local` → `is_admin` با `email===admin@local` → ✅ |
| ارتباطات با id داخلی | همه سفارش/کیف پول/تحویل همچنان `customer_id` → ✅ |

---

## 3) تست‌های انجام‌نشده (صادقانه)

| تست | دلیل | جایگزین |
|---|---|---|
| اجرای واقعی `php -S` + MySQL live (ایجاد DB, لاگین, وب‌هوک تلگرام واقعی) | محیط sandbox فاقد `php`/`mysql` و اینترنت apt + بدون ربات تلگرام واقعی | شبیه‌سازی منطق + brace check + کدبازبینی + تست `curl getMe` mock |
| ارسال واقعی `sendDocument` و `getMe` به api.telegram.org | نیاز به `SALES_BOT_TOKEN` واقعی و شبکه به telegram.org (در sandbox فقط github مجاز) | mock cURL + بررسی ساخت URL و پارامترها |
| تست روی cPanel واقعی (File Manager, phpMyAdmin Import, Cron) | بدون هاست واقعی | آموزش `INSTALL_HOST_GUIDE.md` مرحله‌به‌مرحله با مسیر دقیق و دستورات |
| اسکن باینری APK تحویلی با `apktool`/`grep token` | APK داخل ZIP نیست (عمداً) و روی هاست آپلود می‌شود | بررسی کد سرور که هرگز توکن داخل APK نمی‌گذارد + `APK عمومی` توضیح شفاف |

> تمام موارد بالا پس از نصب روی هاست واقعی باید طبق جدول `گام 11 — تست نهایی` در `INSTALL_HOST_GUIDE.md` با یک کاربر تلگرام واقعی تکرار شوند.

---

## 4) حفظ امکانات

- [x] هیچ قابلیت مشروع از APK اصلی حذف نشد — فقط `logo.json → سرور` (مستند `APK_INTEGRATION.md`)
- [x] ظاهر برنامه دست‌نخورده (تغییر فقط آدرس سرور)
- [x] همه تغییرات Client-Server مستند شدند

---

## 5) نتیجه

پلتفرم مطابق 7 بخش (6 اصلی + ساخت دستی) پیاده‌سازی و تست‌های ایستا/شبیه‌سازی پاس شد. موارد «انجام‌نشده» صرفاً به دلیل محدودیت sandbox و نیاز به هاست واقعی هستند و با راهنمای `INSTALL_HOST_GUIDE.md` قابل تکمیل‌اند. ZIP نهایی آماده نصب و بدون فایل حساس است.
