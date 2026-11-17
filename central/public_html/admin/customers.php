<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_login();
if (!is_admin((int)$me['id'])) { http_response_code(403); exit('فقط مدیر کل'); }
$pdo = db();
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
        $email=sanitize_string($_POST['email']??'',255); $name=sanitize_string($_POST['name']??'',255);
        $pass=$_POST['password']??''; $status=$_POST['status']??'active';
        if (!is_valid_email($email)) $msg='ایمیل نامعتبر';
        elseif (strlen($pass)<8) $msg='رمز کوتاه است';
        else {
            try {
                $uuid=sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff));
                $hash=password_hash($pass,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]);
                $pdo->prepare("INSERT INTO customers (uuid,email,password_hash,name,status) VALUES (?,?,?,?,?)")->execute([$uuid,$email,$hash,$name,$status]);
                $id=(int)$pdo->lastInsertId();
                $apiKey=bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO api_keys (customer_id,api_key) VALUES (?,?)")->execute([$id,$apiKey]);
                sec_log('customer_created',(int)$me['id'],['new_id'=>$id]);
                $msg="مشتری #$id ساخته شد — API Key: $apiKey";
            } catch (Throwable $e){ $msg='خطا: '.htmlspecialchars($e->getMessage()); }
        }
    } elseif ($action==='toggle') {
        $id=(int)($_POST['id']??0); $new=$_POST['new_status']??'active';
        $pdo->prepare("UPDATE customers SET status=? WHERE id=?")->execute([$new,$id]);
        sec_log('customer_status',(int)$me['id'],['target'=>$id,'status'=>$new]);
        $msg="وضعیت به $new تغییر کرد.";
    } elseif ($action==='delete') {
        $id=(int)($_POST['id']??0);
        if ($id!==(int)$me['id']) { $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]); $msg="حذف شد."; sec_log('customer_deleted',(int)$me['id'],['target'=>$id]); } else $msg='خودتان را نمی‌توانید حذف کنید.';
    }
}
$rows=$pdo->query("SELECT id,uuid,email,name,status,created_at FROM customers ORDER BY id DESC")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مشتریان</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:12px}.nav a{color:#94a3b8;text-decoration:none;padding:8px 12px;border-radius:8px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:13px}input,select{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}button{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}.btn2{background:#22c55e;color:#052e16;font-weight:700}.btn3{background:#7f1d1d;color:#fecaca}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a class="active" href="customers.php">مشتریان</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت مشتریان</h1>
<?php if($msg): ?><div class="card" style="background:#14532d;color:#bbf7d0"><?=$msg?></div><?php endif; ?>
<div class="card">
<h3>افزودن مشتری جدید</h3>
<form method="post"><input type="hidden" name="action" value="add">
<input name="email" placeholder="ایمیل" required> <input name="name" placeholder="نام">
<input type="password" name="password" placeholder="رمز عبور (حداقل ۸)" required>
<select name="status"><option value="active">فعال</option><option value="blocked">مسدود</option><option value="pending">در انتظار</option></select>
<button class="btn2" type="submit">افزودن</button>
</form>
<p style="font-size:12px;color:#94a3b8">هر مشتری UUID و API Key اختصاصی می‌گیرد. API Key را به APK بدهید (هرگز توکن ربات را در APK نگذارید).</p>
</div>
<div class="card">
<table><tr><th>ID</th><th>UUID</th><th>ایمیل / نام</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
<?php foreach($rows as $r): ?>
<tr>
<td><?=$r['id']?></td><td><code style="font-size:11px"><?=htmlspecialchars(substr($r['uuid'],0,8))?>…</code></td>
<td><?=htmlspecialchars($r['email'])?><br><small style="color:#94a3b8"><?=htmlspecialchars($r['name'])?></small></td>
<td><?=$r['status']==='active'?'✅ فعال':($r['status']==='blocked'?'⛔ مسدود':'⏳ انتظار')?></td>
<td><?=htmlspecialchars($r['created_at'])?></td>
<td>
<form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="new_status" value="<?=$r['status']==='active'?'blocked':'active'?>"><button type="submit" style="background:#334155;color:#e2e8f0"><?= $r['status']==='active'?'مسدود':'فعال'?></button></form>
<form method="post" style="display:inline" onsubmit="return confirm('حذف؟')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn3" type="submit">حذف</button></form>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div></body></html>
