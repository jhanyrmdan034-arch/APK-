# راهنمای پنل مدیریت — BABARPHANTOM Central v2

## ورود
آدرس: `https://your-host.example.com/admin/`  
پیش‌فرض: `admin@local` / `Admin123!` — پس از ورود حتماً از `تنظیمات → تغییر رمز` عوض کنید.

## منوها

| منو | آدرس | کاربرد |
|---|---|---|
| داشبورد | `admin/index.php` | آمار کل (مشتریان فعال/تعلیق، کیف پول، پرداخت pending، سفارش، APK، لاگ 24ساعت)، سلامت API، لینک فرم توکن، راهنمای خودکارسازی |
| مشتریان | `admin/customers.php` | افزودن مشتری (ایمیل/نام/رمز/وضعیت)، جستجو، تغییر وضعیت `ACTIVE/SUSPENDED/DISABLED` (همراه با فعال/غیرفعال خودکار دستگاه‌ها)، قطع ربات، حذف |
| کیف پول | `admin/wallets.php` | نمایش موجودی هر مشتری + 3 تراکنش آخر، شارژ/کسر دستی (`adjustment`) با توضیح، فیلتر مشتری |
| پرداخت‌ها | `admin/payments.php` | فیلتر `pending/approved/rejected/all`، مشاهده رسید (در `private/uploads/`), تأیید (کیف پول خودکار + پیام ربات) / رد با دلیل |
| سفارش‌ها | `admin/orders.php` | فیلتر `pending/paid/delivered`، نمایش لینک دانلود تحویل، تحویل دستی APK برای `paid`، لغو `pending` |
| ربات‌ها | `admin/bots.php?customer_id=` | انتخاب مشتری، مشاهده ربات (username/وضعیت/خطا), ایجاد **لینک امن** `token.php?t=...` (ارسال خودکار به تلگرام مشتری), ثبت مستقیم توکن (getMe), تست ارسال, قطع/حذف |
| دستگاه‌ها | `admin/devices.php?customer_id=` | لیست ایزوله `WHERE customer_id=?`, مسدود/فعال تکی (is_active)، حذف — مسدود = 403 در `devices.php` و `telegram.php` |
| ساخت دستی | `admin/delivery_builder.php` | **بخش جدید** — انتخاب/ساخت مشتری + ورود توکن + اعتبارسنجی getMe + انتخاب APK عمومی + ساخت بسته تحویل (delivery_token + api_key) + نمایش لینک + ارسال از طریق ربات فروش + مدیریت لغو/صدور مجدد |
| APK | `admin/apk.php` | آپلود APK (چک PK header، حجم 100MB، SHA256)، فعال‌سازی (فقط یکی فعال), حذف (اگر تحویل دارد ممنوع) |
| گزارش‌ها | `admin/logs.php` | `admin_logs` (عملیات مدیر) + `security_events` (100 اخیر) |
| تنظیمات | `admin/settings.php` | `app_name, webview_url, poll_interval, card_number, sales_bot_username, apk_auto_delivery, maintenance`, تغییر رمز مدیر, بکاپ فوری (mysqldump), کرون توضیح |

## عملیات حساس و لاگ
هر عملیات در `admin_logs` ثبت: `customer_created, payment_approved, bot_connected, device_blocked, apk_uploaded, manual_delivery_created, delivery_revoked` و در `security_events` + فایل `private/logs/security.log`.

## نکات
- تغییر وضعیت مشتری `DISABLED` → دستگاه‌ها خودکار `is_active=0` می‌شوند (سرور اعمال می‌کند).
- ربات قطع (`is_connected=0`) → پروکسی تلگرام برای آن مشتری 400 می‌دهد.
- دستگاه مسدود → APK همان device_id پاسخ 403 می‌گیرد.
