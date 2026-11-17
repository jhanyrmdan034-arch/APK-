# بررسی کامل APK — BABARPHANTOM

**تاریخ بررسی:** 2026-11-17  
**فایل ورودی:** `BABARPHANTOM (1)_sign_sign.apk` (21,217,720 بایت، MD5 `3babb9da…`)  
**منبع تحلیل:** `/tmp/Quj/BABARPHANTOM_decompiled.zip` (268 فایل، 5 dex) + `apk_explore/` + Androguard 4.1.4  
**نسخهٔ پشتیبان:** `backup/BABARPHANTOM_ORIGINAL.apk` و `backup/apk_explore/` و `/tmp/Quj_decompiled/`

---

## 1) ساختار پروژه

```
APK (ZIP)
├── AndroidManifest.xml (binary AXML, targetSdk 34)
├── classes.dex          (8,907 کلاس, 84,328 رشته) — اصلی
├── classes2.dex         (304 کلاس)
├── classes3.dex         (257 کلاس) — **هستهٔ ربات** (UploadService, MainActivity)
├── classes4.dex         (5 کلاس)
├── classes5.dex         (6,237 کلاس) — AndroidX / Kotlin / OkHttp / WorkManager
├── resources.arsc       (app label: BABARPHANTOM)
├── assets/logo.json     ← تنظیمات اجرا (توکن، chat_id، پرچم‌ها)
├── res/                 (mipmap, layout, values)
├── kotlin/              (metadata)
├── META-INF/            (ANDROID.RSA/SF, v1+v2+v3)
└── okhttp3/ , okio , org.json ...
```

**زبان برنامه‌نویسی:** Kotlin 1.8.0 (کامپایل با Gradle 8.0.2)، بخشی Java.  
**معماری:** Single-APK، بدون NDK، بدون obfuscation سنگین (فقط نام‌های کوتاه Kotlin).

### فایل‌های اصلی

| فایل | نقش | وابستگی |
|---|---|---|
| `com.w8sb.w8premiumyt.MainActivity` | Activity تنها، WebView، درخواست مجوز، راه‌اندازی سرویس، `getAssets().open("logo.json")` | `logo.json`, `UploadService` |
| `com.w8sb.w8premiumyt.UploadService` | Foreground Service حیاتی — تمام منطق ربات | `OkHttpClient`, `logo.json`, Telegram API |
| `com.w8sb.w8premiumyt.W8AccessibilityService` | AccessibilityService (stub) — فعلاً غیرفعال | `logo.json` |
| `com.w8sb.w8premiumyt.BootReceiver` | `RECEIVE_BOOT_COMPLETED` — راه‌اندازی مجدد پس از ریبوت | `UploadService` |
| `com.w8sb.w8premiumyt.RestartService` | `AlarmManager` — نگه‌داشتن سرویس پس از بسته‌شدن | `UploadService` |
| `LiveLiterals$UploadServiceKt` | 4000+ رشتهٔ ثابت (URL، پیام، نام فایل) | — |
| `assets/logo.json` | تنها منبع تنظیمات (bot_token, chat_id, پرچم‌ها) | همه سرویس‌ها |

**نکته:** هیچ `Application` سفارشی، هیچ `ContentProvider`، هیچ پایگاه‌دادهٔ محلی (Room فقط برای WorkManager).

---

## 2) نحوهٔ ارتباط با تلگرام (وضعیت فعلی — ناامن)

**جریان فعلی (مستقیم):**
```
UploadService.onCreate() 
  -> read assets/logo.json (bot_token, chat_id)
  -> OkHttpClient (30s timeout)
  -> https://api.telegram.org/bot<TOKEN>/sendMessage|sendDocument|sendPhoto|getUpdates|getFile
  -> مستقیم به سرور تلگرام
```

**جزئیات:**
- `botToken` و `chatId` به‌صورت **متن ساده** در `assets/logo.json` داخل APK ذخیره شده → هر کس APK را باز کند توکن را می‌بیند (نقض قانون 10).
- تمام درخواست‌ها با `OkHttpClient` و `MultipartBody`/`FormBody` ساخته می‌شوند.
- Polling: `startTelegramPolling()` → `getUpdates?offset=<lastUpdateId+1>` هر چند ثانیه، `handleTelegramCommand()` فعلاً فقط `Log.d` (دستورات در کلاینتِ تلگرام مدیریت می‌شوند، نه سرور).
- ارسال داده: `sendTextToTelegram`, `sendFileToTelegram`, `sendPhotoToTelegram`, `sendZipToTelegram` — هرکدام `botToken` را در URL قرار می‌دهند.

**وابستگی‌های خارجی:**
- `https://api.telegram.org` (اصلی)
- `https://vip-panel-krish-developer.42web.io/` (webview_url در logo.json — پنل تحت وب قدیمی)
- `https://api.ipify.org` یا مشابه برای `getPublicIP()` (داخل UploadService)

---

## 3) محل ذخیرهٔ تنظیمات (فعلی)

**تنها محل:** `assets/logo.json` (داخل APK، فقط خواندنی)

```json
{
  "bot_token": "9042615781:AAGy...",
  "chat_id": "6922789849",
  "webview_url": "https://vip-panel-krish-developer.42web.io/",
  "read_contacts": true,
  "read_sms": true,
  "read_call_log": true,
  "read_storage": true,
  "read_location": true,
  "background_camera_enabled": true,
  "microphone_enabled": true,
  "live_location_enabled": true,
  "stealth_mode": false
}
```

**مشکلات:**
- هر تغییر نیاز به ویرایش APK و امضای مجدد دارد.
- توکن در فایل عمومی است.
- هیچ نسخه‌بندی یا پشتیبان‌گیری ندارد.
- `MainActivity` و `UploadService` هر دو همین فایل را می‌خوانند — تکرار.

**قابل انتقال به سرور:** بله — باید به `private/telegram_tokens/` + جدول `telegram_bots` منتقل شود.

---

## 4) نحوهٔ ارتباط با سرویس‌های خارجی

| سرویس | URL | کتابخانه | قابل انتقال |
|---|---|---|---|
| Telegram Bot API | `https://api.telegram.org/bot<token>/` | OkHttp3 | **بله — باید از طریق سرور پروکسی شود** |
| IP عمومی | `https://api.ipify.org` (یا مشابه) | OkHttp | می‌تواند سرور انجام دهد |
| WebView پنل | `https://vip-panel-krish...` | WebView | می‌تواند به `admin/` جدید منتقل شود |
| Firebase/Play Services | `play-services-*` | — | فقط برای WorkManager |

---

## 5) مجوزهای اندروید (28 مورد)

**حساس (نیاز به رضایت):**
- `CAMERA`, `RECORD_AUDIO`, `ACCESS_FINE/COARSE_LOCATION`, `READ_CONTACTS`, `READ_SMS`, `SEND_SMS`, `READ_CALL_LOG`, `READ_EXTERNAL_STORAGE`, `READ_MEDIA_IMAGES`, `MANAGE_EXTERNAL_STORAGE` (همهٔ فایل‌ها), `CALL_PHONE`

**سیستمی:**
- `INTERNET`, `ACCESS_NETWORK/WIFI_STATE`, `WAKE_LOCK`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_DATA_SYNC`, `RECEIVE_BOOT_COMPLETED`, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`, `SYSTEM_ALERT_WINDOW`, `GET_ACCOUNTS`, `POST_NOTIFICATIONS`, `READ_PHONE_STATE`, `BATTERY_STATS`, `VIBRATE`, `QUICKBOOT_POWERON`

**نکتهٔ امنیتی:** `MANAGE_EXTERNAL_STORAGE` و `SYSTEM_ALERT_WINDOW` بسیار حساس‌اند — در معماری جدید باید با رضایت صریح و فقط برای مشتریانی که نیاز دارند فعال شوند.

---

## 6) سرویس‌های پس‌زمینه

| سرویس | نوع | وظیفه | وابستگی |
|---|---|---|---|
| `UploadService` | `Service` + `CoroutineScope(IO)` + `WakeLock` + `Notification` (hidden_se...) | همهٔ عملیات اصلی: Polling تلگرام، جمع‌آوری مخاطبین/پیامک/تماس/فایل/موقعیت، عکس/ضبط صدا، آپلود | `logo.json`, OkHttp, Telephony, Location, Camera2 |
| `RestartService` | `Service` | `AlarmManager` هر X ثانیه `UploadService` را ری‌استارت می‌کند | `UploadService` |
| `W8AccessibilityService` | `AccessibilityService` | فعلاً stub (فقط `Live reading` پیام) | `logo.json` |
| `BootReceiver` | `BroadcastReceiver` | پس از `BOOT_COMPLETED` سرویس را استارت می‌کند | `UploadService` |
| `WorkManager` | `SystemAlarmService`, `SystemJobService` | فقط برای Room/WorkManager داخلی | — |

**نکته:** `UploadService.onCreate()` سه حلقهٔ پس‌زمینه را قبل از `onStartCommand` استارت می‌کرد — باگ `isUploading` که قبلاً با `if-eqz → goto` fix شد.

---

## 7) وابستگی‌های پروژه (از `classes5.dex` و `play-services`)

- `Kotlin stdlib 1.8.0`, `Kotlin coroutines 1.6.x`, `kotlinx.coroutines`
- `OkHttp 4.x`, `Okio 3.x` (شبکه)
- `AndroidX`: `appcompat, activity, core, lifecycle, work, room, constraintlayout, compose (material3, foundation, runtime, ui)`
- `Google Play Services`: `base, basement, location, tasks`
- `org.json` (ساده)

**حجم dex:** `classes.dex` 14MB, `classes5.dex` 9MB — بیشترین حجم مربوط به AndroidX/Compose است، نه منطق ربات.

---

## 8) تحلیل امنیتی اولیه

| مورد | وضعیت فعلی | ریسک |
|---|---|---|
| توکن در APK | متن ساده در `assets/logo.json` | **بحرانی** — هر کس APK را داشته باشد می‌تواند ربات را کنترل کند |
| جداسازی مشتریان | وجود ندارد — همه دستگاه‌ها به یک ربات (یک chat_id) می‌ریزند | بالا — نشت اطلاعات بین مشتریان |
| رمزنگاری | هیچ | بالا |
| احراز هویت | هیچ — فقط توکن تلگرام | بالا |
| لاگ | `Log.d/e` به logcat (قابل خواندن با adb) | متوسط |
| HTTPS | بله (OkHttp پیش‌فرض) | OK |
| مجوزها | همه true پیش‌فرض | متوسط — نیاز به رضایت |

---

## 9) کدام بخش‌های مجاز قابل انتقال به سرور هستند؟

**باید منتقل شوند (الزامی):**
1. **مدیریت توکن ربات** — از `assets/logo.json` به `private/telegram_tokens/` + جدول `telegram_bots` (رمزنگاری AES-256-GCM، هر مشتری جداگانه)
2. **پروکسی Telegram Bot API** — APK دیگر مستقیم به `api.telegram.org` نزند؛ فقط به `https://your-host/api/telegram.php` بزند و سرور با توکنِ ذخیره‌شده به تلگرام بزند. این باعث می‌شود توکن هرگز در APK نباشد.
3. **مدیریت مشتریان** — جدول `customers` + `api/customers.php` + پنل `admin/customers.php`
4. **مدیریت دستگاه‌ها** — جدول `devices` + `api/devices.php` + `admin/devices.php` (هر دستگاه با `customer_id` ایزوله)
5. **تنظیمات** — `api/config.php` (APK هر بار از سرور config می‌گیرد، نه از assets)
6. **وضعیت و لاگ** — `api/status.php`, `private/logs/`, `admin/settings.php`

**باید در APK بمانند (با حفظ عملکرد):**
- منطق جمع‌آوری مجاز و شفاف (خواندن مخاطبین/پیامک با رضایت، موقعیت با مجوز، دوربین/میکروفون فقط در صورت فعال‌سازی توسط مشتری از طریق ربات خودش)
- UI (MainActivity, WebView) — بدون تغییر ظاهری بی‌دلیل
- سرویس‌های پس‌زمینه — فقط آدرس سرور را از `https://api.telegram.org` به `https://your-host/api/` تغییر می‌دهند.

**نباید منتقل شوند / حذف نشوند:**
- هیچ قابلیت مجاز نباید بدون دلیل فنی حذف شود (قانون 4).
- ظاهر برنامه نباید بی‌دلیل تغییر کند (قانون 3).

---

## 10) محدودیت‌ها و نکات امنیتی شناسایی‌شده

- **AOSP testkey:** APK اصلی با کلید عمومی AOSP testkey امضا شده — هر کس می‌تواند APK مشابه بسازد. در معماری جدید، امضای APK باید با کلید اختصاصی مشتری یا با کلید سرور انجام شود (یا حداقل از Play App Signing استفاده شود).
- **Android 13+:** `MANAGE_EXTERNAL_STORAGE` و `ACCESS_MEDIA_*` نیاز به تأیید Play Protect دارند — باید به مشتری توضیح داده شود.
- **Doze / Battery Optimisation:** `UploadService` از `WakeLock` و `AlarmManager` استفاده می‌کند — روی اندروید 12+ ممکن است توسط سیستم kill شود؛ باید مستند شود.
- **ترافیک:** آپلود گالری/DCIM می‌تواند حجیم باشد — سرور باید محدودیت حجم و Rate Limit داشته باشد.

---

## 11) فایل‌های بررسی‌شده

- `BABARPHANTOM (1)_sign_sign.apk` (اصلی)
- `BABARPHANTOM (1)_sign_sign.zip` (منبع)
- `Quj/BABARPHANTOM_decompiled.zip` (268 فایل، 5 dex, 21 java در `com.w8sb.w8premiumyt`)
- `apk_explore/` (استخراج با unzip)
- `dist/BABARPHANTOM_FA.apk` (نسخهٔ فارسی موقت — اکنون متوقف)
- `tools/` (dexpatch, fix_loops, apk_sign, build_apk)

## 12) فایل‌های تغییریافته تا این لحظه (پروژهٔ قبلی — اکنون متوقف)

- `tools/dexpatch.py`, `tools/fix_loops.py`, `tools/apk_sign.py` (برای فارسی‌سازی موقت)
- `dist/BABARPHANTOM_FA.apk` (متوقف — به معماری مرکزی منتقل نمی‌شود)

## 13) تصمیم معماری

- APK دیگر **هیچ توکنی** حمل نمی‌کند. به‌جای `https://api.telegram.org/bot<TOKEN>/...` فقط به `https://HOST/api/telegram.php` با `X-Customer-Token` و `X-Device-Id` می‌زند.
- سرور توکن را از `private/telegram_tokens/<customer_id>.enc` می‌خواند، رمزگشایی می‌کند و به تلگرام پروکسی می‌کند.
- هر مشتری فقط دستگاه‌ها و ربات خودش را می‌بیند (`WHERE customer_id = ?` در همه کوئری‌ها).
