<?php
// Handle form submission
$pdo = DB::connect();
$message = '';
$defaultRepeatMonths = (int)DB::getSetting($pdo, 'recurring_expense_default_months', 1);
$defaultRepeatMonths = max(1, min(120, $defaultRepeatMonths));
require_once __DIR__ . '/../includes/recurring_expense.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $date = $_POST['trans_date'];
    $category = $_POST['category'];
    $amount = (int)$_POST['amount'];
    $desc = $_POST['description'] ?? '';

    $utilityCategory = "\u{96FB}\u{8CBB}";
    $isUtilityBill = $category === $utilityCategory;

    $billPeriodStart = $_POST['bill_period_start'] ?? '';
    $billPeriodEnd = $_POST['bill_period_end'] ?? '';
    $billKwhRaw = $_POST['bill_kwh'] ?? '';
    $billDate = $_POST['bill_date'] ?? '';
    $billNote = $_POST['bill_note'] ?? '';

    if ($isUtilityBill) {
        $missingFields = [];
        if (trim($billPeriodStart) === '') {
            $missingFields[] = 'bill_period_start';
        }
        if (trim($billPeriodEnd) === '') {
            $missingFields[] = 'bill_period_end';
        }
        if (trim($billKwhRaw) === '') {
            $missingFields[] = 'bill_kwh';
        }
        if (trim($billDate) === '') {
            $missingFields[] = 'bill_date';
        }

        if (!empty($missingFields)) {
            $message = '<div class="alert alert-danger">電費帳單欄位未填完整。</div>';
        }
    }

    $repeatEnabled = !empty($_POST['enable_repeat']);
    if ($isUtilityBill) {
        $repeatEnabled = false;
    }
    $repeatMonths = $repeatEnabled && isset($_POST['repeat_months']) ? (int)$_POST['repeat_months'] : 1;
    $repeatMonths = max(1, min(120, $repeatMonths));
    if ($repeatEnabled && !empty($_POST['save_default_repeat'])) {
        DB::setSetting($pdo, 'recurring_expense_default_months', (string)$repeatMonths);
    }

    if ($message === '') {
        $dates = buildMonthlyDates($date, $repeatMonths);
        $billKwh = $isUtilityBill ? (float)$billKwhRaw : null;

        $ledgerStmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, ref_type, ref_id, is_paid) VALUES (?, 'expense', ?, ?, ?, ?, ?, 1)");
        $utilityStmt = null;
        if ($isUtilityBill) {
            $utilityStmt = $pdo->prepare("INSERT INTO utility_bills (building_id, period_start, period_end, kwh, amount, bill_date, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
        }

        foreach ($dates as $index => $transDate) {
            $batchDesc = $desc;
            if ($repeatEnabled && $repeatMonths > 1) {
                $batchDesc = trim($desc . "（第 " . ($index + 1) . " / " . $repeatMonths . " 期）");
            }

            $refType = null;
            $refId = null;
            if ($isUtilityBill && $utilityStmt) {
                $utilityStmt->execute([null, $billPeriodStart, $billPeriodEnd, $billKwh, $amount, $billDate, $billNote]);
                $refType = 'utility_bill';
                $refId = $pdo->lastInsertId();
            }

            $ledgerStmt->execute([$transDate, $category, $amount, $batchDesc, $refType, $refId]);
            $ledger_id = $pdo->lastInsertId();

            // 處理檔案上傳（只套用到第一筆）
            if ($index === 0 && !empty($_FILES['attachments']['name'][0])) {
                $upload_dir = __DIR__ . '/../uploads/expense_attachments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_count = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                        $file_name = $_FILES['attachments']['name'][$i];
                        $file_size = $_FILES['attachments']['size'][$i];

                        // 驗證檔案大小 (5MB)
                        if ($file_size > 5 * 1024 * 1024) {
                            continue;
                        }

                        // 生成唯一檔名
                        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_name = uniqid() . '_' . time() . '.' . $ext;
                        $file_path = 'uploads/expense_attachments/' . $new_name;
                        $full_path = $upload_dir . $new_name;

                        // 移動檔案
                        if (move_uploaded_file($tmp_name, $full_path)) {
                            $pdo->prepare("INSERT INTO ledger_attachments (ledger_id, file_path, file_name) VALUES (?, ?, ?)")
                                ->execute([$ledger_id, $file_path, $file_name]);
                        }
                    }
                }
            }
        }

        // Redirect to ledger page with success message
        header('Location: index.php?p=ledger&msg=expense_added');
        exit;
    }
}

// Fetch all categories with usage count
$allCategories = $pdo->query("
    SELECT c.*, COUNT(l.id) as usage_count 
    FROM expense_categories c 
    LEFT JOIN ledger l ON l.category = c.name AND l.type = 'expense'
    GROUP BY c.id 
    ORDER BY c.display_order
")->fetchAll();

// Fetch active categories for dropdown
$activeCategories = $pdo->query("
    SELECT name FROM expense_categories 
    WHERE is_active = 1 
    ORDER BY display_order
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">新增支出記錄</h1>
</div>

<?= $message ?>

<div class="row">
    <!-- 左側：新增支出表單 -->
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-dash-circle"></i> 支出資訊</h5>
            </div>
            <div class="card-body">
                <form method="post" id="expenseForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_expense">
                    
                    <div class="mb-3">
                        <label class="form-label">日期 <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="trans_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">類別 <span class="text-danger">*</span></label>
                        <select class="form-select" name="category" id="categorySelect" required>
                            <option value="">請選擇類別</option>
                            <?php foreach ($activeCategories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['name']) ?>">
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i> 可在右側管理類別
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">金額 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="amount" 
                                   placeholder="0" min="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">說明</label>
                        <input type="text" class="form-control" name="description"
                               placeholder="例如: 101冷氣維修">
                    </div>

                    <div class="border rounded p-3 mb-3 d-none" id="billFields">
                        <div class="fw-semibold mb-2">&#x96FB;&#x8CBB;&#x5E33;&#x55AE;&#x8CC7;&#x8A0A;</div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">&#x5E33;&#x55AE;&#x671F;&#x9593;&#x8D77;</label>
                                <input type="date" class="form-control" name="bill_period_start" id="billPeriodStart" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">&#x5E33;&#x55AE;&#x671F;&#x9593;&#x8FC4;</label>
                                <input type="date" class="form-control" name="bill_period_end" id="billPeriodEnd" disabled>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">&#x7528;&#x96FB;&#x5EA6;&#x6578; (kWh)</label>
                                <input type="number" class="form-control" name="bill_kwh" id="billKwh" min="0" step="0.01" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">&#x5E33;&#x55AE;&#x65E5;&#x671F;</label>
                                <input type="date" class="form-control" name="bill_date" id="billDate" disabled>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label">&#x5099;&#x8A3B;</label>
                            <input type="text" class="form-control" name="bill_note" id="billNote" disabled>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="enableRepeat" name="enable_repeat" value="1">
                            <label class="form-check-label" for="enableRepeat">啟用重複期數</label>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i> 未勾選即為單筆新增
                        </div>
                        <label class="form-label">重複期數</label>
                        <input type="number" class="form-control" name="repeat_months"
                               value="<?= $defaultRepeatMonths ?>" min="1" max="120" id="repeatMonthsInput" disabled>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i> 勾選後才會建立多筆資料（1-120 個月）
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="saveDefaultRepeat" name="save_default_repeat" value="1" disabled>
                        <label class="form-check-label" for="saveDefaultRepeat">設為預設期數</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            附件
                            <small class="text-muted">(帳單照片、收據等)</small>
                        </label>
                        <input type="file" class="form-control" name="attachments[]" id="attachmentInput"
                               accept="image/*,application/pdf" multiple>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i>
                            可上傳多個檔案，支援圖片和 PDF，單檔最大 5MB
                        </div>
                        <div id="fileList" class="mt-2"></div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-danger btn-lg">
                            <i class="bi bi-plus-circle"></i> 新增支出
                        </button>
                        <a href="index.php?p=ledger" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> 返回流水帳
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 右側：類別管理 -->
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-tags"></i> 類別管理</h5>
                <button type="button" class="btn btn-sm btn-light" 
                        data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus"></i> 新增
                </button>
            </div>
            <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                <div class="list-group list-group-flush" id="categoryList">
                    <?php foreach ($allCategories as $cat): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center" 
                         data-category-id="<?= $cat['id'] ?>" draggable="true">
                        <div class="flex-grow-1">
                            <i class="bi bi-grip-vertical text-muted me-2" style="cursor: move;"></i>
                            <span class="category-name"><?= htmlspecialchars($cat['name']) ?></span>
                            <?php if (!$cat['is_active']): ?>
                                <span class="badge bg-secondary ms-2">已停用</span>
                            <?php endif; ?>
                            <?php if ($cat['is_system']): ?>
                                <span class="badge bg-info ms-1">系統</span>
                            <?php endif; ?>
                            <br>
                            <small class="text-muted">使用 <?= $cat['usage_count'] ?> 次</small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" 
                                    onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', <?= $cat['is_active'] ?>)"
                                    title="編輯">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" 
                                    onclick="deleteCategory(<?= $cat['id'] ?>, <?= $cat['usage_count'] ?>, <?= $cat['is_system'] ?>)"
                                    title="刪除">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info mt-3">
            <small>
                <i class="bi bi-info-circle"></i> 
                <strong>提示：</strong>拖曳類別可調整順序，已使用的類別無法刪除
            </small>
        </div>
    </div>
</div>

<!-- 新增類別 Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增類別</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">類別名稱 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newCategoryName" 
                           placeholder="輸入類別名稱" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="addCategory()">
                    <i class="bi bi-plus-circle"></i> 新增
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 編輯類別 Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">編輯類別</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editCategoryId">
                <div class="mb-3">
                    <label class="form-label">類別名稱 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editCategoryName" required>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="editCategoryActive">
                    <label class="form-check-label" for="editCategoryActive">啟用此類別</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="updateCategory()">
                    <i class="bi bi-check-circle"></i> 儲存
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Category Management JavaScript
let draggedElement = null;

// Drag and Drop functionality
document.addEventListener('DOMContentLoaded', function() {
    const categoryList = document.getElementById('categoryList');
    
    categoryList.addEventListener('dragstart', function(e) {
        if (e.target.classList.contains('list-group-item')) {
            draggedElement = e.target;
            e.target.style.opacity = '0.5';
        }
    });
    
    categoryList.addEventListener('dragend', function(e) {
        if (e.target.classList.contains('list-group-item')) {
            e.target.style.opacity = '';
        }
    });
    
    categoryList.addEventListener('dragover', function(e) {
        e.preventDefault();
        const afterElement = getDragAfterElement(categoryList, e.clientY);
        if (afterElement == null) {
            categoryList.appendChild(draggedElement);
        } else {
            categoryList.insertBefore(draggedElement, afterElement);
        }
    });
    
    categoryList.addEventListener('drop', function(e) {
        e.preventDefault();
        saveOrder();
    });
});

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.list-group-item:not(.dragging)')];
    
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function saveOrder() {
    const items = document.querySelectorAll('#categoryList .list-group-item');
    const order = Array.from(items).map(item => item.dataset.categoryId);
    
    fetch('api/manage_categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'reorder', order: order })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('排序已更新', 'success');
        }
    });
}

function addCategory() {
    const name = document.getElementById('newCategoryName').value.trim();
    
    if (!name) {
        alert('請輸入類別名稱');
        return;
    }
    
    fetch('api/manage_categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add', name: name })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            document.getElementById('newCategoryName').value = '';
            bootstrap.Modal.getInstance(document.getElementById('addCategoryModal')).hide();
            location.reload(); // Reload to update both list and dropdown
        } else {
            alert(data.message);
        }
    });
}

function editCategory(id, name, isActive) {
    document.getElementById('editCategoryId').value = id;
    document.getElementById('editCategoryName').value = name;
    document.getElementById('editCategoryActive').checked = isActive == 1;
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function updateCategory() {
    const id = document.getElementById('editCategoryId').value;
    const name = document.getElementById('editCategoryName').value.trim();
    const isActive = document.getElementById('editCategoryActive').checked ? 1 : 0;
    
    if (!name) {
        alert('請輸入類別名稱');
        return;
    }
    
    fetch('api/manage_categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'edit', id: id, name: name, is_active: isActive })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('editCategoryModal')).hide();
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

function deleteCategory(id, usageCount, isSystem) {
    let message = '確定要刪除這個類別嗎？';
    
    if (usageCount > 0) {
        message = `此類別已被使用 ${usageCount} 次，無法刪除，只能停用。\n確定要停用嗎？`;
    } else if (isSystem) {
        message = '這是系統預設類別，無法刪除，只能停用。\n確定要停用嗎？';
    }
    
    if (!confirm(message)) {
        return;
    }
    
    fetch('api/manage_categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete', id: id })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

function showToast(message, type) {
    // Simple toast notification (you can enhance this)
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);

    setTimeout(() => alertDiv.remove(), 3000);
}

// Show selected files
document.getElementById('attachmentInput').addEventListener('change', function(e) {
    const fileList = document.getElementById('fileList');
    const files = e.target.files;

    if (files.length === 0) {
        fileList.innerHTML = '';
        return;
    }

    let html = '<small class="text-muted">已選擇 ' + files.length + ' 個檔案：</small><ul class="list-unstyled mt-1">';
    let totalSize = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        totalSize += file.size;

        const sizeClass = file.size > 5 * 1024 * 1024 ? 'text-danger' : 'text-muted';
        const warningIcon = file.size > 5 * 1024 * 1024 ? '<i class="bi bi-exclamation-triangle text-danger"></i> ' : '';

        html += `<li><small>${warningIcon}<i class="bi bi-file-earmark"></i> ${file.name} <span class="${sizeClass}">(${sizeMB} MB)</span></small></li>`;
    }

    const totalSizeMB = (totalSize / 1024 / 1024).toFixed(2);
    html += '</ul><small class="text-muted">總大小: ' + totalSizeMB + ' MB</small>';

    fileList.innerHTML = html;
});

// Prevent duplicate submission and show upload progress
document.getElementById('expenseForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const fileInput = this.querySelector('input[type="file"]');

    // Check if files are selected
    const hasFiles = fileInput.files.length > 0;

    // Disable submit button
    submitBtn.disabled = true;

    // Show loading state
    const originalHTML = submitBtn.innerHTML;
    if (hasFiles) {
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>上傳中... 請勿關閉視窗';
    } else {
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>處理中...';
    }

    // If submission fails somehow, re-enable after 30 seconds
    setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHTML;
    }, 30000);
});

// Toggle repeat fields
document.addEventListener('DOMContentLoaded', function() {
    const enableRepeat = document.getElementById('enableRepeat');
    const repeatInput = document.getElementById('repeatMonthsInput');
    const saveDefault = document.getElementById('saveDefaultRepeat');

    if (!enableRepeat || !repeatInput || !saveDefault) {
        return;
    }

    function syncRepeatState() {
        const enabled = enableRepeat.checked;
        repeatInput.disabled = !enabled;
        saveDefault.disabled = !enabled;
        if (!enabled) {
            saveDefault.checked = false;
        }
    }

    enableRepeat.addEventListener('change', syncRepeatState);
    syncRepeatState();
});

// Toggle bill fields based on category
document.addEventListener('DOMContentLoaded', function() {
    const categorySelect = document.getElementById('categorySelect');
    const billFields = document.getElementById('billFields');
    const enableRepeat = document.getElementById('enableRepeat');
    const repeatInput = document.getElementById('repeatMonthsInput');
    const saveDefault = document.getElementById('saveDefaultRepeat');

    if (!categorySelect || !billFields) {
        return;
    }

    const billCategoryName = '\u96fb\u8cbb';

    function syncBillFields() {
        const isBillCategory = categorySelect.value === billCategoryName;
        billFields.classList.toggle('d-none', !isBillCategory);

        const inputs = billFields.querySelectorAll('input, textarea, select');
        inputs.forEach((input) => {
            input.disabled = !isBillCategory;
            input.required = isBillCategory && input.name !== 'bill_note';
            if (!isBillCategory) {
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            }
        });

        if (enableRepeat && repeatInput && saveDefault) {
            enableRepeat.disabled = isBillCategory;
            repeatInput.disabled = isBillCategory || !enableRepeat.checked;
            saveDefault.disabled = isBillCategory || !enableRepeat.checked;
            if (isBillCategory) {
                enableRepeat.checked = false;
                saveDefault.checked = false;
            }
        }
    }

    categorySelect.addEventListener('change', syncBillFields);
    syncBillFields();
});
</script>
