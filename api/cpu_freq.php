<?php
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'get';

if ($action === 'get') {
    $governor = trim(@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor') ?: '--');
    $cur_freq = intval(trim(@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq') ?: 0));
    echo json_encode([
        'governor' => $governor,
        'freq_mhz' => $cur_freq ? round($cur_freq / 1000) : null,
    ]);
    exit;
}

if ($action === 'set') {
    $valid = ['powersave', 'ondemand', 'performance', 'schedutil'];
    $g = $_GET['governor'] ?? '';
    if (!in_array($g, $valid, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid governor']);
        exit;
    }
    shell_exec('sudo /usr/local/bin/set_cpu_governor ' . escapeshellarg($g) . ' 2>&1');
    $new = trim(@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor') ?: '');
    echo json_encode(['ok' => $new === $g, 'governor' => $new]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
