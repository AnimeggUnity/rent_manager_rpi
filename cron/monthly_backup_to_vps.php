<?php
/**
 * 每月備份：打包 DB + uploads → FTP 上傳到 VPS
 * crontab: 0 1 1 * * /usr/bin/php /home/rpi/rent_manager/cron/monthly_backup_to_vps.php >> /home/rpi/rent_manager/cron/monthly_backup.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config.php';

date_default_timezone_set('Asia/Taipei');

$log_prefix = "[" . date('Y-m-d H:i:s') . "] ";

// ── 1. 建立 ZIP ────────────────────────────────────────────────────────────────
$tmpZip = tempnam(sys_get_temp_dir(), 'backup_');
$zip = new ZipArchive();

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    echo $log_prefix . "ERROR: 無法建立 ZIP\n";
    exit(1);
}

// SQLite DB（先做安全備份再加入）
$safeDb = tempnam(sys_get_temp_dir(), 'db_');
$pdo = DB::connect();
$pdo->exec("VACUUM INTO '$safeDb'");
$zip->addFile($safeDb, 'rent_manager.sqlite');

// uploads 目錄
$uploadDir = realpath(__DIR__ . '/../uploads');
if ($uploadDir && is_dir($uploadDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $zip->addFile($file->getRealPath(), 'uploads/' . substr($file->getRealPath(), strlen($uploadDir) + 1));
        }
    }
}

$zip->close();
unlink($safeDb);

$size = round(filesize($tmpZip) / 1024, 1);
echo $log_prefix . "ZIP 建立完成（{$size} KB）\n";

// ── 2. FTP 上傳 ────────────────────────────────────────────────────────────────
$ftpUrl = sc_FTP_HOST . sc_FTP_BACKUP_PATH;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL        => $ftpUrl,
    CURLOPT_USERPWD    => sc_FTP_USER . ':' . sc_FTP_PASS,
    CURLOPT_UPLOAD     => true,
    CURLOPT_INFILE     => fopen($tmpZip, 'r'),
    CURLOPT_INFILESIZE => filesize($tmpZip),
    CURLOPT_TIMEOUT    => 120,
]);

$ok = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
unlink($tmpZip);

if (!$ok || $err) {
    echo $log_prefix . "ERROR: FTP 上傳失敗：$err\n";
    exit(1);
}

echo $log_prefix . "備份上傳成功（{$size} KB）→ " . sc_FTP_BACKUP_PATH . "\n";
