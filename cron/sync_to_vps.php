<?php
/**
 * 同步 RPi electricity_readings → VPS save_reading_batch API
 * crontab: 5 0 * * * /usr/bin/php /home/rpi/rent_manager/cron/sync_to_vps.php >> /home/rpi/rent_manager/cron/sync_to_vps.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config.php';

date_default_timezone_set('Asia/Taipei');

const VPS_API   = 'http://580.blias.com/rent_manager/api/save_reading_batch.php';
const SYNC_DAYS = 30;

$log_prefix = "[" . date('Y-m-d H:i:s') . "] ";
$since = date('Y-m-d', strtotime('-' . SYNC_DAYS . ' days'));

$db = DB::connect();
$stmt = $db->prepare("
    SELECT unit_id, record_date, reading_value
    FROM electricity_readings
    WHERE record_date >= ? AND record_type = 'daily'
    ORDER BY record_date, unit_id
");
$stmt->execute([$since]);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo $log_prefix . "沒有資料需要同步\n";
    exit(0);
}

$readings = [];
foreach ($rows as $r) {
    $readings[] = [
        'unit_id' => (int)$r['unit_id'],
        'date'    => $r['record_date'],
        'reading' => (float)$r['reading_value'],
    ];
}

$payload = json_encode([
    'api_key'  => sc_API_KEY,
    'readings' => $readings,
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 30,
    ]
]);

$response = @file_get_contents(VPS_API, false, $ctx);
if ($response === false) {
    echo $log_prefix . "ERROR: 無法連接 VPS\n";
    exit(1);
}

$result = json_decode($response, true);
if ($result && $result['success']) {
    $msg = "成功：{$result['count']} 筆新增";
    if (!empty($result['errors'])) {
        $skipped = count($result['errors']);
        $msg .= "，略過 {$skipped} 筆（重複或異常）";
    }
    echo $log_prefix . $msg . "\n";
} else {
    echo $log_prefix . "ERROR: " . ($result['message'] ?? $response) . "\n";
    exit(1);
}
