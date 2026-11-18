<?php
/**
 * BABARPHANTOM Central v2 — نقطه پایانی آپلود APK به‌صورت تکه‌تکه (chunked)
 *
 * چرا این فایل؟
 *  - بعضی هاست‌ها post_max_size = 2M دارند و فایل 20MB هرگز در یک درخواست جا نمی‌شود.
 *    (درخواست بزرگ یا 404/413 می‌گیرد یا $_FILES خالی برمی‌گردد.)
 *  - اینجا فایل در تکه‌های ~1MB با بدنه خام (raw body) فرستاده می‌شود؛ نه multipart،
 *    نه JSON — پس فایروال/مود‌سکیوریتی هاست هم گیر نمی‌دهد.
 *
 * اکشن‌ها (همه POST، پاسخ JSON):
 *   ?action=ping    → تست دسترسی و نمایش محدودیت‌ها
 *   ?action=init    → شروع آپلود (name,size,version,version_code,notes,is_active)
 *   ?action=chunk   → بدنه خام تکه (header/query: token,index)
 *   ?action=finish  → اعتبارسنجی + انتقال به private/apk + ثبت در DB
 *   ?action=abort   → پاک‌سازی فایل ناقص
 *
 * امنیت: فقط مدیر وارد‌شده، توکن تصادفی ۳۲ hex، فقط .apk با هدر PK، سقف حجم، SHA256،
 * ذخیره خارج از public_html، فایل ناقص هرگز به private/apk منتقل نمی‌شود،
 * APK فعال قبلی تا لحظه تأیید دست‌نخورده می‌ماند.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

function up_fail(string $msg, int $code = 200, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function up_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function up_ini(string $v): int {
    $v = trim($v);
    if ($v === '' ) return 0;
    $u = strtolower(substr($v, -1));
    $n = (int)$v;
    if ($u === 'g') $n *= 1024 * 1024 * 1024;
    elseif ($u === 'm') $n *= 1024 * 1024;
    elseif ($u === 'k') $n *= 1024;
    return $n;
}
function up_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $dir . '/' . $i;
        is_dir($p) ? up_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
function up_log(string $line): void {
    $dir = defined('LOG_DIR') ? LOG_DIR : (defined('PRIVATE_ROOT') ? PRIVATE_ROOT . '/logs' : __DIR__ . '/../../private/logs');
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/upload.log', date('c') . ' [apk_upload] ' . $line . "\n", FILE_APPEND);
    }
}

// ---------- احراز هویت (بدون redirect، فقط JSON) ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    up_fail('این آدرس فقط POST را قبول می‌کند', 405);
}
if (empty($_SESSION['customer_id'])) {
    up_fail('نشست منقضی شده — دوباره در پنل وارد شو', 401);
}
try {
    $pdo = db();
    $st = $pdo->prepare("SELECT id, email, name FROM customers WHERE id=? LIMIT 1");
    $st->execute([$_SESSION['customer_id']]);
    $me = $st->fetch();
    if (!$me) up_fail('کاربر پیدا نشد — دوباره وارد شو', 401);
    if (!is_admin((int)$me['id'])) up_fail('فقط مدیر کل اجازه آپلود دارد', 403);
} catch (Throwable $e) {
    up_fail('خطای دیتابیس در احراز هویت: ' . $e->getMessage(), 500);
}

$action    = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$postMax   = up_ini((string)ini_get('post_max_size'));
$uploadMax = up_ini((string)ini_get('upload_max_filesize'));
$appMax    = defined('UPLOAD_MAX_BYTES') ? (int)UPLOAD_MAX_BYTES : 50 * 1024 * 1024;
$storeDir  = defined('APK_STORE_DIR') ? APK_STORE_DIR : dirname(__DIR__, 2) . '/apk';
$privateR  = defined('PRIVATE_ROOT') ? PRIVATE_ROOT : dirname(__DIR__, 2);
$incoming  = $privateR . '/uploads/incoming';

if (!is_dir($incoming)) { @mkdir($incoming, 0775, true); @chmod($incoming, 0775); }

// ---------- ping ----------
if ($action === 'ping') {
    up_ok([
        'php'          => PHP_VERSION,
        'post_max'     => $postMax,
        'upload_max'   => $uploadMax,
        'app_max'      => $appMax,
        'store_dir'    => $storeDir,
        'store_ok'     => is_dir($storeDir) && is_writable($storeDir),
        'incoming_ok'  => is_dir($incoming) && is_writable($incoming),
        'chunk_size'   => max(256 * 1024, min(1024 * 1024, (int)($postMax / 2)))
    ]);
}

// ---------- init ----------
if ($action === 'init') {
    $name = sanitize_string((string)($_POST['name'] ?? 'app.apk'), 200);
    $size = (int)($_POST['size'] ?? 0);
    if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'apk') {
        up_fail('فرمت فایل معتبر نیست — فقط .apk (فایل: ' . $name . ')');
    }
    if ($size <= 0)               up_fail('حجم فایل نامعتبر است');
    if ($size > $appMax)          up_fail('حجم فایل بیشتر از حد مجاز است — ' . round($size / 1048576, 2) . 'MB > ' . round($appMax / 1048576) . 'MB');
    $free = @disk_free_space($privateR);
    if ($free !== false && $free < $size + 10 * 1048576) {
        up_fail('فضای ذخیره‌سازی کافی نیست — آزاد: ' . round($free / 1048576, 1) . 'MB');
    }
    if (!is_dir($storeDir)) { @mkdir($storeDir, 0775, true); @chmod($storeDir, 0775); }
    if (!is_dir($storeDir) || !is_writable($storeDir)) {
        up_fail('مجوز نوشتن در پوشه وجود ندارد — ' . $storeDir . ' را 0775 کن');
    }
    // پاک‌سازی آپلودهای نیمه‌کاره قدیمی
    foreach (scandir($incoming) ?: [] as $d) {
        if ($d === '.' || $d === '..') continue;
        $p = $incoming . '/' . $d;
        if (is_dir($p) && (time() - (int)@filemtime($p)) > 6 * 3600) up_rmdir($p);
    }
    $token = bin2hex(random_bytes(16));
    $dir = $incoming . '/' . $token;
    $chunkSize = max(256 * 1024, min(1024 * 1024, (int)($postMax / 2)));
    if (!@mkdir($dir, 0775, true)) up_fail('ساخت پوشه موقت ناموفق بود — مجوز private/uploads را 0775 کن');
    $meta = [
        'name'         => $name,
        'size'         => $size,
        'version'      => sanitize_string((string)($_POST['version'] ?? '1.0.0'), 32) ?: '1.0.0',
        'version_code' => max(1, (int)($_POST['version_code'] ?? 1)),
        'notes'        => sanitize_string((string)($_POST['notes'] ?? ''), 500),
        'is_active'    => (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0,
        'received'     => 0,
        'chunk_size'   => $chunkSize,
        'created'      => time(),
        'admin'        => (int)$me['id'],
    ];
    @file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    @file_put_contents($dir . '/part.apk', '');
    up_ok(['token' => $token, 'chunk_size' => $chunkSize]);
}

// ---------- اعتبارسنجی توکن برای chunk/finish/abort ----------
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) up_fail('توکن آپلود نامعتبر است');
$dir  = $incoming . '/' . $token;
$metaF = $dir . '/meta.json';
$partF = $dir . '/part.apk';
if (!is_file($metaF) || !is_file($partF)) up_fail('جلسه آپلود پیدا نشد — از ابتدا شروع کن');
$meta = json_decode((string)file_get_contents($metaF), true);
if (!is_array($meta)) up_fail('فایل توضیحات جلسه خراب است — از ابتدا شروع کن');

// ---------- chunk ----------
if ($action === 'chunk') {
    $index = (int)($_GET['index'] ?? $_POST['index'] ?? -1);
    $body  = file_get_contents('php://input');
    $clen  = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($body === false || $body === '') {
        if ($clen > 0) up_fail('بدنه درخواست خالی رسید — احتمالاً post_max_size هاست (' . ini_get('post_max_size') . ') کوچک‌تر از تکه است', 413);
        up_fail('تکه خالی است');
    }
    $len = strlen($body);
    $chunkSize = max(262144, (int)($meta['chunk_size'] ?? 262144));
    $expectedIndex = (int)floor(((int)$meta['received']) / $chunkSize);
    if ($index >= 0 && $index !== $expectedIndex) {
        up_fail('ترتیب تکه‌ها درست نیست (انتظار ' . $expectedIndex . ' بود، ' . $index . ' رسید)', 200, ['expected' => $expectedIndex]);
    }
    if ((int)$meta['received'] + $len > (int)$meta['size']) up_fail('حجم دریافتی از حجم اعلام‌شده بیشتر شد');
    $fh = @fopen($partF, 'ab');
    if (!$fh) up_fail('نوشتن در فایل موقت ناموفق بود — مجوز private/uploads را 0775 کن');
    $written = fwrite($fh, $body);
    fflush($fh);
    fclose($fh);
    if ($written === false || $written !== $len) up_fail('نوشتن کامل نشد (نوشته‌شده: ' . var_export($written, true) . ' از ' . $len . ')');
    $meta['received'] = (int)$meta['received'] + $len;
    @file_put_contents($metaF, json_encode($meta, JSON_UNESCAPED_UNICODE));
    up_ok(['received' => $meta['received'], 'size' => (int)$meta['size']]);
}

// ---------- abort ----------
if ($action === 'abort') {
    up_rmdir($dir);
    up_ok(['msg' => 'آپلود لغو و فایل موقت پاک شد']);
}

// ---------- finish ----------
if ($action === 'finish') {
    clearstatcache(true, $partF);
    $got = (int)@filesize($partF);
    if ($got !== (int)$meta['size']) {
        up_fail('فایل ناقص است — دریافت‌شده ' . $got . ' از ' . (int)$meta['size'] . ' بایت. دوباره تلاش کن');
    }
    $fh = @fopen($partF, 'rb');
    $head = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);
    if (strlen($head) < 2 || substr($head, 0, 2) !== 'PK') {
        up_rmdir($dir);
        up_fail('فرمت فایل معتبر نیست — APK باید ZIP باشد (هدر PK ندارد). فایل: ' . $meta['name']);
    }
    $sha = @hash_file('sha256', $partF);
    if ($sha === false) { up_rmdir($dir); up_fail('محاسبه SHA256 ناموفق بود — دوباره تلاش کن'); }

    $safeVer = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$meta['version']);
    $fname = 'BABARPHANTOM_' . $safeVer . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.apk';
    $dest  = rtrim($storeDir, '/') . '/' . $fname;
    $moved = @rename($partF, $dest);
    if (!$moved) { $moved = @copy($partF, $dest); if ($moved) @unlink($partF); }
    if (!$moved || !is_file($dest)) {
        $e = error_get_last();
        up_fail('انتقال فایل به ' . $storeDir . ' ناموفق بود — ' . ($e['message'] ?? 'مجوز را 0775 کن'));
    }
    @chmod($dest, 0644);
    clearstatcache(true, $dest);
    $fsize = (int)@filesize($dest);
    $wantActive = (int)$meta['is_active'] === 1;

    try {
        $pdo->beginTransaction();
        $activeExists = $pdo->query("SELECT id FROM apk_files WHERE is_active=1 LIMIT 1")->fetchColumn();
        if ($wantActive && $activeExists) {
            // فقط بعد از INSERT موفق و داخل تراکنش غیرفعال می‌شود؛ اگر INSERT شکست بخورد rollback
            $isActive = 1;
        } else {
            $isActive = $activeExists ? 0 : 1; // اولین APK خودکار فعال
        }
        $ins = $pdo->prepare("INSERT INTO apk_files (version,version_code,file_name,file_path,file_size,sha256,is_active,uploaded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([$meta['version'], (int)$meta['version_code'], $meta['name'], $dest, $fsize, $sha, $isActive, (int)$me['id'], $meta['notes'] ?? '']);
        $newId = (int)$pdo->lastInsertId();
        if ($isActive && $activeExists) {
            $pdo->prepare("UPDATE apk_files SET is_active=0 WHERE is_active=1 AND id<>?")->execute([$newId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $x) {}
        @unlink($dest);
        up_rmdir($dir);
        up_fail('خطای دیتابیس رخ داده است — ' . $e->getMessage());
    }
    up_rmdir($dir);
    admin_log((int)$me['id'], 'apk_uploaded', 'apk', $newId, [
        'version' => $meta['version'], 'size' => $fsize, 'sha' => substr((string)$sha, 0, 16),
        'file' => $fname, 'active' => $isActive, 'mode' => 'chunked'
    ]);
    up_log('ok id=' . $newId . ' size=' . $fsize . ' sha=' . substr((string)$sha, 0, 16) . ' file=' . $fname);
    up_ok([
        'msg'    => 'آپلود با موفقیت انجام شد — نسخه ' . $meta['version'] . ' — ' . round($fsize / 1048576, 2) . ' MB',
        'id'     => $newId,
        'version'=> $meta['version'],
        'size'   => $fsize,
        'sha256' => $sha,
        'is_active' => $isActive,
        'file'   => $fname
    ]);
}

up_fail('اکشن نامشخص: ' . $action, 400);
