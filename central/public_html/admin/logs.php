<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$type=$_GET['type']??'admin';
$allowed=['admin','security','all'];
if(!in_array($type,$allowed,true)) $type='admin';

$logs=[];
if($type==='admin' || $type==='all'){
    $logs=$pdo->query("SELECT l.*, c.email as admin_email FROM admin_logs l LEFT JOIN customers c ON c.id=l.admin_id ORDER BY l.id DESC LIMIT 100")->fetchAll();
    $sec=[];
} else {
    $logs=[];
}
if($type==='security' || $type==='all'){
    $sec=$pdo->query("SELECT * FROM security_events ORDER BY id DESC LIMIT 100")->fetchAll();
} else $sec=[];
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1200px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}pre{white-space:pre-wrap;font-size:11px;background:#0f172a;padding:10px;border-radius:8px;max-height:400px;overflow:auto}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="apk.php">APK</a><a class="active" href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>گزارش فعالیت‌ها</h1>
<div class="card">
<a href="logs.php?type=admin" style="background:<?=$type==='admin'?'#38bdf8;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">عملیات مدیر</a>
<a href="logs.php?type=security" style="background:<?=$type==='security'?'#38bdf8;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">رویداد امنیتی</a>
<a href="logs.php?type=all" style="background:<?=$type==='all'?'#38bdf8;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">همه</a>
</div>
<?php if($type!=='security'): ?>
<div class="card">
<h3>عملیات مدیر (آخر 100)</h3>
<pre><?php foreach($logs as $l) echo htmlspecialchars($l['created_at'].' ['.$l['action'].'] admin='.$l['admin_email'].' ('.$l['admin_id'].') target='.$l['target_type'].'#'.$l['target_id'].' ip='.$l['ip'].' '.($l['details']??''))."\n"; if(!$logs) echo "—";?></pre>
</div>
<?php endif; ?>
<?php if($type!=='admin'): ?>
<div class="card">
<h3>رویدادهای امنیتی (آخر 100)</h3>
<pre><?php foreach($sec as $l) echo htmlspecialchars($l['created_at'].' ['.$l['event_type'].'] cid='.($l['customer_id']??'-').' ip='.$l['ip'].' '.($l['details']??''))."\n"; if(!$sec) echo "—";?></pre>
</div>
<?php endif; ?>
</div></body></html>
