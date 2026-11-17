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
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id'])) {
    $did=(int)$_POST['delete_id'];
    $s=$pdo->prepare("SELECT customer_id FROM devices WHERE id=?"); $s->execute([$did]); $row=$s->fetch();
    if ($row && ((int)$row['customer_id']==(int)$me['id'] || $isAdmin)) {
        if ($isAdmin || (int)$row['customer_id']==(int)$me['id']) { $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([$did]); sec_log('device_deleted_admin',(int)$me['id'],['did'=>$did]); }
    }
    header("Location: devices.php".($isAdmin?"?customer_id=$cid":'')); exit;
}
$filterCid = $isAdmin ? $cid : (int)$me['id'];
$stmt=$pdo->prepare("SELECT id, device_id, device_name, model, os_version, app_version, last_seen_at, is_active, created_at FROM devices WHERE customer_id=? ORDER BY last_seen_at DESC");
$stmt->execute([$filterCid]); $rows=$stmt->fetchAll();
$cEmail=$pdo->prepare("SELECT email FROM customers WHERE id=?"); $cEmail->execute([$filterCid]); $cEmail=$cEmail->fetchColumn();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دستگاه‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:12px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 12px;border-radius:8px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:13px}select{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="bots.php">ربات‌ها</a><a class="active" href="devices.php">دستگاه‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>دستگاه‌ها</h1>
<?php if($isAdmin): ?><div class="card"><form method="get">مشتری: <select name="customer_id" onchange="this.form.submit()"><?php $cs=$pdo->query("SELECT id,email FROM customers ORDER BY id"); foreach($cs as $c) echo '<option value="'.$c['id'].'"'.($c['id']==$filterCid?' selected':'').'>'.htmlspecialchars($c['email'])."</option>"; ?></select></form></div><?php endif; ?>
<p>مشتری: <b><?=htmlspecialchars($cEmail)?></b> — <?=count($rows)?> دستگاه</p>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>Device ID</th><th>نام / مدل</th><th>OS / App</th><th>آخرین اتصال</th><th>وضعیت</th><th>عملیات</th></tr>
<?php foreach($rows as $r): ?>
<tr>
<td><?=$r['id']?></td><td><code><?=htmlspecialchars(substr($r['device_id'],0,16))?>…</code></td>
<td><?=htmlspecialchars($r['device_name']?:'—')?><br><small style="color:#94a3b8"><?=htmlspecialchars($r['model']?:'—')?></small></td>
<td><?=htmlspecialchars($r['os_version']?:'—')?> / <?=htmlspecialchars($r['app_version']?:'—')?></td>
<td><?=htmlspecialchars($r['last_seen_at']??'—')?></td>
<td><?=$r['is_active']?'✅':'⛔'?></td>
<td><form method="post" onsubmit="return confirm('حذف دستگاه؟')"><input type="hidden" name="delete_id" value="<?=$r['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 10px;border:0;border-radius:6px;cursor:pointer">حذف</button></form></td>
</tr>
<?php endforeach; ?>
<?php if(!count($rows)): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">هنوز دستگاهی ثبت نشده. APK را با API Key صحیح متصل کنید.</td></tr><?php endif; ?>
</table>
</div>
<div class="card" style="font-size:12px;color:#94a3b8">هر ردیف یک دستگاه فیزیکی است. جداسازی با <code>WHERE customer_id=?</code> — هر مشتری فقط دستگاه خودش را می‌بیند.</div>
</div></body></html>
