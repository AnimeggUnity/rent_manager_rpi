<?php
require_once __DIR__ . '/../includes/platform.php';

header('Content-Type: application/json');
$json = @file_get_contents(flask_api_base_url() . '/api/temp_history');
echo $json ?: json_encode([]);
