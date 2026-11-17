<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

require_https();

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

$customer = admin_require_login();
if (!is_admin((int)$customer['id'])) {
    json_out(['ok' => false, 'error' => 'Forbidden — admin only'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

try {
    if ($method === 'GET') {
        // List customers with pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(10, (int)($_GET['per'] ?? 20)));
        $offset = ($page - 1) * $per;
        $q = sanitize_string($_GET['q'] ?? '', 100);

        if ($q !== '') {
            $stmt = $pdo->prepare("SELECT id, uuid, name, status, created_at FROM customers WHERE name LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
            $like = "%$q%";
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $per, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT id, uuid, name, status, created_at FROM customers ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $per, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        $rows = $stmt->fetchAll();
        // also count devices/bots per customer
        foreach ($rows as &$r) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=?");
            $s->execute([$r['id']]);
            $r['devices_count'] = (int)$s->fetchColumn();
            $s = $pdo->prepare("SELECT COUNT(*) FROM telegram_bots WHERE customer_id=?");
            $s->execute([$r['id']]);
            $r['bots_count'] = (int)$s->fetchColumn();
        }
        json_out(['ok' => true, 'customers' => $rows, 'page' => $page]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        $name = sanitize_string($input['name'] ?? $input['username'] ?? '', 32);
        $statusRaw = $input['status'] ?? 'ACTIVE';
        $status = normalize_status($statusRaw);
        if (!in_array($status, [STATUS_ACTIVE,STATUS_SUSPENDED,STATUS_DISABLED], true)) $status = STATUS_ACTIVE;

        // حالت ساده: فقط نام کاربری (بدون ایمیل/رمز) — برای سازگاری، اگر ایمیل/رمز آمد قدیمی را ساپورت کن
        if ($name === '') json_out(['ok'=>false,'error'=>'نام کاربری الزامی است'],400);
        if (!is_valid_username($name)) json_out(['ok'=>false,'error'=>'نام کاربری نامعتبر (3-32)'],400);
        try {
            $cust = create_customer_by_username($name);
            $id = $cust['id']; $uuid=$cust['uuid']; $apiKey=$cust['api_key'];
            if ($status !== 'ACTIVE') $pdo->prepare("UPDATE customers SET status=? WHERE id=?")->execute([$status,$id]);
            sec_log('customer_created', (int)$customer['id'], ['new_customer_id'=>$id,'username'=>$name]);
            json_out(['ok'=>true,'id'=>$id,'uuid'=>$uuid,'api_key'=>$apiKey],201);
        } catch(Throwable $e){
            json_out(['ok'=>false,'error'=>$e->getMessage()],400);
        }
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);
        $id = (int)($input['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'Missing id'], 400);

        $fields = [];
        $params = [];
        if (isset($input['name']) || isset($input['username'])) {
            $nameTmp = sanitize_string($input['name'] ?? $input['username'], 32);
            if (!is_valid_username($nameTmp)) json_out(['ok'=>false,'error'=>'نام کاربری نامعتبر (3-32)'],400);
            $fields[] = 'name=?'; $params[] = $nameTmp;
        }
        if (isset($input['status'])) { $fields[] = 'status=?'; $params[] = normalize_status($input['status']); }
        if (!$fields) json_out(['ok'=>false,'error'=>'Nothing to update'],400);
        $params[] = $id;
        $sql = "UPDATE customers SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id=?";
        $pdo->prepare($sql)->execute($params);
        sec_log('customer_updated', (int)$customer['id'], ['target_id' => $id, 'fields' => $fields]);
        json_out(['ok'=>true]);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) json_out(['ok'=>false,'error'=>'Missing id'],400);
        if ($id === (int)$customer['id']) json_out(['ok'=>false,'error'=>'Cannot delete yourself'],400);
        $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
        sec_log('customer_deleted', (int)$customer['id'], ['target_id'=>$id]);
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'Method not allowed'],405);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['ok'=>false,'error'=>'Server error'],500);
}
