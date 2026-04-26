<?php
session_start();
require_once 'config.php';
require_once 'includes/db.php';

// Load Routes Configuration
$routes = require 'routes.php';

// Initialize DB on first run
DB::initSchema();

$is_pi = file_exists('/sys/firmware/devicetree/base/model');

// Authentication Check
$is_logged_in = isset($_SESSION[sc_SESSION_KEY]) && $_SESSION[sc_SESSION_KEY] === true;
$page = $_GET['p'] ?? 'dashboard';

// Handle Logout
if ($page === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Allow public tenant share links without login
if (!$is_logged_in) {
    if ($page === 'tenant_view' && isset($_GET['code']) && trim($_GET['code']) !== '') {
        $_SESSION['user_role'] = 'public';
    } else {
        include __DIR__ . "/views/login.php";
        exit;
    }
}

// ========== 统一路由权限检查 ==========
$user_role = $_SESSION['user_role'] ?? 'admin';

// 1. 验证路由是否存在
if (!isset($routes[$page])) {
    http_response_code(404);
    die("<h2>404 Not Found</h2><p>找不到頁面: " . htmlspecialchars($page) . "</p>");
}

$route = $routes[$page];

// 2. 特殊處理: logout (不加載view，直接執行)
if ($page === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 2.5. Tenant 特殊邏輯: 強制重定向到 tenant_view（必須在權限檢查之前）
if ($user_role === 'tenant' && $page !== 'tenant_view' && $page !== 'logout' && strpos($page, 'api/') === false) {
    header("Location: index.php?p=tenant_view");
    exit;
}

if ($user_role === 'public' && $page !== 'tenant_view' && $page !== 'logout' && strpos($page, 'api/') === false) {
    header("Location: index.php?p=tenant_view&code=" . urlencode($_GET['code'] ?? ''));
    exit;
}

// 3. 權限驗證（帶 preview_id 的 tenant_view 特殊處理）
$allowed_roles = $route['roles'];

// 特殊處理：Admin 訪問 tenant_view 且帶 preview_id 參數時允許（預覽模式）
$is_admin_preview = ($page === 'tenant_view' && $user_role === 'admin' && isset($_GET['preview_id']));
$is_public_share = ($page === 'tenant_view' && isset($_GET['code']) && trim($_GET['code']) !== '');
$layout_role = $is_public_share ? 'public' : $user_role;

// 權限檢查（預覽模式例外）
$has_wildcard = in_array('*', $allowed_roles);
$has_user_role = in_array($user_role, $allowed_roles);

if (!$is_admin_preview && !$is_public_share && !$has_wildcard && !$has_user_role) {
    http_response_code(403);
    // 詳細調試
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    echo '<h2>403 Forbidden</h2><p>您沒有訪問此頁面的權限</p><hr>';
    echo '<h3>🔍 調試資訊</h3><ul>';
    echo '<li><strong>頁面:</strong> ' . htmlspecialchars($page) . '</li>';
    echo '<li><strong>你的角色:</strong> ' . htmlspecialchars($user_role) . '</li>';
    echo '<li><strong>允許角色:</strong> ' . htmlspecialchars(implode(', ', $allowed_roles)) . '</li>';
    echo '<li><strong>是預覽模式:</strong> ' . ($is_admin_preview ? '是' : '否') . '</li>';
    echo '<li><strong>包含通配符:</strong> ' . ($has_wildcard ? '是' : '否') . '</li>';
    echo '<li><strong>包含你的角色:</strong> ' . ($has_user_role ? '是' : '否') . '</li>';
    echo '<li><strong>$routes類型:</strong> ' . gettype($routes) . '</li>';
    echo '<li><strong>$route內容:</strong><pre>' . htmlspecialchars(print_r($route, true)) . '</pre></li>';
    echo '</ul>';
    echo '<p><a href="index.php?p=dashboard">返回首頁</a> | <a href="index.php?p=logout">登出</a></p>';
    echo '</body></html>';
    die();
}

// 4. Viewer 角色 POST 保護
if ($user_role === 'viewer' && $_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'logout') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => '唯讀模式：您沒有修改資料權限。']);
    exit;
}

// --- Authorized Access Below ---

// 從路由配置獲取檔案路徑
$view_file = __DIR__ . '/' . $route['file'];

// Skip layout and buffering for AJAX, API, or specific raw actions
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
$is_api = strpos($page, 'api/') === 0;
$is_raw = isset($_GET['download_sample']);

if ($is_ajax || $is_api || $is_raw) {
    if (file_exists($view_file)) {
        include $view_file;
    }
    exit;
}

// Capture view output for standard page loads
$view_output = '';
if (file_exists($view_file)) {
    ob_start();
    include $view_file;
    $view_output = ob_get_clean();
} else {
    $view_output = "<h2>404 Not Found</h2><p>找不到頁面: " . htmlspecialchars($page) . "</p>";
}
?>
<!DOCTYPE html>
<html lang="zh-Hant" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= sc_APP_NAME ?></title>
    
    <!-- Google Fonts: Modern Professional (Poppins + Open Sans) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- FullCalendar CSS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/multimonth.global.min.js"></script>
    
    <!-- Modern Professional Theme -->
    <link rel="stylesheet" href="assets/css/modern-theme.css?v=<?= time() ?>">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        :root {
            --bottom-nav-height: 70px; /* Base height of bottom navigation */
        }
        
        /* Mobile Single Scrollable Navigation */
        @media (max-width: 767.98px) {
            .sidebar { display: none !important; }
            main { 
                padding-bottom: calc(var(--bottom-nav-height) + env(safe-area-inset-bottom, 0px)) !important; 
            }
            
            /* Single Bottom Navigation Bar - Scrollable */
            .bottom-nav { 
                display: flex !important; 
                position: fixed; 
                bottom: 0;
                width: 100%; 
                z-index: 1031;
                padding: 6px 0 calc(6px + env(safe-area-inset-bottom, 0px)) 0;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                -ms-overflow-style: none;
                background: #f8f9fa;
                border-top: 1px solid #dee2e6;
                box-shadow: 0 -1px 5px rgba(0,0,0,0.08);
            }
            .bottom-nav::-webkit-scrollbar {
                display: none;
            }
            .bottom-nav a { 
                text-align: center; 
                text-decoration: none;
                color: #6c757d;
                font-size: 0.65rem;
                min-width: 70px;
                padding: 4px 8px;
                white-space: nowrap;
                flex-shrink: 0;
                transition: all 0.2s;
            }
            .bottom-nav a i { 
                font-size: 1.3rem; 
                display: block;
                margin-bottom: 2px;
            }
            .bottom-nav a.active {
                color: var(--bs-primary);
                font-weight: 600;
            }
            .bottom-nav a:hover {
                color: var(--bs-primary);
            }
        }
        .bottom-nav { display: none; }



        <?php if ($layout_role === 'viewer'): ?>
        /* 🛡️ Global Read-only UI Protection */
        button[onclick*="openAddModal"], 
        button[onclick*="openEditModal"], 
        button[onclick*="openReadingModal"],
        button[onclick*="openModal"],
        a[href*="delete_id"], 
        a[href*="move_out_id"],
        a[href*="archive_id"],
        a[href*="restore_id"],
        button[type="submit"]:not(#loginBtn),
        .btn-primary:not(.nav-link):not(#todayBtn),
        .btn-danger,
        .btn-warning:not(.nav-link),
        .btn-outline-danger,
        .btn-outline-primary:has(.bi-pencil),
        .btn-outline-primary:has(.bi-person-plus) {
            display: none !important;
        }
        form:not([method="get"]) {
            pointer-events: none;
            opacity: 0.8;
        }
        <?php endif; ?>
    </style>

</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php if ($layout_role === 'admin' || $layout_role === 'viewer'): ?>
            <nav id="sidebar" class="col-md-2 d-md-block sidebar collapse p-3">
                <a href="index.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto link-body-emphasis text-decoration-none">
                    <span class="fs-4">管理系統</span>
                </a>
                <hr>
                <ul class="nav nav-pills flex-column mb-auto">
                    <?php foreach ($routes as $key => $r): ?>
                        <?php if (!empty($r['show_in_sidebar']) && in_array($layout_role, $r['roles'])): ?>
                            <li class="nav-item <?= ($key === 'tenant_preview') ? 'mt-2' : '' ?>">
                                <a href="index.php?p=<?= htmlspecialchars($key) ?>"
                                   class="nav-link <?= $page == $key ? 'active' : '' ?> <?= ($key === 'tenant_preview') ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : '' ?>">
                                    <i class="<?= htmlspecialchars($r['icon']) ?> me-2"></i>
                                    <?= htmlspecialchars($r['title']) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <hr>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center link-body-emphasis text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-2"></i>
                        <strong>管理員 <?= $layout_role === 'viewer' ? '<span class="badge bg-secondary ms-1" style="font-size:0.6rem">唯讀</span>' : '' ?></strong>
                    </a>
                    <ul class="dropdown-menu text-small shadow">
                        <li><a class="dropdown-item" href="index.php?p=logout">登出</a></li>
                    </ul>
                </div>
                <?php if ($is_pi): ?>
                <!-- 硬體資訊面板 -->
                <div class="mt-3" style="font-size:0.72rem;border-top:1px solid #dee2e6;padding-top:10px;">
                    <div class="fw-semibold text-secondary mb-2" style="font-size:0.7rem;letter-spacing:.3px;">硬體狀態</div>

                    <!-- 板子資訊 -->
                    <div class="mb-2 pb-2" style="border-bottom:1px solid #f0f0f0;">
                        <div id="si-board" class="text-dark fw-semibold" style="font-size:0.68rem;line-height:1.4;">--</div>
                        <div class="text-muted mt-1" style="font-size:0.62rem;line-height:1.6;">
                            <div>OS：<span id="si-os">--</span></div>
                            <div>核心：<span id="si-kernel">--</span></div>
                            <div>序號末8碼：<span id="si-serial">--</span></div>
                            <div>開機時間：<span id="si-uptime">--</span></div>
                        </div>
                    </div>

                    <!-- 溫度 + 頻率 -->
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="text-muted"><i class="bi bi-thermometer-half"></i> 溫度</span>
                        <span id="si-temp" class="fw-bold">--</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                        <span class="text-muted"><i class="bi bi-cpu"></i> 頻率</span>
                        <span id="si-freq" class="text-secondary">--</span>
                    </div>

                    <!-- 溫度趨勢圖 -->
                    <div style="height:110px;position:relative;margin-bottom:10px;">
                        <canvas id="temp-chart"></canvas>
                    </div>

                    <!-- CPU 負載 -->
                    <div class="mb-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">CPU 負載</span>
                            <span id="si-cpu">--%</span>
                        </div>
                        <div class="progress" style="height:5px;">
                            <div id="pb-cpu" class="progress-bar bg-info" style="width:0%;transition:width .5s"></div>
                        </div>
                    </div>

                    <!-- 記憶體 -->
                    <div class="mb-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">記憶體</span>
                            <span id="si-mem">-- / -- MB</span>
                        </div>
                        <div class="progress" style="height:5px;">
                            <div id="pb-mem" class="progress-bar bg-success" style="width:0%;transition:width .5s"></div>
                        </div>
                    </div>

                    <!-- 磁碟 -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">磁碟</span>
                            <span id="si-disk">-- / -- GB</span>
                        </div>
                        <div class="progress" style="height:5px;">
                            <div id="pb-disk" class="progress-bar bg-warning" style="width:0%;transition:width .5s"></div>
                        </div>
                    </div>

                    <!-- 頻率控制 -->
                    <div class="fw-semibold text-secondary mb-1" style="font-size:0.7rem;">CPU 模式</div>
                    <div class="d-flex gap-1 flex-wrap mb-1">
                        <button onclick="setCpuGov('powersave')"   id="gov-btn-powersave"   class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">省電</button>
                        <button onclick="setCpuGov('schedutil')"   id="gov-btn-schedutil"   class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">自動</button>
                        <button onclick="setCpuGov('ondemand')"    id="gov-btn-ondemand"    class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">按需</button>
                        <button onclick="setCpuGov('performance')" id="gov-btn-performance" class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">效能</button>
                    </div>
                    <div id="gov-status" class="text-muted" style="font-size:0.62rem;min-height:1em;"></div>
                </div>

                <script>
                let tempChart = null;

                const GOV_LABELS = { powersave:'省電 600MHz', schedutil:'自動', ondemand:'按需', performance:'效能 1.4GHz' };

                function updateGovButtons(gov) {
                    ['powersave','schedutil','ondemand','performance'].forEach(g => {
                        const btn = document.getElementById('gov-btn-' + g);
                        if (!btn) return;
                        btn.className = 'btn btn-xs border' + (g === gov ? ' btn-primary text-white' : '');
                        btn.style.cssText = 'font-size:0.62rem;padding:1px 5px;';
                    });
                }

                function setCpuGov(gov) {
                    document.getElementById('gov-status').textContent = '切換中…';
                    fetch('/api/cpu_freq.php?action=set&governor=' + gov)
                        .then(r => r.json())
                        .then(d => {
                            if (d.ok) {
                                updateGovButtons(d.governor);
                                document.getElementById('gov-status').textContent = '✓ ' + (GOV_LABELS[d.governor] || d.governor);
                            } else {
                                document.getElementById('gov-status').textContent = '失敗：' + (d.error || '未知');
                            }
                        }).catch(() => {
                            document.getElementById('gov-status').textContent = '請求失敗';
                        });
                }

                function setBar(id, pct) {
                    const el = document.getElementById(id);
                    if (el) el.style.width = Math.min(100, pct) + '%';
                }

                function fetchSysInfo() {
                    fetch('/api/sysinfo.php').then(r => r.json()).then(d => {
                        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
                        set('si-board',  d.board_model || '--');
                        set('si-os',     d.os_name     || '--');
                        set('si-kernel', d.kernel      || '--');
                        set('si-serial', d.serial      || '--');
                        set('si-uptime', d.uptime      || '--');

                        const tempEl = document.getElementById('si-temp');
                        if (tempEl) {
                            tempEl.textContent = d.temp_c + '°C';
                            tempEl.style.color = d.temp_c >= 80 ? '#e53935' : d.temp_c >= 70 ? '#fb8c00' : '#2e7d32';
                        }
                        const freqEl = document.getElementById('si-freq');
                        if (freqEl) freqEl.textContent = (d.freq_mhz || '--') + ' MHz';
                        if (d.governor) updateGovButtons(d.governor);

                        const cpuEl = document.getElementById('si-cpu');
                        if (cpuEl) cpuEl.textContent = d.cpu_pct + '%';
                        setBar('pb-cpu', d.cpu_pct);

                        const memEl = document.getElementById('si-mem');
                        if (memEl) memEl.textContent = d.mem_used_mb + ' / ' + d.mem_total_mb + ' MB';
                        setBar('pb-mem', d.mem_used_mb / d.mem_total_mb * 100);

                        const diskEl = document.getElementById('si-disk');
                        if (diskEl) diskEl.textContent = d.disk_used_gb + ' / ' + d.disk_total_gb + ' GB';
                        setBar('pb-disk', d.disk_used_gb / d.disk_total_gb * 100);
                    }).catch(() => {});
                }

                function fetchTempChart() {
                    fetch('/api/temp_history').then(r => r.json()).then(data => {
                        if (!Array.isArray(data) || !data.length) return;
                        const labels = data.map(d => d.t.slice(11, 16));
                        const values = data.map(d => d.c);
                        const canvas = document.getElementById('temp-chart');
                        if (!canvas) return;
                        if (tempChart) tempChart.destroy();
                        tempChart = new Chart(canvas.getContext('2d'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [{
                                    data: values,
                                    borderColor: values[values.length - 1] >= 70 ? '#e53935' : '#fb8c00',
                                    backgroundColor: values[values.length - 1] >= 70 ? 'rgba(229,57,53,0.08)' : 'rgba(251,140,0,0.08)',
                                    borderWidth: 1.5,
                                    pointRadius: 0,
                                    fill: true,
                                    tension: 0.3
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false }, tooltip: {
                                    callbacks: { label: ctx => ctx.parsed.y + '°C' }
                                }},
                                scales: {
                                    x: {
                                        display: true,
                                        title: { display: true, text: '時間', font: { size: 8 }, color: '#aaa' },
                                        ticks: { font: { size: 8 }, maxTicksLimit: 4, maxRotation: 0, color: '#aaa' },
                                        grid: { display: false }
                                    },
                                    y: {
                                        display: true,
                                        title: { display: true, text: '°C', font: { size: 8 }, color: '#aaa' },
                                        suggestedMin: 40,
                                        suggestedMax: 85,
                                        ticks: { font: { size: 8 }, maxTicksLimit: 4, color: '#aaa', callback: v => v + '°' },
                                        grid: { color: 'rgba(0,0,0,0.04)' }
                                    }
                                }
                            }
                        });
                    }).catch(() => {});
                }

                fetchSysInfo();
                fetchTempChart();
                setInterval(fetchSysInfo, 10000);
                setInterval(fetchTempChart, 600000);
                </script>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <!-- Single Scrollable Navigation Bar (Mobile Only) -->
            <?php if ($layout_role === 'admin' || $layout_role === 'viewer'): ?>
            <nav class="bottom-nav">
                <?php
                // 過濾出移動選單項並按優先級排序
                $mobile_menu = [];
                foreach ($routes as $key => $r) {
                    if (!empty($r['show_in_mobile']) && in_array($layout_role, $r['roles'])) {
                        $mobile_menu[$key] = $r;
                    }
                }

                // 按 mobile_priority 降序排序 (高優先級在前)
                uasort($mobile_menu, function($a, $b) {
                    return ($b['mobile_priority'] ?? 0) - ($a['mobile_priority'] ?? 0);
                });

                foreach ($mobile_menu as $key => $r):
                ?>
                    <a href="index.php?p=<?= htmlspecialchars($key) ?>"
                       class="<?= $page == $key ? 'active' : '' ?>">
                        <i class="<?= htmlspecialchars($r['icon']) ?>"></i>
                        <?= htmlspecialchars($r['title']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <!-- Main Content -->
            <main class="<?= ($layout_role === 'admin' || $layout_role === 'viewer') ? 'col-md-10 ms-sm-auto' : 'col-12' ?> px-md-4 py-3">
                
                <?= $view_output ?>

                <!-- Spacer for Mobile Bottom Nav (Physical Element) -->
                <div class="mobile-nav-spacer d-md-none"></div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
