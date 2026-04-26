<?php
// Handle Bill Generation
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_bill') {
    $tenant_id = (int)$_POST['tenant_id'];
    $unit_id = (int)$_POST['unit_id'];
    $rent_amount = (int)$_POST['rent_amount'];
    $elec_rate = (float)$_POST['elec_rate'];
    
    $start_reading_id = (int)$_POST['start_reading_id'];
    $start_reading_id = (int)$_POST['start_reading_id'];
    $end_reading_id = (int)$_POST['end_reading_id'];
    $bill_date = $_POST['bill_date'] ?? date('Y-m-d'); // Custom date
    
    // Fetch Reading Values
    $pdo = DB::connect();
    $stmt = $pdo->prepare("SELECT id, reading_value, record_date FROM electricity_readings WHERE id IN (?, ?)");
    
    // Fetch safely
    $start_val = 0; $end_val = 0;
    $stmt->execute([$start_reading_id, $end_reading_id]);
    $rows = $stmt->fetchAll();
    foreach($rows as $r) {
        if ($r['id'] == $start_reading_id) $start_val = $r['reading_value'];
        if ($r['id'] == $end_reading_id) $end_val = $r['reading_value'];
    }

    $usage = $end_val - $start_val;
    if ($usage < 0) $usage = 0;
    
    $elec_cost = round($usage * $elec_rate);
    $now = date('Y-m-d H:i:s');
    
    try {
        $pdo->beginTransaction();

        // 1. Rent
        $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type) VALUES (?, 'income', '房租', ?, ?, ?, ?, 0, 'bill')");
        $stmt->execute([$bill_date, $rent_amount, "房租明細", $tenant_id, $unit_id]);

        // 2. Electricity
        if ($elec_cost > 0) {
            $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type, ref_id) VALUES (?, 'income', '電費', ?, ?, ?, ?, 0, 'bill', ?)");
            $stmt->execute([$bill_date, $elec_cost, "電費 (用量: {$usage}度, 費率: \${$elec_rate}/度, {$start_val}->{$end_val})", $tenant_id, $unit_id, $end_reading_id]);
        }
        
        // 3. Mark Reading as Closing
        $stmt = $pdo->prepare("UPDATE electricity_readings SET record_type = 'closing' WHERE id = ?");
        $stmt->execute([$end_reading_id]);
        
        $pdo->commit();
        $message = '<div class="alert alert-success">帳單已拆分開立！房租: $' . number_format($rent_amount) . ' + 電費: $' . number_format($elec_cost) . ' (費率: $' . $elec_rate . ')</div>';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">開立失敗: ' . $e->getMessage() . '</div>';
    }
}

// Fetch Active Tenants (In active units only)
$tenants = DB::connect()->query("
    SELECT t.*, u.name as unit_name, u.base_rent
    FROM tenants t 
    JOIN units u ON t.unit_id = u.id 
    WHERE t.is_active = 1 AND u.is_active = 1
    ORDER BY u.name ASC
")->fetchAll();

// API-like block to fetch readings for a specific unit (via AJAX or simply reload page logic, keeping it simple with reload for now or JS? 
// Let's use simple PHP rendering for selected tenant if param exists, or JS for dynamic)
// Actually, pure JS for fetching readings is better.
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">帳單試算與開立</h1>
</div>

<?= $message ?>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="post" id="billingForm">
                    <input type="hidden" name="action" value="generate_bill">
                    
                    <!-- 1. Select Tenant -->
                    <div class="mb-4">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label h6">帳單歸屬日期</label>
                                <input type="date" class="form-control" name="bill_date" value="<?= date('Y-m-d') ?>" required>
                                <div class="form-text">這將決定帳單顯示在報表上的月份。</div>
                            </div>
                        </div>

                        <label class="form-label h6">步驟 1: 選擇房客</label>
                        <select class="form-select" id="tenantSelect" name="tenant_id" required onchange="loadTenantDetails(this)">
                            <option value="">-- 請選擇 --</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>" 
                                    data-unit-id="<?= $t['unit_id'] ?>" 
                                    data-rent="<?= $t['base_rent'] ?>"
                                >
                                    <?= htmlspecialchars($t['unit_name']) ?> - <?= htmlspecialchars($t['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="unit_id" id="unitIdField">
                    </div>

                    <!-- 2. Rent Details -->
                    <div class="mb-4 bg-light p-3 rounded">
                        <label class="form-label h6">步驟 2: 確認租金</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="rent_amount" id="rentAmountField" readonly>
                        </div>
                    </div>

                    <!-- 3. Electricity Calculation -->
                    <div class="mb-4 p-3 border rounded">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label h6 mb-0">步驟 3: 電費計算</label>
                            <div class="input-group input-group-sm" style="width: 150px;">
                                <span class="input-group-text">費率 $</span>
                                <input type="number" class="form-control" name="elec_rate" id="elecRateField" value="<?= sc_ELEC_RATE ?>" step="0.1" onchange="calculatePreview()">
                                <span class="input-group-text">/度</span>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted">起算讀數 (上期)</label>
                                <select class="form-select" name="start_reading_id" id="startReadingSelect" required onchange="calculatePreview()">
                                    <option value="">(請先選擇房客)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">結算讀數 (本期)</label>
                                <select class="form-select" name="end_reading_id" id="endReadingSelect" required onchange="calculatePreview()">
                                    <option value="">(請先選擇房客)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <span class="text-muted">用量: <span id="usagePreview">0</span> 度</span> | 
                            <span class="fw-bold text-primary">電費: $<span id="elecCostPreview">0</span></span>
                        </div>
                    </div>

                    <!-- 4. Total and Submit -->
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">確認開立帳單 (總計: $<span id="totalPreview">0</span>)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="alert alert-info">
            <h5><i class="bi bi-lightbulb"></i> 說明</h5>
            <ol>
                <li>選擇房客後，系統會自動帶入基本租金。</li>
                <li>請從下拉選單選擇「起算」與「結算」的電表紀錄。</li>
                <li>若找不到最新的電表紀錄，請先至「電表紀錄」頁面輸入今日讀數。</li>
                <li>確認金額無誤後，點擊開立。帳單將會存入「財務流水帳」。</li>
            </ol>
        </div>
    </div>
</div>

<script>
    const ELEC_RATE = <?= sc_ELEC_RATE ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tenantId = urlParams.get('tenant_id');
        if (tenantId) {
            const select = document.getElementById('tenantSelect');
            select.value = tenantId;
            loadTenantDetails(select);
        }
    });

    function loadTenantDetails(select) {
        const option = select.options[select.selectedIndex];
        if (!option.value) return;

        const unitId = option.getAttribute('data-unit-id');
        const rent = option.getAttribute('data-rent');

        document.getElementById('unitIdField').value = unitId;
        document.getElementById('rentAmountField').value = rent;
        
        // Fetch readings via simple fetch to a new API endpoint we will create inline or separate
        fetch(`api/get_readings.php?unit_id=${unitId}`)
            .then(response => response.json())
            .then(data => {
                const startSelect = document.getElementById('startReadingSelect');
                const endSelect = document.getElementById('endReadingSelect');
                
                // Reset
                startSelect.innerHTML = '<option value="">-- 選擇起算度數 --</option>';
                endSelect.innerHTML = '<option value="">-- 選擇結算度數 --</option>';

                let lastClosingId = null;
                data.forEach((r, index) => {
                    const text = `${r.record_date} (${r.reading_value}) ${r.record_type === 'closing' ? '[結算點]' : ''}`;
                    
                    // Add to Start (Previous)
                    startSelect.add(new Option(text, r.id));
                    
                    // Add to End (Current)
                    endSelect.add(new Option(text, r.id, false, index === 0)); // Default latest as end
                    
                    if (r.record_type === 'closing' && lastClosingId === null) {
                        lastClosingId = r.id;
                    }
                });

                // Default Start to last closing, or 2nd latest if none
                if (lastClosingId) {
                    startSelect.value = lastClosingId;
                } else if (data.length > 1) {
                    startSelect.value = data[1].id;
                }
                
                // Store data for quick lookup
                window.readingsData = data.reduce((map, obj) => {
                    map[obj.id] = { val: obj.reading_value, type: obj.record_type };
                    return map;
                }, {});

                calculatePreview();
            });
    }

    function calculatePreview() {
        const startId = document.getElementById('startReadingSelect').value;
        const endId = document.getElementById('endReadingSelect').value;
        const rent = parseFloat(document.getElementById('rentAmountField').value) || 0;
        const rate = parseFloat(document.getElementById('elecRateField').value) || 0;

        if (startId && endId && window.readingsData) {
            const startVal = window.readingsData[startId].val;
            const endVal = window.readingsData[endId].val;
            
            let usage = endVal - startVal;
            if (usage < 0) usage = 0; // Should alert ideally
            
            const elecCost = Math.round(usage * rate);
            const total = rent + elecCost;

            document.getElementById('usagePreview').innerText = usage.toFixed(1);
            document.getElementById('elecCostPreview').innerText = elecCost;
            document.getElementById('totalPreview').innerText = total;
        }
    }
</script>
