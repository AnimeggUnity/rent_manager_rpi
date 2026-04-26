<?php
// Linus would say: Don't rely on the server to serve static files correctly. Serve it yourself.
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=import_sample.csv');
    echo "\xEF\xBB\xBF"; // BOM for Excel
    echo "日期,類型,類別,金額,房源名稱,描述\n";
    echo "2023-01-05,收入,房租,10000,101室,101房1月租金\n";
    echo "2023-01-05,收入,電費,550,101室,101房1月電表結算\n";
    echo "2023-02-10,支出,維修費,1200,101室,101房房內燈具更換\n";
    echo "2023-03-01,支出,電費,3200,,繳給台電(1-2月總電費)\n";
    exit;
}

$message = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (!is_uploaded_file($file)) {
        $message = '<div class="alert alert-danger">請選擇有效的 CSV 檔案。</div>';
    } else {
        $handle = fopen($file, 'r');
        
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom != "\xEF\xBB\xBF") fseek($handle, 0);

        // Pre-fetch units for mapping names to IDs
        $unit_map = [];
        $units = $pdo->query("SELECT id, name FROM units")->fetchAll();
        foreach ($units as $u) {
            $normalized_name = preg_replace('/[室房號]$/u', '', trim($u['name']));
            $unit_map[trim($u['name'])] = $u['id'];
            $unit_map[$normalized_name] = $u['id']; // Also Map "101" to the ID of "101室"
        }

        $imported = 0;
        $row_count = 0;
        $skipped = [];

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO ledger (trans_date, type, category, amount, description, unit_id, is_paid, ref_type) VALUES (?, ?, ?, ?, ?, ?, 1, 'legacy_import')");
            
            while (($data = fgetcsv($handle)) !== false) {
                $row_count++;
                if ($row_count === 1 && trim($data[0]) === '日期') continue; // Skip header

                if (count($data) < 4) continue; 

                $date = trim($data[0]);
                $raw_type = trim($data[1]);
                $category = trim($data[2]);
                $amount = (int)trim($data[3]);
                $unit_name = isset($data[4]) ? trim($data[4]) : '';
                $desc = isset($data[5]) ? trim($data[5]) : '';

                // Mapping types
                $type = '';
                if (in_array($raw_type, ['income', '收入'])) $type = 'income';
                if (in_array($raw_type, ['expense', '支出'])) $type = 'expense';

                // Basic logic validation
                if (!strtotime($date)) continue;
                if (!$type) {
                    $skipped[] = "第 {$row_count} 行: 無效的類型 '{$raw_type}' (應為 收入/支出)";
                    continue;
                }

                $unit_id = null;
                if ($unit_name && isset($unit_map[$unit_name])) {
                    $unit_id = $unit_map[$unit_name];
                } elseif ($unit_name) {
                    $skipped[] = "第 {$row_count} 行: 找不到房源 '{$unit_name}'";
                    continue;
                }

                $stmt->execute([$date, $type, $category, $amount, $desc, $unit_id]);
                $imported++;
            }
            
            $pdo->commit();
            $msg_text = "成功匯入 {$imported} 筆歷史資料。";
            if (!empty($skipped)) {
                $msg_text .= "<br>已跳過以下錯誤：<br>" . implode("<br>", $skipped);
            }
            $message = "<div class='alert alert-success'>$msg_text</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>匯入失敗: " . $e->getMessage() . "</div>";
        }
        fclose($handle);
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">匯入歷史流水帳</h1>
    <a href="index.php?p=ledger" class="btn btn-outline-secondary">返回流水帳</a>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-light">上傳 CSV 檔案</div>
            <div class="card-body">
                <?= $message ?>
                <form method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">選擇檔案</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">開始匯入</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card shadow-sm border-warning">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <span class="fw-bold">CSV 格式說明</span>
                <a href="index.php?p=import_ledger&download_sample=1" class="btn btn-sm btn-dark text-white">
                    <i class="bi bi-download"></i> 下載範例檔
                </a>
            </div>
            <div class="card-body">
                <p>請確保您的 CSV 檔案符合以下欄位順序，不要包含多餘的空白：</p>
                <table class="table table-sm table-bordered">
                    <thead class="bg-light">
                        <tr>
                            <th>欄位</th>
                            <th>說明</th>
                            <th>範例</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>日期</td><td>YYYY-MM-DD</td><td>2023-01-15</td></tr>
                        <tr><td>類型</td><td>收入 / 支出</td><td>收入</td></tr>
                        <tr><td>類別</td><td>房租 / 電費 / 其他</td><td>房租</td></tr>
                        <tr><td>金額</td><td>純數字</td><td>12000</td></tr>
                        <tr><td>房源名稱</td><td>(選填) 支援模糊比對 (101 可匹配 101室)</td><td>101</td></tr>
                        <tr><td>描述</td><td>選填</td><td>101房1月租金</td></tr>
                    </tbody>
                </table>
                <p class="text-danger small"><i class="bi bi-exclamation-triangle"></i> 第一行為標題（日期、類型...），系統會自動跳過。</p>
                <div class="bg-light p-2 border rounded">
                    <code>
                        日期,類型,類別,金額,房源名稱,描述<br>
                        2023-05-10,收入,房租,8000,101,5月份房租<br>
                        2023-05-10,收入,電費,450,101,5月份電費
                    </code>
                </div>
            </div>
        </div>
    </div>
</div>
