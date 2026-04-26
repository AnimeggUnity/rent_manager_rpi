<?php
// Handle form submission
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ADD UNIT
    if (isset($_POST['action']) && $_POST['action'] === 'add_unit') {
        $name = trim($_POST['name']);
        $base_rent = (int)$_POST['base_rent'];
        
        if ($name) {
            $stmt = DB::connect()->prepare("INSERT INTO units (name, base_rent) VALUES (?, ?)");
            $stmt->execute([$name, $base_rent]);
            $message = '<div class="alert alert-success">房源單位已新增。</div>';
        }
    }
    
    // EDIT UNIT
    if (isset($_POST['action']) && $_POST['action'] === 'edit_unit') {
        $id = (int)$_POST['unit_id'];
        $name = trim($_POST['name']);
        $base_rent = (int)$_POST['base_rent'];
        
        if ($name) {
            $stmt = DB::connect()->prepare("UPDATE units SET name = ?, base_rent = ? WHERE id = ?");
            $stmt->execute([$name, $base_rent, $id]);
            $message = '<div class="alert alert-success">房源單位已更新。</div>';
        }
    }
}

// Handle GET actions
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ARCHIVE UNIT (Soft-delete)
    if (isset($_GET['archive_id'])) {
        $id = (int)$_GET['archive_id'];
        // Check for active tenants
        $check = DB::connect()->prepare("SELECT id FROM tenants WHERE unit_id = ? AND is_active = 1");
        $check->execute([$id]);
        if ($check->fetch()) {
            $message = '<div class="alert alert-danger">無法停用：該房源目前仍有有效房客。</div>';
        } else {
            DB::connect()->prepare("UPDATE units SET is_active = 0 WHERE id = ?")->execute([$id]);
            $message = '<div class="alert alert-success">房源已停用（移動至停用分頁）。</div>';
        }
    }

    // RESTORE UNIT
    if (isset($_GET['restore_id'])) {
        $id = (int)$_GET['restore_id'];
        DB::connect()->prepare("UPDATE units SET is_active = 1 WHERE id = ?")->execute([$id]);
        $message = '<div class="alert alert-success">房源已重新啟用。</div>';
    }

    // DELETE UNIT (Permanent)
    if (isset($_GET['delete_id'])) {
        $id = (int)$_GET['delete_id'];
        $pdo = DB::connect();
        try {
            $pdo->beginTransaction();
            // Delete related data
            $pdo->prepare("DELETE FROM electricity_readings WHERE unit_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM unit_assets WHERE unit_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM ledger WHERE unit_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM units WHERE id = ?")->execute([$id]);
            $pdo->commit();
            $message = '<div class="alert alert-success">房源及所有相關紀錄已永久刪除。</div>';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">刪除失敗：' . $e->getMessage() . '</div>';
        }
    }
}

// Check if is_active column exists (Simple migration check)
try {
    DB::connect()->query("SELECT is_active FROM units LIMIT 1");
} catch (Exception $e) {
    DB::connect()->exec("ALTER TABLE units ADD COLUMN is_active BOOLEAN DEFAULT 1");
}

// Fetch all units with metadata
$units = DB::connect()->query("
    SELECT u.*, 
           (SELECT name FROM tenants WHERE unit_id = u.id AND is_active = 1 LIMIT 1) as tenant_name,
           (SELECT COUNT(*) FROM tenants WHERE unit_id = u.id AND is_active = 0) as past_tenant_count,
           (SELECT SUM(purchase_cost) FROM unit_assets WHERE unit_id = u.id) as total_asset_cost
    FROM units u
    ORDER BY u.name ASC
")->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">房源管理</h1>
    <button type="button" class="btn btn-primary" onclick="openAddUnitModal()">
        <i class="bi bi-plus-lg"></i> 新增單位
    </button>
</div>

<?= $message ?>

<ul class="nav nav-tabs mb-4" id="unitTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-units" type="button">有效房源</button>
  </li>
  <li class="nav-item">
    <button class="nav-link text-muted" id="archived-tab" data-bs-toggle="tab" data-bs-target="#archived-units" type="button">停用房源</button>
  </li>
</ul>

<div class="tab-content" id="unitTabsContent">
    <!-- Active Units -->
    <div class="tab-pane fade show active" id="active-units">
        <div class="row">
            <?php 
            $active_units = array_filter($units, fn($u) => $u['is_active']);
            if (empty($active_units)): 
            ?>
                <div class="col-12"><div class="alert alert-warning">目前沒有有效的房源單位。</div></div>
            <?php else: ?>
                <?php foreach ($active_units as $unit): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100 shadow-sm border-<?= $unit['tenant_name'] ? 'success' : 'secondary' ?>">
                            <div class="card-body">
                                <h5 class="card-title d-flex justify-content-between">
                                    <?= htmlspecialchars($unit['name']) ?>
                                    <?php if($unit['tenant_name']): ?>
                                        <span class="badge bg-success">已出租</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">空屋</span>
                                    <?php endif; ?>
                                </h5>
                                <p class="card-text mt-3">
                                    基本租金: $<?= number_format($unit['base_rent']) ?><br>
                                    房客: <?= $unit['tenant_name'] ? '<strong>'.htmlspecialchars($unit['tenant_name']).'</strong>' : '<span class="text-muted">無</span>' ?>
                                </p>
                            </div>
                            <div class="card-footer bg-transparent border-top-0 border-0">
                                <div class="btn-group w-100 mb-2">
                                     <button class="btn btn-sm btn-outline-secondary" 
                                        data-id="<?= $unit['id'] ?>"
                                        data-name="<?= htmlspecialchars($unit['name']) ?>"
                                        data-rent="<?= $unit['base_rent'] ?>"
                                        onclick="openEditUnitModal(this)">編輯</button>
                                    <a href="index.php?p=assets&unit_id=<?= $unit['id'] ?>" class="btn btn-sm btn-outline-primary">設備</a>
                                    <a href="index.php?p=meter&unit_id=<?= $unit['id'] ?>" class="btn btn-sm btn-outline-info">電表</a>
                                </div>
                                <div class="d-grid gap-2">
                                    <?php if(!$unit['tenant_name']): ?>
                                        <a href="index.php?p=tenants" class="btn btn-sm btn-primary">入住/簽約</a>
                                        <a href="index.php?p=units&archive_id=<?= $unit['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定要停用此房源嗎？（停用後將不會出現在下拉選單中）')">停用房源</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Archived Units -->
    <div class="tab-pane fade" id="archived-units">
        <div class="row">
            <?php 
            $archived_units = array_filter($units, fn($u) => !$u['is_active']);
            if (empty($archived_units)): 
            ?>
                <div class="col-12"><div class="alert alert-light text-center border p-5">目前沒有停用中的房源。</div></div>
            <?php else: ?>
                <?php foreach ($archived_units as $unit): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100 shadow-sm border-light opacity-75">
                            <div class="card-body">
                                <h5 class="card-title text-muted"><?= htmlspecialchars($unit['name']) ?> <small class="badge bg-light text-dark">停用中</small></h5>
                                <p class="card-text small text-muted">
                                    已有歷史紀錄: <?= $unit['past_tenant_count'] ?> 筆合約<br>
                                    設備總值: $<?= number_format($unit['total_asset_cost'] ?? 0) ?>
                                </p>
                            </div>
                            <div class="card-footer bg-transparent border-0 d-flex gap-2">
                                <a href="index.php?p=units&restore_id=<?= $unit['id'] ?>" class="btn btn-sm btn-outline-success flex-grow-1">重新啟用</a>
                                <a href="index.php?p=units&delete_id=<?= $unit['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('【警告】確定要「永久刪除」此房源嗎？\n這將會連同該單位的電表歷史、資產、帳單紀錄一併刪除，且無法恢復！')"><i class="bi bi-trash"></i> 永久刪除</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Unit Modal -->
<div class="modal fade" id="unitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="unitModalTitle">新增房源單位</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" id="unitFormAction" value="add_unit">
                    <input type="hidden" name="unit_id" id="unitIdField">
                    <div class="mb-3">
                        <label class="form-label">單位名稱 (例如: 101)</label>
                        <input type="text" class="form-control" name="name" id="unitNameField" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">基本租金</label>
                        <input type="number" class="form-control" name="base_rent" id="unitRentField" value="5000" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" id="unitSubmitBtn">儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var unitModalEl = document.getElementById('unitModal');
    var unitModal = null;

    document.addEventListener('DOMContentLoaded', function() {
        unitModal = new bootstrap.Modal(unitModalEl);
    });

    function openAddUnitModal() {
        if (!unitModal) unitModal = new bootstrap.Modal(unitModalEl);
        document.getElementById('unitModalTitle').innerText = '新增房源單位';
        document.getElementById('unitFormAction').value = 'add_unit';
        document.getElementById('unitSubmitBtn').innerText = '儲存';
        document.getElementById('unitIdField').value = '';
        document.getElementById('unitNameField').value = '';
        document.getElementById('unitRentField').value = '5000';
        unitModal.show();
    }

    function openEditUnitModal(btn) {
        if (!unitModal) unitModal = new bootstrap.Modal(unitModalEl);
        document.getElementById('unitModalTitle').innerText = '編輯房源單位';
        document.getElementById('unitFormAction').value = 'edit_unit';
        document.getElementById('unitSubmitBtn').innerText = '更新';
        
        const data = btn.dataset;
        document.getElementById('unitIdField').value = data.id;
        document.getElementById('unitNameField').value = data.name;
        document.getElementById('unitRentField').value = data.rent;
        
        unitModal.show();
    }
</script>
