<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg=''; $err=false; $result=null;

// --- آپلود سریع APK در همین صفحه (برای راحتی) ---
if (($_POST['action'] ?? '') === 'upload_apk') {
    $version = sanitize_string($_POST['version'] ?? '', 32) ?: '1.0.0';
    $code = (int)($_POST['version_code'] ?? 1);
    $isActive = 1;
    $upErr = $_FILES['apk']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upErr !== UPLOAD_ERR_OK) {
        $map = [1=>'حجم بیش از upload_max_filesize',2=>'حجم بیش از MAX_FILE_SIZE',3=>'ناقص',4=>'فایلی انتخاب نشده',6=>'پوشه موقت نیست',7=>'خطای دیسک',8=>'افزونه'];
        $msg='خطای آپلود APK: '.($map[$upErr] ?? "کد $upErr"); $err=true;
    } elseif (empty($_FILES['apk']['tmp_name']) || !is_uploaded_file($_FILES['apk']['tmp_name'])) {
        $msg='فایل APK انتخاب نشده'; $err=true;
    } else {
        $tmp = $_FILES['apk']['tmp_name'];
        if (!is_dir(APK_STORE_DIR)) @mkdir(APK_STORE_DIR, 0775, true);
        if (!is_writable(APK_STORE_DIR)) @chmod(APK_STORE_DIR, 0775);
        $head = @file_get_contents($tmp, false, null, 0, 2);
        if ($head === false || substr($head,0,2) !== 'PK') { $msg='فایل APK معتبر نیست (PK header ندارد)'; $err=true; }
        else {
            $fname='BABARPHANTOM_'.$version.'_'.time().'.apk';
            $dest=(is_writable(APK_STORE_DIR)?APK_STORE_DIR:(__DIR__.'/../uploads_apk')).'/'.$fname;
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest),0775,true);
            $moved=@move_uploaded_file($tmp,$dest) ?: @copy($tmp,$dest);
            if (!$moved && is_readable($tmp)) { $d=@file_get_contents($tmp); if($d!==false) $moved=@file_put_contents($dest,$d)!==false; }
            if (!$moved) { $msg='ذخیره ناموفق — مجوز پوشه private/apk را 0755 کن'; $err=true; }
            else {
                @chmod($dest,0644);
                $size=filesize($dest) ?: (int)($_FILES['apk']['size']??0);
                $sha=@hash_file('sha256',$dest) ?: hash('sha256',(string)time());
                $pdo->exec("UPDATE apk_files SET is_active=0");
                $pdo->prepare("INSERT INTO apk_files (version,version_code,file_name,file_path,file_size,sha256,is_active,uploaded_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$version,$code,($_FILES['apk']['name']??'app.apk'),$dest,$size,$sha,1,$me['id']]);
                $msg="✅ APK $version آپلود شد — حالا از لیست انتخاب کن"; $err=false;
            }
        }
    }
}
// لیست APKها و تحویل‌های اخیر
$apkFiles = $pdo->query("SELECT id,version,version_code,file_name,file_size,is_active FROM apk_files ORDER BY id DESC")->fetchAll();
$activeApk = get_active_apk();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    // --- ساخت مشتری و بسته تحویل (فقط نام کاربری + توکن) ---
    if ($act==='build') {
        $username = sanitize_string(trim($_POST['username'] ?? ''), 32);
        $token = trim($_POST['bot_token'] ?? '');
        $apkId = (int)($_POST['apk_id'] ?? 0);
        if ($username === '' || $token === '') { $msg='نام کاربری و توکن الزامی است'; $err=true; }
        elseif (!is_valid_username($username)) { $msg='نام کاربری نامعتبر: 3-32 کاراکتر (حروف/عدد/زیرخط/خط‌تیره)'; $err=true; }
        elseif (!is_valid_bot_token($token)) { $msg='فرمت توکن نامعتبر (مثال: 123456789:AAH...)'; $err=true; }
        elseif (!$apkId) { $msg='فایل APK انتخاب نشده'; $err=true; }
        else {
            $pdo->beginTransaction();
            try {
                // جلوگیری از تکراری
                $dup = $pdo->prepare("SELECT id FROM customers WHERE name=? LIMIT 1");
                $dup->execute([$username]);
                if ($dup->fetch()) throw new RuntimeException('نام کاربری تکراری است — مشتری با این نام وجود دارد');
                // اعتبارسنجی توکن با تلگرام
                $url='https://api.telegram.org/bot'.$token.'/getMe';
                $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]); $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                $j=json_decode($resp,true);
                if ($code!==200 || empty($j['ok'])) throw new RuntimeException('توکن نامعتبر (getMe ناموفق): '.substr($resp??'',0,300));
                $botUsername=$j['result']['username'] ?? null;
                $botId=$j['result']['id'] ?? null;
                // نمایش مشخصات ربات قبل از ذخیره — برای سادگی در همین تراکنش ذخیره می‌کنیم و در نتیجه نمایش می‌دهیم
                // ایجاد مشتری (بدون ایمیل و رمز)
                $cust = create_customer_by_username($username);
                $cid = (int)$cust['id'];
                // رمزنگاری و ذخیره توکن (فقط سرور)
                $enc=encrypt_token($token);
                $pdo->prepare("INSERT INTO telegram_bots (customer_id,bot_username,token_encrypted,token_nonce,is_connected,last_checked_at) VALUES (?,?,?,?,1,NOW())")
                    ->execute([$cid,$botUsername,$enc['ct'],$enc['nonce']]);
                $file=TOKEN_STORE_DIR.'/'.$cid.'.enc';
                file_put_contents($file, json_encode(['ct'=>$enc['ct'],'nonce'=>$enc['nonce'],'username'=>$botUsername],JSON_UNESCAPED_UNICODE), LOCK_EX);
                @chmod($file,0600);
                // APK
                $apk=$pdo->prepare("SELECT * FROM apk_files WHERE id=? LIMIT 1"); $apk->execute([$apkId]); $apkRow=$apk->fetch();
                if(!$apkRow) throw new RuntimeException('APK یافت نشد');
                if(!file_exists($apkRow['file_path'])) throw new RuntimeException('فایل APK روی سرور موجود نیست');
                // سفارش حسابرسی
                $pdo->prepare("INSERT INTO orders (customer_id,product_code,amount,status,paid_at,delivered_at) VALUES (?,?,?, 'delivered', NOW(), NOW())")
                    ->execute([$cid,'manual_delivery',0]);
                $orderId=(int)$pdo->lastInsertId();
                $dl=create_apk_delivery($cid,(int)$apkRow['id'],$orderId);
                $pdo->prepare("UPDATE orders SET api_key_id=(SELECT id FROM api_keys WHERE api_key=? LIMIT 1) WHERE id=?")->execute([$dl['api_key'],$orderId]);
                $pdo->commit();
                admin_log((int)$me['id'],'manual_delivery_created','customer',$cid,['username'=>$username,'apk'=>$apkRow['version'],'order'=>$orderId,'bot'=>$botUsername]);
                sec_log('manual_delivery_created',$cid,['order'=>$orderId,'apk'=>$apkRow['version']]);
                $result=[
                    'customer'=>['id'=>$cid,'name'=>$username,'uuid'=>$cust['uuid']],
                    'bot_username'=>$botUsername,
                    'bot_id'=>$botId,
                    'apk'=>$apkRow,
                    'order_id'=>$orderId,
                    'delivery'=>$dl,
                    'url'=>APP_URL.'/api/delivery.php?t='.$dl['token'],
                ];
                $msg="✅ مشتری '$username' (@$botUsername) ساخته شد و بسته تحویل آماده شد.";
            } catch(Throwable $e){
                $pdo->rollBack();
                $msg='خطا: '.$e->getMessage(); $err=true;
            }
        }
    }
    // --- لغو / صدور مجدد ---
    elseif ($act==='revoke') {
        $did=(int)($_POST['delivery_id']??0);
        $pdo->prepare("UPDATE apk_deliveries SET expires_at=NOW() - INTERVAL 1 DAY WHERE id=?")->execute([$did]);
        $rk=$pdo->prepare("SELECT api_key FROM apk_deliveries WHERE id=?"); $rk->execute([$did]); $k=$rk->fetchColumn();
        if($k) $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$k]);
        admin_log((int)$me['id'],'delivery_revoked','delivery',$did,[]);
        $msg="لینک #$did لغو شد.";
    } elseif ($act==='reissue') {
        $did=(int)($_POST['delivery_id']??0);
        $r=$pdo->prepare("SELECT * FROM apk_deliveries WHERE id=? LIMIT 1"); $r->execute([$did]); $del=$r->fetch();
        if(!$del){$msg='تحویل یافت نشد';$err=true;}
        else{
            $newTok=generate_delivery_token(); $newKey=generate_api_key();
            $pdo->prepare("UPDATE apk_deliveries SET delivery_token=?, api_key=?, expires_at=DATE_ADD(NOW(), INTERVAL ? DAY), download_count=0 WHERE id=?")->execute([$newTok,$newKey,APK_DELIVERY_EXPIRES_DAYS,$did]);
            $pdo->prepare("INSERT INTO api_keys (customer_id,api_key,name) VALUES (?,?,?)")->execute([$del['customer_id'],$newKey,'apk_reissue_'.$did]);
            $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$del['api_key']]);
            admin_log((int)$me['id'],'delivery_reissued','delivery',$did,['new_token'=>substr($newTok,0,8).'...']);
            $msg="✅ لینک جدید: ".APP_URL."/api/delivery.php?t=".$newTok." — کلید: ".$newKey;
        }
    }
}

$recentDeliveries=$pdo->query("SELECT d.id, d.delivery_token, d.api_key, d.download_count, d.expires_at, d.created_at, c.name as customer_name, a.version FROM apk_deliveries d JOIN customers c ON c.id=d.customer_id JOIN apk_files a ON a.id=d.apk_file_id ORDER BY d.id DESC LIMIT 20")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ساخت و تحویل دستی APK</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:900px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input,select{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700;font-size:13px}label{font-size:13px;color:#cbd5e1}code{background:#0f172a;padding:2px 6px;border-radius:4px;word-break:break-all}.alert{padding:10px;border-radius:8px;margin-bottom:12px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="apk.php">APK</a><a href="template.php">قالب دلخواه</a><a class="active" href="delivery_builder.php">ساخت دستی</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>ساخت و تحویل دستی APK — ساده‌شده</h1>
<p style="color:#94a3b8;font-size:12px">فقط دو فیلد: نام کاربری مشتری + توکن ربات. APK عمومی است، نیازی به کامپایل مجدد نیست؛ دسترسی در سرور مدیریت می‌شود و توکن هرگز در APK نیست.</p>

<?php if($msg): ?><div class="alert" style="background:<?=$err?'#7f1d1d;color:#fecaca':'#14532d;color:#bbf7d0'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<?php if($result): ?>
<div class="card" style="border:2px solid #22c55e">
<h3 style="color:#22c55e">✅ مشتری و بسته تحویل ساخته شد</h3>
<p>👤 نام کاربری: <b><?=htmlspecialchars($result['customer']['name'])?> (#<?=$result['customer']['id']?>)</b> — ربات: <b>@<?=htmlspecialchars($result['bot_username'])?></b> (ID: <?=htmlspecialchars($result['bot_id']??'—')?>)</p>
<p>APK: <?=htmlspecialchars($result['apk']['version'])?> — <?=htmlspecialchars($result['apk']['file_name'])?> (<?=round($result['apk']['file_size']/1024/1024,2)?> MB)</p>
<p>سفارش: #<?=htmlspecialchars($result['order_id'])?></p>
<p>🔗 <b>لینک دانلود اختصاصی:</b><br><code><?=htmlspecialchars($result['url'])?></code></p>
<p>🔑 <b>API Key اختصاصی:</b> <code><?=htmlspecialchars($result['delivery']['api_key'])?></code></p>
<p>⏳ انقضا: <?=htmlspecialchars($result['delivery']['expires_at'])?></p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
<a href="<?=htmlspecialchars($result['url'])?>" target="_blank" style="background:#22c55e;color:#052e16;padding:10px 16px;border-radius:8px;text-decoration:none">📥 دانلود APK</a>

</div>
<p style="font-size:11px;color:#94a3b8;margin-top:8px">اگر مشتری telegram_id ندارد، لینک را دستی بفرستید.</p>
</div>
<?php endif; ?>

<div class="card">
<h3>ساخت مشتری و بسته تحویل</h3>
<form method="post">
<input type="hidden" name="action" value="build">
<label>نام کاربری مشتری *</label>
<input name="username" placeholder="مثال: ali_123" required maxlength="32" dir="ltr">
<small style="color:#94a3b8">3-32 کاراکتر انگلیسی/عدد/زیرخط/خط‌تیره، یکتا</small>

<label>توکن ربات تلگرام مشتری *</label>
<input name="bot_token" placeholder="123456789:AAH..." required dir="ltr">
<small style="color:#fbbf24">⚠️ فقط سرور ذخیره رمزنگاری می‌کند؛ در APK/لینک/لاگ plaintext نمی‌ماند. اعتبارسنجی با getMe انجام می‌شود.</small>

<label>فایل APK عمومی *</label>
<?php if(!$apkFiles): ?>
<div style="background:#7f1d1d;padding:10px;border-radius:8px;margin:6px 0;font-size:12px">⚠️ هنوز APK آپلود نشده — همینجا آپلود کن:</div>
<form method="post" enctype="multipart/form-data" style="background:#0f172a;padding:10px;border-radius:8px;margin:6px 0">
<input type="hidden" name="action" value="upload_apk">
<div style="display:flex;gap:6px;flex-wrap:wrap">
<input name="version" placeholder="نسخه مثل 1.0.0" value="1.0.0" required style="flex:1">
<input name="version_code" type="number" value="1" min="1" style="width:90px">
</div>
<input type="file" name="apk" accept=".apk,application/vnd.android.package-archive" required>
<button style="background:#f59e0b;color:#000;width:100%;margin-top:6px">📤 آپلود سریع APK همینجا</button>
</form>
<p style="text-align:center;color:#94a3b8;font-size:11px">یا از <a href="apk.php" style="color:#38bdf8">صفحه APK</a> آپلود کن</p>
<?php endif; ?>
<select name="apk_id" required>
<option value="">— انتخاب کنید —</option>
<?php foreach($apkFiles as $a): ?>
<option value="<?=$a['id']?>" <?= $a['is_active']?'selected':''?>><?=htmlspecialchars($a['version'])?> — <?=htmlspecialchars($a['file_name'])?> — <?=round($a['file_size']/1024/1024,2)?> MB <?= $a['is_active']?' ✅ فعال':''?></option>
<?php endforeach; ?>
</select>
<?php if($apkFiles): ?><p style="font-size:11px;color:#22c55e">✅ <?=count($apkFiles)?> فایل آماده — فعال: <?=htmlspecialchars($activeApk['version']??'—')?></p><?php endif; ?>

<button style="background:#22c55e;color:#052e16;width:100%;margin-top:10px">➕ ساخت مشتری و بسته تحویل</button>
</form>
<div style="background:#0f172a;padding:10px;border-radius:8px;margin-top:10px;font-size:11px;color:#94a3b8">
<b>شفاف:</b> APK عمومی است؛ برای هر مشتری کامپایل مجدد لازم نیست. سرور شناسه اختصاصی (delivery_token + API Key) را به حساب مشتری متصل و APK با <code>X-API-Key</code> به <code>/api/telegram.php</code> وصل می‌شود. اگر Bake داخل باینری بخواهید نیاز به apktool است که ناامن است.
</div>
</div>

<div class="card">
<h3>مدیریت دسترسی — لینک‌های اخیر</h3>
<div style="overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="color:#94a3b8"><th>ID</th><th>مشتری</th><th>نسخه</th><th>لینک</th><th>کلید</th><th>دانلود</th><th>انقضا</th><th>عملیات</th></tr>
<?php foreach($recentDeliveries as $d):
 $expired = strtotime($d['expires_at'] ?? '2099-01-01') < time();
 $revoked = $expired ? '⛔ منقضی' : '✅ فعال';
?>
<tr style="border-top:1px solid #334155"><td><?=$d['id']?></td><td><?=htmlspecialchars($d['customer_name'])?></td><td><?=htmlspecialchars($d['version'])?></td>
<td><a href="<?=htmlspecialchars(APP_URL)?>/api/delivery.php?t=<?=htmlspecialchars($d['delivery_token'])?>" target="_blank" style="color:#38bdf8">دانلود</a></td>
<td><code style="font-size:10px"><?=htmlspecialchars(substr($d['api_key']??'',0,12))?>…</code></td>
<td><?=$d['download_count']?></td><td><?=htmlspecialchars($d['expires_at']??'—')?><br><?=$revoked?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="revoke"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 8px;border-radius:6px" onclick="return confirm('لغو؟')">لغو</button></form>
<form method="post"><input type="hidden" name="action" value="reissue"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#334155;color:#e2e8f0;padding:6px 8px;border-radius:6px">صدور مجدد</button></form>
</td></tr>
<?php endforeach; ?>
<?php if(!$recentDeliveries): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">تحویلی نیست.</td></tr><?php endif; ?>
</table>
</div>
</div>

</div></body></html>
