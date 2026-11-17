# فهرست فایل‌های نهایی — BABARPHANTOM Central v2 (نهایی — بدون ربات فروش + قالب دلخواه)

```
BABARPHANTOM_Central_v2.zip
│
├── public_html/                    ← روی هاست: /public_html/
│   ├── .htaccess                   Apache headers + HTTPS redirect (ready)
│   ├── token.php                   فرم امن HTTPS ثبت توکن (one-time, getMe)
│   ├── templates/
│   │   ├── .htaccess               deny PHP
│   │   └── custom.html             قالب دلخواه آپلودشده (پس از آپلود)
│   ├── api/
│   │   ├── .htaccess
│   │   ├── auth.php                login admin (email/password — فقط مدیر)
│   │   ├── customers.php           CRUD مشتریان (admin — فقط name یکتا)
│   │   ├── devices.php             ثبت/لیست دستگاه (APK + admin)
│   │   ├── telegram.php            پراکسی امن Telegram Bot API (با فیلتر Owner + Rate-limit)
│   │   ├── config.php              کانفیگ بدون رمز برای APK (نام + webview_url)
│   │   ├── status.php              health check public
│   │   ├── wallet.php              کیف پول + تراکنش
│   │   ├── payments.php            درخواست شارژ
│   │   ├── orders.php              سفارش با کسر کیف پول
│   │   ├── apk.php                 لیست APK / تحویل‌ها
│   │   └── delivery.php            دانلود با delivery_token (public)
│   └── admin/
│       ├── index.php               داشبورد + لاگین
│       ├── customers.php           مدیریت مشتریان (فقط نام کاربری 3-32 یکتا) + وضعیت ACTIVE/SUSPENDED/DISABLED
│       ├── wallets.php             کیف پول + شارژ دستی
│       ├── payments.php            تأیید/رد پرداخت + رسید
│       ├── orders.php              سفارش‌ها
│       ├── bots.php                ربات‌ها (توکن مشتری) + لینک امن
│       ├── devices.php             دستگاه‌ها + مسدود/فعال
│       ├── delivery_builder.php    ★ ساخت و تحویل دستی (فقط نام کاربری + توکن → link)
│       ├── apk.php                 آپلود/فعال‌سازی APK
│       ├── template.php            ★ قالب دلخواه — آپلود HTML → webview_url
│       ├── logs.php                admin_logs + security_events
│       └── settings.php            تنظیمات + بکاپ + تغییر رمز
│
├── private/                        ← روی هاست: خارج از public_html (../private/)
│   ├── .htaccess                   Require all denied
│   ├── config/
│   │   └── config.sample.php       نمونه — کپی به config.php و پر کنید (بدون SALES_BOT)
│   ├── database/
│   │   ├── schema.sql              اسکیما پایه 8 جدول
│   │   ├── migration_v2_sales_platform.sql  10 جدول جدید
│   │   └── migration_v3_simplify_customer.sql  ساده‌سازی: email/password nullable، username یکتا (بدون حذف)
│   ├── security/
│   │   └── helpers.php             DB, AES-256-GCM, auth, wallet, token-form, APK (بدون sales bot)
│   ├── templates/
│   │   ├── .htaccess               Require all denied
│   │   └── custom.html             پشتیبان قالب (0600)
│   ├── apk/
│   │   └── .gitkeep                محل APKهای آپلودشده
│   ├── uploads/
│   │   └── .gitkeep                رسیدهای پرداخت
│   ├── backups/
│   │   └── .gitkeep                بکاپ‌ها
│   ├── logs/
│   │   └── .gitkeep                security.log
│   ├── telegram_tokens/
│   │   └── .gitkeep                فایل‌های .enc (0600)
│   └── scripts/
│       └── backup.php              بکاپ خودکار
│
└── docs/
    ├── ANALYSIS.md                 بررسی Quj و تصمیم پروکسی
    ├── API.md                      مرجع API + پنل
    ├── APK_INTEGRATION.md          اتصال APK به سرور (webview_url از قالب)
    ├── INSTALL.md                  راهنمای نصب خلاصه
    ├── INSTALL_HOST_GUIDE.md       ★ آموزش مرحله‌به‌مرحله (نهایی)
    ├── ADMIN_GUIDE.md              ★ راهنمای پنل (بدون ربات فروش + قالب)
    ├── MANUAL_DELIVERY_GUIDE.md    ★ راهنمای ساخت دستی (2 فیلد)
    ├── TEST_REPORT.md              گزارش تست (شبیه‌سازی/واقعی جداگانه)
    ├── SECURITY_REPORT.md          ★ گزارش امنیت (کلید اختصاصی، بدون مشترک)
    └── FILE_LIST.md                همین فهرست
```

**حجم تقریبی ZIP:** ~210KB کد (بدون APK)
**APK داخل ZIP نیست** — در `admin/apk.php` آپلود می‌شود.
**ربات فروش کاملاً حذف** — هیچ فایل `sales_bot.php` / `sales_webhook.php` / `SALES_BOT_TOKEN` در ZIP نیست.

**فایل‌های حساس که در ZIP نیستند (روی هاست ساخته می‌شوند):**
- `private/config/config.php`
- `private/telegram_tokens/*.enc`
- `private/uploads/*`
- `private/backups/*`
- `private/logs/*.log`
- `private/apk/*.apk`
- `private/templates/custom.html` (پس از آپلود)
