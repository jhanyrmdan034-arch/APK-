<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

// Backup خودکار — اجرا via cron: 0 3 * * * php /path/to/private/scripts/backup.php
// خروجی: private/backups/backup_YYYYmmdd_HHiiss.sql.gz + tokens + logs

@mkdir(BACKUP_DIR, 0700, true);
@mkdir(LOG_DIR, 0700, true);
$ts = date('Ymd_His');
$logFile = LOG_DIR.'/backup.log';
function logmsg(string $m): void {
    global $logFile;
    $line = date('c')." $m".PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND|LOCK_EX);
    echo $line;
}

logmsg("Backup started $ts");

// 1) DB dump via mysqldump if available
$dbFile = BACKUP_DIR."/backup_{$ts}.sql.gz";
$cmd = "mysqldump -h ".escapeshellarg(DB_HOST)." -u ".escapeshellarg(DB_USER)." -p".escapeshellarg(DB_PASS)." ".escapeshellarg(DB_NAME)." 2>&1 | gzip > ".escapeshellarg($dbFile);
exec($cmd, $out, $ret);
if ($ret===0 && file_exists($dbFile)) {
    logmsg("DB backup ok: $dbFile (".round(filesize($dbFile)/1024,1)." KB)");
} else {
    logmsg("DB backup failed: ".implode(' ',array_slice($out,0,5)));
    // fallback: PHP dump via PDO (structure only)
    try {
        require_once __DIR__ . '/../security/helpers.php';
        $pdo=db();
        $tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $dump="-- Fallback dump $ts\n";
        foreach($tables as $t){
            $create=$pdo->query("SHOW CREATE TABLE `$t`")->fetch();
            $dump.=$create['Create Table'].";\n\n";
        }
        file_put_contents(BACKUP_DIR."/fallback_{$ts}.sql", $dump);
        logmsg("Fallback dump created");
    } catch(Throwable $e){ logmsg("Fallback failed: ".$e->getMessage()); }
}

// 2) Tokens backup
$tokFile = BACKUP_DIR."/tokens_{$ts}.tgz";
$tokSrc = dirname(__DIR__)."/telegram_tokens";
if(is_dir($tokSrc)){
    exec("tar czf ".escapeshellarg($tokFile)." -C ".escapeshellarg(dirname($tokSrc))." ".escapeshellarg(basename($tokSrc))." 2>&1", $o2, $r2);
    if($r2===0) logmsg("Tokens backup ok: $tokFile");
    else logmsg("Tokens backup failed");
}

// 3) Cleanup old backups (>30 days)
foreach(glob(BACKUP_DIR."/*.{sql.gz,sql,tgz}",GLOB_BRACE) as $f){
    if(time()-filemtime($f) > 30*86400){
        @unlink($f);
        logmsg("Cleaned old: $f");
    }
}
logmsg("Backup finished");
