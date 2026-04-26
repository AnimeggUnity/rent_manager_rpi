<?php
/**
 * 路由配置文件
 * 定义所有页面、API端点及其权限规则
 */

return [
    // === 主要功能页面 ===

    'dashboard' => [
        'file' => 'views/dashboard.php',
        'title' => '總覽日曆',
        'icon' => 'bi-calendar3',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 10,
        'type' => 'view'
    ],

    'units' => [
        'file' => 'views/units.php',
        'title' => '房源管理',
        'icon' => 'bi-house-door',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 4,
        'type' => 'view'
    ],

    'tenants' => [
        'file' => 'views/tenants.php',
        'title' => '房客合約',
        'icon' => 'bi-people',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 3,
        'type' => 'view'
    ],

    'billing' => [
        'file' => 'views/billing.php',
        'title' => '帳單開立',
        'icon' => 'bi-receipt',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 7,
        'type' => 'view'
    ],

    'meter' => [
        'file' => 'views/meter.php',
        'title' => '電表紀錄',
        'icon' => 'bi-lightning-charge',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => false,
        'type' => 'view'
    ],

    'quick_meter' => [
        'file' => 'views/quick_meter.php',
        'title' => '抄表',
        'icon' => 'bi-lightning-charge-fill',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => false,
        'show_in_mobile' => true,
        'mobile_priority' => 9,
        'type' => 'view'
    ],

    'ledger' => [
        'file' => 'views/ledger.php',
        'title' => '財務流水帳',
        'icon' => 'bi-currency-dollar',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 2,
        'type' => 'view'
    ],

    'add_expense' => [
        'file' => 'views/add_expense.php',
        'title' => '新增支出',
        'icon' => 'bi-dash-circle',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 8,
        'type' => 'view'
    ],

    'maintenance' => [
        'file' => 'views/maintenance.php',
        'title' => '系統維護',
        'icon' => 'bi-shield-lock',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => true,
        'show_in_mobile' => true,
        'mobile_priority' => 1,
        'type' => 'view'
    ],

    // === Admin 专用功能 ===

    'tenant_preview' => [
        'file' => 'views/tenant_preview.php',
        'title' => '房客視角預覽',
        'icon' => 'bi-eye',
        'roles' => ['admin'],
        'show_in_sidebar' => true,
        'show_in_mobile' => false,
        'type' => 'view'
    ],

    // === Tenant 专用页面 ===

    'tenant_view' => [
        'file' => 'views/tenant_view.php',
        'title' => '我的租屋資訊',
        'icon' => 'bi-house-heart',
        'roles' => ['tenant', 'public'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'view'
    ],

    // === 特殊/隐藏页面 ===

    'assets' => [
        'file' => 'views/assets.php',
        'title' => '設備管理',
        'icon' => 'bi-box-seam',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'requires_param' => 'unit_id',
        'type' => 'view'
    ],

    'move_out' => [
        'file' => 'views/move_out.php',
        'title' => '退租結算',
        'icon' => 'bi-door-open',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'requires_param' => 'tenant_id',
        'type' => 'view'
    ],

    'import_ledger' => [
        'file' => 'views/import_ledger.php',
        'title' => '帳單匯入',
        'icon' => 'bi-upload',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'view'
    ],

    // === 认证相关 ===

    'login' => [
        'file' => 'views/login.php',
        'title' => '登入',
        'icon' => '',
        'roles' => ['*'],  // 所有人可访问
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'special'
    ],

    'logout' => [
        'file' => '',  // 不加载文件，直接执行逻辑
        'title' => '登出',
        'icon' => 'bi-box-arrow-right',
        'roles' => ['*'],
        'show_in_sidebar' => false,
        'show_in_mobile' => true,
        'mobile_priority' => 6,
        'type' => 'action'
    ],

    // === API 端点 ===

    'api/get_events' => [
        'file' => 'api/get_events.php',
        'title' => 'Get Events API',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],

    'api/get_readings' => [
        'file' => 'api/get_readings.php',
        'title' => 'Get Readings API',
        'roles' => ['admin', 'viewer'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],

    'api/save_reading_batch' => [
        'file' => 'api/save_reading_batch.php',
        'title' => 'Save Reading Batch API',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],

    'api/save_meter_settings' => [
        'file' => 'api/save_meter_settings.php',
        'title' => 'Save Meter Settings API',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],

    'api/manage_categories' => [
        'file' => 'api/manage_categories.php',
        'title' => 'Manage Categories API',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],

    'api/import_meter' => [
        'file' => 'api/import_meter.php',
        'title' => 'Import Meter API',
        'roles' => ['admin'],
        'show_in_sidebar' => false,
        'show_in_mobile' => false,
        'type' => 'api'
    ],
];
