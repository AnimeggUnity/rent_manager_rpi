<!-- R5S 硬體資訊面板（Docker；多感測器、無調速） -->
<div class="mt-3" style="font-size:0.72rem;border-top:1px solid #dee2e6;padding-top:10px;">
    <div class="fw-semibold text-secondary mb-2" style="font-size:0.7rem;letter-spacing:.3px;">硬體狀態 · R5S</div>

    <div class="mb-2 pb-2" style="border-bottom:1px solid #f0f0f0;">
        <div id="r5-board" class="text-dark fw-semibold" style="font-size:0.68rem;line-height:1.4;">--</div>
        <div class="text-muted mt-1" style="font-size:0.62rem;line-height:1.6;">
            <div>核心：<span id="r5-kernel">--</span></div>
            <div>開機時間：<span id="r5-uptime">--</span></div>
        </div>
    </div>

    <div class="mb-2 pb-2" style="border-bottom:1px solid #f0f0f0;">
        <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span class="text-muted"><i class="bi bi-cpu"></i> 頻率</span>
            <span id="r5-freq" class="fw-bold text-dark">--</span>
        </div>
        <div id="r5-freq-note" class="text-muted mb-2" style="font-size:0.6rem;text-align:right;min-height:1em;"></div>
        <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span class="text-muted">CPU</span>
            <span id="r5-cpu-temp" class="fw-bold">--</span>
        </div>
        <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span class="text-muted">GPU</span>
            <span id="r5-gpu-temp" class="fw-bold">--</span>
        </div>
        <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span class="text-muted">NVMe</span>
            <span id="r5-nvme-temp" class="fw-bold">--</span>
        </div>
        <div id="r5-nic-temps"></div>
        <div class="d-flex justify-content-between align-items-baseline mt-1">
            <span class="text-muted"><i class="bi bi-fan"></i> 風扇</span>
            <span id="r5-fan" class="text-secondary">--</span>
        </div>
    </div>

    <div class="mb-2">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">CPU 負載</span>
            <span id="r5-cpu">--%</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="r5-pb-cpu" class="progress-bar bg-info" style="width:0%;transition:width .5s"></div>
        </div>
    </div>

    <div class="mb-2">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">記憶體</span>
            <span id="r5-mem">-- / -- MB</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="r5-pb-mem" class="progress-bar bg-success" style="width:0%;transition:width .5s"></div>
        </div>
    </div>

    <div class="mb-1">
        <div class="d-flex justify-content-between mb-1">
            <span class="text-muted">NVMe 容量</span>
            <span id="r5-disk">-- / -- GB</span>
        </div>
        <div class="progress" style="height:5px;">
            <div id="r5-pb-disk" class="progress-bar bg-warning" style="width:0%;transition:width .5s"></div>
        </div>
    </div>
</div>

<script>
(function () {
    function setBar(id, pct) {
        const el = document.getElementById(id);
        if (el) el.style.width = Math.min(100, Math.max(0, pct || 0)) + '%';
    }

    function setTemp(id, c, color) {
        const el = document.getElementById(id);
        if (!el) return;
        if (c == null) {
            el.textContent = '--';
            el.style.color = '#6c757d';
            return;
        }
        el.textContent = c + '°C';
        el.style.color = color || '#2e7d32';
    }

    function fetchR5SysInfo() {
        fetch('/api/sysinfo_r5s.php').then(r => r.json()).then(d => {
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            set('r5-board',  d.board_model || 'NanoPi R5S');
            set('r5-kernel', d.kernel || '--');
            set('r5-uptime', d.uptime || '--');

            const freqEl = document.getElementById('r5-freq');
            const freqNote = document.getElementById('r5-freq-note');
            if (freqEl) {
                freqEl.textContent = d.freq_mhz != null ? (d.freq_mhz + ' MHz') : '--';
            }
            if (freqNote) {
                if (d.freq_locked) {
                    freqNote.textContent = '上限已鎖定（調速請至路由後台）';
                } else if (d.freq_min_mhz != null && d.freq_max_mhz != null) {
                    freqNote.textContent = d.freq_min_mhz + '–' + d.freq_max_mhz + ' MHz · ' + (d.governor || '');
                } else {
                    freqNote.textContent = d.governor || '';
                }
            }

            const colors = d.temp_colors || {};
            setTemp('r5-cpu-temp',  d.cpu_temp_c,  colors.cpu);
            setTemp('r5-gpu-temp',  d.gpu_temp_c,  colors.gpu);
            setTemp('r5-nvme-temp', d.nvme_temp_c, colors.nvme);

            const nicBox = document.getElementById('r5-nic-temps');
            if (nicBox) {
                const nics = Array.isArray(d.nic_temps) ? d.nic_temps : [];
                nicBox.innerHTML = nics.map((n, i) => {
                    const label = '網卡' + (nics.length > 1 ? (i + 1) : '');
                    const color = n.color || '#2e7d32';
                    return '<div class="d-flex justify-content-between align-items-baseline mb-1">'
                        + '<span class="text-muted">' + label + '</span>'
                        + '<span class="fw-bold" style="color:' + color + '">' + n.c + '°C</span>'
                        + '</div>';
                }).join('');
            }

            let fanText = '--';
            if (d.fan_rpm != null) {
                fanText = d.fan_rpm + ' RPM';
            } else if (d.fan_pwm_pct != null) {
                fanText = 'PWM ' + d.fan_pwm_pct + '%';
            }
            set('r5-fan', fanText);

            set('r5-cpu', d.cpu_pct + '%');
            setBar('r5-pb-cpu', d.cpu_pct);

            set('r5-mem', d.mem_used_mb + ' / ' + d.mem_total_mb + ' MB');
            setBar('r5-pb-mem', d.mem_total_mb ? (d.mem_used_mb / d.mem_total_mb * 100) : 0);

            set('r5-disk', d.disk_used_gb + ' / ' + d.disk_total_gb + ' GB');
            setBar('r5-pb-disk', d.disk_total_gb ? (d.disk_used_gb / d.disk_total_gb * 100) : 0);
        }).catch(() => {});
    }

    fetchR5SysInfo();
    setInterval(fetchR5SysInfo, 10000);
})();
</script>
