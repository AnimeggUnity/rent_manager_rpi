<?php
/**
 * 依部署環境決定硬體監控面板。
 * R5S 跑 Docker → r5s；裸機且可讀溫度（RPi）→ rpi；其餘 → none。
 */
function detect_hw_panel(): string
{
    if (file_exists('/.dockerenv')) {
        return 'r5s';
    }

    // 裸機 RPi：device-tree model 或 thermal zone 任一存在即可
    if (file_exists('/sys/firmware/devicetree/base/model')
        || file_exists('/sys/class/thermal/thermal_zone0/temp')) {
        return 'rpi';
    }

    return 'none';
}

/**
 * 即時電表監控頁面 URL。
 * RPi：nginx 反代 /meter → Flask:5001。
 * Docker(R5S)：無反代，直接開宿主 :5001（同源主機、換 port）。
 */
function meter_monitor_url(): string
{
    if (!file_exists('/.dockerenv')) {
        return '/meter';
    }

    $host = $_SERVER['HTTP_HOST'] ?? '192.168.101.1';
    $hostname = preg_replace('/:\d+$/', '', $host) ?: '192.168.101.1';
    return 'http://' . $hostname . ':5001/';
}

/**
 * Flask 電表 API 基底 URL（PHP 伺服器端呼叫用）。
 * RPi：本機 5001；Docker：同 compose 網路的 rent_meter 服務名。
 */
function flask_api_base_url(): string
{
    if (file_exists('/.dockerenv')) {
        return 'http://rent_meter:5001';
    }
    return 'http://127.0.0.1:5001';
}
