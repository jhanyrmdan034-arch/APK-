<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

/**
 * Helpers — encryption, validation, rate limiting, logging, wallets, orders, token-forms
 */

// Status constants (جدید: ACTIVE/SUSPENDED/DISABLED)
const STATUS_ACTIVE = 'ACTIVE';
const STATUS_SUSPENDED = 'SUSPENDED';
const STATUS_DISABLED = 'DISABLED';
// Legacy mapping for backward compat
function normalize_status(string $s): string {
    $map = ['active'=>'ACTIVE','blocked'=>'DISABLED','pending'=>'SUSPENDED','ACTIVE'=>'ACTIVE','SUSPENDED'=>'SUSPENDED','DISABLED'=>'DISABLED'];
    return $map[$s] ?? $s;
}
function status_allows_login(string $status): bool { return normalize_status($status) === STATUS_ACTIVE; }
function status_allows_api(string $status): bool { return normalize_status($status) === STATUS_ACTIVE; }

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
    return (bool) preg_match('/^\d{8,12}:[A-Za-z0-9_-]{30,}$/', $t);
}
function is_valid_username(string $u): bool {
    // نام کاربری مشتری: 3-32 کاراکتر، حروف/عدد/underscore/خط تیره، بدون فاصله
    // یکتا بودن در DB جداگانه چک می‌شود
    return (bool) preg_match('/^[a-zA-Z0-9_\-\x{0600}-\x{06FF}]{3,32}$/u', $u);
}
function sanitize_string(string $s, int $max = 255): string {
    $s = trim($s);
    $s = mb_substr($s, 0, $max);
    return $s;
}
function generate_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff), random_int(0,0x0fff)|0x4000, random_int(0,0x3fff)|0x8000, random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff));
}
function generate_api_key(): string { return bin2hex(random_bytes(32)); }
function generate_delivery_token(): string { return bin2hex(random_bytes(32)); }

// --- Security logging ---
function sec_log(string $type, ?int $customer_id = null, array $details = []): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO security_events (customer_id, event_type, ip, user_agent, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $type, $ip, $ua, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        $line = date('c') . " [$type] cid=" . ($customer_id ?? '-') . " ip=$ip " . json_encode($details, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents(SECURITY_LOG, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {}
}
function admin_log(int $admin_id, string $action, ?string $target_type=null, $target_id=null, array $details=[]): void {
    try {
        $pdo=db();
        $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)")
            ->execute([$admin_id,$action,$target_type,(string)$target_id,json_encode($details,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
        sec_log('admin_'.$action, $admin_id, array_merge(['target'=>$target_type,'tid'=>$target_id],$details));
    } catch(Throwable $e){}
}

// --- Rate limiting (DB-based, per minute) ---
function check_rate_limit(string $identifier, string $endpoint): bool {
    $pdo = db();
    $window = date('Y-m-d H:i:00');
    $pdo->beginTransaction();
    try {
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
        try{$pdo->rollBack();}catch(Throwable $x){}
        return true;
    }
}

// --- JSON response ---
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
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
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($proto !== 'https') {
            json_out(['ok' => false, 'error' => 'HTTPS required'], 403);
        }
    }
}

function get_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    if (!empty($_SERVER['HTTP_X_API_KEY'])) return trim($_SERVER['HTTP_X_API_KEY']);
    if (!empty($_SERVER['HTTP_X_CUSTOMER_TOKEN'])) return trim($_SERVER['HTTP_X_CUSTOMER_TOKEN']);
    return null;
}

// --- Customer auth (for APK) ---
function authenticate_apk(): array {
    $apiKey = get_bearer_token();
    if (!$apiKey) {
        $apiKey = $_POST['api_key'] ?? $_GET['api_key'] ?? null;
    }
    if (!$apiKey) {
        json_out(['ok' => false, 'error' => 'Missing API key'], 401);
    }
    if (!check_rate_limit($apiKey, 'apk_auth')) {
        json_out(['ok' => false, 'error' => 'Rate limit exceeded'], 429);
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT c.id, c.uuid, c.email, c.name, c.status, k.id as key_id FROM api_keys k JOIN customers c ON c.id=k.customer_id WHERE k.api_key=? AND k.is_active=1 LIMIT 1");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch();
    if (!$row) {
        sec_log('apk_auth_failed', null, ['api_key_prefix' => substr($apiKey, 0, 8)]);
        json_out(['ok' => false, 'error' => 'Invalid API key'], 401);
    }
    $st = normalize_status($row['status']);
    if ($st !== STATUS_ACTIVE) {
        json_out(['ok'=>false,'error'=>'Account '.$st.' — contact support'], 403);
    }
    // also check if api_key revoked due to device block? api_keys.is_active covers it
    $pdo->prepare("UPDATE api_keys SET last_used_at=NOW() WHERE id=?")->execute([$row['key_id']]);
    return $row;
}

// --- Admin auth (session) ---
function admin_require_login(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
    if (empty($_SESSION['customer_id'])) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        header('Location: index.php');
        exit;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, uuid, email, name, status, telegram_id FROM customers WHERE id=? LIMIT 1");
    $stmt->execute([$_SESSION['customer_id']]);
    $c = $stmt->fetch();
    if (!$c || !status_allows_login($c['status'])) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    return $c;
}
function admin_require_admin(): array {
    $me = admin_require_login();
    if (!is_admin((int)$me['id'])) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) json_out(['ok'=>false,'error'=>'Forbidden — admin only'],403);
        http_response_code(403); exit('فقط مدیر کل');
    }
    return $me;
}

function is_admin(int $customer_id): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT email FROM customers WHERE id=?");
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();
    // admin@local is super admin; also check settings admin_ids or role column if exists
    return $row && $row['email'] === 'admin@local';
}

// --- Wallet ---
function get_wallet(int $customer_id): array {
    $pdo=db();
    $stmt=$pdo->prepare("SELECT * FROM wallets WHERE customer_id=? LIMIT 1");
    $stmt->execute([$customer_id]);
    $w=$stmt->fetch();
    if(!$w){
        $pdo->prepare("INSERT IGNORE INTO wallets (customer_id,balance) VALUES (?,0)")->execute([$customer_id]);
        $stmt->execute([$customer_id]); $w=$stmt->fetch();
    }
    return $w;
}
function wallet_add_transaction(int $customer_id, string $type, int $amount, ?string $refType=null, $refId=null, ?string $desc=null, ?int $by=null): int {
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $w=get_wallet($customer_id);
        $newBal = (int)$w['balance'] + $amount;
        $pdo->prepare("UPDATE wallets SET balance=? WHERE customer_id=?")->execute([$newBal,$customer_id]);
        $stmt=$pdo->prepare("INSERT INTO wallet_transactions (customer_id,type,amount,balance_after,reference_type,reference_id,description,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$customer_id,$type,$amount,$newBal,$refType,$refId,$desc,$by]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    }catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}

// --- Token form ---
function create_token_form(int $customer_id): array {
    $tok = bin2hex(random_bytes(32));
    $exp = date('Y-m-d H:i:s', time()+ TOKEN_FORM_TTL_MINUTES*60);
    $pdo=db();
    $pdo->prepare("INSERT INTO token_forms (customer_id, token, expires_at, ip) VALUES (?,?,?,?)")->execute([$customer_id,$tok,$exp,$_SERVER['REMOTE_ADDR']??null]);
    return ['token'=>$tok,'expires_at'=>$exp,'url'=>APP_URL.'/token.php?t='.$tok];
}
function validate_token_form(string $tok): ?array {
    $pdo=db();
    $stmt=$pdo->prepare("SELECT * FROM token_forms WHERE token=? LIMIT 1");
    $stmt->execute([$tok]);
    $r=$stmt->fetch();
    if(!$r) return null;
    if((int)$r['is_used']===1) return null;
    if(strtotime($r['expires_at']) < time()) return null;
    return $r;
}

// --- APK delivery ---
function create_apk_delivery(int $customer_id, int $apk_file_id, ?int $order_id=null): array {
    $pdo=db();
    $token = generate_delivery_token();
    $apiKey = generate_api_key();
    $expires = date('Y-m-d H:i:s', time()+ APK_DELIVERY_EXPIRES_DAYS*86400);
    $pdo->prepare("INSERT INTO apk_deliveries (customer_id, apk_file_id, order_id, delivery_token, api_key, expires_at) VALUES (?,?,?,?,?,?)")
        ->execute([$customer_id,$apk_file_id,$order_id,$token,$apiKey,$expires]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO api_keys (customer_id, api_key, name) VALUES (?,?,?)")->execute([$customer_id,$apiKey,'apk_delivery_'.$id]);
    return ['id'=>$id,'token'=>$token,'api_key'=>$apiKey,'expires_at'=>$expires];
}
function get_active_apk(): ?array {
    $pdo=db();
    $stmt=$pdo->query("SELECT * FROM apk_files WHERE is_active=1 ORDER BY id DESC LIMIT 1");
    $r=$stmt->fetch();
    return $r?:null;
}

function ensure_customer_for_telegram(int $tgId, string $username='', string $firstName=''): array {
    $pdo=db();
    $stmt=$pdo->prepare("SELECT * FROM customers WHERE telegram_id=? LIMIT 1");
    $stmt->execute([$tgId]);
    $c=$stmt->fetch();
    if($c) {
        if($username && $c['telegram_username']!==$username){
            $pdo->prepare("UPDATE customers SET telegram_username=? WHERE id=?")->execute([$username,$c['id']]);
        }
        return $c;
    }
    // create new customer without email/password — only username (v3)
    $uuid=generate_uuid();
    $baseName=$firstName ?: $username ?: "کاربر_$tgId";
    $name=$baseName;
    // یکتاسازی نام کاربری
    $i=0;
    while (true) {
        $chk=$pdo->prepare("SELECT id FROM customers WHERE name=? LIMIT 1");
        $chk->execute([$name]);
        if(!$chk->fetch()) break;
        $i++; $name=$baseName.'_'.$i;
        if($i>99) { $name=$baseName.'_'.$tgId; break; }
    }
    // ایمیل و رمز برای مشتری عادی NULL — فقط ادمین email/password دارد
    $pdo->prepare("INSERT INTO customers (uuid,email,password_hash,name,status,telegram_id,telegram_username) VALUES (?,?,?,?,?,?,?)")
        ->execute([$uuid,null,null,$name,STATUS_ACTIVE,$tgId,$username]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wallets (customer_id,balance) VALUES (?,0)")->execute([$id]);
    $apiKey=generate_api_key();
    $pdo->prepare("INSERT INTO api_keys (customer_id,api_key) VALUES (?,?)")->execute([$id,$apiKey]);
    $stmt->execute([$tgId]);
    return $stmt->fetch();
}
function create_customer_by_username(string $username): array {
    $pdo=db();
    $username=sanitize_string($username,32);
    if(!is_valid_username($username)) throw new RuntimeException('نام کاربری نامعتبر (3-32 حرف/عدد/زیرخط)');
    $chk=$pdo->prepare("SELECT id FROM customers WHERE name=? LIMIT 1");
    $chk->execute([$username]);
    if($chk->fetch()) throw new RuntimeException('نام کاربری تکراری است');
    $uuid=generate_uuid();
    // ایمیل و رمز برای مشتری عادی NULL — فقط ادمین email/password دارد
    $pdo->prepare("INSERT INTO customers (uuid,email,password_hash,name,status) VALUES (?,?,?,?,?)")
        ->execute([$uuid,null,null,$username,'ACTIVE']);
    $cid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT IGNORE INTO wallets (customer_id,balance) VALUES (?,0)")->execute([$cid]);
    $apiKey=generate_api_key();
    $pdo->prepare("INSERT INTO api_keys (customer_id,api_key,name) VALUES (?,?,?)")->execute([$cid,$apiKey,'manual_default']);
    return ['id'=>$cid,'uuid'=>$uuid,'name'=>$username,'api_key'=>$apiKey];
}
