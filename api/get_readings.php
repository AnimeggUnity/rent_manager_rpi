<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$unit_id = $_GET['unit_id'] ?? 0;

if ($unit_id) {
    $stmt = DB::connect()->prepare("SELECT id, record_date, reading_value, record_type FROM electricity_readings WHERE unit_id = ? ORDER BY record_date DESC LIMIT 60");
    $stmt->execute([$unit_id]);
    echo json_encode($stmt->fetchAll());
} else {
    echo json_encode([]);
}
