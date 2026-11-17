<?php
declare(strict_types=1);
require_once __DIR__ . '/../private/config/config.php';
require_once __DIR__ . '/../private/security/helpers.php';

// فرم امن دریافت توکن ربات — فقط HTTPS، one-time token
require_https();

$tok = trim($_GET['t'] ?? $_POST['t'] ?? '');
$err=''; $ok='';
$info = $tok ? validate_token_form($tok) : null;
if (!$tok) { $err='لینک نامعتبر است.'; }
elseif (!$info) { $err='لینک منقضی یا قبلاً استفاده شده است. از ربات فروش لینک جدید بگیرید.'; }

if ($_SERVER['REQUEST_METHOD']==='POST' && $info) {
    $botToken = trim($_POST['bot_token'] ?? '');
    if (!is_valid_bot_token($botToken)) {
        $err='فرمت توکن نامعتبر است. مثال: 123456789:AAH...';
    } else {
        // اعتبارسنجی با Telegram
        $url = 'https://api.telegram.org/bot'.$botToken.'/getMe';
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]); $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $j=json_decode($resp,true);
        if ($code!==200 || empty($j['ok'])) {
            $err='توکن نامعتبر است (getMe ناموفق). پاسخ: '.htmlspecialchars(substr($resp??'',0,400));
        } else {
            $username = $j['result']['username'] ?? null;
            $cid=(int)$info['customer_id'];
            $pdo=db();
            try{
                $enc=encrypt_token($botToken);
                // upsert
                $s=$pdo->prepare("SELECT id FROM telegram_bots WHERE customer_id=? LIMIT 1"); $s->execute([$cid]); $ex=$s->fetch();
                if($ex){
                    $pdo->prepare("UPDATE telegram_bots SET bot_username=?, token_encrypted=?, token_nonce=?, is_connected=1, last_checked_at=NOW(), last_error=NULL WHERE id=?")->execute([$username,$enc['ct'],$enc['nonce'],$ex['id']]);
                } else {
                    $pdo->prepare("INSERT INTO telegram_bots (customer_id, bot_username, token_encrypted, token_nonce, is_connected, last_checked_at) VALUES (?,?,?,?,1,NOW())")->execute([$cid,$username,$enc['ct'],$enc['nonce']]);
                }
                $file = TOKEN_STORE_DIR.'/'.$cid.'.enc';
                file_put_contents($file, json_encode(['ct'=>$enc['ct'],'nonce'=>$enc['nonce'],'username'=>$username],JSON_UNESCAPED_UNICODE), LOCK_EX);
                chmod($file,0600);
                $pdo->prepare("UPDATE token_forms SET is_used=1, used_at=NOW(), ip=? WHERE id=?")->execute([$_SERVER['REMOTE_ADDR']??null,$info['id']]);
                sec_log('token_form_success',$cid,['username'=>$username]);
                // تلاش تحویل خودکار APK اگر سفارش paid بدون delivery دارد
                $apk=get_active_apk();
                if($apk){
                    $o=$pdo->prepare("SELECT id FROM orders WHERE customer_id=? AND status='paid' ORDER BY id DESC LIMIT 1"); $o->execute([$cid]); $ord=$o->fetch();
                    if($ord){
                        $has=$pdo->prepare("SELECT id FROM apk_deliveries WHERE order_id=? LIMIT 1"); $has->execute([$ord['id']]); 
                        if(!$has->fetch()){
                            $dl=create_apk_delivery($cid,(int)$apk['id'],(int)$ord['id']);
                            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$ord['id']]);
                            if(SALES_BOT_TOKEN){
                                $cust=$pdo->prepare("SELECT telegram_id FROM customers WHERE id=?"); $cust->execute([$cid]); $tg=$cust->fetchColumn();
                                if($tg){
                                    $url=APP_URL.'/api/delivery.php?t='.$dl['token'];
                                    sales_bot_send('sendMessage',['chat_id'=>$tg,'text'=>"✅ ربات @$username متصل شد و APK تحویل داده شد!\n📥 دانلود: $url"]);
                                    if((int)$apk['file_size'] < 49000000 && file_exists($apk['file_path'])){
                                        $curlUrl = TELEGRAM_API_BASE . SALES_BOT_TOKEN . '/sendDocument';
                                        $ch=curl_init($curlUrl);
                                        $post=['chat_id'=>$tg,'caption'=>"📦 APK — سفارش #{$ord['id']}\nAPI Key: {$dl['api_key']}",'document'=>new CURLFile($apk['file_path'],'application/vnd.android.package-archive',$apk['file_name'])];
                                        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>60]);
                                        curl_exec($ch); curl_close($ch);
                                    }
                                }
                            }
                        }
                    }
                }
                $ok = "✅ ربات @$username با موفقیت متصل شد و توکن به‌صورت رمزنگاری‌شده ذخیره شد. می‌توانید این صفحه را ببندید.";
                $info=null; // جلوگیری از نمایش دوباره فرم
            }catch(Throwable $e){ $err='خطای ذخیره: '.$e->getMessage(); }
        }
    }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ثبت توکن ربات — <?=htmlspecialchars(APP_NAME)?></title>
<style>body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#1e293b;padding:28px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.4);width:100%;max-width:520px}
input{width:100%;padding:12px;margin:8px 0;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;box-sizing:border-box;direction:ltr}
button{width:100%;padding:12px;background:#22c55e;color:#052e16;border:0;border-radius:8px;font-weight:800;cursor:pointer}
.err{background:#7f1d1d;color:#fecaca;padding:10px;border-radius:8px;margin-bottom:12px}
.ok{background:#14532d;color:#bbf7d0;padding:10px;border-radius:8px;margin-bottom:12px}
a{color:#38bdf8}code{background:#0f172a;padding:2px 6px;border-radius:4px}
small{color:#94a3b8}</style>
</head><body><div class="card">
<h1>🔐 ثبت امن توکن ربات</h1>
<?php if($err): ?><div class="err"><?= $err ?></div><?php endif; ?>
<?php if($ok): ?><div class="ok"><?=htmlspecialchars($ok)?></div><p style="text-align:center"><a href="https://t.me/<?=htmlspecialchars(SALES_BOT_USERNAME ?: 'SalesBot')?>">بازگشت به ربات فروش</a></p>
<?php elseif($info): ?>
<p style="color:#cbd5e1;font-size:13px">توکن را از <code>@BotFather</code> بگیرید و فقط اینجا وارد کنید. توکن هرگز در APK قرار نمی‌گیرد و روی سرور رمزنگاری می‌شود.</p>
<form method="post">
<input type="hidden" name="t" value="<?=htmlspecialchars($tok)?>">
<label>Bot Token</label>
<input name="bot_token" placeholder="123456789:AAH..." required>
<small>فرمت: 8-12 رقم، دو‌نقطه، 30+ کاراکتر</small>
<button type="submit">ثبت و اتصال</button>
</form>
<p style="font-size:12px;color:#94a3b8;margin-top:12px">انقضا: <?=htmlspecialchars($info['expires_at'])?> — یک‌بار مصرف — فقط HTTPS</p>
<?php else: ?>
<p>لینک نامعتبر. از ربات فروش لینک جدید بگیرید: <a href="https://t.me/<?=htmlspecialchars(SALES_BOT_USERNAME ?: 'SalesBot')?>">@<?=htmlspecialchars(SALES_BOT_USERNAME ?: 'SalesBot')?></a></p>
<?php endif; ?>
</div></body></html>
