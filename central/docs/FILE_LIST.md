# فهرست فایل‌های نهایی — BABARPHANTOM Central v2

```
BABARPHANTOM_Central_v2.zip
│
├── public_html/                    ← روی هاست: /public_html/
│   ├── .htaccess                   Apache headers + HTTPS redirect (ready)
│   ├── token.php                   فرم امن HTTPS ثبت توکن (one-time, getMe)
│   ├── api/
│   │   ├── .htaccess
│   │   ├── auth.php                login admin
│   │   ├── customers.php           CRUD مشتریان (admin)
│   │   ├── devices.php             ثبت/لیست دستگاه (APK + admin)
│   │   ├── telegram.php            پروکسی امن Telegram Bot API
│   │   ├── config.php              کانفیگ بدون رمز برای APK
│   │   ├── status.php              health check public
│   │   ├── wallet.php              کیف پول + تراکنش
│   │   ├── payments.php            درخواست شارژ
│   │   ├── orders.php              سفارش با کسر کیف پول + تحویل خودکار
│   │   ├── apk.php                 لیست APK / تحویل‌ها
│   │   ├── delivery.php            دانلود با delivery_token (public)
│   │   └── sales_webhook.php       wrapper وب‌هوک ربات فروش
│   └── admin/
│       ├── index.php               داشبورد + لاگین
│       ├── customers.php           مدیریت مشتریان + وضعیت ACTIVE/SUSPENDED/DISABLED
│       ├── wallets.php             کیف پول + شارژ دستی
│       ├── payments.php            تأیید/رد پرداخت + رسید
│       ├── orders.php              سفارش‌ها + تحویل دستی
│       ├── bots.php                ربات‌ها + لینک امن
│       ├── devices.php             دستگاه‌ها + مسدود/فعال
│       ├── delivery_builder.php    ★ ساخت و تحویل دستی (customer+token+APK→link+send)
│       ├── apk.php                 آپلود/فعال‌سازی APK
│       ├── logs.php                admin_logs + security_events
│       └── settings.php            تنظیمات + بکاپ + تغییر رمز
│
├── private/                        ← روی هاست: خارج از public_html (../private/)
│   ├── .htaccess                   Require all denied
│   ├── config/
│   │   └── config.sample.php       نمونه — کپی به config.php و پر کنید
│   ├── database/
│   │   ├── schema.sql              اسکیما پایه 8 جدول
│   │   └── migration_v2_sales_platform.sql  9 جدول جدید + wallets/orders/apk
│   ├── security/
│   │   └── helpers.php             DB, AES-256-GCM, auth, wallet, token-form, APK, sales bots
│   ├── telegram/
│   │   └── sales_bot.php           منطق ربات فروش (/start, شارژ, رسید, خرید, توکن, APK)
│   ├── apk/
│   │   └── .gitkeep                محل APKهای آپلودشده (روی هاست پر می‌شود)
│   ├── uploads/
│   │   └── .gitkeep                رسیدهای پرداخت
│   ├── backups/
│   │   └── .gitkeep                بکاپ‌های خودکار
│   ├── logs/
│   │   └── .gitkeep                security.log, sales_bot.log
│   ├── telegram_tokens/
│   │   └── .gitkeep                فایل‌های .enc رمزنگاری‌شده (0600)
│   └── scripts/
│       └── backup.php              بکاپ خودکار (mysqldump + tar + rotate)
│
└── docs/
    ├── ANALYSIS.md                 بررسی Quj و تصمیم پروکسی
    ├── API.md                      مرجع API + پنل
    ├── APK_INTEGRATION.md          اتصال APK به سرور
    ├── INSTALL.md                  راهنمای نصب خلاصه
    ├── INSTALL_HOST_GUIDE.md       ★ آموزش تصویری مرحله‌به‌مرحله هاست
    ├── ADMIN_GUIDE.md              ★ راهنمای پنل مدیریت
    ├── MANUAL_DELIVERY_GUIDE.md    ★ راهنمای ساخت دستی APK
    ├── TEST_REPORT.md              گزارش تست v2
    ├── SECURITY_REPORT.md          ★ گزارش امنیت
    └── FILE_LIST.md                همین فهرست
```

**حجم تقریبی ZIP:** ~200KB کد (بدون APK)
**APK داخل ZIP نیست** — جداگانه در `admin/apk.php` آپلود می‌شود.

**فایل‌های حساس که در ZIP نیستند (روی هاست ساخته می‌شوند):**
- `private/config/config.php`
- `private/telegram_tokens/*.enc`
- `private/uploads/*` (رسیدها)
- `private/backups/*`
- `private/logs/*.log`
- `private/apk/*.apk`
