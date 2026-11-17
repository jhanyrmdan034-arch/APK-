<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??''; $id=(int)($_POST['id']??0);
    if($act==='deliver' && $id){
        $ord=$pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1"); $ord->execute([$id]); $o=$ord->fetch();
        if($o && $o['status']==='paid'){
            $apk=get_active_apk();
            if(!$apk) $msg='APK فعال موجود نیست.';
            else {
                $has=$pdo->prepare("SELECT id FROM apk_deliveries WHERE order_id=? LIMIT 1"); $has->execute([$id]);
                if($has->fetch()) $msg='قبلاً تحویل داده شده.';
                else {
                    $dl=create_apk_delivery((int)$o['customer_id'],(int)$apk['id'],$id);
                    $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$id]);
                    admin_log((int)$me['id'],'order_delivered','order',$id,['apk'=>$apk['version']]);
                    $msg="سفارش #$id تحویل شد — لینک: ".APP_URL."/api/delivery.php?t=".$dl['token'];
                    // ارسال به تلگرام اگر telegram_id دارد
                    $tg=$pdo->prepare("SELECT telegram_id FROM customers WHERE id=?"); $tg->execute([$o['customer_id']]); $tid=$tg->fetchColumn();
                    if($tid && SALES_BOT_TOKEN){
                        sales_bot_send('sendMessage',['chat_id'=>$tid,'text'=>"📦 سفارش #$id تحویل شد!\n📥 دانلود: ".APP_URL."/api/delivery.php?t=".$dl['token']]);
                    }
                }
            }
        }
    } elseif($act==='cancel' && $id){
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]);
        admin_log((int)$me['id'],'order_cancelled','order',$id,[]);
        $msg="سفارش #$id لغو شد.";
    }
}
$filter=$_GET['status']??'all';
$allowed=['pending','paid','delivered','cancelled','all'];
if(!in_array($filter,$allowed,true)) $filter='all';
if($filter==='all'){
    $rows=$pdo->query("SELECT o.*, c.email FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 100")->fetchAll();
} else {
    $stmt=$pdo->prepare("SELECT o.*, c.email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.status=? ORDER BY o.id DESC LIMIT 100");
    $stmt->execute([$filter]); $rows=$stmt->fetchAll();
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سفارش‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1200px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a class="active" href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت سفارش‌ها</h1>
<?php if($msg): ?><div class="card" style="background:#14532d"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="card">
<a href="orders.php?status=all" style="background:<?= $filter==='all'?'#38bdf8;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">همه</a>
<a href="orders.php?status=paid" style="background:<?= $filter==='paid'?'#22c55e;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">paid</a>
<a href="orders.php?status=delivered" style="background:<?= $filter==='delivered'?'#22c55e;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">delivered</a>
<a href="orders.php?status=pending" style="background:<?= $filter==='pending'?'#f59e0b;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">pending</a>
</div>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>مشتری</th><th>محصول</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th>تحویل</th><th>عملیات</th></tr>
<?php foreach($rows as $r):
 $del=$pdo->prepare("SELECT delivery_token FROM apk_deliveries WHERE order_id=? LIMIT 1"); $del->execute([$r['id']]); $d=$del->fetch();
?>
<tr>
<td>#<?=$r['id']?></td>
<td><?=htmlspecialchars($r['email'])?></td>
<td><?=htmlspecialchars($r['product_code'])?></td>
<td><?=number_format($r['amount'])?></td>
<td><?=$r['status']?></td>
<td><?=htmlspecialchars($r['created_at'])?></td>
<td><?php if($d): ?><a href="<?=htmlspecialchars(APP_URL)?>/api/delivery.php?t=<?=htmlspecialchars($d['delivery_token'])?>" style="color:#38bdf8" target="_blank">دانلود</a><?php else: ?>—<?php endif; ?></td>
<td>
<?php if($r['status']==='paid'): ?>
<form method="post" style="display:inline"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="deliver"><button style="background:#22c55e;color:#052e16;padding:6px 10px;border:0;border-radius:6px">تحویل APK</button></form>
<?php endif; ?>
<?php if($r['status']==='pending'): ?>
<form method="post" style="display:inline"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="cancel"><button style="background:#7f1d1d;color:#fecaca;padding:6px 10px;border:0;border-radius:6px">لغو</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">سفارشی نیست.</td></tr><?php endif; ?>
</table>
</div>
</div></body></html>
