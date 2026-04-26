<?php
// Handle Record Submission
$message = '';

// Export readings to CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_readings') {
    $startDate = $_POST['export_start'] ?? '';
    $endDate = $_POST['export_end'] ?? '';

    if (trim($startDate) === '' || trim($endDate) === '') {
        $message = '<div class="alert alert-danger">請選擇匯出日期區間。</div>';
    } else {
        $pdo = DB::connect();
        $stmt = $pdo->prepare("SELECT u.name as unit_name, er.record_date, er.reading_value, er.diff_value, er.record_type FROM electricity_readings er JOIN units u ON u.id = er.unit_id WHERE er.record_date BETWEEN ? AND ? ORDER BY er.record_date ASC, u.name ASC");
        $stmt->execute([$startDate, $endDate]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="meter_readings_' . $startDate . '_to_' . $endDate . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['unit_name', 'record_date', 'reading_value', 'diff_value', 'record_type']);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [$row['unit_name'], $row['record_date'], $row['reading_value'], $row['diff_value'], $row['record_type']]);
        }
        fclose($out);
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_reading') {
    $unit_id = (int)$_POST['unit_id'];
    $record_date = $_POST['record_date'];
    $reading_value = (float)$_POST['reading_value'];
    // Default to 'closing' since user mentioned monthly cycle, but we can just use 'manual' or keep schema default 'daily'/'closing'.
    // Let's use 'closing' if it's considered a cycle end, but for flexibility let's just stick to schema column usage.
    // The previous schema had `record_type`. Let's assume these manual entries are 'closing' readings for now or just standard readings.
    $record_type = 'manual';

    // 1. Find previous reading to calculate diff
    $pdo = DB::connect();
    $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
    $stmt->execute([$unit_id, $record_date]);
    $prev = $stmt->fetch();
    
    $diff_value = 0;
    if ($prev) {
        $diff_value = $reading_value - $prev['reading_value'];
    }

    if ($diff_value < 0) {
        $message = '<div class="alert alert-danger">錯誤：輸入的度數小於前次度數，請檢查數值。</div>';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO electricity_readings (unit_id, record_date, reading_value, diff_value, record_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$unit_id, $record_date, $reading_value, $diff_value, $record_type]);
            $message = '<div class="alert alert-success">抄表紀錄已儲存。本次用量: ' . $diff_value . ' 度</div>';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $message = '<div class="alert alert-warning">該日期已存在抄表紀錄。</div>'; }
            else { $message = '<div class="alert alert-danger">資料庫錯誤: ' . $e->getMessage() . '</div>'; }
        }
    }
}

// Handle Edit Reading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_reading') {
    $id = (int)$_POST['id'];
    $new_val = (float)$_POST['reading_value'];
    $pdo = DB::connect();
    
    try {
        $pdo->beginTransaction();
        // 1. Get this record details
        $stmt = $pdo->prepare("SELECT unit_id, record_date FROM electricity_readings WHERE id = ?");
        $stmt->execute([$id]);
        $curr = $stmt->fetch();
        
        if ($curr) {
            // 2. Update this record's reading and re-calc its diff
            $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
            $stmt->execute([$curr['unit_id'], $curr['record_date']]);
            $prev = $stmt->fetch();
            $new_diff = $prev ? ($new_val - $prev['reading_value']) : 0;
            
            $stmt = $pdo->prepare("UPDATE electricity_readings SET reading_value = ?, diff_value = ? WHERE id = ?");
            $stmt->execute([$new_val, $new_diff, $id]);
            
            // 3. Re-balance NEXT record's diff
            $stmt = $pdo->prepare("SELECT id, reading_value FROM electricity_readings WHERE unit_id = ? AND record_date > ? ORDER BY record_date ASC LIMIT 1");
            $stmt->execute([$curr['unit_id'], $curr['record_date']]);
            $next = $stmt->fetch();
            if ($next) {
                $next_diff = $next['reading_value'] - $new_val;
                $pdo->prepare("UPDATE electricity_readings SET diff_value = ? WHERE id = ?")->execute([$next_diff, $next['id']]);
            }
        }
        $pdo->commit();
        $message = '<div class="alert alert-success">紀錄已修正。</div>';
    } catch (Exception $e) { $pdo->rollBack(); $message = '<div class="alert alert-danger">修正失敗: ' . $e->getMessage() . '</div>'; }
}

// Handle Delete Reading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_reading') {
    $id = (int)$_POST['id'];
    $pdo = DB::connect();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT unit_id, record_date FROM electricity_readings WHERE id = ?");
        $stmt->execute([$id]);
        $curr = $stmt->fetch();
        if ($curr) {
            // Re-balance NEXT record before deleting
            $stmt = $pdo->prepare("SELECT id, reading_value FROM electricity_readings WHERE unit_id = ? AND record_date > ? ORDER BY record_date ASC LIMIT 1");
            $stmt->execute([$curr['unit_id'], $curr['record_date']]);
            $next = $stmt->fetch();
            
            if ($next) {
                // Find PREV to get new base for NEXT
                $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
                $stmt->execute([$curr['unit_id'], $curr['record_date']]);
                $prev = $stmt->fetch();
                $new_next_diff = $prev ? ($next['reading_value'] - $prev['reading_value']) : 0;
                $pdo->prepare("UPDATE electricity_readings SET diff_value = ? WHERE id = ?")->execute([$new_next_diff, $next['id']]);
            }
            $pdo->prepare("DELETE FROM electricity_readings WHERE id = ?")->execute([$id]);
        }
        $pdo->commit();
        $message = '<div class="alert alert-success">紀錄已刪除。</div>';
    } catch (Exception $e) { $pdo->rollBack(); $message = '<div class="alert alert-danger">刪除失敗: ' . $e->getMessage() . '</div>'; }
}

// Handle Delete Utility Bill
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_utility_bill') {
    $billId = isset($_POST['bill_id']) ? (int)$_POST['bill_id'] : 0;
    if ($billId > 0) {
        $pdo = DB::connect();
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE ledger SET ref_type = NULL, ref_id = NULL WHERE ref_type = 'utility_bill' AND ref_id = ?")
                ->execute([$billId]);
            $pdo->prepare("DELETE FROM utility_bills WHERE id = ?")
                ->execute([$billId]);
            $pdo->commit();
            $message = '<div class="alert alert-success">台電帳單已刪除。</div>';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">刪除失敗: ' . $e->getMessage() . '</div>';
        }
    } else {
        $message = '<div class="alert alert-warning">無效的帳單編號。</div>';
    }
}

// Fetch Units for card display
$pdo = DB::connect();
$units = $pdo->query("SELECT id, name FROM units WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Latest utility bills summary (1-3)
$recentBills = $pdo->query("
    SELECT ub.*, 
           (SELECT COALESCE(SUM(er.diff_value), 0)
             FROM electricity_readings er
             JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
             WHERE er.record_date BETWEEN ub.period_start AND ub.period_end) as submeter_total,
           (SELECT COUNT(DISTINCT er.unit_id)
             FROM electricity_readings er
             JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
             WHERE er.record_date BETWEEN ub.period_start AND ub.period_end) as reading_unit_count,
           (SELECT COUNT(*) FROM units WHERE is_active = 1) as active_unit_count
    FROM utility_bills ub
    ORDER BY COALESCE(ub.bill_date, ub.period_end) DESC, ub.id DESC
    LIMIT 3
")->fetchAll();

// All utility bills summary (paginated)
$billPage = isset($_GET['bill_page']) ? (int)$_GET['bill_page'] : 1;
$billPage = max(1, $billPage);
$billPageSize = 20;
$billOffset = ($billPage - 1) * $billPageSize;

$billTotal = (int)$pdo->query("SELECT COUNT(*) FROM utility_bills")->fetchColumn();
$billTotalPages = $billTotal > 0 ? (int)ceil($billTotal / $billPageSize) : 1;

$stmt = $pdo->prepare("
    SELECT ub.*, 
           (SELECT COALESCE(SUM(er.diff_value), 0)
            FROM electricity_readings er
            JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
            WHERE er.record_date BETWEEN ub.period_start AND ub.period_end) as submeter_total,
           (SELECT COUNT(DISTINCT er.unit_id)
            FROM electricity_readings er
            JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
            WHERE er.record_date BETWEEN ub.period_start AND ub.period_end) as reading_unit_count,
           (SELECT COUNT(*) FROM units WHERE is_active = 1) as active_unit_count
    FROM utility_bills ub
    ORDER BY COALESCE(ub.bill_date, ub.period_end) DESC, ub.id DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$billPageSize, $billOffset]);
$allBills = $stmt->fetchAll();

$defaultChartUnitId = !empty($units) ? (int)$units[0]['id'] : 0;

// Helper functions for card-based display with record type priority
function getLatestReading($unit_id) {
    $pdo = DB::connect();
    // Priority: closing > manual > daily
    $stmt = $pdo->prepare("
        SELECT * FROM electricity_readings 
        WHERE unit_id = ? 
        ORDER BY record_date DESC, 
                 CASE record_type 
                     WHEN 'closing' THEN 1 
                     WHEN 'manual' THEN 2 
                     WHEN 'daily' THEN 3 
                     ELSE 4
                 END
        LIMIT 1
    ");
    $stmt->execute([$unit_id]);
    return $stmt->fetch();
}

function getMonthlyUsage($unit_id) {
    $pdo = DB::connect();
    // Calculate from most recent closing/manual point to now
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT reading_value FROM electricity_readings 
             WHERE unit_id = ? 
             AND strftime('%Y-%m', record_date) = strftime('%Y-%m', 'now')
             ORDER BY record_date DESC,
                      CASE record_type 
                          WHEN 'closing' THEN 1 
                          WHEN 'manual' THEN 2 
                          WHEN 'daily' THEN 3 
                      END
             LIMIT 1) as latest,
            (SELECT reading_value FROM electricity_readings 
             WHERE unit_id = ? 
             AND record_date < date('now', 'start of month')
             ORDER BY record_date DESC,
                      CASE record_type 
                          WHEN 'closing' THEN 1 
                          WHEN 'manual' THEN 2 
                          WHEN 'daily' THEN 3 
                      END
             LIMIT 1) as month_start
    ");
    $stmt->execute([$unit_id, $unit_id]);
    $result = $stmt->fetch();
    
    if ($result && $result['latest'] && $result['month_start']) {
        return $result['latest'] - $result['month_start'];
    }
    return 0;
}

function getUnitHistory($unit_id, $limit = 10, $offset = 0) {
    $pdo = DB::connect();
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM electricity_readings WHERE unit_id = ?");
    $countStmt->execute([$unit_id]);
    $total = $countStmt->fetchColumn();
    
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
    
    return [
        'records' => $stmt->fetchAll(),
        'total' => $total
    ];
}

// Load Meter Config for Auto-Import
$meterConfig = ['username' => '', 'password' => ''];
$configFile = __DIR__ . '/../config/meter_config.json';
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        $meterConfig = array_merge($meterConfig, $loaded);
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">電表紀錄</h1>
    <a href="/meter" target="_blank" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-speedometer2 me-1"></i> 即時電錶監控
    </a>
</div>

<?= $message ?>

<!-- Auto Import Settings -->
<div class="accordion mb-4 shadow-sm" id="meterSettingsAccordion">
    <div class="accordion-item border-0">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSettings">
                <i class="bi bi-cloud-download me-2"></i> 金房東資料匯入
            </button>
        </h2>
        <div id="collapseSettings" class="accordion-collapse collapse" data-bs-parent="#meterSettingsAccordion">
            <div class="accordion-body bg-light">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="small text-muted">
                            <div class="fw-semibold">匯入模式：</div>
                            <div>執行匯入：智慧判斷，通常只載入最近幾天</div>
                            <div>自訂匯入：自行指定查詢天數（1-365天）</div>
                            <div>建議匯入：系統篩選的結算日和月底資料</div>
                        </div>
                        <div class="mt-3">
                            <div class="input-group input-group-sm">
                                <input type="number" id="customDays" class="form-control" placeholder="天數" value="90" min="1" max="365" title="輸入要查詢的天數（1-365）">
                                <button class="btn btn-warning" onclick="runCustomImport()" title="自訂查詢天數，適合首次建立資料庫或補齊特定期間">
                                    <i class="bi bi-calendar-range"></i> 自訂匯入
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1" id="customDaysHint">
                                <i class="bi bi-calendar2-range"></i>
                                自訂匯入將查詢最近 90 天
                            </small>
                            <small class="text-muted d-block">
                                <i class="bi bi-lightbulb"></i>
                                日常使用：點「執行匯入」｜首次建立：輸入 90 天｜補齊資料：輸入需要的天數
                            </small>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">帳號 (User)</label>
                                <input type="text" id="meterUser" class="form-control" placeholder="輸入帳號" value="<?= htmlspecialchars($meterConfig['username']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">密碼 (Password)</label>
                                <input type="password" id="meterPass" class="form-control" placeholder="輸入密碼" value="<?= htmlspecialchars($meterConfig['password']) ?>">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-secondary flex-grow-1" onclick="saveMeterSettings()">
                                <i class="bi bi-save"></i> 儲存
                            </button>
                            <button class="btn btn-primary flex-grow-1" onclick="runMeterImport()">
                                <i class="bi bi-play-fill"></i> 執行匯入
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">
                            <i class="bi bi-info-circle"></i>
                            執行匯入為增量模式，通常只查最近 7 天
                        </small>
                    </div>
                </div>
                <div id="importStatus" class="mt-3 d-none">
                    <div class="alert alert-info d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        <span>正在連線 clh25.dae.tw 讀取資料中，請稍候... (約需 10-20 秒)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Results Preview -->
<div id="importResultPanel" class="d-none mb-4">
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0">✅ 已載入 <span id="total-count">0</span> 筆資料</h6>
                <div class="small" id="import-range-text"></div>
            </div>
            <button class="btn btn-sm btn-light text-success" onclick="closeImportPanel()">關閉</button>
        </div>
        <div class="card-body p-0">
            <ul class="nav nav-tabs nav-justified" id="importTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="suggested-tab" data-bs-toggle="tab" data-bs-target="#suggested-pane" type="button" title="系統自動篩選的結算日和月底資料，建議優先匯入">
                        ✅ 建議匯入 <span class="badge bg-success rounded-pill ms-1" id="badge-suggested">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-pane" type="button" title="顯示所有查詢到的資料，可自行勾選需要的項目">
                        📂 全部資料
                    </button>
                </li>
            </ul>
            <div class="tab-content p-3" id="importTabContent">
                <!-- Suggested Pane -->
                <div class="tab-pane fade show active" id="suggested-pane">
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-lightbulb"></i> 系統已自動篩選出結算日和月底資料，建議優先匯入
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this, 'suggested')" checked></th>
                                    <th>日期</th>
                                    <th>房號</th>
                                    <th>讀數</th>
                                    <th>原因</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-suggested"></tbody>
                        </table>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-success" onclick="confirmBatchImport('suggested')">
                            <i class="bi bi-check-circle"></i> 確認匯入已選取的資料
                        </button>
                    </div>
                </div>
                <!-- All Pane -->
                <div class="tab-pane fade" id="all-pane">
                    <div class="alert alert-secondary small mb-3">
                        <i class="bi bi-info-circle"></i> 顯示所有查詢到的資料，可勾選需要的項目進行匯入
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="sticky-top bg-white">
                                <tr>
                                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this, 'all')"></th>
                                    <th>日期</th>
                                    <th>房號</th>
                                    <th>讀數</th>
                                    <th>狀態</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-all"></tbody>
                        </table>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-primary" onclick="confirmBatchImport('all')">
                            <i class="bi bi-check-circle"></i> 匯入已選取的資料
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Export Readings (Collapsed) -->
<details class="mb-4">
    <summary class="text-muted small">
        <i class="bi bi-download"></i> 匯出電表讀數（進階）
    </summary>
    <div class="card shadow-sm mt-2">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-download"></i> 匯出電表讀數</h6>
            <span class="text-muted small">CSV</span>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="export_readings">
                <div class="col-md-4">
                    <label class="form-label">起始日期</label>
                    <input type="date" class="form-control" name="export_start" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">結束日期</label>
                    <input type="date" class="form-control" name="export_end" required>
                </div>
                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-file-earmark-arrow-down"></i> 下載 CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</details>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">公用電估算（最近一期）</div>
                <?php if (!empty($recentBills)): ?>
                    <?php
                    $latestBill = $recentBills[0];
                    $billKwh = (float)$latestBill['kwh'];
                    $submeterTotal = (float)$latestBill['submeter_total'];
                    $publicKwh = $billKwh - $submeterTotal;
                    $activeUnits = (int)$latestBill['active_unit_count'];
                    $readingUnits = (int)$latestBill['reading_unit_count'];
                    $hasMissingReadings = $activeUnits > 0 && $readingUnits < $activeUnits;
                    ?>
                    <div class="h3 mb-2 text-primary"><?= number_format($publicKwh, 2) ?> kWh</div>
                    <div class="text-muted small">帳期</div>
                    <div class="text-muted small"><?= htmlspecialchars($latestBill['period_start']) ?> ~ <?= htmlspecialchars($latestBill['period_end']) ?></div>
                <?php else: ?>
                    <div class="h4 mb-0 text-secondary">-- kWh</div>
                    <div class="text-muted small">尚無台電帳單</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-receipt"></i> 台電帳單差異（最近）</h6>
                <span class="text-muted small">最近 1-3 筆</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentBills)): ?>
                    <div class="p-3 text-muted small">尚無台電帳單</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>帳期</th>
                                    <th class="text-end">帳單度數</th>
                                    <th class="text-end">分錶合計</th>
                                    <th class="text-end">差額</th>
                                    <th>狀態</th>
                                    <th class="text-end">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBills as $bill): ?>
                                    <?php
                                    $billKwh = (float)$bill['kwh'];
                                    $submeterTotal = (float)$bill['submeter_total'];
                                    $diffKwh = $billKwh - $submeterTotal;
                                    $activeUnits = (int)$bill['active_unit_count'];
                                    $readingUnits = (int)$bill['reading_unit_count'];
                                    $hasMissingReadings = $activeUnits > 0 && $readingUnits < $activeUnits;
                                    ?>
                                    <tr>
                                        <td class="small"><?= htmlspecialchars($bill['period_start']) ?> ~ <?= htmlspecialchars($bill['period_end']) ?></td>
                                        <td class="text-end"><span class="badge bg-secondary-subtle text-secondary"><?= number_format($billKwh, 2) ?></span></td>
                                        <td class="text-end"><span class="badge bg-success-subtle text-success"><?= number_format($submeterTotal, 2) ?></span></td>
                                        <td class="text-end"><span class="badge bg-primary-subtle text-primary"><?= number_format($diffKwh, 2) ?></span></td>
                                        <td>
                                            <?php if ($hasMissingReadings): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis">讀數不完整</span>
                                            <?php else: ?>
                                                <span class="text-muted small">完整</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="delete_utility_bill">
                                                <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定要刪除這筆台電帳單嗎？')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- All Utility Bills (Collapsed) -->
<details class="mb-4">
    <summary class="text-muted small">
        <i class="bi bi-collection"></i> 完整公用電估算紀錄
    </summary>
    <div class="card shadow-sm mt-2">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-receipt"></i> 台電帳單差異（完整）</h6>
            <span class="text-muted small">全部紀錄</span>
        </div>
        <div class="card-body p-0">
            <?php if ($billTotal === 0): ?>
                <div class="p-3 text-muted small">尚無台電帳單</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>帳期</th>
                                <th class="text-end">帳單度數</th>
                                <th class="text-end">分錶合計</th>
                                <th class="text-end">差額</th>
                                <th>狀態</th>
                                <th class="text-end">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allBills as $bill): ?>
                                <?php
                                $billKwh = (float)$bill['kwh'];
                                $submeterTotal = (float)$bill['submeter_total'];
                                $diffKwh = $billKwh - $submeterTotal;
                                $activeUnits = (int)$bill['active_unit_count'];
                                $readingUnits = (int)$bill['reading_unit_count'];
                                $hasMissingReadings = $activeUnits > 0 && $readingUnits < $activeUnits;
                                ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars($bill['period_start']) ?> ~ <?= htmlspecialchars($bill['period_end']) ?></td>
                                    <td class="text-end"><span class="badge bg-secondary-subtle text-secondary"><?= number_format($billKwh, 2) ?></span></td>
                                    <td class="text-end"><span class="badge bg-success-subtle text-success"><?= number_format($submeterTotal, 2) ?></span></td>
                                    <td class="text-end"><span class="badge bg-primary-subtle text-primary"><?= number_format($diffKwh, 2) ?></span></td>
                                    <td>
                                        <?php if ($hasMissingReadings): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis">讀數不完整</span>
                                        <?php else: ?>
                                            <span class="text-muted small">完整</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="delete_utility_bill">
                                            <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定要刪除這筆台電帳單嗎？')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($billTotalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="text-muted small">第 <?= $billPage ?> / <?= $billTotalPages ?> 頁</div>
                        <div class="btn-group btn-group-sm">
                            <a class="btn btn-outline-secondary <?= $billPage <= 1 ? 'disabled' : '' ?>"
                               href="index.php?p=meter&bill_page=<?= max(1, $billPage - 1) ?>">
                                上一頁
                            </a>
                            <a class="btn btn-outline-secondary <?= $billPage >= $billTotalPages ? 'disabled' : '' ?>"
                               href="index.php?p=meter&bill_page=<?= min($billTotalPages, $billPage + 1) ?>">
                                下一頁
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</details>

<!-- Meter Chart -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0"><i class="bi bi-graph-up"></i> 用電趨勢</h6>
            <div class="text-muted small" id="chartSubtitle">每日用量 + 累積讀數</div>
        </div>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" id="chartUnitSelect" style="min-width: 10ch;">
                <option value="0" selected>整體</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= (int)$unit['id'] ?>">
                        <?= htmlspecialchars($unit['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" id="chartRangeSelect" style="min-width: 12ch;">
                <option value="30" selected>近 30 天</option>
                <option value="60">近 60 天</option>
                <option value="90">近 90 天</option>
                <option value="180">近 180 天</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-center mb-3" id="chartSummary">
            <span class="badge bg-success-subtle text-success" id="summaryTotal">總用量：--</span>
            <span class="badge bg-info-subtle text-info" id="summaryAvg">日均：--</span>
            <span class="badge bg-warning-subtle text-warning-emphasis" id="summaryMax">單日最高：--</span>
        </div>
        <div class="position-relative" style="min-height: 280px;">
            <canvas id="meterChart"></canvas>
        </div>
        <div class="text-muted small mt-2" id="chartEmpty" style="display: none;">
            目前沒有資料可顯示。
        </div>
    </div>
</div>

<div class="row">
    <!-- Card-Based Recent Records -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-speedometer2"></i> 各房源電表紀錄</h4>
            <span class="text-muted small">點擊卡片查看詳細歷史</span>
        </div>
        
        <div class="row g-3">
            <?php foreach ($units as $unit): ?>
                <?php 
                $latest = getLatestReading($unit['id']);
                $thisMonth = getMonthlyUsage($unit['id']);
                ?>
                
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm meter-card">
                        <div class="card-body">
                            <!-- Card Header -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="mb-0">
                                    <i class="bi bi-house-door text-primary"></i>
                                    <?= htmlspecialchars($unit['name']) ?>
                                </h5>
                                <h4 class="mb-0 text-primary">
                                    <?= $latest ? number_format($latest['reading_value'], 2) : '-' ?>
                                </h4>
                            </div>
                            
                            <!-- Summary Info -->
                            <?php if ($latest): ?>
                                <div class="small text-muted mb-2">
                                    <i class="bi bi-calendar3"></i>
                                    最後抄表：<?= $latest['record_date'] ?>
                                    <?php 
                                    // 顯示資料來源
                                    if ($latest['record_type'] === 'closing') {
                                        echo ' <span class="badge bg-danger-subtle text-danger">結算</span>';
                                    } elseif ($latest['record_type'] === 'manual') {
                                        echo ' <span class="badge bg-secondary-subtle text-secondary">手動</span>';
                                    } elseif ($latest['record_type'] === 'daily') {
                                        echo ' <span class="badge bg-info-subtle text-info">自動</span>';
                                    }
                                    ?>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="small">本月用量</span>
                                    <span class="badge bg-success">
                                        +<?= number_format($thisMonth, 2) ?> 度
                                    </span>
                                </div>
                                
                                <?php if ($latest['record_type'] === 'closing'): ?>
                                    <div class="small text-danger mb-3">
                                        <i class="bi bi-bookmark-check-fill"></i> 已結算
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-muted small mb-3">尚無紀錄</div>
                            <?php endif; ?>
                            
                            <!-- Action Buttons -->
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary btn-sm" 
                                        onclick="toggleHistory(<?= $unit['id'] ?>)">
                                    <i class="bi bi-chevron-down" id="icon-<?= $unit['id'] ?>"></i> 查看明細
                                </button>
                            </div>
                        </div>
                        
                        <!-- History (Hidden by default) -->
                        <div id="history-<?= $unit['id'] ?>" class="card-footer bg-light d-none">
                            <?php 
                            $historyData = getUnitHistory($unit['id'], 10);
                            $history = $historyData['records'];
                            $totalCount = $historyData['total'];
                            ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0"><i class="bi bi-clock-history"></i> 歷史紀錄</h6>
                                <small class="text-muted">
                                    顯示 <span id="shown-<?= $unit['id'] ?>"><?= count($history) ?></span> 筆，
                                    共 <span id="total-<?= $unit['id'] ?>"><?= $totalCount ?></span> 筆
                                </small>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>日期</th>
                                            <th>讀數</th>
                                            <th>用量</th>
                                            <th>來源</th>
                                        </tr>
                                    </thead>
                                    <tbody id="history-tbody-<?= $unit['id'] ?>">
                                        <?php if (empty($history)): ?>
                                            <tr><td colspan="4" class="text-center text-muted small">尚無歷史紀錄</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history as $h): ?>
                                                <tr>
                                                    <td class="small"><?= $h['record_date'] ?></td>
                                                    <td><?= number_format($h['reading_value'], 2) ?></td>
                                                    <td>
                                                        <?php if ($h['diff_value'] > 0): ?>
                                                            <span class="badge bg-success-subtle text-success">
                                                                +<?= number_format($h['diff_value'], 2) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($h['record_type'] === 'closing'): ?>
                                                            <span class="badge bg-danger-subtle text-danger">結算</span>
                                                        <?php elseif ($h['record_type'] === 'manual'): ?>
                                                            <span class="badge bg-secondary-subtle text-secondary">手動</span>
                                                        <?php elseif ($h['record_type'] === 'daily'): ?>
                                                            <span class="badge bg-info-subtle text-info">自動</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if ($totalCount > 10): ?>
                                <div class="text-center mt-2" id="loadmore-<?= $unit['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="loadMoreHistory(<?= $unit['id'] ?>)">
                                        <i class="bi bi-chevron-down"></i> 載入更多
                                    </button>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 mt-3">
                                <button class="btn btn-sm btn-primary" 
                                        onclick="openAddModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['name']) ?>')">
                                    <i class="bi bi-plus-circle"></i> 手動新增紀錄
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" 
                                        onclick="toggleHistory(<?= $unit['id'] ?>)">
                                    <i class="bi bi-chevron-up"></i> 收起明細
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Help Section -->
<div class="alert alert-info mb-4">
    <h6 class="mb-2"><i class="bi bi-info-circle-fill"></i> 使用說明</h6>
    <div class="row small">
        <div class="col-md-6">
            <strong>📊 記錄來源</strong>
            <ul class="mb-0 mt-1" style="list-style: none; padding-left: 0;">
                <li><span class="badge bg-danger-subtle text-danger">結算</span> 產生帳單時的結算點（最優先）</li>
                <li><span class="badge bg-secondary-subtle text-secondary">手動</span> 您手動輸入的資料</li>
                <li><span class="badge bg-info-subtle text-info">自動</span> 從金房東匯入的資料</li>
            </ul>
        </div>
        <div class="col-md-6">
            <strong>🏠 卡片資訊</strong>
            <ul class="mb-0 mt-1" style="list-style: none; padding-left: 0;">
                <li><strong>大數字</strong>：最新電表讀數（優先顯示結算點）</li>
                <li><strong>本月用量</strong>：本月初到現在的用電量</li>
                <li><strong>查看明細</strong>：展開查看歷史記錄</li>
            </ul>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">修正電表紀錄</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_reading">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="mb-3">
                        <label class="form-label">房源 / 日期</label>
                        <input type="text" class="form-control bg-light" id="editUnitDate" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">修正度數</label>
                        <input type="number" step="0.1" class="form-control form-control-lg" name="reading_value" id="editValue" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">確認儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openEditModal(id, unit, date, value) {
        document.getElementById('editId').value = id;
        document.getElementById('editUnitDate').value = unit + ' (' + date + ')';
        document.getElementById('editValue').value = value;
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }
    
    // Toggle card history
    function toggleHistory(unitId) {
        const historyDiv = document.getElementById(`history-${unitId}`);
        const icon = document.getElementById(`icon-${unitId}`);
        
        if (historyDiv.classList.contains('d-none')) {
            historyDiv.classList.remove('d-none');
            if (icon) icon.className = 'bi bi-chevron-up';
        } else {
            historyDiv.classList.add('d-none');
            if (icon) icon.className = 'bi bi-chevron-down';
        }
    }
    
    // Open add modal with pre-filled unit
    function openAddModal(unitId, unitName) {
        // Scroll to top where the form would be, or create a modal
        alert(`新增 ${unitName} 的電表紀錄功能即將推出`);
        // TODO: Implement add modal or redirect to form
    }
    
    // Load more history records
    function loadMoreHistory(unitId) {
        const tbody = document.getElementById(`history-tbody-${unitId}`);
        const currentCount = tbody.querySelectorAll('tr').length;
        const totalSpan = document.getElementById(`total-${unitId}`);
        const shownSpan = document.getElementById(`shown-${unitId}`);
        
        fetch(`api/get_unit_history.php?unit_id=${unitId}&offset=${currentCount}&limit=20`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.records.length > 0) {
                data.records.forEach(h => {
                    let sourceLabel = '';
                    if (h.record_type === 'closing') sourceLabel = '<span class="badge bg-danger-subtle text-danger">結算</span>';
                    else if (h.record_type === 'manual') sourceLabel = '<span class="badge bg-secondary-subtle text-secondary">手動</span>';
                    else if (h.record_type === 'daily') sourceLabel = '<span class="badge bg-info-subtle text-info">自動</span>';
                    
                    const diffBadge = h.diff_value > 0 
                        ? `<span class="badge bg-success-subtle text-success">+${parseFloat(h.diff_value).toFixed(2)}</span>`
                        : '<span class="text-muted">-</span>';
                    
                    tbody.innerHTML += `<tr>
                        <td class="small">${h.record_date}</td>
                        <td>${parseFloat(h.reading_value).toFixed(2)}</td>
                        <td>${diffBadge}</td>
                        <td>${sourceLabel}</td>
                    </tr>`;
                });
                
                const newCount = currentCount + data.records.length;
                shownSpan.textContent = newCount;
                
                // Hide button if all loaded
                if (newCount >= parseInt(totalSpan.textContent)) {
                    document.getElementById(`loadmore-${unitId}`).style.display = 'none';
                }
            }
        })
        .catch(e => console.error('載入失敗:', e));
    }
    
    // --- Auto Import Functions (Duplicates Logic from Quick Meter for now) ---
    
    function saveMeterSettings() {
        const user = document.getElementById('meterUser').value;
        const pass = document.getElementById('meterPass').value;
        
        if(!user || !pass) { alert('請輸入帳號與密碼'); return; }
        
        fetch('api/save_meter_settings.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `username=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}`
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) { alert('設定已儲存'); }
            else { alert('儲存失敗: ' + data.message); }
        })
        .catch(e => alert('連線錯誤'));
    }
    
    function runMeterImport(forceFull = false) {
        // Show loading
        document.getElementById('importStatus').classList.remove('d-none');
        document.getElementById('importResultPanel').classList.add('d-none');
        
        const url = forceFull ? 'api/import_meter.php?force_full=1' : 'api/import_meter.php';
        
        fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('importStatus').classList.add('d-none');
            
             if(data.success) {
                // 顯示查詢範圍資訊
                 if (data.query_range) {
                     const strategyLabels = {
                         'force_full': '🔄 強制完整匯入',
                         'incremental': '⚡ 增量更新',
                         'full': '📦 完整查詢'
                     };
                     const strategy = strategyLabels[data.query_range.strategy] || data.query_range.strategy;
                     console.log(`📊 查詢策略: ${strategy} | 範圍: ${data.query_range.begin} ~ ${data.query_range.end} (${data.query_range.days} 天)`);
                 }
                 window.lastImportQueryRange = data.query_range || null;
                 window.lastImportData = data.data;
                 renderImportResults(data.data);
            } else {
                console.error('匯入失敗:', data.message);
                const statusDiv = document.getElementById('importStatus');
                statusDiv.classList.remove('d-none');
                statusDiv.innerHTML = `<div class="alert alert-danger">匯入失敗: ${data.message}</div>`;
            }
        })
        .catch(e => {
            document.getElementById('importStatus').classList.add('d-none');
            console.error('系統錯誤:', e);
            const statusDiv = document.getElementById('importStatus');
            statusDiv.classList.remove('d-none');
            statusDiv.innerHTML = `<div class="alert alert-danger">系統發生錯誤，請按 F12 查看 Console 詳細訊息。</div>`;
        });
    }
    
    function runCustomImport() {
        const days = parseInt(document.getElementById('customDays').value);
        
        if (!days || days < 1 || days > 365) {
            alert('請輸入 1-365 之間的天數');
            return;
        }
        
        if (!confirm(`即將查詢最近 ${days} 天的資料，確定嗎？`)) {
            return;
        }
        
        // Show loading
        document.getElementById('importStatus').classList.remove('d-none');
        document.getElementById('importResultPanel').classList.add('d-none');
        
        fetch(`api/import_meter.php?custom_days=${days}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('importStatus').classList.add('d-none');
            
             if(data.success) {
                 if (data.query_range) {
                     console.log(`📅 自訂查詢: ${days} 天 | 範圍: ${data.query_range.begin} ~ ${data.query_range.end}`);
                 }
                 window.lastImportQueryRange = data.query_range || null;
                 window.lastImportData = data.data;
                 renderImportResults(data.data);
            } else {
                console.error('匯入失敗:', data.message);
                const statusDiv = document.getElementById('importStatus');
                statusDiv.classList.remove('d-none');
                statusDiv.innerHTML = `<div class="alert alert-danger">匯入失敗: ${data.message}</div>`;
            }
        })
        .catch(e => {
            document.getElementById('importStatus').classList.add('d-none');
            console.error('系統錯誤:', e);
            const statusDiv = document.getElementById('importStatus');
            statusDiv.classList.remove('d-none');
            statusDiv.innerHTML = `<div class="alert alert-danger">系統發生錯誤，請按 F12 查看 Console 詳細訊息。</div>`;
        });
    }

    function updateCustomDaysHint() {
        const input = document.getElementById('customDays');
        const hint = document.getElementById('customDaysHint');
        if (!input || !hint) return;
        const days = parseInt(input.value);
        const safeDays = (!days || days < 1) ? 1 : days;
        hint.textContent = `自訂匯入將查詢最近 ${safeDays} 天`;
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateCustomDaysHint();
        const input = document.getElementById('customDays');
        if (input) {
            input.addEventListener('input', updateCustomDaysHint);
        }
    });
    
    function renderImportResults(rows) {
        const tbodySuggested = document.getElementById('tbody-suggested');
        const tbodyAll = document.getElementById('tbody-all');
        const rangeText = document.getElementById('import-range-text');
        
        tbodySuggested.innerHTML = '';
        tbodyAll.innerHTML = '';
        
        let countSuggested = 0;
        
        rows.forEach((row, idx) => {
            const unitMatchDisplay = row.unit_id ? `<span class="text-success">${row.unit_display_name}</span>` : '<span class="text-danger">查無房源</span>';
            
            // 判斷是否為建議匯入
            const isSuggested = ['settlement', 'month_end', 'vacant_cost'].includes(row.status);
            
            // 原因標籤
            let reasonLabel = '';
            if (row.status === 'settlement') reasonLabel = '<span class="badge bg-danger">結算日</span>';
            else if (row.status === 'month_end') reasonLabel = '<span class="badge bg-primary">月底</span>';
            else if (row.status === 'vacant_cost') reasonLabel = '<span class="badge bg-secondary">空屋</span>';
            else if (row.status === 'attention') reasonLabel = '<span class="badge bg-warning text-dark">注意</span>';
            else reasonLabel = '<span class="badge bg-light text-muted">一般</span>';
            
            // 操作按鈕（只有有對應房源的才能匯入）
            const checkbox = row.unit_id ? `<input type="checkbox" class="import-check-all" data-idx="${idx}">` : '';
            const actionBtn = row.unit_id ? `<button class="btn btn-sm btn-outline-success" onclick="importSingleIdx(${idx})"><i class="bi bi-plus-circle"></i></button>` : '';
            
            // 全部資料分頁（所有記錄都顯示）
            tbodyAll.innerHTML += `<tr>
                <td>${checkbox}</td>
                <td>${row.date}</td>
                <td><small class="text-muted">[${row.room}]</small> ${unitMatchDisplay}</td>
                <td class="fw-bold">${row.reading}</td>
                <td>${reasonLabel}</td>
                <td>${actionBtn}</td>
            </tr>`;
            
            // 建議匯入分頁（只顯示建議項目）
            if (isSuggested && row.unit_id) {
                countSuggested++;
                const suggestedCheckbox = `<input type="checkbox" class="import-check-suggested" data-idx="${idx}" checked>`;
                
                tbodySuggested.innerHTML += `<tr>
                    <td>${suggestedCheckbox}</td>
                    <td>${row.date}</td>
                    <td><small class="text-muted">[${row.room}]</small> ${unitMatchDisplay}</td>
                    <td class="fw-bold">${row.reading}</td>
                    <td>${reasonLabel}</td>
                    <td>${actionBtn}</td>
                </tr>`;
            }
        });
        
        document.getElementById('badge-suggested').innerText = countSuggested;
        document.getElementById('total-count').innerText = rows.length;
        document.getElementById('importResultPanel').classList.remove('d-none');

        if (window.lastImportQueryRange && rangeText) {
            const q = window.lastImportQueryRange;
            const strategyLabels = {
                force_full: '強制完整匯入',
                incremental: '增量匯入',
                full: '完整查詢',
                custom: '自訂匯入'
            };
            const label = strategyLabels[q.strategy] || q.strategy;
            rangeText.textContent = `查詢範圍：${q.begin} ~ ${q.end}（${label}，${q.days} 天）`;
        }
    }
    
    function toggleAllCheckboxes(master, type) {
        const checkboxes = document.querySelectorAll(`.import-check-${type}`);
        checkboxes.forEach(cb => cb.checked = master.checked);
    }
    
    function importSingleIdx(idx) {
        const row = window.lastImportData[idx];
        saveReadingsToDB([{unit_id: row.unit_id, date: row.date, reading: row.reading}]);
    }

    function confirmBatchImport(type) {
        const checkboxes = document.querySelectorAll(`.import-check-${type}:checked`);
        if (checkboxes.length === 0) { alert('請先勾選要匯入的項目'); return; }
        
        const list = Array.from(checkboxes).map(cb => {
            const row = window.lastImportData[cb.dataset.idx];
            return {unit_id: row.unit_id, date: row.date, reading: row.reading};
        });
        
        saveReadingsToDB(list);
    }

    function closeImportPanel() {
        document.getElementById('importResultPanel').classList.add('d-none');
    }
    
    function saveReadingsToDB(list) {
        if(list.length === 0) return;
        if(!confirm(`即將匯入 ${list.length} 筆資料，確定嗎？`)) return;
        
        // Disable buttons
        fetch('api/save_reading_batch.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({readings: list})
        })
        .then(r => r.json())
        .then(res => {
            let msg = `成功匯入 ${res.count} 筆資料`;
            if(res.errors && res.errors.length > 0) {
                msg += `\n\n警告：有 ${res.errors.length} 筆匯入失敗或略過：\n` + res.errors.slice(0, 5).join('\n') + (res.errors.length > 5 ? '...' : '');
            }
            alert(msg);
            if(res.count > 0) location.reload();
        })
        .catch(e => alert('匯入發生錯誤: ' + e));
    }
    
    function importSingle(room, date, val, unitId) {
        if (!unitId) { alert('查無此房間 ID，無法匯入'); return; }
        saveReadingsToDB([{unit_id: unitId, date: date, reading: val}]);
    }
    
    function confirmImport(type) {
        const rows = window.lastImportData || [];
        let list = [];
        
        if(type === 'suggested') {
            list = rows.filter(r => r.status === 'import_suggested' && r.unit_id);
        }
        
        if(list.length === 0) {
            alert('沒有可匯入的資料');
            return;
        }
        
        saveReadingsToDB(list);
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const unitSelect = document.getElementById('chartUnitSelect');
    const rangeSelect = document.getElementById('chartRangeSelect');
    const canvas = document.getElementById('meterChart');
    const emptyText = document.getElementById('chartEmpty');
    const summaryTotal = document.getElementById('summaryTotal');
    const summaryAvg = document.getElementById('summaryAvg');
    const summaryMax = document.getElementById('summaryMax');
    const chartSubtitle = document.getElementById('chartSubtitle');

    if (!unitSelect || !rangeSelect || !canvas) {
        return;
    }

    let chart;

    function renderChart(data) {
        const labels = data.labels || [];
        const usage = data.usage || [];
        const readings = data.readings || [];
        const datasets = data.datasets || [];
        const avgValue = data.summary && data.summary.avg_kwh !== null ? data.summary.avg_kwh : null;
        const lineLabel = data.line_label || '累積讀數';
        const subtitleText = data.subtitle || '每日用量 + 累積讀數';

        if (chartSubtitle) {
            chartSubtitle.textContent = subtitleText;
        }

        if (labels.length === 0) {
            if (chart) {
                chart.destroy();
                chart = null;
            }
            emptyText.style.display = 'block';
            return;
        }

        emptyText.style.display = 'none';

        const ctx = canvas.getContext('2d');
        if (chart) {
            chart.destroy();
        }

        const avgLine = avgValue !== null ? labels.map(() => avgValue) : [];
        const usageColors = usage.map(() => 'rgba(13, 110, 253, 0.25)');
        const usageBorderColors = usage.map(() => 'rgba(13, 110, 253, 0.7)');

        if (data.mode === 'overall') {
            const palette = [
                'rgba(13, 110, 253, 0.6)',
                'rgba(25, 135, 84, 0.6)',
                'rgba(255, 193, 7, 0.6)',
                'rgba(220, 53, 69, 0.6)',
                'rgba(111, 66, 193, 0.6)',
                'rgba(13, 202, 240, 0.6)'
            ];
            const stacked = datasets.map((ds, idx) => ({
                type: 'bar',
                label: ds.label,
                data: ds.data,
                backgroundColor: palette[idx % palette.length],
                borderWidth: 0,
                yAxisID: 'y'
            }));

            chart = new Chart(ctx, {
                data: {
                    labels,
                    datasets: stacked
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            ticks: {
                                color: function(ctx) {
                                    const label = ctx.chart.data.labels[ctx.index];
                                    if (!label) return '#6c757d';
                                    const day = new Date(label + 'T00:00:00').getDay();
                                    return (day === 0 || day === 6) ? '#dc3545' : '#6c757d';
                                }
                            }
                        },
                        y: {
                            stacked: true,
                            position: 'left',
                            title: { display: true, text: 'kWh' }
                        }
                    },
                    plugins: {
                        legend: { display: true },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            itemSort: (a, b) => a.datasetIndex - b.datasetIndex
                        }
                    }
                }
            });
        } else {
            chart = new Chart(ctx, {
                data: {
                    labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: '每日用量 (kWh)',
                            data: usage,
                            backgroundColor: usageColors,
                            borderColor: usageBorderColors,
                            borderWidth: 1,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: lineLabel,
                            data: readings,
                            borderColor: 'rgba(25, 135, 84, 0.9)',
                            backgroundColor: 'rgba(25, 135, 84, 0.15)',
                            borderWidth: 2,
                            tension: 0.3,
                            yAxisID: 'y1'
                        },
                        {
                            type: 'line',
                            label: '日均基準',
                            data: avgLine,
                            borderColor: 'rgba(255, 193, 7, 0.9)',
                            borderWidth: 2,
                            borderDash: [6, 4],
                            pointRadius: 0,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            ticks: {
                                color: function(ctx) {
                                    const label = ctx.chart.data.labels[ctx.index];
                                    if (!label) return '#6c757d';
                                    const day = new Date(label + 'T00:00:00').getDay();
                                    return (day === 0 || day === 6) ? '#dc3545' : '#6c757d';
                                }
                            }
                        },
                        y: {
                            position: 'left',
                            title: { display: true, text: 'kWh' }
                        },
                        y1: {
                            position: 'right',
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: lineLabel }
                        }
                    },
                    plugins: {
                        legend: { display: true },
                        tooltip: { mode: 'index', intersect: false }
                    }
                }
            });
        }
    }

    function updateSummary(summary) {
        summaryTotal.textContent = `總用量：${summary.total_kwh ?? '--'} kWh`;
        summaryAvg.textContent = `日均：${summary.avg_kwh ?? '--'} kWh`;
        summaryMax.textContent = `單日最高：${summary.max_kwh ?? '--'} kWh`;
    }

    function loadChart() {
        const unitId = unitSelect.value;
        const range = rangeSelect.value;
        fetch(`api/get_meter_chart.php?unit_id=${encodeURIComponent(unitId)}&range_days=${encodeURIComponent(range)}`)
            .then(res => res.json())
            .then(data => {
                renderChart(data);
                updateSummary(data.summary || {});
            })
            .catch(() => {
                renderChart({ labels: [], usage: [], readings: [] });
                updateSummary({});
                if (chartSubtitle) {
                    chartSubtitle.textContent = '每日用量 + 累積讀數';
                }
            });
    }

    unitSelect.addEventListener('change', loadChart);
    rangeSelect.addEventListener('change', loadChart);
    loadChart();
});
</script>
