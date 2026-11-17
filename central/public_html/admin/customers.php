<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_admin();
$pdo = db();
$msg=''; $msgErr=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
        $name=sanitize_string($_POST['name']??'',32);
        $status=normalize_status($_POST['status']??'ACTIVE');
        if (!is_valid_username($name)) {$msg='نام کاربری نامعتبر (3-32 حرف/عدد/زیرخط)';$msgErr=true;}
        elseif (!in_array($status,[STATUS_ACTIVE,STATUS_SUSPENDED,STATUS_DISABLED],true)) {$msg='وضعیت نامعتبر';$msgErr=true;}
        else {
            try {
                $cust=create_customer_by_username($name);
                $id=$cust['id'];
                // اگر وضعیت غیر ACTIVE بود، به‌روزرسانی
                if($status!=='ACTIVE') $pdo->prepare("UPDATE customers SET status=? WHERE id=?")->execute([$status,$id]);
                admin_log((int)$me['id'],'customer_created','customer',$id,['username'=>$name]);
                $msg="مشتری '$name' (#$id) ساخته شد — API Key: {$cust['api_key']}";
            } catch (Throwable $e){ $msg='خطا: '.htmlspecialchars($e->getMessage());$msgErr=true; }
        }
    } elseif ($action==='status') {
        $id=(int)($_POST['id']??0); $new=normalize_status($_POST['new_status']??'ACTIVE');
        $pdo->prepare("UPDATE customers SET status=? WHERE id=?")->execute([$new,$id]);
        admin_log((int)$me['id'],'customer_status_change','customer',$id,['new_status'=>$new]);
        $msg="وضعیت مشتری #$id به $new تغییر کرد.";
        if($new===STATUS_DISABLED || $new===STATUS_SUSPENDED){
            $pdo->prepare("UPDATE devices SET is_active=0 WHERE customer_id=?")->execute([$id]);
        } elseif($new===STATUS_ACTIVE){
            $pdo->prepare("UPDATE devices SET is_active=1 WHERE customer_id=?")->execute([$id]);
        }
    } elseif ($action==='delete') {
        $id=(int)($_POST['id']??0);
        if ($id===(int)$me['id']) {$msg='خودتان را نمی‌توانید حذف کنید.';$msgErr=true;}
        else { $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]); admin_log((int)$me['id'],'customer_deleted','customer',$id,[]); $msg="حذف شد."; }
    } elseif ($action==='disconnect_bot') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE telegram_bots SET is_connected=0 WHERE customer_id=?")->execute([$id]);
        admin_log((int)$me['id'],'bot_disconnected','bot',$id,[]);
        $msg="ربات مشتری #$id قطع شد.";
    }
}
$q=sanitize_string($_GET['q']??'',100);
if($q!==''){
    $stmt=$pdo->prepare("SELECT id,uuid,email,name,status,telegram_id,created_at FROM customers WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC");
    $like="%$q%"; $stmt->execute([$like,$like]);
} else {
    $stmt=$pdo->query("SELECT id,uuid,email,name,status,telegram_id,created_at FROM customers ORDER BY id DESC");
}
$rows=$stmt->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مشتریان</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1200px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}input,select{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}button{padding:7px 10px;border-radius:6px;border:0;cursor:pointer;font-size:12px}.btn2{background:#22c55e;color:#052e16;font-weight:700}.btn3{background:#7f1d1d;color:#fecaca}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a class="active" href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت مشتریان — نام کاربری یکتا</h1>
<?php if($msg): ?><div class="card" style="background:<?=$msgErr?'#7f1d1d':'#14532d'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="card">
<h3>افزودن مشتری (بدون ایمیل و رمز)</h3>
<form method="post"><input type="hidden" name="action" value="add">
<input name="name" placeholder="نام کاربری (مثال: ali_123)" required maxlength="32" dir="ltr">
<select name="status"><option value="ACTIVE">ACTIVE فعال</option><option value="SUSPENDED">SUSPENDED تعلیق</option><option value="DISABLED">DISABLED غیرفعال</option></select>
<button class="btn2" type="submit">افزودن</button>
</form>
<p style="font-size:11px;color:#94a3b8">نام کاربری یکتا (3-32 حرف/عدد/زیرخط). ایمیل/رمز برای مشتری عادی لازم نیست — فقط مدیر با ایمیل/رمز وارد پنل می‌شود. شناسه داخلی (id/uuid) برای سفارش‌ها استفاده می‌شود.</p>
</div>
<div class="card">
<form method="get"><input name="q" placeholder="جستجو نام کاربری" value="<?=htmlspecialchars($q)?>"><button>جستجو</button> <a href="customers.php" style="color:#38bdf8">نمایش همه</a></form>
</div>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>نام کاربری / تلگرام</th><th>وضعیت</th><th>کیف پول</th><th>دستگاه/ربات</th><th>عملیات</th></tr>
<?php foreach($rows as $r):
 $w=$pdo->prepare("SELECT balance FROM wallets WHERE customer_id=?"); $w->execute([$r['id']]); $bal=$w->fetchColumn();
 $dc=$pdo->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=?"); $dc->execute([$r['id']]); $dcc=(int)$dc->fetchColumn();
 $bc=$pdo->prepare("SELECT COUNT(*) FROM telegram_bots WHERE customer_id=?"); $bc->execute([$r['id']]); $bcc=(int)$bc->fetchColumn();
 $status=$r['status']; $color=$status==='ACTIVE'?'#22c55e':($status==='SUSPENDED'?'#f59e0b':'#ef4444');
 $displayName = $r['name'] ?: ('user_'.$r['id']);
?>
<tr>
<td><?=$r['id']?><br><code style="font-size:10px"><?=htmlspecialchars(substr($r['uuid'],0,8))?>…</code></td>
<td><b><?=htmlspecialchars($displayName)?></b><br><small style="color:#94a3b8">tg:<?=htmlspecialchars($r['telegram_id']??'—')?> <?php if(!empty($r['email'])) echo ' | '.htmlspecialchars($r['email']); ?></small></td>
<td><span style="background:<?=$color?>;color:#000;padding:2px 8px;border-radius:10px;font-weight:700;font-size:11px"><?=$status?></span></td>
<td><?=number_format((int)($bal?:0))?></td>
<td>📱<?=$dcc?> 🤖<?=$bcc?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$r['id']?>"><select name="new_status"><option value="ACTIVE" <?=$status==='ACTIVE'?'selected':''?>>ACTIVE</option><option value="SUSPENDED" <?=$status==='SUSPENDED'?'selected':''?>>SUSPENDED</option><option value="DISABLED" <?=$status==='DISABLED'?'selected':''?>>DISABLED</option></select><button style="background:#334155;color:#e2e8f0">اعمال</button></form>
<form method="post"><input type="hidden" name="action" value="disconnect_bot"><input type="hidden" name="id" value="<?=$r['id']?>"><button style="background:#f59e0b;color:#000">قطع ربات</button></form>
<a href="wallets.php?customer_id=<?=$r['id']?>" style="background:#38bdf8;color:#082f49;padding:6px 8px;border-radius:6px;text-decoration:none;font-size:11px">کیف پول</a>
<form method="post" onsubmit="return confirm('حذف؟')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn3">حذف</button></form>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div></body></html>
