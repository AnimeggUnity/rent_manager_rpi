<?php
header('Content-Type: application/json');

// 溫度
$temp_raw = intval(trim(@file_get_contents('/sys/class/thermal/thermal_zone0/temp') ?: '0'));
$temp_c   = round($temp_raw / 1000, 1);

// CPU 頻率 & Governor
$freq_raw = intval(trim(@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq') ?: '0'));
$governor = trim(@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor') ?: '--');

// 記憶體（從 /proc/meminfo）
$meminfo = @file_get_contents('/proc/meminfo') ?: '';
preg_match('/MemTotal:\s+(\d+)/',     $meminfo, $mt);
preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma);
$mem_total_mb = round(intval($mt[1] ?? 0) / 1024);
$mem_avail_mb = round(intval($ma[1] ?? 0) / 1024);
$mem_used_mb  = $mem_total_mb - $mem_avail_mb;

// 磁碟
$disk_total    = disk_total_space('/');
$disk_free     = disk_free_space('/');
$disk_used_gb  = round(($disk_total - $disk_free) / 1073741824, 1);
$disk_total_gb = round($disk_total / 1073741824, 1);

// CPU 負載（1分鐘 load average → 百分比）
$load   = sys_getloadavg();
$cores  = intval(trim(@shell_exec('nproc') ?: '4'));
$cpu_pct = min(100, round($load[0] / $cores * 100, 1));

// 板子型號（/proc/device-tree/model）
$board_model = trim(@file_get_contents('/proc/device-tree/model') ?: '');
$board_model = rtrim($board_model, "\x00");  // 移除 null terminator

// 序號（/proc/cpuinfo）
$cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';
preg_match('/Serial\s*:\s*(\S+)/', $cpuinfo, $ser);
$serial = isset($ser[1]) ? substr($ser[1], -8) : '--';  // 只取末8碼

// OS 版本
$os = trim(@file_get_contents('/etc/os-release') ?: '');
preg_match('/PRETTY_NAME="([^"]+)"/', $os, $osm);
$os_name = $osm[1] ?? '--';

// 核心版本
$kernel = trim(@shell_exec('uname -r') ?: '--');

// 系統啟動時間
$uptime_sec = intval(trim(explode(' ', @file_get_contents('/proc/uptime') ?: '0')[0]));
$uptime_days  = intdiv($uptime_sec, 86400);
$uptime_hours = intdiv($uptime_sec % 86400, 3600);
$uptime_str   = $uptime_days > 0 ? "{$uptime_days}天 {$uptime_hours}時" : intdiv($uptime_sec % 3600, 60) . '分';

echo json_encode([
    'temp_c'        => $temp_c,
    'governor'      => $governor,
    'freq_mhz'      => $freq_raw ? intval(round($freq_raw / 1000)) : null,
    'cpu_pct'       => $cpu_pct,
    'mem_used_mb'   => $mem_used_mb,
    'mem_total_mb'  => $mem_total_mb,
    'disk_used_gb'  => $disk_used_gb,
    'disk_total_gb' => $disk_total_gb,
    'board_model'   => $board_model,
    'serial'        => $serial,
    'os_name'       => $os_name,
    'kernel'        => $kernel,
    'uptime'        => $uptime_str,
]);
