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
    $stmt=$pdo->prepare("SELECT id,amount,method,status,created_at,reviewed_at FROM payment_requests WHERE customer_id=? ORDER BY id DESC");
    $stmt->execute([$cid]);
    json_out(['ok'=>true,'payments'=>$stmt->fetchAll()]);
}
if($method==='POST'){
    if(normalize_status($customer['status'])!==STATUS_ACTIVE) json_out(['ok'=>false,'error'=>'Account not active'],403);
    $input=json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $amount=(int)($input['amount']??0);
    if($amount < 10000) json_out(['ok'=>false,'error'=>'Amount too small'],400);
    $methodV=sanitize_string($input['method']??'card_to_card',32);
    $pdo->prepare("INSERT INTO payment_requests (customer_id,amount,method,status) VALUES (?,?,?,?)")->execute([$cid,$amount,$methodV,'pending']);
    $id=(int)$pdo->lastInsertId();
    sec_log('payment_request_created',$cid,['id'=>$id,'amount'=>$amount]);
    json_out(['ok'=>true,'id'=>$id,'status'=>'pending'],201);
}
json_out(['ok'=>false,'error'=>'Method not allowed'],405);
