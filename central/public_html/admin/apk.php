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
    if($act==='upload'){
        $version=sanitize_string($_POST['version']??'',32) ?: '1.0.0';
        $code=(int)($_POST['version_code']??1);
        $notes=sanitize_string($_POST['notes']??'',500);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if(empty($_FILES['apk']['tmp_name'])){
            $msg='فایل APK انتخاب نشده.'; $err=true;
        } elseif($_FILES['apk']['size'] > 100*1024*1024){
            $msg='حجم فایل بیش از 100MB.'; $err=true;
        } else {
            $tmp=$_FILES['apk']['tmp_name'];
            $orig=sanitize_string($_FILES['apk']['name']??'app.apk',255);
            // اعتبارسنجی: باید ZIP/APK باشد (PK header)
            $head=@file_get_contents($tmp, false, null, 0, 4);
            if($head===false || substr($head,0,2)!=='PK'){
                $msg='فایل APK معتبر نیست (PK header ندارد).'; $err=true;
            } else {
                $fname='BABARPHANTOM_'.$version.'_'.time().'.apk';
                $dest=APK_STORE_DIR.'/'.$fname;
                if(!move_uploaded_file($tmp,$dest)){
                    // fallback for test
                    if(!@copy($tmp,$dest)) { $msg='خطای ذخیره فایل.'; $err=true; }
                }
                if(!$err){
                    $size=filesize($dest);
                    $sha=hash_file('sha256',$dest);
                    if($isActive) $pdo->exec("UPDATE apk_files SET is_active=0");
                    $pdo->prepare("INSERT INTO apk_files (version,version_code,file_name,file_path,file_size,sha256,is_active,uploaded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$version,$code,$orig,$dest,$size,$sha,$isActive,$me['id'],$notes]);
                    admin_log((int)$me['id'],'apk_uploaded','apk',null,['version'=>$version,'size'=>$size,'sha'=>$sha]);
                    $msg="APK نسخه $version آپلود شد — SHA256: ".substr($sha,0,16)."... — فعال: ".($isActive?'بله':'خیر');
                }
            }
        }
    } elseif($act==='activate'){
        $id=(int)($_POST['id']??0);
        $pdo->exec("UPDATE apk_files SET is_active=0");
        $pdo->prepare("UPDATE apk_files SET is_active=1 WHERE id=?")->execute([$id]);
        admin_log((int)$me['id'],'apk_activated','apk',$id,[]);
        $msg="نسخه #$id فعال شد.";
    } elseif($act==='delete'){
        $id=(int)($_POST['id']??0);
        $row=$pdo->prepare("SELECT file_path FROM apk_files WHERE id=?"); $row->execute([$id]); $r=$row->fetch();
        if($r){
            // جلوگیری از حذف اگر delivery دارد
            $cnt=$pdo->prepare("SELECT COUNT(*) FROM apk_deliveries WHERE apk_file_id=?"); $cnt->execute([$id]); 
            if((int)$cnt->fetchColumn()>0){ $msg='نمی‌توان حذف کرد — تحویل‌های مرتبط دارد.'; $err=true; }
            else {
                @unlink($r['file_path']);
                $pdo->prepare("DELETE FROM apk_files WHERE id=?")->execute([$id]);
                admin_log((int)$me['id'],'apk_deleted','apk',$id,[]);
                $msg="حذف شد.";
            }
        }
    }
}
$rows=$pdo->query("SELECT * FROM apk_files ORDER BY id DESC")->fetchAll();
$active=get_active_apk();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>APK</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}input{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a class="active" href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت فایل‌های APK</h1>
<?php if($msg): ?><div class="card" style="background:<?=$err?'#7f1d1d':'#14532d'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="card">
<p style="font-size:12px;color:#94a3b8">APK آماده و تأییدشده را اینجا آپلود کنید. نسخهٔ فعال به‌صورت خودکار به سفارش‌های پرداخت‌شده تحویل داده می‌شود. توکن هرگز داخل APK نیست — شناسه اختصاصی مشتری (API Key) روی سرور به تحویل متصل است.</p>
<?php if($active): ?><p style="font-size:12px">نسخه فعال فعلی: <b><?=htmlspecialchars($active['version'])?></b> (code <?=$active['version_code']?>) — <?=htmlspecialchars($active['file_name'])?> — <?=round($active['file_size']/1024/1024,2)?> MB</p><?php else: ?><p style="color:#f87171">⚠️ هیچ نسخه فعالی تنظیم نشده.</p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
<input name="version" placeholder="نسخه مثل 1.0.0" required>
<input name="version_code" placeholder="versionCode مثل 1" type="number" min="1" value="1">
</div>
<input name="notes" placeholder="یادداشت نسخه (اختیاری)" style="width:100%;margin-top:8px">
<label style="display:block;margin-top:8px;font-size:12px"><input type="checkbox" name="is_active" checked> فعال برای تحویل خودکار</label>
<input type="file" name="apk" accept=".apk,application/vnd.android.package-archive" required style="margin-top:8px">
<button style="background:#22c55e;color:#052e16;padding:10px 16px;border:0;border-radius:8px;font-weight:800;margin-top:8px;width:100%">آپلود APK</button>
</form>
</div>
<div class="card" style="overflow:auto">
<table><tr><th>ID</th><th>نسخه</th><th>فایل</th><th>حجم</th><th>SHA256</th><th>فعال</th><th>تاریخ</th><th>تحویل‌ها</th><th>عملیات</th></tr>
<?php foreach($rows as $r):
 $cnt=$pdo->prepare("SELECT COUNT(*) FROM apk_deliveries WHERE apk_file_id=?"); $cnt->execute([$r['id']]); $c=(int)$cnt->fetchColumn();
?>
<tr>
<td><?=$r['id']?></td>
<td><?=htmlspecialchars($r['version'])?> (<?=$r['version_code']?>)</td>
<td><?=htmlspecialchars($r['file_name'])?><br><small style="color:#94a3b8"><?=htmlspecialchars(basename($r['file_path']))?></small></td>
<td><?=round($r['file_size']/1024/1024,2)?> MB</td>
<td><code style="font-size:10px"><?=htmlspecialchars(substr($r['sha256']??'',0,16))?>…</code></td>
<td><?= $r['is_active']?'✅':'—'?></td>
<td><?=htmlspecialchars($r['created_at'])?></td>
<td><?=$c?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<?php if(!$r['is_active']): ?><form method="post"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?=$r['id']?>"><button style="background:#334155;color:#e2e8f0;padding:6px 10px;border-radius:6px">فعال‌سازی</button></form><?php endif; ?>
<form method="post" onsubmit="return confirm('حذف؟')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 10px;border-radius:6px">حذف</button></form>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="9" style="text-align:center;color:#94a3b8">هنوز APK آپلود نشده — APK تأییدشده را بارگذاری کنید.</td></tr><?php endif; ?>
</table>
</div>
</div></body></html>
