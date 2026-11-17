<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

/**
 * Helpers — encryption, validation, rate limiting, logging
 */

// --- DB ---
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

// --- Encryption (AES-256-GCM) ---
function encrypt_token(string $plain): array {
    $key = base64_decode(ENCRYPTION_KEY_B64, true);
    if (!$key || strlen($key) !== 32) {
        throw new RuntimeException('Invalid ENCRYPTION_KEY_B64 — must be 32 bytes base64');
    }
    $nonce = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Encryption failed');
    // store as base64(cipher+tag) + nonce separately for simplicity
    return [
        'ct' => base64_encode($cipher . $tag),
        'nonce' => base64_encode($nonce),
    ];
}

function decrypt_token(string $ct_b64, string $nonce_b64): string {
    $key = base64_decode(ENCRYPTION_KEY_B64, true);
    $nonce = base64_decode($nonce_b64, true);
    $raw = base64_decode($ct_b64, true);
    if (!$key || !$nonce || !$raw) throw new RuntimeException('Invalid encrypted data');
    $tag = substr($raw, -16);
    $cipher = substr($raw, 0, -16);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) throw new RuntimeException('Decryption failed');
    return $plain;
}

// --- Validation ---
function is_valid_email(string $e): bool { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
function is_valid_bot_token(string $t): bool {
    // Telegram token: 8-12 digits, colon, 30+ chars [A-Za-z0-9_-]
    return (bool) preg_match('/^\d{8,12}:[A-Za-z0-9_-]{30,}$/', $t);
}
function sanitize_string(string $s, int $max = 255): string {
    $s = trim($s);
    $s = mb_substr($s, 0, $max);
    return $s;
}

// --- Security logging ---
function sec_log(string $type, ?int $customer_id = null, array $details = []): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO security_events (customer_id, event_type, ip, user_agent, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $type, $ip, $ua, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        // also file log
        $line = date('c') . " [$type] cid=" . ($customer_id ?? '-') . " ip=$ip " . json_encode($details, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents(SECURITY_LOG, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // never break main flow on log failure
    }
}

// --- Rate limiting (DB-based, per minute) ---
function check_rate_limit(string $identifier, string $endpoint): bool {
    $pdo = db();
    $window = date('Y-m-d H:i:00');
    $pdo->beginTransaction();
    try {
        // upsert
        $stmt = $pdo->prepare("INSERT INTO rate_limits (identifier, endpoint, requests, window_start) VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE requests = requests + 1");
        $stmt->execute([$identifier, $endpoint, $window]);
        $stmt = $pdo->prepare("SELECT requests FROM rate_limits WHERE identifier=? AND endpoint=? AND window_start=?");
        $stmt->execute([$identifier, $endpoint, $window]);
        $row = $stmt->fetch();
        $pdo->commit();
        $count = (int)($row['requests'] ?? 0);
        return $count <= API_RATE_LIMIT_PER_MINUTE;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return true; // fail open, but log
    }
}

// --- JSON response ---
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if (ENV === 'production' && isset($_SERVER['HTTPS'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_https(): void {
    if (ENV === 'production' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
        // Allow behind proxy with X-Forwarded-Proto
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($proto !== 'https') {
            json_out(['ok' => false, 'error' => 'HTTPS required'], 403);
        }
    }
}

function get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    // also allow X-API-Key header for APK
    if (!empty($_SERVER['HTTP_X_API_KEY'])) return trim($_SERVER['HTTP_X_API_KEY']);
    if (!empty($_SERVER['HTTP_X_CUSTOMER_TOKEN'])) return trim($_SERVER['HTTP_X_CUSTOMER_TOKEN']);
    return null;
}

// --- Customer auth (for APK) ---
function authenticate_apk(): array {
    $apiKey = get_bearer_token();
    if (!$apiKey) {
        // Try POST/GET param as fallback (less secure, but for compatibility)
        $apiKey = $_POST['api_key'] ?? $_GET['api_key'] ?? null;
    }
    if (!$apiKey) {
        json_out(['ok' => false, 'error' => 'Missing API key'], 401);
    }
    // Rate limit by api_key
    if (!check_rate_limit($apiKey, 'apk_auth')) {
        json_out(['ok' => false, 'error' => 'Rate limit exceeded'], 429);
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT c.id, c.uuid, c.email, c.status, k.id as key_id FROM api_keys k JOIN customers c ON c.id=k.customer_id WHERE k.api_key=? AND k.is_active=1 AND c.status='active' LIMIT 1");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch();
    if (!$row) {
        sec_log('apk_auth_failed', null, ['api_key_prefix' => substr($apiKey, 0, 8)]);
        json_out(['ok' => false, 'error' => 'Invalid API key'], 401);
    }
    // update last_used
    $pdo->prepare("UPDATE api_keys SET last_used_at=NOW() WHERE id=?")->execute([$row['key_id']]);
    return $row;
}

// --- Admin auth (session) ---
function admin_require_login(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
    if (empty($_SESSION['customer_id']) || empty($_SESSION['is_admin'])) {
        // For API, return JSON; for admin panel, redirect will be handled by caller
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        header('Location: index.php');
        exit;
    }
    // Check customer still active
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, uuid, email, name, status FROM customers WHERE id=? LIMIT 1");
    $stmt->execute([$_SESSION['customer_id']]);
    $c = $stmt->fetch();
    if (!$c || $c['status'] !== 'active') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    return $c;
}

function is_admin(int $customer_id): bool {
    // Simple check: first customer (admin@local) or flag in DB
    // For now, consider customer with email admin@local as super admin
    $pdo = db();
    $stmt = $pdo->prepare("SELECT email FROM customers WHERE id=?");
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();
    return $row && $row['email'] === 'admin@local';
}
