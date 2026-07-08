/* ============================================================================
   MCNP-ISAP Staff Portal — Shared behavior (portal.js)
   Light/Dark theme controller + macOS window manager (PortalWindows) driving
   the bottom dock. Loaded by every staff shell AND the admin_module iframe
   (it self-detects context). No workflow/JS review contract changes.
   ========================================================================== */
(function () {
    'use strict';

    /* --- Theme: Light + Dark only ---------------------------------------- */
    var THEME_KEY = 'rd-portal-theme';
    var VALID = { 'theme-light': 1, 'theme-dark': 1 };

    var PortalTheme = {
        get: function () {
            var v = localStorage.getItem(THEME_KEY);
            return VALID[v] ? v : 'theme-light';   // migrate legacy 7-theme values -> light
        },
        apply: function (name) {
            name = VALID[name] ? name : this.get();
            document.body.className = document.body.className.replace(/\btheme-[\w-]+\b/g, '').trim();
            document.body.classList.add(name);
            this._syncControls(name);
            this._syncFrames(name);
        },
        set: function (name) { localStorage.setItem(THEME_KEY, name); this.apply(name); },
        toggle: function () { this.set(this.get() === 'theme-dark' ? 'theme-light' : 'theme-dark'); },
        _syncControls: function (name) {
            var dark = (name === 'theme-dark');
            document.querySelectorAll('.seg-toggle button').forEach(function (b) {
                b.classList.toggle('active', b.dataset.theme === name);
            });
            var legacy = document.getElementById('user_theme_select');
            if (legacy) legacy.value = name;
        },
        _syncFrames: function (name) {
            document.querySelectorAll('iframe').forEach(function (f) {
                if (f.contentWindow) { try { f.contentWindow.postMessage({ action: 'themeChanged', theme: name }, '*'); } catch (e) {} }
            });
        }
    };
    window.addEventListener('message', function (e) {
        if (e.data && e.data.action === 'themeChanged' && e.data.theme) PortalTheme.apply(e.data.theme);
    });
    window.addEventListener('storage', function (e) {
        if (e.key === THEME_KEY && e.newValue) PortalTheme.apply(e.newValue);
    });

    /* --- macOS window manager -------------------------------------------- */
    var wins = {};          // key -> { el, iframe, dockBtn }
    var seq = 0;

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
            // Show only this window; keep the others' state (multitask switch).
            Object.keys(wins).forEach(function (k) {
                if (k !== key) { wins[k].el.classList.remove('active', 'shown'); }
            });
            w.el.classList.remove('minimized');
            w.el.classList.add('active');
            void w.el.offsetWidth;               // reflow so the transition runs
            w.el.classList.add('shown');
            this._setDock(w.dockBtn);
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
                w.el.classList.remove('active', 'expanded');
                w.iframe.src = 'about:blank'; w._loaded = false;
            }, 300);
            this._home();
        },
        expand: function (key) { var w = wins[key]; if (w) w.el.classList.toggle('expanded'); },
        collapseAll: function () {
            Object.keys(wins).forEach(function (k) {
                wins[k].el.classList.remove('shown');
                setTimeout(function () { wins[k].el.classList.remove('active'); }, 300);
            });
        },
        _home: function () {
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
            el.innerHTML =
                '<div class="window-titlebar">' +
                    '<div class="traffic-lights">' +
                        '<button class="traffic-light red" title="Close" onclick="PortalWindows.close(\'' + safe + '\')">&times;</button>' +
                        '<button class="traffic-light yellow" title="Minimize" onclick="PortalWindows.minimize(\'' + safe + '\')">&minus;</button>' +
                        '<button class="traffic-light green" title="Expand" onclick="PortalWindows.expand(\'' + safe + '\')">+</button>' +
                    '</div>' +
                    '<div class="window-title"><i data-lucide="' + (icon || 'app-window') + '"></i><span></span></div>' +
                '</div>' +
                '<iframe class="window-frame"></iframe>';
            el.querySelector('.window-title span').textContent = title || 'Module';
            layer().appendChild(el);
            var w = { el: el, iframe: el.querySelector('iframe'), dockBtn: null, _loaded: false };
            wins[key] = w;
            return w;
        }
    };

    /* --- Boot ------------------------------------------------------------ */
    function boot() { PortalTheme.apply(); }
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
