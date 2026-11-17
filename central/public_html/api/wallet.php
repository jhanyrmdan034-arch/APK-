<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
require_https();

// احراز هویت: APK با X-API-Key یا ادمین با session
$customer=null;
$bearer=get_bearer_token();
if($bearer && preg_match('/^[a-f0-9]{64}$/i',$bearer)){
    $customer=authenticate_apk();
} else {
    if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
    if(!empty($_SESSION['customer_id'])) $customer=admin_require_login();
}
if(!$customer) json_out(['ok'=>false,'error'=>'Unauthorized'],401);
$cid=(int)$customer['id'];
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    $w=get_wallet($cid);
    $txStmt=db()->prepare("SELECT id,type,amount,balance_after,description,created_at FROM wallet_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 20");
    $txStmt->execute([$cid]);
    json_out(['ok'=>true,'wallet'=>['balance'=>(int)$w['balance'],'currency'=>$w['currency']],'transactions'=>$txStmt->fetchAll()]);
}
json_out(['ok'=>false,'error'=>'Method not allowed'],405);
