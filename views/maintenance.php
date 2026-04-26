<?php
// Handle Backup Export (ZIP)
if (isset($_GET['action']) && $_GET['action'] === 'export_backup') {
    $pdo = DB::connect();
    $zip = new ZipArchive();
    $tmpZip = tempnam(sys_get_temp_dir(), 'bak');
    
    // Header Mapping for CSVs (Human-Readable)
    $mapping = [
        'units' => ['id'=>'ID', 'name'=>'房源名稱', 'base_rent'=>'基本租金', 'description'=>'備註', 'is_active'=>'有效狀態'],
        'tenants' => ['id'=>'ID', 'name'=>'房客姓名', 'contact_info'=>'聯絡資訊', 'unit_id'=>'房源ID', 'contract_start'=>'起租日', 'contract_end'=>'租約結束', 'security_deposit'=>'押金', 'billing_cycle_day'=>'收租日', 'is_active'=>'在租狀態'],
        'ledger' => ['id'=>'ID', 'trans_date'=>'日期', 'type'=>'收支', 'category'=>'類別', 'amount'=>'金額', 'description'=>'說明', 'tenant_id'=>'房客ID', 'unit_id'=>'房源ID', 'is_paid'=>'結清'],
        'ledger_attachments' => ['id'=>'ID', 'ledger_id'=>'帳目ID', 'file_path'=>'檔案路徑', 'file_name'=>'原始檔名', 'created_at'=>'上傳時間'],
        'unit_assets' => ['id'=>'ID', 'unit_id'=>'房源ID', 'item_name'=>'設備名稱', 'purchase_date'=>'購買日期', 'warranty_date'=>'保固至', 'purchase_cost'=>'採購金額', 'status'=>'狀態'],
        'electricity_readings' => ['id'=>'ID', 'unit_id'=>'房源ID', 'record_date'=>'抄表日期', 'reading_value'=>'度數', 'diff_value'=>'差值', 'record_type'=>'類型'],
        'tenant_documents' => ['id'=>'ID', 'tenant_id'=>'房客ID', 'file_path'=>'檔案路徑', 'file_name'=>'原始檔名', 'created_at'=>'上傳時間'],
        'unit_asset_photos' => ['id'=>'ID', 'asset_id'=>'設備ID', 'file_path'=>'檔案路徑', 'created_at'=>'上傳時間'],
        'asset_maintenance' => ['id'=>'ID', 'asset_id'=>'設備ID', 'repair_date'=>'維修日期', 'repair_cost'=>'費用', 'repair_person'=>'維修人員', 'details'=>'說明']
    ];

    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        // 1. Add raw SQLite file
        $zip->addFile(sc_DB_FILE, 'rent_manager.sqlite');
        
        // 2. Add CSVs (Excel-Friendly) and JSONs for each table
        foreach ($mapping as $table => $cols) {
            $stmt = $pdo->query("SELECT * FROM $table");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                // Generate CSV with Excel trick for leading zeros
                $csv = fopen('php://temp', 'r+');
                fprintf($csv, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
                fputcsv($csv, array_values($cols), ',', '"', '\\');
                foreach ($rows as $row) {
                    $clean_row = [];
                    foreach ($cols as $orig_key => $zh_name) {
                        $val = $row[$orig_key] ?? '';
                        // Trick for Leading Zeros in Excel: ="value"
                        if (is_string($val) && strlen($val) > 0 && $val[0] === '0') {
                            $clean_row[] = '="' . $val . '"';
                        } else {
                            $clean_row[] = $val;
                        }
                    }
                    fputcsv($csv, $clean_row, ',', '"', '\\');
                }
                rewind($csv);
                $zip->addFromString("csv_for_excel/{$table}.csv", stream_get_contents($csv));
                fclose($csv);

                // Generate Clean JSON (Master Data)
                $zip->addFromString("json_data/{$table}.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        // 3. Add Uploaded Files recursively
        $uploadDir = realpath(__DIR__ . '/../uploads');
        if ($uploadDir && is_dir($uploadDir)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = 'uploads/' . substr($filePath, strlen($uploadDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="backup_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);
        exit;
    }
}

// Handle Sample CSV Downloads
if (isset($_GET['download_sample'])) {
    $type = $_GET['download_sample'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_' . $type . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    
    if ($type === 'units') {
        fputcsv($output, ['房源名稱', '基本租金', '備註'], ',', '"', '\\');
        fputcsv($output, ['101', '8500', '採光佳'], ',', '"', '\\');
    } elseif ($type === 'tenants') {
        fputcsv($output, ['房客姓名', '聯絡電話', '房源名稱', '起租日期', '退租日期', '押金', '收租日(1-28)'], ',', '"', '\\');
        // Use Excel Trick for Sample Phone
        fputcsv($output, ['張三', '="0912345678"', '101', date('Y-m-d'), date('Y-m-d', strtotime('+1 year')), '17000', '5'], ',', '"', '\\');
    }
    fclose($output);
    exit;
}

// Helper to clean Excel's formula trick values
function clean_csv_val($val) {
    $val = trim($val);
    if (preg_match('/^="?(.*)"?$/', $val, $matches)) {
        return $matches[1];
    }
    return $val;
}

// Handle Bulk Import Logic
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $pdo = DB::connect();
    
    // Bulk Import Units
    if ($_POST['action'] === 'import_units' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle); // Skip header
            $count = 0;
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO units (name, base_rent, description) VALUES (?, ?, ?)");
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) < 2) continue;
                    $stmt->execute([
                        clean_csv_val($data[0]), 
                        (int)clean_csv_val($data[1]), 
                        clean_csv_val($data[2] ?? '')
                    ]);
                    $count++;
                }
                $pdo->commit();
                $message = "<div class='alert alert-success'>成功匯入 {$count} 筆房源資料。</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "<div class='alert alert-danger'>匯入失敗: " . $e->getMessage() . "</div>";
            }
            fclose($handle);
        }
    }

    // Bulk Import Tenants
    if ($_POST['action'] === 'import_tenants' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle); // Skip header
            $count = 0;
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO tenants (name, contact_info, unit_id, contract_start, contract_end, security_deposit, billing_cycle_day, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $getUnit = $pdo->prepare("SELECT id FROM units WHERE name = ? LIMIT 1");

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) < 3) continue;
                    // Find Unit ID by name (Fuzzy/Clean)
                    $unit_name = clean_csv_val($data[2]);
                    $getUnit->execute([$unit_name]);
                    $unit = $getUnit->fetch();
                    if (!$unit) throw new Exception("找不到房源: {$unit_name}，請先匯入或建立房源。");
                    
                    $stmt->execute([
                        clean_csv_val($data[0]), // name
                        clean_csv_val($data[1]), // contact
                        $unit['id'],
                        clean_csv_val($data[3]) ?: date('Y-m-d'), // start
                        clean_csv_val($data[4]) ?: null, // end
                        (int)clean_csv_val($data[5] ?? 0), // deposit
                        (int)clean_csv_val($data[6] ?? 1)  // billing day
                    ]);
                    $count++;
                }
                $pdo->commit();
                $message = "<div class='alert alert-success'>成功匯入 {$count} 筆房客資料。</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "<div class='alert alert-danger'>匯入失敗: " . $e->getMessage() . "</div>";
            }
            fclose($handle);
        }
    }

    // --- Rescue Restore from ZIP ---
    if ($_POST['action'] === 'restore_backup' && isset($_FILES['backup_zip'])) {
        $file = $_FILES['backup_zip']['tmp_name'];
        $zip = new ZipArchive;
        if ($zip->open($file) === TRUE) {
            try {
                $restoreDir = sys_get_temp_dir() . '/rm_restore_' . time();
                @mkdir($restoreDir);
                $zip->extractTo($restoreDir);
                $zip->close();

                $newDb = $restoreDir . '/rent_manager.sqlite';
                if (!file_exists($newDb)) {
                    throw new Exception("壓縮檔內找不到 rent_manager.sqlite，這可能不是有效的備份檔。");
                }

                // 1. Close current connection to unlock file
                DB::resetConnection();

                // 2. Backup current DB just in case
                if (file_exists(sc_DB_FILE)) {
                    @copy(sc_DB_FILE, sc_DB_FILE . '.before_restore.bak');
                }

                // 3. Overwrite DB
                if (!@copy($newDb, sc_DB_FILE)) {
                    throw new Exception("資料庫檔案覆蓋失敗，請檢查權限。");
                }

                // 4. Restore Uploads folder
                $newUploads = $restoreDir . '/uploads';
                if (is_dir($newUploads)) {
                    $targetUploads = __DIR__ . '/../uploads';
                    // Simple recursive copy/override for uploads
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newUploads), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $f) {
                        if (!$f->isDir()) {
                            $fPath = $f->getRealPath();
                            $subPath = substr($fPath, strlen($newUploads) + 1);
                            $dest = $targetUploads . '/' . $subPath;
                            $destDir = dirname($dest);
                            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
                            @copy($fPath, $dest);
                        }
                    }
                }

                $message = "<div class='alert alert-success'>系統還原成功！資料庫與上傳檔案已更新。</div>";
            } catch (Exception $e) {
                $message = "<div class='alert alert-danger'>還原失敗: " . $e->getMessage() . "</div>";
            }
        } else {
            $message = "<div class='alert alert-danger'>無法開啟 ZIP 檔案。</div>";
        }
    }

    if ($_POST['action'] === 'reset_system') {
        $entered_pwd = $_POST['confirm_password'] ?? '';
        $pdo = DB::connect();
        $adminHash = DB::getSetting($pdo, 'admin_password_hash');
        $isValid = !empty($adminHash) ? password_verify($entered_pwd, $adminHash) : ($entered_pwd === sc_ADMIN_PASSWORD);
        if ($isValid) {
            try {
                if (!method_exists('DB', 'nukeAndReinit')) {
                    throw new Exception("系統核心組件未更新（找不到 DB::nukeAndReinit），請確保您已上傳最新的 <strong>includes/db.php</strong> 到伺服器。");
                }
                error_log("RM_DEBUG: Starting nukeAndReinit");
                DB::nukeAndReinit();
                error_log("RM_DEBUG: Finished nukeAndReinit");
                $message = '<div class="alert alert-success">系統已成功重置為初始狀態。</div>';
            } catch (Exception $e) {
                error_log("RM_DEBUG: Error: " . $e->getMessage());
                $message = '<div class="alert alert-danger">重置失敗: ' . $e->getMessage() . '</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">密碼錯誤！重置操作已取消。</div>';
        }
    }

    if ($_POST['action'] === 'change_admin_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_new_password'] ?? '';

        if ($new !== $confirm) {
            $message = '<div class="alert alert-danger">新密碼與確認密碼不一致。</div>';
        } elseif (strlen($new) < 6) {
            $message = '<div class="alert alert-danger">新密碼至少 6 碼。</div>';
        } else {
            $pdo = DB::connect();
            $adminHash = DB::getSetting($pdo, 'admin_password_hash');
            $isValid = !empty($adminHash) ? password_verify($current, $adminHash) : ($current === sc_ADMIN_PASSWORD);
            if (!$isValid) {
                $message = '<div class="alert alert-danger">目前密碼錯誤。</div>';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                DB::setSetting($pdo, 'admin_password_hash', $hash);
                $message = '<div class="alert alert-success">管理員密碼已更新。</div>';
            }
        }
    }

    // --- Sync/Fix Data Logic ---
    if ($_POST['action'] === 'sync_deposits') {
        try {
            $pdo->beginTransaction();
            $tenants = $pdo->query("SELECT id, name, unit_id, contract_sign_date, security_deposit FROM tenants WHERE is_active = 1 AND security_deposit > 0")->fetchAll();
            $count = 0;
            
            foreach ($tenants as $t) {
                // Check if deposit ledger exists
                $chk = $pdo->prepare("SELECT id FROM ledger WHERE tenant_id = ? AND category = '押金' AND type = 'income'");
                $chk->execute([$t['id']]);
                
                if (!$chk->fetch()) {
                    // Not found, insert it
                    $date = $t['contract_sign_date'] ?: date('Y-m-d');
                    $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, tenant_id, unit_id, is_paid, ref_type, ref_id) VALUES (?, 'income', '押金', ?, ?, ?, ?, 1, 'deposit', ?)");
                    $stmt->execute([$date, $t['security_deposit'], "補登押金 (房客: {$t['name']})", $t['id'], $t['unit_id'], $t['id']]);
                    $count++;
                }
            }
            $pdo->commit();
            $message = "<div class='alert alert-success'>校正完成！共補登了 {$count} 筆遺漏的押金紀錄。</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>校正失敗: " . $e->getMessage() . "</div>";
        }
    }
    
    // Fix Electricity Diff Values
    if ($_POST['action'] === 'fix_diff_value') {
        try {
            $pdo->beginTransaction();
            $units = $pdo->query("SELECT id, name FROM units ORDER BY id")->fetchAll();
            $updated = 0;
            $errors = [];
            
            foreach ($units as $unit) {
                // Get all readings for this unit, ordered by date and priority
                $stmt = $pdo->prepare("
                    SELECT id, record_date, reading_value, record_type
                    FROM electricity_readings 
                    WHERE unit_id = ? 
                    ORDER BY record_date ASC,
                             CASE record_type 
                                 WHEN 'closing' THEN 1 
                                 WHEN 'manual' THEN 2 
                                 WHEN 'daily' THEN 3 
                                 ELSE 4
                             END
                ");
                $stmt->execute([$unit['id']]);
                $readings = $stmt->fetchAll();
                
                $prevReading = null;
                
                foreach ($readings as $reading) {
                    $newDiffValue = 0;
                    
                    if ($prevReading) {
                        $newDiffValue = $reading['reading_value'] - $prevReading['reading_value'];
                        
                        // Check for negative values (anomaly)
                        if ($newDiffValue < -0.1) { // Allow small floating point errors
                            $errors[] = "{$unit['name']} - {$reading['record_date']}: 用量為負 (" . number_format($newDiffValue, 2) . ")";
                        }
                    }
                    
                    // Update diff_value
                    $updateStmt = $pdo->prepare("UPDATE electricity_readings SET diff_value = ? WHERE id = ?");
                    $updateStmt->execute([$newDiffValue, $reading['id']]);
                    
                    $updated++;
                    $prevReading = $reading;
                }
            }
            
            $pdo->commit();
            $msg = "成功重新計算 {$updated} 筆電表用量。";
            if (!empty($errors)) {
                $msg .= "<br><small class='text-warning'>發現 " . count($errors) . " 筆異常（用量為負），這通常是同一天有重複記錄且讀數略有差異，可忽略。</small>";
            }
            $message = "<div class='alert alert-success'>{$msg}</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>修正失敗: " . $e->getMessage() . "</div>";
        }
    }
}
?>

<div class="pt-3 pb-2 mb-3 border-bottom d-flex justify-content-between align-items-center">
    <h1 class="h2">系統維護中心</h1>
    <span class="badge bg-secondary">System Maintenance</span>
</div>

<?= $message ?>

<div class="row g-4">
    <!-- Backup Section -->
    <div class="col-md-6">
        <div class="card h-100 shadow-sm border-primary">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-cloud-download me-2"></i> 資料備份 (Export)
            </div>
            <div class="card-body">
                <p class="text-muted small">下載完整的系統備份包。壓縮檔內包含原始資料庫檔案 (.sqlite) 以及各個資料表的 CSV 檔案，方便您在 Excel 中檢視或修正。</p>
                <div class="d-grid mt-4">
                    <a href="index.php?p=maintenance&action=export_backup" class="btn btn-primary btn-lg">
                        下載全系統備份 (ZIP)
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Section -->
    <div class="col-md-6">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-success text-white">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> 批次匯入 (Import)
            </div>
                <div class="alert alert-info py-2">
                    <p class="mb-0 small"><i class="bi bi-info-circle me-1"></i> <strong>Excel 小撇步</strong>：輸入電話時，請先打一個單引號（例如：<strong>'0912...</strong>）或是使用格式 <strong>="0912..."</strong>，這樣 0 就不會被 Excel 吃掉囉！</p>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold small">1. 批次新增房源 (Units)</label>
                    <div class="d-flex gap-2 mb-2">
                        <a href="index.php?p=maintenance&download_sample=units" class="btn btn-sm btn-link p-0 text-decoration-none"><i class="bi bi-download"></i> 下載範例檔</a>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="input-group">
                        <input type="hidden" name="action" value="import_units">
                        <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
                        <button class="btn btn-sm btn-success" type="submit">匯入</button>
                    </form>
                </div>

                <div>
                    <label class="form-label fw-bold small">2. 批次新增房客 (Tenants)</label>
                    <div class="d-flex gap-2 mb-2">
                        <a href="index.php?p=maintenance&download_sample=tenants" class="btn btn-sm btn-link p-0 text-decoration-none"><i class="bi bi-download"></i> 下載範例檔</a>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="input-group">
                        <input type="hidden" name="action" value="import_tenants">
                        <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
                        <button class="btn btn-sm btn-success" type="submit">匯入</button>
                    </form>
                    <p class="mt-2 text-danger small" style="font-size: 0.7rem;">* 匯入房客前，請確認該「房源名稱」已存在系統中。</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Fix Zone -->
    <div class="col-12 mt-4">
        <div class="card border-info shadow-sm">
            <div class="card-header bg-info-subtle text-info-emphasis fw-bold">
                <i class="bi bi-wrench-adjustable-circle me-2"></i> 資料一致性校正 (Data Doctor)
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-1">補登遺漏的押金紀錄</h5>
                        <p class="text-muted small mb-0">系統更新前建立的房客可能沒有自動產生「押金收入」流水帳。此功能會掃描所有有效房客，若發現沒入帳則自動補登。</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="sync_deposits">
                        <button type="submit" class="btn btn-info text-white" onclick="return confirm('確定要執行補登嗎？')">
                            <i class="bi bi-magic"></i> 一鍵補登
                        </button>
                    </form>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">重新計算電表用量</h5>
                        <p class="text-muted small mb-0">分批匯入歷史資料後，電表用量可能計算錯誤。此功能會重新計算所有房源的用量（diff_value），確保資料正確。</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="fix_diff_value">
                        <button type="submit" class="btn btn-info text-white" onclick="return confirm('確定要重新計算所有電表用量嗎？\n\n此操作會更新所有記錄的 diff_value 欄位。')">
                            <i class="bi bi-calculator"></i> 重新計算
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Password Management -->
    <div class="col-12 mt-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info-subtle text-info fw-bold">
                <i class="bi bi-key me-2"></i> 管理員密碼
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="change_admin_password">
                    <div class="col-md-4">
                        <label class="form-label">目前密碼</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">新密碼</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">確認新密碼</label>
                        <input type="password" name="confirm_new_password" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-info text-white">
                            <i class="bi bi-check-circle"></i> 更新密碼
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Dangerous Zone -->
    <div class="col-12 mt-4">
        <div class="card border-danger shadow-sm">
            <div class="card-header bg-danger-subtle text-danger fw-bold">
                <i class="bi bi-exclamation-triangle me-2"></i> 危險區域 (Danger Zone)
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="mb-1">一鍵備份還原 (Restore from ZIP)</h5>
                        <p class="text-muted small mb-0">上傳您之前導出的備份壓縮檔 (.zip)。這將會**覆蓋**當前的資料庫與所有照片/合約檔案。</p>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                        <input type="hidden" name="action" value="restore_backup">
                        <input type="file" name="backup_zip" class="form-control form-control-sm" accept=".zip" required>
                        <button type="submit" class="btn btn-danger" onclick="return confirm('警告！此操作將覆蓋現有所有資料，請確保您上傳的是正確的備份檔。確定要繼續嗎？')">開始還原</button>
                    </form>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1 text-danger">重設系統資料庫 (Reset)</h5>
                        <p class="text-muted small mb-0">清空所有房源、租客、帳單等資料。請輸入登入密碼後執行。</p>
                    </div>
                    <form method="post" class="d-flex gap-2" onsubmit="return confirm('警告！這將清除所有資料並恢復初始狀態。您確定要繼續嗎？')">
                        <input type="hidden" name="action" value="reset_system">
                        <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="請輸入密碼" required>
                        <button type="submit" class="btn btn-outline-danger">確定重設</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
