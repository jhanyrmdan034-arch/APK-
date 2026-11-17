<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=admin_require_admin();
$pdo=db();
$msg=''; $err=false; $result=null;

// لیست مشتریان و APKها
$customers = $pdo->query("SELECT id,email,name,status,telegram_id FROM customers ORDER BY id DESC")->fetchAll();
$apkFiles = $pdo->query("SELECT id,version,version_code,file_name,file_size,is_active FROM apk_files ORDER BY id DESC")->fetchAll();
$activeApk = get_active_apk();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    // --- ایجاد مشتری جدید (اگر انتخاب شد) ---
    if ($act==='create_customer') {
        $email=sanitize_string($_POST['email']??'',255);
        $name=sanitize_string($_POST['name']??'',255);
        $pass=$_POST['password']??'';
        if(!is_valid_email($email)){$msg='ایمیل نامعتبر';$err=true;}
        elseif(strlen($pass)<8){$msg='رمز حداقل 8 کاراکتر';$err=true;}
        else{
            try{
                $uuid=generate_uuid();
                $hash=password_hash($pass,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]);
                $pdo->prepare("INSERT INTO customers (uuid,email,password_hash,name,status) VALUES (?,?,?,?,?)")->execute([$uuid,$email,$hash,$name,'ACTIVE']);
                $nid=(int)$pdo->lastInsertId();
                $apiKey=generate_api_key();
                $pdo->prepare("INSERT INTO api_keys (customer_id,api_key) VALUES (?,?)")->execute([$nid,$apiKey]);
                $pdo->prepare("INSERT IGNORE INTO wallets (customer_id,balance) VALUES (?,0)")->execute([$nid]);
                admin_log((int)$me['id'],'customer_created','customer',$nid,['email'=>$email,'via'=>'delivery_builder']);
                $msg="مشتری #$nid ($email) ساخته شد."; 
                $customers = $pdo->query("SELECT id,email,name,status,telegram_id FROM customers ORDER BY id DESC")->fetchAll();
            }catch(Throwable $e){$msg='خطا: '.$e->getMessage();$err=true;}
        }
    }
    // --- ساخت بسته تحویل دستی ---
    elseif ($act==='build') {
        $cid=(int)($_POST['customer_id']??0);
        $token=trim($_POST['bot_token']??'');
        $apkId=(int)($_POST['apk_id']??0);
        if(!$cid){$msg='مشتری انتخاب نشده';$err=true;}
        elseif(!is_valid_bot_token($token)){$msg='فرمت توکن نامعتبر (مثال: 123456:AAH...)';$err=true;}
        elseif(!$apkId){$msg='فایل APK انتخاب نشده';$err=true;}
        else{
            $pdo->beginTransaction();
            try{
                // بررسی مشتری فعال
                $c=$pdo->prepare("SELECT id,status,email,telegram_id FROM customers WHERE id=? LIMIT 1"); $c->execute([$cid]); $cust=$c->fetch();
                if(!$cust) throw new RuntimeException('مشتری یافت نشد');
                // اعتبارسنجی توکن با تلگرام
                $url='https://api.telegram.org/bot'.$token.'/getMe';
                $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]); $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                $j=json_decode($resp,true);
                if($code!==200 || empty($j['ok'])) throw new RuntimeException('توکن نامعتبر (getMe ناموفق): '.substr($resp??'',0,300));
                $username=$j['result']['username'] ?? null;
                $botId=$j['result']['id'] ?? null;
                // رمزنگاری و ذخیره (هرگز در APK یا لاگ plaintext نماند)
                $enc=encrypt_token($token);
                $s=$pdo->prepare("SELECT id FROM telegram_bots WHERE customer_id=? LIMIT 1"); $s->execute([$cid]); $ex=$s->fetch();
                if($ex){
                    $pdo->prepare("UPDATE telegram_bots SET bot_username=?, token_encrypted=?, token_nonce=?, is_connected=1, last_checked_at=NOW(), last_error=NULL WHERE id=?")->execute([$username,$enc['ct'],$enc['nonce'],$ex['id']]);
                }else{
                    $pdo->prepare("INSERT INTO telegram_bots (customer_id,bot_username,token_encrypted,token_nonce,is_connected,last_checked_at) VALUES (?,?,?,?,1,NOW())")->execute([$cid,$username,$enc['ct'],$enc['nonce']]);
                }
                $file=TOKEN_STORE_DIR.'/'.$cid.'.enc';
                file_put_contents($file, json_encode(['ct'=>$enc['ct'],'nonce'=>$enc['nonce'],'username'=>$username],JSON_UNESCAPED_UNICODE), LOCK_EX);
                @chmod($file,0600);
                // APK موجود؟
                $apk=$pdo->prepare("SELECT * FROM apk_files WHERE id=? LIMIT 1"); $apk->execute([$apkId]); $apkRow=$apk->fetch();
                if(!$apkRow) throw new RuntimeException('APK یافت نشد');
                if(!file_exists($apkRow['file_path'])) throw new RuntimeException('فایل APK روی سرور موجود نیست: '.$apkRow['file_path']);
                // ایجاد سفارش (اگر نیاز باشد برای حسابرسی)
                $pdo->prepare("INSERT INTO orders (customer_id,product_code,amount,status,paid_at,delivered_at) VALUES (?,?,?, 'delivered', NOW(), NOW())")->execute([$cid,'manual_delivery',0]);
                $orderId=(int)$pdo->lastInsertId();
                // ایجاد تحویل اختصاصی
                $dl=create_apk_delivery($cid,(int)$apkRow['id'],$orderId);
                $pdo->prepare("UPDATE orders SET api_key_id=(SELECT id FROM api_keys WHERE api_key=? LIMIT 1) WHERE id=?")->execute([$dl['api_key'],$orderId]);
                $pdo->commit();
                admin_log((int)$me['id'],'manual_delivery_created','customer',$cid,['apk'=>$apkRow['version'],'order'=>$orderId,'bot'=>$username,'delivery'=>$dl['token']]);
                sec_log('manual_delivery_created',$cid,['order'=>$orderId,'apk'=>$apkRow['version']]);
                $result=[
                    'customer'=>$cust,
                    'bot_username'=>$username,
                    'bot_id'=>$botId,
                    'apk'=>$apkRow,
                    'order_id'=>$orderId,
                    'delivery'=>$dl,
                    'url'=>APP_URL.'/api/delivery.php?t='.$dl['token'],
                ];
                $msg="✅ بسته تحویل برای مشتری #{$cid} (@{$username}) ساخته شد.";
            }catch(Throwable $e){
                $pdo->rollBack();
                $msg='خطا: '.$e->getMessage(); $err=true;
            }
        }
    }
    // --- ارسال مستقیم از طریق ربات فروش ---
    elseif ($act==='send_via_bot') {
        $cid=(int)($_POST['customer_id']??0);
        $deliveryToken=sanitize_string($_POST['delivery_token']??'',64);
        if(!$cid || !$deliveryToken){$msg='پارامتر ناقص';$err=true;}
        else{
            $c=$pdo->prepare("SELECT telegram_id,email FROM customers WHERE id=?"); $c->execute([$cid]); $cust=$c->fetch();
            if(!$cust || empty($cust['telegram_id'])){$msg='مشتری telegram_id ندارد — از طریق ربات فروش ثبت‌نام نکرده. لینک را دستی بفرستید.';$err=true;}
            elseif(!SALES_BOT_TOKEN){$msg='SALES_BOT_TOKEN تنظیم نشده (config.php)';$err=true;}
            else{
                $d=$pdo->prepare("SELECT d.*, a.file_path, a.file_name FROM apk_deliveries d JOIN apk_files a ON a.id=d.apk_file_id WHERE d.delivery_token=? AND d.customer_id=? LIMIT 1"); $d->execute([$deliveryToken,$cid]); $del=$d->fetch();
                if(!$del){$msg='تحویل یافت نشد';$err=true;}
                elseif(!file_exists($del['file_path'])){$msg='فایل APK موجود نیست';$err=true;}
                else{
                    $caption="📦 بسته APK شما آماده است!\n🔗 لینک دانلود:\n".APP_URL."/api/delivery.php?t=".$deliveryToken."\n🔑 API Key: ".$del['api_key']."\n\nنصب کنید و با همین API Key وارد شوید.";
                    $curlUrl=TELEGRAM_API_BASE.SALES_BOT_TOKEN.'/sendDocument';
                    $ch=curl_init($curlUrl);
                    $post=['chat_id'=>$cust['telegram_id'],'caption'=>$caption,'document'=>new CURLFile($del['file_path'],'application/vnd.android.package-archive',$del['file_name'])];
                    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>60]);
                    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                    $j=json_decode($resp,true);
                    if(!empty($j['ok'])){
                        $msg="✅ فایل برای @{$cust['email']} (tg:{$cust['telegram_id']}) ارسال شد.";
                        admin_log((int)$me['id'],'apk_sent_via_bot','customer',$cid,['delivery'=>$deliveryToken]);
                    } else {
                        $msg='ارسال ناموفق: '.htmlspecialchars(substr($resp??'',0,500)); $err=true;
                    }
                }
            }
        }
    }
    // --- لغو لینک ---
    elseif ($act==='revoke') {
        $did=(int)($_POST['delivery_id']??0);
        $pdo->prepare("UPDATE apk_deliveries SET expires_at=NOW() - INTERVAL 1 DAY WHERE id=?")->execute([$did]);
        // همچنین api_key مرتبط را غیرفعال
        $rk=$pdo->prepare("SELECT api_key FROM apk_deliveries WHERE id=?"); $rk->execute([$did]); $k=$rk->fetchColumn();
        if($k) $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$k]);
        admin_log((int)$me['id'],'delivery_revoked','delivery',$did,[]);
        $msg="لینک #$did لغو شد (منقضی و کلید غیرفعال).";
    }
    // --- صدور مجدد ---
    elseif ($act==='reissue') {
        $did=(int)($_POST['delivery_id']??0);
        $r=$pdo->prepare("SELECT * FROM apk_deliveries WHERE id=? LIMIT 1"); $r->execute([$did]); $del=$r->fetch();
        if(!$del){$msg='تحویل یافت نشد';$err=true;}
        else{
            $newTok=generate_delivery_token();
            $newKey=generate_api_key();
            $pdo->prepare("UPDATE apk_deliveries SET delivery_token=?, api_key=?, expires_at=DATE_ADD(NOW(), INTERVAL ? DAY), download_count=0 WHERE id=?")->execute([$newTok,$newKey,APK_DELIVERY_EXPIRES_DAYS,$did]);
            $pdo->prepare("INSERT INTO api_keys (customer_id,api_key,name) VALUES (?,?,?)")->execute([$del['customer_id'],$newKey,'apk_reissue_'.$did]);
            // old key غیرفعال
            $pdo->prepare("UPDATE api_keys SET is_active=0 WHERE api_key=?")->execute([$del['api_key']]);
            admin_log((int)$me['id'],'delivery_reissued','delivery',$did,['new_token'=>substr($newTok,0,8).'...']);
            $msg="✅ لینک جدید صادر شد: ".APP_URL."/api/delivery.php?t=".$newTok." — کلید جدید: ".$newKey;
        }
    }
}

// تحویل‌های اخیر برای مدیریت دسترسی
$recentDeliveries=$pdo->query("SELECT d.id, d.delivery_token, d.api_key, d.download_count, d.expires_at, d.created_at, c.email, a.version FROM apk_deliveries d JOIN customers c ON c.id=d.customer_id JOIN apk_files a ON a.id=d.apk_file_id ORDER BY d.id DESC LIMIT 20")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ساخت و تحویل دستی APK</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input,select{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700;font-size:13px}label{font-size:13px;color:#cbd5e1}code{background:#0f172a;padding:2px 6px;border-radius:4px;word-break:break-all}.alert{padding:10px;border-radius:8px;margin-bottom:12px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="apk.php">APK</a><a class="active" href="delivery_builder.php">ساخت و تحویل دستی</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>ساخت و تحویل دستی APK</h1>
<p style="color:#94a3b8;font-size:12px">APK عمومی است — نیازی به کامپایل مجدد نیست. شناسه و دسترسی اختصاصی هر مشتری در سرور (delivery_token + API Key) مدیریت می‌شود. توکن ربات هرگز داخل APK قرار نمی‌گیرد.</p>

<?php if($msg): ?><div class="alert" style="background:<?=$err?'#7f1d1d;color:#fecaca':'#14532d;color:#bbf7d0'?>"><?= $msg ?></div><?php endif; ?>

<?php if($result): ?>
<div class="card" style="border:2px solid #22c55e">
<h3 style="color:#22c55e">✅ بسته تحویل آماده</h3>
<p>مشتری: <b><?=htmlspecialchars($result['customer']['email'])?> (#<?=$result['customer']['id']?>)</b> — ربات: <b>@<?=htmlspecialchars($result['bot_username'])?></b></p>
<p>APK: <?=htmlspecialchars($result['apk']['version'])?> — <?=htmlspecialchars($result['apk']['file_name'])?> (<?=round($result['apk']['file_size']/1024/1024,2)?> MB)</p>
<p>سفارش: #<?=htmlspecialchars($result['order_id'])?></p>
<p>🔗 <b>لینک دانلود اختصاصی:</b><br><code><?=htmlspecialchars($result['url'])?></code></p>
<p>🔑 <b>API Key اختصاصی:</b> <code><?=htmlspecialchars($result['delivery']['api_key'])?></code></p>
<p>⏳ انقضا: <?=htmlspecialchars($result['delivery']['expires_at'])?></p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
<a href="<?=htmlspecialchars($result['url'])?>" target="_blank" style="background:#22c55e;color:#052e16;padding:10px 16px;border-radius:8px;text-decoration:none">📥 دانلود APK</a>
<form method="post" style="display:inline"><input type="hidden" name="action" value="send_via_bot"><input type="hidden" name="customer_id" value="<?=$result['customer']['id']?>"><input type="hidden" name="delivery_token" value="<?=htmlspecialchars($result['delivery']['token'])?>"><button style="background:#38bdf8;color:#082f49">📤 ارسال از طریق ربات فروش</button></form>
</div>
<p style="font-size:11px;color:#94a3b8;margin-top:8px">می‌توانید لینک و فایل را دستی برای مشتری بفرستید یا دکمه ارسال خودکار را بزنید (نیازمند telegram_id).</p>
</div>
<?php endif; ?>

<div class="card">
<h3>1) انتخاب مشتری</h3>
<form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
<input type="hidden" name="action" value="create_customer">
<div style="flex:1;min-width:180px"><label>ایمیل مشتری جدید</label><input name="email" placeholder="customer@example.com" required></div>
<div style="flex:1;min-width:120px"><label>نام</label><input name="name" placeholder="نام"></div>
<div style="flex:1;min-width:120px"><label>رمز عبور (حداقل 8)</label><input name="password" type="password" required></div>
<button style="background:#334155;color:#e2e8f0;height:40px">➕ ساخت مشتری</button>
</form>
<p style="font-size:11px;color:#94a3b8">یا از فهرست زیر مشتری موجود را انتخاب کنید.</p>
</div>

<div class="card">
<h3>2) ساخت بسته تحویل</h3>
<form method="post">
<input type="hidden" name="action" value="build">
<label>مشتری موردنظر</label>
<select name="customer_id" required>
<option value="">— انتخاب کنید —</option>
<?php foreach($customers as $c): ?>
<option value="<?=$c['id']?>"><?=htmlspecialchars($c['email'])?> — <?=htmlspecialchars($c['name']?:'بدون نام')?> [<?=htmlspecialchars($c['status'])?>] <?= $c['telegram_id']?' (tg: '.$c['telegram_id'].')':''?> (#<?=$c['id']?>)</option>
<?php endforeach; ?>
</select>

<label>توکن ربات تلگرام مشتری (از @BotFather)</label>
<input name="bot_token" placeholder="123456789:AAH..." required dir="ltr">
<small style="color:#fbbf24">⚠️ فقط اینجا وارد کنید — در APK یا لاگ ذخیره plaintext نمی‌شود، بلکه با AES-256-GCM رمزنگاری می‌شود.</small>

<label>فایل APK عمومی</label>
<select name="apk_id" required>
<option value="">— انتخاب کنید —</option>
<?php foreach($apkFiles as $a): ?>
<option value="<?=$a['id']?>" <?= $a['is_active']?'selected':''?>><?=htmlspecialchars($a['version'])?> (<?=$a['version_code']?>) — <?=htmlspecialchars($a['file_name'])?> — <?=round($a['file_size']/1024/1024,2)?> MB <?= $a['is_active']?' ✅ فعال':''?></option>
<?php endforeach; ?>
</select>
<?php if(!$apkFiles): ?><p style="color:#f87171">⚠️ هنوز APK آپلود نشده — ابتدا در <a href="apk.php" style="color:#38bdf8">مدیریت APK</a> بارگذاری کنید.</p><?php endif; ?>
<?php if($activeApk): ?><p style="font-size:11px;color:#94a3b8">پیش‌فرض: <?=htmlspecialchars($activeApk['version'])?> فعال است.</p><?php endif; ?>

<button style="background:#22c55e;color:#052e16;width:100%;margin-top:10px">🔨 ساخت بسته تحویل</button>
</form>
<div style="background:#0f172a;padding:10px;border-radius:8px;margin-top:10px;font-size:11px;color:#94a3b8">
<b>شفاف‌سازی:</b> چون APK عمومی است، برای هر مشتری کامپایل مجدد لازم نیست. سیستم شناسه اختصاصی (delivery_token + API Key) را در سرور به حساب مشتری متصل می‌کند و APK در اجرا با <code>X-API-Key</code> خود به <code>/api/telegram.php</code> و <code>/api/config.php</code> متصل می‌شود. اگر در آینده بخواهید کلید داخل باینری Bake شود، نیاز به <code>apktool</code> + ویرایش <code>assets/config.json</code> + امضای مجدد است — که فعلاً توصیه نمی‌شود چون امنیت کاهش می‌یابد.
</div>
</div>

<div class="card">
<h3>مدیریت دسترسی — لینک‌های اخیر</h3>
<p style="font-size:11px;color:#94a3b8">لغو لینک دانلود و صدور مجدد — محدودیت‌ها در سرور اعمال می‌شود.</p>
<div style="overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="color:#94a3b8"><th>ID</th><th>مشتری</th><th>نسخه</th><th>لینک</th><th>کلید</th><th>دانلود</th><th>انقضا</th><th>عملیات</th></tr>
<?php foreach($recentDeliveries as $d):
 $expired = strtotime($d['expires_at'] ?? '2099-01-01') < time();
 $revoked = $expired ? '⛔ منقضی' : '✅ فعال';
?>
<tr style="border-top:1px solid #334155"><td><?=$d['id']?></td><td><?=htmlspecialchars($d['email'])?></td><td><?=htmlspecialchars($d['version'])?></td>
<td><a href="<?=htmlspecialchars(APP_URL)?>/api/delivery.php?t=<?=htmlspecialchars($d['delivery_token'])?>" target="_blank" style="color:#38bdf8">دانلود</a><br><code style="font-size:10px"><?=htmlspecialchars(substr($d['delivery_token'],0,12))?>…</code></td>
<td><code style="font-size:10px"><?=htmlspecialchars(substr($d['api_key']??'',0,12))?>…</code></td>
<td><?=$d['download_count']?></td><td><?=htmlspecialchars($d['expires_at']??'—')?><br><?=$revoked?></td>
<td style="display:flex;gap:4px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="revoke"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#7f1d1d;color:#fecaca;padding:6px 8px;border-radius:6px" onclick="return confirm('لغو لینک؟')">لغو لینک</button></form>
<form method="post"><input type="hidden" name="action" value="reissue"><input type="hidden" name="delivery_id" value="<?=$d['id']?>"><button style="background:#334155;color:#e2e8f0;padding:6px 8px;border-radius:6px">صدور مجدد</button></form>
</td></tr>
<?php endforeach; ?>
<?php if(!$recentDeliveries): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">تحویلی ثبت نشده.</td></tr><?php endif; ?>
</table>
</div>
</div>

<div class="card" style="font-size:12px;color:#94a3b8">
<h3>مدیریت دسترسی کلی (یادآوری)</h3>
<ul>
<li>فعال‌سازی: <code>customers.php → ACTIVE</code></li>
<li>تعلیق: <code>customers.php → SUSPENDED</code> (API 403، فقط مشاهده)</li>
<li>غیرفعال‌سازی: <code>customers.php → DISABLED</code> (+ دستگاه‌ها is_active=0)</li>
<li>قطع ربات: <code>bots.php → قطع</code></li>
<li>مسدود دستگاه: <code>devices.php → مسدود</code></li>
<li>لغو/صدور لینک: همین صفحه ↑</li>
</ul>
</div>

</div></body></html>
