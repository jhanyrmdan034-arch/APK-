<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
require_https();

// مدیریت فایل‌های APK — فقط ادمین برای آپلود/لیست کامل، مشتری برای مشاهده موجود و دانلودهای خودش
if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me=null;
$bearer=get_bearer_token();
if($bearer && preg_match('/^[a-f0-9]{64}$/i',$bearer)){
    $me=authenticate_apk(); // customer
    $isAdmin=false;
} else {
    if(!empty($_SESSION['customer_id'])) $me=admin_require_login();
    $isAdmin = $me ? is_admin((int)$me['id']) : false;
}
if(!$me) json_out(['ok'=>false,'error'=>'Unauthorized'],401);

$pdo=db();
$method=$_SERVER['REQUEST_METHOD'];

if($method==='GET'){
    if($isAdmin){
        $rows=$pdo->query("SELECT id,version,version_code,file_name,file_size,sha256,is_active,created_at FROM apk_files ORDER BY id DESC")->fetchAll();
        json_out(['ok'=>true,'apk_files'=>$rows]);
    } else {
        $active=get_active_apk();
        $deliveries=$pdo->prepare("SELECT d.id, d.delivery_token, d.download_count, d.created_at, a.version, a.file_name FROM apk_deliveries d JOIN apk_files a ON a.id=d.apk_file_id WHERE d.customer_id=? ORDER BY d.id DESC");
        $deliveries->execute([(int)$me['id']]);
        json_out(['ok'=>true,'active_apk'=>$active?['version']:null,'deliveries'=>$deliveries->fetchAll()]);
    }
}
json_out(['ok'=>false,'error'=>'Method not allowed'],405);
