# API Reference — BABARPHANTOM Central

Base URL: `https://HOST`

All endpoints require HTTPS in production (enforced by `require_https()` + HSTS).

## Authentication

- **Admin Panel**: session cookie `BABAR_SESS` (login via `POST /api/auth.php` with email/password).
- **APK**: `X-API-Key: <64 hex chars>` (or `X-Customer-Token` / `Authorization: Bearer <key>`). `api_keys.api_key` per customer. Isolated by `customer_id`.

Rate limit: `API_RATE_LIMIT_PER_MINUTE` (default 60) per IP or per api_key — DB table `rate_limits`.

---

## Endpoints

### `POST /api/auth.php`
Admin login. Body `{"email":"...","password":"..."}` → sets session, returns `{ok, customer, api_key, is_admin}`. Creates api_key if missing.

### `GET/POST/PUT/DELETE /api/customers.php` (admin only)
- `GET ?page=&per=&q=` — list customers (with devices/bots count)
- `POST {"email","name","password","status"}` — create (generates UUID + api_key)
- `PUT {"id","name","status","password"}` — update
- `DELETE ?id=` — delete (cascade deletes devices, bots, keys)

### `GET/POST/PUT/DELETE /api/devices.php`
- Auth: APK (X-API-Key) sees own devices; admin can filter `?customer_id=`.
- `GET` — list devices for customer
- `POST {"device_id","device_name","model","os_version","app_version"}` — register/upsert
- `PUT {"id","is_active"}` — admin only (ownership check)
- `DELETE ?id=` — ownership check

### `POST /api/telegram.php` (APK proxy)
- Auth: X-API-Key (customer). Server loads `telegram_bots` for that `customer_id`, decrypts token (AES-256-GCM), proxies to `https://api.telegram.org/bot<TOKEN>/<action>`.
- Body: `{"action":"sendMessage","chat_id":"...","text":"..."}` + optional file upload (`multipart/form-data` with `document`/`photo`).
- Allowlist: `sendMessage, sendDocument, sendPhoto, sendAudio, sendVideo, sendLocation, sendChatAction, getUpdates, getFile, getMe`.
- No token ever returned to client. Responses proxied transparently (HTTP code + JSON from Telegram).
- Logs to `device_data` + `security_events` when applicable.

### `GET /api/config.php`
- Auth: X-API-Key. Returns `{customer, bot:{username,is_connected}, config:{webview_url,poll_interval_sec,...}, device}` without secrets.

### `GET /api/status.php`
- Public health check (no auth). Returns `{ok, checks:{database, token_store, logs, encryption, https}, time}`.

---

## Admin Panel

- `admin/index.php` — login + dashboard (stats, health)
- `admin/customers.php` — CRUD customers
- `admin/bots.php?customer_id=` — register/validate token (calls `getMe`), disconnect, delete, test send; stores encrypted in DB + `private/telegram_tokens/<customer_id>.enc`
- `admin/devices.php?customer_id=` — list/delete devices
- `admin/settings.php` — settings + change password + security log tail

---

## Security Notes

- Tokens: `encrypt_token()` → `AES-256-GCM` with 12-byte nonce, 16-byte tag; key from `ENCRYPTION_KEY_B64` (32 bytes). Stored as `(ct_b64, nonce_b64)` in `telegram_bots` + file backup `private/telegram_tokens/<id>.enc` (0600). Never logged.
- SQL: all queries via `PDO prepared` — no string interpolation.
- Input: `sanitize_string()` + `is_valid_email/bot_token` allowlists; file size capped `UPLOAD_MAX_BYTES`.
- Headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`; HTTPS redirect + HSTS ready.
- Logging: every auth/bot/device change → `security_events` + `private/logs/security.log`.
- Isolation: every data query `WHERE customer_id=?`; APK cannot enumerate other customers' data. Admin (`is_admin()`) can, but only via session.
