/* ERP Dashboard — charts, clock, table search */
(function () {
    'use strict';

    var chartInstances = [];

    function updateClock() {
        var now = new Date();
        var dateEl = document.getElementById('dash-date');
        var timeEl = document.getElementById('dash-time');
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-US', {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
            });
        }
        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit', minute: '2-digit', hour12: true
            });
        }
    }

    function getCountData() {
        var el = document.getElementById('dash-count-data');
        if (!el) return {};
        try { return JSON.parse(el.textContent); } catch (e) { return {}; }
    }

    function destroyCharts() {
        chartInstances.forEach(function (c) { if (c) c.destroy(); });
        chartInstances = [];
    }

    function initChartsFromPayload(payload) {
        if (typeof Chart === 'undefined') return;

        Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#64748b';

        destroyCharts();

        var overviewEl = document.getElementById('chart-overview');
        if (!overviewEl) return;

        var labels = payload.labels || [];
        var values = payload.values || [];
        if (!labels.length) return;

        chartInstances.push(new Chart(overviewEl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Pending',
                    data: values,
                    backgroundColor: 'rgba(37, 99, 235, 0.75)',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        }));
    }

    function initCharts() {
        initChartsFromPayload(getCountData());
    }

    window.erpOnDashboardCountsLoaded = function (data, isError) {
        var countDataEl = document.getElementById('dash-count-data');
        if (!countDataEl) return;

        var base = getCountData();
        var labels = base.labels || [];
        var values = [];
        var total = 0;
        var byKey = (data && data.by_key) ? data.by_key : {};

        labels.forEach(function (key) {
            var n = isError ? 0 : (parseInt(byKey[key], 10) || 0);
            values.push(n);
            total += n;
        });

        var payload = {
            labels: labels,
            values: values,
            totalPending: total
        };

        countDataEl.textContent = JSON.stringify(payload);

        var totalEl = document.getElementById('dash-total-pending');
        if (totalEl) {
            totalEl.textContent = total + ' total pending';
        }

        initChartsFromPayload(payload);
    };

    function initTableSearch() {
        var input = document.getElementById('dash-search');
        var table = document.getElementById('dash-pending-table');
        if (!input || !table) return;

        input.addEventListener('input', function () {
            var q = input.value.toLowerCase().trim();
            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function (row) {
                var text = row.textContent.toLowerCase();
                row.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    function initSidebar() {
        var toggle  = document.getElementById('sidebar-toggle');
        var close   = document.getElementById('sidebar-close');
        var overlay = document.getElementById('sidebar-overlay');
        function openSidebar()  { document.body.classList.add('sidebar-open'); }
        function closeSidebar() { document.body.classList.remove('sidebar-open'); }
        if (toggle)  toggle.addEventListener('click', openSidebar);
        if (close)   close.addEventListener('click', closeSidebar);
        if (overlay) overlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateClock();
        setInterval(updateClock, 1000);
        initCharts();
        initTableSearch();
    });
}());
