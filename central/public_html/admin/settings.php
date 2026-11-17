<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_login();
if (!is_admin((int)$me['id'])) { http_response_code(403); exit('فقط مدیر کل'); }
$pdo=db();
$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='save') {
        $allow=['app_name','webview_url','poll_interval_sec','upload_interval_sec','max_devices_per_customer','maintenance'];
        foreach($allow as $k){
            if(isset($_POST[$k])){
                $v=sanitize_string($_POST[$k],500);
                $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")->execute([$k,$v,$v]);
            }
        }
        $msg='تنظیمات ذخیره شد.'; sec_log('settings_updated',(int)$me['id'],[]);
    } elseif ($action==='passwd') {
        $old=$_POST['old']??''; $new=$_POST['new']??'';
        if(strlen($new)<8) $msg='رمز جدید کوتاه است.';
        else {
            $s=$pdo->prepare("SELECT password_hash FROM customers WHERE id=?"); $s->execute([$me['id']]); $row=$s->fetch();
            if(!password_verify($old,$row['password_hash'])) $msg='رمز فعلی اشتباه است.';
            else { $pdo->prepare("UPDATE customers SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]),$me['id']]); $msg='رمز عوض شد.'; }
        }
    }
}
$settings=[]; foreach($pdo->query("SELECT k,v FROM settings") as $r) $settings[$r['k']]=$r['v'];
// Security log tail
$logs=$pdo->query("SELECT id, event_type, ip, details, created_at, customer_id FROM security_events ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیمات</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:12px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 12px;border-radius:8px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:900px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input,select{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700}pre{white-space:pre-wrap;font-size:11px;background:#0f172a;padding:10px;border-radius:8px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a class="active" href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>تنظیمات</h1>
<?php if($msg): ?><div class="card" style="background:#14532d"><?=$msg?></div><?php endif; ?>
<div class="card">
<h3>تنظیمات عمومی</h3>
<form method="post"><input type="hidden" name="action" value="save">
<label>نام اپ</label><input name="app_name" value="<?=htmlspecialchars($settings['app_name']??APP_NAME)?>">
<label>webview_url (برای APK)</label><input name="webview_url" value="<?=htmlspecialchars($settings['webview_url']??APP_URL)?>" dir="ltr">
<label>poll_interval_sec</label><input name="poll_interval_sec" value="<?=htmlspecialchars($settings['poll_interval_sec']??'5')?>">
<label>upload_interval_sec</label><input name="upload_interval_sec" value="<?=htmlspecialchars($settings['upload_interval_sec']??'30')?>">
<label>max_devices_per_customer</label><input name="max_devices_per_customer" value="<?=htmlspecialchars($settings['max_devices_per_customer']??'50')?>">
<label>maintenance (0/1)</label><select name="maintenance"><option value="0" <?=($settings['maintenance']??'0')==='0'?'selected':''?>>فعال</option><option value="1" <?=($settings['maintenance']??'0')==='1'?'selected':''?>>تعمیر</option></select>
<button style="background:#22c55e;color:#052e16;width:100%;margin-top:8px">ذخیره</button>
</form>
</div>
<div class="card">
<h3>تغییر رمز مدیر</h3>
<form method="post"><input type="hidden" name="action" value="passwd">
<input type="password" name="old" placeholder="رمز فعلی" required>
<input type="password" name="new" placeholder="رمز جدید (حداقل ۸)" required>
<button style="background:#38bdf8;color:#082f49;width:100%">تغییر رمز</button>
</form>
</div>
<div class="card">
<h3>بکاپ</h3>
<p style="font-size:12px;color:#94a3b8">دستور بکاپ DB: <code>mysqldump -u babar_user -p babar_central | gzip > backup_$(date +%Y%m%d).sql.gz</code></p>
<p style="font-size:12px;color:#94a3b8">بکاپ توکن‌ها: <code>tar czf tokens_backup.tgz private/telegram_tokens/</code> (رمزنگاری‌شده)</p>
</div>
<div class="card">
<h3>آخرین رویدادهای امنیتی (۵۰ مورد)</h3>
<pre><?php foreach($logs as $l) echo htmlspecialchars($l['created_at'].' ['.$l['event_type'].'] ip='.$l['ip'].' cid='.($l['customer_id']??'-').' '.($l['details']??''))."\n"; ?></pre>
</div>
</div></body></html>
