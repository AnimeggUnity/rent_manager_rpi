<?php
// Handle Actions
$pdo = DB::connect();
$message = '';
$showFuture = isset($_GET['show_future']) && $_GET['show_future'] === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_expense') {
        $date = $_POST['trans_date'];
        $category = $_POST['category'];
        $amount = (int)$_POST['amount'];
        $desc = $_POST['description'];
        
        $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, is_paid) VALUES (?, 'expense', ?, ?, ?, 1)")
            ->execute([$date, $category, $amount, $desc]);
        $message = '<div class="alert alert-success">支出已記帳。</div>';
    }
    
    // Mark as Paid
    if (isset($_POST['action']) && $_POST['action'] === 'mark_paid') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE ledger SET is_paid = 1 WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">款項已標記為收款完成。</div>';
    }

    // Delete Record
    if (isset($_POST['action']) && $_POST['action'] === 'delete_record') {
        $id = (int)$_POST['id'];
        
        // 1. Check if it's a bill linked to an electricity reading
        $stmt = $pdo->prepare("SELECT ref_type, ref_id, category FROM ledger WHERE id = ?");
        $stmt->execute([$id]);
        $rec = $stmt->fetch();
        
        if ($rec && $rec['ref_type'] === 'bill' && $rec['category'] === '電費' && $rec['ref_id']) {
            // Revert the closing status back to daily (the default)
            $pdo->prepare("UPDATE electricity_readings SET record_type = 'daily' WHERE id = ? AND record_type = 'closing'")
                ->execute([$rec['ref_id']]);
        }

        if ($rec && $rec['ref_type'] === 'utility_bill' && $rec['ref_id']) {
            $pdo->prepare("DELETE FROM utility_bills WHERE id = ?")->execute([$rec['ref_id']]);
        }

        $pdo->prepare("DELETE FROM ledger WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-warning">紀錄已刪除。</div>';
    }

    // Batch Delete Records
    if (isset($_POST['action']) && $_POST['action'] === 'batch_delete') {
        $ids = isset($_POST['delete_ids']) && is_array($_POST['delete_ids']) ? $_POST['delete_ids'] : [];
        $deleted = 0;

        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("SELECT ref_type, ref_id, category FROM ledger WHERE id = ?");
            $stmt->execute([$id]);
            $rec = $stmt->fetch();

            if ($rec && $rec['ref_type'] === 'bill' && $rec['category'] === '電費' && $rec['ref_id']) {
                $pdo->prepare("UPDATE electricity_readings SET record_type = 'daily' WHERE id = ? AND record_type = 'closing'")
                    ->execute([$rec['ref_id']]);
            }

            if ($rec && $rec['ref_type'] === 'utility_bill' && $rec['ref_id']) {
                $pdo->prepare("DELETE FROM utility_bills WHERE id = ?")->execute([$rec['ref_id']]);
            }

            $pdo->prepare("DELETE FROM ledger WHERE id = ?")->execute([$id]);
            $deleted++;
        }

        if ($deleted > 0) {
            $message = '<div class="alert alert-warning">已刪除 ' . $deleted . ' 筆紀錄。</div>';
        } else {
            $message = '<div class="alert alert-secondary">未選擇任何紀錄。</div>';
        }
    }
}

// Fetch Transactions (Last 50)
$dateFilter = $showFuture ? "1=1" : "l.trans_date <= date('now', 'start of month', '+2 months', '-1 day')";
$trans = $pdo->query("
    SELECT l.*, t.name as tenant_name, u.name as unit_name,
           GROUP_CONCAT(la.id || '|' || la.file_path || '|' || la.file_name, ':::') as attachments,
           ub.period_start as bill_period_start,
           ub.period_end as bill_period_end,
           ub.kwh as bill_kwh,
           ub.amount as bill_amount,
           ub.bill_date as bill_date,
           ub.note as bill_note,
            (SELECT COALESCE(SUM(er.diff_value), 0)
             FROM electricity_readings er
             JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
             WHERE ub.id IS NOT NULL
               AND er.record_date BETWEEN ub.period_start AND ub.period_end) as submeter_total,
            (SELECT COUNT(DISTINCT er.unit_id)
             FROM electricity_readings er
             JOIN units u2 ON u2.id = er.unit_id AND u2.is_active = 1
             WHERE ub.id IS NOT NULL
               AND er.record_date BETWEEN ub.period_start AND ub.period_end) as reading_unit_count,
           (SELECT COUNT(*) FROM units WHERE is_active = 1) as active_unit_count
    FROM ledger l
    LEFT JOIN tenants t ON l.tenant_id = t.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN ledger_attachments la ON la.ledger_id = l.id
    LEFT JOIN utility_bills ub ON l.ref_type = 'utility_bill' AND l.ref_id = ub.id
    WHERE {$dateFilter}
    GROUP BY l.id
    ORDER BY l.trans_date DESC, l.id DESC
    LIMIT 50
")->fetchAll();

// Calculate Monthly Summary - Refactored for clarity
function getMonthlySummary($db, $month) {
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN category='房租' AND is_paid=1 THEN amount ELSE 0 END) as rent_income,
            SUM(CASE WHEN category='電費' AND type='income' AND is_paid=1 THEN amount ELSE 0 END) as elec_income,
            SUM(CASE WHEN category='電費' AND type='income' AND is_paid=0 THEN amount ELSE 0 END) as elec_unpaid,
            SUM(CASE WHEN category='電費' AND type='expense' THEN amount ELSE 0 END) as elec_expense,
            SUM(CASE WHEN type='expense' AND category != '電費' THEN amount ELSE 0 END) as other_expense
        FROM ledger 
        WHERE strftime('%Y-%m', trans_date) = ?
    ");
    $stmt->execute([$month]);
    $data = $stmt->fetch();
    
    $data['elec_balance'] = $data['elec_income'] - $data['elec_expense'];
    $data['total_net'] = $data['rent_income'] + $data['elec_balance'] - $data['other_expense'];
    return $data;
}

$summary = getMonthlySummary($pdo, date('Y-m'));
$elec_balance = $summary['elec_balance'];
$total_net = $summary['total_net'];

$monthSummaries = [];
foreach ($trans as $row) {
    $monthKey = date('Y-m', strtotime($row['trans_date']));
    if (!isset($monthSummaries[$monthKey])) {
        $monthSummaries[$monthKey] = [
            'rent_income' => 0,
            'elec_income' => 0,
            'elec_unpaid' => 0,
            'elec_expense' => 0,
            'other_expense' => 0,
            'total_net' => 0
        ];
    }

    $amount = (int)$row['amount'];
    if ($row['category'] === '房租' && $row['type'] === 'income' && (int)$row['is_paid'] === 1) {
        $monthSummaries[$monthKey]['rent_income'] += $amount;
    }
    if ($row['category'] === '電費' && $row['type'] === 'income') {
        if ((int)$row['is_paid'] === 1) {
            $monthSummaries[$monthKey]['elec_income'] += $amount;
        } else {
            $monthSummaries[$monthKey]['elec_unpaid'] += $amount;
        }
    }
    if ($row['category'] === '電費' && $row['type'] === 'expense') {
        $monthSummaries[$monthKey]['elec_expense'] += $amount;
    }
    if ($row['type'] === 'expense' && $row['category'] !== '電費') {
        $monthSummaries[$monthKey]['other_expense'] += $amount;
    }

    $monthSummaries[$monthKey]['total_net'] =
        $monthSummaries[$monthKey]['rent_income'] +
        ($monthSummaries[$monthKey]['elec_income'] - $monthSummaries[$monthKey]['elec_expense']) -
        $monthSummaries[$monthKey]['other_expense'];
}

$latestUtilityBill = $pdo->query("SELECT amount, period_start, period_end, bill_date FROM utility_bills ORDER BY COALESCE(bill_date, period_end) DESC, id DESC LIMIT 1")->fetch();

function renderMonthSubtotalRow($income, $expense, $net, $unpaidIncome, $elecIncome, $elecExpense) {
    $incomeText = number_format($income);
    $expenseText = number_format($expense);
    $netText = number_format($net);
    $unpaidText = number_format($unpaidIncome);
    $elecIncomeText = number_format($elecIncome);
    $elecExpenseText = number_format($elecExpense);

    return <<<HTML
        <tr class="table-light">
            <td colspan="8" class="small">
                <div class="bg-white border rounded px-3 py-2 d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-dark-subtle text-dark">本月小計</span>
                    <span class="badge bg-success-subtle text-success">收入 $${incomeText}</span>
                    <span class="text-muted">-</span>
                    <span class="badge bg-danger-subtle text-danger">支出 $${expenseText}</span>
                    <span class="text-muted">=</span>
                    <span class="badge bg-primary-subtle text-primary">淨額 $${netText}</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis">未收款 $${unpaidText}</span>
                    <span class="badge bg-info-subtle text-info">電費收 $${elecIncomeText}</span>
                    <span class="badge bg-secondary-subtle text-secondary">電費支 $${elecExpenseText}</span>
                </div>
            </td>
        </tr>
    HTML;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">財務流水帳</h1>
    <div class="d-flex align-items-center gap-3">
        <form method="get" class="d-flex align-items-center gap-2">
            <input type="hidden" name="p" value="ledger">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="showFuture" name="show_future" value="1" <?= $showFuture ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label" for="showFuture">顯示下個月後的資料</label>
            </div>
        </form>
        <form method="post" id="batchDeleteForm" class="d-none">
            <input type="hidden" name="action" value="batch_delete">
        </form>
        <button type="submit" form="batchDeleteForm" class="btn btn-outline-danger" id="batchDeleteBtn" disabled onclick="return confirmBatchDelete()">
            <i class="bi bi-trash"></i> 批次刪除
        </button>
        <a href="index.php?p=import_ledger" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-arrow-up"></i> 匯入歷史資料
        </a>
    </div>
</div>

<?= $message ?>

<style>
.ledger-summary {
    position: sticky;
    top: 0.75rem;
    z-index: 1020;
    background: #f8f9fa;
    padding-top: 0.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    border-radius: 0.5rem;
}
@media (max-width: 767.98px) {
    .ledger-summary {
        position: static;
        box-shadow: none;
        background: transparent;
        padding-top: 0;
    }
}
</style>

<!-- Monthly Summary Card -->
<div class="ledger-summary mb-4">
    <div class="text-muted small mb-2" id="summaryMonthLabel">月份：<?= date('Y-m') ?></div>
    <div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-header">本月實收房租</div>
            <div class="card-body">
                <h5 class="card-title" id="summaryRent">$<?= number_format($summary['rent_income']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-info mb-3">
            <div class="card-header">電費收支概況</div>
            <div class="card-body">
                <h5 class="card-title" id="summaryElec">應收 / $<?= number_format($summary['elec_income']) ?>　未收 / $<?= number_format($summary['elec_unpaid']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-danger mb-3">
            <div class="card-header">其他雜項支出</div>
            <div class="card-body">
                <h5 class="card-title" id="summaryOther">$<?= number_format($summary['other_expense']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-dark mb-3">
            <div class="card-header">本月總淨利</div>
            <div class="card-body">
                <h5 class="card-title" id="summaryNet">$<?= number_format($total_net) ?></h5>
            </div>
        </div>
    </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover shadow-sm bg-white rounded align-middle">
        <thead>
            <tr>
                <th class="text-center" style="width: 36px;">
                    <input type="checkbox" id="selectAllRows">
                </th>
                <th>日期</th>
                <th>類型</th>
                <th>類別</th>
                <th>金額</th>
                <th>對象/描述</th>
                <th>狀態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($trans)): ?>
                <tr><td colspan="8" class="text-center text-muted">目前沒有資料</td></tr>
            <?php else: ?>
                <?php
                $currentMonth = null;
                $monthIncome = 0;
                $monthExpense = 0;
                $monthUnpaidIncome = 0;
                $monthElecIncome = 0;
                $monthElecExpense = 0;
                ?>
                <?php foreach ($trans as $r): ?>
                    <?php
                    $rowMonth = date('Y-m', strtotime($r['trans_date']));
                    if ($rowMonth !== $currentMonth):
                        if ($currentMonth !== null):
                            echo renderMonthSubtotalRow(
                                $monthIncome,
                                $monthExpense,
                                $monthIncome - $monthExpense,
                                $monthUnpaidIncome,
                                $monthElecIncome,
                                $monthElecExpense
                            );
                        endif;

                        $currentMonth = $rowMonth;
                        $monthIncome = 0;
                        $monthExpense = 0;
                        $monthUnpaidIncome = 0;
                        $monthElecIncome = 0;
                        $monthElecExpense = 0;
                    ?>
                        <tr class="table-secondary month-row" data-month="<?= htmlspecialchars($currentMonth) ?>" style="cursor: pointer;">
                            <td colspan="8" class="fw-semibold text-muted">
                                <?= htmlspecialchars($currentMonth) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $amount = (int)$r['amount'];
                    if ($r['type'] === 'income') {
                        $monthIncome += $amount;
                        if ((int)$r['is_paid'] === 0) {
                            $monthUnpaidIncome += $amount;
                        }
                        if ($r['category'] === '電費') {
                            $monthElecIncome += $amount;
                        }
                    } else {
                        $monthExpense += $amount;
                        if ($r['category'] === '電費') {
                            $monthElecExpense += $amount;
                        }
                    }
                    ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="row-check" name="delete_ids[]" value="<?= $r['id'] ?>" form="batchDeleteForm">
                        </td>
                        <td><?= $r['trans_date'] ?></td>
                        <td>
                            <?php if ($r['type'] === 'income'): ?>
                                <span class="badge text-bg-success">收入</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">支出</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['category']) ?></td>
                        <td class="text-end fw-semibold <?= $r['type'] === 'income' ? 'text-success' : 'text-danger' ?>">
                            $<?= number_format($r['amount']) ?>
                        </td>
                        <td>
                            <div class="small text-muted">
                                <?php if ($r['unit_name']): ?>
                                    [<?= htmlspecialchars($r['unit_name']) ?>]
                                <?php endif; ?>
                                <?php if ($r['tenant_name']): ?>
                                    <?= htmlspecialchars($r['tenant_name']) ?>
                                <?php endif; ?>
                            </div>
                            <div><?= htmlspecialchars($r['description']) ?></div>
                            <?php if ($r['ref_type'] === 'utility_bill' && !empty($r['bill_period_start']) && !empty($r['bill_period_end'])): ?>
                                <?php
                                $billKwh = (float)$r['bill_kwh'];
                                $submeterTotal = (float)$r['submeter_total'];
                                $diffKwh = $billKwh - $submeterTotal;
                                $activeUnits = (int)$r['active_unit_count'];
                                $readingUnits = (int)$r['reading_unit_count'];
                                $hasMissingReadings = $activeUnits > 0 && $readingUnits < $activeUnits;
                                ?>
                                <div class="small text-muted mt-1 d-flex flex-wrap gap-2 align-items-center">
                                    <span class="badge bg-info-subtle text-info">帳期 <?= htmlspecialchars($r['bill_period_start']) ?> ~ <?= htmlspecialchars($r['bill_period_end']) ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary">帳單 <?= number_format($billKwh, 2) ?> 度</span>
                                    <span class="badge bg-success-subtle text-success">分錶合計 <?= number_format($submeterTotal, 2) ?> 度</span>
                                    <span class="badge bg-primary-subtle text-primary">差額 <?= number_format($diffKwh, 2) ?> 度</span>
                                    <?php if ($hasMissingReadings): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis">讀數不完整</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($r['attachments'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-paperclip"></i>
                                    <?php
                                    $files = explode(':::', $r['attachments']);
                                    foreach ($files as $file) {
                                        if (empty($file)) continue;
                                        list($id, $path, $name) = explode('|', $file);
                                        echo '<a href="' . htmlspecialchars($path) . '" target="_blank" class="text-decoration-none">' . htmlspecialchars($name) . '</a> ';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['type'] === 'income'): ?>
                                <?php if ($r['is_paid']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> 已收款</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">未收款</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">已支付</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['type'] === 'income' && !$r['is_paid']): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            onclick="return confirm('確認已收到款項？')"
                                            data-bs-toggle="tooltip"
                                            data-bs-title="收款"
                                            aria-label="收款">
                                        <i class="bi bi-cash-coin"></i>
                                        <span class="d-md-none"> 收款</span>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    onclick="openAttachmentModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['category'], ENT_QUOTES) ?>', <?= $r['amount'] ?>, '<?= htmlspecialchars($r['description'], ENT_QUOTES) ?>')"
                                    data-bs-toggle="tooltip"
                                    data-bs-title="附件"
                                    aria-label="附件">
                                <i class="bi bi-paperclip"></i>
                                <span class="d-md-none"> 附件</span>
                                <?php
                                if (!empty($r['attachments'])) {
                                    $count = count(explode(':::', $r['attachments']));
                                    echo "({$count})";
                                }
                                ?>
                            </button>

                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="delete_record">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-secondary"
                                        onclick="return confirm('確定要刪除這筆紀錄嗎？此操作無法恢復。')"
                                        data-bs-toggle="tooltip"
                                        data-bs-title="刪除"
                                        aria-label="刪除">
                                    <i class="bi bi-trash"></i>
                                    <span class="d-md-none"> 刪除</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php
                if ($currentMonth !== null) {
                    echo renderMonthSubtotalRow(
                        $monthIncome,
                        $monthExpense,
                        $monthIncome - $monthExpense,
                        $monthUnpaidIncome,
                        $monthElecIncome,
                        $monthElecExpense
                    );
                }
                ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 管理附件 Modal -->
<div class="modal fade" id="attachmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-paperclip"></i> 管理附件
                    <small class="text-muted" id="modalLedgerInfo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="currentLedgerId">

                <!-- 現有附件列表 -->
                <div class="mb-4">
                    <h6 class="border-bottom pb-2">現有附件</h6>
                    <div id="existingAttachments">
                        <div class="text-center text-muted py-3">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">載入中...</span>
                            </div>
                            載入中...
                        </div>
                    </div>
                </div>

                <!-- 新增附件 -->
                <div>
                    <h6 class="border-bottom pb-2">新增附件</h6>
                    <form id="uploadAttachmentForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <input type="file" class="form-control" name="attachments[]" id="newAttachments"
                                   accept="image/*,application/pdf" multiple>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i>
                                可上傳多個檔案，支援圖片和 PDF，單檔最大 5MB
                            </div>
                            <div id="newFileList" class="mt-2"></div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="bi bi-upload"></i> 上傳檔案
                        </button>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<script>
let attachmentModal;

document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    attachmentModal = new bootstrap.Modal(document.getElementById('attachmentModal'));

    // 監聽檔案選擇
    document.getElementById('newAttachments').addEventListener('change', function(e) {
        const fileList = document.getElementById('newFileList');
        const files = e.target.files;

        if (files.length === 0) {
            fileList.innerHTML = '';
            return;
        }

        let html = '<small class="text-muted">已選擇 ' + files.length + ' 個檔案：</small><ul class="list-unstyled mt-1">';
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            const sizeClass = file.size > 5 * 1024 * 1024 ? 'text-danger' : 'text-muted';
            const warningIcon = file.size > 5 * 1024 * 1024 ? '<i class="bi bi-exclamation-triangle text-danger"></i> ' : '';
            html += `<li><small>${warningIcon}<i class="bi bi-file-earmark"></i> ${file.name} <span class="${sizeClass}">(${sizeMB} MB)</span></small></li>`;
        }
        html += '</ul>';
        fileList.innerHTML = html;
    });

    // 上傳表單提交
    document.getElementById('uploadAttachmentForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const ledgerId = document.getElementById('currentLedgerId').value;
        const fileInput = document.getElementById('newAttachments');
        const uploadBtn = document.getElementById('uploadBtn');

        if (fileInput.files.length === 0) {
            alert('請選擇檔案');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'upload_attachment');
        formData.append('ledger_id', ledgerId);

        for (let i = 0; i < fileInput.files.length; i++) {
            formData.append('attachments[]', fileInput.files[i]);
        }

        // 禁用按鈕
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>上傳中...';

        fetch('api/manage_ledger_attachments.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                // 清空檔案選擇
                fileInput.value = '';
                document.getElementById('newFileList').innerHTML = '';
                // 重新載入附件列表
                loadAttachments(ledgerId);
                // 重新載入頁面以更新附件數量
                setTimeout(() => location.reload(), 1000);
            } else {
                alert('上傳失敗: ' + data.message);
            }
        })
        .catch(error => {
            alert('上傳失敗: ' + error);
        })
        .finally(() => {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> 上傳檔案';
        });
    });
});

function openAttachmentModal(ledgerId, category, amount, description) {
    document.getElementById('currentLedgerId').value = ledgerId;
    document.getElementById('modalLedgerInfo').textContent = `${category} $${amount.toLocaleString()} - ${description}`;

    // 清空之前的內容
    document.getElementById('existingAttachments').innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> 載入中...</div>';
    document.getElementById('newAttachments').value = '';
    document.getElementById('newFileList').innerHTML = '';

    attachmentModal.show();
    loadAttachments(ledgerId);
}

function loadAttachments(ledgerId) {
    fetch('api/manage_ledger_attachments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_attachments', ledger_id: ledgerId })
    })
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('existingAttachments');

        if (!data.success || data.attachments.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3"><i class="bi bi-inbox"></i> 尚無附件</div>';
            return;
        }

        let html = '<div class="list-group">';
        data.attachments.forEach(att => {
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-file-earmark"></i>
                        <strong>${att.file_name}</strong>
                        <br>
                        <small class="text-muted">${att.file_size_mb} MB · ${att.created_at}</small>
                    </div>
                    <div>
                        <a href="${att.file_path}" target="_blank" class="btn btn-sm btn-outline-primary me-1">
                            <i class="bi bi-eye"></i> 查看
                        </a>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteAttachment(${att.id})">
                            <i class="bi bi-trash"></i> 刪除
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    })
    .catch(error => {
        document.getElementById('existingAttachments').innerHTML = '<div class="alert alert-danger">載入失敗: ' + error + '</div>';
    });
}

function deleteAttachment(attachmentId) {
    if (!confirm('確定要刪除這個附件嗎？此操作無法恢復。')) {
        return;
    }

    fetch('api/manage_ledger_attachments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete_attachment', attachment_id: attachmentId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            const ledgerId = document.getElementById('currentLedgerId').value;
            loadAttachments(ledgerId);
            // 重新載入頁面以更新附件數量
            setTimeout(() => location.reload(), 500);
        } else {
            alert('刪除失敗: ' + data.message);
        }
    })
    .catch(error => {
        alert('刪除失敗: ' + error);
    });
}

function confirmBatchDelete() {
    const checked = document.querySelectorAll('.row-check:checked');
    const count = checked.length;
    if (count === 0) {
        return false;
    }
    return confirm(`確定要刪除選取的 ${count} 筆紀錄嗎？此操作無法恢復。`);
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAllRows');
    const batchDeleteBtn = document.getElementById('batchDeleteBtn');
    const rowChecks = document.querySelectorAll('.row-check');
    const monthRows = document.querySelectorAll('.month-row');
    const summaryMonthLabel = document.getElementById('summaryMonthLabel');
    const summaryRent = document.getElementById('summaryRent');
    const summaryElec = document.getElementById('summaryElec');
    const summaryOther = document.getElementById('summaryOther');
    const summaryNet = document.getElementById('summaryNet');

    const monthSummaries = <?= json_encode($monthSummaries, JSON_UNESCAPED_UNICODE) ?>;

    function updateBatchDeleteState() {
        if (!batchDeleteBtn) {
            return;
        }
        const anyChecked = Array.from(rowChecks).some(cb => cb.checked);
        batchDeleteBtn.disabled = !anyChecked;
    }

    function updateSummary(monthKey) {
        if (!monthSummaries[monthKey]) {
            return;
        }
        const data = monthSummaries[monthKey];
        if (summaryMonthLabel) {
            summaryMonthLabel.textContent = `月份：${monthKey}`;
        }
        if (summaryRent) {
            summaryRent.textContent = `$${data.rent_income.toLocaleString()}`;
        }
        if (summaryElec) {
            summaryElec.textContent = `應收 / $${data.elec_income.toLocaleString()}　未收 / $${data.elec_unpaid.toLocaleString()}`;
        }
        if (summaryOther) {
            summaryOther.textContent = `$${data.other_expense.toLocaleString()}`;
        }
        if (summaryNet) {
            summaryNet.textContent = `$${data.total_net.toLocaleString()}`;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const rows = document.querySelectorAll('.row-check');
            rows.forEach(cb => cb.checked = selectAll.checked);
            updateBatchDeleteState();
        });
    }

    rowChecks.forEach(cb => cb.addEventListener('change', updateBatchDeleteState));
    updateBatchDeleteState();

    monthRows.forEach(row => {
        row.addEventListener('click', function() {
            const monthKey = row.getAttribute('data-month');
            if (monthKey) {
                updateSummary(monthKey);
            }
        });
    });
});
</script>

