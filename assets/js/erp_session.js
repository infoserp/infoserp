/**
 * Client idle logout (default 60 minutes). Max session lifetime is enforced server-side (12h).
 * On idle/max expiry, redirect top window to login with no message.
 */
(function () {
    'use strict';

    if (window.__erpSessionWatch) {
        return;
    }
    window.__erpSessionWatch = true;

    function meta(name, fallback) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return (el && el.content) ? el.content : fallback;
    }

    var idleMs = parseInt(meta('erp-session-idle', '3600'), 10);
    if (!idleMs || idleMs < 60) {
        idleMs = 3600;
    }
    idleMs = idleMs * 1000;

    var loginUrl = meta('erp-login-url', '/login');
    var touchUrl = meta('erp-session-touch', '');
    var storageKey = 'erp_last_activity';
    var lastBeacon = 0;
    var beaconEveryMs = 120000; // 2 minutes

    function touch() {
        try {
            localStorage.setItem(storageKey, String(Date.now()));
        } catch (e) { /* ignore */ }
    }

    function goLogin() {
        try {
            localStorage.removeItem(storageKey);
        } catch (e) { /* ignore */ }
        var target = loginUrl;
        try {
            if (window.top && window.top !== window) {
                window.top.location.replace(target);
                return;
            }
        } catch (e) { /* cross-origin — fall through */ }
        window.location.replace(target);
    }

    function check() {
        var last = 0;
        try {
            last = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0;
        } catch (e) {
            return;
        }
        if (!last) {
            touch();
            return;
        }
        if (Date.now() - last > idleMs) {
            goLogin();
        }
    }

    function beacon() {
        if (!touchUrl || !window.fetch) {
            return;
        }
        var now = Date.now();
        if (now - lastBeacon < beaconEveryMs) {
            return;
        }
        var last = 0;
        try {
            last = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0;
        } catch (e) {
            return;
        }
        // Only ping while user has been active recently
        if (!last || now - last > beaconEveryMs) {
            return;
        }
        lastBeacon = now;
        fetch(touchUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        }).catch(function () { /* ignore */ });
    }

    var events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'wheel'];
    for (var i = 0; i < events.length; i++) {
        document.addEventListener(events[i], function () {
            touch();
            beacon();
        }, { capture: true, passive: true });
    }

    window.addEventListener('storage', function (ev) {
        if (ev.key === storageKey) {
            check();
        }
    });

    touch();
    setInterval(function () {
        check();
        beacon();
    }, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            check();
        }
    });

    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function () {
            return origFetch.apply(this, arguments).then(function (res) {
                if (res.status === 401) {
                    var clone = res.clone();
                    clone.json().then(function (body) {
                        if (body && body.redirect) {
                            try {
                                if (window.top) {
                                    window.top.location.replace(body.redirect);
                                } else {
                                    window.location.replace(body.redirect);
                                }
                            } catch (e) {
                                window.location.replace(body.redirect);
                            }
                        }
                    }).catch(function () { /* not JSON */ });
                }
                return res;
            });
        };
    }
})();
