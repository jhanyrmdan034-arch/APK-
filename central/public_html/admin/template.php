<?php
declare(strict_types=1);
require_once __DIR__ . '/../../private/config/config.php';
require_once __DIR__ . '/../../private/security/helpers.php';
if (session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_start();}
$me = admin_require_admin();
$pdo = db();
$msg=''; $err=false;

// Template dirs
$publicTplDir = __DIR__ . '/../templates';
$privateTplDir = __DIR__ . '/../../private/templates';
$publicTplFile = $publicTplDir . '/custom.html';
$privateTplFile = $privateTplDir . '/custom.html';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    if ($act==='upload') {
        if (empty($_FILES['template']['tmp_name'])) {
            $msg='فایل HTML انتخاب نشده.'; $err=true;
        } elseif ($_FILES['template']['size'] > 5*1024*1024) {
            $msg='حجم فایل بیش از 5MB.'; $err=true;
        } else {
            $tmp = $_FILES['template']['tmp_name'];
            $orig = $_FILES['template']['name'] ?? 'template.html';
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['html','htm'], true)) {
                $msg='فقط فایل .html / .htm مجاز است.'; $err=true;
            } else {
                $content = @file_get_contents($tmp);
                if ($content===false) { $msg='خطای خواندن فایل.'; $err=true; }
                elseif (stripos($content, '<?php')!==false) { $msg='فایل حاوی کد PHP است — مجاز نیست.'; $err=true; }
                elseif (strlen($content) < 20) { $msg='فایل خالی یا خیلی کوچک است.'; $err=true; }
                else {
                    // اطمینان از وجود پوشه‌ها
                    @mkdir($publicTplDir, 0755, true);
                    @mkdir($privateTplDir, 0700, true);
                    // ذخیره در هر دو مکان
                    if (!@file_put_contents($privateTplFile, $content, LOCK_EX)) {
                        $msg='خطای ذخیره در private/templates.'; $err=true;
                    } elseif (!@file_put_contents($publicTplFile, $content, LOCK_EX)) {
                        $msg='خطای ذخیره در public/templates.'; $err=true;
                    } else {
                        @chmod($privateTplFile, 0600);
                        @chmod($publicTplFile, 0644);
                        // به‌روزرسانی webview_url به قالب دلخواه
                        $url = rtrim(APP_URL, '/') . '/templates/custom.html';
                        $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")->execute(['webview_url', $url, $url]);
                        // همچنین هش برای لاگ
                        $hash = substr(hash('sha256', $content), 0, 12);
                        admin_log((int)$me['id'], 'template_uploaded', 'template', null, ['file'=>$orig,'size'=>strlen($content),'hash'=>$hash,'url'=>$url]);
                        sec_log('template_uploaded', (int)$me['id'], ['file'=>$orig,'hash'=>$hash]);
                        $msg="قالب دلخواه آپلود شد — طرح برنامه دقیقاً مثل همین HTML خواهد بود. URL: $url — اندازه: ".round(strlen($content)/1024,1)." KB — هش: $hash";
                    }
                }
            }
        }
    } elseif ($act==='reset') {
        // بازنشانی به حالت پیش‌فرض (حذف قالب دلخواه)
        @unlink($publicTplFile);
        @unlink($privateTplFile);
        $defaultUrl = 'https://vip-panel-krish-developer.42web.io/';
        $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")->execute(['webview_url', $defaultUrl, $defaultUrl]);
        admin_log((int)$me['id'], 'template_reset', 'template', null, []);
        $msg='قالب دلخواه حذف شد — به حالت پیش‌فرض برگشت.';
    } elseif ($act==='save_url') {
        $url = sanitize_string($_POST['webview_url'] ?? '', 500);
        if ($url==='') { $msg='آدرس خالی است.'; $err=true; }
        else {
            $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")->execute(['webview_url', $url, $url]);
            admin_log((int)$me['id'], 'template_url_updated', 'template', null, ['url'=>$url]);
            $msg="آدرس webview به‌روزرسانی شد: $url";
        }
    }
}

$settings=[]; foreach($pdo->query("SELECT k,v FROM settings") as $r) $settings[$r['k']]=$r['v'];
$currentUrl = $settings['webview_url'] ?? (defined('APP_URL') ? APP_URL : '');
$hasCustom = file_exists($publicTplFile);
$customSize = $hasCustom ? round(filesize($publicTplFile)/1024,1).' KB' : '—';
$customHash = $hasCustom ? substr(hash_file('sha256', $publicTplFile),0,16).'...' : '—';
$customTime = $hasCustom ? date('Y-m-d H:i', filemtime($publicTplFile)) : '—';
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>قالب دلخواه</title>
<style>*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;margin:0}.nav{background:#1e293b;padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap}.nav a{color:#94a3b8;text-decoration:none;padding:8px 10px;border-radius:8px;font-size:13px}.nav a.active{background:#334155;color:#e2e8f0}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#1e293b;border-radius:12px;padding:16px;margin-bottom:16px}input{padding:10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;width:100%;margin:6px 0}button{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700}code{background:#0f172a;padding:2px 6px;border-radius:4px}pre{white-space:pre-wrap;font-size:11px;background:#0f172a;padding:10px;border-radius:8px;max-height:300px;overflow:auto}</style>
</head><body>
<div class="nav"><b><?=htmlspecialchars(APP_NAME)?></b><a href="index.php">داشبورد</a><a href="customers.php">مشتریان</a><a href="wallets.php">کیف پول</a><a href="payments.php">پرداخت‌ها</a><a href="orders.php">سفارش‌ها</a><a href="bots.php">ربات‌ها</a><a href="devices.php">دستگاه‌ها</a><a href="delivery_builder.php">ساخت دستی</a><a href="apk.php">APK</a><a href="logs.php">گزارش‌ها</a><a href="settings.php">تنظیمات</a><a class="active" href="template.php">قالب دلخواه</a></div>
<div class="wrap">
<h1>قالب دلخواه — HTML سفارشی برای داخل برنامه</h1>
<?php if($msg): ?><div class="card" style="background:<?=$err?'#7f1d1d':'#14532d'?>;word-break:break-all"><?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="card">
<p style="font-size:13px;color:#cbd5e1">فایل <b>.html</b> دلخواه را آپلود کنید — طرح داخل برنامه (WebView) دقیقاً مثل همین HTML نمایش داده می‌شود. بعد از آپلود، <b>توکن</b> را در <a href="delivery_builder.php" style="color:#38bdf8">ساخت دستی</a> بگذارید و APK را نصب کنید. پنل مدیریت و همه قابلیت‌های قبلی حفظ می‌شود.</p>
<p style="font-size:12px;color:#94a3b8">آدرس فعلی WebView: <code><?=htmlspecialchars($currentUrl)?></code> <?= $hasCustom ? '<span style="color:#22c55e">— قالب دلخواه فعال</span>' : '<span style="color:#f59e0b">— پیش‌فرض</span>' ?></p>
<?php if($hasCustom): ?>
<p style="font-size:12px">قالب فعال: <code>templates/custom.html</code> — حجم: <?=$customSize?> — هش: <code><?=$customHash?></code> — تاریخ: <?=$customTime?></p>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<a href="../templates/custom.html" target="_blank" style="background:#38bdf8;color:#082f49;padding:8px 12px;border-radius:8px;text-decoration:none">👁 پیش‌نمایش قالب</a>
<a href="<?=htmlspecialchars($currentUrl)?>" target="_blank" style="background:#334155;color:#e2e8f0;padding:8px 12px;border-radius:8px;text-decoration:none">🔗 بازکردن WebView URL</a>
</div>
<?php endif; ?>
</div>

<div class="card">
<h3>آپلود قالب HTML</h3>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<input type="file" name="template" accept=".html,.htm,text/html" required>
<p style="font-size:11px;color:#94a3b8">حداکثر 5MB — فقط .html — کد PHP مجاز نیست — فایل همیشه با نام <code>custom.html</code> ذخیره و <code>webview_url</code> خودکار به <code>/templates/custom.html</code> تنظیم می‌شود.</p>
<button style="background:#22c55e;color:#052e16;width:100%">📤 آپلود و فعال‌سازی قالب</button>
</form>
<form method="post" style="margin-top:8px"><input type="hidden" name="action" value="reset"><button style="background:#7f1d1d;color:#fecaca;width:100%" onclick="return confirm('حذف قالب دلخواه و برگشت به پیش‌فرض؟')">♻️ حذف قالب و برگشت به پیش‌فرض</button></form>
</div>

<div class="card">
<h3>تنظیم دستی WebView URL</h3>
<p style="font-size:11px;color:#94a3b8">اگر می‌خواهید قالب را از آدرس دیگری (مثلاً هاست جدا) لود کنید، اینجا URL را دستی تغییر دهید.</p>
<form method="post"><input type="hidden" name="action" value="save_url"><input name="webview_url" value="<?=htmlspecialchars($currentUrl)?>" dir="ltr" placeholder="https://example.com/template.html"><button style="background:#334155;color:#e2e8f0;width:100%;margin-top:6px">ذخیره URL</button></form>
</div>

<div class="card" style="font-size:12px;color:#94a3b8">
<h3 style="color:#e2e8f0">نکات</h3>
<ul style="line-height:1.9">
<li>قالب در <code>public_html/templates/custom.html</code> (قابل دسترسی وب) و پشتیبان در <code>private/templates/custom.html</code> ذخیره می‌شود.</li>
<li>برای طرح‌های چندفایله (CSS/JS/عکس) همه را داخل یک HTML (inline) قرار دهید یا آنها را جداگانه در <code>public_html/templates/assets/</code> آپلود و در HTML با مسیر نسبی <code>assets/style.css</code> ارجاع دهید.</li>
<li>پس از آپلود، <b>توکن ربات</b> را در <code>ساخت دستی</code> ثبت کنید — APK با <code>X-API-Key</code> به <code>/api/telegram.php</code> وصل و طرح جدید را از همین قالب لود می‌کند.</li>
<li>پنل مدیریت و تمام قابلیت‌های قبلی (کیف پول، پرداخت، سفارش، دستگاه، لاگ) بدون تغییر باقی می‌مانند.</li>
<li>فقط همین فایل HTML جایگزین می‌شود — هیچ تغییری در کدهای دیگر ایجاد نمی‌شود.</li>
</ul>
</div>

<?php if($hasCustom): ?>
<div class="card">
<h3>پیش‌نمایش زنده (iframe)</h3>
<iframe src="../templates/custom.html" style="width:100%;height:480px;border:1px solid #334155;border-radius:8px;background:#fff"></iframe>
</div>
<?php endif; ?>

</div></body></html>
