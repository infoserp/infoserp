/**
 * INFOS ERP shell — multi-tab workspace controller (max 8 tabs).
 * Mounts on #erp-tab-host. Persists tab list in sessionStorage.
 */
(function (global) {
    'use strict';

    var MAX_TABS = 8;
    var STORAGE_KEY = 'erp_shell_tabs_v1';
    var host, strip, panels, countEl, toastEl, bannerEl, ctxEl, mobileLabel, mobileToggle, mobileList;
    var modalEl, modalOk, modalCancel;
    var state = { tabs: [], activeId: null, lastActiveId: null };
    var seq = 1;
    var modalResolve = null;
    var ctxTabId = null;

    function $(id) { return document.getElementById(id); }

    function toast(msg) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.add('is-visible');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () {
            toastEl.classList.remove('is-visible');
        }, 3200);
    }

    function saveState() {
        try {
            var payload = {
                activeId: state.activeId,
                tabs: state.tabs.map(function (t) {
                    return { id: t.id, title: t.title, url: t.url, dirty: !!t.dirty, pinned: !!t.pinned };
                })
            };
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
        } catch (e) { /* private mode / quota */ }
    }

    function loadState() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function clearState() {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    }

    function normalizeUrl(url) {
        try {
            var u = new URL(url, global.location.origin);
            // Keep path + search; drop hash. Sort query for stable compare? keep as-is for doc_no identity.
            var path = u.pathname.replace(/\/+$/, '') || '/';
            return path + u.search;
        } catch (e) {
            return String(url || '');
        }
    }

    function absoluteUrl(url) {
        try {
            return new URL(url, global.location.origin).href;
        } catch (e) {
            return String(url || '');
        }
    }

    function withEmbed(url) {
        var href = String(url || '');
        if (!href || href.indexOf('javascript:') === 0) return href;
        if (href.indexOf('embed=1') >= 0) return href;
        return href + (href.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    }

    function findTab(id) {
        for (var i = 0; i < state.tabs.length; i++) {
            if (state.tabs[i].id === id) return state.tabs[i];
        }
        return null;
    }

    function findTabByUrl(url) {
        var key = normalizeUrl(url);
        for (var i = 0; i < state.tabs.length; i++) {
            if (normalizeUrl(state.tabs[i].url) === key) return state.tabs[i];
        }
        return null;
    }

    function extractDocNo(url) {
        try {
            var u = new URL(url, global.location.origin);
            return (u.searchParams.get('doc_no') || '').trim();
        } catch (e) {
            return '';
        }
    }

    function anyDirty() {
        return state.tabs.some(function (t) { return !!t.dirty; });
    }

    function nextId() {
        return 'tab-' + (seq++) + '-' + Date.now().toString(36);
    }

    function confirmDiscard(title) {
        return new Promise(function (resolve) {
            if (!modalEl) {
                resolve(global.confirm('Discard unsaved changes?'));
                return;
            }
            modalResolve = resolve;
            $('erp-tab-modal-title').textContent = 'Discard unsaved changes?';
            $('erp-tab-modal-body').textContent =
                (title ? '"' + title + '" has unsaved changes. Close it anyway?' : 'This tab has unsaved changes. Close it anyway?');
            modalEl.classList.add('is-open');
            modalEl.setAttribute('aria-hidden', 'false');
            if (modalOk) modalOk.focus();
        });
    }

    function closeModal(result) {
        if (modalEl) {
            modalEl.classList.remove('is-open');
            modalEl.setAttribute('aria-hidden', 'true');
        }
        if (modalResolve) {
            var r = modalResolve;
            modalResolve = null;
            r(!!result);
        }
    }

    function setActiveNavFromTab(tab) {
        if (!tab || typeof global.setActiveNav === 'function') {
            /* setActiveNav is private in approvalmenu — dispatch custom event */
        }
        try {
            global.dispatchEvent(new CustomEvent('erp:tab-activated', {
                detail: { url: tab ? tab.url : '', title: tab ? tab.title : '' }
            }));
        } catch (e) { /* ignore */ }
    }

    function renderStrip() {
        if (!strip) return;
        strip.innerHTML = '';
        state.tabs.forEach(function (tab) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'erp-tab'
                + (tab.id === state.activeId ? ' is-active' : '')
                + (tab.dirty ? ' is-dirty' : '')
                + (tab.pinned ? ' is-pinned' : '')
                + (tab.loading ? ' is-loading' : '');
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', tab.id === state.activeId ? 'true' : 'false');
            btn.setAttribute('data-tab-id', tab.id);
            btn.id = 'erp-tab-btn-' + tab.id;
            btn.title = tab.title;

            var icon = document.createElement('i');
            icon.className = 'fa erp-tab-icon ' + (tab.pinned ? 'fa-home' : 'fa-file-o');
            icon.setAttribute('aria-hidden', 'true');
            btn.appendChild(icon);

            var title = document.createElement('span');
            title.className = 'erp-tab-title';
            title.textContent = tab.title;
            btn.appendChild(title);

            var dot = document.createElement('span');
            dot.className = 'erp-tab-dirty-dot';
            dot.setAttribute('aria-hidden', 'true');
            btn.appendChild(dot);

            if (!tab.pinned) {
                var close = document.createElement('span');
                close.className = 'erp-tab-close';
                close.setAttribute('role', 'button');
                close.setAttribute('aria-label', 'Close ' + tab.title);
                close.tabIndex = 0;
                close.innerHTML = '&times;';
                close.addEventListener('click', function (e) {
                    e.stopPropagation();
                    closeTab(tab.id);
                });
                close.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        e.stopPropagation();
                        closeTab(tab.id);
                    }
                });
                btn.appendChild(close);
            }

            var load = document.createElement('span');
            load.className = 'erp-tab-loading';
            load.setAttribute('aria-hidden', 'true');
            btn.appendChild(load);

            btn.addEventListener('click', function () { activateTab(tab.id); });
            btn.addEventListener('auxclick', function (e) {
                if (e.button === 1) {
                    e.preventDefault();
                    if (!tab.pinned) closeTab(tab.id);
                }
            });
            btn.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                openContextMenu(e.clientX, e.clientY, tab.id);
            });

            strip.appendChild(btn);
        });

        if (countEl) countEl.textContent = state.tabs.length + '/' + MAX_TABS;
        updateMobileChrome();
    }

    function updateMobileChrome() {
        var active = findTab(state.activeId);
        if (mobileLabel) mobileLabel.textContent = active ? active.title : 'Dashboard';
        if (!mobileList) return;
        mobileList.innerHTML = '';
        state.tabs.forEach(function (tab) {
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'option');
            b.className = tab.id === state.activeId ? 'is-active' : '';
            b.textContent = tab.title + (tab.dirty ? ' •' : '');
            b.addEventListener('click', function () {
                activateTab(tab.id);
                mobileList.hidden = true;
                if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false');
            });
            mobileList.appendChild(b);
        });
    }

    function ensurePanel(tab) {
        var panel = document.getElementById('erp-tab-panel-' + tab.id);
        if (panel) return panel;

        panel = document.createElement('div');
        panel.className = 'erp-tab-panel';
        panel.id = 'erp-tab-panel-' + tab.id;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', 'erp-tab-btn-' + tab.id);

        var iframe = document.createElement('iframe');
        iframe.className = 'erp-tab-frame erp-shell-frame';
        iframe.id = 'erp-tab-frame-' + tab.id;
        iframe.name = 'erp-tab-frame-' + tab.id;
        iframe.setAttribute('data-tab-id', tab.id);
        iframe.loading = 'eager';
        iframe.referrerPolicy = 'same-origin';
        iframe.title = tab.title;
        iframe.src = absoluteUrl(withEmbed(tab.url));

        tab.loading = true;
        iframe.addEventListener('load', function () {
            tab.loading = false;
            renderStrip();
            checkSessionExpired(iframe);
        });

        panel.appendChild(iframe);
        panels.appendChild(panel);
        tab.iframe = iframe;
        return panel;
    }

    function checkSessionExpired(iframe) {
        try {
            var loc = iframe.contentWindow && iframe.contentWindow.location;
            if (!loc) return;
            var path = (loc.pathname || '').toLowerCase();
            if (path.indexOf('login') >= 0) {
                if (bannerEl) bannerEl.classList.add('is-visible');
                clearState();
                setTimeout(function () {
                    global.location.href = loc.href;
                }, 900);
            }
        } catch (e) {
            // cross-origin — ignore
        }
    }

    function syncPanels() {
        state.tabs.forEach(function (tab) {
            var panel = ensurePanel(tab);
            panel.classList.toggle('is-active', tab.id === state.activeId);
            var frame = document.getElementById('erp-tab-frame-' + tab.id);
            if (frame) frame.title = tab.title;
        });
        // Remove panels for closed tabs
        Array.prototype.slice.call(panels.children).forEach(function (panel) {
            var id = panel.id.replace('erp-tab-panel-', '');
            if (!findTab(id)) panel.remove();
        });
    }

    function activateTab(id) {
        var tab = findTab(id);
        if (!tab) return;
        if (state.activeId && state.activeId !== id) {
            state.lastActiveId = state.activeId;
        }
        state.activeId = id;
        renderStrip();
        syncPanels();
        saveState();
        setActiveNavFromTab(tab);
        var btn = document.getElementById('erp-tab-btn-' + id);
        if (btn) btn.focus();
    }

    function openTab(opts) {
        opts = opts || {};
        var title = String(opts.title || 'Page').trim() || 'Page';
        var url = withEmbed(opts.url || '');
        if (!url) return null;

        var existing = findTabByUrl(url);
        if (existing) {
            activateTab(existing.id);
            return existing;
        }

        if (!opts.pinned && state.tabs.length >= MAX_TABS) {
            toast('Maximum 8 tabs open. Close a tab to open another.');
            return null;
        }

        var docNo = extractDocNo(url);
        if (docNo) {
            var other = state.tabs.some(function (t) {
                return extractDocNo(t.url) === docNo;
            });
            if (other) {
                toast('Document ' + docNo + ' is already open in another tab.');
            }
        }

        var tab = {
            id: opts.id || nextId(),
            title: title,
            url: url,
            dirty: !!opts.dirty,
            pinned: !!opts.pinned,
            loading: true
        };

        var insertAt = state.tabs.length;
        if (state.activeId) {
            for (var i = 0; i < state.tabs.length; i++) {
                if (state.tabs[i].id === state.activeId) {
                    insertAt = i + 1;
                    break;
                }
            }
        }
        state.tabs.splice(insertAt, 0, tab);
        state.activeId = tab.id;
        renderStrip();
        syncPanels();
        saveState();
        setActiveNavFromTab(tab);
        return tab;
    }

    function closeTab(id, force) {
        var tab = findTab(id);
        if (!tab || tab.pinned) return;

        function doClose() {
            var idx = state.tabs.indexOf(tab);
            state.tabs.splice(idx, 1);
            if (state.activeId === id) {
                var left = state.tabs[idx - 1] || state.tabs[idx] || null;
                var fallback = findTab(state.lastActiveId);
                state.activeId = (fallback && fallback.id !== id ? fallback.id : null)
                    || (left ? left.id : (state.tabs[0] ? state.tabs[0].id : null));
            }
            renderStrip();
            syncPanels();
            saveState();
            setActiveNavFromTab(findTab(state.activeId));
        }

        if (!force && tab.dirty) {
            confirmDiscard(tab.title).then(function (ok) {
                if (ok) {
                    tab.dirty = false;
                    doClose();
                }
            });
            return;
        }
        doClose();
    }

    function closeOthers(id) {
        state.tabs.slice().forEach(function (t) {
            if (!t.pinned && t.id !== id) closeTab(t.id, false);
        });
    }

    function closeAllExceptDashboard() {
        state.tabs.slice().forEach(function (t) {
            if (!t.pinned) closeTab(t.id, false);
        });
    }

    function reloadTab(id) {
        var tab = findTab(id);
        if (!tab) return;
        var frame = document.getElementById('erp-tab-frame-' + tab.id);
        if (!frame) return;
        tab.loading = true;
        tab.dirty = false;
        renderStrip();
        try {
            frame.contentWindow.location.reload();
        } catch (e) {
            frame.src = absoluteUrl(withEmbed(tab.url));
        }
        saveState();
    }

    function openContextMenu(x, y, tabId) {
        ctxTabId = tabId;
        if (!ctxEl) return;
        var tab = findTab(tabId);
        ctxEl.innerHTML = '';
        function item(label, fn, disabled) {
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.textContent = label;
            if (disabled) {
                b.disabled = true;
                b.style.opacity = '0.45';
            } else {
                b.addEventListener('click', function () {
                    hideContextMenu();
                    fn();
                });
            }
            ctxEl.appendChild(b);
        }
        item('Close', function () { closeTab(tabId); }, !tab || tab.pinned);
        item('Close others', function () { closeOthers(tabId); });
        item('Close all', function () { closeAllExceptDashboard(); });
        item('Reload tab', function () { reloadTab(tabId); });
        ctxEl.hidden = false;
        ctxEl.style.left = Math.min(x, global.innerWidth - 180) + 'px';
        ctxEl.style.top = Math.min(y, global.innerHeight - 160) + 'px';
    }

    function hideContextMenu() {
        if (ctxEl) ctxEl.hidden = true;
        ctxTabId = null;
    }

    function handleMessage(event) {
        if (event.origin !== global.location.origin) return;
        var data = event.data;
        if (!data || typeof data !== 'object') return;

        var sourceTab = null;
        for (var i = 0; i < state.tabs.length; i++) {
            var frame = document.getElementById('erp-tab-frame-' + state.tabs[i].id);
            if (frame && frame.contentWindow === event.source) {
                sourceTab = state.tabs[i];
                break;
            }
        }

        var type = data.type;
        if (type === 'OPEN_TAB') {
            if (typeof data.url !== 'string' || !data.url) return;
            openTab({ title: String(data.title || 'Page'), url: data.url });
            return;
        }

        if (!sourceTab) return;

        if (type === 'DIRTY') {
            sourceTab.dirty = !!data.value;
            renderStrip();
            saveState();
            return;
        }
        if (type === 'SET_TITLE') {
            if (typeof data.title === 'string' && data.title.trim() !== '') {
                sourceTab.title = data.title.trim();
                renderStrip();
                saveState();
            }
            return;
        }
        if (type === 'CLOSE_SELF') {
            closeTab(sourceTab.id);
            return;
        }
        if (type === 'KEY') {
            handleShortcut({
                key: data.key,
                ctrlKey: data.ctrlKey,
                metaKey: data.metaKey,
                shiftKey: data.shiftKey,
                altKey: data.altKey,
                preventDefault: function () {},
                target: document.body
            });
        }
    }

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || !!el.isContentEditable;
    }

    function handleShortcut(event) {
        if (isTypingTarget(event.target)) return;
        var isMod = event.ctrlKey || event.metaKey;
        var tabs = state.tabs;
        if (!tabs.length) return;

        if (isMod && (event.key === 'w' || event.key === 'W')) {
            event.preventDefault();
            var active = findTab(state.activeId);
            if (active && !active.pinned) closeTab(active.id);
            return;
        }
        if (isMod && event.key === 'Tab') {
            event.preventDefault();
            var idx = -1;
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].id === state.activeId) { idx = i; break; }
            }
            if (idx < 0) return;
            var next = event.shiftKey
                ? tabs[(idx - 1 + tabs.length) % tabs.length]
                : tabs[(idx + 1) % tabs.length];
            activateTab(next.id);
            return;
        }
        if (event.altKey && event.key >= '1' && event.key <= '8') {
            event.preventDefault();
            var n = parseInt(event.key, 10) - 1;
            if (tabs[n]) activateTab(tabs[n].id);
        }
    }

    function handleStripKeys(event) {
        if (!strip || !strip.contains(event.target)) return;
        var tabs = state.tabs;
        var idx = -1;
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].id === state.activeId) { idx = i; break; }
        }
        if (idx < 0) return;
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            activateTab(tabs[(idx + 1) % tabs.length].id);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            activateTab(tabs[(idx - 1 + tabs.length) % tabs.length].id);
        } else if (event.key === 'Home') {
            event.preventDefault();
            activateTab(tabs[0].id);
        } else if (event.key === 'End') {
            event.preventDefault();
            activateTab(tabs[tabs.length - 1].id);
        }
    }

    function interceptLeaveLinks() {
        document.querySelectorAll('a[href]').forEach(function (link) {
            var key = link.getAttribute('data-nav-key') || '';
            var href = link.getAttribute('href') || '';
            var isBranch = key === 'branch' || /branchselection/i.test(href);
            var isLogout = key === 'logout' || /logout/i.test(href);
            if (!isBranch && !isLogout) return;
            if (link.getAttribute('data-erp-tabs-bound') === '1') return;
            link.setAttribute('data-erp-tabs-bound', '1');
            link.addEventListener('click', function (event) {
                var msg = isLogout
                    ? 'Log out and close all open tabs?'
                    : 'Change branch and close all open tabs?';
                if (state.tabs.length > 1 || anyDirty()) {
                    if (!global.confirm(msg)) {
                        event.preventDefault();
                        return;
                    }
                }
                clearState();
            });
        });
    }

    function boot() {
        host = $('erp-tab-host');
        if (!host) return;

        strip = $('erp-tab-strip');
        panels = $('erp-tab-panels');
        countEl = $('erp-tab-count');
        toastEl = $('erp-tab-toast');
        bannerEl = $('erp-tab-session-banner');
        ctxEl = $('erp-tab-ctx');
        mobileLabel = $('erp-tab-mobile-label');
        mobileToggle = $('erp-tab-mobile-toggle');
        mobileList = $('erp-tab-mobile-list');
        modalEl = $('erp-tab-modal');
        modalOk = $('erp-tab-modal-ok');
        modalCancel = $('erp-tab-modal-cancel');

        var dashUrl = host.getAttribute('data-dashboard-url') || withEmbed('dashboard');
        var dashTitle = host.getAttribute('data-dashboard-title') || 'Dashboard';

        var saved = loadState();
        if (saved && Array.isArray(saved.tabs) && saved.tabs.length) {
            state.tabs = saved.tabs.slice(0, MAX_TABS).map(function (t) {
                return {
                    id: t.id || nextId(),
                    title: t.title || 'Page',
                    url: withEmbed(t.url || dashUrl),
                    dirty: false, // never restore dirty across refresh
                    pinned: !!t.pinned
                };
            });
            // Ensure pinned dashboard exists first
            if (!state.tabs.some(function (t) { return t.pinned; })) {
                state.tabs.unshift({
                    id: nextId(),
                    title: dashTitle,
                    url: withEmbed(dashUrl),
                    dirty: false,
                    pinned: true
                });
            }
            state.activeId = saved.activeId && findTab(saved.activeId)
                ? saved.activeId
                : state.tabs[0].id;
        } else {
            state.tabs = [{
                id: nextId(),
                title: dashTitle,
                url: withEmbed(dashUrl),
                dirty: false,
                pinned: true
            }];
            state.activeId = state.tabs[0].id;
        }

        renderStrip();
        syncPanels();
        saveState();

        global.addEventListener('message', handleMessage);
        global.addEventListener('keydown', handleShortcut);
        if (strip) strip.addEventListener('keydown', handleStripKeys);
        document.addEventListener('click', function () { hideContextMenu(); });
        document.addEventListener('click', function (e) {
            if (mobileList && !mobileList.hidden && mobileToggle && !mobileToggle.contains(e.target) && !mobileList.contains(e.target)) {
                mobileList.hidden = true;
                mobileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        if (mobileToggle) {
            mobileToggle.addEventListener('click', function () {
                var open = mobileList.hidden;
                mobileList.hidden = !open;
                mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) updateMobileChrome();
            });
        }
        if (modalOk) modalOk.addEventListener('click', function () { closeModal(true); });
        if (modalCancel) modalCancel.addEventListener('click', function () { closeModal(false); });
        if (modalEl) {
            modalEl.addEventListener('click', function (e) {
                if (e.target === modalEl) closeModal(false);
            });
        }

        global.addEventListener('beforeunload', function (e) {
            if (!anyDirty()) return;
            e.preventDefault();
            e.returnValue = '';
        });

        interceptLeaveLinks();
    }

    var api = {
        open: openTab,
        close: closeTab,
        activate: activateTab,
        reload: reloadTab,
        toast: toast,
        clearPersisted: clearState,
        getState: function () {
            return { tabs: state.tabs.slice(), activeId: state.activeId };
        }
    };

    global.ErpShellTabs = api;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(window));
