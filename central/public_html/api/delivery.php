<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';

// دانلود APK با delivery_token — بدون نیاز به لاگین، فقط توکن یک‌بار/چندبار مصرف
$token = sanitize_string($_GET['t'] ?? '', 64);
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i',$token)) {
    http_response_code(400); exit('Invalid token');
}
$pdo=db();
$stmt=$pdo->prepare("SELECT d.*, a.file_path, a.file_name, a.file_size, c.status as cstatus FROM apk_deliveries d JOIN apk_files a ON a.id=d.apk_file_id JOIN customers c ON c.id=d.customer_id WHERE d.delivery_token=? LIMIT 1");
$stmt->execute([$token]);
$del=$stmt->fetch();
if(!$del){ http_response_code(404); exit('Delivery not found'); }
if(normalize_status($del['cstatus']) !== STATUS_ACTIVE){ http_response_code(403); exit('Account not active'); }
if(!empty($del['expires_at']) && strtotime($del['expires_at']) < time()){ http_response_code(410); exit('Link expired'); }
$path=$del['file_path'];
if(!file_exists($path)){ http_response_code(500); exit('APK file missing on server'); }

// به‌روزرسانی شمارنده
$pdo->prepare("UPDATE apk_deliveries SET download_count=download_count+1, last_download_at=NOW() WHERE id=?")->execute([$del['id']]);
sec_log('apk_download', (int)$del['customer_id'], ['delivery_id'=>$del['id']]);

// ارسال فایل
$fname=$del['file_name'] ?: 'BABARPHANTOM.apk';
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="'.basename($fname).'"');
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
