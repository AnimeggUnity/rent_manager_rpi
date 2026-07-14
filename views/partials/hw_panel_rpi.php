<!-- RPi 硬體資訊面板（裸機；含調速與溫度趨勢圖） -->
<div class="mt-3" style="font-size:0.72rem;border-top:1px solid #dee2e6;padding-top:10px;">
    <div class="fw-semibold text-secondary mb-2" style="font-size:0.7rem;letter-spacing:.3px;">硬體狀態 · RPi</div>

    <div class="mb-2 pb-2" style="border-bottom:1px solid #f0f0f0;">
        <div id="si-board" class="text-dark fw-semibold" style="font-size:0.68rem;line-height:1.4;">--</div>
        <div class="text-muted mt-1" style="font-size:0.62rem;line-height:1.6;">
            <div>OS：<span id="si-os">--</span></div>
            <div>核心：<span id="si-kernel">--</span></div>
            <div>序號末8碼：<span id="si-serial">--</span></div>
            <div>開機時間：<span id="si-uptime">--</span></div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-baseline mb-1">
        <span class="text-muted"><i class="bi bi-thermometer-half"></i> 溫度</span>
        <span id="si-temp" class="fw-bold">--</span>
    </div>
    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <span class="text-muted"><i class="bi bi-cpu"></i> 頻率</span>
        <span id="si-freq" class="text-secondary">--</span>
    </div>

    <div style="height:110px;position:relative;margin-bottom:10px;">
        <canvas id="temp-chart"></canvas>
    </div>

    <div class="mb-2">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">CPU 負載</span>
            <span id="si-cpu">--%</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="pb-cpu" class="progress-bar bg-info" style="width:0%;transition:width .5s"></div>
        </div>
    </div>

    <div class="mb-2">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">記憶體</span>
            <span id="si-mem">-- / -- MB</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="pb-mem" class="progress-bar bg-success" style="width:0%;transition:width .5s"></div>
        </div>
    </div>

    <div class="mb-3">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">磁碟</span>
            <span id="si-disk">-- / -- GB</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="pb-disk" class="progress-bar bg-warning" style="width:0%;transition:width .5s"></div>
        </div>
    </div>

    <div class="fw-semibold text-secondary mb-1" style="font-size:0.7rem;">CPU 模式</div>
    <div class="d-flex gap-1 flex-wrap mb-1">
        <button type="button" onclick="setCpuGov('powersave')"   id="gov-btn-powersave"   class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">省電</button>
        <button type="button" onclick="setCpuGov('schedutil')"   id="gov-btn-schedutil"   class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">自動</button>
        <button type="button" onclick="setCpuGov('ondemand')"    id="gov-btn-ondemand"    class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">按需</button>
        <button type="button" onclick="setCpuGov('performance')" id="gov-btn-performance" class="btn btn-xs border" style="font-size:0.62rem;padding:1px 5px;">效能</button>
    </div>
    <div id="gov-status" class="text-muted" style="font-size:0.62rem;min-height:1em;"></div>
</div>

<script>
let tempChart = null;

const GOV_LABELS = { powersave:'省電 600MHz', schedutil:'自動', ondemand:'按需', performance:'效能 1.4GHz' };

function updateGovButtons(gov) {
    ['powersave','schedutil','ondemand','performance'].forEach(g => {
        const btn = document.getElementById('gov-btn-' + g);
        if (!btn) return;
        btn.className = 'btn btn-xs border' + (g === gov ? ' btn-primary text-white' : '');
        btn.style.cssText = 'font-size:0.62rem;padding:1px 5px;';
    });
}

function setCpuGov(gov) {
    document.getElementById('gov-status').textContent = '切換中…';
    fetch('/api/cpu_freq.php?action=set&governor=' + gov)
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                updateGovButtons(d.governor);
                document.getElementById('gov-status').textContent = '✓ ' + (GOV_LABELS[d.governor] || d.governor);
            } else {
                document.getElementById('gov-status').textContent = '失敗：' + (d.error || '未知');
            }
        }).catch(() => {
            document.getElementById('gov-status').textContent = '請求失敗';
        });
}

function setBar(id, pct) {
    const el = document.getElementById(id);
    if (el) el.style.width = Math.min(100, pct) + '%';
}

function fetchSysInfo() {
    fetch('/api/sysinfo.php').then(r => r.json()).then(d => {
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        set('si-board',  d.board_model || '--');
        set('si-os',     d.os_name     || '--');
        set('si-kernel', d.kernel      || '--');
        set('si-serial', d.serial      || '--');
        set('si-uptime', d.uptime      || '--');

        const tempEl = document.getElementById('si-temp');
        if (tempEl) {
            tempEl.textContent = d.temp_c + '°C';
            tempEl.style.color = d.temp_c >= 80 ? '#e53935' : d.temp_c >= 70 ? '#fb8c00' : '#2e7d32';
        }
        const freqEl = document.getElementById('si-freq');
        if (freqEl) freqEl.textContent = (d.freq_mhz || '--') + ' MHz';
        if (d.governor) updateGovButtons(d.governor);

        const cpuEl = document.getElementById('si-cpu');
        if (cpuEl) cpuEl.textContent = d.cpu_pct + '%';
        setBar('pb-cpu', d.cpu_pct);

        const memEl = document.getElementById('si-mem');
        if (memEl) memEl.textContent = d.mem_used_mb + ' / ' + d.mem_total_mb + ' MB';
        setBar('pb-mem', d.mem_used_mb / d.mem_total_mb * 100);

        const diskEl = document.getElementById('si-disk');
        if (diskEl) diskEl.textContent = d.disk_used_gb + ' / ' + d.disk_total_gb + ' GB';
        setBar('pb-disk', d.disk_used_gb / d.disk_total_gb * 100);
    }).catch(() => {});
}

function fetchTempChart() {
    // RPi：經本機 nginx 反代到 Flask，維持相對路徑
    fetch('/api/temp_history').then(r => r.json()).then(data => {
        if (!Array.isArray(data) || !data.length) return;
        const labels = data.map(d => d.t.slice(11, 16));
        const values = data.map(d => d.c);
        const canvas = document.getElementById('temp-chart');
        if (!canvas) return;
        if (tempChart) tempChart.destroy();
        tempChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data: values,
                    borderColor: values[values.length - 1] >= 70 ? '#e53935' : '#fb8c00',
                    backgroundColor: values[values.length - 1] >= 70 ? 'rgba(229,57,53,0.08)' : 'rgba(251,140,0,0.08)',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: {
                    callbacks: { label: ctx => ctx.parsed.y + '°C' }
                }},
                scales: {
                    x: {
                        display: true,
                        title: { display: true, text: '時間', font: { size: 8 }, color: '#aaa' },
                        ticks: { font: { size: 8 }, maxTicksLimit: 4, maxRotation: 0, color: '#aaa' },
                        grid: { display: false }
                    },
                    y: {
                        display: true,
                        title: { display: true, text: '°C', font: { size: 8 }, color: '#aaa' },
                        suggestedMin: 40,
                        suggestedMax: 85,
                        ticks: { font: { size: 8 }, maxTicksLimit: 4, color: '#aaa', callback: v => v + '°' },
                        grid: { color: 'rgba(0,0,0,0.04)' }
                    }
                }
            }
        });
    }).catch(() => {});
}

fetchSysInfo();
fetchTempChart();
setInterval(fetchSysInfo, 10000);
setInterval(fetchTempChart, 600000);
</script>
