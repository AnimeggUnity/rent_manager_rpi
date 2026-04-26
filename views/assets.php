<?php
$unit_id = (int)($_GET['unit_id'] ?? 0);
if (!$unit_id) {
    echo "<h2>錯誤: 未指定房源</h2>";
    exit;
}

$pdo = DB::connect();
$unit = $pdo->prepare("SELECT name FROM units WHERE id = ?");
$unit->execute([$unit_id]);
$unit = $unit->fetch();

if (!$unit) {
    echo "<h2>錯誤: 找不到該房源</h2>";
    exit;
}

// Self-healing database check: Ensure internal tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS unit_asset_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        asset_id INTEGER NOT NULL,
        file_path TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(asset_id) REFERENCES unit_assets(id)
    )");
} catch (Exception $e) {
    // Silently fail or log, but viewing should continue if possible
}

// Handle actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $handle_photos = function($asset_id, $item_name) use ($pdo) {
            if (!isset($_FILES['asset_photos']) || empty($_FILES['asset_photos']['name'][0])) return;
            $dir = __DIR__ . '/../uploads/assets/';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            if (!is_writable($dir)) throw new Exception("目錄不可寫入: " . $dir);

            $stmt = $pdo->prepare("INSERT INTO unit_asset_photos (asset_id, file_path) VALUES (?, ?)");
            foreach ($_FILES['asset_photos']['name'] as $i => $raw) {
                if ($_FILES['asset_photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($raw, PATHINFO_EXTENSION));
                // Practical filename: avoid complex regex for widest compatibility
                $fn = time() . "_{$i}." . $ext;
                if (move_uploaded_file($_FILES['asset_photos']['tmp_name'][$i], $dir . $fn)) {
                    $stmt->execute([$asset_id, 'uploads/assets/' . $fn]);
                } else {
                    throw new Exception("檔案移動失敗，請檢查資料夾權限。");
                }
            }
        };

        if ($_POST['action'] === 'add_asset' || $_POST['action'] === 'edit_asset') {
            try {
                $item_name = trim($_POST['item_name']);
                $p_date = $_POST['purchase_date'] ?: null;
                $w_date = $_POST['warranty_date'] ?: null;
                $cost = (int)$_POST['purchase_cost'];
                $status = $_POST['status'];
                
                if ($_POST['action'] === 'add_asset') {
                    $stmt = $pdo->prepare("INSERT INTO unit_assets (unit_id, item_name, purchase_date, warranty_date, purchase_cost, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$unit_id, $item_name, $p_date, $w_date, $cost, $status]);
                    $asset_id = $pdo->lastInsertId();
                    $handle_photos($asset_id, $item_name);
                } else {
                    $asset_id = (int)$_POST['asset_id'];
                    $stmt = $pdo->prepare("UPDATE unit_assets SET item_name=?, purchase_date=?, warranty_date=?, purchase_cost=?, status=? WHERE id=?");
                    $stmt->execute([$item_name, $p_date, $w_date, $cost, $status, $asset_id]);
                    $handle_photos($asset_id, $item_name);
                }
                echo json_encode(['status' => 'success']);
                exit;
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
        
        if ($_POST['action'] === 'delete_asset') {
            try {
                $asset_id = (int)$_POST['id'];
                $stmt = $pdo->prepare("SELECT file_path FROM unit_asset_photos WHERE asset_id = ?");
                $stmt->execute([$asset_id]);
                foreach ($stmt->fetchAll() as $p) {
                    @unlink(__DIR__ . '/../' . $p['file_path']);
                }
                $pdo->prepare("DELETE FROM unit_asset_photos WHERE asset_id = ?")->execute([$asset_id]);
                $pdo->prepare("DELETE FROM asset_maintenance WHERE asset_id = ?")->execute([$asset_id]);
                $pdo->prepare("DELETE FROM unit_assets WHERE id = ?")->execute([$asset_id]);
                $message = '<div class="alert alert-warning">設備及其相片、維修紀錄已刪除。</div>';
            } catch (Exception $e) {
                $message = '<div class="alert alert-danger">刪除失敗: ' . $e->getMessage() . '</div>';
            }
        }

        if ($_POST['action'] === 'delete_photo') {
            $photo_id = (int)$_POST['photo_id'];
            $stmt = $pdo->prepare("SELECT file_path FROM unit_asset_photos WHERE id = ?");
            $stmt->execute([$photo_id]);
            $p = $stmt->fetch();
            if ($p) {
                @unlink(__DIR__ . '/../' . $p['file_path']);
                $pdo->prepare("DELETE FROM unit_asset_photos WHERE id = ?")->execute([$photo_id]);
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error']);
            }
            exit;
        }
    }
}

// Fetch assets for this unit
$stmt = $pdo->prepare("
    SELECT a.*, 
           (SELECT SUM(repair_cost) FROM asset_maintenance WHERE asset_id = a.id) as total_repair_cost
    FROM unit_assets a 
    WHERE a.unit_id = ?
    ORDER BY a.purchase_date DESC
");
$stmt->execute([$unit_id]);
$assets = $stmt->fetchAll();

// Separately fetch photos to avoid SQLite JSON extension dependency
foreach ($assets as &$a) {
    $p_stmt = $pdo->prepare("SELECT id, file_path as path FROM unit_asset_photos WHERE asset_id = ?");
    $p_stmt->execute([$a['id']]);
    $a['photos'] = json_encode($p_stmt->fetchAll());
}
unset($a);

$total_investment = 0;
foreach($assets as $a) $total_investment += ($a['purchase_cost'] + ($a['total_repair_cost'] ?? 0));
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="index.php?p=units">房源管理</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($unit['name']) ?> 設備清單</li>
      </ol>
    </nav>
    <div>
        <span class="me-3 text-muted">累計投入成本: <strong>$<?= number_format($total_investment) ?></strong></span>
        <button type="button" class="btn btn-primary" onclick="openAssetModal()">
            <i class="bi bi-plus-lg"></i> 新增設備
        </button>
    </div>
</div>

<?= $message ?>

<div class="row">
    <?php if (empty($assets)): ?>
        <div class="col-12"><div class="alert alert-info">此房間目前沒有登錄設備內容。</div></div>
    <?php else: ?>
        <?php foreach ($assets as $a): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><?= htmlspecialchars($a['item_name']) ?></span>
                        <span class="badge <?= $a['status'] == '正常' ? 'bg-success' : 'bg-danger' ?>"><?= $a['status'] ?></span>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-2">
                            <li><small class="text-muted">購買日期:</small> <?= $a['purchase_date'] ?: '-' ?></li>
                            <li><small class="text-muted">保固至:</small> <?= $a['warranty_date'] ?: '-' ?></li>
                            <li><small class="text-muted">採購金額:</small> $<?= number_format($a['purchase_cost']) ?></li>
                            <li><small class="text-muted">維修累計:</small> <span class="text-danger">$<?= number_format($a['total_repair_cost'] ?? 0) ?></span></li>
                        </ul>
                        <?php 
                        $photos = json_decode($a['photos'], true);
                        if (!empty($photos)): ?>
                            <div class="row g-1 mt-2">
                                <?php foreach ($photos as $idx => $p): ?>
                                    <div class="col-3">
                                        <a href="<?= $p['path'] ?>" target="_blank">
                                            <img src="<?= $p['path'] ?>" class="img-fluid rounded shadow-sm" style="height:40px; width:100%; object-fit:cover;">
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-between">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary" 
                                data-id="<?= $a['id'] ?>"
                                data-name="<?= htmlspecialchars($a['item_name']) ?>"
                                data-pdate="<?= $a['purchase_date'] ?>"
                                data-wdate="<?= $a['warranty_date'] ?>"
                                data-cost="<?= $a['purchase_cost'] ?>"
                                data-status="<?= $a['status'] ?>"
                                data-photos='<?= htmlspecialchars($a['photos']) ?>'
                                onclick="openAssetModal(this)">編輯</button>
                            <a href="index.php?p=maintenance&asset_id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-info">維修紀錄</a>
                        </div>
                        <form method="post" class="d-inline" onsubmit="return confirm('確定要刪除此設備及其所有維修紀錄嗎？')">
                            <input type="hidden" name="action" value="delete_asset">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Asset Modal -->
<div class="modal fade" id="assetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assetModalTitle">新增設備</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="assetAction" value="add_asset">
                    <input type="hidden" name="asset_id" id="assetId">
                    
                    <div class="mb-3">
                        <label class="form-label">設備名稱</label>
                        <input type="text" class="form-control" name="item_name" id="itemName" placeholder="例如: 變頻冷氣" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">購買日期</label>
                            <input type="date" class="form-control" name="purchase_date" id="purchaseDate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">保固期限</label>
                            <input type="date" class="form-control" name="warranty_date" id="warrantyDate">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">購買金額</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" name="purchase_cost" id="purchaseCost" value="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">目前狀態</label>
                        <select class="form-select" name="status" id="assetStatus">
                            <option value="正常">正常</option>
                            <option value="維修中">維修中</option>
                            <option value="待換新">待換新</option>
                            <option value="報廢">報廢</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">上傳照片 (可多選)</label>
                        <input type="file" class="form-control" name="asset_photos[]" id="assetPhotos" multiple accept="image/*">
                        <div id="existingPhotos" class="mt-2 row g-2"></div>
                        
                        <!-- Progress Bar -->
                        <div class="progress mt-2 d-none" id="uploadProgressContainer" style="height: 10px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div id="uploadStatus" class="mt-1 small text-muted d-none">正在上傳...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" id="saveAssetBtn">儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var modalEl = document.getElementById('assetModal');
    var assetModal = null;

    function openAssetModal(btn = null) {
        if (!assetModal) assetModal = new bootstrap.Modal(modalEl);
        
        if (btn) {
            const d = btn.dataset;
            document.getElementById('assetModalTitle').innerText = '編輯設備: ' + d.name;
            document.getElementById('assetAction').value = 'edit_asset';
            document.getElementById('assetId').value = d.id;
            document.getElementById('itemName').value = d.name;
            document.getElementById('purchaseDate').value = d.pdate || '';
            document.getElementById('warrantyDate').value = d.wdate || '';
            document.getElementById('purchaseCost').value = d.cost;
            document.getElementById('assetStatus').value = d.status;
        } else {
            document.getElementById('assetModalTitle').innerText = '新增設備';
            document.getElementById('assetAction').value = 'add_asset';
            document.getElementById('assetId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('purchaseDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('warrantyDate').value = '';
            document.getElementById('purchaseCost').value = '0';
            document.getElementById('assetStatus').value = '正常';
            document.getElementById('existingPhotos').innerHTML = '';
            document.getElementById('assetPhotos').value = '';
            resetProgressBar();
        }
        assetModal.show();
    }

    function resetProgressBar() {
        document.getElementById('uploadProgressContainer').classList.add('d-none');
        document.getElementById('uploadStatus').classList.add('d-none');
        document.getElementById('uploadProgressBar').style.width = '0%';
    }

    // AJAX Submission with Progress Bar
    const assetForm = document.querySelector('#assetModal form');
    assetForm.onsubmit = function(e) {
        e.preventDefault();
        const btn = document.getElementById('saveAssetBtn');
        btn.disabled = true;
        
        const fd = new FormData(assetForm);
        const xhr = new XMLHttpRequest();
        
        document.getElementById('uploadProgressContainer').classList.remove('d-none');
        document.getElementById('uploadStatus').classList.remove('d-none');
        document.getElementById('uploadStatus').innerText = '正在處理照片與資料...';

        xhr.upload.onprogress = function(ev) {
            if (ev.lengthComputable) {
                const percent = Math.round((ev.loaded / ev.total) * 100);
                document.getElementById('uploadProgressBar').style.width = percent + '%';
                document.getElementById('uploadStatus').innerText = `已上傳 ${percent}% ...`;
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                window.location.reload();
            } else {
                let msg = xhr.responseText;
                try {
                    const err = JSON.parse(xhr.responseText);
                    if (err.message) msg = err.message;
                } catch(e) {}
                alert('發生錯誤: ' + xhr.status + '\n原因: ' + msg);
                btn.disabled = false;
            }
        };

        xhr.onerror = function() {
            alert('連線失敗');
            btn.disabled = false;
        };

        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); // Important for index.php routing
        xhr.send(fd);
    };

    function renderExistingPhotos(photos) {
        const container = document.getElementById('existingPhotos');
        container.innerHTML = '';
        if (!photos || photos.length === 0) return;
        
        photos.forEach(p => {
            const div = document.createElement('div');
            div.className = 'col-4 position-relative';
            div.innerHTML = `
                <img src="${p.path}" class="img-fluid rounded border" style="height:60px; width:100%; object-fit:cover;">
                <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-0" 
                        style="width:20px;height:20px;font-size:12px;" onclick="deletePhoto(this, ${p.id})">×</button>
            `;
            container.appendChild(div);
        });
    }

    function deletePhoto(btn, photoId) {
        if (!confirm('確定要刪除這張照片嗎？')) return;
        const fd = new FormData();
        fd.append('action', 'delete_photo');
        fd.append('photo_id', photoId);
        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') btn.parentElement.remove();
            });
    }

    // Override openAssetModal's inside for Edit specifically 
    const originalOpen = openAssetModal;
    openAssetModal = function(btn = null) {
        originalOpen(btn);
        if (btn) {
            const photos = JSON.parse(btn.dataset.photos || '[]');
            renderExistingPhotos(photos);
        }
    }
</script>
