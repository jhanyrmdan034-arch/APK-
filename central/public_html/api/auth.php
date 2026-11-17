<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

require_https();
header('Content-Type: application/json; charset=utf-8');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Rate limit by IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!check_rate_limit($ip, 'auth')) {
    json_out(['ok' => false, 'error' => 'Too many attempts'], 429);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$email = sanitize_string($input['email'] ?? '', 255);
$password = $input['password'] ?? '';

if (!is_valid_email($email) || strlen($password) < 8) {
    sec_log('auth_failed', null, ['email' => $email, 'reason' => 'validation']);
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 400);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, uuid, email, password_hash, status, name FROM customers WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $c = $stmt->fetch();
    if (!$c || !password_verify($password, $c['password_hash'])) {
        sec_log('auth_failed', null, ['email' => $email]);
        // Mitigate timing
        usleep(random_int(100000, 300000));
        json_out(['ok' => false, 'error' => 'Invalid email or password'], 401);
    }
    if ($c['status'] !== 'active') {
        sec_log('auth_blocked', (int)$c['id'], ['status' => $c['status']]);
        json_out(['ok' => false, 'error' => 'Account blocked'], 403);
    }

    // Start session for admin panel (also return token for API clients)
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
        session_regenerate_id(true);
    }
    $_SESSION['customer_id'] = (int)$c['id'];
    $_SESSION['customer_uuid'] = $c['uuid'];
    $_SESSION['email'] = $c['email'];
    $_SESSION['is_admin'] = is_admin((int)$c['id']);
    $_SESSION['created'] = time();

    sec_log('auth_success', (int)$c['id'], ['email' => $email]);

    // Also issue a simple API key for APK if needed (return existing)
    $stmt = $pdo->prepare("SELECT api_key FROM api_keys WHERE customer_id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$c['id']]);
    $keyRow = $stmt->fetch();
    $apiKey = $keyRow['api_key'] ?? null;
    if (!$apiKey) {
        // create one
        $apiKey = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO api_keys (customer_id, api_key, name) VALUES (?, ?, 'auto')")->execute([$c['id'], $apiKey]);
    }

    json_out([
        'ok' => true,
        'customer' => ['uuid' => $c['uuid'], 'email' => $c['email'], 'name' => $c['name']],
        'api_key' => $apiKey,
        'is_admin' => $_SESSION['is_admin'],
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error'], 500);
}
