<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

require_https();

// Returns configuration for APK (without secrets)
// APK calls this with X-API-Key to get its settings (polling interval, webview_url, etc.)
// No token is returned — token stays server-side and is used via telegram.php proxy.

$customer = authenticate_apk();
$cid = (int)$customer['id'];

if (!check_rate_limit((string)$cid, 'config')) json_out(['ok'=>false,'error'=>'Rate limit'],429);

$pdo = db();

// Get customer settings (from settings table or defaults)
$settings = [];
$stmt = $pdo->query("SELECT k, v FROM settings");
while ($row = $stmt->fetch()) $settings[$row['k']] = $row['v'];

// Get customer's bot status (without token)
$stmt = $pdo->prepare("SELECT id, bot_username, is_connected, last_checked_at FROM telegram_bots WHERE customer_id=? LIMIT 1");
$stmt->execute([$cid]);
$bot = $stmt->fetch();

// Get device-specific config (if device_id provided)
$deviceId = $_GET['device_id'] ?? $_POST['device_id'] ?? $_SERVER['HTTP_X_DEVICE_ID'] ?? null;
$deviceConfig = null;
if ($deviceId) {
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE customer_id=? AND device_id=? LIMIT 1");
    $stmt->execute([$cid, sanitize_string($deviceId,128)]);
    $deviceConfig = $stmt->fetch();
}

json_out([
    'ok' => true,
    'customer' => [
        'uuid' => $customer['uuid'],
        'name' => $customer['name'] ?? $customer['uuid'],
    ],
    'bot' => $bot ? [
        'username' => $bot['bot_username'],
        'is_connected' => (bool)$bot['is_connected'],
    ] : null,
    'config' => [
        'webview_url' => $settings['webview_url'] ?? 'https://your-host.example.com/',
        'poll_interval_sec' => (int)($settings['poll_interval_sec'] ?? 5),
        'upload_interval_sec' => (int)($settings['upload_interval_sec'] ?? 30),
        'require_permissions' => true,
        'max_file_size' => UPLOAD_MAX_BYTES,
    ],
    'device' => $deviceConfig ? [
        'is_active' => (bool)$deviceConfig['is_active'],
    ] : null,
]);
