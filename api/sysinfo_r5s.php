<?php
/**
 * R5S（Docker）硬體狀態 API —— 多感測器、唯讀，不含調速。
 */
header('Content-Type: application/json');

function read_int_file(string $path): ?int
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = trim((string) @file_get_contents($path));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (int) $raw;
}

function temp_milli_to_c(?int $milli): ?float
{
    return $milli === null ? null : round($milli / 1000, 1);
}

function color_for_temp(?float $c): string
{
    if ($c === null) {
        return '#6c757d';
    }
    if ($c >= 80) {
        return '#e53935';
    }
    if ($c >= 70) {
        return '#fb8c00';
    }
    return '#2e7d32';
}

/** @return array<string, array{path:string, temps:list<array{label:string,c:float}>, fan: ?int, pwm: ?int}> */
function scan_hwmon(): array
{
    $out = [];
    foreach (glob('/sys/class/hwmon/hwmon*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = trim((string) @file_get_contents($dir . '/name'));
        if ($name === '') {
            $name = basename($dir);
        }
        $entry = ['path' => $dir, 'temps' => [], 'fan' => null, 'pwm' => null];

        foreach (glob($dir . '/temp*_input') ?: [] as $input) {
            $idx = preg_replace('/^temp(\d+)_input$/', '$1', basename($input));
            $labelPath = $dir . '/temp' . $idx . '_label';
            $label = is_readable($labelPath)
                ? trim((string) @file_get_contents($labelPath))
                : ('temp' . $idx);
            $c = temp_milli_to_c(read_int_file($input));
            if ($c !== null) {
                $entry['temps'][] = ['label' => $label, 'c' => $c];
            }
        }

        foreach (glob($dir . '/fan*_input') ?: [] as $fanInput) {
            $rpm = read_int_file($fanInput);
            if ($rpm !== null) {
                $entry['fan'] = $rpm;
                break;
            }
        }

        $pwm = read_int_file($dir . '/pwm1');
        if ($pwm !== null) {
            $entry['pwm'] = $pwm; // 0–255
        }

        $out[$name] = $entry;
    }
    return $out;
}

function thermal_by_type(): array
{
    $map = [];
    foreach (glob('/sys/class/thermal/thermal_zone*', GLOB_ONLYDIR) ?: [] as $dir) {
        $type = trim((string) @file_get_contents($dir . '/type'));
        $c = temp_milli_to_c(read_int_file($dir . '/temp'));
        if ($type !== '' && $c !== null) {
            $map[$type] = $c;
        }
    }
    return $map;
}

$hwmon = scan_hwmon();
$thermal = thermal_by_type();

$cpu_c = $thermal['cpu-thermal'] ?? ($hwmon['cpu_thermal']['temps'][0]['c'] ?? null);
$gpu_c = $thermal['gpu-thermal'] ?? ($hwmon['gpu_thermal']['temps'][0]['c'] ?? null);

$nvme_c = null;
if (isset($hwmon['nvme']['temps'][0])) {
    $nvme_c = $hwmon['nvme']['temps'][0]['c'];
}

$nic_temps = [];
foreach ($hwmon as $name => $info) {
    if (str_starts_with($name, 'r8169') && !empty($info['temps'])) {
        $nic_temps[] = [
            'name'  => $name,
            'c'     => $info['temps'][0]['c'],
            'color' => color_for_temp($info['temps'][0]['c']),
        ];
    }
}

$fan_rpm = null;
$fan_pwm = null;
$fan_pwm_pct = null;
if (isset($hwmon['pwmfan'])) {
    $fan_rpm = $hwmon['pwmfan']['fan'];
    $fan_pwm = $hwmon['pwmfan']['pwm'];
    if ($fan_pwm !== null) {
        $fan_pwm_pct = (int) round($fan_pwm / 255 * 100);
    }
}

$freq_raw = read_int_file('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
$freq_min_raw = read_int_file('/sys/devices/system/cpu/cpu0/cpufreq/scaling_min_freq');
$freq_max_raw = read_int_file('/sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq');
$governor = trim((string) (@file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor') ?: '--'));
$freq_mhz = $freq_raw ? (int) round($freq_raw / 1000) : null;
$freq_min_mhz = $freq_min_raw ? (int) round($freq_min_raw / 1000) : null;
$freq_max_mhz = $freq_max_raw ? (int) round($freq_max_raw / 1000) : null;
// min==max 表示被鎖在單一頻率（常見於降溫設定）
$freq_locked = ($freq_min_mhz !== null && $freq_max_mhz !== null && $freq_min_mhz === $freq_max_mhz);

$meminfo = @file_get_contents('/proc/meminfo') ?: '';
preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt);
preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma);
$mem_total_mb = (int) round(intval($mt[1] ?? 0) / 1024);
$mem_avail_mb = (int) round(intval($ma[1] ?? 0) / 1024);
$mem_used_mb  = $mem_total_mb - $mem_avail_mb;

// /app 掛在宿主 NVMe 上，用它反映實際資料碟容量
$disk_path = is_dir('/app') ? '/app' : '/';
$disk_total = @disk_total_space($disk_path) ?: 0;
$disk_free  = @disk_free_space($disk_path) ?: 0;
$disk_used_gb  = round(($disk_total - $disk_free) / 1073741824, 1);
$disk_total_gb = round($disk_total / 1073741824, 1);

$load  = sys_getloadavg();
$cores = (int) trim((string) (@shell_exec('nproc') ?: '4'));
$cpu_pct = min(100, round(($load[0] ?? 0) / max(1, $cores) * 100, 1));

$board_model = trim((string) (@file_get_contents('/proc/device-tree/model') ?: ''));
$board_model = rtrim($board_model, "\x00");
if ($board_model === '') {
    $board_model = 'NanoPi R5S';
}

$kernel = trim((string) (@shell_exec('uname -r') ?: '--'));

$uptime_sec = (int) trim(explode(' ', (string) (@file_get_contents('/proc/uptime') ?: '0'))[0]);
$uptime_days  = intdiv($uptime_sec, 86400);
$uptime_hours = intdiv($uptime_sec % 86400, 3600);
$uptime_str   = $uptime_days > 0
    ? "{$uptime_days}天 {$uptime_hours}時"
    : intdiv($uptime_sec % 3600, 60) . '分';

echo json_encode([
    'platform'      => 'r5s',
    'board_model'   => $board_model,
    'kernel'        => $kernel,
    'uptime'        => $uptime_str,
    'cpu_temp_c'    => $cpu_c,
    'gpu_temp_c'    => $gpu_c,
    'nvme_temp_c'   => $nvme_c,
    'nic_temps'     => $nic_temps,
    'fan_rpm'       => $fan_rpm,
    'fan_pwm'       => $fan_pwm,
    'fan_pwm_pct'   => $fan_pwm_pct,
    'governor'      => $governor,
    'freq_mhz'      => $freq_mhz,
    'freq_min_mhz'  => $freq_min_mhz,
    'freq_max_mhz'  => $freq_max_mhz,
    'freq_locked'   => $freq_locked,
    'cpu_pct'       => $cpu_pct,
    'mem_used_mb'   => $mem_used_mb,
    'mem_total_mb'  => $mem_total_mb,
    'disk_label'    => 'NVMe',
    'disk_used_gb'  => $disk_used_gb,
    'disk_total_gb' => $disk_total_gb,
    'temp_colors'   => [
        'cpu'  => color_for_temp($cpu_c),
        'gpu'  => color_for_temp($gpu_c),
        'nvme' => color_for_temp($nvme_c),
    ],
], JSON_UNESCAPED_UNICODE);
