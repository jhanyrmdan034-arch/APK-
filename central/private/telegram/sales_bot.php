<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../security/helpers.php';

// Webhook ربات فروش — تنظیم: https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://HOST/private/telegram/sales_bot.php
// یا public_html/api/sales_webhook.php → include این فایل. برای سادگی هر دو را ساپورت می‌کنیم.

if (SALES_BOT_TOKEN === '') { http_response_code(500); exit('SALES_BOT_TOKEN not configured'); }

// دریافت آپدیت
$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(200); exit('ok'); }
$update = json_decode($raw, true);
if (!$update) { http_response_code(200); exit('ok'); }

// لاگ
@file_put_contents(LOG_DIR.'/sales_bot.log', date('c')." ".substr($raw,0,4000).PHP_EOL, FILE_APPEND|LOCK_EX);

// استخراج اطلاعات پیام
$message = $update['message'] ?? $update['callback_query']['message'] ?? null;
$from = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
$chatId = $message['chat']['id'] ?? $from['id'] ?? null;
$tgId = $from['id'] ?? null;
$text = trim($update['message']['text'] ?? $update['callback_query']['data'] ?? '');
$username = $from['username'] ?? '';
$firstName = $from['first_name'] ?? '';

if (!$tgId || !$chatId) { http_response_code(200); exit; }

// اطمینان از وجود مشتری
$customer = ensure_customer_for_telegram((int)$tgId, $username, $firstName);
$cid = (int)$customer['id'];
// بررسی وضعیت دسترسی
$st = normalize_status($customer['status']);
if ($st === STATUS_DISABLED) {
    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⛔ حساب شما غیرفعال است. با پشتیبانی تماس بگیرید."]);
    http_response_code(200); exit;
}
if ($st === STATUS_SUSPENDED) {
    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⏸️ حساب شما تعلیق شده. امکان سفارش جدید وجود ندارد."]);
    // اجازه مشاهده کیف پول را می‌دهیم ولی ادامه نمی‌دهیم برای سفارش
}

// مدیریت session حالت
$pdo=db();
$stmt=$pdo->prepare("SELECT * FROM sales_sessions WHERE telegram_id=? LIMIT 1");
$stmt->execute([$tgId]);
$session=$stmt->fetch();
if(!$session){
    $pdo->prepare("INSERT INTO sales_sessions (telegram_id,customer_id,state) VALUES (?,?,?)")->execute([$tgId,$cid,'idle']);
    $session=['telegram_id'=>$tgId,'customer_id'=>$cid,'state'=>'idle','state_data'=>null];
}
$state = $session['state'];
$stateData = json_decode($session['state_data'] ?? 'null', true) ?: [];

function set_state(int $tgId, string $state, array $data=[]): void {
    $pdo=db();
    $pdo->prepare("UPDATE sales_sessions SET state=?, state_data=?, updated_at=NOW() WHERE telegram_id=?")->execute([$state, json_encode($data,JSON_UNESCAPED_UNICODE), $tgId]);
}
function main_menu(): array {
    return ['keyboard'=>[
        [['text'=>'💰 کیف پول'],['text'=>'💳 شارژ حساب']],
        [['text'=>'🛒 سفارش جدید'],['text'=>'📦 سفارش‌های من']],
        [['text'=>'🤖 ثبت ربات'],['text'=>'📥 دریافت APK']],
        [['text'=>'📊 تراکنش‌ها'],['text'=>'🆘 پشتیبانی']],
    ],'resize_keyboard'=>true];
}
function inline_token_link(string $url): array {
    return ['inline_keyboard'=>[[['text'=>'🔐 فرم امن ثبت توکن','url'=>$url]]]];
}

// فایل عکس رسید
$photo = $update['message']['photo'] ?? null;
if ($photo && $state==='awaiting_receipt') {
    // ذخیره رسید
    $fileId = end($photo)['file_id'];
    // دریافت فایل از تلگرام
    $info = sales_bot_send('getFile',['file_id'=>$fileId]);
    if (!empty($info['ok'])) {
        $filePath = $info['result']['file_path'];
        $dlUrl = "https://api.telegram.org/file/bot".SALES_BOT_TOKEN."/".$filePath;
        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
        $saveName = 'receipt_'.$cid.'_'.time().'.'.$ext;
        $savePath = UPLOAD_DIR.'/'.$saveName;
        $data = @file_get_contents($dlUrl);
        if ($data) @file_put_contents($savePath, $data);
        // به‌روزرسانی آخرین payment_request این مشتری که pending است
        $pr = $pdo->prepare("SELECT id FROM payment_requests WHERE customer_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $pr->execute([$cid]); $prRow=$pr->fetch();
        if($prRow){
            $pdo->prepare("UPDATE payment_requests SET receipt_path=? WHERE id=?")->execute([$savePath,$prRow['id']]);
        }
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"✅ رسید دریافت شد و برای بررسی به مدیر ارسال شد.\nوضعیت: ⏳ در انتظار تأیید\nپس از تأیید، کیف پول شارژ و سفارش به‌صورت خودکار ایجاد می‌شود.",'reply_markup'=>json_encode(main_menu())]);
        sec_log('sales_receipt_uploaded',$cid,['file'=>$saveName]);
        set_state((int)$tgId,'idle',[]);
        http_response_code(200); exit;
    }
}

// دستورات
if (strpos($text,'/start')===0 || $text==='شروع' || $text==='بازگشت') {
    set_state((int)$tgId,'idle',[]);
    $msg = "👋 سلام ".htmlspecialchars($firstName)."!\n\nبه ربات فروش *".APP_NAME."* خوش آمدید.\n\n💳 برای شروع، حساب خود را شارژ کنید و سپس سفارش ثبت کنید.\n🔐 توکن ربات را فقط از طریق فرم امن HTTPS ثبت کنید.\n\nشناسه مشتری: `{$customer['uuid']}`\nوضعیت: $st";
    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_markup'=>json_encode(main_menu())]);
    http_response_code(200); exit;
}

// اگر در حالت انتظار مقدار هستیم
if ($state==='awaiting_amount') {
    // انتظار مبلغ شارژ
    $amount = (int)preg_replace('/[^0-9]/','',$text);
    if ($amount < 10000) {
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⚠️ مبلغ نامعتبر است. حداقل 10,000 تومان.\nمثال: 1500000"]);
        http_response_code(200); exit;
    }
    // ایجاد درخواست پرداخت
    $pdo->prepare("INSERT INTO payment_requests (customer_id,amount,method,status) VALUES (?,?,?,?)")->execute([$cid,$amount,'card_to_card','pending']);
    $prId=(int)$pdo->lastInsertId();
    $settings = []; foreach($pdo->query("SELECT k,v FROM settings") as $r) $settings[$r['k']]=$r['v'];
    $card = $settings['card_number'] ?? '6037-xxxx-xxxx-xxxx';
    $holder = $settings['card_holder'] ?? 'BABARPHANTOM';
    $msg="✅ درخواست شارژ به مبلغ *".number_format($amount)." تومان* ثبت شد. (شناسه: #$prId)\n\n💳 لطفاً مبلغ را به کارت زیر واریز کنید و تصویر رسید را همینجا ارسال کنید:\n`$card`\nبه نام: $holder\n\nسپس تصویر رسید را بفرستید.";
    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown']);
    set_state((int)$tgId,'awaiting_receipt',['pr_id'=>$prId]);
    http_response_code(200); exit;
}

// منوی اصلی
switch($text){
    case '💰 کیف پول':
        $w=get_wallet($cid);
        $bal=number_format((int)$w['balance']);
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"💰 موجودی: *$bal تومان*\nارز: {$w['currency']}",'parse_mode'=>'Markdown','reply_markup'=>json_encode(main_menu())]);
        break;
    case '💳 شارژ حساب':
        if($st!==STATUS_ACTIVE){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⏸️ حساب شما فعال نیست."]); break; }
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"💳 مبلغ شارژ را به تومان وارد کنید:\nمثال: 1500000",'reply_markup'=>json_encode(['force_reply'=>true])]);
        set_state((int)$tgId,'awaiting_amount',[]);
        break;
    case '📊 تراکنش‌ها':
        $rows=$pdo->prepare("SELECT type,amount,balance_after,created_at FROM wallet_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 10");
        $rows->execute([$cid]); $list=$rows->fetchAll();
        if(!$list) $msg="هنوز تراکنشی ندارید.";
        else { $msg="📊 10 تراکنش اخیر:\n"; foreach($list as $r) $msg.="\n". $r['created_at']." — ".$r['type']." ".number_format($r['amount'])." → ".number_format($r['balance_after']); }
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>$msg,'reply_markup'=>json_encode(main_menu())]);
        break;
    case '📦 سفارش‌های من':
        $rows=$pdo->prepare("SELECT id,product_code,amount,status,created_at FROM orders WHERE customer_id=? ORDER BY id DESC LIMIT 10");
        $rows->execute([$cid]); $list=$rows->fetchAll();
        if(!$list) $msg="سفارشی ثبت نشده.";
        else { $msg="📦 سفارش‌ها:\n"; foreach($list as $r) $msg.="\n#{$r['id']} {$r['product_code']} ".number_format($r['amount'])." — {$r['status']} ({$r['created_at']})"; }
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>$msg,'reply_markup'=>json_encode(main_menu())]);
        break;
    case '🛒 سفارش جدید':
        if($st!==STATUS_ACTIVE){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⏸️ حساب تعلیق/غیرفعال است."]); break; }
        $prods=$pdo->query("SELECT code,name,price FROM products WHERE is_active=1")->fetchAll();
        $msg="🛒 محصولات:\n"; $kbd=[];
        foreach($prods as $p){ $msg.="\n• {$p['name']} ({$p['code']}) — ".number_format($p['price'])." تومان"; $kbd[]=[['text'=>"خرید {$p['code']}",'callback_data'=>"buy:{$p['code']}"]]; }
        if(!$kbd) $msg="محصولی موجود نیست.";
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>$msg,'reply_markup'=>json_encode($kbd?['inline_keyboard'=>$kbd]:main_menu())]);
        break;
    case '🤖 ثبت ربات':
        // ایجاد فرم امن
        $form=create_token_form($cid);
        $url=$form['url'];
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"🔐 برای ثبت توکن ربات، روی لینک امن زیر بزنید (".TOKEN_FORM_TTL_MINUTES." دقیقه اعتبار):\n$url\n\n⚠️ توکن را داخل تلگرام ارسال نکنید — فقط در فرم HTTPS وارد کنید.",'reply_markup'=>json_encode(inline_token_link($url))]);
        sec_log('sales_token_form_created',$cid,['token'=>substr($form['token'],0,8).'...']);
        break;
    case '📥 دریافت APK':
        if($st!==STATUS_ACTIVE){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⏸️ حساب فعال نیست."]); break; }
        // بررسی سفارش paid/delivered
        $ord=$pdo->prepare("SELECT id,status FROM orders WHERE customer_id=? AND status IN ('paid','delivered') ORDER BY id DESC LIMIT 1");
        $ord->execute([$cid]); $o=$ord->fetch();
        if(!$o){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"❌ سفارش پرداخت‌شده ندارید. ابتدا سفارش ثبت و پرداخت را تکمیل کنید."]); break; }
        // آیا قبلاً delivery دارد؟
        $del=$pdo->prepare("SELECT delivery_token FROM apk_deliveries WHERE customer_id=? AND order_id=? LIMIT 1");
        $del->execute([$cid,$o['id']]); $d=$del->fetch();
        if($d){
            $url = APP_URL . '/api/delivery.php?t=' . $d['delivery_token'];
            sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"📥 APK شما آماده است:\n$url\n\nیا از همین ربات دوباره درخواست کنید."]);
        } else {
            // ایجاد delivery اگر APK فعال موجود باشد
            $apk=get_active_apk();
            if(!$apk){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"⚠️ فایل APK هنوز توسط مدیر بارگذاری نشده."]); break; }
            $dl=create_apk_delivery($cid,(int)$apk['id'],(int)$o['id']);
            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$o['id']]);
            $url = APP_URL . '/api/delivery.php?t=' . $dl['token'];
            sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"✅ سفارش #{$o['id']} تحویل شد!\n📥 دانلود APK:\n$url\n🔑 API Key اختصاصی: `{$dl['api_key']}`\nاین کلید را در APK وارد کنید (یا APK تحویلی خودکار آن را دارد).",'parse_mode'=>'Markdown']);
            // ارسال فایل APK به تلگرام اگر حجم < 50MB
            if((int)$apk['file_size'] < 49000000 && file_exists($apk['file_path'])){
                $curlUrl = TELEGRAM_API_BASE . SALES_BOT_TOKEN . '/sendDocument';
                $ch=curl_init($curlUrl);
                $post=['chat_id'=>$chatId,'caption'=>"📦 APK — سفارش #{$o['id']}\nAPI Key: {$dl['api_key']}",'document'=>new CURLFile($apk['file_path'], 'application/vnd.android.package-archive', $apk['file_name'])];
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>60]);
                curl_exec($ch); curl_close($ch);
            }
        }
        break;
    case '🆘 پشتیبانی':
        $sup = $pdo->query("SELECT v FROM settings WHERE k='support_telegram'")->fetchColumn() ?: '@support';
        sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"🆘 پشتیبانی: $sup"]);
        break;
    default:
        // callback buy
        if(strpos($text,'buy:')===0){
            $code=substr($text,4);
            $prod=$pdo->prepare("SELECT * FROM products WHERE code=? AND is_active=1 LIMIT 1");
            $prod->execute([$code]); $p=$prod->fetch();
            if(!$p){ sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"محصول یافت نشد."]); break; }
            $w=get_wallet($cid);
            if((int)$w['balance'] < (int)$p['price']){
                sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"❌ موجودی کافی نیست. موجودی: ".number_format($w['balance'])." ، قیمت: ".number_format($p['price'])."\nلطفاً شارژ کنید."]);
                break;
            }
            // کسر از کیف پول و ایجاد سفارش paid
            $pdo->beginTransaction();
            try{
                wallet_add_transaction($cid,'purchase', - (int)$p['price'], 'order', null, 'خرید '.$p['name']);
                $pdo->prepare("INSERT INTO orders (customer_id,product_code,amount,status,paid_at) VALUES (?,?,?,?,NOW())")->execute([$cid,$p['code'],$p['price'],'paid']);
                $oid=(int)$pdo->lastInsertId();
                $pdo->commit();
            }catch(Throwable $e){ $pdo->rollBack(); sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"خطا در ثبت سفارش."]); break; }
            // تحویل خودکار APK اگر فعال است و محصول phantom_*
            $auto = $pdo->query("SELECT v FROM settings WHERE k='apk_auto_delivery'")->fetchColumn();
            if($auto==='1'){
                $apk=get_active_apk();
                if($apk){
                    $dl=create_apk_delivery($cid,(int)$apk['id'],$oid);
                    $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW(), api_key_id=(SELECT id FROM api_keys WHERE api_key=? LIMIT 1) WHERE id=?")->execute([$dl['api_key'],$oid]);
                    $url = APP_URL . '/api/delivery.php?t=' . $dl['token'];
                    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"✅ سفارش #$oid ثبت و پرداخت شد!\n📥 دانلود APK:\n$url"]);
                    // ثبت نیاز به توکن
                    $form=create_token_form($cid);
                    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"🔐 حالا توکن ربات خود را ثبت کنید:\n".$form['url'],'reply_markup'=>json_encode(inline_token_link($form['url']))]);
                } else {
                    sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"✅ سفارش #$oid ثبت شد. فایل APK به‌زودی بارگذاری می‌شود."]);
                }
            } else {
                sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"✅ سفارش #$oid ثبت شد. پس از آماده‌سازی APK تحویل داده می‌شود."]);
            }
            sec_log('sales_order_created',$cid,['order_id'=>$oid,'code'=>$code]);
        } else {
            sales_bot_send('sendMessage',['chat_id'=>$chatId,'text'=>"دستور نامفهوم. از منو استفاده کنید.",'reply_markup'=>json_encode(main_menu())]);
        }
        break;
}

http_response_code(200);
