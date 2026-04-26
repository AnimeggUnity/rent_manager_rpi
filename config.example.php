<?php
// Config for Rental Management System
// 複製此檔為 config.php 並填入實際值

// Paths
define('sc_ABS_PATH', __DIR__);
define('sc_DB_DIR', sc_ABS_PATH . '/database');
define('sc_DB_FILE', sc_DB_DIR . '/rent_manager.sqlite');

// Application Settings
define('sc_APP_NAME', '管理系統');
define('sc_ELEC_RATE', 5); // 電費單價（元/kWh）

// Timezone
date_default_timezone_set('Asia/Taipei');

// Security
define('sc_ADMIN_PASSWORD', 'your_admin_password');
define('sc_VIEWER_PASSWORD', 'your_viewer_password');
define('sc_SESSION_KEY', 'rent_manager_auth');
