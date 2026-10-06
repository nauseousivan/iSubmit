/* ============================================================================
   MCNP-ISAP Staff Portal — Shared behavior (portal.js)
   Light/Dark theme controller + macOS window manager (PortalWindows) driving
   the bottom dock. Loaded by every staff shell AND the admin_module iframe
   (it self-detects context). No workflow/JS review contract changes.
   ========================================================================== */
(function () {
    'use strict';

    /* --- Theme: Light + Dark only ---------------------------------------- */
    // Two independent choices. MODE: light / dark / auto (auto = follow the device, resolved to
    // theme-light|theme-dark on <body>, so every stylesheet still only knows those two).
    // COLOUR: lavender (no class) or one of ACCENTS, as body.acc-* (portal.css "COLOUR THEMES").
    var THEME_KEY = 'rd-portal-theme';
    var ACCENT_KEY = 'rd-portal-accent';
    var VALID = { 'theme-light': 1, 'theme-dark': 1, 'theme-auto': 1 };
    var ACCENTS = { lavender: 1, blue: 1, green: 1, rose: 1, amber: 1, teal: 1 };
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function ls(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    var PortalTheme = {
        get: function () {
            var v = ls(THEME_KEY);
            return VALID[v] ? v : 'theme-light';   // migrate legacy 7-theme values -> light
        },
        getAccent: function () {
            var a = ls(ACCENT_KEY);
            return ACCENTS[a] ? a : 'lavender';
        },
        resolve: function (name) {
            if (name !== 'theme-auto') return name;
            return (mq && mq.matches) ? 'theme-dark' : 'theme-light';
        },
        apply: function (name, accent) {
            name = VALID[name] ? name : this.get();
            accent = ACCENTS[accent] ? accent : this.getAccent();
            var b = document.body;
            b.className = b.className.replace(/\b(theme|acc)-[\w-]+\b/g, '').replace(/\s+/g, ' ').trim();
            b.classList.add(this.resolve(name));
            if (accent !== 'lavender') b.classList.add('acc-' + accent);
            this._syncControls(name, accent);
            this._syncFrames(name, accent);
        },
        set: function (name) { lsSet(THEME_KEY, name); this.apply(name); },
        setAccent: function (accent) { lsSet(ACCENT_KEY, accent); this.apply(this.get(), accent); },
        toggle: function () {
            var dark = document.body.classList.contains('theme-dark');
            this.set(dark ? 'theme-light' : 'theme-dark');
        },
        _syncControls: function (name, accent) {
            document.querySelectorAll('.seg-toggle button, [data-theme-choice]').forEach(function (b) {
                var v = b.dataset.themeChoice || b.dataset.theme;
                b.classList.toggle('active', v === name);
                b.setAttribute('aria-pressed', v === name ? 'true' : 'false');
            });
            document.querySelectorAll('[data-accent-choice]').forEach(function (b) {
                b.classList.toggle('active', b.dataset.accentChoice === accent);
                b.setAttribute('aria-pressed', b.dataset.accentChoice === accent ? 'true' : 'false');
            });
            var legacy = document.getElementById('user_theme_select');
            if (legacy) legacy.value = name;
        },
        _syncFrames: function (name, accent) {
            document.querySelectorAll('iframe').forEach(function (f) {
                if (f.contentWindow) { try { f.contentWindow.postMessage({ action: 'themeChanged', theme: name, accent: accent }, '*'); } catch (e) {} }
            });
        }
    };
    window.addEventListener('message', function (e) {
        if (e.data && e.data.action === 'themeChanged' && e.data.theme) PortalTheme.apply(e.data.theme, e.data.accent);
    });
    window.addEventListener('storage', function (e) {
        if (e.key === THEME_KEY || e.key === ACCENT_KEY) PortalTheme.apply();
    });
    // "Match device" follows the OS live, not just on load.
    if (mq) {
        var onScheme = function () { if (PortalTheme.get() === 'theme-auto') PortalTheme.apply(); };
        if (mq.addEventListener) mq.addEventListener('change', onScheme); else if (mq.addListener) mq.addListener(onScheme);
    }

    /* --- macOS window manager -------------------------------------------- */
    var wins = {};          // key -> { el, iframe, dockBtn }
    var seq = 0;

    /* A refresh used to drop every open window and land on the Dashboard. The open window is
       remembered per TAB (sessionStorage — survives a refresh, not a new tab), with the page it
       had navigated to inside the frame (a search, an opened group), and reopened on boot.
       Keyed to the shell's own path so a different role's shell in the same tab never inherits
       it. Going back to the Dashboard forgets it. */
    var OPEN_KEY = 'rd-portal-open';
    function remember(key, src, title, icon) {
        try {
            sessionStorage.setItem(OPEN_KEY, JSON.stringify({
                shell: location.pathname, key: key, src: src || key, title: title, icon: icon
            }));
        } catch (e) {}
    }
    function forget() { try { sessionStorage.removeItem(OPEN_KEY); } catch (e) {} }
    function recalled() {
        try {
            var s = JSON.parse(sessionStorage.getItem(OPEN_KEY) || 'null');
            return (s && s.shell === location.pathname && s.key) ? s : null;
        } catch (e) { return null; }
    }
    // Which SCREEN a module URL is: the file plus its phase and view. Everything else in the query
    // (search, group, batch, from) is a place WITHIN that screen.
    function screenOf(p) {
        if (!p) return '';
        var q = p.split('?'), prm = {};
        (q[1] || '').split('&').forEach(function (kv) { var a = kv.split('='); if (a[0]) prm[a[0]] = decodeURIComponent(a[1] || ''); });
        var file = q[0].split('/').pop();
        // Only admin_module_dynamic.php uses ?view= for different SCREENS (schedule, pipeline,
        // history...). Elsewhere (Approvals' published / returned) it is a tab of the same screen.
        return file + '|' + (prm.phase || '') + '|' + (file === 'admin_module_dynamic.php' ? (prm.view || '') : '');
    }
    // The frame's current page as a path relative to /dashboards/, or null when it is not ours.
    function framePath(iframe) {
        try {
            var l = iframe.contentWindow.location;
            if (l.origin !== location.origin || l.href === 'about:blank') return null;
            return l.pathname.split('/').pop() + l.search;
        } catch (e) { return null; }
    }

    function layer() {
        var l = document.getElementById('windowLayer');
        if (!l) {
            l = document.createElement('div');
            l.className = 'window-layer';
            l.id = 'windowLayer';
            document.body.appendChild(l);
        }
        return l;
    }

    var PortalWindows = {
        open: function (key, url, title, icon, dockBtn) {
            var w = wins[key];
            if (!w) w = this._build(key, url, title, icon);
            if (dockBtn) w.dockBtn = dockBtn;
            if (url && !w._loaded) { w.iframe.src = url; w._loaded = true; }
            // A window can wander: Approvals' "Log slots and publish" walks its frame over to the
            // Scheduling screen. Clicking the Approvals card again used to just show wherever it
            // had wandered to, and only a reload got you back. A CLICK (dockBtn given) on a window
            // now on a different SCREEN takes it home. Same screen — a search, an opened group —
            // is left exactly where you were.
            else if (url && dockBtn && screenOf(framePath(w.iframe)) !== screenOf(key)) { w.iframe.src = url; }
            // Show only this window; keep the others' state (multitask switch).
            Object.keys(wins).forEach(function (k) {
                if (k !== key) { wins[k].el.classList.remove('active', 'shown'); }
            });
            w.el.classList.remove('minimized');
            w.el.classList.add('active');
            void w.el.offsetWidth;               // reflow so the transition runs
            w.el.classList.add('shown');
            this._setDock(w.dockBtn);
            w.title = title; w.icon = icon;
            remember(key, framePath(w.iframe) || url || key, title, icon);
            // Lets a shell light the rail for the card that opened this (staff home, data-phase).
            try { document.dispatchEvent(new CustomEvent('portal:open', { detail: { key: key, btn: w.dockBtn } })); } catch (e) {}
            if (window.lucide) lucide.createIcons();
        },
        minimize: function (key) {                // back to the dock, state preserved
            var w = wins[key]; if (!w) return;
            w.el.classList.remove('shown');
            setTimeout(function () { if (!w.el.classList.contains('shown')) w.el.classList.remove('active'); }, 300);
            this._home();
        },
        close: function (key) {                   // discard: reset the iframe
            var w = wins[key]; if (!w) return;
            w.el.classList.remove('shown');
            setTimeout(function () {
                if (w.el.classList.contains('shown')) return;   // re-opened during the fade — abort teardown
                w.el.classList.remove('active', 'expanded');
                w.iframe.src = 'about:blank'; w._loaded = false;
            }, 300);
            this._home();
        },
        expand: function (key) { var w = wins[key]; if (w) w.el.classList.toggle('expanded'); },
        collapseAll: function () {
            forget();
            Object.keys(wins).forEach(function (k) {
                var w = wins[k];
                w.el.classList.remove('shown');
                setTimeout(function () {
                    if (!w.el.classList.contains('shown')) w.el.classList.remove('active');   // don't hide a re-opened window
                }, 300);
            });
        },
        _home: function () {
            forget();
            var home = document.querySelector('.dock-btn[data-view="home"]');
            if (home) this._setDock(home);
        },
        _setDock: function (btn) {
            document.querySelectorAll('.dock-btn').forEach(function (b) { b.classList.remove('active'); });
            if (btn) btn.classList.add('active');
        },
        _build: function (key, url, title, icon) {
            var el = document.createElement('div');
            el.className = 'app-window';
            el.id = 'win_' + (++seq);
            el.setAttribute('data-key', key);
            var safe = String(key).replace(/'/g, "\\'");
            // No window chrome — the module fills the whole area right of the side rail.
            // Navigation back to the dashboard is via the sidebar (which collapses open windows).
            el.innerHTML = '<iframe class="window-frame"></iframe>';
            layer().appendChild(el);
            var w = { el: el, iframe: el.querySelector('iframe'), dockBtn: null, _loaded: false };
            wins[key] = w;
            // Follow navigation INSIDE the window (search, filters, opening a group, a POST that
            // re-renders), so a refresh returns to that page and not the module's front door.
            // Only while this is the window on screen — a background window must not overwrite.
            w.iframe.addEventListener('load', function () {
                if (!w.el.classList.contains('shown')) return;
                var p = framePath(w.iframe);
                if (p) remember(key, p, w.title, w.icon);
            });
            return w;
        }
    };

    // Reopen what was open before the refresh. Shell only: inside a module iframe there is no
    // dashboard to restore to. The nav button is found by the URL it opens, so the window is
    // built under the SAME key a later click uses — a click re-focuses it instead of opening
    // a duplicate — and that button lights up as active.
    function restoreWindow() {
        if (window.top !== window) return;
        if (!document.querySelector('.dock-btn[data-view="home"]')) return;
        var s = recalled();
        if (!s) return;
        var btn = null;
        document.querySelectorAll('.nav-item-btn[onclick]').forEach(function (b) {
            if (!btn && b.getAttribute('onclick').indexOf("'" + s.key + "'") !== -1) btn = b;
        });
        PortalWindows.open(s.key, s.src, s.title || 'Module', s.icon || 'app-window', btn);
    }

    /* --- Paint the right theme from the FIRST frame (2026-10-01) ------------
       The theme class used to land on <body> only at DOMContentLoaded, so a dark-mode
       reload painted the light page first: the white flicker. This script runs in <head>,
       so: (1) give <html> the dark canvas + colour scheme right now, before anything
       paints, and (2) put the classes on <body> the moment the parser creates it. */
    (function early() {
        var de = document.documentElement, dark = PortalTheme.resolve(PortalTheme.get()) === 'theme-dark';
        var CANVAS = { lavender: '#16121f', blue: '#10141f', green: '#0f1813', rose: '#1c1117', amber: '#1a150d', teal: '#0e1716' };   // = portal.css dark --canvas
        de.style.colorScheme = dark ? 'dark' : 'light';
        de.style.backgroundColor = dark ? CANVAS[PortalTheme.getAccent()] || CANVAS.lavender : '';
        if (document.body) return;
        var mo = new MutationObserver(function () { if (document.body) { mo.disconnect(); PortalTheme.apply(); } });
        mo.observe(de, { childList: true });
    })();
    // keep <html> in step when the viewer switches theme or accent later
    var _apply = PortalTheme.apply;
    PortalTheme.apply = function (name, accent) {
        _apply.call(this, name, accent);
        var dark = document.body && document.body.classList.contains('theme-dark');
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        document.documentElement.style.backgroundColor = dark && document.body ? getComputedStyle(document.body).getPropertyValue('--canvas').trim() : '';
    };

    /* --- Boot ------------------------------------------------------------ */
    function boot() { PortalTheme.apply(); restoreWindow(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    /* --- Public API ------------------------------------------------------ */
    window.PortalTheme = PortalTheme;
    window.PortalWindows = PortalWindows;
    window.setPortalTheme = function (n) { PortalTheme.set(n); };
    window.togglePortalTheme = function () { PortalTheme.toggle(); };

    /* --- Dock avatar dropdown -------------------------------------------- */
    window.toggleDockMenu = function (id, ev) {
        if (ev) ev.stopPropagation();
        var m = document.getElementById(id);
        if (!m) return;
        var wasOpen = m.classList.contains('open');
        document.querySelectorAll('.dock-dropdown.open').forEach(function (x) { x.classList.remove('open'); });
        if (!wasOpen) m.classList.add('open');
    };
    document.addEventListener('click', function () {
        document.querySelectorAll('.dock-dropdown.open').forEach(function (m) { m.classList.remove('open'); });
    });
})();
