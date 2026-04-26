<?php
$pdo = DB::connect();
$today = date('Y-m-d');
$this_month_prefix = date('Y-m-');

// Fetch active tenants and their billing days
$active_tenants = $pdo->query("
    SELECT t.name as tenant_name, t.unit_id, t.billing_cycle_day, u.name as unit_name 
    FROM tenants t 
    JOIN units u ON t.unit_id = u.id 
    WHERE t.is_active = 1 AND u.is_active = 1
")->fetchAll();

$missing_readings = [];
foreach ($active_tenants as $t) {
    // Construct the expected billing date for this month
    // Ensure day is valid for this month (e.g. 31st in Feb)
    $day = min((int)$t['billing_cycle_day'], (int)date('t')); 
    $expected_date = $this_month_prefix . str_pad($day, 2, '0', STR_PAD_LEFT);
    
    if ($today >= $expected_date) {
        // Check if a reading exists on or after this date (or just for this month's cycle)
        $stmt = $pdo->prepare("SELECT id FROM electricity_readings WHERE unit_id = ? AND record_date >= ? LIMIT 1");
        $stmt->execute([$t['unit_id'], $expected_date]);
        if (!$stmt->fetch()) {
            $missing_readings[] = [
                'unit_id' => $t['unit_id'],
                'unit_name' => $t['unit_name'],
                'tenant_name' => $t['tenant_name'],
                'expected_date' => $expected_date
            ];
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">總覽日曆</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button type="button" class="btn btn-sm btn-outline-primary" id="todayBtn">
            <i class="bi bi-calendar-event me-1"></i> 回到今天
        </button>
    </div>
</div>

<?php if (!empty($missing_readings)): ?>
    <div class="alert alert-warning shadow-sm border-start border-4 border-warning mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div>
                <h6 class="alert-heading fw-bold mb-1">智慧提醒：有房源尚未輸入本期電度</h6>
                <p class="mb-2 small">以下房源已過計費日（<?= date('n') ?>月），請儘速查表以利開立帳單：</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($missing_readings as $m): ?>
                        <span class="badge bg-white text-dark border border-warning-subtle">
                            <?= htmlspecialchars($m['unit_name']) ?> (<?= htmlspecialchars($m['tenant_name']) ?>) - 應抄日: <?= substr($m['expected_date'], 5) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ms-auto ps-3 text-nowrap">
                <a href="index.php?p=quick_meter" class="btn btn-sm btn-warning fw-bold">
                    <i class="bi bi-lightning-charge"></i> 前往抄表
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info py-2" role="alert" style="font-size: 0.9rem;">
    <i class="bi bi-info-circle"></i> 歡迎使用房客租金管理系統。請至「房源管理」建立單位。
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-2 p-md-3">
        <ul class="nav nav-tabs" id="calendarTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="rent-tab" data-bs-toggle="tab" data-bs-target="#rentCalendarPane" type="button" role="tab" aria-controls="rentCalendarPane" aria-selected="true">
                    租金
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#ledgerCalendarPane" type="button" role="tab" aria-controls="ledgerCalendarPane" aria-selected="false">
                    流水帳
                </button>
            </li>
        </ul>
        <div class="tab-content pt-3" id="calendarTabContent">
            <div class="tab-pane fade show active" id="rentCalendarPane" role="tabpanel" aria-labelledby="rent-tab">
                <div id="calendarRent"></div>
            </div>
            <div class="tab-pane fade" id="ledgerCalendarPane" role="tabpanel" aria-labelledby="ledger-tab">
                <div id="calendarLedger"></div>
            </div>
        </div>
    </div>
</div>

<style>
/* 針對手機版日曆標題與按鈕的優化 */
@media (max-width: 768px) {
    .fc .fc-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 2px;
    }
    .fc .fc-toolbar-title {
        font-size: 1.1rem !important;
        margin: 0 4px !important;
    }
    .fc .fc-button {
        padding: 0.2rem 0.4rem !important;
        font-size: 0.8rem !important;
    }
    /* 讓兩側按鈕群組更緊湊 */
    .fc-toolbar-chunk:nth-child(1), .fc-toolbar-chunk:nth-child(3) {
        flex: 0 0 auto;
    }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var isMobile = window.innerWidth < 768;

        function buildCalendarOptions(eventsUrl) {
            return {
                initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                locale: 'zh-tw',
                headerToolbar: isMobile ? {
                    left: 'prev,next',
                    center: 'title',
                    right: 'listMonth,dayGridMonth,multiMonthYear'
                } : {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listMonth,listWeek,multiMonthYear'
                },
                buttonText: {
                    today: '今天',
                    month: '月',
                    list: '列表',
                    listMonth: '列表',
                    dayGridMonth: '月',
                    listWeek: '週',
                    multiMonthYear: '年'
                },
                height: 'auto',
                navLinks: true,
                events: eventsUrl
            };
        }

        var rentCalendarEl = document.getElementById('calendarRent');
        var ledgerCalendarEl = document.getElementById('calendarLedger');

        var rentCalendar = new FullCalendar.Calendar(rentCalendarEl, buildCalendarOptions('api/get_events.php'));
        var ledgerCalendar = new FullCalendar.Calendar(ledgerCalendarEl, buildCalendarOptions('api/get_ledger_events.php'));

        rentCalendar.render();
        ledgerCalendar.render();

        // 頂部按鈕連動
        document.getElementById('todayBtn').addEventListener('click', function() {
            var activeTab = document.querySelector('#calendarTabs .nav-link.active');
            if (activeTab && activeTab.id === 'ledger-tab') {
                ledgerCalendar.today();
            } else {
                rentCalendar.today();
            }
        });

        var tabButtons = document.querySelectorAll('#calendarTabs button[data-bs-toggle="tab"]');
        tabButtons.forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
                var target = e.target.getAttribute('data-bs-target');
                if (target === '#ledgerCalendarPane') {
                    ledgerCalendar.updateSize();
                } else {
                    rentCalendar.updateSize();
                }
            });
        });
    });
</script>
