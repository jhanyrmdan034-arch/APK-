<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg=''; $err=false;

function ini_bytes_apk(string $v): int {
    $v=trim($v); $u=strtolower(substr($v,-1)); $n=(int)$v;
    if ($u==='g') $n*=1024*1024*1024; elseif($u==='m') $n*=1024*1024; elseif($u==='k') $n*=1024;
    return $n;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='upload'){
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') || (($_POST['ajax'] ?? '')==='1');
        $version=sanitize_string($_POST['version']??'',32) ?: '1.0.0';
        $code=(int)($_POST['version_code']??1);
        if($code<1) $code=1;
        $notes=sanitize_string($_POST['notes']??'',500);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // post_max_size exceeded?
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $msg='حجم فایل از حد مجاز هاست بیشتر است — post_max_size='.ini_get('post_max_size').' / upload_max_filesize='.ini_get('upload_max_filesize').' — هاست را 50M کن';
            $err=true;
            if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
        } else {
            $upErr = $_FILES['apk']['error'] ?? UPLOAD_ERR_NO_FILE;
            if($upErr !== UPLOAD_ERR_OK){
                $map = [
                    UPLOAD_ERR_INI_SIZE=>'حجم فایل بیشتر از حد مجاز است (upload_max_filesize='.ini_get('upload_max_filesize').')',
                    UPLOAD_ERR_FORM_SIZE=>'حجم از MAX_FILE_SIZE بیشتر است',
                    UPLOAD_ERR_PARTIAL=>'آپلود ناقص — ارتباط قطع شد',
                    UPLOAD_ERR_NO_FILE=>'فایلی انتخاب نشده',
                    UPLOAD_ERR_NO_TMP_DIR=>'پوشه موقت موجود نیست — با پشتیبان هاست تماس بگیر',
                    UPLOAD_ERR_CANT_WRITE=>'فضای ذخیره‌سازی کافی نیست — دیسک پر است',
                    UPLOAD_ERR_EXTENSION=>'افزونه مانع آپلود شد',
                ];
                $detail = $map[$upErr] ?? "خطا کد $upErr";
                if($upErr===UPLOAD_ERR_INI_SIZE) $detail="حجم فایل بیشتر از حد مجاز است — ".ini_get('upload_max_filesize')." — از cPanel → Select PHP Version → upload_max_filesize را 50M کن";
                elseif($upErr===UPLOAD_ERR_CANT_WRITE) $detail="فضای ذخیره‌سازی کافی نیست — فضای هاست پر است";
                $msg="❌ $detail"; $err=true;
                $logDir = defined('LOG_DIR')?LOG_DIR:dirname(__DIR__,2).'/logs';
                if(is_dir($logDir)) @file_put_contents($logDir.'/upload.log', date('c')." apk.php err=$upErr name=".($_FILES['apk']['name']??'')." size=".($_FILES['apk']['size']??0)."\n", FILE_APPEND);
                if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
            } elseif(empty($_FILES['apk']['tmp_name']) || !is_uploaded_file($_FILES['apk']['tmp_name'])){
                $msg='❌ فایل APK انتخاب نشده یا مرورگر فایل را نفرستاد — دوباره از Downloads انتخاب کن'; $err=true;
                if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
            } else {
                $tmp=$_FILES['apk']['tmp_name'];
                $orig=$_FILES['apk']['name']??'app.apk';
                $size=(int)($_FILES['apk']['size']??0);
                $real=@filesize($tmp); if($real!==false && $real>0) $size=$real;
                $maxBytes=defined('UPLOAD_MAX_BYTES')?UPLOAD_MAX_BYTES:50*1024*1024;
                $hostUp=ini_bytes_apk(ini_get('upload_max_filesize')?:'2M');
                $hostPost=ini_bytes_apk(ini_get('post_max_size')?:'8M');
                $msgErr='';
                if($size > $maxBytes) $msgErr="حجم فایل بیشتر از حد مجاز است — ".round($size/1024/1024,2)."MB > ".round($maxBytes/1024/1024)."MB";
                elseif($size > $hostUp) $msgErr="حجم فایل بیشتر از حد مجاز هاست است — ".round($size/1024/1024,2)."MB > upload_max_filesize=".ini_get('upload_max_filesize');
                elseif($size===0) $msgErr="فایل خالی است";
                elseif(strtolower(pathinfo($orig,PATHINFO_EXTENSION))!=='apk') $msgErr="فرمت فایل معتبر نیست — فقط .apk (فایل: ".h($orig).")";
                else{
                    $head=@file_get_contents($tmp,false,null,0,4);
                    if($head===false || substr($head,0,2)!=='PK') $msgErr="فرمت فایل معتبر نیست — APK باید ZIP باشد (PK header) — فایل: ".h($orig);
                }
                // disk
                $storeDir=defined('APK_STORE_DIR')?APK_STORE_DIR:dirname(__DIR__,2).'/apk';
                $free=@disk_free_space(dirname($storeDir));
                if(!$msgErr && $free!==false && $free < $size + 5*1024*1024) $msgErr="فضای ذخیره‌سازی کافی نیست — آزاد: ".round($free/1024/1024,1)."MB";

                if($msgErr){
                    $msg="❌ $msgErr"; $err=true;
                    if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
                } else {
                    if(!is_dir($storeDir)){ @mkdir($storeDir,0775,true); @chmod($storeDir,0775);}
                    if(!is_writable($storeDir)){ @chmod($storeDir,0775); @chmod(dirname($storeDir),0775);}
                    if(!is_dir($storeDir) || !is_writable($storeDir)){
                        $msg='❌ مجوز نوشتن در پوشه وجود ندارد — '.h($storeDir).' — File Manager → private/apk → Permissions → 0775'; $err=true;
                        if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
                    } else {
                        $safeVer=preg_replace('/[^A-Za-z0-9._-]/','_',$version);
                        $fname='BABARPHANTOM_'.$safeVer.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(6)).'.apk';
                        $dest=rtrim($storeDir,'/').'/'.$fname;
                        $moved=@move_uploaded_file($tmp,$dest);
                        if(!$moved) $moved=@copy($tmp,$dest) && @unlink($tmp);
                        if(!$moved && is_readable($tmp)){ $d=@file_get_contents($tmp); if($d!==false) $moved=@file_put_contents($dest,$d)!==false; }
                        if(!$moved || !file_exists($dest)){
                            $last=error_get_last();
                            $msg='❌ خطای ذخیره فایل — '.h($last['message']??'مجوز یا فضا را چک کن'); $err=true;
                            if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
                        } else {
                            @chmod($dest,0644);
                            $fsize=@filesize($dest); if($fsize===false) $fsize=$size;
                            $sha=@hash_file('sha256',$dest) ?: hash('sha256',(string)time());
                            try{
                                $pdo->beginTransaction();
                                if($isActive) $pdo->exec("UPDATE apk_files SET is_active=0");
                                // if not active but no active exists, make first active? keep as requested
                                $pdo->prepare("INSERT INTO apk_files (version,version_code,file_name,file_path,file_size,sha256,is_active,uploaded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                                    ->execute([$version,$code,$orig,$dest,$fsize,$sha,$isActive,$me['id'],$notes]);
                                $nid=(int)$pdo->lastInsertId();
                                $pdo->commit();
                                admin_log((int)$me['id'],'apk_uploaded','apk',$nid,['version'=>$version,'size'=>$fsize,'sha'=>substr($sha,0,16),'file'=>$fname,'active'=>$isActive]);
                                $msg="آپلود با موفقیت انجام شد — نسخه $version — ".round($fsize/1024/1024,2)." MB — SHA256: ".substr($sha,0,12)."... — فعال: ".($isActive?'بله':'خیر');
                                $err=false;
                                if($isAjax) json_out(['ok'=>true,'msg'=>$msg,'data'=>['id'=>$nid,'version'=>$version]],200);
                            }catch(Throwable $e){
                                try{$pdo->rollBack();}catch(Throwable $x){}
                                @unlink($dest);
                                $msg='❌ خطای دیتابیس رخ داده است — '.$e->getMessage(); $err=true;
                                if($isAjax) json_out(['ok'=>false,'msg'=>$msg],400);
                            }
                        }
                    }
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
$hostInfo=['upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size'),'max_execution_time'=>ini_get('max_execution_time'),'max_input_time'=>ini_get('max_input_time'),'memory_limit'=>ini_get('memory_limit'),'upload_max_bytes'=>defined('UPLOAD_MAX_BYTES')?round(UPLOAD_MAX_BYTES/1024/1024).'M':'50M'];
$apkStoreDir=defined('APK_STORE_DIR')?APK_STORE_DIR:'private/apk';
$writable=is_dir($apkStoreDir)?(is_writable($apkStoreDir)?'✅ قابل نوشتن':'❌ غیرقابل نوشتن — 0775 کن'):'❌ وجود ندارد';
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>APK</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:right;border-bottom:1px solid #334155;font-size:12px}input{padding:8px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}.progress-wrap{height:18px;background:#0f172a;border:1px solid #334155;border-radius:999px;overflow:hidden;position:relative}.progress-bar{height:100%;width:0%;background:linear-gradient(90deg,#22c55e,#16a34a);transition:width .2s}.progress-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a class="active" href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت فایل‌های APK</h1>
<?php if($msg): ?><div class="card" style="background:<?=$err?'#7f1d1d':'#14532d'?><?=!$err?';color:#bbf7d0':';color:#fecaca'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="card" style="background:#0f172a;border:1px solid #334155">
<details open><summary style="cursor:pointer;font-weight:800;font-size:13px">⚙️ وضعیت سرور</summary>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:8px;font-size:11px">
<div>upload_max_filesize: <b><?=h($hostInfo['upload_max_filesize'])?></b></div>
<div>post_max_size: <b><?=h($hostInfo['post_max_size'])?></b></div>
<div>max_execution: <b><?=h($hostInfo['max_execution_time'])?>s</b></div>
<div>max_input: <b><?=h($hostInfo['max_input_time'])?>s</b></div>
<div>memory: <b><?=h($hostInfo['memory_limit'])?></b></div>
<div>حد برنامه: <b><?=h($hostInfo['upload_max_bytes'])?></b></div>
<div>private/apk: <b><?=h($writable)?></b></div>
<div>مسیر: <code><?=h($apkStoreDir)?></code></div>
</div>
<p style="font-size:11px;color:#94a3b8;margin-top:6px">اگر محدودیت کم است: cPanel → Select PHP Version → Options → upload_max_filesize/post_max_size=50M/55M، max_execution_time=300</p>
</details>
</div>

<div class="card">
<p style="font-size:12px;color:#94a3b8">APK آماده و تأییدشده را اینجا آپلود کنید. نسخهٔ فعال به‌صورت خودکار به سفارش‌های پرداخت‌شده تحویل داده می‌شود. توکن هرگز داخل APK نیست — شناسه اختصاصی مشتری (API Key) روی سرور به تحویل متصل است. فایل خارج از public_html در private/apk ذخیره می‌شود.</p>
<?php if($active): ?><p style="font-size:12px">نسخه فعال فعلی: <b><?=htmlspecialchars($active['version'])?></b> (code <?=$active['version_code']?>) — <?=htmlspecialchars($active['file_name'])?> — <?=round($active['file_size']/1024/1024,2)?> MB — SHA256 <?=htmlspecialchars(substr($active['sha256']??'',0,12))?>...</p><?php else: ?><p style="color:#f87171">⚠️ هیچ نسخه فعالی تنظیم نشده.</p><?php endif; ?>
<form id="apkForm" method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
<input name="version" id="apkVersion" placeholder="نسخه مثل 1.0.0" required value="1.0.0">
<input name="version_code" placeholder="versionCode مثل 1" type="number" min="1" value="<?= (count($rows)? max(array_column($rows,'version_code'))+1 : 1) ?>">
</div>
<input name="notes" placeholder="یادداشت نسخه (اختیاری)" style="width:100%;margin-top:8px">
<label style="display:block;margin-top:8px;font-size:12px"><input type="checkbox" name="is_active" checked> فعال برای تحویل خودکار (اگر تیک نخورد، ذخیره می‌شود و بعدا فعال می‌کنی — قبلی حذف نمی‌شود)</label>
<label for="apkFile" style="display:block;margin-top:8px;padding:10px;border:1px dashed #475569;border-radius:8px;text-align:center;cursor:pointer;background:#0f172a">
<span style="font-size:13px">📁 انتخاب فایل APK</span><br><small style="color:#94a3b8">فقط .apk — تست شده روی Chrome اندروید (Downloads)</small>
<input type="file" id="apkFile" name="apk" accept=".apk,application/vnd.android.package-archive,application/zip,application/octet-stream" required style="margin-top:6px;width:100%">
</label>
<div id="apkFileInfo" style="font-size:11px;color:#94a3b8;margin-top:4px"></div>
<div id="apkProgressWrap" style="display:none;margin-top:8px"><div class="progress-wrap"><div id="apkProgressBar" class="progress-bar"></div><span id="apkProgressText" class="progress-text">0%</span></div></div>
<div id="apkStatus" style="font-size:12px;margin-top:6px;min-height:18px"></div>
<button id="apkBtn" type="submit" style="background:#22c55e;color:#052e16;padding:10px 16px;border:0;border-radius:8px;font-weight:800;margin-top:8px;width:100%">آپلود APK</button>
<small style="color:#94a3b8">ارسال با multipart/form-data و FormData (نه JSON) — نوار پیشرفت، دکمه تا پایان غیرفعال، جلوگیری از دوبار ارسال</small>
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
<?php if(!$rows): ?><tr><td colspan="9" style="text-align:center;color:#94a3b8">هنوز APK آپلود نشده.</td></tr><?php endif; ?>
</table>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var form=document.getElementById('apkForm');
  if(!form) return;
  var fileInput=document.getElementById('apkFile');
  var info=document.getElementById('apkFileInfo');
  var btn=document.getElementById('apkBtn');
  var wrap=document.getElementById('apkProgressWrap');
  var bar=document.getElementById('apkProgressBar');
  var txt=document.getElementById('apkProgressText');
  var status=document.getElementById('apkStatus');
  var uploading=false;
  fileInput.addEventListener('change', function(){
    var f=fileInput.files[0];
    info.textContent = f ? 'انتخاب شد: '+f.name+' — '+(f.size/1024/1024).toFixed(2)+' MB'+(f.name.toLowerCase().endsWith('.apk')?' ✅':' ❌ فقط .apk') : '';
    status.textContent='';
  });
  form.addEventListener('submit', function(e){
    var f=fileInput.files[0];
    if(!f){ status.textContent='❌ فایلی انتخاب نشده'; status.style.color='#fecaca'; e.preventDefault(); return; }
    if(!f.name.toLowerCase().endsWith('.apk')){ status.textContent='❌ فرمت فایل معتبر نیست — فقط .apk'; status.style.color='#fecaca'; e.preventDefault(); return; }
    if(f.size>50*1024*1024){ status.textContent='❌ حجم فایل بیشتر از حد مجاز است — '+(f.size/1024/1024).toFixed(1)+'MB > 50MB'; status.style.color='#fecaca'; e.preventDefault(); return; }
    if(f.size===0){ status.textContent='❌ فایل خالی است'; status.style.color='#fecaca'; e.preventDefault(); return; }
    if(uploading){ e.preventDefault(); return; }
    if(window.XMLHttpRequest && window.FormData){
      e.preventDefault();
      uploading=true; btn.disabled=true; btn.textContent='⏳ در حال آپلود...';
      wrap.style.display='block'; bar.style.width='0%'; txt.textContent='0%';
      status.textContent='در حال ارسال به سرور...'; status.style.color='#94a3b8';
      var fd=new FormData(form);
      var xhr=new XMLHttpRequest();
      xhr.open('POST', window.location.href, true);
      xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
      xhr.upload.onprogress=function(ev){ if(ev.lengthComputable){ var p=Math.round(ev.loaded/ev.total*100); bar.style.width=p+'%'; txt.textContent=p+'%'; } };
      xhr.onload=function(){
        uploading=false; btn.disabled=false; btn.textContent='آپلود APK';
        if(xhr.status>=200 && xhr.status<300){
          var t=xhr.responseText||'';
          try{ var j=JSON.parse(t); if(j.ok){ status.textContent='✅ '+j.msg; status.style.color='#bbf7d0'; bar.style.width='100%'; txt.textContent='100%'; setTimeout(function(){location.reload()},1100); } else { status.textContent='❌ '+j.msg; status.style.color='#fecaca'; wrap.style.display='none'; } }
          catch(ex){ if(t.indexOf('آپلود با موفقیت')!==-1 || t.indexOf('✅')!==-1){ status.textContent='✅ آپلود انجام شد — در حال به‌روزرسانی...'; status.style.color='#bbf7d0'; bar.style.width='100%'; txt.textContent='100%'; setTimeout(function(){location.reload()},900);} else { var m=t.match(/class="card"[^>]*>([^<]+)/); status.textContent=m?'❌ '+m[1].trim():'❌ خطای سرور — دوباره تلاش کن'; status.style.color='#fecaca'; wrap.style.display='none'; } }
        }else if(xhr.status===413){ status.textContent='❌ حجم فایل بیشتر از حد مجاز هاست (413)'; status.style.color='#fecaca'; wrap.style.display='none'; }
        else if(xhr.status===0){ status.textContent='❌ ارتباط با سرور قطع شد'; status.style.color='#fecaca'; wrap.style.display='none'; }
        else{ status.textContent='❌ خطای سرور کد '+xhr.status; status.style.color='#fecaca'; wrap.style.display='none'; }
      };
      xhr.onerror=function(){ uploading=false; btn.disabled=false; btn.textContent='آپلود APK'; status.textContent='❌ ارتباط با سرور قطع شد — اینترنت را چک کن'; status.style.color='#fecaca'; wrap.style.display='none'; };
      xhr.send(fd);
    } else { uploading=true; btn.disabled=true; btn.textContent='⏳ در حال آپلود...'; }
  });
});
</script>
</body></html>
