<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

require_https();

// APK authenticates with api_key, Admin with session
$isApk = false;
$customer = null;

// Try APK auth first (X-API-Key)
$bearer = get_bearer_token();
if ($bearer) {
    // Check if it's an api_key (64 hex) vs session
    if (preg_match('/^[a-f0-9]{64}$/i', $bearer)) {
        $customer = authenticate_apk(); // will exit on fail
        $isApk = true;
    }
}
if (!$customer) {
    if (session_status() === PHP_SESSION_NONE) { session_name(SESSION_NAME); session_start(); }
    if (!empty($_SESSION['customer_id'])) {
        $customer = admin_require_login();
    } else {
        json_out(['ok'=>false,'error'=>'Unauthorized'],401);
    }
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // APK: list own devices, Admin: list own or all if admin
        $cid = (int)$customer['id'];
        $isAdmin = is_admin($cid);
        $filterCid = $cid;
        if ($isAdmin && isset($_GET['customer_id'])) {
            $filterCid = (int)$_GET['customer_id'];
        }
        // Ensure customer can only see own devices unless admin
        if (!$isAdmin) $filterCid = $cid;

        $stmt = $pdo->prepare("SELECT id, device_id, device_name, model, os_version, app_version, last_seen_at, is_active, created_at FROM devices WHERE customer_id=? ORDER BY last_seen_at DESC");
        $stmt->execute([$filterCid]);
        $rows = $stmt->fetchAll();
        json_out(['ok'=>true, 'devices'=>$rows]);
    }

    if ($method === 'POST') {
        // APK registers/updates device, Admin can also but normally APK
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        $device_id = sanitize_string($input['device_id'] ?? '', 128);
        if ($device_id === '') json_out(['ok'=>false,'error'=>'Missing device_id'],400);
        // Rate limit per device
        if (!check_rate_limit($device_id, 'device_register')) json_out(['ok'=>false,'error'=>'Rate limit'],429);

        $device_name = sanitize_string($input['device_name'] ?? '', 255);
        $model = sanitize_string($input['model'] ?? '', 255);
        $os = sanitize_string($input['os_version'] ?? '', 64);
        $appVer = sanitize_string($input['app_version'] ?? '', 64);

        $cid = (int)$customer['id'];
        // اگر حساب SUSPENDED/DISABLED باشد، ثبت دستگاه جدید ممنوع (فقط مشاهده)
        if (normalize_status($customer['status']) !== STATUS_ACTIVE) {
            json_out(['ok'=>false,'error'=>'Account '.normalize_status($customer['status']).' — device registration blocked'],403);
        }
        // Upsert
        $stmt = $pdo->prepare("SELECT id, is_active FROM devices WHERE customer_id=? AND device_id=? LIMIT 1");
        $stmt->execute([$cid, $device_id]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ((int)$existing['is_active']===0) {
                json_out(['ok'=>false,'error'=>'Device blocked by admin'],403);
            }
            $pdo->prepare("UPDATE devices SET device_name=?, model=?, os_version=?, app_version=?, last_seen_at=NOW() WHERE id=?")
                ->execute([$device_name, $model, $os, $appVer, $existing['id']]);
            $did = (int)$existing['id'];
        } else {
            // Check limit
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=?");
            $cnt->execute([$cid]);
            $max = (int)($pdo->query("SELECT v FROM settings WHERE k='max_devices_per_customer'")->fetchColumn() ?: 50);
            if ((int)$cnt->fetchColumn() >= $max) {
                json_out(['ok'=>false,'error'=>'Device limit reached'],403);
            }
            $pdo->prepare("INSERT INTO devices (customer_id, device_id, device_name, model, os_version, app_version, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$cid, $device_id, $device_name, $model, $os, $appVer]);
            $did = (int)$pdo->lastInsertId();
        }
        sec_log('device_register', $cid, ['device_id'=>$device_id, 'did'=>$did]);
        json_out(['ok'=>true, 'device_db_id'=>$did]);
    }

    if ($method === 'PUT') {
        // Update device (e.g., deactivate)
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) json_out(['ok'=>false,'error'=>'Invalid JSON'],400);
        $did = (int)($input['id'] ?? 0);
        if (!$did) json_out(['ok'=>false,'error'=>'Missing id'],400);
        // Ensure ownership
        $stmt = $pdo->prepare("SELECT customer_id FROM devices WHERE id=?");
        $stmt->execute([$did]);
        $row = $stmt->fetch();
        if (!$row) json_out(['ok'=>false,'error'=>'Not found'],404);
        $cid = (int)$customer['id'];
        if ((int)$row['customer_id'] !== $cid && !is_admin($cid)) json_out(['ok'=>false,'error'=>'Forbidden'],403);
        if (isset($input['is_active'])) {
            $pdo->prepare("UPDATE devices SET is_active=? WHERE id=?")->execute([(int)$input['is_active'], $did]);
        }
        json_out(['ok'=>true]);
    }

    if ($method === 'DELETE') {
        $did = (int)($_GET['id'] ?? 0);
        if (!$did) json_out(['ok'=>false,'error'=>'Missing id'],400);
        $stmt = $pdo->prepare("SELECT customer_id FROM devices WHERE id=?");
        $stmt->execute([$did]);
        $row = $stmt->fetch();
        if (!$row) json_out(['ok'=>false,'error'=>'Not found'],404);
        $cid = (int)$customer['id'];
        if ((int)$row['customer_id'] !== $cid && !is_admin($cid)) json_out(['ok'=>false,'error'=>'Forbidden'],403);
        $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([$did]);
        sec_log('device_deleted', $cid, ['did'=>$did]);
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'Method not allowed'],405);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['ok'=>false,'error'=>'Server error'],500);
}
