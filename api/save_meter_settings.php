<?php
// api/save_meter_settings.php
// 儲存電表查詢的帳號密碼設定到本地 JSON 檔案
// 注意：實際生產環境建議加密儲存或使用更安全的機制

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("只允許 POST 請求");
    }
    
    // 檢查參數
    if (!isset($_POST['username']) || !isset($_POST['password'])) {
        throw new Exception("請提供帳號與密碼");
    }
    
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (empty($username) || empty($password)) {
        throw new Exception("帳號與密碼不可為空");
    }
    
    // 設定檔路徑
    // 儲存在專案根目錄或 config 目錄下
    $configDir = __DIR__ . '/../config';
    if (!file_exists($configDir)) {
        mkdir($configDir, 0755, true);
    }
    $configFile = $configDir . '/meter_config.json';
    
    // 準備資料
    // TODO: 建議對密碼進行加密處理，此處為範例實作
    $data = [
        'username' => $username,
        'password' => $password,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // 寫入檔案
    if (file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) === false) {
        throw new Exception("無法寫入設定檔，請檢查目錄權限");
    }
    
    echo json_encode(['success' => true, 'message' => '設定已儲存']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
