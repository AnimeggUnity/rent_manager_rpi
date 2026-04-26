<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '僅支援 POST 請求']);
    exit;
}

$pdo = DB::connect();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // 取得所有類別（含使用次數）
            $stmt = $pdo->query("
                SELECT c.*, COUNT(l.id) as usage_count 
                FROM expense_categories c 
                LEFT JOIN ledger l ON l.category = c.name AND l.type = 'expense'
                GROUP BY c.id 
                ORDER BY c.display_order
            ");
            $categories = $stmt->fetchAll();
            echo json_encode(['success' => true, 'categories' => $categories]);
            break;

        case 'add':
            // 新增類別
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '類別名稱不可為空']);
                exit;
            }
            
            // 檢查是否重複
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => '類別名稱已存在']);
                exit;
            }
            
            // 取得最大排序值
            $maxOrder = $pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM expense_categories")->fetchColumn();
            
            // 插入新類別
            $stmt = $pdo->prepare("INSERT INTO expense_categories (name, display_order, is_active, is_system) VALUES (?, ?, 1, 0)");
            $stmt->execute([$name, $maxOrder + 1]);
            
            $newId = $pdo->lastInsertId();
            $newCategory = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ?");
            $newCategory->execute([$newId]);
            
            echo json_encode(['success' => true, 'message' => '類別新增成功', 'category' => $newCategory->fetch()]);
            break;

        case 'edit':
            // 編輯類別
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '類別名稱不可為空']);
                exit;
            }
            
            // 檢查是否重複（排除自己）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => '類別名稱已存在']);
                exit;
            }
            
            // 更新類別
            $stmt = $pdo->prepare("UPDATE expense_categories SET name = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $isActive, $id]);
            
            echo json_encode(['success' => true, 'message' => '類別更新成功']);
            break;

        case 'delete':
            // 刪除/停用類別
            $id = (int)($_POST['id'] ?? 0);
            
            // 檢查使用次數
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM ledger 
                WHERE category = (SELECT name FROM expense_categories WHERE id = ?) 
                AND type = 'expense'
            ");
            $stmt->execute([$id]);
            $usageCount = $stmt->fetchColumn();
            
            // 檢查是否為系統類別
            $stmt = $pdo->prepare("SELECT is_system FROM expense_categories WHERE id = ?");
            $stmt->execute([$id]);
            $isSystem = $stmt->fetchColumn();
            
            if ($usageCount > 0 || $isSystem) {
                // 已使用或系統類別，只能停用
                $stmt = $pdo->prepare("UPDATE expense_categories SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => '類別已停用（因為已有使用記錄或為系統類別）']);
            } else {
                // 未使用且非系統類別，可以刪除
                $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => '類別已刪除']);
            }
            break;

        case 'reorder':
            // 更新排序
            $order = $_POST['order'] ?? [];
            
            if (!is_array($order)) {
                echo json_encode(['success' => false, 'message' => '排序資料格式錯誤']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE expense_categories SET display_order = ? WHERE id = ?");
            foreach ($order as $index => $categoryId) {
                $stmt->execute([$index, $categoryId]);
            }
            
            echo json_encode(['success' => true, 'message' => '排序已更新']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '無效的操作']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '伺服器錯誤: ' . $e->getMessage()]);
}
