<?php
header('Content-Type: application/json');
$json = @file_get_contents('http://127.0.0.1:5001/api/temp_history');
echo $json ?: json_encode([]);
