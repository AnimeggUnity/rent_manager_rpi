<?php
// api/import_meter.php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0); // 禁止直接輸出錯誤
error_reporting(E_ALL);

// 註冊 Shutdown Function 以捕獲 Fatal Error
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "系統嚴重錯誤: " . $error['message'] . " in " . $error['file'] . " line " . $error['line']]);
        exit;
    }
});

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meter_report_query.php';

try {
    // 1. 從 POST 取得帳密（不存檔，用完即丟）
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (empty($username) || empty($password)) {
        throw new Exception("請輸入帳號與密碼");
    }
    $config = ['username' => $username, 'password' => $password];

    // 2. 獲取合約與房間資訊 (Smart Filter 準備)
    $pdo = DB::connect();
    
    // 取得所有房間與對應的"生效中"合約結算日
    // 如果房間沒有生效租客，則不在 tenants 表中撈出(或為 null)
    $sql = "
        SELECT u.id, u.name as unit_name, t.billing_cycle_day, t.contract_start 
        FROM units u 
        LEFT JOIN tenants t ON u.id = t.unit_id AND t.is_active = 1
        WHERE u.is_active = 1
    ";
    $units = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // 建立查找表: Unit Name => Info
    $unitInfoMap = [];
    foreach ($units as $u) {
        $unitName = trim($u['unit_name']); // 增加 trim 確保資料庫尾巴沒空白
        
        // 決定結算日: 有 billing_cycle_day 用之，否則用 contract_start 的日
        $billDay = 1;
        if (!empty($u['billing_cycle_day'])) {
            $billDay = (int)$u['billing_cycle_day'];
        } elseif (!empty($u['contract_start'])) {
            $billDay = (int)date('d', strtotime($u['contract_start']));
        }
        
        $unitInfoMap[$unitName] = [
            'id' => $u['id'], // Added ID
            'name' => $unitName, // Added Name
            'has_tenant' => !empty($u['billing_cycle_day']) || !empty($u['contract_start']),
            'bill_day' => $billDay
        ];
    }
    
    // 3. 執行爬蟲查詢
    // 直接在建構子傳入帳密
    $query = new MeterReportQuery($config['username'], $config['password']);
    
    if (!$query->login()) {
        throw new Exception("登入失敗，請檢查帳號密碼");
    }
    
    // 檢查是否為強制完整匯入或自訂天數
    $forceFull = isset($_GET['force_full']) && $_GET['force_full'] === '1';
    $customDays = isset($_GET['custom_days']) ? (int)$_GET['custom_days'] : 0;
    
    // 智慧計算查詢日期範圍
    $endDate = date('Y-m-d');
    $beginDate = date('Y-m-d', strtotime('-3 months')); // 預設 3 個月
    $queryStrategy = 'full';
    
    if ($customDays > 0) {
        // 自訂天數模式
        $customDays = min(365, max(1, $customDays)); // 限制 1-365 天
        $beginDate = date('Y-m-d', strtotime("-{$customDays} days"));
        $queryStrategy = 'custom';
    } elseif (!$forceFull) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'last_meter_import_date'");
        $stmt->execute();
        $lastImport = $stmt->fetchColumn();
        
        if ($lastImport) {
            $daysSinceLastImport = (strtotime($endDate) - strtotime($lastImport)) / 86400;
            
            if ($daysSinceLastImport <= 45) {
                // 定期登入：只查詢上次日期前 7 天到今天（容錯緩衝）
                $beginDate = date('Y-m-d', strtotime($lastImport . ' -7 days'));
                $queryStrategy = 'incremental';
            }
            // 否則使用預設的 3 個月範圍
        }
    } else {
        $queryStrategy = 'force_full';
    }
    
    // 查詢資料
    $rawResult = $query->queryDateRange($beginDate, $endDate);
    if (!$rawResult['success']) {
        throw new Exception("查詢失敗: " . ($rawResult['error'] ?? '未知錯誤'));
    }
    
    // 嘗試只提取表格
    $tableHtml = $query->extractTableOnly($rawResult['data']);
    
    // 解析資料
    $parsedData = $query->parseAndCleanData($tableHtml ?? $rawResult['data']);
    if (empty($parsedData)) {
        throw new Exception("解析失敗或無資料");
    }
    
    // 4. 獲取資料庫已有的紀錄 (近 3 個月)，用來過濾重複
    $existingReadings = [];
    $stmt = $pdo->query("SELECT unit_id, record_date, reading_value FROM electricity_readings WHERE record_date >= date('now', '-4 months')");
    while ($er = $stmt->fetch()) {
        $existingReadings[$er['unit_id'] . '_' . $er['record_date']] = (float)$er['reading_value'];
    }

    // 5. Smart Filter 邏輯應用
    $finalData = [];
    
    foreach ($parsedData as $row) {
        $room = $row['room'];
        $date = $row['date']; // YYYY-MM-DD
        $reading = (float)$row['reading'];
        
        $day = (int)date('d', strtotime($date));
        $isMonthEnd = ($date === date('Y-m-t', strtotime($date)));
        
        $status = 'ignore';
        $reason = '';
        
        if (isset($unitInfoMap[$room])) {
            $info = $unitInfoMap[$room];
            $row['unit_id'] = $info['id']; 
            $row['unit_display_name'] = $info['name']; 
            
            // 🔍 檢查是否已存在於資料庫
            $key = $info['id'] . '_' . $date;
            if (isset($existingReadings[$key])) {
                $dbVal = $existingReadings[$key];
                // 如果數值非常接近 (差異小於 0.1)，則視為已匯入
                if (abs($dbVal - $reading) < 0.11) {
                    $status = 'ignore';
                    $reason = '已匯入 (數值相似)';
                } else {
                    // 數值差異過大，可能是修正或異常
                    $status = 'attention';
                    $reason = "已存在紀錄，但數值不同 (DB: $dbVal, WEB: $reading)";
                }
            } else {
                // 資料庫尚無此紀錄，執行原有分類邏輯
                if ($info['has_tenant']) {
                    if ($day === $info['bill_day']) {
                        $status = 'settlement';
                        $reason = '合約結算日';
                    } elseif ($isMonthEnd) {
                        $status = 'month_end'; 
                        $reason = '月底';
                    }
                } else {
                    if ($isMonthEnd) {
                        $status = 'vacant_cost'; 
                        $reason = '空屋月底';
                    }
                }
            }
            
        } else {
            $status = 'unknown_unit';
            $reason = "系統無此房號 ($room)"; 
            $row['unit_id'] = null;
        }
        
        $row['status'] = $status;
        $row['reason'] = $reason;
        $row['reading'] = $reading; // 確保傳回 float
        
        $finalData[] = $row;
    }
    
    // 更新最後匯入時間（成功解析資料後）
    $updateStmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ('last_meter_import_date', ?, datetime('now'))");
    $updateStmt->execute([date('Y-m-d')]);
    
    echo json_encode([
        'success' => true, 
        'data' => $finalData,
        'query_range' => [
            'begin' => $beginDate,
            'end' => $endDate,
            'strategy' => $queryStrategy,
            'days' => round((strtotime($endDate) - strtotime($beginDate)) / 86400)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
