<?php
declare(strict_types=1);
/**
 * BABARPHANTOM Central — نصاب تحت وب (یک‌کلیک)
 * فقط فایل را به هاست آپلود کن، به /install.php برو و فرم را پر کن — تمام
 * - دیتابیس را در مرورگر وارد می‌کنی
 * - خودش جدول‌ها را می‌سازد (schema + migration_v2 + migration_v3)
 * - خودش config.php را می‌سازد + کلیدها را می‌سازد
 * - بعد خودش را پاک کن
 */
session_start();
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if (isset($_POST['step'])) $step = (int)$_POST['step'];
$self = basename(__FILE__);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function find_private(){
    $candidates = [
        __DIR__ . '/../private',
        __DIR__ . '/../../private',
        dirname(__DIR__) . '/private',
        dirname(__DIR__,2) . '/private',
        __DIR__ . '/private',
    ];
    foreach($candidates as $p){
        if(is_dir($p)) return realpath($p);
    }
    // fallback: try to locate central/private when running from repo
    $p = __DIR__ . '/../private';
    return $p;
}
$privateRoot = find_private();
$configPath = $privateRoot . '/config/config.php';
$configSample = $privateRoot . '/config/config.sample.php';
$dbDir = $privateRoot . '/database';
// auto-detect APP_URL
function detect_app_url(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'https'; // force https for production
    $host = $_SERVER['HTTP_HOST'] ?? 'your-host.example.com';
    // remove install.php from request URI
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    // public_html is docroot, so base is /
    // if installed in subdir, keep it
    $path = rtrim($path, '/');
    if ($path === '' || $path === '\\' || $path === '/') $path = '';
    return $scheme . '://' . $host . $path;
}
$detectedUrl = detect_app_url();
$alreadyInstalled = false;
if (file_exists($configPath)) {
    $c = @file_get_contents($configPath);
    if ($c && strpos($c, 'REPLACE_WITH') === false && strpos($c, 'babar_central') === false && strpos($c, 'change-me') === false) {
        // check if DB reachable? try to include? just flag as installed
        // but allow force reinstall via ?force=1
        if (empty($_GET['force'])) $alreadyInstalled = true;
    }
}
// helpers
function check_requirements(): array {
    $r = [];
    $r[] = ['PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION];
    $r[] = ['PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'ok' : 'missing'];
    $r[] = ['OpenSSL', extension_loaded('openssl'), extension_loaded('openssl') ? 'ok' : 'missing'];
    $r[] = ['mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ok' : 'missing'];
    $r[] = ['JSON', extension_loaded('json'), extension_loaded('json') ? 'ok' : 'missing'];
    // writable checks
    $private = find_private();
    $writable = [
        $private . '/config',
        $private . '/apk',
        $private . '/uploads',
        $private . '/logs',
        $private . '/telegram_tokens',
        $private . '/templates',
        dirname(__DIR__) . '/public_html/templates',
    ];
    foreach($writable as $p){
        $exists = is_dir($p) || @mkdir($p, 0755, true);
        $ok = is_writable($p);
        $r[] = ['قابل نوشتن: ' . $p, $ok, $ok ? 'writable' : 'not writable - chmod 755/700'];
    }
    return $r;
}
function gen_b64(int $bytes): string { return base64_encode(random_bytes($bytes)); }
function import_sql_file(PDO $pdo, string $file): array {
    if (!file_exists($file)) return ['ok'=>false,'error'=>"فایل یافت نشد: $file"];
    $sql = file_get_contents($file);
    // remove comments? PDO can handle -- comments, but we split
    // Split by ;\n
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("SET NAMES utf8mb4");
    // Use exec for whole file if possible - but need to handle multiple statements
    // Try to execute via PDO exec with multiple statements - many hosts allow it
    try {
        // Split manually - naive but works for our schema
        $stmts = [];
        $buffer = '';
        $lines = explode("\n", $sql);
        foreach($lines as $line){
            $trim = trim($line);
            if($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '#') === 0) continue;
            $buffer .= $line . "\n";
            if(substr(rtrim($line), -1) === ';'){
                $stmts[] = $buffer;
                $buffer = '';
            }
        }
        if(trim($buffer) !== '') $stmts[] = $buffer;
        $count = 0;
        foreach($stmts as $stmt){
            $stmt = trim($stmt);
            if($stmt === '') continue;
            try{
                $pdo->exec($stmt);
                $count++;
            }catch(PDOException $e){
                $msg = $e->getMessage();
                // ignore already exists / duplicate / index not exists (for re-runs)
                if(stripos($msg, 'Duplicate') !== false || stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate key') !== false
                    || stripos($msg, 'Duplicate column') !== false
                    || stripos($msg, "Can't DROP") !== false || strpos($msg, '1091') !== false
                    || stripos($msg, 'check that it exists') !== false){
                    continue;
                }
                return ['ok'=>false,'error'=>"خطا در اجرای SQL: " . $msg . "\n---\n" . substr($stmt,0,500)];
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        return ['ok'=>true,'count'=>$count];
    } catch(Throwable $e){
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        // step 2: test DB + import + write config
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = (string)($_POST['db_pass'] ?? '');
        $app_url = trim($_POST['app_url'] ?? $detectedUrl);
        $admin_pass = trim($_POST['admin_pass'] ?? 'Admin123!');
        $admin_pass2 = trim($_POST['admin_pass2'] ?? 'Admin123!');
        if ($db_name === '' || $db_user === '') $errors[] = 'نام دیتابیس و یوزر الزامی است';
        if ($admin_pass !== $admin_pass2) $errors[] = 'تکرار رمز مدیر مطابقت ندارد';
        if (strlen($admin_pass) < 8) $errors[] = 'رمز مدیر حداقل 8 کاراکتر';
        if (empty($errors)) {
            // try connect
            try {
                $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
                // import
                $files = [
                    $dbDir . '/schema.sql',
                    $dbDir . '/migration_v2_sales_platform.sql',
                    $dbDir . '/migration_v3_simplify_customer.sql',
                ];
                $importOk = true;
                $importLog = '';
                foreach($files as $f){
                    $res = import_sql_file($pdo, $f);
                    if(!$res['ok']){
                        $errors[] = 'خطا در ' . basename($f) . ': ' . $res['error'];
                        $importOk = false;
                        break;
                    } else {
                        $importLog .= basename($f) . ' : ' . ($res['count'] ?? 0) . " دستور اجرا شد<br>";
                    }
                }
                if ($importOk) {
                    // generate keys
                    $enc = gen_b64(32);
                    $jwt = gen_b64(64);
                    $app_url = rtrim($app_url, '/');
                    // write config.php
                    $configDir = dirname($configPath);
                    if (!is_dir($configDir)) @mkdir($configDir, 0750, true);
                    $tpl = "<?php\n/**\n * Auto-generated by install.php on " . date('c') . "\n */\ndeclare(strict_types=1);\n";
                    $tpl .= "define('DB_HOST', " . var_export($db_host, true) . ");\n";
                    $tpl .= "define('DB_NAME', " . var_export($db_name, true) . ");\n";
                    $tpl .= "define('DB_USER', " . var_export($db_user, true) . ");\n";
                    $tpl .= "define('DB_PASS', " . var_export($db_pass, true) . ");\n";
                    $tpl .= "define('DB_CHARSET', 'utf8mb4');\n\n";
                    $tpl .= "define('ENCRYPTION_KEY_B64', " . var_export($enc, true) . ");\n";
                    $tpl .= "define('JWT_SECRET', " . var_export($jwt, true) . ");\n";
                    $tpl .= "define('SESSION_NAME', 'BABAR_SESS');\n";
                    $tpl .= "define('SESSION_LIFETIME', 3600*8);\n";
                    $tpl .= "define('BCRYPT_COST', 12);\n\n";
                    $tpl .= "define('APP_NAME', 'BABARPHANTOM Central');\n";
                    $tpl .= "define('APP_URL', " . var_export($app_url, true) . ");\n";
                    $tpl .= "define('API_RATE_LIMIT_PER_MINUTE', 60);\n";
                    $tpl .= "define('UPLOAD_MAX_BYTES', 50*1024*1024);\n\n";
                    $tpl .= "define('TELEGRAM_API_BASE', 'https://api.telegram.org/bot');\n\n";
                    $tpl .= "define('PRIVATE_ROOT', dirname(__DIR__));\n";
                    $tpl .= "define('TOKEN_STORE_DIR', PRIVATE_ROOT . '/telegram_tokens');\n";
                    $tpl .= "define('LOG_DIR', PRIVATE_ROOT . '/logs');\n";
                    $tpl .= "define('SECURITY_LOG', LOG_DIR . '/security.log');\n";
                    $tpl .= "define('APK_STORE_DIR', PRIVATE_ROOT . '/apk');\n";
                    $tpl .= "define('UPLOAD_DIR', PRIVATE_ROOT . '/uploads');\n";
                    $tpl .= "define('BACKUP_DIR', PRIVATE_ROOT . '/backups');\n\n";
                    $tpl .= "define('ENV', 'production');\n";
                    $tpl .= "if (ENV === 'production') { ini_set('display_errors','0'); error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED); } else { ini_set('display_errors','1'); error_reporting(E_ALL); }\n";
                    $tpl .= "@mkdir(TOKEN_STORE_DIR, 0700, true);\n@mkdir(LOG_DIR, 0700, true);\n@mkdir(APK_STORE_DIR, 0700, true);\n@mkdir(UPLOAD_DIR, 0700, true);\n@mkdir(BACKUP_DIR, 0700, true);\n\n";
                    $tpl .= "define('WALLET_CURRENCY', 'IRT');\ndefine('TOKEN_FORM_TTL_MINUTES', 30);\ndefine('APK_DELIVERY_EXPIRES_DAYS', 30);\n";
                    $written = @file_put_contents($configPath, $tpl, LOCK_EX);
                    if (!$written) {
                        $errors[] = 'نوشتن فایل config.php ناموفق — دسترسی پوشه private/config را 750 کنید';
                    } else {
                        @chmod($configPath, 0640);
                        // create needed dirs
                        @mkdir($privateRoot . '/telegram_tokens', 0700, true);
                        @mkdir($privateRoot . '/logs', 0700, true);
                        @mkdir($privateRoot . '/apk', 0750, true);
                        @mkdir($privateRoot . '/uploads', 0750, true);
                        @mkdir($privateRoot . '/backups', 0700, true);
                        @mkdir($privateRoot . '/templates', 0700, true);
                        @mkdir(__DIR__ . '/templates', 0755, true);
                        // update admin password if provided and not default
                        if ($admin_pass !== 'Admin123!' || true) {
                            $hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost'=>12]);
                            try {
                                $pdo->prepare("UPDATE customers SET password_hash=? WHERE email='admin@local' LIMIT 1")->execute([$hash]);
                                // also ensure status ACTIVE
                                $pdo->exec("UPDATE customers SET status='ACTIVE' WHERE email='admin@local'");
                            } catch(Throwable $e){}
                        }
                        // set app_name and app_url in settings
                        try {
                            $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute(['app_name','BABARPHANTOM Central']);
                            $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute(['app_url',$app_url]);
                        } catch(Throwable $e){}
                        $_SESSION['install_done'] = true;
                        $_SESSION['install_app_url'] = $app_url;
                        $_SESSION['install_db'] = $db_name;
                        $step = 3;
                        $success = $importLog;
                    }
                }
            } catch(PDOException $e){
                $errors[] = 'اتصال به دیتابیس ناموفق: ' . $e->getMessage() . ' — نام/یوزر/پسورد یا هاست را چک کن (معمولا localhost)';
            } catch(Throwable $e){
                $errors[] = $e->getMessage();
            }
        }
        if (!empty($errors)) $step = 2;
    }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصاب BABARPHANTOM Central</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;line-height:1.8} .wrap{max-width:720px;margin:0 auto;padding:16px} .card{background:#1e293b;border-radius:14px;padding:18px;margin:14px 0;border:1px solid #334155} h1{font-size:20px;margin:8px 0} h2{font-size:16px;margin:10px 0;color:#38bdf8} label{display:block;font-size:13px;margin:10px 0 4px;color:#cbd5e1} input{width:100%;padding:12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:14px} input:focus{outline:none;border-color:#38bdf8} button{width:100%;padding:14px;border-radius:12px;border:0;background:#22c55e;color:#052e16;font-weight:800;font-size:15px;cursor:pointer;margin-top:12px} button.secondary{background:#334155;color:#e2e8f0} .err{background:#7f1d1d;color:#fecaca;padding:12px;border-radius:10px;margin:10px 0;font-size:13px} .ok{background:#14532d;color:#bbf7d0;padding:12px;border-radius:10px;margin:10px 0;font-size:13px} .muted{color:#94a3b8;font-size:12px} code{background:#0f172a;padding:2px 6px;border-radius:6px;font-size:12px} .steps{display:flex;gap:8px;margin:12px 0} .step{flex:1;text-align:center;padding:8px;border-radius:10px;background:#0f172a;border:1px solid #334155;font-size:12px} .step.active{background:#38bdf8;color:#082f49;border-color:#38bdf8;font-weight:800} table{width:100%;font-size:12px;border-collapse:collapse} td{padding:6px;border-bottom:1px solid #334155} .badge{padding:3px 8px;border-radius:999px;font-size:11px} .badge.ok{background:#14532d;color:#86efac} .badge.bad{background:#7f1d1d;color:#fecaca}</style>
</head><body><div class="wrap">
<h1>🚀 نصاب BABARPHANTOM Central <span class="muted">v2.1</span></h1>
<p class="muted">فقط همین صفحه را پر کن — همه‌چیز خودکار ساخته می‌شود. بدون نیاز به ویرایش دستی فایل.</p>

<div class="steps">
<div class="step <?= $step===1?'active':'' ?>">1. بررسی</div>
<div class="step <?= $step===2?'active':'' ?>">2. دیتابیس</div>
<div class="step <?= $step===3?'active':'' ?>">3. پایان</div>
</div>

<?php if($alreadyInstalled): ?>
<div class="card">
<h2>✅ قبلاً نصب شده</h2>
<p class="muted">فایل <code><?=h($configPath)?></code> وجود دارد. برای جلوگیری از بازنویسی، نصاب متوقف شد.</p>
<p>اگر می‌خواهی دوباره نصب کنی: <code><?=h($self)?>?force=1</code> را باز کن یا <code>private/config/config.php</code> را پاک کن.</p>
<p><a href="admin/" style="color:#38bdf8">→ ورود به پنل مدیریت</a></p>
</div>
<?php endif; ?>

<?php foreach($errors as $e): ?><div class="err"><?=h($e)?></div><?php endforeach; ?>
<?php if($success): ?><div class="ok"><?= $success ?></div><?php endif; ?>

<?php if($step===1 && !$alreadyInstalled): 
$reqs = check_requirements();
$allOk = true; foreach($reqs as $r) if(!$r[1]) $allOk=false;
?>
<div class="card">
<h2>بررسی سرور</h2>
<table>
<?php foreach($reqs as $r): ?>
<tr><td><?=h($r[0])?></td><td style="text-align:left"><span class="badge <?= $r[1]?'ok':'bad' ?>"><?= $r[1]?'✅ '.$r[2] : '❌ '.$r[2] ?></span></td></tr>
<?php endforeach; ?>
</table>
<p class="muted">اگر موردی ❌ بود: ورژن PHP را از cPanel → Select PHP Version روی 8.1 یا 8.2 بگذار، و پوشه‌ها را 755 کن.</p>
<form method="get">
<input type="hidden" name="step" value="2">
<button type="submit" <?= $allOk?'':'disabled style="opacity:.5"' ?>><?= $allOk?'ادامه → تنظیم دیتابیس':'لطفا موارد ❌ را رفع کن' ?></button>
</form>
</div>
<div class="card muted" style="font-size:11px">
نکته مسیر: نصاب به‌صورت خودکار <code>private</code> را در <code>../private</code> پیدا می‌کند. اگر هاست فقط public_html دارد، private را یک سطح بالاتر بگذار — همین ZIP آماده (HOST_READY) همین کار را می‌کند.
</div>

<?php elseif($step===2 && !$alreadyInstalled): ?>
<div class="card">
<h2>اتصال دیتابیس + ساخت جدول‌ها</h2>
<p class="muted">اطلاعات را از cPanel → MySQL Databases کپی کن. اگر دیتابیس نساختی، اول بساز (نام، یوزر، پسورد). برای بیشتر هاست‌ها هاست = <code>localhost</code></p>
<form method="post">
<input type="hidden" name="step" value="2">
<label>هاست دیتابیس</label>
<input name="db_host" value="<?=h($_POST['db_host'] ?? 'localhost')?>" placeholder="localhost" required>
<label>نام دیتابیس (با پیشوند مثل nexsrvir_babar_central)</label>
<input name="db_name" value="<?=h($_POST['db_name'] ?? '')?>" placeholder="nexsrvir_babar_central" required dir="ltr">
<label>یوزر دیتابیس</label>
<input name="db_user" value="<?=h($_POST['db_user'] ?? '')?>" placeholder="nexsrvir_babar_central" required dir="ltr">
<label>پسورد دیتابیس</label>
<input name="db_pass" type="password" value="<?=h($_POST['db_pass'] ?? '')?>" placeholder="••••••••" required dir="ltr">
<label>آدرس سایت (APP_URL)</label>
<input name="app_url" value="<?=h($_POST['app_url'] ?? $detectedUrl)?>" placeholder="https://nexsrv.ir" required dir="ltr">
<p class="muted">خودکار تشخیص داده شد: <code><?=h($detectedUrl)?></code> — اگر درست نیست اصلاح کن (بدون / آخر)</p>
<label>رمز جدید مدیر (admin@local)</label>
<input name="admin_pass" type="password" value="<?=h($_POST['admin_pass'] ?? 'Admin123!')?>" required dir="ltr">
<label>تکرار رمز مدیر</label>
<input name="admin_pass2" type="password" value="<?=h($_POST['admin_pass2'] ?? 'Admin123!')?>" required dir="ltr">
<p class="muted">پیش‌فرض: Admin123! — حتما بعدا از تنظیمات عوض کن</p>
<button type="submit">ساخت خودکار جدول‌ها + فایل config →</button>
</form>
</div>

<?php elseif($step===3): ?>
<div class="card">
<h2>🎉 نصب کامل شد</h2>
<div class="ok">دیتابیس ساخته شد، <code>private/config/config.php</code> با کلیدهای تصادفی ساخته شد، پوشه‌ها آماده شد.</div>
<p>اطلاعات ورود مدیر:</p>
<p><code>https://<?=h(parse_url($_SESSION['install_app_url'] ?? $detectedUrl, PHP_URL_HOST) ?? 'your-host')?>/admin/</code><br>
یوزر: <code>admin@local</code> — رمز: همونی که وارد کردی (پیش‌فرض Admin123!)</p>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<a href="admin/" style="flex:1;text-align:center;background:#22c55e;color:#052e16;padding:12px;border-radius:10px;text-decoration:none;font-weight:800">ورود به پنل مدیریت →</a>
<a href="api/status.php" style="flex:1;text-align:center;background:#334155;color:#e2e8f0;padding:12px;border-radius:10px;text-decoration:none">تست سلامت API</a>
</div>
<p class="muted">قدم‌های بعدی (اختیاری):<br>
1) در پنل → APK آپلود کن<br>
2) در پنل → قالب دلخواه → HTML آپلود کن<br>
3) در ساخت دستی → نام کاربری + توکن ربات → لینک تحویل بساز</p>
<div class="err">⚠️ برای امنیت همین الان فایل <code>install.php</code> را از هاست حذف کن (File Manager → Delete)</div>
<form method="post" action="?delete=1" onsubmit="return confirm('حذف install.php؟')">
<button type="submit" class="secondary">حذف خودکار install.php (توصیه می‌شود)</button>
</form>
</div>
<?php endif; ?>

<?php if(isset($_GET['delete'])){ @unlink(__FILE__); echo '<div class="ok">install.php حذف شد</div>'; } ?>

<p class="muted" style="text-align:center;margin-top:18px">BABARPHANTOM Central v2.1 — بدون ربات فروش — قالب دلخواه — هر مشتری API Key جدا</p>
</div></body></html>
