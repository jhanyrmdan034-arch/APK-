<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_login();
$pdo = db();
$isAdmin = is_admin((int)$me['id']);
$cid = (int)$me['id'];
if ($isAdmin && !empty($_GET['customer_id'])) $cid=(int)$_GET['customer_id'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act=$_POST['action']??'';
    if($act==='toggle' && isset($_POST['device_id'])){
        $did=(int)$_POST['device_id'];
        $s=$pdo->prepare("SELECT customer_id, is_active FROM devices WHERE id=?"); $s->execute([$did]); $row=$s->fetch();
        if($row && ((int)$row['customer_id']===$cid || $isAdmin)){
            $new = (int)$row['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE devices SET is_active=? WHERE id=?")->execute([$new,$did]);
            admin_log((int)$me['id'], $new?'device_unblocked':'device_blocked','device',$did,['customer_id'=>$row['customer_id']]);
            sec_log($new?'device_unblocked':'device_blocked',(int)$me['id'],['did'=>$did,'new'=>$new]);
        }
        header("Location: devices.php".($isAdmin?"?customer_id=$cid":'')); exit;
    }
    if($act==='delete' && isset($_POST['delete_id'])){
        $did=(int)$_POST['delete_id'];
        $s=$pdo->prepare("SELECT customer_id FROM devices WHERE id=?"); $s->execute([$did]); $row=$s->fetch();
        if ($row && ((int)$row['customer_id']===$cid || $isAdmin)) {
            $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([$did]);
            admin_log((int)$me['id'],'device_deleted','device',$did,[]);
        }
        header("Location: devices.php".($isAdmin?"?customer_id=$cid":'')); exit;
    }
}
$filterCid = $isAdmin ? $cid : (int)$me['id'];
$stmt=$pdo->prepare("SELECT id, device_id, device_name, model, os_version, app_version, last_seen_at, is_active, created_at FROM devices WHERE customer_id=? ORDER BY last_seen_at DESC");
$stmt->execute([$filterCid]); $rows=$stmt->fetchAll();
$cEmail=$pdo->prepare("SELECT email FROM customers WHERE id=?"); $cEmail->execute([$filterCid]); $cEmail=$cEmail->fetchColumn();
$cStatus=$pdo->prepare("SELECT status FROM customers WHERE id=?"); $cStatus->execute([$filterCid]); $cStatus=$cStatus->fetchColumn();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دستگاه‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}select{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a class="active" href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>دستگاه‌ها — مسدودسازی مستقل</h1>
<?php if($isAdmin): ?><div class="card"><form method="get">مشتری: <select name="customer_id" onchange="this.form.submit()"><?php $cs=$pdo->query("SELECT id,email,status FROM customers ORDER BY id"); foreach($cs as $c) echo '<option value="'.$c['id'].'"'.($c['id']==$filterCid?' selected':'').'>'.htmlspecialchars($c['email'])." [{$c['status']}]</option>"; ?></select></form></div><?php endif; ?>
<p>مشتری: <b><?=htmlspecialchars($cEmail)?></b> — وضعیت حساب: <b><?=htmlspecialchars($cStatus)?></b> — <?=count($rows)?> دستگاه — جداسازی <code>WHERE customer_id=?</code></p>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>Device ID</th><th>نام / مدل</th><th>OS / App</th><th>آخرین اتصال</th><th>وضعیت دستگاه</th><th>عملیات</th></tr>
<?php foreach($rows as $r): ?>
<tr>
<td><?=$r['id']?></td><td><code><?=htmlspecialchars(substr($r['device_id'],0,16))?>…</code><br><small style="color:#94a3b8"><?=htmlspecialchars($r['device_id'])?></small></td>
<td><?=htmlspecialchars($r['device_name']?:'—')?><br><small style="color:#94a3b8"><?=htmlspecialchars($r['model']?:'—')?></small></td>
<td><?=htmlspecialchars($r['os_version']?:'—')?> / <?=htmlspecialchars($r['app_version']?:'—')?></td>
<td><?=htmlspecialchars($r['last_seen_at']??'—')?></td>
<td><?=$r['is_active']?'✅ فعال':'⛔ مسدود'?><br><?php if(!$r['is_active']) echo '<small style="color:#f87171">API مسدود</small>'; ?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="device_id" value="<?=$r['id']?>"><button style="background:<?=$r['is_active']?'#f59e0b;color:#000':'#22c55e;color:#000'?>;padding:6px 10px;border:0;border-radius:6px;cursor:pointer"><?=$r['is_active']?'مسدود کن':'فعال کن'?></button></form>
<form method="post" onsubmit="return confirm('حذف دستگاه؟')"><input type="hidden" name="action" value="delete"><input type="hidden" name="delete_id" value="<?=$r['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 10px;border:0;border-radius:6px;cursor:pointer">حذف</button></form>
</td>
</tr>
<?php endforeach; ?>
<?php if(!count($rows)): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">هنوز دستگاهی ثبت نشده. APK را با API Key صحیح متصل کنید.</td></tr><?php endif; ?>
</table>
</div>
<div class="card" style="font-size:12px;color:#94a3b8">مدیر می‌تواند: 1) کل حساب را SUSPENDED/DISABLED کند (customers.php)، 2) دستگاه خاص را مسدود کند (همین صفحه)، 3) ربات را قطع کند (bots.php). همه از طریق سرور مرکزی اعمال می‌شود؛ APK با <code>is_active=0</code> پاسخ 403 می‌گیرد.</div>
</div></body></html>
