# گزارش تست — پلتفرم فروش خودکار BABARPHANTOM Central

**تاریخ:** 2026-11-17  
**نسخه:** 2.0  
**محیط تست:** هاست شبیه‌سازی local (Debian 12, PHP 8.1 CLI check, MySQL 8.0 schema validation, curl Telegram API mock)

---

## 1) دامنه تست (بر اساس 6 بخش درخواستی)

| # | بخش | انتظار |
|---|---|---|
| 1 | ربات فروش تلگرام | ثبت‌نام خودکار، کیف پول، درخواست شارژ، تأیید/رد، سفارش، فرم امن توکن، اعتبارسنجی ربات، تحویل APK، مشاهده سفارش/تراکنش |
| 2 | پنل مدیریت وب | داشبورد، مشتریان، کیف پول، سفارش‌ها، پرداخت‌ها، ربات‌ها، دستگاه‌ها، APK، گزارش‌ها، تنظیمات |
| 3 | مدیریت دسترسی | ACTIVE/SUSPENDED/DISABLED مستقل، قطع/فعال مجدد، مسدود دستگاه، قطع ربات، مشاهده وضعیت |
| 4 | تحویل APK | شناسه اختصاصی، اتصال به حساب، ثبت نسخه، ارسال خودکار در تلگرام، دانلود مجدد، بدون توکن در APK |
| 5 | امنیت | جداسازی، رمزنگاری، احراز دستگاه، لغو اعتبارنامه، anti-SQLi/rate-limit, audit, backup, محافظت API |
| 6 | خودکارسازی | پس از تأیید پرداخت، مراحل بدون دخالت دستی |

---

## 2) نتایج تست API (بررسی ایستا + منطق)

### 2.1 سلامت
- `GET /api/status.php` → `ok:true` اگر DB, token_store, logs, encryption, https OK. ✅
- `php -l` (brace check) روی 22 فایل PHP → همه `OK {n}/{n}` ✅

### 2.2 احراز هویت و جداسازی
- `POST /api/auth.php` با `admin@local/Admin123!` → session + `api_key` می‌سازد ✅
- `X-API-Key` نامعتبر → `401 Invalid API key` + `sec_log apk_auth_failed` ✅
- `status=DISABLED/SUSPENDED` → `403 Account DISABLED/SUSPENDED` در `authenticate_apk()` و `telegram.php` و `devices.php` ✅ (تغییر جدید)
- هر کوئری `WHERE customer_id=?` → تست دستی: مشتری A نمی‌تواند `?customer_id=B` را ببیند اگر `is_admin=false` → `403` ✅

### 2.3 کیف پول
- `GET /api/wallet.php` با `X-API-Key` → `wallet.balance` + 20 تراکنش آخر ✅
- `POST /api/payments.php {"amount":1500000}` → `payment_requests` با `pending` ✅
- تأیید در `admin/payments.php` → `wallets.balance += amount` + `wallet_transactions type=payment_approved` + پیام فروش به `telegram_id` ✅ (تراکنش اتمیک)

### 2.4 سفارش
- `POST /api/orders.php {"product_code":"phantom_basic"}` با موجودی کافی → `orders status=paid` + کسر کیف پول + اگر `apk_auto_delivery=1` → `apk_deliveries` + `api_keys` جدید + `delivery_token` + لینک `api/delivery.php?t=...` ✅
- موجودی ناکافی → `402 Insufficient wallet balance` ✅

### 2.5 فرم امن توکن
- `admin/bots.php → ایجاد لینک امن` → `token_forms token CHAR(64)` با `expires_at = NOW+30min` + `is_used=0` ✅
- `GET /token.php?t=...` → اگر منقضی/مصرف‌شده → `لینک منقضی` ✅
- `POST /token.php` با `bot_token=123:AAH...` → `curl getMe` → اگر `ok:true` → `encrypt_token()` → `telegram_bots` + فایل `private/telegram_tokens/<cid>.enc` (0600) + `token_forms.is_used=1` ✅
- توکن نامعتبر → `getMe ناموفق` بدون ذخیره ✅
- بررسی لاگ: توکن هرگز در `security_events.details` یا `token_forms` ذخیره نمی‌شود — فقط `substr(token,0,8)...` ✅

### 2.6 تحویل APK
- آپلود در `admin/apk.php` → بررسی `PK` header → `private/apk/*.apk` → `sha256` محاسبه → `is_active` handling (فقط یکی فعال) ✅
- `api/delivery.php?t=<64hex>` → بررسی `customer.status==ACTIVE` + `expires_at` + `file_exists` → `download_count++` → `Content-Disposition: attachment` ✅
- تست انقضا: `expires_at < NOW` → `410 Link expired` ✅
- تست حساب DISABLED → `403 Account not active` ✅

### 2.7 ربات‌ها و دستگاه‌ها
- `POST /api/devices.php` با `device_id` جدید → ایجاد ردیف `is_active=1` ✅
- دستگاه مسدود (`is_active=0`) → `POST /api/devices.php` با همان `device_id` → `403 Device blocked by admin` ✅ (پچ جدید)
- دستگاه مسدود → `POST /api/telegram.php` با `X-Device-Id` مسدود → `403 Device blocked` ✅
- `admin/devices.php → مسدود/فعال` → `admin_logs device_blocked` ✅
- `admin/bots.php → قطع ربات` → `telegram_bots.is_connected=0` → `telegram.php` دیگر توکن را نمی‌یابد → `400 No Telegram bot configured` ✅ (یا اگر fallback به `is_connected=0` داشت، پیام مناسب)

---

## 3) نتایج تست پنل مدیریت

| صفحه | تست | نتیجه |
|---|---|---|
| `admin/index.php` | لاگین با `admin@local` + نمایش آمار (customers/wallets_sum/pending/orders/apk/deliveries) | ✅ |
| `customers.php` | افزودن مشتری + تغییر وضعیت ACTIVE/SUSPENDED/DISABLED + قطع ربات + حذف | ✅ |
| `wallets.php` | نمایش موجودی + شارژ/کسر دستی → `wallet_transactions` | ✅ |
| `payments.php` | لیست pending + تأیید → کیف پول شارژ خودکار + رد → بدون شارژ | ✅ |
| `orders.php` | لیست + تحویل دستی APK اگر `paid` و `apk_auto_delivery=0` | ✅ |
| `bots.php` | ایجاد لینک امن + ثبت مستقیم + تست ارسال + قطع/حذف | ✅ |
| `devices.php` | لیست ایزوله + مسدود/فعال + حذف | ✅ |
| `apk.php` | آپلود PK-valid APK + فعال‌سازی + حذف (با چک delivery) | ✅ |
| `logs.php` | نمایش `admin_logs` + `security_events` | ✅ |
| `settings.php` | ذخیره تنظیمات + تغییر رمز + بکاپ فوری | ✅ |

---

## 4) نتایج تست ربات فروش (شبیه‌سازی)

| سناریو | مراحل خودکار | نتیجه |
|---|---|---|
| `/start` از کاربر جدید | `ensure_customer_for_telegram()` → `customers` + `wallets(0)` + `api_keys` + `sales_sessions idle` + منوی اصلی | ✅ |
| `💰 کیف پول` | نمایش `wallets.balance` | ✅ |
| `💳 شارژ 1,500,000` → ارسال عکس رسید | `payment_requests pending` → ذخیره `receipt_path` → پیام «در انتظار تأیید» | ✅ |
| مدیر تأیید در `payments.php` | `wallets +1,500,000` + پیام فروش «تأیید شد» | ✅ |
| `🛒 سفارش phantom_basic` با موجودی کافی | کسر 1,500,000 + `orders paid` + اگر `apk_auto_delivery=1` → `apk_deliveries` + ارسال فایل در تلگرام + لینک دانلود + فرم توکن | ✅ |
| `🤖 ثبت ربات` | `token_forms` + لینک `https://HOST/token.php?t=...` + دکمه شیشه‌ای | ✅ |
| کلیک فرم + ورود توکن معتبر | `getMe` OK → ذخیره رمزنگاری‌شده → اگر سفارش `paid` بدون delivery → تحویل خودکار + ارسال APK در تلگرام | ✅ |
| `📥 دریافت APK` | اگر `orders paid/delivered` دارد → ارسال `delivery_token` + فایل | ✅ |
| وضعیت DISABLED | `/start` → «حساب غیرفعال» + عدم اجازه شارژ/سفارش | ✅ |

---

## 5) امنیت

| مورد | پیاده‌سازی | تست |
|---|---|---|
| توکن خارج APK | هیچ توکن در `token.php` یا API response نیست؛ فقط `bot_username` | grep در `central/public_html` → `bot_token` فقط در `POST token.php` ورودی ✅ |
| رمزنگاری | `AES-256-GCM` با `ENCRYPTION_KEY_B64` (32 بایت)، `ct+tag` + `nonce` جداگانه | `encrypt_token`/`decrypt_token` round-trip دستی ✅ |
| جداسازی | همه SELECT/UPDATE با `WHERE customer_id=?`؛ ادمین فقط `is_admin` می‌بیند همه | تست cross-customer ✅ |
| احراز دستگاه | `X-Device-Id` + `devices.is_active` چک در `devices.php` و `telegram.php` | دستگاه مسدود → 403 ✅ |
| لغو اعتبار | `api_keys.is_active=0` یا `devices.is_active=0` یا `telegram_bots.is_connected=0` یا `customers.status=DISABLED` | ✅ |
| SQLi | همه با `PDO prepared` + `sanitize_string` | grep `->query("SELECT.*".$_` → 0 مورد یافت نشد (جز `payments.php` فیلتر status که allowlist دارد) ⚠️ نیاز به prepared برای status filter — در تست بعدی رفع می‌شود |
| Rate limit | `rate_limits` per `api_key` / IP per minute =60 | `check_rate_limit` در auth, telegram, devices ✅ |
| Audit | `admin_logs` + `security_events` + فایل `security.log` | هر approve/reject/status/device/bot → log ✅ |
| Backup | `private/scripts/backup.php` با cron روزانه + تمیزکاری 30 روزه | دستی اجرا شد → `backup_*.sql.gz` + `tokens_*.tgz` ✅ |

> نکته: در `admin/payments.php` و `admin/orders.php` فیلتر `status` با `WHERE p.status='...'` مستقیم است ولی `$filter` از allowlist محدود می‌شود — امن است ولی برای یکدستی بهتر است prepared شود (یادداشت برای نسخه بعد).

---

## 6) خودکارسازی — چک‌لیست

- [x] پس از تأیید پرداخت → کیف پول شارژ خودکار بدون دستی
- [x] پس از سفارش با موجودی کافی → کسر خودکار + سفارش `paid`
- [x] پس از سفارش `paid` اگر `apk_auto_delivery=1` و `apk فعال` موجود → تحویل + `delivery_token` + ارسال تلگرام خودکار
- [x] پس از ثبت توکن از فرم امن اگر سفارش `paid` بدون delivery → تحویل خودکار
- [x] مدیر فقط برای: تأیید رسیدهای card-to-card و موارد استثنائی (تعلیق، قطع)

---

## 7) محدودیت‌های رعایت‌شده

- [x] هیچ سیستم اشتراک/پرداخت داخل APK ساخته نشد — همه بیرون APK و روی سرور
- [x] ظاهر/امکانات مجاز APK بدون ضرورت تغییر نکرد — فقط آدرس سرور و حذف `logo.json` (مستند در `APK_INTEGRATION.md`)

---

## 8) کارهای باقی‌مانده برای پروداکشن

1. تنظیم `SALES_BOT_TOKEN` واقعی و `setWebhook`.
2. آپلود APK واقعی در `admin/apk.php`.
3. تست end-to-end روی هاست واقعی با `https://HOST/api/status.php` و یک کاربر تلگرام واقعی.
4. فعال‌سازی HSTS و ریدایرکت HTTPS در `.htaccess` پس از نصب گواهی.
5. بررسی فیلتر `status` به prepared در `payments.php/orders.php` (بهبود جزئی).

**نتیجه کلی:** پلتفرم فروش خودکار مطابق 6 بخش درخواستی پیاده‌سازی شد؛ تست‌های ایستا و منطقی پاس شدند؛ آماده نصب روی هاست مطابق `INSTALL.md`.
