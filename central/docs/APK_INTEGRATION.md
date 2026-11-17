# اتصال APK به سرور مرکزی

## خلاصه
قبلاً APK توکن را از `assets/logo.json` می‌خواند و مستقیم به `https://api.telegram.org/bot<TOKEN>/` می‌زد (ناامن، تک‌مشتری). پس از انتقال، APK هیچ توکنی ندارد؛ فقط `X-API-Key` خود را می‌فرستد و همه درخواست‌های تلگرام از سرور پروکسی می‌شود.

## تغییرات لازم در کد APK

### 1) حذف `assets/logo.json`
- فایل را حذف یا خالی بگذارید (توکن/چت‌آی‌دی دیگر در APK نیست).
- در `UploadService.java` و `MainActivity.java` این خط را جایگزین کنید:
```java
// قبل:
InputStream is = getAssets().open("logo.json"); // حاوی bot_token

// بعد:
String apiBase = "https://HOST"; // از BuildConfig یا کانفیگ سرور
String apiKey  = BuildConfig.API_KEY; // یا از SharedPreferences پس از اولین login
String deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
```

### 2) کانفیگ مرکزی به جای logo.json
- در شروع اپ: `GET https://HOST/api/config.php` با هدر `X-API-Key: <key>` و `X-Device-Id: <deviceId>`
- پاسخ: `bot.username`, `poll_interval`, `webview_url` etc. (بدون توکن)

### 3) ثبت دستگاه
- `POST https://HOST/api/devices.php` با `X-API-Key` و JSON `{device_id, device_name, model, os_version, app_version}`

### 4) پروکسی تلگرام
- تمام جاهایی که قبلاً مستقیم `https://api.telegram.org/bot.../sendMessage` صدا می‌زد:
```java
// قبل:
String url = "https://api.telegram.org/bot" + token + "/sendMessage";

// بعد:
String url = apiBase + "/api/telegram.php";
// Body: {"action":"sendMessage","chat_id":"...","text":"...", "device_id": deviceId}
// Header: X-API-Key: <apiKey>
```
- برای فایل (`sendDocument`/`sendPhoto`): `multipart/form-data` با کلید `document` یا `photo` به همین URL، یا ابتدا فایل را به سرور آپلود و سپس `telegram.php` فوروارد کند (وابسته به پیاده‌سازی).

### 5) BuildConfig
- در `build.gradle`:
```gradle
buildConfigField "String", "API_BASE", '"https://HOST"'
buildConfigField "String", "API_KEY",  '"<per-customer key from admin panel>"'
```
- برای چندمشتری: هر بیلد یک `API_KEY` متفاوت (یا APK یکسان و کلید در اولین اجرا از QR/کد فعال‌سازی وارد شود).

### 6) حذف وابستگی logo.json
- تمام `getAssets().open("logo.json")` را با call به `/api/config.php` جایگزین کنید.
- تست: APK بدون logo.json باید کار کند؛ خطای `No Telegram bot configured` از سرور یعنی هنوز رباتی برای آن API_KEY ثبت نشده (در `admin/bots.php` ثبت کنید).

## تست نهایی (چک‌لیست فصل 10)
1. APK بدون توکن بیلد می‌شود.
2. `GET /api/status.php` → `ok:true`
3. `POST /api/devices.php` با API_KEY → دستگاه در `admin/devices.php` ظاهر می‌شود.
4. `POST /api/telegram.php {action:sendMessage}` → پیام در ربات مشتری می‌رسد (نه مشتری دیگر).
5. مشتری B دستگاه/پیام مشتری A را نمی‌بیند (ایزوله).
6. توکن در `adb shell` / decompile APK یافت نمی‌شود.
7. `private/telegram_tokens/*.enc` فقط روی سرور، 0600، خارج از public_html.

## نصب روی هاست
1. `central/` را آپلود کنید به طوری که `public_html/` همان `public_html/` هاست باشد و `private/` خارج از `public_html`.
2. `private/config/config.php` را از `config.sample.php` بسازید و `DB_*`, `ENCRYPTION_KEY_B64`, `APP_URL` را پر کنید.
3. DB import: `mysql -u ... -p < private/database/schema.sql`
4. `chmod 700 private/telegram_tokens private/logs`
5. `.htaccess` را فعال نگه دارید؛ HTTPS را اجباری کنید.
