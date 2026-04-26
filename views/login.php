<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['credentials'] ?? '');
    
    // 1. Check Admin Password (DB hash preferred)
    $pdo = DB::connect();
    $adminHash = DB::getSetting($pdo, 'admin_password_hash');
    if (!empty($adminHash)) {
        if (password_verify($input, $adminHash)) {
            $_SESSION[sc_SESSION_KEY] = true;
            $_SESSION['user_role'] = 'admin';
            header("Location: index.php");
            exit;
        }
    } else {
        if ($input === sc_ADMIN_PASSWORD) {
            $_SESSION[sc_SESSION_KEY] = true;
            $_SESSION['user_role'] = 'admin';
            header("Location: index.php");
            exit;
        }
    }

    // 1.5 Check Viewer Password (Read-only)
    if ($input === sc_VIEWER_PASSWORD) {
        $_SESSION[sc_SESSION_KEY] = true;
        $_SESSION['user_role'] = 'viewer';
        header("Location: index.php");
        exit;
    }
    
    // 2. Check Tenant Phone (contact_info)
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE contact_info LIKE ? AND is_active = 1 LIMIT 1");
    // Use simple like search to handle formatting variants, or exact match if preferred
    $stmt->execute(["%$input%"]);
    $tenant = $stmt->fetch();
    
    if ($tenant && !empty($input)) {
        $_SESSION[sc_SESSION_KEY] = true;
        $_SESSION['user_role'] = 'tenant';
        $_SESSION['tenant_id'] = $tenant['id'];
        header("Location: index.php?p=tenant_view");
        exit;
    }

    $error = "登入資訊錯誤 (請確認密碼或手機號碼)";
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 - <?= sc_APP_NAME ?></title>
    
    <!-- Google Fonts: Modern Professional -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary: #2563EB;
            --primary-hover: #1D4ED8;
            --background: #F8FAFC;
            --text: #1E293B;
            --text-muted: #475569;
            --border: #E2E8F0;
            --error: #EF4444;
            --shadow-lg: 0 8px 32px rgba(37, 99, 235, 0.16);
            --shadow-xl: 0 12px 48px rgba(37, 99, 235, 0.20);
        }

        body {
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #2563EB 0%, #1E40AF 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .login-container {
            max-width: 420px;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            animation: fadeInUp 0.5s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }

        .login-header i {
            font-size: 3rem;
            margin-bottom: 10px;
            opacity: 0.95;
        }

        .login-header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }

        .login-header p {
            margin: 8px 0 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control {
            border: 2px solid var(--border);
            border-radius: 10px;
            padding: 12px 16px;
            transition: all 0.2s ease;
            font-size: 1rem;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
            outline: none;
        }

        .form-floating label {
            padding: 1rem 16px;
        }

        .btn-login {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 14px 24px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1.05rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-login:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-danger {
            background: #FEF2F2;
            color: #991B1B;
            border-left: 4px solid var(--error);
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .info-box {
            background: #EFF6FF;
            border-left: 4px solid var(--primary);
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.875rem;
            color: #1E40AF;
        }

        .info-box i {
            margin-right: 8px;
        }

        /* Accessibility: Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            
            body::before {
                animation: none;
            }
            
            .btn-login:hover {
                transform: none;
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-header {
                padding: 30px 20px;
            }
            
            .login-body {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="bi bi-shield-lock"></i>
                <h1>租賃管理系統</h1>
                <p><?= sc_APP_NAME ?></p>
            </div>
            
            <div class="login-body">
                <form method="post">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?= $error ?>
                        </div>
                    <?php endif; ?>

                    <div class="info-box">
                        <i class="bi bi-info-circle-fill"></i>
                        請使用管理員密碼或房客手機號碼登入
                    </div>

                    <div class="form-floating mb-4">
                        <input 
                            type="text" 
                            class="form-control" 
                            id="floatingInput" 
                            name="credentials" 
                            placeholder="Password or Phone" 
                            required
                            autofocus
                        >
                        <label for="floatingInput">
                            <i class="bi bi-key me-2"></i>密碼或手機號碼
                        </label>
                    </div>

                    <button class="btn btn-login w-100" type="submit" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right me-2"></i>登入系統
                    </button>
                    
                    <div class="login-footer">
                        <i class="bi bi-shield-check me-1"></i>
                        &copy; <?= date('Y') ?> <?= sc_APP_NAME ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
