<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$pdo = DB::connect();
$events = [];

$start = isset($_GET['start']) ? $_GET['start'] : null;
$end = isset($_GET['end']) ? $_GET['end'] : null;

$sql = "
    SELECT l.id, l.trans_date, l.type, l.category, l.amount, l.description, l.is_paid,
           u.name as unit_name, t.name as tenant_name
    FROM ledger l
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN tenants t ON l.tenant_id = t.id
    WHERE l.type = 'expense'
";

$params = [];
if ($start && $end) {
    $sql .= " AND l.trans_date >= ? AND l.trans_date <= ?";
    $params[] = $start;
    $params[] = $end;
}

$sql .= " ORDER BY l.trans_date ASC, l.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as $row) {
    $titleParts = [];
    $typeLabel = $row['type'] === 'income' ? '收入' : '支出';
    $titleParts[] = $typeLabel;
    $titleParts[] = $row['category'];

    if (!empty($row['unit_name'])) {
        $titleParts[] = $row['unit_name'];
    }
    if (!empty($row['tenant_name'])) {
        $titleParts[] = $row['tenant_name'];
    }

    $title = implode(' · ', $titleParts) . ' $' . number_format((int)$row['amount']);

    $color = '#dc3545';
    $textColor = 'white';

    if ($row['type'] === 'income') {
        if ((int)$row['is_paid'] === 1) {
            $color = '#198754';
        } else {
            $color = '#ffc107';
            $textColor = 'black';
        }
    }

    $events[] = [
        'title' => $title,
        'start' => $row['trans_date'],
        'color' => $color,
        'textColor' => $textColor,
        'url' => 'index.php?p=ledger',
        'description' => $row['description']
    ];
}

echo json_encode($events);
