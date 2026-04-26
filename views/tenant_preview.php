<?php
// views/tenant_preview.php
$pdo = DB::connect();

    $tenants = $pdo->query("
    SELECT t.*, u.name as unit_name 
    FROM tenants t 
    JOIN units u ON t.unit_id = u.id 
    WHERE t.is_active = 1 
    ORDER BY u.name ASC
")->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-eye text-info"></i> 房客視角預覽</h1>
</div>

<div class="alert alert-info shadow-sm">
    <i class="bi bi-info-circle"></i> 這裡允許您以不同房客的角色進入系統，檢查他們看到的帳單、電表與合約資料是否正確。
</div>

<div class="row g-3">
    <?php if (empty($tenants)): ?>
        <div class="col-12">
            <div class="alert alert-warning">目前沒有正在租賃中的房客。</div>
        </div>
    <?php else: ?>
        <?php foreach ($tenants as $t): ?>
            <?php $share_url = 'index.php?p=tenant_view&code=' . urlencode($t['share_code']); ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                             <h5 class="card-title fw-bold mb-0"><?= htmlspecialchars($t['name']) ?></h5>
                             <span class="badge bg-light text-info border border-info"><?= htmlspecialchars($t['unit_name']) ?></span>
                        </div>
                        <p class="card-text small text-muted mb-3">
                            合約: <?= $t['contract_start'] ?> ~ <?= $t['contract_end'] ?: '不定期' ?><br>
                            繳費日: 每月 <?= $t['billing_cycle_day'] ?> 號
                        </p>
                        <div class="small text-muted mb-2">
                            分享碼：<span class="fw-semibold text-dark"><?= htmlspecialchars($t['share_code']) ?></span>
                        </div>
                        <div class="d-grid">
                            <div class="btn-group">
                                <a href="index.php?p=tenant_view&preview_id=<?= $t['id'] ?>" class="btn btn-outline-info">
                                    <i class="bi bi-box-arrow-in-right"></i> 進入預覽
                                </a>
                                <a href="<?= $share_url ?>" class="btn btn-outline-secondary" target="_blank">
                                    <i class="bi bi-link-45deg"></i> 分享連結
                                </a>
                                <button type="button" class="btn btn-outline-dark" onclick="copyShareLink(this.previousElementSibling.href, this)">
                                    <i class="bi bi-clipboard"></i> 複製
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php foreach (array_filter($tenants, fn($t) => false) as $t): endforeach; // dummy for loop wrap ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function copyShareLink(url, btn) {
    navigator.clipboard.writeText(url).then(function() {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> 已複製';
        setTimeout(function() {
            btn.innerHTML = original;
        }, 1500);
    });
}
</script>
