/* =============================================================================
   holo_app.js — the Visualizer SCREEN around holo.js (2026-10-01).

   Shared by the Admin's Visualizer (admin_visualize.php) and the office's
   (office_visualize.php). holo.js draws one 3D view; this is everything around it:
     colours     read from portal.css (the viewer's light/dark mode AND accent),
                 re-read live when they switch it — never a fixed palette.
     pop-outs    a draggable window with its own small Holo, opened beside the
                 thing clicked and tied back to it by a live wire. Pop-outs open
                 pop-outs; at most MAX at once (the oldest closes); a closed
                 pop-out takes its children with it.
     tooltip     one floating card for every Holo on the page.
     telemetry   the side panel, ADJUSTABLE: each block can be hidden, the panel
                 collapsed or dragged. Remembered per screen in localStorage.
     controls    the month picker (grouped by year, plus 'All of YYYY') and the
                 institution switch. A period is 'YYYY-MM' or 'YYYY': match with
                 HoloApp.inPeriod(ym, F.ym), never ===.

     HoloApp.ready(function () { ... });   // after portal.js has put the theme on <body>
     var app = new HoloApp({ key: 'admin', onTheme: fn(pal), tipOf: fn(item) -> html,
                             opens: fn(item) -> bool, open: fn(item, srcHolo) });
     var main = new Holo(canvas, { ..., onHover: function (it, x, y) { app.tip(it, x, y, main.c); },
                                   onClick: app.open, after: app.wires });
     app.pod(item, srcHolo, { kind, title, sub, build: fn(pod) });   // build calls app.ready(...)
     app.tele([{ id, title, html }]);
   ========================================================================== */
(function (W) {
    'use strict';
    var MAX = 4;
    function $(id) { return document.getElementById(id); }
    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function store(k, v) {
        try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; }
    }

    function HoloApp(o) {
        var self = this;
        this.o = o; this.pods = []; this.z = 20; this.key = 'rd-holo-' + (o.key || 'main');
        this.pal = HoloApp.palette(); this.theme = this.pal.holo;
        // portal.js swaps body.theme-* / body.acc-* when the viewer changes it (here, in the shell,
        // or in another tab): re-read the colours and let the page rebuild its scene.
        var last = document.body.className;
        new MutationObserver(function () {
            if (document.body.className === last) return;
            last = document.body.className;
            self.pal = HoloApp.palette(); self.theme = self.pal.holo;
            self.pods.forEach(function (p) { p.holo.setTheme(self.theme); });
            if (o.onTheme) o.onTheme(self.pal);
        }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
        this.wires = function () { self._wires(); };
        this.open = function (it, src) { if (o.open) o.open(it, src); };
        W.addEventListener('resize', function () { self.pods.forEach(function (p) { p.holo.resize(); }); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self.pods.length) self.close(self.pods[self.pods.length - 1]); });
    }
    HoloApp.esc = esc;
    HoloApp.ready = function (fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); };
    HoloApp.inPeriod = function (ym, p) { return !p || String(ym || '').indexOf(p) === 0; };
    HoloApp.periodLabel = function (p, months) {
        if (!p) return 'All time';
        if (p.length === 4) return p;
        return (months.filter(function (m) { return m.ym === p; })[0] || {}).label || p;
    };
    // The last n CALENDAR months, oldest first — a quiet month still gets its (empty) bar.
    HoloApp.lastMonths = function (n) {
        var out = [], d = new Date();
        d.setDate(1);
        for (var i = 0; i < n; i++) {
            out.unshift({ ym: d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2), label: d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) });
            d.setMonth(d.getMonth() - 1);
        }
        return out;
    };
    // The viewer's palette, out of portal.css: mode, accent, ink, surface — as the canvas needs them.
    HoloApp.palette = function () {
        var b = document.body, dark = b.classList.contains('theme-dark'), probe = document.createElement('i');
        probe.style.display = 'none'; b.appendChild(probe);
        function get(v, fb) {
            probe.style.color = ''; probe.style.color = 'var(' + v + ', ' + fb + ')';
            var m = (getComputedStyle(probe).color.match(/[\d.]+/g) || [0, 0, 0]).slice(0, 3).map(Number);
            return m.map(Math.round);
        }
        var acc = get('--accent', dark ? '#a78bfa' : '#6d28d9'), ink = get('--ink', dark ? '#ede9fe' : '#4c1d95'), sur = get('--surface', dark ? '#1e1830' : '#ffffff');
        probe.remove();
        var hot = dark ? [251, 191, 36] : [180, 83, 9];   // = --hx-hot in holo.css
        function rgba(c, a) { return 'rgba(' + c.join(',') + ',' + a + ')'; }
        return {
            dark: dark, accent: acc, ink: ink, surface: sur,
            // n tones from the ink to the accent: staff roles, so they wear the viewer's colour
            tones: function (n) {
                var out = [];
                for (var i = 0; i < n; i++) { var t = n === 1 ? 1 : i / (n - 1); out.push('rgb(' + ink.map(function (v, k) { return Math.round(v + (acc[k] - v) * t); }).join(',') + ')'); }
                return out;
            },
            holo: {
                disc: acc.join(','), line: acc.join(','), node: 'rgb(' + ink.join(',') + ')',
                hot: 'rgb(' + hot.join(',') + ')', hotRgb: hot.join(','), hotBg: dark ? 'rgba(30,20,4,0.9)' : 'rgba(255,248,235,0.95)',
                text: rgba(ink, 0.95), layerText: rgba(acc, dark ? 0.8 : 0.85), labelBg: rgba(sur, dark ? 0.8 : 0.9), glow: dark
            }
        };
    };
    HoloApp.plural = function (n, w, ws) { return n + ' ' + (n === 1 ? w : (ws || w + 's')); };
    HoloApp.ring = function (n, r, y, i, off) { var a = Math.PI * 2 * i / Math.max(1, n) + (off || 0); return { x: Math.cos(a) * r, y: y, z: Math.sin(a) * r, a: a }; };
    HoloApp.abbr = function (program) {
        var w = String(program || '').split(/\s+/), out = '';
        w.forEach(function (x, i) { if (i === 0 && /^[A-Z]{2,4}$/.test(x)) out += x; else if (/^\d/.test(x)) out += x.replace(/\D.*/, ''); else if (!/^(of|and|in|the|&)$/i.test(x)) out += x.charAt(0).toUpperCase(); });
        return out || program;
    };
    // A bar height for a count, readable from 1 to a few hundred (square root, so one huge course
    // does not flatten every other bar), scaled to the largest value on screen.
    HoloApp.height = function (v, max, top) { return v ? 8 + (top || 170) * Math.sqrt(v / Math.max(1, max)) : 2; };

    /* ---- tooltip ---------------------------------------------------------------------------- */
    HoloApp.prototype.tip = function (it, mx, my, canvas) {
        var el = $('avTip');
        if (!it) { el.hidden = true; return; }
        var h = it.tip || (this.o.tipOf ? this.o.tipOf(it) : '') || '<b>' + esc(it.label || '') + '</b>';
        el.innerHTML = h + (this.o.opens && this.o.opens(it) ? '<span class="av-tip-go">Click to open</span>' : '');
        var r = canvas.getBoundingClientRect(), x = r.left + mx + 16, y = r.top + my + 16;
        el.hidden = false;
        el.style.left = Math.max(8, Math.min(x, innerWidth - el.offsetWidth - 12)) + 'px';
        el.style.top = Math.max(8, Math.min(y, innerHeight - el.offsetHeight - 12)) + 'px';
    };

    /* ---- pop-outs ---------------------------------------------------------------------------- */
    HoloApp.prototype.pod = function (it, src, spec) {
        var self = this, key = spec.key || (spec.kind + ':' + spec.title);
        var ex = this.pods.filter(function (p) { return p.key === key; })[0];
        if (ex) { this.front(ex); return ex; }
        if (this.pods.length >= MAX) this.close(this.pods[0]);
        var el = document.createElement('section');
        el.className = 'av-pod';
        el.innerHTML = '<header class="av-pod-h"><span class="av-pod-k">' + esc(spec.kind) + '</span><span class="av-pod-t"><b>' + esc(spec.title) + '</b><small>' + esc(spec.sub || '') +
            '</small></span><button type="button" class="av-pod-x" aria-label="Close"><i data-lucide="x"></i></button></header>' +
            '<div class="av-pod-c"><canvas></canvas><div class="av-pod-load">SCANNING</div></div><dl class="av-pod-r"></dl><div class="av-pod-legend"></div>';
        $('avPods').appendChild(el);

        var sr = src.c.getBoundingClientRect(), ax = sr.left + (it._p ? it._p.x : sr.width / 2), ay = sr.top + (it._p ? it._p.y : sr.height / 2);
        var w = el.offsetWidth || 400, hgt = el.offsetHeight || 470, spot = this.place(w, hgt, ax, ay);
        el.style.left = spot[0] + 'px'; el.style.top = spot[1] + 'px';
        el.style.setProperty('--ox', (ax - spot[0]) + 'px'); el.style.setProperty('--oy', (ay - spot[1]) + 'px');

        var pod = { key: key, el: el, item: it, src: src };
        pod.holo = new Holo(el.querySelector('canvas'), {
            theme: this.theme, fitW: 540, fitH: 560, spin: .004, pitch: .42, yMid: 20,
            related: function (x) { return [x].concat((x.rel || []).filter(Boolean)); },
            label: function (x) { return x.always || null; },
            onHover: function (x, mx, my) { self.tip(x, mx, my, pod.holo.c); },
            onClick: function (x, h) { if (self.o.opens && self.o.opens(x)) self.open(x, h); }
        });
        src.pin(it);
        this.pods.push(pod);
        this.front(pod);
        el.querySelector('.av-pod-x').addEventListener('click', function () { self.close(pod); });
        el.addEventListener('pointerdown', function () { self.front(pod); });
        this.drag(el, el.querySelector('.av-pod-h'));
        requestAnimationFrame(function () { el.classList.add('in'); });
        setTimeout(function () { pod.holo.resize(); }, 420);
        if (W.lucide) lucide.createIcons();
        spec.build(pod);
        return pod;
    };
    HoloApp.prototype.ready = function (pod, model, reads, legend) {
        var ld = pod.el.querySelector('.av-pod-load'); if (ld) ld.remove();
        pod.holo.set(model);
        pod.el.querySelector('.av-pod-r').innerHTML = reads.map(function (r) { return '<div class="' + (r[2] ? 'wide' : '') + '"><dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd></div>'; }).join('');
        pod.el.querySelector('.av-pod-legend').innerHTML = (legend || []).map(function (l) { return '<span><i style="background:' + l[1] + '"></i>' + esc(l[0]) + '</span>'; }).join('');
    };
    // Where a pop-out goes: the spot with the least overlap with the other pop-outs and the
    // telemetry panel, preferring close beside the item that opened it. offset* sizes, because
    // a pop-out is still scaled down while it animates in.
    HoloApp.prototype.place = function (w, h, ax, ay) {
        var avoid = this.pods.map(function (p) { var e = p.el; return { left: e.offsetLeft, top: e.offsetTop, right: e.offsetLeft + e.offsetWidth, bottom: e.offsetTop + e.offsetHeight }; });
        var tele = document.querySelector('.av-tele'); if (tele && tele.offsetParent) avoid.push(tele.getBoundingClientRect());
        function clamp(c) { return [Math.max(16, Math.min(innerWidth - w - 16, c[0])), Math.max(70, Math.min(innerHeight - h - 16, c[1]))]; }
        var cands = [[ax + 50, ay - h / 2], [ax - 50 - w, ay - h / 2], [ax + 50, ay - h - 10], [ax - 50 - w, ay + 10], [ax + 50, ay + 10], [ax - 50 - w, ay - h - 10]];
        for (var yy = 70; yy < innerHeight - h; yy += 48) for (var xx = 16; xx < innerWidth - w - 16; xx += 48) cands.push([xx, yy]);
        var best = null, bs = Infinity;
        cands.forEach(function (c, i) {
            c = clamp(c);
            var ov = 0;
            avoid.forEach(function (r) {
                var ox = Math.max(0, Math.min(c[0] + w, r.right) - Math.max(c[0], r.left)), oy = Math.max(0, Math.min(c[1] + h, r.bottom) - Math.max(c[1], r.top));
                ov += ox * oy;
            });
            var score = ov * 10 + Math.hypot(c[0] + w / 2 - ax, c[1] + h / 2 - ay) + (i < 6 ? 0 : 120);
            if (score < bs) { bs = score; best = c; }
        });
        return best;
    };
    HoloApp.prototype.front = function (pod) { this.pods.forEach(function (p) { p.el.classList.remove('top'); }); pod.el.style.zIndex = ++this.z; pod.el.classList.add('top'); };
    HoloApp.prototype.close = function (pod) {
        var self = this;
        this.pods.filter(function (p) { return p.src === pod.holo; }).forEach(function (p) { self.close(p); });
        var i = this.pods.indexOf(pod); if (i === -1) return;
        this.pods.splice(i, 1);
        if (pod.src.pinned === pod.item) pod.src.pin(null);
        pod.holo.destroy();
        pod.el.classList.remove('in');
        setTimeout(function () { pod.el.remove(); }, 260);
    };
    HoloApp.prototype.closeAll = function () { var self = this; this.pods.slice().forEach(function (p) { self.close(p); }); };
    HoloApp.prototype.drag = function (el, handle, onEnd) {
        var s = null;
        handle.addEventListener('pointerdown', function (e) {
            if (e.target.closest('button,select,input,label')) return;
            s = { x: e.clientX, y: e.clientY, l: el.offsetLeft, t: el.offsetTop }; handle.setPointerCapture(e.pointerId);
        });
        handle.addEventListener('pointermove', function (e) {
            if (!s) return;
            el.style.left = Math.max(0, Math.min(innerWidth - 120, s.l + e.clientX - s.x)) + 'px';
            el.style.top = Math.max(0, Math.min(innerHeight - 50, s.t + e.clientY - s.y)) + 'px';
            el.style.right = 'auto';
        });
        handle.addEventListener('pointerup', function () { if (s && onEnd) onEnd(); s = null; });
    };

    // The live wires: each pop-out tied back to the item that opened it, wherever both have moved.
    HoloApp.prototype._wires = function () {
        var c = $('avWires'), dpr = W.devicePixelRatio || 1, Wd = c.clientWidth, H = c.clientHeight;
        if (c.width !== Math.round(Wd * dpr)) { c.width = Math.round(Wd * dpr); c.height = Math.round(H * dpr); }
        var ctx = c.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, Wd, H);
        var t = performance.now(), T = this.theme;
        this.pods.forEach(function (p) {
            if (!p.item._p || !p.el.classList.contains('in')) return;
            var sr = p.src.c.getBoundingClientRect(), ax = sr.left + p.item._p.x, ay = sr.top + p.item._p.y;
            var r = p.el.getBoundingClientRect(), bx = ax < r.left ? r.left : (ax > r.right ? r.right : r.left), by = Math.max(r.top + 20, Math.min(r.bottom - 20, ay));
            ctx.beginPath(); ctx.moveTo(ax, ay); ctx.lineTo(bx, by);
            ctx.setLineDash([6, 5]); ctx.lineDashOffset = -t / 40;
            ctx.strokeStyle = 'rgba(' + T.hotRgb + ',0.75)'; ctx.lineWidth = 1.5; ctx.stroke(); ctx.setLineDash([]);
            [[ax, ay], [bx, by]].forEach(function (q) { ctx.beginPath(); ctx.arc(q[0], q[1], 3.5, 0, Math.PI * 2); ctx.fillStyle = T.hot; ctx.fill(); });
        });
    };

    /* ---- telemetry: adjustable --------------------------------------------------------------- */
    // blocks: [{ id, title, html }]. The viewer hides any block, collapses the panel or drags it;
    // all three are remembered for this screen. A hidden block is still listed under Adjust.
    HoloApp.prototype.tele = function (blocks) {
        var self = this, el = document.querySelector('.av-tele'), st = store(this.key) || {};
        this.teleBlocks = blocks;
        var hide = st.hide || [];
        el.classList.toggle('min', !!st.min);
        if (st.pos && st.pos.left != null && st.pos.left < innerWidth - 120 && st.pos.top < innerHeight - 50) { el.style.left = st.pos.left + 'px'; el.style.top = st.pos.top + 'px'; el.style.right = 'auto'; }
        el.innerHTML = '<header class="av-tele-h"><h2>Telemetry</h2>' +
            '<button type="button" class="av-ic" data-tele="adjust" aria-label="Adjust telemetry" title="Choose what shows"><i data-lucide="sliders-horizontal"></i></button>' +
            '<button type="button" class="av-ic" data-tele="min" aria-label="' + (st.min ? 'Expand' : 'Collapse') + ' telemetry" title="' + (st.min ? 'Expand' : 'Collapse') + '"><i data-lucide="' + (st.min ? 'chevron-down' : 'chevron-up') + '"></i></button></header>' +
            '<div class="av-adjust" hidden>' + blocks.map(function (b) {
                return '<label><input type="checkbox" data-blk="' + b.id + '"' + (hide.indexOf(b.id) === -1 ? ' checked' : '') + '> ' + esc(b.title) + '</label>';
            }).join('') + '<button type="button" class="av-adjust-reset" data-tele="reset">Show all · reset position</button></div>' +
            '<div class="av-tele-b">' + blocks.filter(function (b) { return hide.indexOf(b.id) === -1; }).map(function (b) {
                return '<section class="av-blk" data-id="' + b.id + '"><h3>' + esc(b.title) + '</h3>' + b.html + '</section>';
            }).join('') + (blocks.every(function (b) { return hide.indexOf(b.id) !== -1; }) ? '<p class="av-none">Everything is hidden. Use the sliders to bring a block back.</p>' : '') + '</div>';
        if (!el._wired) {
            el._wired = true;
            el.addEventListener('click', function (e) {
                var b = e.target.closest('[data-tele]'); if (!b) return;
                var s = store(self.key) || {};
                if (b.dataset.tele === 'adjust') { var a = el.querySelector('.av-adjust'); a.hidden = !a.hidden; self._adjOpen = !a.hidden; return; }
                if (b.dataset.tele === 'min') s.min = !s.min;
                if (b.dataset.tele === 'reset') { s = {}; el.style.left = el.style.top = el.style.right = ''; }
                store(self.key, s); self.tele(self.teleBlocks);
            });
            el.addEventListener('change', function (e) {
                var c = e.target.closest('[data-blk]'); if (!c) return;
                var s = store(self.key) || {}, h = s.hide || [];
                h = h.filter(function (x) { return x !== c.dataset.blk; }); if (!c.checked) h.push(c.dataset.blk);
                s.hide = h; store(self.key, s); self.tele(self.teleBlocks);
            });
            this.drag(el, el, function () { var s = store(self.key) || {}; s.pos = { left: el.offsetLeft, top: el.offsetTop }; store(self.key, s); });
        }
        if (this._adjOpen) el.querySelector('.av-adjust').hidden = false;
        if (W.lucide) lucide.createIcons();
    };

    /* ---- controls: month + institution -------------------------------------------------------- */
    HoloApp.prototype.controls = function (months, insts, onChange) {
        var m = $('avMonth'), seg = $('avInst');
        // grouped by year (newest first) with a whole-year choice, so years of data stay one short list
        var years = {};
        months.forEach(function (x) { (years[x.ym.slice(0, 4)] = years[x.ym.slice(0, 4)] || []).push(x); });
        m.innerHTML = '<option value="">All time</option>' + Object.keys(years).sort().reverse().map(function (y) {
            return '<optgroup label="' + y + '"><option value="' + y + '">All of ' + y + '</option>' +
                years[y].map(function (x) { return '<option value="' + x.ym + '">' + esc(x.label) + '</option>'; }).join('') + '</optgroup>';
        }).join('');
        m.addEventListener('change', function () { onChange('ym', m.value); });
        if (insts.length < 2) { seg.hidden = true; return; }
        seg.innerHTML = '<button type="button" data-i="" class="on">ALL</button>' + insts.map(function (i) { return '<button type="button" data-i="' + esc(i.key) + '">' + esc(i.tag) + '</button>'; }).join('');
        seg.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
            onChange('inst', b.dataset.i);
        });
    };

    HoloApp.toHub = function (hub) {
        try { if (W.top !== W && typeof W.top.shShow === 'function') { W.top.shShow(hub); return false; } } catch (e) {}
        return true;
    };
    W.HoloApp = HoloApp;
})(window);
