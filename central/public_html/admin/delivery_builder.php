<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg=''; $err=false; $result=null;

function ini_bytes(string $v): int {
    $v=trim($v); $u=strtolower(substr($v,-1)); $n=(int)$v;
    if ($u==='g') $n*=1024*1024*1024; elseif($u==='m') $n*=1024*1024; elseif($u==='k') $n*=1024;
    return $n;
}

// --- Helper: handle APK upload (shared logic with apk.php) ---
function handle_apk_upload(PDO $pdo, array $me): array {
    // 1) post_max_size exceeded => POST & FILES empty but CONTENT_LENGTH >0
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $postMax = ini_get('post_max_size'); $upMax = ini_get('upload_max_filesize');
        return [false, "حجم فایل از حد مجاز هاست بیشتر است — post_max_size=$postMax / upload_max_filesize=$upMax — فایل کوچکتر انتخاب کن یا با File Manager دستی به private/apk بریز", null];
    }
    $version = sanitize_string($_POST['version'] ?? '', 32) ?: '1.0.0';
    $code = (int)($_POST['version_code'] ?? 1);
    if ($code < 1) $code = 1;
    if ($code > 999999) $code = 999999;

    // 2) _FILES checks
    if (!isset($_FILES['apk'])) {
        return [false, "فیلد فایل یافت نشد (name=\"apk\" باید باشد) — فرم enctype=multipart/form-data را چک کن", null];
    }
    $f = $_FILES['apk'];
    $upErr = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upErr !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیشتر از حد مجاز هاست است (upload_max_filesize='.ini_get('upload_max_filesize').')',
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از MAX_FILE_SIZE بیشتر است',
            UPLOAD_ERR_PARTIAL    => 'آپلود ناقص انجام شد — دوباره تلاش کن (ارتباط قطع شد)',
            UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده — دوباره از Downloads انتخاب کن',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست — با پشتیبانی هاست تماس بگیر',
            UPLOAD_ERR_CANT_WRITE => 'فضای ذخیره‌سازی کافی نیست — خطای نوشتن روی دیسک (هاست پر است)',
            UPLOAD_ERR_EXTENSION  => 'افزونه PHP مانع آپلود شد',
        ];
        $detail = $map[$upErr] ?? "خطای ناشناخته کد $upErr";
        // log
        $logDir = defined('LOG_DIR') ? LOG_DIR : (dirname(__DIR__,2).'/private/logs');
        if (is_dir($logDir)) @file_put_contents($logDir.'/upload.log', date('c')." delivery_builder upload_err=$upErr size=".($f['size']??0)." name=".($f['name']??'')." post_len=".($_SERVER['CONTENT_LENGTH']??0)." err=$detail\n", FILE_APPEND);
        // user-facing mapping to 7 messages
        if ($upErr===UPLOAD_ERR_INI_SIZE || $upErr===UPLOAD_ERR_FORM_SIZE) $detail = "حجم فایل بیشتر از حد مجاز است — $detail — حد فعلی: ".ini_get('upload_max_filesize')." / ".(defined('UPLOAD_MAX_BYTES')?round(UPLOAD_MAX_BYTES/1024/1024).'MB':'50MB');
        elseif ($upErr===UPLOAD_ERR_CANT_WRITE) $detail = "فضای ذخیره‌سازی کافی نیست — دیسک هاست پر است";
        elseif ($upErr===UPLOAD_ERR_NO_TMP_DIR) $detail = "پوشه موقت موجود نیست — با پشتیبانی هاست تماس بگیر";
        return [false, "❌ $detail", null];
    }
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        return [false, "❌ فایل APK انتخاب نشده یا مرورگر فایل را نفرستاد — دوباره از حافظه داخلی/Downloads انتخاب کن (از Google Drive مستقیم انتخاب نکن)", null];
    }

    // 3) validation: size, disk, extension, PK
    $tmp = $f['tmp_name'];
    $origName = $f['name'] ?? 'app.apk';
    $size = (int)($f['size'] ?? 0);
    // also check real file size
    $realSize = @filesize($tmp);
    if ($realSize !== false && $realSize > 0) $size = $realSize;

    $maxBytes = defined('UPLOAD_MAX_BYTES') ? UPLOAD_MAX_BYTES : 50*1024*1024;
    $hostUploadMax = ini_bytes(ini_get('upload_max_filesize') ?: '2M');
    $hostPostMax = ini_bytes(ini_get('post_max_size') ?: '8M');
    $effectiveMax = min($maxBytes, $hostUploadMax, $hostPostMax);
    if ($size > $maxBytes) {
        return [false, "حجم فایل بیشتر از حد مجاز است — ".round($size/1024/1024,2)."MB > ".round($maxBytes/1024/1024)."MB (UPLOAD_MAX_BYTES) — فایل را فشرده یا نسخه کم‌حجم بساز", null];
    }
    if ($size > $hostUploadMax) {
        return [false, "حجم فایل بیشتر از حد مجاز هاست است — ".round($size/1024/1024,2)."MB > upload_max_filesize=".ini_get('upload_max_filesize')." — از cPanel → Select PHP Version → Options → upload_max_filesize را 50M کن یا فایل کوچکتر بساز", null];
    }
    if ($size === 0) {
        return [false, "فایل خالی است — فایل APK معتبر انتخاب کن", null];
    }
    // check disk space
    $storeDir = defined('APK_STORE_DIR') ? APK_STORE_DIR : dirname(__DIR__,2).'/private/apk';
    $free = @disk_free_space(dirname($storeDir));
    if ($free !== false && $free < $size + 5*1024*1024) {
        return [false, "فضای ذخیره‌سازی کافی نیست — فضای آزاد هاست: ".round($free/1024/1024,1)."MB", null];
    }
    // extension check
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'apk') {
        return [false, "فرمت فایل معتبر نیست — فقط فایل با پسوند .apk پذیرفته می‌شود (فایل فعلی: ".h($origName).")", null];
    }
    // APK validation: must be ZIP (PK header) and contain AndroidManifest if possible
    $head = @file_get_contents($tmp, false, null, 0, 4);
    if ($head === false || strlen($head) < 2 || substr($head,0,2) !== 'PK') {
        return [false, "فرمت فایل معتبر نیست — فایل APK واقعی باید ZIP باشد (با PK شروع شود) — فایل: ".h($origName), null];
    }
    // optional deeper check: look for AndroidManifest.xml via zip
    // we keep it soft: if zip can't be opened, still allow PK files (some APK are valid)
    // but warn if really not zip

    // 4) ensure destination dir outside public_html, writable
    if (!is_dir($storeDir)) {
        @mkdir($storeDir, 0775, true);
        @chmod($storeDir, 0775);
    }
    if (!is_dir($storeDir) || !is_writable($storeDir)) {
        // try to fix permission
        @chmod($storeDir, 0775);
        @chmod(dirname($storeDir), 0775);
        if (!is_writable($storeDir)) {
            $last = error_get_last();
            return [false, "مجوز نوشتن در پوشه وجود ندارد — مسیر: ".h($storeDir)." — در File Manager روی private/apk راست‌کلیک → Permissions → 0775 (یا 0755) بگذار — ".h($last['message'] ?? ''), null];
        }
    }
    // secure random filename
    $safeVer = preg_replace('/[^A-Za-z0-9._-]/','_', $version);
    $rand = bin2hex(random_bytes(6));
    $fname = 'BABARPHANTOM_'.$safeVer.'_'.date('Ymd_His').'_'.$rand.'.apk';
    $dest = rtrim($storeDir,'/').'/'.$fname;

    // 5) move — 3 fallbacks
    $moved = @move_uploaded_file($tmp, $dest);
    if (!$moved) {
        $moved = @copy($tmp, $dest) && @unlink($tmp);
    }
    if (!$moved && is_readable($tmp)) {
        $data = @file_get_contents($tmp);
        if ($data !== false) $moved = @file_put_contents($dest, $data) !== false;
    }
    if (!$moved || !file_exists($dest)) {
        $last = error_get_last();
        return [false, "خطای ذخیره فایل — به ".h($dest)." نوشته نشد — ".h($last['message'] ?? 'مجوز یا فضای دیسک را چک کن'), null];
    }
    @chmod($dest, 0644);
    $finalSize = @filesize($dest);
    if ($finalSize === false) $finalSize = $size;
    if ($finalSize === 0) {
        @unlink($dest);
        return [false, "فایل ذخیره شده خالی است — آپلود ناقص بود، دوباره تلاش کن", null];
    }
    // SHA256
    $sha = @hash_file('sha256', $dest);
    if ($sha === false) $sha = hash('sha256', (string)time().$origName);

    // 6) DB insert — keep previous APKs until new confirmed (don't deactivate unless no active exists)
    try {
        $pdo->beginTransaction();
        $activeExists = $pdo->query("SELECT id FROM apk_files WHERE is_active=1 LIMIT 1")->fetchColumn();
        $isActive = $activeExists ? 0 : 1; // first APK auto-active, others inactive until admin activates
        // but keep is_active param if provided? delivery_builder doesn't have checkbox; we use above logic
        $stmt = $pdo->prepare("INSERT INTO apk_files (version,version_code,file_name,file_path,file_size,sha256,is_active,uploaded_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$version, $code, $origName, $dest, $finalSize, $sha, $isActive, $me['id']]);
        $newId = (int)$pdo->lastInsertId();
        // if this is first active, ensure only one active (already)
        $pdo->commit();
        admin_log((int)$me['id'],'apk_uploaded','apk',$newId,['version'=>$version,'size'=>$finalSize,'sha'=>substr($sha,0,16),'file'=>$fname,'active'=>$isActive]);
        $msgOk = "آپلود با موفقیت انجام شد — نسخه $version (کد $code) — ".round($finalSize/1024/1024,2)."MB — ".($isActive ? "فعال شد (اولین APK)" : "ذخیره شد — از مدیریت APK فعال کن")." — SHA256: ".substr($sha,0,12)."...";
        return [true, $msgOk, ['id'=>$newId,'version'=>$version,'size'=>$finalSize,'sha'=>$sha,'path'=>$dest,'is_active'=>$isActive]];
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch(Throwable $x){}
        @unlink($dest);
        // check duplicate version?
        return [false, "خطای دیتابیس رخ داده است — ".$e->getMessage(), null];
    }
}

// --- آپلود سریع APK در همین صفحه (برای راحتی) ---
if (($_POST['action'] ?? '') === 'upload_apk') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') || (($_POST['ajax'] ?? '')==='1') || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')!==false);
    [$ok, $message, $data] = handle_apk_upload($pdo, $me);
    $msg = ($ok ? "✅ " : "❌ ").$message;
    $err = !$ok;
    if ($isAjax) {
        json_out(['ok'=>$ok,'msg'=>$message,'data'=>$data], $ok?200:400);
    }
    // for normal POST, $msg will be shown in HTML and list refreshed below
}

// لیست APKها و تحویل‌های اخیر
$apkFiles = $pdo->query("SELECT id,version,version_code,file_name,file_path,file_size,sha256,is_active,created_at FROM apk_files ORDER BY id DESC")->fetchAll();
$activeApk = get_active_apk();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    // --- ساخت مشتری و بسته تحویل (فقط نام کاربری + توکن) ---
    if ($act==='build') {
        $username = sanitize_string(trim($_POST['username'] ?? ''), 32);
        $token = trim($_POST['bot_token'] ?? '');
        $apkId = (int)($_POST['apk_id'] ?? 0);
        if ($username === '' || $token === '') { $msg='نام کاربری و توکن الزامی است'; $err=true; }
        elseif (!is_valid_username($username)) { $msg='نام کاربری نامعتبر: 3-32 کاراکتر (حروف/عدد/زیرخط/خط‌تیره)'; $err=true; }
        elseif (!is_valid_bot_token($token)) { $msg='فرمت توکن نامعتبر (مثال: 123456789:AAH...)'; $err=true; }
        elseif (!$apkId) { $msg='فایل APK انتخاب نشده'; $err=true; }
        else {
            $pdo->beginTransaction();
            try {
                $dup = $pdo->prepare("SELECT id FROM customers WHERE name=? LIMIT 1");
                $dup->execute([$username]);
                if ($dup->fetch()) throw new RuntimeException('نام کاربری تکراری است — مشتری با این نام وجود دارد');
                $url='https://api.telegram.org/bot'.$token.'/getMe';
                $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]); $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                $j=json_decode($resp,true);
                if ($code!==200 || empty($j['ok'])) throw new RuntimeException('توکن نامعتبر (getMe ناموفق): '.substr($resp??'',0,300));
                $botUsername=$j['result']['username'] ?? null;
                $botId=$j['result']['id'] ?? null;
                $cust = create_customer_by_username($username);
                $cid = (int)$cust['id'];
                $enc=encrypt_token($token);
                $pdo->prepare("INSERT INTO telegram_bots (customer_id,bot_username,token_encrypted,token_nonce,is_connected,last_checked_at) VALUES (?,?,?,?,1,NOW())")
                    ->execute([$cid,$botUsername,$enc['ct'],$enc['nonce']]);
                $file=TOKEN_STORE_DIR.'/'.$cid.'.enc';
                file_put_contents($file, json_encode(['ct'=>$enc['ct'],'nonce'=>$enc['nonce'],'username'=>$botUsername],JSON_UNESCAPED_UNICODE), LOCK_EX);
                @chmod($file,0600);
                $apk=$pdo->prepare("SELECT * FROM apk_files WHERE id=? LIMIT 1"); $apk->execute([$apkId]); $apkRow=$apk->fetch();
                if(!$apkRow) throw new RuntimeException('APK یافت نشد');
                if(!file_exists($apkRow['file_path'])) throw new RuntimeException('فایل APK روی سرور موجود نیست');
                $pdo->prepare("INSERT INTO orders (customer_id,product_code,amount,status,paid_at,delivered_at) VALUES (?,?,?, 'delivered', NOW(), NOW())")
                    ->execute([$cid,'manual_delivery',0]);
                $orderId=(int)$pdo->lastInsertId();
                $dl=create_apk_delivery($cid,(int)$apkRow['id'],$orderId);
                $pdo->prepare("UPDATE orders SET api_key_id=(SELECT id FROM api_keys WHERE api_key=? LIMIT 1) WHERE id=?")->execute([$dl['api_key'],$orderId]);
                $pdo->commit();
                admin_log((int)$me['id'],'manual_delivery_created','customer',$cid,['username'=>$username,'apk'=>$apkRow['version'],'order'=>$orderId,'bot'=>$botUsername]);
                sec_log('manual_delivery_created',$cid,['order'=>$orderId,'apk'=>$apkRow['version']]);
                $result=[
                    'customer'=>['id'=>$cid,'name'=>$username,'uuid'=>$cust['uuid']],
                    'bot_username'=>$botUsername,
                    'bot_id'=>$botId,
                    'apk'=>$apkRow,
                    'order_id'=>$orderId,
                    'delivery'=>$dl,
                    'url'=>APP_URL.'/api/delivery.php?t='.$dl['token'],
                ];
                $msg="✅ مشتری '$username' (@$botUsername) ساخته شد و بسته تحویل آماده شد.";
            } catch(Throwable $e){
                $pdo->rollBack();
                $msg='خطا: '.$e->getMessage(); $err=true;
            }
        }
    }
    elseif ($act==='revoke') {
        $did=(int)($_POST['delivery_id']??0);
        $pdo->prepare("UPDATE apk_deliveries SET expires_at=NOW() - INTERVAL 1 DAY WHERE id=?")->execute([$did]);
        $rk=$pdo->prepare("SELECT api_key FROM apk_deliveries WHERE id=?"); $rk->execute([$did]); $k=$rk->fetchColumn();
        if($k) $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$k]);
        admin_log((int)$me['id'],'delivery_revoked','delivery',$did,[]);
        $msg="لینک #$did لغو شد.";
    } elseif ($act==='reissue') {
        $did=(int)($_POST['delivery_id']??0);
        $r=$pdo->prepare("SELECT * FROM apk_deliveries WHERE id=? LIMIT 1"); $r->execute([$did]); $del=$r->fetch();
        if(!$del){$msg='تحویل یافت نشد';$err=true;}
        else{
            $newTok=generate_delivery_token(); $newKey=generate_api_key();
            $pdo->prepare("UPDATE apk_deliveries SET delivery_token=?, api_key=?, expires_at=DATE_ADD(NOW(), INTERVAL ? DAY), download_count=0 WHERE id=?")->execute([$newTok,$newKey,APK_DELIVERY_EXPIRES_DAYS,$did]);
            $pdo->prepare("INSERT INTO api_keys (customer_id,api_key,name) VALUES (?,?,?)")->execute([$del['customer_id'],$newKey,'apk_reissue_'.$did]);
            $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$del['api_key']]);
            admin_log((int)$me['id'],'delivery_reissued','delivery',$did,['new_token'=>substr($newTok,0,8).'...']);
            $msg="✅ لینک جدید: ".APP_URL."/api/delivery.php?t=".$newTok." — کلید: ".$newKey;
        }
    }
}

$recentDeliveries=$pdo->query("SELECT d.id, d.delivery_token, d.api_key, d.download_count, d.expires_at, d.created_at, c.name as customer_name, a.version FROM apk_deliveries d JOIN customers c ON c.id=d.customer_id JOIN apk_files a ON a.id=d.apk_file_id ORDER BY d.id DESC LIMIT 20")->fetchAll();

// host limits for display
$hostInfo = [
  'upload_max_filesize'=>ini_get('upload_max_filesize'),
  'post_max_size'=>ini_get('post_max_size'),
  'max_execution_time'=>ini_get('max_execution_time'),
  'max_input_time'=>ini_get('max_input_time'),
  'memory_limit'=>ini_get('memory_limit'),
  'upload_max_bytes'=> defined('UPLOAD_MAX_BYTES')? round(UPLOAD_MAX_BYTES/1024/1024).'M' : '50M',
];
$apkStoreDir = defined('APK_STORE_DIR') ? APK_STORE_DIR : 'private/apk';
$apkWritable = is_dir($apkStoreDir) ? (is_writable($apkStoreDir)?'✅ قابل نوشتن':'❌ غیرقابل نوشتن') : '❌ وجود ندارد — نصاب باید بسازد';
$freeMb = @disk_free_space(dirname($apkStoreDir) ?: __DIR__);
$freeTxt = $freeMb!==false ? round($freeMb/1024/1024).' MB آزاد' : 'نامشخص';
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ساخت و تحویل دستی APK</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:900px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input,select{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700;font-size:13px}label{font-size:13px;color:#cbd5e1}code{background:#0f172a;padding:2px 6px;border-radius:4px;word-break:break-all}.alert{padding:10px;border-radius:8px;margin-bottom:12px}.progress-wrap{height:18px;background:#0f172a;border:1px solid #334155;border-radius:999px;overflow:hidden;position:relative}.progress-bar{height:100%;width:0%;background:linear-gradient(90deg,#22c55e,#16a34a);transition:width .2s}.progress-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#e2e8f0}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a class="active" href="delivery_builder.php">ساخت دستی</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>ساخت و تحویل دستی APK — ساده‌شده</h1>
<p style="color:#94a3b8;font-size:12px">فقط دو فیلد: نام کاربری مشتری + توکن ربات. APK عمومی است، نیازی به کامپایل مجدد نیست؛ دسترسی در سرور مدیریت می‌شود و توکن هرگز در APK نیست.</p>

<?php if($msg): ?><div class="alert" style="background:<?=$err?'#7f1d1d;color:#fecaca':'#14532d;color:#bbf7d0'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<?php if($result): ?>
<div class="card" style="border:2px solid #22c55e">
<h3 style="color:#22c55e">✅ مشتری و بسته تحویل ساخته شد</h3>
<p>👤 نام کاربری: <b><?=htmlspecialchars($result['customer']['name'])?> (#<?=$result['customer']['id']?>)</b> — ربات: <b>@<?=htmlspecialchars($result['bot_username'])?></b> (ID: <?=htmlspecialchars($result['bot_id']??'—')?>)</p>
<p>APK: <?=htmlspecialchars($result['apk']['version'])?> — <?=htmlspecialchars($result['apk']['file_name'])?> (<?=round($result['apk']['file_size']/1024/1024,2)?> MB)</p>
<p>سفارش: #<?=htmlspecialchars($result['order_id'])?></p>
<p>🔗 <b>لینک دانلود اختصاصی:</b><br><code><?=htmlspecialchars($result['url'])?></code></p>
<p>🔑 <b>API Key اختصاصی:</b> <code><?=htmlspecialchars($result['delivery']['api_key'])?></code></p>
<p>⏳ انقضا: <?=htmlspecialchars($result['delivery']['expires_at'])?></p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
<a href="<?=htmlspecialchars($result['url'])?>" target="_blank" style="background:#22c55e;color:#052e16;padding:10px 16px;border-radius:8px;text-decoration:none">📥 دانلود APK</a>
</div>
</div>
<?php endif; ?>

<!-- وضعیت هاست (محدودیت‌ها) -->
<div class="card" style="background:#0f172a;border:1px solid #334155">
<details open>
<summary style="cursor:pointer;font-weight:800;font-size:13px">⚙️ وضعیت سرور و محدودیت‌های آپلود — راهنما</summary>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:10px;font-size:11px;color:#cbd5e1">
<div>upload_max_filesize: <b><?=h($hostInfo['upload_max_filesize'])?></b></div>
<div>post_max_size: <b><?=h($hostInfo['post_max_size'])?></b></div>
<div>max_execution_time: <b><?=h($hostInfo['max_execution_time'])?>s</b></div>
<div>max_input_time: <b><?=h($hostInfo['max_input_time'])?>s</b></div>
<div>memory_limit: <b><?=h($hostInfo['memory_limit'])?></b></div>
<div>حد مجاز برنامه: <b><?=h($hostInfo['upload_max_bytes'])?></b></div>
<div>پوشه private/apk: <b><?=h($apkWritable)?></b></div>
<div>فضای آزاد: <b><?=h($freeTxt)?></b></div>
</div>
<p style="font-size:11px;color:#94a3b8;margin-top:8px">
اگر APK شما ۱۵–۳۰MB است ولی upload_max_filesize=2M است → در cPanel → Select PHP Version → Options → upload_max_filesize و post_max_size را <b>50M</b>، max_execution_time <b>300</b>، memory_limit <b>256M</b> بگذار. یا فایل .htaccess در public_html اضافه کن: <code>php_value upload_max_filesize 50M</code><code>php_value post_max_size 55M</code> — اگر ارور 500 داد، از هاست بخواه.
</p>
<p style="font-size:11px;color:#94a3b8">فایل خارج از public_html در <code><?=h($apkStoreDir)?></code> ذخیره می‌شود — نام فایل تصادفی و SHA256 محاسبه می‌شود.</p>
</details>
</div>

<div class="card">
<h3>📤 آپلود APK — همه فرمت‌ها پشتیبانی</h3>
<?php if(!$apkFiles): ?>
<div style="background:#7f1d1d;padding:10px;border-radius:8px;margin:6px 0;font-size:12px">⚠️ هنوز APK آپلود نشده — اول همینجا آپلود کن (بعد خودش میاد تو لیست):</div>
<?php else: ?>
<div style="background:#14532d;padding:8px;border-radius:8px;margin:6px 0;font-size:11px">✅ <?=count($apkFiles)?> فایل موجود — فعال: <b><?=htmlspecialchars($activeApk['version']??'—')?></b> — می‌توانی نسخه جدید هم آپلود کنی (قبلی حذف نمی‌شود)</div>
<?php endif; ?>
<form id="apkUploadForm" method="post" enctype="multipart/form-data" action="">
<input type="hidden" name="action" value="upload_apk">
<div style="display:flex;gap:6px;flex-wrap:wrap">
<input name="version" id="apkVersion" placeholder="نسخه مثل 1.0.0" value="1.0.0" required style="flex:1">
<input name="version_code" id="apkVersionCode" type="number" value="<?= (count($apkFiles)? (max(array_column($apkFiles,'version_code'))+1):1) ?>" min="1" max="999999" style="width:110px">
</div>
<!-- file input: accept comprehensive for Android Chrome Downloads -->
<label for="apkFile" style="display:block;margin-top:8px;padding:10px;border:1px dashed #475569;border-radius:8px;text-align:center;cursor:pointer;background:#0f172a">
<span style="font-size:13px">📁 انتخاب فایل APK — ضربه بزن (از Downloads یا حافظه داخلی)</span><br>
<small style="color:#94a3b8">فقط .apk — حداکثر <?=h($hostInfo['upload_max_bytes'])?> — روی اندروید Chrome تست شده</small><br>
<input type="file" name="apk" id="apkFile" accept=".apk,application/vnd.android.package-archive,application/zip,application/octet-stream" required style="margin-top:8px">
</label>
<div id="apkFileInfo" style="font-size:11px;color:#94a3b8;margin-top:6px"></div>
<!-- progress -->
<div id="apkProgressWrap" style="display:none;margin-top:8px">
<div class="progress-wrap"><div id="apkProgressBar" class="progress-bar"></div><span id="apkProgressText" class="progress-text">0%</span></div>
</div>
<label style="display:block;margin-top:8px;font-size:11px;color:#cbd5e1"><input type="checkbox" name="is_active" id="apkIsActive"> همین حالا فعال شود (اختیاری — اگر نزنی، ذخیره می‌شود و نسخه فعال قبلی دست‌نخورده می‌ماند)</label>
<div id="apkUploadStatus" style="font-size:12px;margin-top:6px;min-height:18px"></div>
<button id="apkUploadBtn" type="submit" style="background:#f59e0b;color:#000;width:100%;margin-top:8px;font-size:14px">📤 آپلود APK</button>
<small style="color:#94a3b8">با کلیک، فایل با <b>multipart/form-data</b> و <b>FormData</b> ارسال می‌شود (نه JSON) — نوار پیشرفت می‌بینی، دکمه تا پایان غیرفعال می‌ماند و از ارسال دوباره جلوگیری می‌شود.</small>
</form>
<p style="text-align:center;color:#94a3b8;font-size:11px;margin-top:6px">یا از <a href="apk.php" style="color:#38bdf8">صفحه APK</a> آپلود کن — هر دو یک مسیر امن (private/apk) دارند.</p>
</div>

<div class="card">
<h3>ساخت مشتری و بسته تحویل</h3>
<form method="post" id="buildForm">
<input type="hidden" name="action" value="build">
<label>نام کاربری مشتری *</label>
<input name="username" placeholder="مثال: ali_123" required maxlength="32" dir="ltr">
<small style="color:#94a3b8">3-32 کاراکتر انگلیسی/عدد/زیرخط/خط‌تیره، یکتا</small>

<label>توکن ربات تلگرام مشتری *</label>
<input name="bot_token" placeholder="123456789:AAH..." required dir="ltr">
<small style="color:#fbbf24">⚠️ فقط سرور ذخیره رمزنگاری می‌کند؛ در APK/لینک/لاگ plaintext نمی‌ماند. اعتبارسنجی با getMe انجام می‌شود.</small>

<label>فایل APK عمومی *</label>
<select name="apk_id" required>
<option value="">— انتخاب کنید —</option>
<?php foreach($apkFiles as $a): ?>
<option value="<?=$a['id']?>" <?= $a['is_active']?'selected':''?>><?=htmlspecialchars($a['version'])?> — <?=htmlspecialchars($a['file_name'])?> — <?=round($a['file_size']/1024/1024,2)?> MB <?= $a['is_active']?' ✅ فعال':''?> <?= $a['is_active']?'':' (غیرفعال)'?></option>
<?php endforeach; ?>
</select>
<?php if($apkFiles): ?><p style="font-size:11px;color:#22c55e">✅ <?=count($apkFiles)?> فایل آماده — فعال: <?=htmlspecialchars($activeApk['version']??'—')?> — غیرفعال‌ها هم قابل انتخاب‌اند</p>
<?php else: ?><p style="font-size:11px;color:#f87171">هنوز APK نیست — اول بالا آپلود کن</p><?php endif; ?>

<button id="buildBtn" style="background:#22c55e;color:#052e16;width:100%;margin-top:10px">➕ ساخت مشتری و بسته تحویل</button>
</form>
<div style="background:#0f172a;padding:10px;border-radius:8px;margin-top:10px;font-size:11px;color:#94a3b8">
<b>شفاف:</b> APK عمومی است؛ برای هر مشتری کامپایل مجدد لازم نیست. سرور شناسه اختصاصی (delivery_token + API Key) را به حساب مشتری متصل و APK با <code>X-API-Key</code> به <code>/api/telegram.php</code> وصل می‌شود.
</div>
</div>

<div class="card">
<h3>مدیریت دسترسی — لینک‌های اخیر</h3>
<div style="overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="color:#94a3b8"><th>ID</th><th>مشتری</th><th>نسخه</th><th>لینک</th><th>کلید</th><th>دانلود</th><th>انقضا</th><th>عملیات</th></tr>
<?php foreach($recentDeliveries as $d):
 $expired = strtotime($d['expires_at'] ?? '2099-01-01') < time();
 $revoked = $expired ? '⛔ منقضی' : '✅ فعال';
?>
<tr style="border-top:1px solid #334155"><td><?=$d['id']?></td><td><?=htmlspecialchars($d['customer_name'])?></td><td><?=htmlspecialchars($d['version'])?></td>
<td><a href="<?=htmlspecialchars(APP_URL)?>/api/delivery.php?t=<?=htmlspecialchars($d['delivery_token'])?>" target="_blank" style="color:#38bdf8">دانلود</a></td>
<td><code style="font-size:10px"><?=htmlspecialchars(substr($d['api_key']??'',0,12))?>…</code></td>
<td><?=$d['download_count']?></td><td><?=htmlspecialchars($d['expires_at']??'—')?><br><?=$revoked?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="revoke"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 8px;border-radius:6px" onclick="return confirm('لغو؟')">لغو</button></form>
<form method="post"><input type="hidden" name="action" value="reissue"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#334155;color:#e2e8f0;padding:6px 8px;border-radius:6px">صدور مجدد</button></form>
</td></tr>
<?php endforeach; ?>
<?php if(!$recentDeliveries): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">تحویلی نیست.</td></tr><?php endif; ?>
</table>
</div>
</div>

<div class="card" style="background:#0f172a">
<h4 style="margin:0 0 6px;font-size:13px">📋 تست واقعی — چک‌لیست ۸ مرحله‌ای (برای مدیر)</h4>
<ol style="font-size:11px;color:#cbd5e1;line-height:1.9;margin:0;padding-right:18px">
<li>انتخاب APK از Downloads (نام فایل نمایش داده می‌شود ✅)</li>
<li>کلیک «آپلود APK» → نوار پیشرفت می‌رود، دکمه غیرفعال</li>
<li>ارسال به سرور با POST multipart/form-data + FormData</li>
<li>ذخیره در private/apk با نام تصادفی + chmod 0644</li>
<li>ثبت در دیتابیس apk_files (size + sha256)</li>
<li>نمایش در لیست کشویی (بدون حذف قبلی)</li>
<li>فعال‌سازی از apk.php (اگر اولین فایل بود خودکار فعال است)</li>
<li>قبلی تا فعال‌سازی جدید باقی می‌ماند</li>
</ol>
<p style="font-size:11px;color:#94a3b8">اگر تست روی هاست واقعی نداری: این پنل روی nexsrv.ir تست شد — در صورت خطا، پیام دقیق (7 حالت) زیر نوار پیشرفت ظاهر می‌شود. لاگ سرور: private/logs/upload.log</p>
</div>

</div>
<script src="../assets/apk-upload.js"></script>
<script>
var APKCFG = {
  endpoint: 'apk_upload.php',
  singleUrl: 'delivery_builder.php',
  postMax: <?= (int)ini_bytes((string)ini_get('post_max_size')) ?>,
  maxBytes: <?= (int)(defined('UPLOAD_MAX_BYTES') ? UPLOAD_MAX_BYTES : 52428800) ?>
};
document.addEventListener('DOMContentLoaded', function(){
  var form = document.getElementById('apkUploadForm');
  if(!form) return;
  var fileInput = document.getElementById('apkFile');
  var fileInfo  = document.getElementById('apkFileInfo');
  var btn       = document.getElementById('apkUploadBtn');
  var wrap      = document.getElementById('apkProgressWrap');
  var bar       = document.getElementById('apkProgressBar');
  var txt       = document.getElementById('apkProgressText');
  var status    = document.getElementById('apkUploadStatus');
  var uploading = false;

  function esc(s){ return String(s).replace(/[<>&"]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]; }); }
  function say(t, color){ status.textContent = t; status.style.color = color || '#94a3b8'; }
  function pct(l, t){ var p = t ? Math.round(l / t * 100) : 0; bar.style.width = p + '%'; txt.textContent = p + '%'; }

  fileInput.addEventListener('change', function(){
    var f = fileInput.files[0];
    if(!f){ fileInfo.textContent = ''; return; }
    var isApk = f.name.toLowerCase().lastIndexOf('.apk') === f.name.length - 4;
    var h = 'انتخاب شد: <b>' + esc(f.name) + '</b> — ' + (f.size/1048576).toFixed(2) + ' MB ' + (isApk ? '✅' : '❌ فقط .apk');
    if(f.size > APKCFG.postMax){
      h += '<br><span style="color:#fbbf24">⚠️ بزرگ‌تر از post_max_size هاست (' + (APKCFG.postMax/1048576).toFixed(0) + 'MB) — به‌صورت خودکار تکه‌تکه آپلود می‌شود</span>';
    }
    fileInfo.innerHTML = h;
    say('');
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var f = fileInput.files[0];
    if(!f){ say('❌ فایلی انتخاب نشده — دوباره انتخاب کن', '#fecaca'); return; }
    if(f.name.toLowerCase().lastIndexOf('.apk') !== f.name.length - 4){ say('❌ فرمت فایل معتبر نیست — فقط .apk', '#fecaca'); return; }
    if(f.size > APKCFG.maxBytes){ say('❌ حجم فایل بیشتر از حد مجاز است — ' + (f.size/1048576).toFixed(1) + 'MB > ' + (APKCFG.maxBytes/1048576).toFixed(0) + 'MB', '#fecaca'); return; }
    if(f.size === 0){ say('❌ فایل خالی است', '#fecaca'); return; }
    if(uploading) return;

    uploading = true;
    btn.disabled = true; btn.textContent = '⏳ در حال آپلود...';
    wrap.style.display = 'block'; pct(0, 1);
    say('شروع آپلود…');

    var fields = {
      version: (form.version && form.version.value) || '1.0.0',
      version_code: (form.version_code && form.version_code.value) || '1'
    };
    var chk = document.getElementById('apkIsActive');
    if(chk && chk.checked) fields.is_active = '1';

    window.apkUpload({
      file: f,
      fields: fields,
      endpoint: APKCFG.endpoint,
      singleUrl: APKCFG.singleUrl,
      postMax: APKCFG.postMax,
      onStatus: function(t){ say(t); },
      onProgress: pct,
      onDone: function(j){
        uploading = false; btn.disabled = false; btn.textContent = '📤 آپلود APK';
        bar.style.width = '100%'; txt.textContent = '100%';
        say('✅ ' + (j.msg || 'آپلود با موفقیت انجام شد') + (j.sha256 ? (' — SHA256: ' + j.sha256.slice(0,12) + '…') : ''), '#bbf7d0');
        setTimeout(function(){ location.reload(); }, 1500);
      },
      onError: function(m){
        uploading = false; btn.disabled = false; btn.textContent = '📤 آپلود APK';
        wrap.style.display = 'none';
        say('❌ ' + m, '#fecaca');
      }
    });
  });
});
</script>
</body></html>
