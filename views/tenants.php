<?php
// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $pdo = DB::connect();
    
    // Helper for file upload (Supports multiple files)
    $handle_uploads = function($tenant_id, $tenant_name) use ($pdo) {
        if (!isset($_FILES['contract_files']) || empty($_FILES['contract_files']['name'][0])) return;
        
        $upload_dir = __DIR__ . '/../uploads/contracts/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $stmt = $pdo->prepare("INSERT INTO tenant_documents (tenant_id, file_path, file_name) VALUES (?, ?, ?)");
        
        foreach ($_FILES['contract_files']['name'] as $i => $raw_name) {
            if ($_FILES['contract_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            
            $ext = pathinfo($raw_name, PATHINFO_EXTENSION);
            $safe_name = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $tenant_name);
            $filename = time() . "_{$i}_" . $safe_name . '.' . $ext;
            $path = 'uploads/contracts/' . $filename;
            
            if (move_uploaded_file($_FILES['contract_files']['tmp_name'][$i], $upload_dir . $filename)) {
                $stmt->execute([$tenant_id, $path, $raw_name]);
            }
        }
    };

    // ADD TENANT
    if (isset($_POST['action']) && $_POST['action'] === 'add_tenant') {
        $name = trim($_POST['name']);
        $unit_id = (int)$_POST['unit_id'];
        $contact = $_POST['contact_info'];
        $sign_date = $_POST['contract_sign_date'];
        $start = $_POST['contract_start'];
        $end = !empty($_POST['contract_end']) ? $_POST['contract_end'] : null;
        $cycle = (int)$_POST['billing_cycle_day'];
        $deposit = (int)$_POST['security_deposit'];
        $share_code = DB::generateShareCode($pdo);

        try {
            $pdo->beginTransaction();
            
            // Check occupancy
            $check = $pdo->prepare("SELECT id FROM tenants WHERE unit_id = ? AND is_active = 1");
            $check->execute([$unit_id]);
            if ($check->fetch()) {
                throw new Exception("該房間目前已有合約中的房客。");
            }

            $stmt = $pdo->prepare("INSERT INTO tenants (name, unit_id, contact_info, contract_sign_date, contract_start, contract_end, billing_cycle_day, security_deposit, is_active, share_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$name, $unit_id, $contact, $sign_date, $start, $end, $cycle, $deposit, $share_code]);
            $tenant_id = $pdo->lastInsertId();
            
            // 📝 Accounting: Record Security Deposit
            if ($deposit > 0) {
                $check_ledger = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type, ref_id) VALUES (?, 'income', '押金', ?, ?, ?, ?, 1, 'deposit', ?)");
                $check_ledger->execute([$sign_date, $deposit, "收到押金 (房客: $name)", $tenant_id, $unit_id, $tenant_id]);
            }
            
            $handle_uploads($tenant_id, $name);
            
            $pdo->commit();
            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // UPDATE TENANT
    if (isset($_POST['action']) && $_POST['action'] === 'edit_tenant') {
        $id = (int)$_POST['tenant_id'];
        $name = trim($_POST['name']);
        $contact = $_POST['contact_info'];
        $sign_date = $_POST['contract_sign_date'];
        $start = $_POST['contract_start'];
        $end = !empty($_POST['contract_end']) ? $_POST['contract_end'] : null;
        $cycle = (int)$_POST['billing_cycle_day'];
        $deposit = (int)$_POST['security_deposit'];
        
        $stmt = $pdo->prepare("UPDATE tenants SET name=?, contact_info=?, contract_sign_date=?, contract_start=?, contract_end=?, billing_cycle_day=?, security_deposit=? WHERE id=?");
        $stmt->execute([$name, $contact, $sign_date, $start, $end, $cycle, $deposit, $id]);
        
        $handle_uploads($id, $name);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // DELETE DOCUMENT (AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_document') {
        $doc_id = (int)$_POST['doc_id'];
        $pdo = DB::connect();
        
        $doc = $pdo->prepare("SELECT file_path FROM tenant_documents WHERE id = ?");
        $doc->execute([$doc_id]);
        $file = $doc->fetch();
        
        if ($file) {
            $full_path = __DIR__ . '/../' . $file['file_path'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            $pdo->prepare("DELETE FROM tenant_documents WHERE id = ?")->execute([$doc_id]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '找不到該文件']);
        }
        exit;
    }
}

// Handle Move Out
if (isset($_GET['move_out_id'])) {
    $tid = (int)$_GET['move_out_id'];
    DB::connect()->prepare("UPDATE tenants SET is_active = 0, contract_end = DATE('now') WHERE id = ?")->execute([$tid]);
    header("Location: index.php?p=tenants");
    exit;
}

// Handle Tenant Deletion (Permanent)
if (isset($_GET['delete_id'])) {
    $tid = (int)$_GET['delete_id'];
    $pdo = DB::connect();
    
    // 1. Get and delete files
    $docs = $pdo->prepare("SELECT file_path FROM tenant_documents WHERE tenant_id = ?");
    $docs->execute([$tid]);
    foreach ($docs->fetchAll() as $doc) {
        $full_path = __DIR__ . '/../' . $doc['file_path'];
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    
    // 2. Clear DB records
    $pdo->prepare("DELETE FROM tenant_documents WHERE tenant_id = ?")->execute([$tid]);
    $pdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
    
    header("Location: index.php?p=tenants&status=success");
    exit;
}

// Fetch Tenants with Unit Name and associated documents
$tenants = DB::connect()->query("
    SELECT t.*, u.name as unit_name,
           (SELECT json_group_array(json_object('id', d.id, 'path', d.file_path, 'name', d.file_name)) 
            FROM tenant_documents d WHERE d.tenant_id = t.id) as documents
    FROM tenants t 
    JOIN units u ON t.unit_id = u.id 
    ORDER BY t.is_active DESC, t.unit_id ASC
")->fetchAll();

// Fetch Units for dropdown (Active and NOT occupied)
$units = DB::connect()->query("
    SELECT id, name 
    FROM units 
    WHERE is_active = 1 
    AND id NOT IN (SELECT unit_id FROM tenants WHERE is_active = 1)
    ORDER BY name ASC
")->fetchAll();
?>

<style>
    /* Fix for dropdown being clipped in responsive table */
    .table-responsive {
        overflow: visible !important;
    }
    @media (max-width: 767.98px) {
        .table-responsive {
            overflow-x: auto !important;
            padding-bottom: 60px; /* Space for dropdown */
        }
    }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">房客與合約</h1>
    <button type="button" class="btn btn-primary" onclick="openAddModal()">
        <i class="bi bi-person-plus"></i> 新增房客
    </button>
</div>


<?php
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $message = '<div class="alert alert-success alert-dismissible fade show">操作成功，資料已同步更新。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
?>

<?= $message ?>

<!-- Tabs for UI separation -->
<ul class="nav nav-tabs mb-4" id="tenantTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-tenants" type="button">有效合約</button>
  </li>
  <li class="nav-item">
    <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-tenants" type="button">歷史紀錄 (已退租)</button>
  </li>
</ul>

<div class="tab-content" id="tenantTabsContent">
    <!-- Active Tenants -->
    <div class="tab-pane fade show active" id="active-tenants">
        <div class="table-responsive">
            <table class="table table-striped table-hover shadow-sm bg-white rounded">
                <thead class="table-dark">
                    <tr>
                        <th>房源</th>
                        <th>房客姓名</th>
                        <th>押金</th>
                        <th>期間</th>
                        <th>繳費日</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $active_count = 0;
                    $today = date('Y-m-d');
                    foreach ($tenants as $t): 
                        if (!$t['is_active']) continue;
                        $active_count++;
                        
                        $is_expired = ($t['contract_end'] && $today > $t['contract_end']);
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['unit_name']) ?></strong></td>
                            <td><?= htmlspecialchars($t['name']) ?></td>
                            <td>$<?= number_format($t['security_deposit']) ?></td>
                            <td>
                                <small><?= $t['contract_start'] ?> ~ <?= $t['contract_end'] ?: '不定期' ?></small>
                                <?php if ($is_expired): ?>
                                    <span class="badge bg-warning text-dark ms-1">已過期 (轉不定期)</span>
                                <?php elseif (!$t['contract_end']): ?>
                                    <span class="badge bg-info text-dark ms-1">不定期</span>
                                <?php endif; ?>
                            </td>
                            <td>每月 <?= $t['billing_cycle_day'] ?> 號</td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-primary" 
                                        data-id="<?= $t['id'] ?>"
                                        data-name="<?= htmlspecialchars($t['name']) ?>"
                                        data-contact="<?= htmlspecialchars($t['contact_info']) ?>"
                                        data-sign="<?= $t['contract_sign_date'] ?>"
                                        data-start="<?= $t['contract_start'] ?>"
                                        data-end="<?= $t['contract_end'] ?>"
                                        data-cycle="<?= $t['billing_cycle_day'] ?>"
                                        data-deposit="<?= $t['security_deposit'] ?>"
                                        data-unitname="<?= htmlspecialchars($t['unit_name']) ?>"
                                        data-documents='<?= htmlspecialchars($t['documents']) ?>'
                                        onclick="openEditModal(this)">
                                        編輯
                                    </button>
                                    <?php 
                                    $docs = json_decode($t['documents'], true);
                                    if (!empty($docs)): ?>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" data-bs-toggle="dropdown">
                                                <i class="bi bi-files"></i> 文件 (<?= count($docs) ?>)
                                            </button>
                                            <ul class="dropdown-menu shadow">
                                                <?php foreach ($docs as $doc): ?>
                                                    <li><a class="dropdown-item small" href="<?= $doc['path'] ?>" target="_blank"><?= htmlspecialchars($doc['name'] ?: '檢視文件') ?></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($user_role === 'admin'): ?>
                                    <a href="index.php?p=tenant_view&preview_id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-info" title="房客視角預覽">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="index.php?p=move_out&tenant_id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger">退租/結算</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($active_count === 0): ?>
                        <tr><td colspan="6" class="text-center text-muted">目前沒有進行中的合約。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- History Tenants -->
    <div class="tab-pane fade" id="history-tenants">
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover bg-white rounded">
                <thead class="table-secondary">
                    <tr>
                        <th>房源</th>
                        <th>房客姓名</th>
                        <th>押金</th>
                        <th>租賃期間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $history_count = 0;
                    foreach ($tenants as $t): 
                        if ($t['is_active']) continue;
                        $history_count++;
                    ?>
                        <tr class="text-muted">
                            <td><?= htmlspecialchars($t['unit_name']) ?></td>
                            <td><?= htmlspecialchars($t['name']) ?></td>
                            <td>$<?= number_format($t['security_deposit']) ?></td>
                            <td><small><?= $t['contract_start'] ?> ~ <?= $t['contract_end'] ?: '不定期' ?></small></td>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-secondary" 
                                        data-id="<?= $t['id'] ?>"
                                        data-name="<?= htmlspecialchars($t['name']) ?>"
                                        data-contact="<?= htmlspecialchars($t['contact_info']) ?>"
                                        data-sign="<?= $t['contract_sign_date'] ?>"
                                        data-start="<?= $t['contract_start'] ?>"
                                        data-end="<?= $t['contract_end'] ?>"
                                        data-cycle="<?= $t['billing_cycle_day'] ?>"
                                        data-deposit="<?= $t['security_deposit'] ?>"
                                        data-unitname="<?= htmlspecialchars($t['unit_name']) ?>"
                                        data-documents='<?= htmlspecialchars($t['documents']) ?>'
                                        data-viewonly="1"
                                        onclick="openEditModal(this)">
                                        詳情
                                    </button>
                                    <?php 
                                    $docs = json_decode($t['documents'], true);
                                    if (!empty($docs)): ?>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" data-bs-toggle="dropdown">
                                                文件 (<?= count($docs) ?>)
                                            </button>
                                            <ul class="dropdown-menu shadow">
                                                <?php foreach ($docs as $doc): ?>
                                                    <li><a class="dropdown-item small" href="<?= $doc['path'] ?>" target="_blank"><?= htmlspecialchars($doc['name'] ?: '檢視文件') ?></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <a href="index.php?p=tenants&delete_id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定要「永久刪除」此房客紀錄嗎？\n這將會連同合約文件一併移除，且無法恢復！')">刪除</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($history_count === 0): ?>
                        <tr><td colspan="5" class="text-center text-muted">目前沒有歷史紀錄。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tenant Modal -->
<div class="modal fade" id="tenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">新增房客合約</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_tenant">
                    <input type="hidden" name="tenant_id" id="tenantId">
                    
                    <div class="mb-3" id="unitSelectDiv">
                        <label class="form-label">選擇房源</label>
                        <select class="form-select" name="unit_id" id="unitId" required>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3 d-none" id="unitStaticDiv">
                         <label class="form-label">房源</label>
                         <input type="text" class="form-control" id="unitStaticName" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">房客姓名</label>
                        <input type="text" class="form-control" name="name" id="tenantName" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">聯絡電話/Line</label>
                        <input type="text" class="form-control" name="contact_info" id="contactInfo">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">押金金額</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="security_deposit" id="securityDeposit" value="0" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">合約簽訂日期</label>
                        <input type="date" class="form-control" name="contract_sign_date" id="contractSign" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">合約起始日</label>
                            <input type="date" class="form-control" name="contract_start" id="contractStart" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">合約結束日 (不填為不定期)</label>
                            <input type="date" class="form-control" name="contract_end" id="contractEnd">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">繳費週期日 (每月幾號)</label>
                        <input type="number" class="form-control" name="billing_cycle_day" id="billingCycle" min="1" max="31" value="5" required>
                    </div>

                    <div class="mb-3" id="fileUploadDiv">
                        <label class="form-label">上傳文件 (可選多個 PDF/圖片)</label>
                        <input type="file" class="form-control" name="contract_files[]" id="contractFile" accept="image/*,.pdf" multiple>
                        <div id="fileList" class="mt-2 small text-muted"></div>
                        
                        <!-- Progress Bar -->
                        <div class="progress mt-2 d-none" id="uploadProgressContainer" style="height: 10px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div id="uploadStatus" class="mt-1 small text-muted d-none">正在上傳...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">建立合約</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var tenantModalEl = document.getElementById('tenantModal');
    var tenantModal = null;

    document.addEventListener('DOMContentLoaded', function() {
        tenantModal = new bootstrap.Modal(tenantModalEl);
    });

    function setModalReadOnly(readonly) {
        const form = document.querySelector('#tenantModal form');
        const elements = form.querySelectorAll('input, select, textarea');
        elements.forEach(el => {
            if (el.id !== 'unitStaticName') { // Keep unitStaticName always readonly
                el.disabled = readonly;
            }
        });
        document.getElementById('submitBtn').classList.toggle('d-none', readonly);
    }

    function openAddModal() {
        if (!tenantModal) tenantModal = new bootstrap.Modal(tenantModalEl);
        setModalReadOnly(false);

        document.getElementById('modalTitle').innerText = '新增房客合約';
        document.getElementById('formAction').value = 'add_tenant';
        document.getElementById('submitBtn').innerText = '建立合約';
        
        document.getElementById('tenantId').value = '';
        document.getElementById('tenantName').value = '';
        document.getElementById('contactInfo').value = '';
        document.getElementById('securityDeposit').value = '0';
        document.getElementById('contractSign').value = '<?= date('Y-m-d') ?>';
        document.getElementById('contractStart').value = '<?= date('Y-m-d') ?>';
        document.getElementById('contractEnd').value = '<?= date('Y-m-d', strtotime('+1 year')) ?>';
        document.getElementById('billingCycle').value = '5';
        document.getElementById('contractFile').value = '';
        document.getElementById('fileList').innerHTML = '';
        resetProgressBar();
        
        document.getElementById('unitSelectDiv').classList.remove('d-none');
        document.getElementById('unitStaticDiv').classList.add('d-none');
        document.getElementById('unitId').required = true;

        tenantModal.show();
    }

    function openEditModal(btn) {
        if (!tenantModal) tenantModal = new bootstrap.Modal(tenantModalEl);
        const data = btn.dataset;
        const isViewOnly = data.viewonly === "1";
        
        setModalReadOnly(isViewOnly);

        document.getElementById('modalTitle').innerText = isViewOnly ? '查看房客歷史資料' : '編輯房客資料';
        document.getElementById('formAction').value = 'edit_tenant';
        document.getElementById('submitBtn').innerText = '更新資料';
        
        document.getElementById('tenantId').value = data.id;
        document.getElementById('tenantName').value = data.name;
        document.getElementById('contactInfo').value = data.contact;
        document.getElementById('securityDeposit').value = data.deposit || 0;
        document.getElementById('contractSign').value = data.sign || '';
        document.getElementById('contractStart').value = data.start;
        document.getElementById('contractEnd').value = data.end;
        document.getElementById('billingCycle').value = data.cycle;
        document.getElementById('contractFile').value = '';
        resetProgressBar();
        
        // Render File List
        const fileList = document.getElementById('fileList');
        fileList.innerHTML = '';
        const docs = JSON.parse(data.documents || '[]');
        if (docs.length > 0) {
            fileList.innerHTML = '<div class="mb-1 fw-bold">現有文件：</div>';
            docs.forEach(doc => {
                let delBtn = '';
                if (!isViewOnly) {
                    delBtn = `<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="deleteDocument(this, ${doc.id})"><i class="bi bi-x-circle"></i> 刪除</button>`;
                }
                fileList.innerHTML += `<div class="mb-2 d-flex align-items-center">
                    <a href="${doc.path}" target="_blank" class="text-decoration-none"><i class="bi bi-file-earmark"></i> ${doc.name || '檢視'}</a>
                    ${delBtn}
                </div>`;
            });
        }
        
        document.getElementById('unitSelectDiv').classList.add('d-none');
        document.getElementById('unitStaticDiv').classList.remove('d-none');
        document.getElementById('unitStaticName').value = data.unitname;
        document.getElementById('unitId').required = false;

        tenantModal.show();
    }

    function resetProgressBar() {
        document.getElementById('uploadProgressContainer').classList.add('d-none');
        document.getElementById('uploadStatus').classList.add('d-none');
        document.getElementById('uploadProgressBar').style.width = '0%';
    }

    // AJAX Submission with Progress Bar
    const tenantForm = document.querySelector('#tenantModal form');
    tenantForm.onsubmit = function(e) {
        e.preventDefault();
        
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        
        const formData = new FormData(tenantForm);
        const xhr = new XMLHttpRequest();
        
        // Show progress elements
        document.getElementById('uploadProgressContainer').classList.remove('d-none');
        document.getElementById('uploadStatus').classList.remove('d-none');
        document.getElementById('uploadStatus').innerText = '正在處理並上傳文件...';

        xhr.upload.onprogress = function(event) {
            if (event.lengthComputable) {
                const percent = Math.round((event.loaded / event.total) * 100);
                document.getElementById('uploadProgressBar').style.width = percent + '%';
                document.getElementById('uploadStatus').innerText = `已上傳 ${percent}% ...`;
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                // If it was a regular page load triggered by form sub, we can just reload
                window.location.href = 'index.php?p=tenants&status=success';
            } else {
                alert('發生錯誤，請稍後再試。');
                submitBtn.disabled = false;
            }
        };

        xhr.onerror = function() {
            alert('上傳失敗，請檢查網路連線。');
            submitBtn.disabled = false;
        };

        xhr.open('POST', 'index.php?p=tenants', true);
        xhr.send(formData);
    };

    function deleteDocument(btn, docId) {
        if (!confirm('確定要刪除此文件嗎？此操作不可恢復。')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete_document');
        formData.append('doc_id', docId);
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('index.php?p=tenants', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                btn.closest('.d-flex').remove();
            } else {
                alert('刪除失敗：' + (data.message || '未知錯誤'));
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-circle"></i> 刪除';
            }
        })
        .catch(err => {
            alert('網路錯誤');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-circle"></i> 刪除';
        });
    }
</script>
