<?php
/**
 * 電表報表自動查詢工具 - PHP 版本
 * 用於自動查詢 clh25.dae.tw 的電表資料
 */

class MeterReportQuery {
    
    private $baseUrl = 'https://clh25.dae.tw/web/index.php';
    private $loginUrl = 'https://clh25.dae.tw/web/index.php';
    private $username = 'dktalk';
    private $password = 'AuVD68QGCKgNakr';
    private $cookieFile;
    
    // 可用的房間選項
    private $rooms = [
        '全部' => '全部',
        '301' => '29483',
        '302' => '29484',
        '303' => '29485',
        '401' => '29486',
        '402' => '29487',
        '403' => '29488'
    ];
    
    public function __construct($username = null, $password = null) {
        if ($username) $this->username = $username;
        if ($password) $this->password = $password;
        
        // 建立專屬 cookie 存放目錄
        $cookieDir = dirname(__DIR__) . '/data/cookies';
        if (!file_exists($cookieDir)) {
            mkdir($cookieDir, 0777, true);
        }
        $this->cookieFile = $cookieDir . '/meter_cookies_' . md5($this->username) . '.txt';
        
        // Debug: 顯示 Cookie 路徑
        // echo "🍪 Cookie 檔案路徑: " . $this->cookieFile . "\n";
    }
    
    /**
     * 登入系統
     */
    public function login() {
        // echo "🔐 正在登入...\n";
        
        $ch = curl_init();
        
        // Step 0: 先訪問首頁取得初始 Cookie
        // echo "   (0/2) 正在初始化 Session...\n";
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        curl_exec($ch);
        
        // Step 1: 執行登入
        // echo "   (1/2) 發送登入請求...\n";
        
        // 登入參數
        $loginData = [
            'd' => 'login',
            'user' => $this->username,
            'pwd' => $this->password,
            'tzo' => '480'
        ];
        
        // $loginUrl = $this->loginUrl . '?' . http_build_query($loginData); // 改為 POST，不帶參數在 URL
        $loginUrl = $this->loginUrl;
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $loginUrl,
            CURLOPT_POST => true, // 使用 POST
            CURLOPT_POSTFIELDS => http_build_query($loginData), // 傳送資料
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200) {
            // 嘗試解析 JSON (因為伺服器可能回傳 JSON 格式的錯誤)
            $json = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($json['result']) && $json['result'] === false) {
                    curl_close($ch);
                    return false;
                }
                // 如果回傳中有 direction，主動訪問一次以穩定 Session
                if (isset($json['data']['direction'])) {
                    // 修正網址拼湊：direction 是相對路徑，需結合 baseUrl 的目錄
                    $baseDir = dirname($this->baseUrl) . '/';
                    $nextUrl = $baseDir . str_replace('./', '', $json['data']['direction']);
                    
                    curl_setopt($ch, CURLOPT_URL, $nextUrl);
                    curl_setopt($ch, CURLOPT_POST, false); // 切換回 GET
                    curl_exec($ch);
                }
            }
            
            curl_close($ch);
            
            // 進階驗證：檢查回應內容是否包含登入失敗特徵 (HTML)
            // 或是是否包含登入成功的特徵 (例如 "登出", "Logout", "系統管理" 等)
            // 寬鬆檢查：只要還有 帳號/密碼 輸入框，就代表還在登入頁
            if (strpos($response, 'name="user"') !== false || strpos($response, 'name="pwd"') !== false) {
                // error_log("Login Failed: Response contains login form.");
                return false;
            }
            
            // 檢查 Cookie 是否成功寫入
            if (!file_exists($this->cookieFile) || filesize($this->cookieFile) == 0) {
                 // Cookie 寫入失敗，視為登入失敗
                 return false;
            }
            
            // echo "✅ 登入成功\n";
            return true;
        } else {
            // echo "❌ 登入失敗 (HTTP {$httpCode})\n";
            return false;
        }
    }
    
    /**
     * 查詢電表資料
     * 
     * @param string $beginDate 開始日期 (格式: Y-m-d)
     * @param string $endDate 結束日期 (格式: Y-m-d)
     * @param string $room 房間 ('全部', '301', '302', '303', '401', '402', '403')
     * @param string $projectId 專案ID (預設: 3209)
     * @return array ['success' => bool, 'data' => string, 'httpCode' => int]
     */
    public function query($beginDate, $endDate, $room = '全部', $projectId = '3209') {
        
        // 取得房間 ID
        $channelId = $this->rooms[$room] ?? '全部';
        
        // echo "📊 查詢參數:\n";
        // echo "   開始日期: {$beginDate}\n";
        // echo "   結束日期: {$endDate}\n";
        // echo "   房間: {$room}\n";
        // echo "   專案ID: {$projectId}\n";
        // echo "\n🔄 發送請求...\n";
        
        $ch = curl_init();
        
        // POST 資料
        $postData = [
            'begin_date' => $beginDate,
            'end_date' => $endDate,
            'project-id' => $projectId,
            'channel-id' => [$channelId],
            'db' => '1'
        ];
        
        $url = $this->baseUrl . '?d=record-and-report&m=meter-report';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile, // 確保更新 Cookie
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_REFERER => $this->baseUrl // 加入 Referer 標頭，模擬瀏覽器行為
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($httpCode == 200) {
            // 檢查是否被重導回登入頁面
            if (strpos($response, 'name="user"') !== false || strpos($response, 'name="pwd"') !== false) {
                // echo "❌ 查詢失敗 (Session 可能已過期，回應為登入頁面)\n";
                // Debug: 儲存失敗的回應
                // file_put_contents(__DIR__ . '/debug_query_fail.html', $response);
                
                // 如果需要，可以在這裡嘗試重新登入
                return [
                   'success' => false,
                   'data' => $response,
                   'httpCode' => 401, // 強制標示為 401
                   'error' => 'Session expired or login required'
                ];
            }
 
            // echo "✅ 請求成功 (狀態碼: {$httpCode})\n";
            return [
                'success' => true,
                'data' => $response,
                'httpCode' => $httpCode
            ];
        } else {
            // echo "❌ 請求失敗 (HTTP {$httpCode})\n";
            // if ($error) echo "錯誤: {$error}\n";
            return [
                'success' => false,
                'data' => $response,
                'httpCode' => $httpCode,
                'error' => $error
            ];
        }
    }
    
    /**
     * 查詢最近 N 天的資料
     */
    public function queryLastNDays($days = 7, $room = '全部') {
        $endDate = date('Y-m-d');
        $beginDate = date('Y-m-d', strtotime("-{$days} days"));
        
        return $this->query($beginDate, $endDate, $room);
    }
    
    /**
     * 查詢本月資料
     */
    public function queryThisMonth($room = '全部') {
        $beginDate = date('Y-m-01');
        $endDate = date('Y-m-d');
        
        return $this->query($beginDate, $endDate, $room);
    }
    
    /**
     * 查詢上個月資料
     */
    public function queryLastMonth($room = '全部') {
        $beginDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        
        return $this->query($beginDate, $endDate, $room);
    }

    /**
     * 查詢最近 3 個月的資料（包含本月）
     */
    public function queryLast3Months($room = '全部') {
        $endDate = date('Y-m-d'); // 今天
        $beginDate = date('Y-m-d', strtotime("-3 months"));
        
        return $this->query($beginDate, $endDate, $room);
    }
    
    /**
     * 查詢指定日期範圍的資料（智慧版本）
     * @param string $beginDate 開始日期 (Y-m-d)
     * @param string $endDate 結束日期 (Y-m-d)
     * @param string $room 房間代碼
     */
    public function queryDateRange($beginDate, $endDate, $room = '全部') {
        return $this->query($beginDate, $endDate, $room);
    }
    
    /**
     * 儲存回應到檔案
     */
    public function saveResponse($response, $filename = null) {
        if (!$response['success']) {
            // echo "❌ 沒有有效的回應資料可儲存\n";
            return false;
        }
        
        if ($filename === null) {
            $filename = 'meter_report_' . date('Ymd_His') . '.html';
        }
        
        $result = file_put_contents($filename, $response['data']);
        
        if ($result !== false) {
            // echo "💾 已儲存到: {$filename}\n";
            return $filename;
        } else {
            // echo "❌ 儲存失敗\n";
            return false;
        }
    }
    
    /**
     * 解析 HTML 回應（可選，用於提取資料）
     */
    public function parseResponse($htmlContent) {
        // 這裡可以用 DOMDocument 或 phpQuery 來解析 HTML
        // 提取你需要的資料
        
        // 範例：簡單的字串搜尋
        // 你可以根據實際的 HTML 結構來調整
        
        return [
            'raw_html' => $htmlContent,
            'length' => strlen($htmlContent),
            // 可以在這裡加入更多解析邏輯
        ];
    }
    
    /**
     * 清理 cookie 檔案
     */
    public function cleanup() {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
            // echo "🧹 已清理 cookie 檔案\n";
        }
    }
    /**
     * 從完整 HTML 中只提取 id="meter-report" 的表格
     */
    public function extractTableOnly($html) {
        // 抑制 HTML 解析錯誤警告
        libxml_use_internal_errors(true);
        
        $dom = new DOMDocument();
        // 加入 UTF-8 meta 以確保中文編碼正確
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
        $dom->loadHTML($html);
        
        $xpath = new DOMXPath($dom);
        // 尋找 id="meter-report" 的表格
        $table = $dom->getElementById('meter-report');
        
        if ($table) {
            // 匯出該節點的 HTML
            return $dom->saveHTML($table);
        }
        
        libxml_clear_errors();
        return false;
    }
    
    /**
     * 解析並清理資料
     * @return array 排序後的資料列表 [['room'=>..., 'date'=>..., 'reading'=>...], ...]
     */
    public function parseAndCleanData($html) {
        libxml_use_internal_errors(true);
        
        $dom = new DOMDocument();
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
        $dom->loadHTML($html);
        
        $xpath = new DOMXPath($dom);
        
        $result = [];
        $rooms = [];
        
        // 1. 解析表頭取得房間列表
        $headers = $xpath->query('//table[@id="meter-report"]/thead/tr/th');
        if ($headers->length > 0) {
            foreach ($headers as $index => $node) {
                // 跳過第一個欄位 (時間)
                if ($index === 0) continue;
                
                $raw = $node->textContent;
                // 強力清理: 移除 NBSP (0xA0, UTF-8: C2A0) 和其他空白
                $clean = trim(str_replace(["\xc2\xa0", "&nbsp;"], ' ', $raw));
                // 移除中括號 [] - 用戶回報房號帶有括號
                $clean = trim(str_replace(['[', ']'], '', $clean));
                // 再次移除所有不可見字元
                $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean);
                
                $rooms[$index] = $clean;
            }
        }
        
        // 2. 解析資料列
        $rows = $xpath->query('//table[@id="meter-report"]/tbody/tr');
        foreach ($rows as $row) {
            $cols = $xpath->query('td', $row);
            
            if ($cols->length > 0) {
                // 取得日期 (第一欄)
                $dateStr = trim($cols->item(0)->textContent);
                // 格式化日期 (去除時間部分，如果有的話)
                $date = date('Y-m-d', strtotime($dateStr));
                
                // 取得各房間讀數
                foreach ($rooms as $colIndex => $roomName) {
                    if ($colIndex < $cols->length) {
                        $cellContent = $cols->item($colIndex);
                        
                        // 清理內容：只取數值部分
                        // 有些格子可能有 <br><span>...</span>，我們只取第一個純文字節點或移除 span 後的內容
                        // 簡單作法：直接取 floatval，因為它會讀取開頭的數字直到非數字字元
                        $rawText = trim($cellContent->textContent);
                        
                        // 更精確的清理：利用 DOM 結構，讀數通常在 <br> 之前
                        // 或者直接用正則表達式提取開頭的浮點數
                        if (preg_match('/^[\d\.]+/', $rawText, $matches)) {
                            $reading = floatval($matches[0]);
                        } else {
                            $reading = 0;
                        }
                        
                        $result[] = [
                            'room' => $roomName,
                            'date' => $date,
                            'reading' => $reading
                        ];
                    }
                }
            }
        }
        
        libxml_clear_errors();
        
        // 3. 排序 (先依房間，再依日期)
        usort($result, function($a, $b) {
            if ($a['room'] !== $b['room']) {
                return strcmp($a['room'], $b['room']);
            }
            return strcmp($a['date'], $b['date']);
        });
        
        return $result;
    }
}


// ============================================================
// 使用範例
// ============================================================

// 範例 1：基本使用
function example1() {
    echo "=== 範例 1: 基本使用 ===\n\n";
    
    $query = new MeterReportQuery();
    
    // 登入
    if ($query->login()) {
        // 查詢最近 7 天的資料
        $result = $query->queryLastNDays(7, '全部');
        
        if ($result['success']) {
            // 儲存結果
            $query->saveResponse($result, 'last_7_days.html');
            
            // 顯示部分內容
            echo "\n預覽內容 (前 300 字元):\n";
            echo substr($result['data'], 0, 300) . "...\n";
        }
    }
    
    $query->cleanup();
}

// 範例 2：查詢特定日期範圍
function example2() {
    echo "=== 範例 2: 查詢特定日期 ===\n\n";
    
    $query = new MeterReportQuery();
    
    if ($query->login()) {
        $result = $query->query('2025-12-28', '2026-01-04', '301');
        
        if ($result['success']) {
            $query->saveResponse($result, 'room_301.html');
        }
    }
    
    $query->cleanup();
}

// 範例 3：批次查詢所有房間
function example3() {
    echo "=== 範例 3: 批次查詢所有房間 ===\n\n";
    
    $query = new MeterReportQuery();
    
    if ($query->login()) {
        $rooms = ['301', '302', '303', '401', '402', '403'];
        
        foreach ($rooms as $room) {
            echo "\n--- 查詢房間 {$room} ---\n";
            $result = $query->queryLastNDays(7, $room);
            
            if ($result['success']) {
                $filename = "room_{$room}_" . date('Ymd') . ".html";
                $query->saveResponse($result, $filename);
            }
            
            // 避免請求太快，稍微延遲
            sleep(1);
        }
    }
    
    $query->cleanup();
}

// 範例 4：整合到你的 PHP 系統
function example4() {
    echo "=== 範例 4: 整合應用 ===\n\n";
    
    $query = new MeterReportQuery();
    
    if ($query->login()) {
        // 查詢本月資料
        $result = $query->queryThisMonth('全部');
        
        if ($result['success']) {
            // 不儲存檔案，直接處理 HTML
            $html = $result['data'];
            
            // 你可以在這裡：
            // 1. 用 DOMDocument 解析 HTML
            // 2. 提取表格資料
            // 3. 存入你的資料庫
            // 4. 或做其他處理
            
            echo "資料長度: " . strlen($html) . " 字元\n";
            
            // 範例：簡單的資料提取
            $parsed = $query->parseResponse($html);
            print_r($parsed);
        }
    }
    
    $query->cleanup();
}


// ============================================================
// 主程式
// ============================================================

if (php_sapi_name() === 'cli') {
    // 命令列執行
    
    // 如果是被其他程式引入（include/require），且是在 CLI 模式下（避免干擾主程式），則不執行
    // 但通常我們希望作為 library 時完全不執行副作用
    // 所以改用 get_included_files 判斷
}

// 如果是被其他程式引入（include/require），則不執行以下的主程式邏輯
if (count(get_included_files()) > 1) {
    return;
}

if (php_sapi_name() === 'cli') {
    // 命令列執行
    
    echo "============================================================\n";
    echo "📊 電表報表自動查詢工具 - PHP 版本\n";
    echo "============================================================\n\n";
    
    // 取消註解來執行範例
    example1();
    // example2();
    // example3();
    // example4();
    
} else {
    // 網頁執行
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<h1>電表報表查詢工具</h1>";
    echo "<p>請在命令列執行此程式，或修改此部分以整合到你的系統中。</p>";
}

?>