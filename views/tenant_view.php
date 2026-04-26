<?php
$is_preview = false;
$is_public = false;
$user_role = $_SESSION['user_role'] ?? 'tenant';
$tenant_id = $_SESSION['tenant_id'] ?? null;
$public_code = trim($_GET['code'] ?? '');

// Admin Preview Logic
if ($user_role === 'admin' && isset($_GET['preview_id'])) {
    $tenant_id = (int)$_GET['preview_id'];
    $is_preview = true;
}

$pdo = DB::connect();

if (!$is_preview && $public_code !== '') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE share_code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$public_code]);
    $tenant_id = (int)$stmt->fetchColumn();
    $is_public = true;
}

if (!$tenant_id) {
    echo "<div class='alert alert-danger m-3'>分享連結無效或已失效。</div>";
    exit;
}

// 1. Fetch Tenant & Unit info
$tenant_sql = "
    SELECT t.*, u.name as unit_name, u.description as unit_desc
    FROM tenants t
    JOIN units u ON t.unit_id = u.id
    WHERE t.id = ?
";
if (!$is_preview) {
    $tenant_sql .= " AND t.is_active = 1";
}
$stmt = $pdo->prepare($tenant_sql);
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    echo "<div class='alert alert-danger m-3'>找不到房客資料或已失效。</div>";
    exit;
}

$contract_start = $tenant['contract_start'] ?: date('Y-m-01');

// 2. Fetch Electricity History
$readings = $pdo->prepare("
    SELECT * FROM electricity_readings 
    WHERE unit_id = ? AND record_date >= ?
    ORDER BY record_date DESC 
    LIMIT 10
");
$readings->execute([$tenant['unit_id'], $contract_start]);
$readings = $readings->fetchAll();

// 3. Fetch Recent Bills (Ledger)
$bills = $pdo->prepare("
    SELECT * FROM ledger 
    WHERE tenant_id = ? 
    ORDER BY trans_date DESC 
    LIMIT 10
");
$bills->execute([$tenant_id]);
$bills = $bills->fetchAll();

// 4. Get Documents
$docs_stmt = $pdo->prepare("SELECT * FROM tenant_documents WHERE tenant_id = ?");
$docs_stmt->execute([$tenant_id]);
$docs = $docs_stmt->fetchAll();

// 5. Get Unit Assets (Equipment List)
$assets_stmt = $pdo->prepare("SELECT * FROM unit_assets WHERE unit_id = ? ORDER BY item_name ASC");
$assets_stmt->execute([$tenant['unit_id']]);
$assets = $assets_stmt->fetchAll();
?>

<div class="container py-3">
    <?php if ($is_preview): ?>
        <div class="alert alert-info d-flex justify-content-between align-items-center shadow-sm mb-4">
            <div>
                <i class="bi bi-eye-fill me-2"></i> <strong>管理員預覽模式</strong>：正在以房客 <strong><?= htmlspecialchars($tenant['name']) ?></strong> 的視角檢視。
            </div>
            <a href="index.php?p=tenant_preview" class="btn btn-sm btn-info text-white">返回預覽選單</a>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12 text-center">
            <h2 class="fw-bold text-primary">👋 你好，<?= htmlspecialchars($tenant['name']) ?></h2>
            <p class="text-muted">這是您的專屬<?= $is_preview ? '預覽' : '' ?>畫面，您可以在此查看目前的租賃狀態。</p>
            <div class="badge bg-info p-2 mt-1">
                <i class="bi bi-house-heart"></i> <?= htmlspecialchars($tenant['unit_name']) ?>
            </div>
            <?php if (!$is_preview && !$is_public): ?>
                <a href="index.php?p=logout" class="btn btn-sm btn-outline-secondary ms-2">登出系統</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Electricity Status -->
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0 border-top border-4 border-warning">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-lightning-charge text-warning"></i> 電費概況</h5>
                    <?php if (!empty($readings)): 
                        $latest = $readings[0];
                        
                        // 計算本月用量：從本月 1 號開始 (若本期合約晚於本月，改用合約起始日)
                        $monthStartDate = date('Y-m-01');
                        $usageStartDate = $monthStartDate;
                        $allowFallback = true;
                        if (!empty($tenant['contract_start']) && $tenant['contract_start'] > $monthStartDate) {
                            $usageStartDate = $tenant['contract_start'];
                            $allowFallback = false;
                        }

                        $monthStartReading = $pdo->prepare("
                            SELECT reading_value FROM electricity_readings 
                            WHERE unit_id = ? 
                            AND record_date >= ?
                            ORDER BY record_date ASC, 
                                     CASE record_type 
                                         WHEN 'closing' THEN 1 
                                         WHEN 'manual' THEN 2 
                                         WHEN 'daily' THEN 3 
                                     END
                            LIMIT 1
                        ");
                        $monthStartReading->execute([$tenant['unit_id'], $usageStartDate]);
                        $monthStart = $monthStartReading->fetchColumn();
                        
                        // 如果本月沒有記錄，用上個月最後一筆
                        if (!$monthStart && $allowFallback) {
                            $prevMonthReading = $pdo->prepare("
                                SELECT reading_value FROM electricity_readings 
                                WHERE unit_id = ? 
                                AND record_date < date('now', 'start of month')
                                ORDER BY record_date DESC, 
                                         CASE record_type 
                                             WHEN 'closing' THEN 1 
                                             WHEN 'manual' THEN 2 
                                             WHEN 'daily' THEN 3 
                                         END
                                LIMIT 1
                            ");
                            $prevMonthReading->execute([$tenant['unit_id']]);
                            $monthStart = $prevMonthReading->fetchColumn();
                        }
                        
                        $monthlyUsage = $monthStart ? ($latest['reading_value'] - $monthStart) : 0;
                        
                        // 查找最近的結算點
                        $lastClosing = $pdo->prepare("
                            SELECT reading_value, record_date FROM electricity_readings 
                            WHERE unit_id = ? AND record_type = 'closing'
                            ORDER BY record_date DESC LIMIT 1
                        ");
                        $lastClosing->execute([$tenant['unit_id']]);
                        $closingData = $lastClosing->fetch();
                        $sinceClosing = $closingData ? ($latest['reading_value'] - $closingData['reading_value']) : null;
                        
                        // 預估電費（假設每度 5 元，可從設定讀取）
                        $pricePerUnit = 5; // TODO: 從系統設定讀取
                        $estimatedCost = $monthlyUsage * $pricePerUnit;
                    ?>
                        <div class="bg-light p-3 rounded text-center mb-3">
                            <div class="small text-muted">目前最新讀數</div>
                            <div class="display-5 fw-bold text-dark"><?= number_format($latest['reading_value'], 2) ?> <small class="fs-6 fw-normal">度</small></div>
                            <div class="small text-muted"><?= $latest['record_date'] ?></div>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-2 bg-success bg-opacity-10 rounded text-center">
                                    <div class="small text-muted">📊 本月用量</div>
                                    <div class="fw-bold text-success"><?= number_format($monthlyUsage, 2) ?> 度</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-primary bg-opacity-10 rounded text-center">
                                    <div class="small text-muted">💰 預估電費</div>
                                    <div class="fw-bold text-primary">$<?= number_format($estimatedCost, 0) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($closingData): ?>
                            <div class="alert alert-info small mb-3 py-2">
                                <i class="bi bi-bookmark-check"></i> 
                                上次結算：<?= $closingData['record_date'] ?>，
                                結算後用量：<strong><?= number_format($sinceClosing, 2) ?> 度</strong>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <div class="small fw-semibold text-muted mb-2">
                                <i class="bi bi-clock-history me-1"></i> 最近 5 筆記錄
                            </div>
                            <div class="list-group list-group-flush small">
                                <?php foreach (array_slice($readings, 0, 5) as $r): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center bg-transparent px-0">
                                        <div>
                                            <div class="text-muted"><?= $r['record_date'] ?></div>
                                            <div class="small text-secondary">
                                                當日用量：
                                                <?php if (isset($r['diff_value']) && $r['diff_value'] !== null): ?>
                                                    <?= number_format((float)$r['diff_value'], 2) ?> 度
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="fw-bold"><?= number_format($r['reading_value'], 2) ?> 度</span>
                                        <?php if ($r['record_type'] === 'closing'): ?>
                                            <span class="badge bg-danger-subtle text-danger">已結算</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted py-4">尚無電表資料</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Ledger / Bills -->
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0 border-top border-4 border-primary">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-receipt text-primary"></i> 帳單紀錄</h5>
                    <?php if (!empty($bills)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>日期</th>
                                        <th>項目</th>
                                        <th>金額</th>
                                        <th>狀態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bills as $b): ?>
                                        <tr>
                                            <td><small><?= $b['trans_date'] ?></small></td>
                                            <td><small><?= htmlspecialchars($b['description']) ?></small></td>
                                            <td class="fw-bold text-danger">$<?= number_format($b['amount']) ?></td>
                                            <td>
                                                <?php if ($b['is_paid']): ?>
                                                    <span class="badge bg-success-subtle text-success rounded-pill">已繳清</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger rounded-pill pulse">待繳</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted py-4">您的帳單資料尚在整理中</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Equipment List -->
        <div class="col-12">
            <div class="card shadow-sm border-0 border-top border-4 border-success">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-tools text-success"></i> 房間設備清單</h5>
                    <?php if (!empty($assets)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>設備名稱</th>
                                        <th>狀態</th>
                                        <th>購買日期</th>
                                        <th>保固期限</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assets as $asset): ?>
                                        <tr>
                                            <td><small><?= htmlspecialchars($asset['item_name']) ?></small></td>
                                            <td>
                                                <?php 
                                                switch($asset['status']) {
                                                    case '正常':
                                                        $status_class = 'bg-success-subtle text-success';
                                                        break;
                                                    case '維修中':
                                                        $status_class = 'bg-warning-subtle text-warning';
                                                        break;
                                                    case '待換新':
                                                        $status_class = 'bg-danger-subtle text-danger';
                                                        break;
                                                    case '報廢':
                                                        $status_class = 'bg-secondary-subtle text-secondary';
                                                        break;
                                                    default:
                                                        $status_class = 'bg-info-subtle text-info';
                                                }
                                                ?>
                                                <span class="badge <?= $status_class ?> rounded-pill"><?= htmlspecialchars($asset['status']) ?></span>
                                            </td>
                                            <td><small class="text-muted"><?= $asset['purchase_date'] ?: '-' ?></small></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php 
                                                    if ($asset['warranty_date']) {
                                                        $warranty = new DateTime($asset['warranty_date']);
                                                        $now = new DateTime();
                                                        if ($warranty > $now) {
                                                            echo '<span class="text-success">' . $asset['warranty_date'] . '</span>';
                                                        } else {
                                                            echo '<span class="text-danger">' . $asset['warranty_date'] . ' (已過期)</span>';
                                                        }
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted py-4">此房間目前沒有登錄設備資訊</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contract & Info -->
        <div class="col-12">
            <div class="card shadow-sm border-0 border-top border-4 border-info">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="card-title fw-bold mb-1"><i class="bi bi-file-earmark-text text-info"></i> 合約資訊</h5>
                            <p class="text-muted small mb-3">您的居住權益與合約期限。</p>
                            <div class="row g-2">
                                <div class="col-auto">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="text-muted small">合約起迄</div>
                                        <div class="fw-bold"><?= $tenant['contract_start'] ?> ~ <?= $tenant['contract_end'] ?: '不定期' ?></div>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="text-muted small">繳費日</div>
                                        <div class="fw-bold">每月 <?= $tenant['billing_cycle_day'] ?> 號</div>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="text-muted small">押金</div>
                                        <div class="fw-bold">$<?= number_format($tenant['security_deposit']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mt-3 mt-md-0">
                            <h6 class="fw-bold small mb-2 text-muted">我的附件 (合約/證件)</h6>
                            <?php if (!empty($docs)): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($docs as $doc): ?>
                                        <a href="<?= $doc['file_path'] ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-file-earmark"></i> <?= htmlspecialchars($doc['file_name'] ?: '查看附件') ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">無附加文件</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .tenant-card {
        transition: transform 0.2s;
    }
    .tenant-card:active {
        transform: scale(0.98);
    }
    .pulse {
        animation: pulse-red 2s infinite;
    }
    @keyframes pulse-red {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }
    
    /* Mobile Adjustments */
    @media (max-width: 576px) {
        .display-5 { font-size: 2.2rem; }
        .card-title { font-size: 1.1rem; }
        .container { padding-left: 10px; padding-right: 10px; }
        .table-responsive { border: 0; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.85rem; }
    }
</style>
