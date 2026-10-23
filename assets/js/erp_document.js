/* Shared behaviour for document pages (invoice, receipt, material request). */

// ---- Density toggle (remembered per browser) ----
function applyDensity(compact) {
    document.body.classList.toggle('compact', compact);
    var btn = document.getElementById('densityBtn');
    if (!btn) return;
    btn.querySelector('span').textContent = compact ? 'Comfortable' : 'Compact';
    btn.querySelector('i').className = compact ? 'fa fa-expand' : 'fa fa-compress';
}

function toggleDensity() {
    var compact = !document.body.classList.contains('compact');
    try { localStorage.setItem('erpDocCompact', compact ? '1' : '0'); } catch (e) {}
    applyDensity(compact);
}

document.addEventListener('DOMContentLoaded', function () {
    var compact = false;
    try { compact = localStorage.getItem('erpDocCompact') === '1'; } catch (e) {}
    applyDensity(compact);
});
