<?php
// views/move_out.php - Tenant Settlement & Move-out Workflow

$tenant_id = (int)($_GET['tenant_id'] ?? 0);
if (!$tenant_id) die("Invalid Tenant ID");

$pdo = DB::connect();

// Fetch Tenant & Unit Info
$t = $pdo->query("SELECT t.*, u.name as unit_name, u.base_rent, u.id as unit_id FROM tenants t JOIN units u ON t.unit_id = u.id WHERE t.id = $tenant_id")->fetch();
if (!$t) die("Tenant not found");

// Fetch Unpaid Bills
$unpaid = $pdo->query("SELECT * FROM ledger WHERE tenant_id = $tenant_id AND is_paid = 0 ORDER BY trans_date ASC")->fetchAll();

// Fetch Last Meter Reading
$last_reading = $pdo->query("SELECT * FROM electricity_readings WHERE unit_id = {$t['unit_id']} ORDER BY record_date DESC, id DESC LIMIT 1")->fetch();

// Handle Confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'confirm_settlement') {
    $final_reading = (float)$_POST['final_reading'];
    $refund_amount = (int)$_POST['refund_amount']; // Calculated by frontend, but we should re-verify ideally. For now, trust admin input.
    $deductions = $_POST['deductions'] ?? []; // Array of desc => amount
    $final_elec_cost = (int)$_POST['final_elec_cost'];
    
    try {
        $pdo->beginTransaction();

        // 1. Insert Final Electricity Bill (if any)
        if ($final_elec_cost > 0) {
            $usage = $final_reading - ($last_reading['reading_value'] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type) VALUES (DATE('now'), 'income', '電費', ?, ?, ?, ?, 1, 'final_settlement')");
            $stmt->execute([$final_elec_cost, "退租結算電費 (用量: {$usage}度)", $tenant_id, $t['unit_id']]);
        }

        // 2. Mark all unpaid bills as Paid (Offset by Deposit)
        $pdo->exec("UPDATE ledger SET is_paid = 1 WHERE tenant_id = $tenant_id AND is_paid = 0");

        // 3. Record Deposit Refund (Expense) or Additional Payment (Income)
        if ($refund_amount > 0) {
            // We are returning money
            $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type) VALUES (DATE('now'), 'expense', '押金退還', ?, '退租結算退款', ?, ?, 1, 'refund')");
            $stmt->execute([$refund_amount, $tenant_id, $t['unit_id']]);
        } elseif ($refund_amount < 0) {
            // Tenant owes us money (Deposit wasn't enough)
            $owed = abs($refund_amount);
            $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type) VALUES (DATE('now'), 'income', '結算尾款', ?, '退租結算補繳', ?, ?, 1, 'settlement')");
            $stmt->execute([$owed, $tenant_id, $t['unit_id']]);
        }

        // 4. Deactivate Tenant
        $stmt = $pdo->prepare("UPDATE tenants SET is_active = 0, contract_end = DATE('now') WHERE id = ?");
        $stmt->execute([$tenant_id]);

        $pdo->commit();
        header("Location: index.php?p=tenants&status=success");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>結算失敗: " . $e->getMessage() . "</div>";
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">退租結算：<?= htmlspecialchars($t['unit_name']) ?> - <?= htmlspecialchars($t['name']) ?></h1>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-dark text-white">結算明細表</div>
            <div class="card-body">
                <form method="post" id="settlementForm">
                    <input type="hidden" name="action" value="confirm_settlement">
                    
                    <!-- 1. Deposit -->
                    <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded lead">
                        <span><i class="bi bi-safe me-2"></i> 原始押金 (收入)</span>
                        <span class="text-success fw-bold">+$<span id="depositVal"><?= $t['security_deposit'] ?></span></span>
                    </div>

                    <!-- 2. Unpaid Bills -->
                    <h6 class="text-muted border-bottom pb-2">未繳帳單 (扣項)</h6>
                    <div class="mb-3">
                        <table class="table table-sm table-borderless">
                            <?php 
                            $total_unpaid = 0;
                            foreach ($unpaid as $bill): 
                                $total_unpaid += $bill['amount'];
                            ?>
                            <tr>
                                <td><?= $bill['trans_date'] ?></td>
                                <td><?= $bill['category'] ?></td>
                                <td><?= $bill['description'] ?></td>
                                <td class="text-end text-danger">-$<?= $bill['amount'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($unpaid)): ?>
                                <tr><td colspan="4" class="text-muted fst-italic">無未繳帳單</td></tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- 3. Final Electricity -->
                    <h6 class="text-muted border-bottom pb-2">結算電費 (扣項)</h6>
                    <div class="row mb-3 align-items-end">
                        <div class="col-md-4">
                            <label class="small text-muted">上期讀數</label>
                            <input type="number" class="form-control" value="<?= $last_reading['reading_value'] ?? 0 ?>" readonly id="lastReading">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold">今日退租讀數</label>
                            <input type="number" class="form-control border-primary" name="final_reading" id="finalReading" step="0.1" required oninput="calcTotal()">
                        </div>
                        <div class="col-md-4 text-end">
                            <input type="hidden" name="final_elec_cost" id="finalElecCostInput" value="0">
                            <span class="text-danger fw-bold">-$<span id="finalElecDisplay">0</span></span>
                            <div class="small text-muted">($<?= sc_ELEC_RATE ?>/度)</div>
                        </div>
                    </div>

                    <!-- 4. Other Deductions -->
                    <h6 class="text-muted border-bottom pb-2">其他扣款 (修繕/清潔費)</h6>
                    <div id="deductionList">
                        <!-- JS generated -->
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addDeduction()">
                        <i class="bi bi-plus"></i> 新增扣款項目
                    </button>

                    <hr>

                    <!-- Total -->
                    <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-secondary bg-opacity-10 rounded">
                        <h4 class="mb-0">結算應退金額</h4>
                        <input type="hidden" name="refund_amount" id="refundAmountInput">
                        <h2 class="mb-0 fw-bold" id="refundTotalText">$0</h2>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-danger btn-lg p-3" onclick="return confirm('確定要執行退租結算嗎？這將會把房客設為無效並產生相關流水帳。')">
                            確認結算並退租
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="alert alert-warning">
            <h5><i class="bi bi-exclamation-circle"></i> 注意事項</h5>
            <ol>
                <li>請務必確認「今日退租讀數」正確。</li>
                <li>系統會自動將所有「未繳帳單」標記為已由押金扣抵。</li>
                <li>若「應退金額」為負數，代表房客需要補錢給您。</li>
                <li>結算後，房客合約將立即終止。</li>
            </ol>
        </div>
    </div>
</div>

<script>
const ELEC_RATE = <?= sc_ELEC_RATE ?>;
const DEPOSIT = <?= $t['security_deposit'] ?>;
const UNPAID_TOTAL = <?= $total_unpaid ?>;
let deductionCount = 0;

function calcTotal() {
    const last = parseFloat(document.getElementById('lastReading').value) || 0;
    const current = parseFloat(document.getElementById('finalReading').value) || 0;
    
    let usage = 0;
    if (current > last) {
        usage = current - last;
    }
    
    // Elec Cost
    const elecCost = Math.round(usage * ELEC_RATE);
    document.getElementById('finalElecDisplay').innerText = elecCost;
    document.getElementById('finalElecCostInput').value = elecCost;

    // Deductions
    let otherDeductions = 0;
    document.querySelectorAll('.deduction-amount').forEach(el => {
        otherDeductions += (parseFloat(el.value) || 0);
    });

    // Final Math
    // Refund = Deposit - Unpaid - Elec - Others
    const refund = DEPOSIT - UNPAID_TOTAL - elecCost - otherDeductions;
    
    const displayEl = document.getElementById('refundTotalText');
    const inputEl = document.getElementById('refundAmountInput');
    
    inputEl.value = refund;

    if (refund >= 0) {
        displayEl.innerText = '$' + refund;
        displayEl.className = 'mb-0 fw-bold text-success';
    } else {
        displayEl.innerText = '-$' + Math.abs(refund) + ' (需補繳)';
        displayEl.className = 'mb-0 fw-bold text-danger';
    }
}

function addDeduction() {
    const div = document.createElement('div');
    div.className = 'row mb-2 deduction-item';
    div.innerHTML = `
        <div class="col-6">
            <input type="text" class="form-control form-control-sm" name="deductions[${deductionCount}][desc]" placeholder="項目 (如: 牆面修繕)">
        </div>
        <div class="col-4">
            <input type="number" class="form-control form-control-sm deduction-amount" name="deductions[${deductionCount}][amount]" placeholder="金額" oninput="calcTotal()">
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove(); calcTotal()">X</button>
        </div>
    `;
    document.getElementById('deductionList').appendChild(div);
    deductionCount++;
}
</script>
