# API Reference — BABARPHANTOM Central Sales Platform v2

Base URL: `https://HOST`

All endpoints require HTTPS in production (`require_https()` + HSTS).

## Authentication

- **Admin Panel**: session cookie `BABAR_SESS` (login `POST /api/auth.php` email/password → session + `api_key`).
- **APK / Customer**: `X-API-Key: <64 hex>` (or `Authorization: Bearer <key>` / `X-Customer-Token`). Per-customer `api_keys.api_key`. Status must be `ACTIVE` else `403`.
- **Rate limit**: `API_RATE_LIMIT_PER_MINUTE` (60/min) per IP or api_key — `rate_limits` table.

---

## Endpoints

### `POST /api/auth.php`
Admin login. Body `{"email","password"}` → `{ok, customer, api_key, is_admin}`. Creates api_key if missing. Only `ACTIVE` can login.

### `GET/POST/PUT/DELETE /api/customers.php` (admin only)
- `GET ?page=&per=&q=` — list (with devices/bots count)
- `POST {"email","name","password","status":"ACTIVE|SUSPENDED|DISABLED"}` — create + wallet + api_key
- `PUT {"id","name","status","password"}` — update
- `DELETE ?id=` — delete (cascade)

### `GET/POST/PUT/DELETE /api/devices.php`
- `GET` — list for customer (admin can `?customer_id=`)
- `POST {"device_id","device_name","model","os_version","app_version"}` — register/upsert (blocked if account not ACTIVE or device blocked)
- `PUT {"id","is_active"}` — toggle (ownership)
- `DELETE ?id=` — ownership

### `POST /api/telegram.php` (APK proxy)
- `X-API-Key` + optional `X-Device-Id`. Server loads `telegram_bots` for `customer_id`, decrypts token (AES-256-GCM), proxies to `https://api.telegram.org/bot<TOKEN>/<action>`.
- Body `{"action":"sendMessage|sendDocument|...","chat_id","text","device_id", ...}` + multipart file.
- Allowlist: `sendMessage, sendDocument, sendPhoto, sendAudio, sendVideo, sendLocation, sendChatAction, getUpdates, getFile, getMe`.
- Checks: account must be `ACTIVE`, device must be `is_active=1`. Never returns token.

### `GET /api/config.php`
`X-API-Key` → `{customer, bot:{username,is_connected}, config:{webview_url,poll_interval,...}, device}` without secrets.

### `GET /api/status.php`
Public health: `{ok, checks:{database,token_store,logs,encryption,https}, time}`.

### `GET /api/wallet.php`
`X-API-Key` or admin session → `{wallet:{balance,currency}, transactions:[20]}`.

### `GET/POST /api/payments.php`
- `GET` — list `payment_requests` for customer
- `POST {"amount","method"}` — create `pending` request (admin approves in `admin/payments.php` → wallet credited automatically)

### `GET/POST /api/orders.php`
- `GET` — list orders
- `POST {"product_code"}` — create order if wallet sufficient → `paid` + auto `apk_deliveries` if `apk_auto_delivery=1`

### `GET /api/apk.php`
- Admin: list all `apk_files`
- Customer: `{active_apk, deliveries}`

### `GET /api/delivery.php?t=<64hex>`
Public download with `delivery_token` (no login). Checks `customer.status==ACTIVE`, `expires_at`, file exists, increments `download_count`. Returns APK binary.

### `POST /api/sales_webhook.php`
Telegram webhook for sales bot (private/telegram/sales_bot.php). Handles `/start`, wallet, charge, receipt photo, buy, token form link, APK delivery.

### `GET/POST /token.php?t=<one-time>`
Secure HTTPS form for bot token submission. `token_forms` one-time, TTL 30min, validated via `getMe`, stored encrypted in `telegram_bots` + `private/telegram_tokens/<cid>.enc`. Auto-delivers APK if order `paid` pending.

---

## Admin Panel

- `admin/index.php` — login + dashboard (pending payments, wallets_sum, orders, apk, etc.)
- `admin/customers.php` — CRUD + status ACTIVE/SUSPENDED/DISABLED + disconnect bot
- `admin/wallets.php` — balances + manual adjustment
- `admin/payments.php` — approve/reject (auto wallet credit)
- `admin/orders.php` — list + manual deliver
- `admin/bots.php?customer_id=` — token via secure link or direct (getMe), disconnect/delete/test
- `admin/devices.php?customer_id=` — list + block/unblock/delete per device
- `admin/apk.php` — upload APK (PK check), activate, delete
- `admin/logs.php` — admin_logs + security_events
- `admin/settings.php` — settings, password, backup

---

## Security Notes

- Tokens: `AES-256-GCM` 12-byte nonce, 16-byte tag, key `ENCRYPTION_KEY_B64` (32B). Stored `(ct,nonce)` in `telegram_bots` + `private/telegram_tokens/<id>.enc` 0600. Never logged.
- SQL: PDO prepared everywhere (status filters use allowlist).
- Input: `sanitize_string()` + `is_valid_*` + `UPLOAD_MAX_BYTES`.
- Headers: `X-Content-Type-Options`, `X-Frame-Options`, HSTS ready.
- Isolation: `WHERE customer_id=?` always; APK cannot enumerate others.
- Access: `ACTIVE`=full, `SUSPENDED`=view only (API 403), `DISABLED`=blocked + devices set `is_active=0` automatically.
- Device block: `devices.is_active=0` → `403 Device blocked` on both `devices.php` and `telegram.php`.

---

## Sales Automation Flow

```
User /start → ensure_customer → wallet 0
    → charge request (amount) → payment_requests pending
        → admin approve → wallets +balance → notify sales bot
    → order buy (product_code) → wallet -price → orders paid
        → if apk_auto_delivery → apk_deliveries + api_keys + notify + sendDocument
    → token form link → /token.php?t=... (HTTPS, one-time)
        → getMe → encrypt → telegram_bots
        → if order paid pending delivery → auto deliver
    → delivery.php?t=... → APK download (no token inside)
```
