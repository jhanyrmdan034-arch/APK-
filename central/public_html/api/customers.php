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
            $stmt = $pdo->prepare("SELECT id, uuid, email, name, status, created_at FROM customers WHERE email LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
            $like = "%$q%";
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $per, PDO::PARAM_INT);
            $stmt->bindValue(4, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT id, uuid, email, name, status, created_at FROM customers ORDER BY id DESC LIMIT ? OFFSET ?");
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
        $email = sanitize_string($input['email'] ?? '', 255);
        $name = sanitize_string($input['name'] ?? '', 255);
        $password = $input['password'] ?? '';
        $status = $input['status'] ?? 'active';

        if (!is_valid_email($email)) json_out(['ok' => false, 'error' => 'Invalid email'], 400);
        if (strlen($password) < 8) json_out(['ok' => false, 'error' => 'Password too short (min 8)'], 400);
        if (!in_array($status, ['active','blocked','pending'], true)) $status = 'active';

        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff), random_int(0,0x0fff)|0x4000, random_int(0,0x3fff)|0x8000, random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff));
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $pdo->prepare("INSERT INTO customers (uuid, email, password_hash, name, status) VALUES (?, ?, ?, ?, ?)")->execute([$uuid, $email, $hash, $name, $status]);
        $id = (int)$pdo->lastInsertId();
        // create api key
        $apiKey = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO api_keys (customer_id, api_key) VALUES (?, ?)")->execute([$id, $apiKey]);

        sec_log('customer_created', (int)$customer['id'], ['new_customer_id' => $id, 'email' => $email]);
        json_out(['ok' => true, 'id' => $id, 'uuid' => $uuid, 'api_key' => $apiKey], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);
        $id = (int)($input['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'Missing id'], 400);

        $fields = [];
        $params = [];
        if (isset($input['name'])) { $fields[] = 'name=?'; $params[] = sanitize_string($input['name'], 255); }
        if (isset($input['status']) && in_array($input['status'], ['active','blocked','pending'], true)) { $fields[] = 'status=?'; $params[] = $input['status']; }
        if (!empty($input['password'])) {
            if (strlen($input['password']) < 8) json_out(['ok'=>false,'error'=>'Password too short'],400);
            $fields[] = 'password_hash=?'; $params[] = password_hash($input['password'], PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]);
        }
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
