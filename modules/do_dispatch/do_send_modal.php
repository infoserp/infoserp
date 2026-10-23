<!-- DO Dispatch — email confirmation + send modal -->
<div id="send-modal-overlay" class="erp-search" style="display:none !important;">

    <div id="send-modal" class="erp-search-dialog sm" role="dialog" aria-modal="true" aria-labelledby="send-modal-title">

        <div class="erp-search-head">
            <div>
                <h4 class="erp-search-title" id="send-modal-title"><i class="fa fa-paper-plane"></i>Send Delivery Order</h4>
                <p id="send-modal-doc" class="erp-search-subtitle"></p>
            </div>
            <button id="send-modal-close" type="button" class="erp-search-close" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>

        <div class="erp-search-content">
            <div id="send-modal-loading" class="erp-search-state" style="display:none;">
                <i class="fa fa-spinner fa-spin"></i> Loading customer email...
            </div>

            <div id="send-modal-body" style="display:none;">
                <p id="send-modal-hint" class="erp-search-text" style="margin-bottom: 12px;">
                    Confirm sending the Delivery Order PDF to the selected customer email(s).
                </p>
                <div class="erp-search-panel">
                    <div class="erp-search-panel-label">Customer</div>
                    <div id="send-modal-customer" class="erp-search-panel-value"></div>
                    <div class="erp-search-pane-row" style="margin-top: 12px;">
                        <div class="erp-search-panel-label">Email(s)</div>
                        <button id="send-add-email-toggle" type="button" class="erp-search-btn link" style="font-size: 12px;">
                            <i class="fa fa-plus"></i> Add email
                        </button>
                    </div>
                    <div id="send-modal-email" class="dod-send-emails"></div>
                    <div id="send-modal-no-email" class="erp-search-msg warn" style="display:none; margin-top: 6px;">
                        No email found for this customer. Add an email below to continue.
                    </div>
                </div>

                <div id="send-add-email-panel" class="erp-search-panel" style="display:none; margin-top: 12px;">
                    <label for="send-new-email" class="erp-search-label">New email</label>
                    <div class="dod-send-email-row">
                        <input id="send-new-email" type="email" autocomplete="email" class="erp-search-field" placeholder="name@example.com">
                        <button id="send-add-email-btn" type="button" class="erp-search-btn primary">
                            <i class="fa fa-save"></i> Save
                        </button>
                    </div>
                </div>
            </div>

            <div id="send-modal-err" class="erp-search-msg error" style="display:none;"></div>
            <div id="send-modal-ok" class="erp-search-msg success" style="display:none;"></div>
        </div>

        <div class="erp-search-foot">
            <button id="send-cancel-btn" type="button" class="erp-search-btn">Cancel</button>
            <button id="send-confirm-btn" type="button" class="erp-search-btn primary" style="display:none;">
                <i class="fa fa-paper-plane"></i>
                Send Email
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay     = document.getElementById('send-modal-overlay');
    var loadingEl   = document.getElementById('send-modal-loading');
    var bodyEl      = document.getElementById('send-modal-body');
    var errEl       = document.getElementById('send-modal-err');
    var okEl        = document.getElementById('send-modal-ok');
    var docEl       = document.getElementById('send-modal-doc');
    var customerEl  = document.getElementById('send-modal-customer');
    var emailEl     = document.getElementById('send-modal-email');
    var noEmailEl   = document.getElementById('send-modal-no-email');
    var hintEl      = document.getElementById('send-modal-hint');
    var addPanel    = document.getElementById('send-add-email-panel');
    var addToggle   = document.getElementById('send-add-email-toggle');
    var newEmailInp = document.getElementById('send-new-email');
    var addEmailBtn = document.getElementById('send-add-email-btn');
    var confirmBtn  = document.getElementById('send-confirm-btn');
    var currentDoc  = '';
    var currentEmails = [];

    function hideMessages() {
        errEl.style.display = 'none';
        okEl.style.display  = 'none';
        errEl.textContent   = '';
        okEl.textContent    = '';
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getSelectedEmails() {
        var selected = [];
        emailEl.querySelectorAll('input.send-email-cb:checked').forEach(function (cb) {
            if (cb.value) selected.push(cb.value);
        });
        return selected;
    }

    function updateSendButtonState() {
        var hasEmails = currentEmails.length > 0;
        var selected  = getSelectedEmails();
        confirmBtn.style.display = hasEmails ? 'inline-flex' : 'none';
        confirmBtn.disabled = !hasEmails || selected.length === 0;
        if (!hasEmails) {
            noEmailEl.style.display = 'block';
            hintEl.textContent = 'No customer email found. Add an email to continue.';
            addPanel.style.display = 'block';
        } else {
            noEmailEl.style.display = 'none';
            hintEl.textContent = 'Confirm sending the Delivery Order PDF to the selected customer email(s).';
        }
    }

    function renderEmails(emails, preferSelect) {
        currentEmails = Array.isArray(emails) ? emails.slice() : [];
        var selectAll = !preferSelect || !preferSelect.length;
        var preferMap = {};
        (preferSelect || []).forEach(function (em) {
            preferMap[String(em).toLowerCase()] = true;
        });

        if (!currentEmails.length) {
            emailEl.innerHTML = '';
            updateSendButtonState();
            return;
        }

        emailEl.innerHTML = currentEmails.map(function (em, idx) {
            var checked = selectAll || preferMap[String(em).toLowerCase()];
            var id = 'send-email-cb-' + idx;
            return '<label for="' + id + '" class="flex items-start gap-2 cursor-pointer text-sm text-gray-800">'
                + '<input id="' + id + '" class="send-email-cb mt-0.5" type="checkbox" value="' + escapeHtml(em) + '"'
                + (checked ? ' checked' : '') + '>'
                + '<span class="font-mono text-indigo-700 break-all leading-relaxed">' + escapeHtml(em) + '</span>'
                + '</label>';
        }).join('');

        emailEl.querySelectorAll('input.send-email-cb').forEach(function (cb) {
            cb.addEventListener('change', updateSendButtonState);
        });
        updateSendButtonState();
    }

    function closeModal() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        hideMessages();
        loadingEl.style.display = 'none';
        bodyEl.style.display    = 'none';
        confirmBtn.style.display = 'none';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fa fa-paper-plane text-xs"></i> Send Email';
        addPanel.style.display = 'none';
        newEmailInp.value = '';
        currentEmails = [];
        emailEl.innerHTML = '';
        noEmailEl.style.display = 'none';
    }

    var sendApiUrl = new URL('do_send.php', window.location.href).href;

    function parseJsonResponse(r) {
        return r.text().then(function (text) {
            var data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                var snippet = text ? text.replace(/\s+/g, ' ').substring(0, 180) : '';
                throw new Error('Unexpected server response (HTTP ' + r.status + '). ' + snippet);
            }
            if (!data) {
                throw new Error('Empty server response (HTTP ' + r.status + ').');
            }
            return { ok: r.ok, status: r.status, data: data };
        });
    }

    function showApiError(res, fallback) {
        var msg = (res && res.data && res.data.message) ? res.data.message : fallback;
        errEl.textContent = msg;
        errEl.style.display = 'block';
    }

    function postSend(payload) {
        return fetch(sendApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(parseJsonResponse);
    }

    window.openSendModal = function (docNo) {
        currentDoc = docNo || '';
        hideMessages();
        docEl.textContent = currentDoc ? ('DO ' + currentDoc) : '';
        loadingEl.style.display = 'block';
        bodyEl.style.display    = 'none';
        confirmBtn.style.display = 'none';
        addPanel.style.display = 'none';
        newEmailInp.value = '';
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        postSend({ action: 'prepare', doc_no: currentDoc })
            .then(function (res) {
                loadingEl.style.display = 'none';
                var d = res.data;
                if (!d.success) {
                    showApiError(res, 'Could not prepare email.');
                    return;
                }
                customerEl.textContent = d.customer_name || '—';
                renderEmails(d.customer_emails || []);
                bodyEl.style.display = 'block';
            })
            .catch(function (err) {
                loadingEl.style.display = 'none';
                errEl.textContent = err.message || 'Request failed.';
                errEl.style.display = 'block';
            });
    };

    function confirmSend() {
        hideMessages();
        var selected = getSelectedEmails();
        if (!selected.length) {
            errEl.textContent = 'Select at least one email to send.';
            errEl.style.display = 'block';
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fa fa-spinner fa-spin text-xs"></i> Sending...';

        postSend({ action: 'send', doc_no: currentDoc, confirm: true, emails: selected })
            .then(function (res) {
                var d = res.data;
                if (!d.success) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fa fa-paper-plane text-xs"></i> Send Email';
                    showApiError(res, 'Send failed.');
                    updateSendButtonState();
                    return;
                }
                okEl.textContent = d.message || 'Email sent successfully.';
                okEl.style.display = 'block';
                confirmBtn.style.display = 'none';
                removeDispatchedDoFromList(d.doc_no || currentDoc);
                showSendToast(d.message || 'Email sent successfully.');
                setTimeout(closeModal, 1200);
            })
            .catch(function (err) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fa fa-paper-plane text-xs"></i> Send Email';
                errEl.textContent = err.message || 'Request failed.';
                errEl.style.display = 'block';
                updateSendButtonState();
            });
    }

    function saveNewEmail() {
        hideMessages();
        var email = (newEmailInp.value || '').trim();
        if (!email) {
            errEl.textContent = 'Please enter an email address.';
            errEl.style.display = 'block';
            return;
        }

        addEmailBtn.disabled = true;
        addEmailBtn.innerHTML = '<i class="fa fa-spinner fa-spin text-xs"></i> Saving';

        postSend({ action: 'add_email', doc_no: currentDoc, email: email })
            .then(function (res) {
                addEmailBtn.disabled = false;
                addEmailBtn.innerHTML = '<i class="fa fa-save text-xs"></i> Save';
                var d = res.data;
                if (!d.success) {
                    showApiError(res, 'Could not save email.');
                    return;
                }
                newEmailInp.value = '';
                var prefer = d.added && d.added.email ? [d.added.email] : [];
                // Keep previously selected emails checked, plus the new one.
                prefer = prefer.concat(getSelectedEmails());
                renderEmails(d.customer_emails || [], prefer);
                okEl.textContent = d.message || 'Email saved.';
                okEl.style.display = 'block';
            })
            .catch(function (err) {
                addEmailBtn.disabled = false;
                addEmailBtn.innerHTML = '<i class="fa fa-save text-xs"></i> Save';
                errEl.textContent = err.message || 'Request failed.';
                errEl.style.display = 'block';
            });
    }

    function removeDispatchedDoFromList(docNo) {
        var tbody = document.getElementById('dod-tbody');
        if (!tbody || !docNo) {
            return;
        }

        tbody.querySelectorAll('tr[data-doc]').forEach(function (tr) {
            if (tr.getAttribute('data-doc') === docNo) {
                tr.remove();
            }
        });

        var statCount = document.getElementById('dod-stat-count');
        if (statCount) {
            var n = parseInt(statCount.textContent, 10) || 0;
            statCount.textContent = Math.max(0, n - 1);
        }

        if (!tbody.querySelector('tr[data-doc]') && typeof window.dodRefreshList === 'function') {
            window.dodRefreshList();
        }
    }

    function showSendToast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:50%', 'transform:translateX(-50%)',
            'background:#4f46e5', 'color:#fff', 'font-size:13px', 'font-weight:600',
            'padding:10px 22px', 'border-radius:999px',
            'box-shadow:0 4px 16px rgba(0,0,0,.18)',
            'z-index:99999', 'opacity:0', 'transition:opacity .3s'
        ].join(';');
        document.body.appendChild(t);
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            setTimeout(function () {
                t.style.opacity = '0';
                setTimeout(function () { t.remove(); }, 350);
            }, 3500);
        });
    }

    document.getElementById('send-modal-close').addEventListener('click', closeModal);
    document.getElementById('send-cancel-btn').addEventListener('click', closeModal);
    confirmBtn.addEventListener('click', confirmSend);
    addToggle.addEventListener('click', function () {
        addPanel.style.display = addPanel.style.display === 'none' ? 'block' : 'none';
        if (addPanel.style.display === 'block') {
            newEmailInp.focus();
        }
    });
    addEmailBtn.addEventListener('click', saveNewEmail);
    newEmailInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveNewEmail();
        }
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });
})();
</script>
