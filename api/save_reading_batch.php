<?php
// api/save_reading_batch.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("只允許 POST 請求");
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $apiKey = $input['api_key'] ?? '';
    if ($apiKey !== sc_API_KEY) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!isset($input['readings']) || !is_array($input['readings'])) {
        throw new Exception("無效的資料格式");
    }
    
    $readings = $input['readings'];
    $pdo = DB::connect();
    
    $successCount = 0;
    $errors = [];
    
    foreach ($readings as $r) {
        $unit_id = (int)$r['unit_id'];
        $date = $r['date'];
        $value = (float)$r['reading'];
        
        if (!$unit_id || !$date) {
            $errors[] = "資料不完整: " . json_encode($r);
            continue;
        }
        
        try {
            // 查看是否已有當日紀錄 (避免重複與 Unique Constraint 衝突)
            $stmt = $pdo->prepare("SELECT id FROM electricity_readings WHERE unit_id = ? AND record_date = ? AND record_type = 'daily'");
            $stmt->execute([$unit_id, $date]);
            if ($stmt->fetch()) {
                 // 已存在，暫時跳過或更新? 策略: 跳過並回報
                 $errors[] = "{$date} 紀錄已存在 (ID: $unit_id)";
                 continue;
            }
            
            // 計算差異值 (Diff)
            $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
            $stmt->execute([$unit_id, $date]);
            $prev = $stmt->fetch();
            
            $diff_value = 0;
            if ($prev) {
                $diff_value = $value - (float)$prev['reading_value'];
            }
            
            if ($diff_value < 0) {
                $errors[] = "讀數異常: {$date} (ID: $unit_id) 小於前期";
                continue; 
            }
            
            // 寫入資料
            $stmt = $pdo->prepare("INSERT INTO electricity_readings (unit_id, record_date, reading_value, diff_value, record_type) VALUES (?, ?, ?, ?, 'daily')");
            $stmt->execute([$unit_id, $date, $value, $diff_value]);
            
            $successCount++;
            
        } catch (Exception $e) {
            $errors[] = "儲存錯誤 (ID: $unit_id): " . $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => $successCount,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
