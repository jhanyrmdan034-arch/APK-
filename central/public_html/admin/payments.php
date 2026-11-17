<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg=''; $err=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    $id=(int)($_POST['id']??0);
    $note=sanitize_string($_POST['admin_note']??'',500);
    if($act==='approve' && $id){
        $pdo->beginTransaction();
        try{
            $pr=$pdo->prepare("SELECT * FROM payment_requests WHERE id=? AND status='pending' LIMIT 1"); $pr->execute([$id]); $row=$pr->fetch();
            if(!$row) throw new RuntimeException('یافت نشد یا قبلاً بررسی شده');
            $pdo->prepare("UPDATE payment_requests SET status='approved', admin_note=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")->execute([$note,$me['id'],$id]);
            wallet_add_transaction((int)$row['customer_id'],'payment_approved',(int)$row['amount'],'payment_request',$id,'تأیید شارژ #'.$id,(int)$me['id']);
            admin_log((int)$me['id'],'payment_approved','payment',$id,['amount'=>$row['amount']]);
            $pdo->commit();
            // اطلاع به ربات فروش
            if(SALES_BOT_TOKEN){
                $c=$pdo->prepare("SELECT telegram_id FROM customers WHERE id=?"); $c->execute([$row['customer_id']]); $tg=$c->fetchColumn();
                if($tg) sales_bot_send('sendMessage',['chat_id'=>$tg,'text'=>"✅ پرداخت #$id تأیید شد! مبلغ ".number_format($row['amount'])." تومان به کیف پول اضافه شد."]);
            }
            $msg="پرداخت #$id تأیید و کیف پول شارژ شد.";
            // اگر سفارش pending با همین مبلغ دارد، خودکار پرداخت کند؟ (اختیاری)
        }catch(Throwable $e){ $pdo->rollBack(); $msg='خطا: '.$e->getMessage(); $err=true; }
    } elseif($act==='reject' && $id){
        $pdo->prepare("UPDATE payment_requests SET status='rejected', admin_note=?, reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status='pending'")->execute([$note,$me['id'],$id]);
        $cidTmp=$pdo->prepare("SELECT customer_id FROM payment_requests WHERE id=?"); $cidTmp->execute([$id]); wallet_add_transaction((int)($cidTmp->fetchColumn()?:0),'payment_rejected',0,'payment_request',$id,$note,(int)$me['id']);
        admin_log((int)$me['id'],'payment_rejected','payment',$id,['note'=>$note]);
        $msg="پرداخت #$id رد شد.";
        if(SALES_BOT_TOKEN){
            try{$c=$pdo->prepare("SELECT customer_id FROM payment_requests WHERE id=?"); $c->execute([$id]); $cid=$c->fetchColumn(); $tg=$pdo->prepare("SELECT telegram_id FROM customers WHERE id=?");$tg->execute([$cid]);$tid=$tg->fetchColumn(); if($tid) sales_bot_send('sendMessage',['chat_id'=>$tid,'text'=>"❌ پرداخت #$id رد شد. دلیل: $note"]);}catch(Throwable $x){}
        }
    }
}
$filter=sanitize_string($_GET['status']??'pending',20);
$allowed=['pending','approved','rejected','all'];
if(!in_array($filter,$allowed,true)) $filter='pending';
if($filter==='all'){
    $rows=$pdo->query("SELECT p.*, c.name, c.email FROM payment_requests p JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC LIMIT 100")->fetchAll();
} else {
    $stmt=$pdo->prepare("SELECT p.*, c.name, c.email FROM payment_requests p JOIN customers c ON c.id=p.customer_id WHERE p.status=? ORDER BY p.id DESC LIMIT 100");
    $stmt->execute([$filter]); $rows=$stmt->fetchAll();
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پرداخت‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1200px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}input,textarea{width:100%;padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;box-sizing:border-box}button{padding:7px 10px;border-radius:6px;border:0;cursor:pointer;font-size:12px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a class="active" href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت پرداخت‌ها (شارژ کیف پول)</h1>
<?php if($msg): ?><div class="card" style="background:<?=$err?'#7f1d1d':'#14532d'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="card">
<a href="payments.php?status=pending" style="background:<?= $filter==='pending'?'#f59e0b;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">pending</a>
<a href="payments.php?status=approved" style="background:<?= $filter==='approved'?'#22c55e;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">approved</a>
<a href="payments.php?status=rejected" style="background:<?= $filter==='rejected'?'#ef4444;color:#fff':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">rejected</a>
<a href="payments.php?status=all" style="background:<?= $filter==='all'?'#38bdf8;color:#000':'#334155;color:#e2e8f0'?>;padding:6px 10px;border-radius:6px;text-decoration:none">همه</a>
</div>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>مشتری</th><th>مبلغ</th><th>روش</th><th>وضعیت</th><th>رسید</th><th>تاریخ</th><th>عملیات (تأیید/رد)</th></tr>
<?php foreach($rows as $r): ?>
<tr>
<td>#<?=$r['id']?></td>
<td><?=htmlspecialchars($r['name']?: $r['email'])?> (#<?=$r['customer_id']?>)</td>
<td style="font-weight:700"><?=number_format($r['amount'])?></td>
<td><?=htmlspecialchars($r['method'])?></td>
<td><?=$r['status']==='pending'?'⏳ pending':($r['status']==='approved'?'✅ approved':'❌ rejected')?></td>
<td><?php if($r['receipt_path'] && file_exists($r['receipt_path'])): ?><a href="#" onclick="window.open('<?=htmlspecialchars(str_replace(PRIVATE_ROOT,'/private',$r['receipt_path']))?>','_blank');return false;" style="color:#38bdf8">مشاهده رسید</a> (<?=htmlspecialchars(basename($r['receipt_path']))?>)<?php else: ?>—<?php endif; ?></td>
<td><?=htmlspecialchars($r['created_at'])?></td>
<td>
<?php if($r['status']==='pending'): ?>
<form method="post" style="display:inline"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="admin_note" value="تأیید"><button style="background:#22c55e;color:#052e16">تأیید</button></form>
<form method="post" style="display:inline"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="reject"><input name="admin_note" placeholder="دلیل رد" style="width:100px;padding:4px"><button style="background:#ef4444;color:#fff">رد</button></form>
<?php else: ?>
<small style="color:#94a3b8"><?=htmlspecialchars($r['admin_note']??'—')?></small>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">موردی یافت نشد.</td></tr><?php endif; ?>
</table>
</div>
<div class="card" style="font-size:11px;color:#94a3b8">پس از تأیید، کیف پول به‌صورت خودکار شارژ و به مشتری در ربات فروش اطلاع داده می‌شود. مدیر فقط پرداخت‌های دستی را تأیید می‌کند.</div>
</div></body></html>
