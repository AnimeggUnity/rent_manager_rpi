<?php
// Handle Record Submission (AJAX or Standard POST)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_reading') {
    $unit_id = (int)$_POST['unit_id'];
    $record_date = $_POST['record_date'];
    $reading_value = (float)$_POST['reading_value'];
    $record_type = 'manual';

    $pdo = DB::connect();
    $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
    $stmt->execute([$unit_id, $record_date]);
    $prev = $stmt->fetch();
    
    $diff_value = 0;
    if ($prev) {
        $diff_value = $reading_value - $prev['reading_value'];
    }

    if ($diff_value < 0) {
        $message = '<div class="alert alert-danger">錯誤：度數不可小於前次。</div>';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO electricity_readings (unit_id, record_date, reading_value, diff_value, record_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$unit_id, $record_date, $reading_value, $diff_value, $record_type]);
            $message = '<div class="alert alert-success">已儲存！用量: ' . $diff_value . ' 度</div>';
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">錯誤: ' . $e->getMessage() . '</div>';
        }
    }
}

// Handle Edit Reading (Quick Fix)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_reading') {
    $id = (int)$_POST['id'];
    $new_val = (float)$_POST['reading_value'];
    $pdo = DB::connect();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT unit_id, record_date FROM electricity_readings WHERE id = ?");
        $stmt->execute([$id]);
        $curr = $stmt->fetch();
        if ($curr) {
            $stmt = $pdo->prepare("SELECT reading_value FROM electricity_readings WHERE unit_id = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1");
            $stmt->execute([$curr['unit_id'], $curr['record_date']]);
            $prev = $stmt->fetch();
            $new_diff = $prev ? ($new_val - $prev['reading_value']) : 0;
            $pdo->prepare("UPDATE electricity_readings SET reading_value = ?, diff_value = ? WHERE id = ?")->execute([$new_val, $new_diff, $id]);
            
            $stmt = $pdo->prepare("SELECT id, reading_value FROM electricity_readings WHERE unit_id = ? AND record_date > ? ORDER BY record_date ASC LIMIT 1");
            $stmt->execute([$curr['unit_id'], $curr['record_date']]);
            $next = $stmt->fetch();
            if ($next) {
                $next_diff = $next['reading_value'] - $new_val;
                $pdo->prepare("UPDATE electricity_readings SET diff_value = ? WHERE id = ?")->execute([$next_diff, $next['id']]);
            }
        }
        $pdo->commit();
        $message = '<div class="alert alert-success small">紀錄已更新！</div>';
    } catch (Exception $e) { $pdo->rollBack(); $message = '<div class="alert alert-danger small">失敗: ' . $e->getMessage() . '</div>'; }
}

// Fetch Active Units with their last reading
$units = DB::connect()->query("
    SELECT u.id, u.name, 
           (SELECT reading_value FROM electricity_readings WHERE unit_id = u.id ORDER BY record_date DESC LIMIT 1) as last_reading,
           (SELECT record_date FROM electricity_readings WHERE unit_id = u.id ORDER BY record_date DESC LIMIT 1) as last_date
    FROM units u 
    WHERE u.is_active = 1 
    ORDER BY u.name ASC
")->fetchAll();

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

<div class="pt-3 pb-2 mb-3 border-bottom d-flex justify-content-between align-items-center">
    <h1 class="h2">快速抄表</h1>
    <span class="badge bg-info text-dark"><?= date('m/d') ?></span>
</div>

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
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">帳號 (User)</label>
                        <input type="text" id="meterUser" class="form-control" placeholder="輸入帳號" value="<?= htmlspecialchars($meterConfig['username']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">密碼 (Password)</label>
                        <input type="password" id="meterPass" class="form-control" placeholder="輸入密碼" value="<?= htmlspecialchars($meterConfig['password']) ?>">
                    </div>
                    <div class="col-md-4">
                         <div class="d-flex gap-2">
                            <button class="btn btn-secondary flex-grow-1" onclick="saveMeterSettings()">
                                <i class="bi bi-save"></i> 儲存
                            </button>
                            <button class="btn btn-primary flex-grow-1" onclick="runMeterImport()">
                                <i class="bi bi-play-fill"></i> 執行匯入
                            </button>
                         </div>
                         <div class="d-grid mt-2">
                            <button class="btn btn-warning btn-sm" onclick="runMeterImport(true)" title="忽略上次匯入時間，強制載入完整 3 個月資料">
                                <i class="bi bi-arrow-clockwise"></i> 強制完整匯入 (3個月)
                            </button>
                         </div>
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
    <div class="card border-primary">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">📋 匯入預覽 (智慧篩選)</h6>
            <button class="btn btn-sm btn-light text-primary" onclick="closeImportPanel()">關閉</button>
        </div>
        <div class="card-body p-0">
            <ul class="nav nav-tabs nav-justified" id="importTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="settlement-tab" data-bs-toggle="tab" data-bs-target="#settlement-pane" type="button">
                        🎯 結算日 (Settlement) <span class="badge bg-danger rounded-pill ms-1" id="badge-settlement">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="monthend-tab" data-bs-toggle="tab" data-bs-target="#monthend-pane" type="button">
                        🌙 月底 (Month-end) <span class="badge bg-primary rounded-pill ms-1" id="badge-monthend">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="vacant-tab" data-bs-toggle="tab" data-bs-target="#vacant-pane" type="button">
                        🏠 空屋 (Vacant) <span class="badge bg-secondary rounded-pill ms-1" id="badge-vacant">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-pane" type="button">
                        👁️ 全部 (All)
                    </button>
                </li>
            </ul>
            <div class="tab-content p-3" id="importTabContent">
                <!-- Settlement Pane -->
                <div class="tab-pane fade show active" id="settlement-pane">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this, 'settlement')"></th>
                                    <th>日期</th><th>網頁房號</th><th>系統對應</th><th>讀數</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-settlement"></tbody>
                        </table>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-danger" onclick="confirmBatchImport('settlement')">匯入已選取的結算日資料</button>
                    </div>
                </div>
                <!-- Month End Pane -->
                <div class="tab-pane fade" id="monthend-pane">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this, 'month_end')"></th>
                                    <th>日期</th><th>網頁房號</th><th>系統對應</th><th>讀數</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-monthend"></tbody>
                        </table>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-primary" onclick="confirmBatchImport('month_end')">匯入已選取的月底資料</button>
                    </div>
                </div>
                <!-- Vacant Pane -->
                <div class="tab-pane fade" id="vacant-pane">
                    <div class="alert alert-warning small py-1 mb-2">空屋期間耗損 (月底)</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this, 'vacant_cost')"></th>
                                    <th>日期</th><th>網頁房號</th><th>系統對應</th><th>讀數</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-vacant"></tbody>
                        </table>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-secondary" onclick="confirmBatchImport('vacant_cost')">匯入已選取的空屋耗損</button>
                    </div>
                </div>
                <!-- All Pane -->
                <div class="tab-pane fade" id="all-pane">
                     <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle">
                            <thead><tr><th>日期</th><th>網頁房號</th><th>系統對應</th><th>讀數</th><th>狀態</th></tr></thead>
                            <tbody id="tbody-all"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<?= $message ?>

<div class="row g-3">
    <?php foreach ($units as $u): ?>
        <?php 
        $is_today = ($u['last_date'] === date('Y-m-d'));
        ?>
        <div class="col-6 col-md-4">
            <div class="card h-100 shadow-sm <?= $is_today ? 'border-success' : '' ?>" onclick="openReadingModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>', '<?= $u['last_reading'] ?? 0 ?>')">
                <div class="card-body text-center p-3">
                    <h5 class="card-title mb-1"><?= htmlspecialchars($u['name']) ?></h5>
                    <div class="text-muted small mb-2">上期: <?= number_format($u['last_reading'] ?? 0, 1) ?></div>
                    <?php if($is_today): ?>
                        <span class="badge bg-success-subtle text-success border border-success">今日已抄</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border">待輸入</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</div>

<hr class="my-4">

<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-light py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i> 最近輸入紀錄 (快速修正)</h6>
    </div>
    <div class="list-group list-group-flush">
        <?php
        $recent = DB::connect()->query("
            SELECT e.*, u.name as unit_name 
            FROM electricity_readings e 
            JOIN units u ON e.unit_id = u.id 
            ORDER BY e.created_at DESC 
            LIMIT 5
        ")->fetchAll();
        
        if (empty($recent)): ?>
            <div class="list-group-item text-center py-3 text-muted small">尚無紀錄</div>
        <?php else: 
            foreach($recent as $r): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div>
                        <div class="fw-bold">
                            <?= htmlspecialchars($r['unit_name']) ?> 
                            <span class="badge bg-secondary-subtle text-secondary fw-normal small ms-1"><?= $r['record_date'] ?></span>
                            <?php if ($r['record_type'] === 'closing'): ?>
                                <span class="badge bg-success-subtle text-success border border-success fw-normal ms-1" style="font-size: 0.65rem;">已結算</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-primary fs-5 fw-bold"><?= number_format($r['reading_value'], 1) ?> <small class="text-muted fw-normal" style="font-size: 0.75rem;">度</small></div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary px-3 rounded-pill" onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['unit_name']) ?>', '<?= $r['record_date'] ?>', <?= $r['reading_value'] ?>)">
                        修正
                    </button>
                </div>
            <?php endforeach; 
        endif; ?>
    </div>
</div>

<!-- Quick Entry Modal -->
<div class="modal fade" id="readingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="modalUnitName">房源抄表</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body pt-2">
                    <input type="hidden" name="action" value="add_reading">
                    <input type="hidden" name="unit_id" id="modalUnitId">
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">抄表日期</label>
                        <input type="date" class="form-control" name="record_date" id="modalDate" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <label class="form-label mb-0 text-muted">上次讀數</label>
                            <span id="modalLastReading" class="fw-bold">0</span>
                        </div>
                        <label class="form-label fs-5 fw-bold">輸入今日讀數</label>
                        <input type="number" step="0.1" class="form-control form-control-lg text-center fs-2 py-3" 
                               name="reading_value" id="modalInput" required 
                               inputmode="decimal" placeholder="000.0">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-lg btn-light flex-grow-1" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-lg btn-primary flex-grow-1">儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal (Mobile Enhanced) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">修正剛才的輸入</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body pt-2">
                    <input type="hidden" name="action" value="edit_reading">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">房源計畫</label>
                        <div class="fs-4 fw-bold" id="editUnitDisplay"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fs-5 fw-bold">正確度數</label>
                        <input type="number" step="0.1" class="form-control form-control-lg text-center fs-2 py-3" name="reading_value" id="editValue" required inputmode="decimal">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-lg btn-light flex-grow-1" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-lg btn-primary flex-grow-1">更新</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let readingModal = null;
    let editModal = null;
    
    document.addEventListener('DOMContentLoaded', function() {
        const rModalEl = document.getElementById('readingModal');
        if (rModalEl) readingModal = new bootstrap.Modal(rModalEl);
        
        const eModalEl = document.getElementById('editModal');
        if (eModalEl) editModal = new bootstrap.Modal(eModalEl);
    });
    
    function openReadingModal(id, name, last) {
        document.getElementById('modalUnitId').value = id;
        document.getElementById('modalUnitName').innerText = name + ' 室 - 抄電表';
        document.getElementById('modalLastReading').innerText = last;
        const input = document.getElementById('modalInput');
        input.value = '';
        input.placeholder = last;
        if (readingModal) {
            readingModal.show();
            setTimeout(() => input.focus(), 500);
        }
    }

    function openEditModal(id, name, date, value) {
        document.getElementById('editId').value = id;
        document.getElementById('editUnitDisplay').innerText = name + ' (' + date + ')';
        const input = document.getElementById('editValue');
        input.value = value;
        if (editModal) {
            editModal.show();
            setTimeout(() => input.focus(), 500);
        }
    }
    
    // --- Auto Import Functions ---
    
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
            // Optional: Update UI to show error state briefly
            const statusDiv = document.getElementById('importStatus');
            statusDiv.classList.remove('d-none');
            statusDiv.innerHTML = `<div class="alert alert-danger">系統發生錯誤，請按 F12 查看 Console 詳細訊息。</div>`;
        });
    }
    
    function renderImportResults(rows) {
        const tbodySettlement = document.getElementById('tbody-settlement');
        const tbodyMonthEnd = document.getElementById('tbody-monthend');
        const tbodyVacant = document.getElementById('tbody-vacant');
        const tbodyAll = document.getElementById('tbody-all');
        
        tbodySettlement.innerHTML = '';
        tbodyMonthEnd.innerHTML = '';
        tbodyVacant.innerHTML = '';
        tbodyAll.innerHTML = '';
        
        let countSettlement = 0;
        let countMonthEnd = 0;
        let countVacant = 0;
        
        rows.forEach((row, idx) => {
            const unitMatchDisplay = row.unit_id ? `<span class="text-success ml-1"><i class="bi bi-link-45deg"></i> ${row.unit_display_name}</span>` : '<span class="text-danger">查無房源</span>';
            const basicRow = `<td>${row.date}</td><td><small class="text-muted">[${row.room}]</small></td><td>${unitMatchDisplay}</td><td class="fw-bold">${row.reading}</td>`;
            
            // All Tab
            let statusBadge = '';
            if(row.status === 'settlement') statusBadge = '<span class="badge bg-danger">結算</span>';
            else if(row.status === 'month_end') statusBadge = '<span class="badge bg-primary">月底</span>';
            else if(row.status === 'vacant_cost') statusBadge = '<span class="badge bg-secondary">空屋</span>';
            else if(row.status === 'attention') statusBadge = '<span class="badge bg-warning text-dark">注意</span>';
            else statusBadge = '<span class="badge bg-light text-muted">忽視</span>';
            
            tbodyAll.innerHTML += `<tr>${basicRow}<td>${statusBadge} ${row.reason}</td></tr>`;
            
            // Checkbox for batch
            const checkbox = row.unit_id ? `<input type="checkbox" class="import-check-${row.status}" data-idx="${idx}">` : '';
            const actionBtn = row.unit_id ? `<button class="btn btn-sm btn-outline-success" onclick="importSingleIdx(${idx})"><i class="bi bi-plus-circle"></i></button>` : '';

            if (row.status === 'settlement') {
                countSettlement++;
                tbodySettlement.innerHTML += `<tr><td>${checkbox}</td>${basicRow}<td>${actionBtn}</td></tr>`;
            } else if (row.status === 'month_end') {
                countMonthEnd++;
                tbodyMonthEnd.innerHTML += `<tr><td>${checkbox}</td>${basicRow}<td>${actionBtn}</td></tr>`;
            } else if (row.status === 'vacant_cost') {
                countVacant++;
                tbodyVacant.innerHTML += `<tr><td>${checkbox}</td>${basicRow}<td>${actionBtn}</td></tr>`;
            }
        });
        
        document.getElementById('badge-settlement').innerText = countSettlement;
        document.getElementById('badge-monthend').innerText = countMonthEnd;
        document.getElementById('badge-vacant').innerText = countVacant;
        document.getElementById('importResultPanel').classList.remove('d-none');
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
        } else if (type === 'vacant') {
            // Future feature
            alert('空屋匯入尚未開放');
            return;
        }
        
        if(list.length === 0) {
            alert('沒有可匯入的資料 (或系統找不到對應房間 ID)');
            return;
        }
        
        saveReadingsToDB(list);
    }
</script>

<style>
    /* Prevent zoom on focus for iOS */
    @media screen and (max-width: 767px) {
        input, select, textarea {
            font-size: 16px !important;
        }
    }
    .card { cursor: pointer; transition: transform 0.1s; }
    .card:active { transform: scale(0.95); }
    .list-group-item { border-left: none; border-right: none; }
</style>
