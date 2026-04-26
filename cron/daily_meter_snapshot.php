<?php
/**
 * 每日 00:00 從 Flask 電表 API 擷取度數，存入 electricity_readings
 * crontab: 0 0 * * * /usr/bin/php /home/rpi/rent_manager/cron/daily_meter_snapshot.php >> /home/rpi/rent_manager/cron/meter_snapshot.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('Asia/Taipei');

// Flask meter ID → PHP unit_id（由 /api/status 的 id/room 欄位確認）
const METER_TO_UNIT = [
    6 => 1,  // 301室
    5 => 3,  // 302室
    1 => 5,  // 303室
    4 => 6,  // 401室
    3 => 7,  // 402室
    2 => 8,  // 403室
];

$record_date = date('Y-m-d');
$log_prefix  = "[" . date('Y-m-d H:i:s') . "] ";

// 從 Flask API 取得即時度數
$json = @file_get_contents('http://127.0.0.1:5001/api/status');
if ($json === false) {
    echo $log_prefix . "ERROR: 無法連接 Flask API\n";
    exit(1);
}

$data = json_decode($json, true);
if (!$data) {
    echo $log_prefix . "ERROR: API 回傳無法解析\n";
    exit(1);
}

// 相容新格式 {"rooms":[...],"time":"..."} 與舊格式 [...]
$meters = isset($data['rooms']) ? $data['rooms'] : $data;

$db = DB::connect();
$ok = 0;
$skip = 0;

foreach ($meters as $m) {
    $room    = $m['room'] ?? '';
    $kwh     = $m['kwh']  ?? null;
    $unit_id = METER_TO_UNIT[$m['id'] ?? 0] ?? null;

    if ($unit_id === null || $kwh === null || $m['status'] === '---') {
        continue;
    }

    // 查前一天的度數以計算 diff
    $prev = $db->prepare("
        SELECT reading_value FROM electricity_readings
        WHERE unit_id = ? AND record_date < ? AND record_type = 'daily'
        ORDER BY record_date DESC LIMIT 1
    ");
    $prev->execute([$unit_id, $record_date]);
    $prev_row  = $prev->fetch();
    $diff      = $prev_row ? round($kwh - $prev_row['reading_value'], 2) : 0;
    if ($diff < 0) $diff = 0;

    $stmt = $db->prepare("
        INSERT OR IGNORE INTO electricity_readings
            (unit_id, record_date, reading_value, diff_value, record_type)
        VALUES (?, ?, ?, ?, 'daily')
    ");
    $stmt->execute([$unit_id, $record_date, $kwh, $diff]);

    if ($stmt->rowCount() > 0) {
        echo $log_prefix . "OK  {$room} (unit {$unit_id}): {$kwh} kWh, diff +{$diff}\n";
        $ok++;
    } else {
        echo $log_prefix . "SKIP {$room}: {$record_date} 已存在\n";
        $skip++;
    }
}

echo $log_prefix . "完成：{$ok} 筆新增，{$skip} 筆略過\n";
