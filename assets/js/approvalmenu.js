/* Shared sidebar + async dashboard count loading */
(function () {
    'use strict';

    function erpBasePath() {
        var meta = document.querySelector('meta[name="erp-base"]');
        if (meta && meta.content) {
            var base = meta.content;
            return base.charAt(base.length - 1) === '/' ? base : base + '/';
        }
        return '/';
    }

    var COUNT_API = erpBasePath() + 'api/dashboard_counts';

    function withEmbed(href) {
        if (!href || href.indexOf('javascript:') === 0 || href.indexOf('#') === 0) {
            return href;
        }
        if (href.indexOf('embed=1') >= 0) {
            return href;
        }
        return href + (href.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    }

    /** Preserve ?embed=1 on same-page JS navigations (matches shared/toolbar.php). */
    function erpNavUrl(queryString) {
        var qs = String(queryString || '').replace(/^\?/, '');
        var url = window.location.pathname;
        if (qs) {
            url += '?' + qs;
        }
        if (window.location.search.indexOf('embed=1') >= 0 && url.indexOf('embed=1') < 0) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
        }
        return url;
    }

    function shouldPatchEmbeddedLinks() {
        var params = new URLSearchParams(window.location.search);
        return params.get('embed') === '1' || window.self !== window.top;
    }

    function patchEmbeddedLinks(root) {
        if (!shouldPatchEmbeddedLinks()) {
            return;
        }
        var scope = root && root.querySelectorAll ? root : document;
        var anchors = scope === document
            ? document.querySelectorAll('a[href]')
            : scope.querySelectorAll('a[href]');
        anchors.forEach(function (anchor) {
            var href = anchor.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('mailto:') === 0) return;
            if (href.indexOf('http://') === 0 || href.indexOf('https://') === 0) return;
            if (anchor.getAttribute('target') === '_blank') return;
            anchor.setAttribute('href', withEmbed(href));
        });
    }

    function normalizePath(pathname) {
        if (!pathname) return '/';
        var p = pathname.replace(/\/+$/, '');
        return p === '' ? '/' : p;
    }

    function initShellUrlLock() {
        var meta = document.querySelector('meta[name="erp-shell-url"]');
        if (!meta || !meta.content) return;

        var shellUrl;
        try {
            shellUrl = new URL(meta.content, window.location.origin);
        } catch (e) {
            return;
        }

        if (normalizePath(window.location.pathname) !== normalizePath(shellUrl.pathname)) {
            history.replaceState(null, document.title, shellUrl.pathname + shellUrl.search);
        }
    }

    function setActiveNav(key) {
        if (!key) return;
        document.querySelectorAll('#erp-sidebar .erp-nav-link').forEach(function (link) {
            var linkKey = link.getAttribute('data-nav-key') || '';
            link.classList.toggle('erp-nav-link--active', linkKey === key);
        });
        document.querySelectorAll('#erp-topnav [data-nav-key]').forEach(function (el) {
            var match = (el.getAttribute('data-nav-key') || '') === key;
            el.classList.toggle(el.classList.contains('erp-topnav-link') ? 'erp-topnav-link--active' : 'is-current', match);
        });
        document.querySelectorAll('#erp-topnav .erp-topnav-item').forEach(function (item) {
            var hasKey = Array.prototype.some.call(item.querySelectorAll('[data-nav-key]'), function (el) {
                return el.getAttribute('data-nav-key') === key;
            });
            item.classList.toggle('is-current', hasKey);
        });
    }

    function initTopNav() {
        var nav = document.getElementById('erp-topnav');
        if (!nav) return;
        var items = nav.querySelectorAll('.erp-topnav-item');

        function setOpen(item, open) {
            var trigger = item.querySelector('.erp-topnav-trigger');
            var panel   = item.querySelector('.erp-topnav-panel');
            item.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            if (open) {
                panel.style.left = '';
                var overflow = panel.getBoundingClientRect().right - (window.innerWidth - 16);
                if (overflow > 0) panel.style.left = (-overflow) + 'px';
            }
        }

        function closeAll(except) {
            items.forEach(function (item) {
                if (item !== except) setOpen(item, false);
            });
        }

        items.forEach(function (item) {
            var trigger = item.querySelector('.erp-topnav-trigger');
            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                var open = !item.classList.contains('is-open');
                closeAll(item);
                setOpen(item, open);
            });
            trigger.addEventListener('mouseenter', function () {
                if (!item.classList.contains('is-open') && nav.querySelector('.erp-topnav-item.is-open')) {
                    closeAll(item);
                    setOpen(item, true);
                }
            });
            item.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () { closeAll(null); });
            });
        });

        document.addEventListener('click', function (event) {
            if (!nav.contains(event.target)) closeAll(null);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAll(null);
        });
        // Clicks inside the content iframe never reach this document; the shell window blurs instead.
        window.addEventListener('blur', function () { closeAll(null); });
    }

    var loadingCounts = false;

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

    var MENU_GROUPS_KEY = 'erp_sidebar_open_groups';

    function initMenuGroups() {
        var groups = document.querySelectorAll('#erp-sidebar details.erp-nav-group[data-group-key]');
        if (!groups.length) return;

        var saved = [];
        try {
            saved = JSON.parse(window.localStorage.getItem(MENU_GROUPS_KEY) || '[]') || [];
        } catch (e) {
            saved = [];
        }

        groups.forEach(function (group) {
            if (saved.indexOf(group.getAttribute('data-group-key')) >= 0) {
                group.open = true;
            }
            group.addEventListener('toggle', function () {
                var open = [];
                groups.forEach(function (g) {
                    if (g.open) open.push(g.getAttribute('data-group-key'));
                });
                try {
                    window.localStorage.setItem(MENU_GROUPS_KEY, JSON.stringify(open));
                } catch (e) { /* storage unavailable */ }
            });
        });
    }

    function setBadgeContent(el, count, isError) {
        if (!el) return;

        var value = parseInt(count, 10);
        if (isNaN(value) || isError) {
            value = 0;
        }

        el.style.display = '';
        el.removeAttribute('title');

        if (el.classList.contains('dash-stat-count')) {
            el.innerHTML = '';
            if (isError) {
                el.textContent = '!';
                el.title = 'Could not load count';
                return;
            }
            el.textContent = String(value);
            return;
        }

        el.classList.remove('am-count-badge', 'erp-nav-badge', 'text-xs', 'font-bold', 'text-slate-300', 'am-count-zero', 'am-count-error');
        el.innerHTML = '';

        if (isError) {
            el.classList.add('am-count-error');
            el.textContent = '!';
            el.title = 'Could not load count';
            return;
        }

        if (value > 0) {
            el.classList.add('erp-nav-badge');
            el.textContent = String(value);
        } else if (el.closest('#erp-sidebar')) {
            el.style.display = 'none';
        } else {
            el.classList.add('text-xs', 'font-bold', 'text-slate-300', 'am-count-zero');
            el.textContent = '0';
        }
    }

    function applyCounts(data, isError) {
        var byKey = (data && data.by_key) ? data.by_key : {};

        document.querySelectorAll('[data-count-key]').forEach(function (el) {
            var key = el.getAttribute('data-count-key');
            if (!key) return;

            if (key === 'UNPAID') {
                setBadgeContent(el, data ? data.unpaid_invoices : 0, isError);
                return;
            }
            if (key === 'PROFORMA') {
                setBadgeContent(el, data ? data.proforma_pending : 0, isError);
                return;
            }

            setBadgeContent(el, byKey[key], isError);
        });

        if (typeof window.erpOnDashboardCountsLoaded === 'function') {
            window.erpOnDashboardCountsLoaded(data || { by_key: {} }, isError);
        }
    }

    function setRefreshSpinning(active) {
        var btn = document.getElementById('refresh-counts-btn');
        if (!btn) return;
        btn.classList.toggle('am-refresh-counts--spinning', active);
        btn.disabled = !!active;
    }

    function loadDashboardCounts(forceRefresh) {
        if (loadingCounts) return;
        loadingCounts = true;
        setRefreshSpinning(true);

        var url = COUNT_API + (forceRefresh ? '?refresh=1' : '');

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data || data.success === false) throw new Error('API error');
                applyCounts(data, false);
            })
            .catch(function (err) {
                console.error('Error fetching dashboard counts:', err);
                applyCounts(null, true);
            })
            .finally(function () {
                loadingCounts = false;
                setRefreshSpinning(false);
            });
    }

    function initRefreshButton() {
        var btn = document.getElementById('refresh-counts-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            loadDashboardCounts(true);
        });
    }

    function initIframeNavigation() {
        var frame = document.getElementById('erp-main-frame');
        if (!frame) return;

        document.querySelectorAll('a[data-iframe-nav="1"]').forEach(function (link) {
            link.setAttribute('target', 'erp-main-frame');
            link.addEventListener('click', function (event) {
                var href = link.getAttribute('href');
                if (!href || href.indexOf('javascript:') === 0) return;
                event.preventDefault();
                frame.src = withEmbed(href);
                setActiveNav(link.getAttribute('data-nav-key') || '');
                closeSidebarOnMobile();
            });
        });
    }

    function closeSidebarOnMobile() {
        document.body.classList.remove('sidebar-open');
    }

    function initEmbeddedLinks() {
        patchEmbeddedLinks(document);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initShellUrlLock();
        initSidebar();
        initMenuGroups();
        initTopNav();
        initRefreshButton();
        initIframeNavigation();
        initEmbeddedLinks();
        if (document.getElementById('erp-main-frame') || document.getElementById('refresh-counts-btn')) {
            loadDashboardCounts(false);
        }
    });

    window.erpLoadDashboardCounts = loadDashboardCounts;
    window.erpWithEmbed = withEmbed;
    window.erpNavUrl = erpNavUrl;
    window.erpPatchEmbeddedLinks = patchEmbeddedLinks;
}());
