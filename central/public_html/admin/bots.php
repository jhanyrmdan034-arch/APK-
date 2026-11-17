<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_login();
$pdo = db();
$isAdmin = is_admin((int)$me['id']);
$msg=''; $msgErr=false;

$cid = (int)$me['id'];
if ($isAdmin && !empty($_GET['customer_id'])) $cid = (int)$_GET['customer_id'];
if ($isAdmin && !empty($_POST['customer_id'])) $cid = (int)$_POST['customer_id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    if ($act==='add') {
        $token = trim($_POST['token'] ?? '');
        if (!is_valid_bot_token($token)) { $msg='فرمت توکن نامعتبر است. مثال: 123456789:AAH...'; $msgErr=true; }
        else {
            $url = 'https://api.telegram.org/bot'.$token.'/getMe';
            $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]); $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            $j=json_decode($resp,true);
            if ($code!==200 || empty($j['ok'])) {
                $msg='توکن نامعتبر است (getMe ناموفق). پاسخ: '.htmlspecialchars(substr($resp??'',0,500));
                $msgErr=true; sec_log('bot_token_invalid',$cid,['resp'=>substr($resp??'',0,500)]);
            } else {
                $username = $j['result']['username'] ?? null;
                try {
                    $enc = encrypt_token($token);
                    $s=$pdo->prepare("SELECT id FROM telegram_bots WHERE customer_id=? LIMIT 1"); $s->execute([$cid]); $ex=$s->fetch();
                    if ($ex) {
                        $pdo->prepare("UPDATE telegram_bots SET bot_username=?, token_encrypted=?, token_nonce=?, is_connected=1, last_checked_at=NOW(), last_error=NULL WHERE id=?")
                             ->execute([$username,$enc['ct'],$enc['nonce'],$ex['id']]);
                        $msg="توکن به‌روزرسانی شد — ربات @$username متصل شد.";
                    } else {
                        $pdo->prepare("INSERT INTO telegram_bots (customer_id, bot_username, token_encrypted, token_nonce, is_connected, last_checked_at) VALUES (?,?,?,?,1,NOW())")
                             ->execute([$cid,$username,$enc['ct'],$enc['nonce']]);
                        $msg="ربات @$username با موفقیت ثبت و متصل شد.";
                    }
                    $file = TOKEN_STORE_DIR . '/' . $cid . '.enc';
                    file_put_contents($file, json_encode(['ct'=>$enc['ct'],'nonce'=>$enc['nonce'],'username'=>$username], JSON_UNESCAPED_UNICODE), LOCK_EX);
                    chmod($file, 0600);
                    admin_log((int)$me['id'],'bot_connected','bot',$cid,['username'=>$username]);
                    sec_log('bot_connected',$cid,['username'=>$username]);
                } catch (Throwable $e){ $msg='خطای ذخیره: '.$e->getMessage(); $msgErr=true; }
            }
        }
    } elseif ($act==='gen_link') {
        $form=create_token_form($cid);
        $msg="🔐 لینک امن ایجاد شد (".TOKEN_FORM_TTL_MINUTES." دقیقه): ".$form['url'];
        admin_log((int)$me['id'],'token_form_created','customer',$cid,['token'=>substr($form['token'],0,8).'...']);
        if(SALES_BOT_TOKEN){
            $tg=$pdo->prepare("SELECT telegram_id FROM customers WHERE id=?"); $tg->execute([$cid]); $tid=$tg->fetchColumn();
            if($tid) sales_bot_send('sendMessage',['chat_id'=>$tid,'text'=>"🔐 لینک امن ثبت توکن (".TOKEN_FORM_TTL_MINUTES." دقیقه):\n".$form['url']]);
        }
    } elseif ($act==='disconnect') {
        $pdo->prepare("UPDATE telegram_bots SET is_connected=0 WHERE customer_id=?")->execute([$cid]);
        admin_log((int)$me['id'],'bot_disconnected','bot',$cid,[]);
        sec_log('bot_disconnected',$cid,[]);
        $msg='ربات قطع شد — دستگاه‌های مشتری دیگر به تلگرام ارسال نخواهند کرد.';
    } elseif ($act==='test') {
        $s=$pdo->prepare("SELECT token_encrypted, token_nonce FROM telegram_bots WHERE customer_id=? LIMIT 1"); $s->execute([$cid]); $b=$s->fetch();
        if (!$b) {$msg='رباتی ثبت نشده.'; $msgErr=true;}
        else {
            try {
                $tok=decrypt_token($b['token_encrypted'],$b['token_nonce']);
                $chat = sanitize_string($_POST['chat_id']??'',64);
                if ($chat==='') {$msg='chat_id لازم است.'; $msgErr=true;}
                else {
                    $url='https://api.telegram.org/bot'.$tok.'/sendMessage';
                    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$chat,'text'=>"✅ تست از ".APP_NAME." — اتصال برقرار است."] ),CURLOPT_TIMEOUT=>15]);
                    $resp=curl_exec($ch); curl_close($ch); $j=json_decode($resp,true);
                    if (!empty($j['ok'])) {$msg='پیام تست با موفقیت ارسال شد.'; $pdo->prepare("UPDATE telegram_bots SET is_connected=1, last_error=NULL, last_checked_at=NOW() WHERE customer_id=?")->execute([$cid]);}
                    else {$msg='ارسال ناموفق: '.htmlspecialchars(substr($resp,0,600)); $msgErr=true; $pdo->prepare("UPDATE telegram_bots SET last_error=?, last_checked_at=NOW() WHERE customer_id=?")->execute([substr($resp,0,1000),$cid]);}
                }
            } catch(Throwable $e){$msg='خطا: '.$e->getMessage(); $msgErr=true;}
        }
    } elseif ($act==='delete') {
        $pdo->prepare("DELETE FROM telegram_bots WHERE customer_id=?")->execute([$cid]);
        @unlink(TOKEN_STORE_DIR.'/'.$cid.'.enc');
        admin_log((int)$me['id'],'bot_deleted','bot',$cid,[]);
        sec_log('bot_deleted',$cid,[]);
        $msg='ربات حذف شد — دسترسی قطع شد.';
    }
}

$s=$pdo->prepare("SELECT id, bot_username, is_connected, last_checked_at, last_error, created_at FROM telegram_bots WHERE customer_id=? LIMIT 1"); $s->execute([$cid]); $bot=$s->fetch();
$targetEmail = $pdo->prepare("SELECT COALESCE(name,email) FROM customers WHERE id=?"); $targetEmail->execute([$cid]); $targetEmail=$targetEmail->fetchColumn();
$targetStatus = $pdo->prepare("SELECT status FROM customers WHERE id=?"); $targetStatus->execute([$cid]); $targetStatus=$targetStatus->fetchColumn();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ربات‌ها</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:900px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700;font-size:13px}code{background:#0f172a;padding:2px 6px;border-radius:4px}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a class="active" href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="settings.php">تنظیمات</a></div>
<div class="wrap">
<h1>مدیریت ربات تلگرام</h1>
<?php if($isAdmin): ?>
<div class="card"><form method="get">انتخاب مشتری: <select name="customer_id" onchange="this.form.submit()">
<?php $cs=$pdo->query("SELECT id,email,status FROM customers ORDER BY id"); foreach($cs as $c) echo '<option value="'.$c['id'].'"'.($c['id']==$cid?' selected':'').'>'.htmlspecialchars($c['email'])." [{$c['status']}] (#$c[id])</option>"; ?>
</select> <noscript><button>برو</button></noscript></form></div>
<?php endif; ?>
<p>مشتری: <b><?=htmlspecialchars($targetEmail)?></b> (#<?=$cid?>) — وضعیت: <b><?=htmlspecialchars($targetStatus)?></b></p>
<?php if($msg): ?><div class="card" style="background:<?=$msgErr?'#7f1d1d':'#14532d'?>;word-break:break-all"><?=$msg?></div><?php endif; ?>

<?php if($bot): ?>
<div class="card">
<p>ربات: <b>@<?=htmlspecialchars($bot['bot_username']??'—')?></b> — وضعیت: <?= $bot['is_connected']?'✅ متصل':'⛔ قطع (دسترسی مسدود)'?></p>
<p style="font-size:12px;color:#94a3b8">آخرین بررسی: <?=htmlspecialchars($bot['last_checked_at']??'—')?></p>
<?php if($bot['last_error']): ?><pre style="background:#7f1d1d;padding:10px;border-radius:8px;white-space:pre-wrap;font-size:12px"><?=htmlspecialchars($bot['last_error'])?></pre><?php endif; ?>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<form method="post"><input type="hidden" name="action" value="disconnect"><input type="hidden" name="customer_id" value="<?=$cid?>"><button style="background:#f59e0b;color:#000">⛔ قطع ربات (مسدود)</button></form>
<form method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="customer_id" value="<?=$cid?>"><button style="background:#7f1d1d;color:#fecaca" onclick="return confirm('حذف ربات؟')">حذف کامل</button></form>
</div>
</div>
<?php else: ?>
<div class="card" style="border:1px dashed #334155"><p>هنوز رباتی برای این مشتری ثبت نشده.</p></div>
<?php endif; ?>

<div class="card">
<h3>ایجاد فرم امن ثبت توکن (پیشنهادی)</h3>
<p style="font-size:12px;color:#38bdf8">🔐 به جای ارسال توکن در تلگرام، لینک HTTPS یک‌بارمصرف برای مشتری بسازید — مشتری توکن را فقط در فرم امن وارد می‌کند.</p>
<form method="post"><input type="hidden" name="action" value="gen_link"><input type="hidden" name="customer_id" value="<?=$cid?>"><button style="background:#38bdf8;color:#082f49;width:100%">🔗 ایجاد لینک امن (<?=TOKEN_FORM_TTL_MINUTES?> دقیقه)</button></form>
</div>

<div class="card">
<h3><?= $bot ? 'به‌روزرسانی توکن (مستقیم — فقط ادمین)' : 'ثبت توکن جدید (مستقیم)' ?></h3>
<p style="font-size:12px;color:#fbbf24">⚠️ توکن فقط در سرور (private/telegram_tokens/ و DB رمزنگاری‌شده) ذخیره می‌شود و هرگز در APK یا لاگ عمومی نمایش داده نمی‌شود. ترجیحاً از فرم امن بالا استفاده کنید.</p>
<form method="post">
<input type="hidden" name="action" value="add">
<input type="hidden" name="customer_id" value="<?=$cid?>">
<label>Bot Token (از @BotFather)</label>
<input name="token" placeholder="123456789:AAH..." required dir="ltr">
<button style="background:#22c55e;color:#052e16;width:100%;margin-top:8px">ذخیره و اتصال</button>
</form>
</div>

<div class="card">
<h3>تست ارسال</h3>
<form method="post">
<input type="hidden" name="action" value="test">
<input type="hidden" name="customer_id" value="<?=$cid?>">
<input name="chat_id" placeholder="chat_id مقصد برای تست" required dir="ltr">
<button style="background:#334155;color:#e2e8f0;width:100%">ارسال پیام تست</button>
</form>
</div>
</div></body></html>
