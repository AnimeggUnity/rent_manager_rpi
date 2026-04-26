<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '只接受 POST 請求']);
    exit;
}

$pdo = DB::connect();
$action = $_POST['action'] ?? '';

// 獲取某筆支出的所有附件
if ($action === 'get_attachments') {
    $ledger_id = (int)($_POST['ledger_id'] ?? 0);

    if ($ledger_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的記錄 ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, file_path, file_name, created_at FROM ledger_attachments WHERE ledger_id = ? ORDER BY id DESC");
    $stmt->execute([$ledger_id]);
    $attachments = $stmt->fetchAll();

    // 計算檔案大小
    foreach ($attachments as &$att) {
        $fullPath = __DIR__ . '/../' . $att['file_path'];
        if (file_exists($fullPath)) {
            $att['file_size'] = filesize($fullPath);
            $att['file_size_mb'] = number_format($att['file_size'] / 1024 / 1024, 2);
        } else {
            $att['file_size'] = 0;
            $att['file_size_mb'] = '0.00';
        }
    }

    echo json_encode(['success' => true, 'attachments' => $attachments]);
    exit;
}

// 上傳新附件
if ($action === 'upload_attachment') {
    $ledger_id = (int)($_POST['ledger_id'] ?? 0);

    if ($ledger_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的記錄 ID']);
        exit;
    }

    // 檢查該 ledger 記錄是否存在
    $stmt = $pdo->prepare("SELECT id FROM ledger WHERE id = ?");
    $stmt->execute([$ledger_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '找不到該支出記錄']);
        exit;
    }

    if (empty($_FILES['attachments']['name'][0])) {
        echo json_encode(['success' => false, 'message' => '請選擇檔案']);
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/expense_attachments/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $uploaded_count = 0;
    $file_count = count($_FILES['attachments']['name']);

    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['attachments']['tmp_name'][$i];
            $file_name = $_FILES['attachments']['name'][$i];
            $file_size = $_FILES['attachments']['size'][$i];

            // 驗證檔案大小 (5MB)
            if ($file_size > 5 * 1024 * 1024) {
                continue;
            }

            // 生成唯一檔名
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            $new_name = uniqid() . '_' . time() . '.' . $ext;
            $file_path = 'uploads/expense_attachments/' . $new_name;
            $full_path = $upload_dir . $new_name;

            // 移動檔案
            if (move_uploaded_file($tmp_name, $full_path)) {
                $pdo->prepare("INSERT INTO ledger_attachments (ledger_id, file_path, file_name) VALUES (?, ?, ?)")
                    ->execute([$ledger_id, $file_path, $file_name]);
                $uploaded_count++;
            }
        }
    }

    echo json_encode(['success' => true, 'message' => "成功上傳 {$uploaded_count} 個檔案", 'count' => $uploaded_count]);
    exit;
}

// 刪除附件
if ($action === 'delete_attachment') {
    $attachment_id = (int)($_POST['attachment_id'] ?? 0);

    if ($attachment_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的附件 ID']);
        exit;
    }

    // 獲取附件資訊
    $stmt = $pdo->prepare("SELECT file_path FROM ledger_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch();

    if (!$attachment) {
        echo json_encode(['success' => false, 'message' => '找不到該附件']);
        exit;
    }

    // 刪除實體檔案
    $fullPath = __DIR__ . '/../' . $attachment['file_path'];
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }

    // 刪除資料庫記錄
    $pdo->prepare("DELETE FROM ledger_attachments WHERE id = ?")->execute([$attachment_id]);

    echo json_encode(['success' => true, 'message' => '附件已刪除']);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知的操作']);
