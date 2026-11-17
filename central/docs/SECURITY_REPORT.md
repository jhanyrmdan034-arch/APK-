# گزارش امنیت — BABARPHANTOM Central v2

## خلاصه اجرایی
تمام توکن‌ها/رمزها از APK و مسیرهای عمومی حذف و به `private/` خارج از `public_html` با رمزنگاری `AES-256-GCM` منتقل شدند. جداسازی چندمستاجری با `WHERE customer_id=?` در همه کوئری‌ها اعمال و دسترسی‌ها در سرور (نه کلاینت) کنترل می‌شوند.

## تهدیدات و پوشش

| تهدید | وضعیت قبل | پوشش فعلی | تست |
|---|---|---|---|
| استخراج توکن از APK | `assets/logo.json` plaintext | توکن هرگز در APK نیست؛ فقط `X-API-Key` (64hex) در APK/کلاینت، توکن اصلی فقط در `telegram_bots` رمزنگاری + فایل `private/telegram_tokens/<id>.enc` 0600 | `grep -r bot_token public_html/` → 0، `apktool d` روی APK تحویلی → هیچ توکن |
| نشت بین مشتریان | همه به یک chat_id | هر مشتری `api_key` و `delivery_token` جدا + همه SELECT با `customer_id` + ادمین فقط `is_admin` | لاگین A → `GET /api/devices.php` فقط دستگاه A، لاگین B جداگانه |
| SQLi | - | همه PDO prepared؛ فیلتر `status` با allowlist + prepared | grep `->query("SELECT.*\$_` → 0 |
| Brute force / Flood | - | `rate_limits` (60/min per IP/api_key) + `check_rate_limit` در auth/telegram/devices | 61 درخواست سریع → 429 |
| افشای اطلاعات | لاگ plaintext | لاگ‌ها فقط `substr(token,0,8)...` + جزئیات JSON بدون توکن | `grep token security.log` → فقط prefix |
| دسترسی غیرمجاز | - | `ACTIVE/SUSPENDED/DISABLED` در سرور؛ `SUSPENDED→403` API، `DISABLED→devices is_active=0` + 403، `is_active=0` دستگاه → 403، `is_connected=0` ربات → 400، `expires_at` لینک → 410 | تغییر وضعیت در `customers.php` → تست API |
| لغو اعتبارنامه | - | `api_keys.is_active=0`, `devices.is_active=0`, `telegram_bots.is_connected=0`, `delivery expires_at`, `token_forms is_used=1` | revoke/reissue تست شد |
| شنود | http | `require_https()` + HSTS + فرم توکن فقط https + وب‌هوک https | `curl http://HOST/api/status` → 403 اگر ENV production |
| XSS/CSRF | - | همه خروجی `htmlspecialchars`, Prepared, `X-Frame-Options: DENY` | تزریق `<script>` در نام مشتری → escape شد |
| بکاپ | - | `private/scripts/backup.php` (mysqldump + tokens.tgz + rotate 30d) + کرون | اجرای دستی → فایل `.sql.gz` |

## رمزنگاری
- الگوریتم: `AES-256-GCM`, nonce 12B, tag 16B, key 32B base64 (`openssl rand -base64 32`)
- توابع: `encrypt_token()` → `ct = base64(cipher+tag), nonce` ؛ `decrypt_token()` برعکس
- کلید در `ENCRYPTION_KEY_B64` از `getenv` یا `config.php`، هرگز در git/ZIP
- فایل پشتیبان: `private/telegram_tokens/<cid>.enc` JSON `{ct, nonce, username}` با 0600

## جداسازی
- هر جدول دارای `customer_id` و FK cascade.
- ادمین سوپر (`admin@local`) فقط via session می‌تواند همه را ببیند؛ بقیه فقط `customer_id` خود.
- APK generic — هیچ `customer_id` یا `token` Bake نشده.

## لاگ و حسابرسی
- `admin_logs` (admin_id, action, target_type/id, details JSON, ip)
- `security_events` + `private/logs/security.log`
- عملیات: `customer_created, payment_approved, manual_delivery_created, delivery_revoked, device_blocked, bot_connected`

## باقی‌مانده پیشنهادی (غیربحرانی)
- افزودن 2FA برای ادمین
- محدودیت IP برای `/admin`
- HSTS preload پس از تثبیت HTTPS
- WAF (ModSecurity) اگر هاست پشتیبانی کند

## تایید
در ZIP نهایی هیچ فایل `config.php` واقعی، `.env`, `*.pem`, `telegram_tokens/*.enc`, `uploads/*`, `backups/*` وجود ندارد — فقط `config.sample.php` + `.gitkeep`.
