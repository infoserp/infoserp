<?php
/**
 * Multi-tab workspace markup — included by the /home shell only.
 * Expects: $iframe_default (Dashboard embed URL)
 */
$erp_dashboard_url = isset($iframe_default) ? $iframe_default : erp_iframe_url('dashboard');
?>
<div class="erp-tab-host flex flex-col flex-1 min-h-0" id="erp-tab-host"
     data-dashboard-url="<?php echo htmlspecialchars($erp_dashboard_url, ENT_QUOTES, 'UTF-8'); ?>"
     data-dashboard-title="Dashboard">

    <div class="erp-tab-strip-wrap ls-no-print" id="erp-tab-strip-wrap">
        <div class="erp-tab-strip" id="erp-tab-strip" role="tablist" aria-label="Open workspace tabs"></div>

        <div class="erp-tab-mobile">
            <i class="fa fa-folder-open-o text-slate-400" aria-hidden="true"></i>
            <span class="erp-tab-mobile-label" id="erp-tab-mobile-label">Dashboard</span>
            <button type="button" class="erp-tab-mobile-btn" id="erp-tab-mobile-toggle" aria-haspopup="listbox" aria-expanded="false">
                Tabs <i class="fa fa-caret-down" aria-hidden="true"></i>
            </button>
        </div>

        <div class="erp-tab-count" id="erp-tab-count" aria-live="polite">1/8</div>
    </div>

    <div class="erp-tab-panels" id="erp-tab-panels"></div>
</div>

<div id="erp-tab-toast" class="erp-tab-toast" role="status" aria-live="polite"></div>
<div id="erp-tab-session-banner" class="erp-tab-banner" role="alert">
    Session expired. Sign in again.
</div>

<div id="erp-tab-ctx" class="erp-tab-ctx" hidden role="menu" aria-label="Tab actions"></div>
<div id="erp-tab-mobile-list" class="erp-tab-mobile-list" hidden role="listbox" aria-label="Open tabs"></div>

<div id="erp-tab-modal" class="erp-tab-modal" aria-hidden="true">
    <div class="erp-tab-modal-card" role="dialog" aria-modal="true" aria-labelledby="erp-tab-modal-title">
        <h3 id="erp-tab-modal-title">Discard unsaved changes?</h3>
        <p id="erp-tab-modal-body">This tab has unsaved changes. Close it anyway?</p>
        <div class="erp-tab-modal-actions">
            <button type="button" id="erp-tab-modal-cancel">Cancel</button>
            <button type="button" class="is-primary" id="erp-tab-modal-ok">Discard</button>
        </div>
    </div>
</div>
