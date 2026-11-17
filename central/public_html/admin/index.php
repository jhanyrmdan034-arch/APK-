<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

if (session_status() === PHP_SESSION_NONE) { session_name(SESSION_NAME); session_start(); }

// If not logged in, show login
if (empty($_SESSION['customer_id'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = sanitize_string($_POST['email'] ?? '', 255);
        $pass = $_POST['password'] ?? '';
        if (!check_rate_limit($_SERVER['REMOTE_ADDR'] ?? 'unknown', 'admin_login')) {
            $error = 'تعداد درخواست زیاد است. یک دقیقه صبر کنید.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("SELECT id, password_hash, status FROM customers WHERE email=? LIMIT 1");
                $stmt->execute([$email]);
                $c = $stmt->fetch();
                if ($c && password_verify($pass, $c['password_hash']) && $c['status']==='active') {
                    session_regenerate_id(true);
                    $_SESSION['customer_id'] = (int)$c['id'];
                    $_SESSION['is_admin'] = is_admin((int)$c['id']);
                    sec_log('admin_login_success', (int)$c['id']);
                    header('Location: index.php'); exit;
                } else {
                    sec_log('admin_login_failed', null, ['email'=>$email]);
                    $error = 'ایمیل یا رمز عبور اشتباه است.';
                }
            } catch (Throwable $e) { $error = 'خطای سرور'; }
        }
    }
    ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود — <?=htmlspecialchars(APP_NAME)?></title>
<style>body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#1e293b;padding:32px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.4);width:100%;max-width:400px}
input{width:100%;padding:12px;margin:8px 0;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;box-sizing:border-box}
button{width:100%;padding:12px;background:#22c55e;color:#052e16;border:0;border-radius:8px;font-weight:800;cursor:pointer}
.err{background:#7f1d1d;color:#fecaca;padding:10px;border-radius:8px;margin-bottom:12px;font-size:13px}
h1{margin:0 0 16px;font-size:20px}</style>
</head><body><div class="card">
<h1>🔐 ورود به پنل مدیریت</h1>
<?php if($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post">
<input type="email" name="email" placeholder="ایمیل" required>
<input type="password" name="password" placeholder="رمز عبور" required>
<button type="submit">ورود</button>
</form>
<p style="font-size:12px;color:#94a3b8;margin-top:16px">پیش‌فرض: admin@local / Admin123! — پس از ورود رمز را عوض کنید.</p>
</div></body></html>
    <?php
    exit;
}

// Logged in — dashboard
$me = admin_require_login();
$pdo = db();
$isAdmin = is_admin((int)$me['id']);

// Stats
$stats = [];
if ($isAdmin) {
    $stats['customers'] = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $stats['devices'] = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
    $stats['bots'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_bots")->fetchColumn();
    $stats['events'] = (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
} else {
    $cid = (int)$me['id'];
    $s = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=?"); $s->execute([$cid]); $stats['devices']=(int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM telegram_bots WHERE customer_id=?"); $s->execute([$cid]); $stats['bots']=(int)$s->fetchColumn();
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>داشبورد — <?=htmlspecialchars(APP_NAME)?></title>
<style>
*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}
.nav{background:#1e293b;padding:12px 24px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.nav a{color:#94a3b8;text-decoration:none;padding:8px 12px;border-radius:8px}
.nav a.active,.nav a:hover{background:#334155;color:#e2e8f0}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
.card{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.stat{background:#0f172a;padding:16px;border-radius:10px;text-align:center}
.stat b{font-size:28px;display:block;color:#22c55e}
.btn{display:inline-block;background:#334155;color:#e2e8f0;padding:8px 14px;border-radius:8px;text-decoration:none}
</style>
</head><body>
<div class="nav">
<b><?=htmlspecialchars(APP_NAME)?></b>
<a class="active" href="index.php">داشبورد</a>
<a href="customers.php">مشتریان</a>
<a href="bots.php">ربات‌ها</a>
<a href="devices.php">دستگاه‌ها</a>
<a href="settings.php">تنظیمات</a>
<a href="?logout=1" style="margin-right:auto;background:#7f1d1d;color:#fecaca">خروج (<?=htmlspecialchars($me['email'])?>)</a>
</div>
<?php if(isset($_GET['logout'])){session_destroy(); header('Location: index.php'); exit;} ?>
<div class="wrap">
<h1>سلام، <?=htmlspecialchars($me['name'] ?: $me['email'])?> 👋</h1>
<p style="color:#94a3b8">نقش: <?= $isAdmin ? 'مدیر کل' : 'مشتری' ?> | UUID: <code><?=htmlspecialchars($me['uuid'])?></code></p>

<div class="grid">
<?php if($isAdmin): ?>
<div class="stat"><b><?=$stats['customers']?></b>مشتری</div>
<?php endif; ?>
<div class="stat"><b><?=$stats['devices']?></b>دستگاه</div>
<div class="stat"><b><?=$stats['bots']?></b>ربات تلگرام</div>
<?php if($isAdmin): ?><div class="stat"><b><?=$stats['events']?></b>رویداد ۲۴ساعت</div><?php endif; ?>
</div>

<div class="card">
<h3>وضعیت سیستم</h3>
<p><a class="btn" href="../api/status.php" target="_blank">بررسی سلامت API</a></p>
<?php
$ch = $pdo->query("SELECT v FROM settings WHERE k='maintenance'")->fetchColumn();
echo $ch==='1' ? '<p style="color:#f87171">⚠️ حالت تعمیر فعال است.</p>' : '<p style="color:#22c55e">✅ سیستم فعال است.</p>';
?>
</div>

<div class="card">
<h3>راهنمای سریع</h3>
<ul style="line-height:2;color:#cbd5e1">
<li>برای افزودن مشتری: <a href="customers.php" style="color:#22c55e">مشتریان → افزودن</a></li>
<li>برای ثبت توکن ربات: <a href="bots.php" style="color:#22c55e">ربات‌ها → ثبت توکن</a> (توکن فقط در سرور ذخیره می‌شود، هرگز در APK نیست)</li>
<li>دستگاه‌ها پس از نصب APK و اولین اتصال به <code>/api/devices.php</code> اینجا ظاهر می‌شوند.</li>
<li>هر مشتری فقط دستگاه‌ها و ربات خودش را می‌بیند.</li>
</ul>
</div>
</div>
</body></html>
