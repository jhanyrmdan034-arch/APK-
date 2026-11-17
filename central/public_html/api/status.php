<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$checks = [];
$ok = true;

// DB
try {
    $pdo = db();
    $pdo->query("SELECT 1");
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $checks['database'] = 'fail: ' . $e->getMessage();
    $ok = false;
}

// Token dir writable
$checks['token_store'] = is_writable(TOKEN_STORE_DIR) ? 'ok' : 'not writable';
if ($checks['token_store'] !== 'ok') $ok = false;

// Logs writable
$checks['logs'] = is_writable(LOG_DIR) ? 'ok' : 'not writable';
if ($checks['logs'] !== 'ok') $ok = false;

// Encryption key
try {
    $k = base64_decode(ENCRYPTION_KEY_B64, true);
    $checks['encryption'] = ($k && strlen($k)===32) ? 'ok' : 'invalid key length';
    if ($checks['encryption'] !== 'ok') $ok = false;
} catch (Throwable $e) {
    $checks['encryption'] = 'fail';
    $ok = false;
}

// HTTPS
$checks['https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'ok' : 'not https (set APP_URL to https in production)';

// App URL reachable (self-check)
$checks['app_url'] = APP_URL;

http_response_code($ok ? 200 : 500);
echo json_encode(['ok'=>$ok, 'checks'=>$checks, 'time'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
