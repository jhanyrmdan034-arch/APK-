<?php
/**
 * BABARPHANTOM — عیب‌یاب مسیر نصب
 * این فایل را در پوشه‌ای که «دامنه» به آن اشاره می‌کند (معمولاً public_html) بگذار
 * و در مرورگر باز کن:  https://دامنه/whereami.php
 * سپس خروجی را کامل کپی کن.
 * هیچ رمز یا کلیدی نمایش داده نمی‌شود.
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

function yn(bool $b): string { return $b ? '✅ هست' : '❌ نیست'; }
function perm(string $p): string {
    if (!file_exists($p)) return '—';
    $p2 = @fileperms($p);
    if ($p2 === false) return '?';
    return substr(sprintf('%o', $p2), -4);
}
function size(string $p): string {
    if (!is_file($p)) return '—';
    $s = @filesize($p);
    return $s === false ? '?' : round($s / 1024, 1) . ' KB';
}

$dir       = __DIR__;
$parent    = dirname($dir);
$docroot   = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$host      = $_SERVER['HTTP_HOST'] ?? '';
$uri       = $_SERVER['REQUEST_URI'] ?? '';
$script    = $_SERVER['SCRIPT_NAME'] ?? '';

$checks = [
    'install.php'                        => $dir . '/install.php',
    'admin/index.php'                    => $dir . '/admin/index.php',
    'admin/delivery_builder.php'         => $dir . '/admin/delivery_builder.php',
    'admin/apk.php'                      => $dir . '/admin/apk.php',
    'api/config.php'                     => $dir . '/api/config.php',
    'public_html/install.php (تودرتو)'   => $dir . '/public_html/install.php',
    'private/security/helpers.php (کنار)' => $parent . '/private/security/helpers.php',
    'private/config/config.php (کنار)'   => $parent . '/private/config/config.php',
    'private/security/helpers.php (داخل)' => $dir . '/private/security/helpers.php',
    'private/config/config.php (داخل)'   => $dir . '/private/config/config.php',
    'private/apk (کنار)'                 => $parent . '/private/apk',
    'private/logs (کنار)'                => $parent . '/private/logs',
];

$scan = static function (string $d): array {
    $out = [];
    $items = @scandir($d);
    if ($items === false) return ['(قابل خواندن نیست)'];
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $out[] = $i . (is_dir($d . '/' . $i) ? '/' : '');
    }
    sort($out);
    return array_slice($out, 0, 40);
};

$docrootEditable = $docroot !== '' && realpath($dir) === realpath($docroot);
?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>عیب‌یاب نصب — BABARPHANTOM</title>
<style>
body{font-family:monospace,Tahoma;background:#0b1120;color:#e2e8f0;margin:0;padding:16px;font-size:13px;line-height:1.9}
h1{font-size:16px}h2{font-size:14px;margin:18px 0 6px;color:#7dd3fc}
table{border-collapse:collapse;width:100%;margin-bottom:10px}
td,th{border:1px solid #334155;padding:6px 8px;text-align:right;vertical-align:top}
th{background:#1e293b}
code{background:#0f172a;padding:1px 5px;border-radius:4px;direction:ltr;display:inline-block;word-break:break-all}
.ok{color:#4ade80}.bad{color:#f87171}
.box{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:12px;margin-bottom:12px;direction:ltr;text-align:left}
.verdict{background:#14532d;border:1px solid #22c55e;border-radius:10px;padding:12px;margin:12px 0}
.verdict.bad{background:#7f1d1d;border-color:#ef4444}
.p{color:#94a3b8}
</style></head><body>
<h1>🔍 عیب‌یاب نصب BABARPHANTOM</h1>

<h2>۱) محیط اجرا</h2>
<div class="box">
SCRIPT_FILENAME = <?= htmlspecialchars((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?><br>
__DIR__         = <?= htmlspecialchars($dir) ?><br>
DOCUMENT_ROOT   = <?= htmlspecialchars($docroot) ?><br>
HTTP_HOST       = <?= htmlspecialchars($host) ?><br>
REQUEST_URI     = <?= htmlspecialchars($uri) ?><br>
SCRIPT_NAME     = <?= htmlspecialchars($script) ?><br>
این فایل در ریشه دامنه است؟ <b class="<?= $docrootEditable ? 'ok' : 'bad' ?>"><?= $docrootEditable ? 'بله ✅' : 'نه ❌ (این فایل جای اشتباهی است)' ?></b><br>
PHP <?= htmlspecialchars(PHP_VERSION) ?> · upload_max_filesize=<?= htmlspecialchars((string)ini_get('upload_max_filesize')) ?> · post_max_size=<?= htmlspecialchars((string)ini_get('post_max_size')) ?> · memory_limit=<?= htmlspecialchars((string)ini_get('memory_limit')) ?>
</div>

<h2>۲) فایل‌های مهم — کدام هست، کدام نیست</h2>
<table>
<tr><th>فایل</th><th>وضعیت</th><th>حجم</th><th>مجوز</th></tr>
<?php foreach ($checks as $label => $path):
    $exists = file_exists($path);
?>
<tr>
  <td><?= htmlspecialchars($label) ?><br><code><?= htmlspecialchars($path) ?></code></td>
  <td class="<?= $exists ? 'ok' : 'bad' ?>"><?= yn($exists) ?></td>
  <td><?= htmlspecialchars(size($path)) ?></td>
  <td><?= htmlspecialchars(perm($path)) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>۳) محتویات این پوشه (<?= htmlspecialchars($dir) ?>)</h2>
<div class="box"><?= htmlspecialchars(implode('  ·  ', $scan($dir))) ?></div>

<h2>۴) محتویات پوشه بالایی (<?= htmlspecialchars($parent) ?>)</h2>
<div class="box"><?= htmlspecialchars(implode('  ·  ', $scan($parent))) ?></div>

<?php
$installHere = file_exists($dir . '/install.php');
$installNested = file_exists($dir . '/public_html/install.php');
$adminHere = file_exists($dir . '/admin/index.php') || file_exists($dir . '/admin/delivery_builder.php');
$privateSibling = file_exists($parent . '/private/security/helpers.php') || is_dir($parent . '/private');
$privateInside  = is_dir($dir . '/private');

if ($installHere || $adminHere) {
    echo '<div class="verdict"><b class="ok">✅ ساختار درست است.</b><br>';
    if ($installHere) echo 'نصاب را باز کن: <code>https://' . htmlspecialchars($host) . '/install.php</code><br>';
    else echo 'پنل را باز کن: <code>https://' . htmlspecialchars($host) . '/admin/</code><br>';
    echo '</div>';
} elseif ($installNested) {
    echo '<div class="verdict bad"><b class="bad">❌ خطای مسیر: فایل‌ها یک پوشه تو رفته‌اند.</b><br>';
    echo 'الان مسیر <code>' . htmlspecialchars($dir . '/public_html/install.php') . '</code> است، ولی دامنه به <code>' . htmlspecialchars($dir) . '</code> اشاره می‌کند.<br>';
    echo 'راه‌حل: در File Manager داخل <code>public_html/public_html</code> برو، همه را Select All → Move → مقصد <code>' . htmlspecialchars($docroot ?: 'DOCUMENT_ROOT') . '</code> . پس از انتقال پوشه خالی <code>public_html/public_html</code> را حذف کن.</div>';
} else {
    echo '<div class="verdict bad"><b class="bad">❌ نه install.php پیدا شد نه admin/ — یعنی فایل‌ها در این مسیر نیستند.</b><br>';
    echo 'احتمال‌ها: (۱) فقط فایل پچ را آپلود کرده‌ای که install.php ندارد، (۲) زیپ در جای دیگری Extract شده، (۳) Document Root این دامنه پوشه دیگری است.<br>';
    echo 'راه‌حل: در File Manager محتویات <code>' . htmlspecialchars($docroot ?: 'public_html') . '</code> را ببین؛ اگر خالی است، ZIP کامل (<code>BABARPHANTOM_HOST_READY.zip</code>) را در Home Extract کن و بعد دوباره همین صفحه را باز کن.</div>';
}

if ($privateSibling) {
    echo '<p class="p">✅ پوشه private کنار public_html پیدا شد — همان ساختار درست است.</p>';
} elseif ($privateInside) {
    echo '<p class="p">⚠️ پوشه private <b>داخل</b> public_html است — نامناسب امنیتی. باید به کنار public_html منتقل شود.</p>';
} else {
    echo '<p class="p">❌ پوشه private در هیچ‌کدام از مسیرهای کنار یا داخل پیدا نشد — پکیج ناقص Extract شده.</p>';
}
?>
<p class="p">این فایل فقط اطلاعات ساختار را نشان می‌دهد و رمز/کلید چاپ نمی‌کند. بعد از رفع مشکل حذفش کن.</p>
</body></html>
