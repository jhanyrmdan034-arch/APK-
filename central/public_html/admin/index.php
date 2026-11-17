<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

if (session_status() === PHP_SESSION_NONE) { session_name(SESSION_NAME); session_start(); }

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
                if ($c && password_verify($pass, $c['password_hash']) && status_allows_login($c['status'])) {
                    session_regenerate_id(true);
                    $_SESSION['customer_id'] = (int)$c['id'];
                    $_SESSION['is_admin'] = is_admin((int)$c['id']);
                    sec_log('admin_login_success', (int)$c['id']);
                    header('Location: index.php'); exit;
                } else {
                    sec_log('admin_login_failed', null, ['email'=>$email]);
                    $error = ($c && !status_allows_login($c['status'])) ? 'حساب شما '.normalize_status($c['status']).' است.' : 'ایمیل یا رمز عبور اشتباه است.';
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
    <?php exit;
}
if(isset($_GET['logout'])){ session_destroy(); header('Location: index.php'); exit; }

$me = admin_require_login();
$pdo = db();
$isAdmin = is_admin((int)$me['id']);
$stats = [];
if ($isAdmin) {
    $stats['customers'] = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $stats['customers_active'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status='ACTIVE'")->fetchColumn();
    $stats['customers_suspended'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status='SUSPENDED'")->fetchColumn();
    $stats['customers_disabled'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status='DISABLED'")->fetchColumn();
    $stats['devices'] = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
    $stats['bots'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_bots")->fetchColumn();
    $stats['events'] = (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $stats['wallets_sum'] = (int)($pdo->query("SELECT COALESCE(SUM(balance),0) FROM wallets")->fetchColumn());
    $stats['pending_payments'] = (int)$pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
    $stats['orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $stats['paid_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','delivered')")->fetchColumn();
    $stats['apk_files'] = (int)$pdo->query("SELECT COUNT(*) FROM apk_files")->fetchColumn();
    $stats['deliveries'] = (int)$pdo->query("SELECT COUNT(*) FROM apk_deliveries")->fetchColumn();
} else {
    $cid = (int)$me['id'];
    $s = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=?"); $s->execute([$cid]); $stats['devices']=(int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM telegram_bots WHERE customer_id=?"); $s->execute([$cid]); $stats['bots']=(int)$s->fetchColumn();
    $w=get_wallet($cid); $stats['balance']=(int)$w['balance'];
    $s=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id=?"); $s->execute([$cid]); $stats['orders']=(int)$s->fetchColumn();
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>داشبورد — <?=htmlspecialchars(APP_NAME)?></title>
<style>
*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}
.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}
.nav a.active,.nav a:hover{background:#334155;color:#e2e8f0}
.wrap{max-width:1200px;margin:24px auto;padding:0 16px}
.card{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.stat{background:#0f172a;padding:14px;border-radius:10px;text-align:center}
.stat b{font-size:22px;display:block;color:#22c55e}
.btn{display:inline-block;background:#334155;color:#e2e8f0;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:13px}
.alert{background:#7f1d1d;color:#fecaca;padding:10px;border-radius:8px;margin-bottom:12px}
.ok{background:#14532d;color:#bbf7d0;padding:10px;border-radius:8px;margin-bottom:12px}
code{background:#0f172a;padding:2px 6px;border-radius:4px}
</style>
</head><body>
<div class="nav">
<b><?=htmlspecialchars(APP_NAME)?></b>
<a class="active" href="index.php">داشبورد</a>
<?php if($isAdmin): ?>
<a href="customers.php">مشتریان</a>
<a href="wallets.php">کیف پول</a>
<a href="payments.php">پرداخت‌ها <?php if(!empty($stats['pending_payments'])&&$stats['pending_payments']>0) echo '<span style="background:#f59e0b;color:#000;padding:2px 6px;border-radius:10px;font-size:11px">'.$stats['pending_payments'].'</span>';?></a>
<a href="orders.php">سفارش‌ها</a>
<?php endif; ?>
<a href="bots.php">ربات‌ها</a>
<a href="devices.php">دستگاه‌ها</a>
<?php if($isAdmin): ?><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a href="logs.php">گزارش‌ها</a><?php endif; ?>
<a href="settings.php">تنظیمات</a>
<a href="?logout=1" style="margin-right:auto;background:#7f1d1d;color:#fecaca">خروج (<?=htmlspecialchars($me['email'])?>)</a>
</div>
<div class="wrap">
<h1>سلام، <?=htmlspecialchars($me['name'] ?: $me['email'])?> 👋</h1>
<p style="color:#94a3b8">نقش: <?= $isAdmin ? 'مدیر کل' : 'مشتری' ?> | وضعیت: <?=htmlspecialchars(normalize_status($me['status']))?> | UUID: <code><?=htmlspecialchars($me['uuid'])?></code></p>

<?php if($isAdmin && $stats['pending_payments']>0): ?><div class="alert">⏳ <?= $stats['pending_payments'] ?> درخواست شارژ در انتظار تأیید — <a href="payments.php" style="color:#fecaca;text-decoration:underline">بررسی کنید</a></div><?php endif; ?>

<div class="grid">
<?php if($isAdmin): ?>
<div class="stat"><b><?=$stats['customers']?></b>مشتری (<?= $stats['customers_active']?> فعال)</div>
<div class="stat" style="border:1px solid #334155"><b style="color:#f59e0b"><?=number_format($stats['wallets_sum'])?></b>مجموع کیف پول</div>
<div class="stat"><b style="color:#f59e0b"><?=$stats['pending_payments']?></b>پرداخت pending</div>
<div class="stat"><b><?=$stats['orders']?></b>سفارش (<?=$stats['paid_orders']?> پرداخت‌شده)</div>
<?php endif; ?>
<div class="stat"><b><?=$stats['devices']?></b>دستگاه</div>
<div class="stat"><b><?=$stats['bots']?></b>ربات تلگرام</div>
<?php if(!$isAdmin): ?><div class="stat"><b style="color:#22c55e"><?=number_format($stats['balance'])?></b>موجودی کیف پول</div><div class="stat"><b><?=$stats['orders']?></b>سفارش من</div><?php endif; ?>
<?php if($isAdmin): ?><div class="stat"><b><?=$stats['apk_files']?></b>نسخه APK</div><div class="stat"><b><?=$stats['deliveries']?></b>تحویل</div><div class="stat"><b><?=$stats['events']?></b>رویداد ۲۴ساعت</div><?php endif; ?>
</div>

<div class="card">
<h3>وضعیت سیستم</h3>
<p><a class="btn" href="../api/status.php" target="_blank">بررسی سلامت API</a> <a class="btn" href="<?=htmlspecialchars(APP_URL)?>/token.php?t=test" target="_blank">تست فرم توکن</a></p>
<?php $ch = $pdo->query("SELECT v FROM settings WHERE k='maintenance'")->fetchColumn(); echo $ch==='1' ? '<p style="color:#f87171">⚠️ حالت تعمیر فعال است.</p>' : '<p style="color:#22c55e">✅ سیستم فعال است.</p>'; ?>
<p style="font-size:12px;color:#94a3b8">ربات فروش: <code><?=htmlspecialchars(SALES_BOT_USERNAME ?: 'تنظیم نشده')?></code> | وب‌هوک: <code><?=htmlspecialchars(APP_URL)?>/api/sales_webhook.php</code></p>
</div>

<?php if($isAdmin): ?>
<div class="card">
<h3>اقدام سریع</h3>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<a class="btn" href="customers.php">➕ مشتری جدید</a>
<a class="btn" href="payments.php">💳 تأیید پرداخت‌ها</a>
<a class="btn" href="apk.php">📦 آپلود APK</a>
<a class="btn" href="wallets.php">💰 مدیریت کیف پول</a>
</div>
</div>
<?php endif; ?>

<div class="card">
<h3>راهنمای خودکارسازی</h3>
<ul style="line-height:2;color:#cbd5e1;font-size:13px">
<li><b>ثبت‌نام خودکار:</b> کاربر با <code>/start</code> در ربات فروش → حساب + کیف پول + API Key ساخته می‌شود.</li>
<li><b>شارژ:</b> درخواست → رسید → مدیر تأیید در <code>payments.php</code> → کیف پول شارژ + تراکنش خودکار.</li>
<li><b>سفارش:</b> با موجودی کافی → کسر خودکار → ایجاد سفارش <code>paid</code> → اگر <code>apk_auto_delivery=1</code> تحویل خودکار APK + لینک دانلود + ارسال فایل در تلگرام.</li>
<li><b>توکن:</b> فقط از طریق <b>فرم امن HTTPS</b> (<code>/token.php?t=one-time</code>) — اعتبارسنجی <code>getMe</code> — ذخیره رمزنگاری‌شده.</li>
<li><b>دسترسی:</b> <code>ACTIVE</code>=کامل، <code>SUSPENDED</code>=فقط مشاهده، <code>DISABLED</code>=مسدود کامل (+ مسدود دستگاه خاص، قطع ربات).</li>
</ul>
</div>

<?php if($isAdmin): 
$recent=$pdo->query("SELECT COALESCE(c.name,c.email) as display_name, p.amount, p.status, p.created_at FROM payment_requests p JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC LIMIT 5")->fetchAll();
if($recent): ?>
<div class="card"><h3>آخرین پرداخت‌ها</h3><table style="width:100%;font-size:12px;border-collapse:collapse"><tr style="color:#94a3b8"><th>مشتری</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr>
<?php foreach($recent as $r): ?><tr style="border-top:1px solid #334155"><td><?=htmlspecialchars($r['display_name'])?></td><td><?=number_format($r['amount'])?></td><td><?=$r['status']?></td><td><?=htmlspecialchars($r['created_at'])?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; endif; ?>
</div>
</body></html>
