<?php
header('Content-Type: application/json');
$raw = shell_exec('vcgencmd measure_temp 2>/dev/null');
preg_match('/temp=([\d.]+)/', $raw ?? '', $m);
echo json_encode(['temp' => isset($m[1]) ? $m[1] . '°C' : '--']);
