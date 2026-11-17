<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='adjust'){
        $cid=(int)($_POST['customer_id']??0);
        $amount=(int)($_POST['amount']??0);
        $desc=sanitize_string($_POST['description']??'',200);
        if($cid && $amount!=0){
            $type = $amount>0 ? 'adjustment' : 'adjustment';
            wallet_add_transaction($cid,$type,$amount,'manual',null,$desc,(int)$me['id']);
            admin_log((int)$me['id'],'wallet_adjust','customer',$cid,['amount'=>$amount,'desc'=>$desc]);
            $msg="تراکنش $amount ثبت شد.";
        }
    }
}
$filterCid = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$where = $filterCid ? "WHERE w.customer_id=$filterCid" : "";
$rows=$pdo->query("SELECT w.customer_id, w.balance, c.name, c.status FROM wallets w JOIN customers c ON c.id=w.customer_id $where ORDER BY w.customer_id DESC LIMIT 100")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کیف پول</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}input,select{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a class="active" href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت کیف پول</h1>
<?php if($msg): ?><div class="card" style="background:#14532d"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="card">
<form method="post"><h3>شارژ/کسر دستی</h3>
<input type="hidden" name="action" value="adjust">
<input name="customer_id" placeholder="ID مشتری" required type="number">
<input name="amount" placeholder="مبلغ (+ شارژ / - کسر) مثل 500000" required type="number">
<input name="description" placeholder="توضیح">
<button style="background:#22c55e;color:#052e16;padding:8px 14px;border:0;border-radius:6px;font-weight:700">ثبت تراکنش</button>
</form>
</div>
<div class="card" style="overflow:auto">
<table><tr><th>ID مشتری</th><th>ایمیل / وضعیت</th><th>موجودی</th><th>آخرین تراکنش‌ها</th><th>عملیات</th></tr>
<?php foreach($rows as $r):
 $txs=$pdo->prepare("SELECT type,amount,balance_after,created_at FROM wallet_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 3");
 $txs->execute([$r['customer_id']]); $list=$txs->fetchAll();
?>
<tr>
<td><?=$r['customer_id']?></td>
<td><b><?=htmlspecialchars($r['name']?:'—')?></b><br><small style="color:#94a3b8"><?=$r['status']?></small></td>
<td style="font-weight:800;color:#22c55e"><?=number_format($r['balance'])?></td>
<td style="font-size:11px"><?php foreach($list as $t) echo htmlspecialchars($t['created_at']." ".$t['type']." ".number_format($t['amount'])." → ".number_format($t['balance_after']))."<br>"; if(!$list) echo "—";?></td>
<td><a href="wallets.php?customer_id=<?=$r['customer_id']?>" style="color:#38bdf8">فیلتر</a> | <a href="customers.php" style="color:#94a3b8">مشتریان</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<div class="card" style="font-size:11px;color:#94a3b8">برای مشاهده همه تراکنش‌ها: <code>SELECT * FROM wallet_transactions WHERE customer_id=? ORDER BY id DESC</code></div>
</div></body></html>
