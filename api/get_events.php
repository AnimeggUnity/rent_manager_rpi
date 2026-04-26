<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$pdo = DB::connect();
$events = [];

// 1. Fetch Unpaid Bills (Income that is not paid)
$unpaid = $pdo->query("
    SELECT l.*, u.name as unit_name, t.name as tenant_name 
    FROM ledger l
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN tenants t ON l.tenant_id = t.id
    WHERE l.type = 'income' AND l.is_paid = 0
")->fetchAll();

foreach ($unpaid as $row) {
    $events[] = [
        'title' => "待收: {$row['unit_name']} - {$row['category']} \${$row['amount']}",
        'start' => $row['trans_date'], // The date bill was generated
        'color' => '#dc3545', // Red
        'url' => 'index.php?p=ledger' // Link to ledger to mark paid
    ];
}

// 2. Fetch Active Tenants for Payment Cycle reminders (Next due date)
// Since we don't have a recurring event engine, we just project the due date for current and next month.
$tenants = $pdo->query("
    SELECT t.*, u.name as unit_name 
    FROM tenants t 
    JOIN units u ON t.unit_id = u.id 
    WHERE t.is_active = 1
")->fetchAll();
$months = [date('Y-m'), date('Y-m', strtotime('+1 month'))];

foreach ($tenants as $t) {
    if (!$t['billing_cycle_day']) continue;
    
    foreach ($months as $m) {
        // Construct date: YYYY-MM-DD
        $day = sprintf('%02d', $t['billing_cycle_day']);
        $date = "$m-$day";
        
        // Validation: check if date is valid AND within contract term (or indefinite)
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) {
            // Check if date is after start date
            if ($t['contract_start'] && $date < $t['contract_start']) continue;

            // Check if contract has technically "expired" but tenant is still active
            $is_expired_indefinite = ($t['contract_end'] && $date > $t['contract_end']);
            
            $title_suffix = $is_expired_indefinite ? " (不定期)" : "";

            $events[] = [
                'title' => "繳費: {$t['unit_name']} - {$t['name']}" . $title_suffix,
                'start' => $date,
                'color' => $is_expired_indefinite ? '#17a2b8' : '#ffc107', // Cyan if indefinite, Yellow if normal
                'textColor' => $is_expired_indefinite ? 'white' : 'black',
                'url' => "index.php?p=billing&tenant_id={$t['id']}",
                'description' => $is_expired_indefinite ? '合約已過期，自動轉為不定期租約' : '合約繳費週期'
            ];
        }
    }
}

// 3. Contract End dates
foreach ($tenants as $t) {
    if (!$t['contract_end']) continue; // Skip if indefinite
    $events[] = [
        'title' => "到期: {$t['unit_name']} - {$t['name']}",
        'start' => $t['contract_end'],
        'color' => '#6c757d', // Grey
    ];
}

echo json_encode($events);
