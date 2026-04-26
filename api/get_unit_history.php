<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    $unit_id = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    
    if (!$unit_id) {
        throw new Exception('缺少房源 ID');
    }
    
    $pdo = DB::connect();
    
    // Get records with priority
    $stmt = $pdo->prepare("
        SELECT *,
               CASE record_type 
                   WHEN 'closing' THEN 1 
                   WHEN 'manual' THEN 2 
                   WHEN 'daily' THEN 3 
                   ELSE 4
               END as priority
        FROM electricity_readings 
        WHERE unit_id = ? 
        ORDER BY record_date DESC, priority ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$unit_id, $limit, $offset]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'records' => $records
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
