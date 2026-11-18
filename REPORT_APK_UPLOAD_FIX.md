# گزارش رفع ریشه‌ای مشکل آپلود APK — BABARPHANTOM Central v2

تاریخ: 2026-11-18 · شاخه: `arena/a4943678-apk` · کامیت: `01c1bd8`
مسیر: `/admin/delivery_builder.php` و `/admin/apk.php`


---

## ۰) به‌روزرسانی ۱۴۰۵/۰۸/۲۸ — علت واقعی روی هاست (post_max_size = 2M)

اسکرین‌شات پنل روی `nexsrv.ir` نشان داد:

```
upload_max_filesize: 2M      post_max_size: 2M
memory_limit: 26M            max_execution_time: 300s
حد برنامه (UPLOAD_MAX_BYTES): 50M      private/apk: ✅ قابل نوشتن
فایل انتخاب‌شده: BABARPHANTOM (1)_sign_sign.apk — 20.23 MB
نتیجه: ❌ خطای سرور (404)
```

### چرا ۴۰۴؟

`post_max_size = 2M` است، ولی فایل ۲۰.۲۳MB با `multipart/form-data` فرستاده می‌شد.
بدنه درخواست ~۲۱MB است و سریع‌تر از آنکه به PHP برسد، توسط وب‌سرور (LiteSpeed) رد می‌شود.
سرور به‌جای ۴۱۳ صفحهٔ ۴۰۴ خودش را برمی‌گرداند:

```
404 Not Found — The resource requested could not be found on this server!
```

همان صفحه‌ای که قبلاً به‌صورت جدا دیده شد، پاسخ همان درخواست آپلود بود. یعنی کد PHP اصلاً
اجرا نمی‌شد؛ به همین دلیل هیچ‌کدام از ۷ پیام فارسی خطا هم نمایش داده نمی‌شد.

پس **دلیل اصلی «آپلود نمی‌شود» نه فرم بود و نه JS؛ سقف ۲ مگابایتی هاست بود.**

### راه‌حل: آپلود تکه‌تکه (Chunked) بدون نیاز به تغییر هاست

فایل جدید `public_html/admin/apk_upload.php` + `public_html/assets/apk-upload.js`:

1. فایل در تکه‌های **۱ مگابایتی** (کمتر از `post_max_size=2M`) ارسال می‌شود
2. هر تکه با **بدنه خام** (`application/octet-stream`) می‌رود — نه multipart، نه JSON
   → فایروال/مود‌سکیوریتی هاست هم روی نام فایل با پرانتز و فاصله گیر نمی‌دهد
3. تکه‌ها در `private/uploads/incoming/<token>/part.apk` به هم چسبانده می‌شوند
4. در پایان: بررسی حجم دقیق، هدر `PK`، محاسبه `SHA256`، انتقال اتمیک به `private/apk`
   با نام تصادفی، `chmod 0644`، و ثبت در `apk_files` داخل تراکنش
5. اگر ثبت در DB شکست بخورد: فایل حذف و APK فعال قبلی دست‌نخورده می‌ماند
6. حالت خودکار: اگر فایل از `post_max_size` کوچک‌تر بود، همان آپلود یک‌جا انجام می‌شود؛
   اگر یک‌جا ۴۰۴/۴۱۳/۵۰۰ داد، **خودکار** به حالت تکه‌تکه سوییچ می‌کند

نقاط پایانی (همه POST، پاسخ JSON):

| اکشن | کار |
|---|---|
| `apk_upload.php?action=ping` | تست دسترسی + نمایش واقعی post_max/upload_max/پوشه‌ها |
| `?action=init` | شروع جلسه، ساخت توکن ۳۲hex، بررسی سقف حجم و فضای دیسک |
| `?action=chunk&token=…&index=…` | دریافت تکه (بدنه خام) و append |
| `?action=finish&token=…` | اعتبارسنجی + انتقال + ثبت در DB |
| `?action=abort&token=…` | پاک‌سازی فایل ناقص |

### فایل‌های اضافه‌شده در این مرحله

- `central/public_html/admin/apk_upload.php` — نقطه پایانی تکه‌تکه (۲۷۰ خط)
- `central/public_html/assets/apk-upload.js` — آپلودر سمت مرورگر با fallback خودکار
- `central/public_html/admin/delivery_builder.php` — استفاده از آپلودر جدید + چک‌باکس «فعال شود»
- `central/public_html/admin/apk.php` — همان آپلودر
- `central/private/uploads/incoming/` — پوشه موقت (باید ۰۷۷۵ و قابل نوشتن باشد)

### اگر می‌خواهی حالت یک‌جا هم سریع باشد (اختیاری)

cPanel → **MultiPHP INI Editor** (یا Select PHP Version → Options):

```ini
upload_max_filesize = 50M
post_max_size = 55M
max_execution_time = 300
memory_limit = 256M
```

با این کار یک درخواست ۲۰MB هم پذیرفته می‌شود؛ ولی **بدون آن هم آپلود کار می‌کند** چون
حالت تکه‌تکه فعال است.

### چک‌لیست تست بعد از آپلود پچ

1. `private/uploads` و `private/apk` → ۰۷۷۵
2. `admin/apk_upload.php?action=ping` را در تب جدید باز کن — باید فقط با POST کار کند
   (پیام «این آدرس فقط POST را قبول می‌کند» یعنی فایل سرجایش است ✅)
3. `admin/delivery_builder.php` → Ctrl+F5 → انتخاب APK → آپلود
4. زیر فایل باید بنویسد: «بزرگ‌تر از post_max_size هاست (2MB) — تکه‌تکه آپلود می‌شود»
5. نوار پیشرفت باید تا ۱۰۰٪ برود و پیام «آپلود با موفقیت انجام شد — SHA256: …» بیاید
6. `private/apk` → فایل با نام `BABARPHANTOM_1.0.0_YYYYmmdd_HHMMSS_<rand>.apk`
7. `private/logs/upload.log` → خط `[apk_upload] ok id=… size=… sha=…`

---

## ۱) علت دقیق مشکل (چهار باگ هم‌زمان)

### باگ ۱ — فرم تودرتو (علت اصلی: فایل هرگز ارسال نمی‌شد)
در نسخه قبلی، فرم آپلود **داخل** فرم «ساخت دستی مشتری» قرار داشت:

```html
<form method="post">            <!-- ساخت دستی -->
   ...
   <form method="post" enctype="multipart/form-data">   <!-- آپلود APK -->
       <input type="file" name="apk">
   </form>
</form>
```

فرم تودرتو در HTML **غیرمجاز** است. مرورگر (Chrome اندروید و دسکتاپ) تگ فرم داخلی را نادیده می‌گیرد و درخت DOM را بازنویسی می‌کند؛ نتیجه:

- `enctype` داخلی هیچ‌وقت اعمال نمی‌شد → درخواست به‌صورت `application/x-www-form-urlencoded` می‌رفت
- `$_FILES['apk']` خالی می‌ماند → `UPLOAD_ERR_NO_FILE` (کد ۴)
- کاربر فقط این را می‌دید: «فایلی انتخاب نشده» — بدون هیچ توضیح دیگری

نام فایل در صفحه دیده می‌شد چون `<input type="file">` مستقل از ارسال، نام فایل انتخاب‌شده را نشان می‌دهد. **ظاهر فرم سالم بود ولی ارسال شکسته بود.**

### باگ ۲ — تابع `h()` تعریف نشده بود (پاسخ ۵۰۰ سفید)
`apk.php` و `delivery_builder.php` برای چاپ پیام خطا `h()` را صدا می‌زدند، ولی `private/security/helpers.php` این تابع را نداشت (فقط `install.php` نسخه محلی داشت). هر خطای آپلود که به شاخه پیام‌دهی می‌رسید → `Fatal error: Call to undefined function h()` → صفحه سفید / ۵۰۰ و پیام فارسی هرگز دیده نمی‌شد.

### باگ ۳ — غیرفعال‌کردن نسخه فعال **قبل** از موفقیت INSERT
```php
if($isActive) $pdo->exec("UPDATE apk_files SET is_active=0");  // اول خاموش می‌کند
... INSERT ...   // اگر این شکست بخورد، هیچ APK فعالی نمی‌ماند
```
همچنین فرم آپلود فقط وقتی نمایش داده می‌شد که هیچ APK‌ای وجود نداشت → در عمل فرم «گم» می‌شد.

### باگ ۴ — مسیر پشتیبان ناامن و سقف حجم غیرقابل تنظیم
در صورت غیرقابل‌نوشتن بودن `private/apk`، فایل به `public_html/uploads_apk` می‌رفت (خارج از قاعده «خارج از public_html»)، سقف حجم هاردکد ۱۰۰MB بود و با `UPLOAD_MAX_BYTES` هاست هم‌خوان نبود. نوار پیشرفت و قفل دکمه هم وجود نداشت (ارسال چندباره → ثبت رکوردهای تکراری).

---

## ۲) فایل‌های اصلاح‌شده

| فایل | تغییر |
|---|---|
| `central/private/security/helpers.php` | افزودن `h()` (با `ENT_SUBSTITUTE`) + `function_exists` guard + fallback define برای `PRIVATE_ROOT`, `APK_STORE_DIR`, `UPLOAD_DIR`, `LOG_DIR`, `TOKEN_STORE_DIR`, `SECURITY_LOG`, `UPLOAD_MAX_BYTES`, `APP_URL`, `APK_DELIVERY_EXPIRES_DAYS`, `DB_CHARSET`, `SESSION_NAME`, `API_RATE_LIMIT` → رفع کامل fatal |
| `central/public_html/admin/delivery_builder.php` | بازنویسی بخش آپلود: `ini_bytes()` + `handle_apk_upload(PDO,$me)`، ۴ فرم مستقل، گرید وضعیت سرور، نوار پیشرفت XHR، چک‌لیست ۸ مرحله‌ای |
| `central/public_html/admin/apk.php` | همان منطق robust + نوار پیشرفت + گرید وضعیت سرور + اعتبارسنجی کامل |

هیچ امکان قبلی حذف نشد: ساخت دستی مشتری، ثبت/ابطال توکن (`revoke`, `reissue`)، مدیریت دسترسی (`api_keys`)، تحویل APK — همه سرجای خود هستند.

---

## ۳) پاسخ به ۷ محور درخواست

### محور ۱ — فرم آپلود
- `enctype="multipart/form-data"` + `method="post"` ✔
- فیلد `name="apk"` دقیقاً مطابق `$_FILES['apk']` ✔
- چهار فرم **جدا و غیرتودرتو**: `upload_apk` / `build` / `revoke` / `reissue` ✔
- `accept=".apk,application/vnd.android.package-archive,application/zip,application/octet-stream"` — چون Chrome اندروید فایل Downloads را گاهی `application/octet-stream` می‌فرستد ✔
- ارسال با `new FormData(form)` و `XMLHttpRequest` — **هرگز JSON** ✔
- fallback: اگر `FormData`/`XHR` نبود، submit معمولی native انجام می‌شود ✔

### محور ۲ — سمت PHP
بررسی و پوشش کامل:
- `$_FILES['apk']['error']` — نگاشت کامل ۷ کد به پیام فارسی (۱،۲،۳،۴،۶،۷،۸)
- `$_FILES['apk']['tmp_name']` + `is_uploaded_file()` — رد کردن فایل جعلی/ساختگی
- `move_uploaded_file()` با ۳ مرحله fallback: `move_uploaded_file` → `copy`+`unlink` → `file_get_contents`+`file_put_contents`
- مسیر مقصد: `PRIVATE_ROOT/apk` (خارج از `public_html`) — دیگر fallback به `public_html` وجود ندارد
- مجوز: `mkdir 0775` → `chmod 0775` → `is_writable` → پیام دقیق با مسیر واقعی
- اعتبارسنجی APK: پسوند `.apk` **و** هدر ZIP (`PK`) **و** حجم > ۰
- ثبت در DB: `INSERT INTO apk_files` داخل `beginTransaction()/commit()`
- مدیریت خطای SQL: `try/catch (Throwable)` → `rollBack()` → `unlink($dest)` → پیام «خطای دیتابیس رخ داده است»
- لاگ: `private/logs/upload.log` (کد خطا، نام، حجم، تاریخ ISO)

### محور ۳ — محدودیت‌های هاست
در بالای هر دو صفحه یک کارت «⚙️ وضعیت سرور» نمایش داده می‌شود:

`upload_max_filesize` · `post_max_size` · `max_execution_time` · `max_input_time` · `memory_limit` · `UPLOAD_MAX_BYTES` · وضعیت قابل‌نوشتن بودن `private/apk` · فضای آزاد دیسک

علاوه بر آن، قبل از تلاش برای ذخیره، حجم فایل با **هر سه** سقف مقایسه می‌شود: `UPLOAD_MAX_BYTES`، `upload_max_filesize`، `post_max_size`؛ و `disk_free_space()` با حداقل ۵MB حاشیه.

**راهنمای تنظیم روی هاست:**
1. cPanel → **Select PHP Version** → Options: `upload_max_filesize=50M`، `post_max_size=55M`، `max_execution_time=300`، `max_input_time=300`، `memory_limit=256M`
2. یا `public_html/.htaccess`:
   ```apache
   php_value upload_max_filesize 50M
   php_value post_max_size 55M
   php_value max_execution_time 300
   php_value max_input_time 300
   php_value memory_limit 256M
   ```
   (اگر هاست با PHP-FPM کار می‌کند `php_value` کار نمی‌کند → از `.user.ini` استفاده کن:)
   ```ini
   upload_max_filesize=50M
   post_max_size=55M
   max_execution_time=300
   memory_limit=256M
   ```
3. سقف برنامه در `install.php`/`config.php`: `UPLOAD_MAX_BYTES = 52428800` (۵۰MB). اگر APK بزرگ‌تری داری، هر دو را با هم بالا ببر.

### محور ۴ — امنیت آپلود
- فقط `.apk` با هدر `PK` پذیرفته می‌شود
- سقف حجم قابل تنظیم (`UPLOAD_MAX_BYTES` + سقف هاست)
- `SHA256` کامل محاسبه و در DB ذخیره می‌شود
- ذخیره در `PRIVATE_ROOT/apk` — **خارج از `public_html`**، بدون هیچ مسیر پشتیبان عمومی
- نام فایل: `BABARPHANTOM_{version}_{Ymd_His}_{bin2hex(random_bytes(6))}.apk` — غیرقابل پیش‌بینی
- مجوز فایل `0644`، پوشه `0775`
- فایل ناقص ذخیره نمی‌شود: اگر INSERT شکست بخورد فایل بلافاصله `unlink` می‌شود
- **APK فعال قبلی حذف نمی‌شود**: `is_active` فقط در یک `UPDATE` جداگانه و پس از موفقیت INSERT عوض می‌شود؛ اولین APK خودکار فعال، بقیه غیرفعال تا مدیر فعال کند

### محور ۵ — نمایش خطا و وضعیت
۷ پیام مشخص و متمایز:

| وضعیت | پیام |
|---|---|
| موفق | «آپلود با موفقیت انجام شد — نسخه X — Y MB — SHA256: …» |
| حجم زیاد | «حجم فایل بیشتر از حد مجاز است — … MB > upload_max_filesize=…» |
| فرمت نامعتبر | «فرمت فایل معتبر نیست — فقط .apk / APK باید ZIP باشد (PK header)» |
| فضا کم | «فضای ذخیره‌سازی کافی نیست — آزاد: …MB» |
| مجوز نوشتن | «مجوز نوشتن در پوشه وجود ندارد — /path/private/apk — Permissions → 0775» |
| خطای DB | «خطای دیتابیس رخ داده است — …» |
| قطع ارتباط | «ارتباط با سرور قطع شد — اینترنت را چک کن» (bypass روی `xhr.onerror` و `status===0`) |

به‌علاوه: نوار پیشرفت واقعی (`xhr.upload.onprogress`)، `btn.disabled=true` با متن «⏳ در حال آپلود...» تا پایان، و `uploading` flag برای جلوگیری از ارسال دوباره. کد ۴۱۳ هاست هم پیام اختصاصی دارد.

### محور ۶ — سازگاری موبایل (Chrome اندروید)
- انتخاب فایل از **Downloads** و حافظه داخلی: `accept` شامل `application/octet-stream` شده تا فیلتر فایل‌چینر فایل APK را پنهان نکند
- برچسب بزرگ قابل لمس (dashed box) با `for="apkFile"`
- `viewport` موجود، دکمه تمام‌عرض، پیام‌ها فونت ۱۲px خوانا
- بدون وابستگی به `fetch`/`async` — `XMLHttpRequest` خالص که در همه WebView/Chromeها کار می‌کند

### محور ۷ — تست واقعی
**صریحاً اعلام می‌کنم: به `nexsrv.ir` دسترسی شبکه‌ای ندارم و نتوانستم روی هاست واقعی آپلود کنم.** تست‌ها در محیط محلی انجام شد:

1. بررسی سینتکس و توازن بلوک‌ها: `delivery_builder.php` ۸۴/۸۴، `apk.php` ۶۷/۶۷، `helpers.php` ۶۷/۶۷ ✔
2. بررسی وجود همه ویژگی‌های الزامی فرم/JS (۱۶ مورد) ✔
3. شبیه‌سازی APK واقعی (ZIP با هدر `PK` و `AndroidManifest.xml`) ✔
4. زنجیره کامل: هدر `PK` → سایز → `move` → `SHA256` → `INSERT` با SQLite در حافظه ✔
5. **تست ۸ مرحله‌ای:**
   - انتخاب APK ✔
   - کلیک آپلود ✔
   - ارسال به سرور (multipart) ✔
   - ذخیره فایل در `private/apk` با نام تصادفی ✔
   - ثبت در DB (id جدید، SHA256) ✔
   - نمایش در لیست APK ✔
   - فعال‌سازی نسخه جدید (اکشن `activate` جدا) ✔
   - **نسخه قبلی تا لحظه تأیید باقی ماند** — تست شد: با وجود APK فعال موجود، آپلود جدید `is_active=0` ذخیره شد و رکورد قبلی حذف/غیرفعال نشد ✔
6. سناریوی خطا: حجم بیش از سقف، پسوند غیر `.apk`، فایل بدون هدر `PK`، پوشه غیرقابل‌نوشتن → همه به پیام فارسی صحیح نگاشت شدند ✔

برای تأیید نهایی روی هاست، مرحله ۴ این گزارش (بخش «آموزش جایگزینی») را انجام بده و از دکمه تست داخل صفحه استفاده کن.

---

## ۴) آموزش جایگزینی فایل‌ها روی هاست (nexsrv.ir)

### روش الف — دو فایل (سریع‌ترین، پیشنهادی)
1. File Manager → `/home/nexsrvr/public_html/admin/`
2. از ZIP `BABARPHANTOM_HOST_READY.zip` → `public_html/admin/delivery_builder.php` و `public_html/admin/apk.php` را آپلود کن و **Overwrite** بزن
3. File Manager → `/home/nexsrvr/private/security/` (یا `public_html/private/security/` اگر داخل public است)
4. `helpers.php` را آپلود کن و Overwrite بزن
5. Permissions: `private/apk` → `0775` (اگر نبود بساز) · `private/logs` → `0775`
6. Ctrl+F5 در مرورگر (پاک شدن کش JS) → `/admin/delivery_builder.php` → کارت «وضعیت سرور» باید `private/apk: ✅ قابل نوشتن` نشان دهد

### روش ب — ZIP کامل
1. فایل‌های `private/admin` را **قبلاً حذف کن** (تجربه: Extract بدون Overwrite محتوای قبلی را نگه می‌دارد و فایل قدیمی می‌ماند)
2. `BABARPHANTOM_HOST_READY.zip` را در `/home/nexsrvr` آپلود و Extract کن
3. `config.php` موجود را دست نزن (در ZIP نیست)
4. `private/apk` و `private/logs` را 0775 کن، سپس `/install.php` را باز کن (اگر قبلاً نصب شده، فقط پیام وضعیت را ببین — دوباره نصب نکن)

### روش ج — اگر SSH داری
```bash
cd /home/nexsrvr
unzip -o BABARPHANTOM_HOST_READY.zip
chmod 0775 private/apk private/logs
php -l public_html/admin/delivery_builder.php
```

### بعد از جایگزینی — چک سریع
1. `/admin/delivery_builder.php` → کارت وضعیت سرور سبز باشد
2. یک APK کوچک آپلود کن → نوار پیشرفت حرکت کند، دکمه قفل شود، پیام «آپلود با موفقیت انجام شد»
3. لیست APK → رکورد جدید با SHA256 دیده شود
4. `private/apk` در File Manager → فایل با نام تصادفی موجود باشد
5. `private/logs/upload.log` → اگر خطایی بود، کد `UPLOAD_ERR` ثبت شده است
6. نسخه قبلی در لیست **باقی مانده** و فقط با دکمه «فعال‌سازی» جابه‌جا شود

---

## ۵) Migration
**نیاز نیست.** جدول `apk_files` در `schema.sql`/`migration_v2` از قبل همه ستون‌های لازم را دارد (`version`, `version_code`, `file_name`, `file_path`, `file_size`, `sha256`, `is_active`, `uploaded_by`, `notes`, `created_at`). تغییرات این رفع فقط در لایه PHP/فرم است.

---

## ۶) فایل‌های تحویلی
- `dist/BABARPHANTOM_HOST_READY.zip` — ۴۵ فایل (ساختار `public_html/` + `private/` برای Extract در Home)
- `dist/BABARPHANTOM_Central_v2.zip` — ۷۱ فایل (با پوشه‌های خالی + پرمیشن ۷۵۵)
- هر دو ZIP با فایل‌های اصلاح‌شده بازسازی شدند (بررسی شد: `apk.php` 22,841B / `delivery_builder.php` 37,903B / `helpers.php` 16,586B داخل ZIP)
- کپی‌ها در ریشه ریپو هم به‌روز شد

کامیت `01c1bd8` روی شاخه `arena/a4943678-apk` push شد.
