<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = DB::connect();

$unitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$rangeDays = isset($_GET['range_days']) ? (int)$_GET['range_days'] : 90;

if ($unitId < 0) {
    echo json_encode(['labels' => [], 'usage' => [], 'readings' => [], 'summary' => []]);
    exit;
}

$rangeDays = max(7, min(365, $rangeDays));
$startDate = date('Y-m-d', strtotime('-' . $rangeDays . ' days'));

$priority = ['daily' => 1, 'manual' => 2, 'closing' => 3];

if ($unitId === 0) {
    $stmt = $pdo->prepare("SELECT er.unit_id, er.record_date, er.diff_value, er.record_type, u.name as unit_name FROM electricity_readings er JOIN units u ON u.id = er.unit_id AND u.is_active = 1 WHERE er.record_date >= ? ORDER BY er.record_date ASC, u.name ASC");
    $stmt->execute([$startDate]);
    $rows = $stmt->fetchAll();

    $byUnitDate = [];
    $unitNames = [];
    foreach ($rows as $row) {
        $key = $row['unit_id'] . '|' . $row['record_date'];
        $type = $row['record_type'] ?? 'daily';
        $p = $priority[$type] ?? 0;
        if (!isset($byUnitDate[$key]) || $p > $byUnitDate[$key]['priority']) {
            $byUnitDate[$key] = [
                'priority' => $p,
                'date' => $row['record_date'],
                'diff_value' => (float)$row['diff_value'],
                'unit_id' => (int)$row['unit_id']
            ];
        }
        $unitNames[(int)$row['unit_id']] = $row['unit_name'];
    }

    $byDate = [];
    $byUnit = [];
    foreach ($byUnitDate as $item) {
        $date = $item['date'];
        $unitIdKey = $item['unit_id'];
        if (!isset($byDate[$date])) {
            $byDate[$date] = 0.0;
        }
        if (!isset($byUnit[$unitIdKey])) {
            $byUnit[$unitIdKey] = [];
        }
        $byDate[$date] += $item['diff_value'];
        $byUnit[$unitIdKey][$date] = $item['diff_value'];
    }

    ksort($byDate);
    $labels = array_keys($byDate);
    $usage = [];
    $readings = [];
    $total = 0.0;
    $max = null;

    foreach ($byDate as $date => $value) {
        $usage[] = round($value, 2);
        $total += $value;
        $readings[] = round($total, 2);
        if ($max === null || $value > $max) {
            $max = $value;
        }
    }

    $avg = count($usage) > 0 ? $total / count($usage) : 0;

    $datasets = [];
    foreach ($byUnit as $unitIdKey => $valuesByDate) {
        $data = [];
        foreach ($labels as $date) {
            $data[] = round($valuesByDate[$date] ?? 0, 2);
        }
        $datasets[] = [
            'label' => $unitNames[$unitIdKey] ?? ('Unit ' . $unitIdKey),
            'data' => $data
        ];
    }

    echo json_encode([
        'mode' => 'overall',
        'labels' => $labels,
        'usage' => $usage,
        'readings' => $readings,
        'datasets' => $datasets,
        'line_label' => '累積用量',
        'subtitle' => '每日用量（各房源堆疊）',
        'summary' => [
            'total_kwh' => count($usage) > 0 ? round($total, 2) : null,
            'avg_kwh' => count($usage) > 0 ? round($avg, 2) : null,
            'max_kwh' => $max !== null ? round($max, 2) : null
        ]
    ]);
    exit;
}

$stmt = $pdo->prepare("SELECT record_date, reading_value, diff_value, record_type FROM electricity_readings WHERE unit_id = ? AND record_date >= ? ORDER BY record_date ASC");
$stmt->execute([$unitId, $startDate]);
$rows = $stmt->fetchAll();

$byDate = [];
foreach ($rows as $row) {
    $date = $row['record_date'];
    $type = $row['record_type'] ?? 'daily';
    $p = $priority[$type] ?? 0;

    if (!isset($byDate[$date]) || $p > $byDate[$date]['priority']) {
        $byDate[$date] = [
            'priority' => $p,
            'reading_value' => (float)$row['reading_value'],
            'diff_value' => (float)$row['diff_value']
        ];
    }
}

ksort($byDate);

$labels = [];
$usage = [];
$readings = [];

$total = 0.0;
$max = null;

foreach ($byDate as $date => $data) {
    $labels[] = $date;
    $usage[] = round($data['diff_value'], 2);
    $readings[] = round($data['reading_value'], 2);

    $total += $data['diff_value'];
    if ($max === null || $data['diff_value'] > $max) {
        $max = $data['diff_value'];
    }
}

$avg = count($usage) > 0 ? $total / count($usage) : 0;

echo json_encode([
    'labels' => $labels,
    'usage' => $usage,
    'readings' => $readings,
    'line_label' => '累積讀數',
    'subtitle' => '每日用量 + 累積讀數',
    'summary' => [
        'total_kwh' => count($usage) > 0 ? round($total, 2) : null,
        'avg_kwh' => count($usage) > 0 ? round($avg, 2) : null,
        'max_kwh' => $max !== null ? round($max, 2) : null
    ]
]);
