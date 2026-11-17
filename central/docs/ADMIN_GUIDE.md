# راهنمای پنل مدیریت — BABARPHANTOM Central v2 (نهایی — بدون ربات فروش)

## ورود
آدرس: `https://your-host.example.com/admin/`  
پیش‌فرض: `admin@local` / `Admin123!` — پس از ورود حتماً از `تنظیمات → تغییر رمز` عوض کنید. ورود مدیر با **ایمیل/رمز مستقل** — مشتریان با نام کاربری یکتا ساخته می‌شوند و با `X-API-Key` به API وصل می‌شوند (بدون ایمیل/رمز).

## منوها

| منو | آدرس | کاربرد |
|---|---|---|
| داشبورد | `admin/index.php` | آمار کل (مشتریان فعال/تعلیق، کیف پول، پرداخت pending، سفارش، APK، لاگ 24ساعت)، سلامت API |
| مشتریان | `admin/customers.php` | افزودن مشتری **فقط نام کاربری** (3-32، یکتا — بدون ایمیل/رمز) + وضعیت، جستجو، تغییر وضعیت `ACTIVE/SUSPENDED/DISABLED`، قطع ربات، حذف |
| کیف پول | `admin/wallets.php` | نمایش موجودی هر مشتری + 3 تراکنش آخر، شارژ/کسر دستی (`adjustment`) با توضیح، فیلتر مشتری |
| پرداخت‌ها | `admin/payments.php` | فیلتر `pending/approved/rejected/all`، مشاهده رسید (در `private/uploads/`), تأیید (کیف پول خودکار) / رد با دلیل — بدون ربات فروش |
| سفارش‌ها | `admin/orders.php` | فیلتر `pending/paid/delivered`، نمایش لینک دانلود تحویل، تحویل دستی APK برای `paid`، لغو `pending` |
| ربات‌ها | `admin/bots.php?customer_id=` | انتخاب مشتری، مشاهده ربات گزارش‌ده (username/وضعیت/خطا), ایجاد **لینک امن** `token.php?t=...` (فرم HTTPS یک‌بارمصرف), ثبت مستقیم توکن (getMe با رمزنگاری AES-256-GCM), تست ارسال, قطع/حذف |
| دستگاه‌ها | `admin/devices.php?customer_id=` | لیست ایزوله `WHERE customer_id=?`, مسدود/فعال تکی (is_active)، حذف — مسدود = 403 در `devices.php` و `telegram.php` |
| ساخت دستی | `admin/delivery_builder.php` | **فقط نام کاربری + توکن** → اعتبارسنجی `getMe` → ساخت مشتری یکتا (بدون ایمیل/رمز) + ذخیره رمزنگاری + انتخاب APK + بسته تحویل (`delivery_token`+`api_key` اختصاصی 30روزه) + لینک دانلود دستی + لغو/صدور مجدد |
| APK | `admin/apk.php` | آپلود APK (چک PK header، حجم 100MB، SHA256)، فعال‌سازی (فقط یکی فعال), حذف (اگر تحویل دارد ممنوع) |
| قالب دلخواه | `admin/template.php` | ★ **جدید** — آپلود فایل `.html` دلخواه → ذخیره در `public_html/templates/custom.html` + `private/templates/custom.html` + تنظیم خودکار `webview_url` → طرح داخل برنامه دقیقاً مثل همون |
| گزارش‌ها | `admin/logs.php` | `admin_logs` (عملیات مدیر) + `security_events` (100 اخیر) |
| تنظیمات | `admin/settings.php` | `app_name, webview_url, poll_interval, card_number, support_telegram, apk_auto_delivery, maintenance`, تغییر رمز مدیر, بکاپ فوری (mysqldump), کرون توضیح — بدون `sales_bot` |

## عملیات حساس و لاگ
هر عملیات در `admin_logs` ثبت: `customer_created, payment_approved, bot_connected, device_blocked, apk_uploaded, manual_delivery_created, delivery_revoked, template_uploaded` و در `security_events` + فایل `private/logs/security.log`.

## نکات مهم
- **ایمیل/رمز مشتری کاملاً حذف** — ساخت مشتری فقط با نام کاربری یکتا و توکن ربات (اعتبارسنجی `getMe`، ذخیره `AES-256-GCM` در `telegram_bots` + `.enc` 0600)
- **مدیر مستقل** — `admin@local` با `password_hash` bcrypt، `is_admin` با `email===admin@local`، ورود با `admin_require_admin`
- **ربات فروش کاملاً حذف** — هیچ فایل/تنظیم `sales_bot` در ZIP نیست، هیچ وابستگی باقی نیست
- تغییر وضعیت مشتری `DISABLED` → دستگاه‌ها خودکار `is_active=0` می‌شوند (سرور اعمال می‌کند)
- ربات قطع (`is_connected=0`) → پروکسی تلگرام برای آن مشتری 400 می‌دهد
- دستگاه مسدود → APK همان `device_id` پاسخ 403 می‌گیرد
- هر مشتری `api_key` اختصاصی 64hex دارد — **نه کلید مشترک** — `apk_deliveries.api_key` + `api_keys` جدا
- قالب HTML: APK `webview_url` را از `api/config.php` می‌گیرد — پس از آپلود قالب، حتماً `GET /api/config.php` را با `X-API-Key` تست کنید
