/**
 * Child-page helper for the INFOS ERP multi-tab shell.
 * Falls back safely when the page is not inside a tabbed shell.
 */
(function (global) {
    'use strict';

    function hasParent() {
        try {
            return !!(global.parent && global.parent !== global);
        } catch (e) {
            return false;
        }
    }

    function parentHasTabs() {
        try {
            return !!(hasParent() && global.parent.ErpShellTabs);
        } catch (e) {
            return false;
        }
    }

    function post(payload) {
        if (!hasParent()) return false;
        try {
            // Always post when framed — shell listener accepts OPEN_TAB even if
            // this helper cannot read parent.ErpShellTabs for some reason.
            global.parent.postMessage(payload, global.location.origin);
            return true;
        } catch (e) {
            return false;
        }
    }

    function withEmbed(url) {
        var href = String(url || '');
        if (!href || href.indexOf('javascript:') === 0 || href.charAt(0) === '#') return href;
        if (href.indexOf('embed=1') >= 0) return href;
        return href + (href.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    }

    var ErpTabs = {
        inShell: function () {
            return parentHasTabs();
        },

        open: function (title, url) {
            var href = withEmbed(url);
            if (!href) return;

            // Prefer shell multi-tab open
            if (hasParent()) {
                post({ type: 'OPEN_TAB', title: String(title || 'Page'), url: href });
                if (parentHasTabs()) {
                    return;
                }
                // Inside an iframe but shell tabs JS missing/outdated:
                // never navigate this frame (would replace the report tab content).
                try {
                    global.open(href, '_blank');
                } catch (e) {
                    global.top.location.href = href;
                }
                return;
            }

            // Top-level page (not in shell)
            global.location.href = href;
        },

        setDirty: function (value) {
            post({ type: 'DIRTY', value: !!value });
        },

        setTitle: function (title) {
            post({ type: 'SET_TITLE', title: String(title || '') });
        },

        closeSelf: function () {
            if (!post({ type: 'CLOSE_SELF' })) {
                if (global.history.length > 1) global.history.back();
            }
        }
    };

    // Forward shell shortcuts from iframe focus to parent
    global.addEventListener('keydown', function (event) {
        if (!ErpTabs.inShell()) return;
        var tag = (event.target && event.target.tagName) ? event.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (event.target && event.target.isContentEditable)) {
            return;
        }
        var isMod = event.ctrlKey || event.metaKey;
        var forward =
            (isMod && (event.key === 'w' || event.key === 'W' || event.key === 'Tab')) ||
            (event.altKey && event.key >= '1' && event.key <= '8');
        if (!forward) return;
        post({
            type: 'KEY',
            key: event.key,
            ctrlKey: !!event.ctrlKey,
            metaKey: !!event.metaKey,
            shiftKey: !!event.shiftKey,
            altKey: !!event.altKey
        });
        event.preventDefault();
    }, true);

    global.ErpTabs = ErpTabs;
}(window));
