<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
require_https();

$bearer=get_bearer_token();
$customer=null;
if($bearer && preg_match('/^[a-f0-9]{64}$/i',$bearer)) $customer=authenticate_apk();
else { if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();} if(!empty($_SESSION['customer_id'])) $customer=admin_require_login(); }
if(!$customer) json_out(['ok'=>false,'error'=>'Unauthorized'],401);
$cid=(int)$customer['id'];
$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    $stmt=$pdo->prepare("SELECT id,product_code,amount,status,created_at,paid_at,delivered_at FROM orders WHERE customer_id=? ORDER BY id DESC");
    $stmt->execute([$cid]);
    json_out(['ok'=>true,'orders'=>$stmt->fetchAll()]);
}
if($method==='POST'){
    if(normalize_status($customer['status'])!==STATUS_ACTIVE) json_out(['ok'=>false,'error'=>'Account not active'],403);
    $input=json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $code=sanitize_string($input['product_code']??'',64);
    if($code==='') json_out(['ok'=>false,'error'=>'Missing product_code'],400);
    $prod=$pdo->prepare("SELECT * FROM products WHERE code=? AND is_active=1 LIMIT 1");
    $prod->execute([$code]); $p=$prod->fetch();
    if(!$p) json_out(['ok'=>false,'error'=>'Product not found'],404);
    $w=get_wallet($cid);
    if((int)$w['balance'] < (int)$p['price']) json_out(['ok'=>false,'error'=>'Insufficient wallet balance','balance'=>(int)$w['balance']],402);
    $pdo->beginTransaction();
    try{
        wallet_add_transaction($cid,'purchase', - (int)$p['price'], 'order', null, 'خرید '.$p['name']);
        $pdo->prepare("INSERT INTO orders (customer_id,product_code,amount,status,paid_at) VALUES (?,?,?,?,NOW())")->execute([$cid,$p['code'],$p['price'],'paid']);
        $oid=(int)$pdo->lastInsertId();
        $pdo->commit();
    }catch(Throwable $e){ $pdo->rollBack(); json_out(['ok'=>false,'error'=>'Server error'],500); }
    // auto delivery اگر فعال
    $auto=$pdo->query("SELECT v FROM settings WHERE k='apk_auto_delivery'")->fetchColumn();
    if($auto==='1'){
        $apk=get_active_apk();
        if($apk){
            $dl=create_apk_delivery($cid,(int)$apk['id'],$oid);
            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$oid]);
            json_out(['ok'=>true,'order_id'=>$oid,'delivery'=>['url'=>APP_URL.'/api/delivery.php?t='.$dl['token'],'api_key'=>$dl['api_key']]]);
        }
    }
    json_out(['ok'=>true,'order_id'=>$oid]);
}
json_out(['ok'=>false,'error'=>'Method not allowed'],405);
