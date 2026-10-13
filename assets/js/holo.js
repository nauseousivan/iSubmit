/* =============================================================================
   holo.js — a small hologram engine (2026-10-01). No library: a hand-rolled 3D
   projection drawn on a 2D canvas, so it works on the office Wi-Fi with no internet.

   A Holo draws stacked DISCS (layers), flat NODES on them, BARS (lit boxes) standing
   on them, and LINKS between any two. Drag turns it, the wheel zooms, it idles into
   a slow spin. Hover asks the app which items are related and dims the rest; click
   hands the item back. Many can run at once (the Admin's pop-outs): one shared
   requestAnimationFrame loop draws every live instance.

     var h = new Holo(canvas, {
         theme:   {...},                         // colours (see T below)
         related: function (item) { return [...] },   // what lights up with it
         label:   function (item) { return 'TEXT' | null },   // always-on labels
         onHover: function (item, mx, my) {},
         onClick: function (item) {},
         fitW: 1150, fitH: 1080, spin: 0.0016, yaw: .7, pitch: .34 });
     h.set({ layers: [{y, r, label, n}], nodes: [{x,y,z, color, shape:'dot'|'diamond', size, ring, label}],
             bars: [{x,y,z, h, color, w, label}], links: [{a, b, aTop, bTop}] });
   Items keep their last screen position (item._p) so the app can draw connectors.
   ========================================================================== */
(function (W) {
    'use strict';

    var all = [], raf = 0;
    var REDUCE = W.matchMedia && W.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function tick(t) {
        all.forEach(function (h) { if (h.alive && h.c.isConnected) h.frame(t); });
        raf = all.length ? requestAnimationFrame(tick) : 0;
    }

    function Holo(canvas, o) {
        o = o || {};
        this.c = canvas; this.ctx = canvas.getContext('2d'); this.o = o;
        this.yaw = o.yaw != null ? o.yaw : 0.7;
        this.pitch = o.pitch != null ? o.pitch : 0.34;
        this.zoom = 1; this.spin = o.spin != null ? o.spin : 0.0016;
        this.fitW = o.fitW || 1150; this.fitH = o.fitH || 1080; this.cyFrac = o.cyFrac || 0.5; this.yMid = o.yMid || 0;
        this.T = Object.assign({
            disc: '94,234,212', line: '94,234,212', node: '#cffafe', hot: '#f5d90a',
            text: 'rgba(207,250,254,0.92)', layerText: 'rgba(153,246,228,0.7)', labelBg: 'rgba(4,12,16,0.78)', glow: true,
            hotRgb: '245,217,10', hotBg: 'rgba(24,18,2,0.88)'
        }, o.theme || {});
        this.layers = []; this.nodes = []; this.bars = []; this.links = [];
        this.hover = null; this.lit = null; this.pinned = null; this.alive = true; this.born = performance.now();
        this._bind();
        this.resize();
        all.push(this);
        if (!raf) raf = requestAnimationFrame(tick);
    }

    Holo.prototype.set = function (m) {
        this.layers = m.layers || []; this.nodes = m.nodes || []; this.bars = m.bars || []; this.links = m.links || [];
        this.hover = null; this.lit = null; this.born = performance.now();
    };
    Holo.prototype.destroy = function () { this.alive = false; var i = all.indexOf(this); if (i !== -1) all.splice(i, 1); };
    Holo.prototype.resize = function () {
        // layout size, not getBoundingClientRect(): a pop-out is still scaled down while it animates
        // in, and a canvas sized then stays a blurry thumbnail stretched to the window
        var r = this.c.getBoundingClientRect();
        this.dpr = W.devicePixelRatio || 1; this.W = this.c.clientWidth || r.width; this.H = this.c.clientHeight || r.height;
        this.c.width = Math.max(1, Math.round(this.W * this.dpr)); this.c.height = Math.max(1, Math.round(this.H * this.dpr));
        this.base = Math.min(this.W / this.fitW, this.H / this.fitH);
    };
    Holo.prototype.pin = function (item) { this.pinned = item; };
    // the viewer switched light/dark or accent: same scene, new colours (holo_app.js reads them from portal.css)
    Holo.prototype.setTheme = function (t) { Object.assign(this.T, t); };

    // yaw around the vertical axis, then tilt; a gentle perspective toward the viewer
    Holo.prototype.proj = function (x, y, z) {
        var cy = Math.cos(this.yaw), sy = Math.sin(this.yaw), cp = Math.cos(this.pitch), sp = Math.sin(this.pitch);
        var rx = x * cy - z * sy, rz = x * sy + z * cy, yy = y - this.yMid;
        var d = rz * cp + yy * sp, k = 1500 / (1500 - d), sc = this.base * this.zoom * k;
        return { x: this.W / 2 + rx * sc, y: this.H * this.cyFrac - (yy * cp - rz * sp) * sc, d: d, k: k, sc: sc };
    };

    Holo.prototype.frame = function (t) {
        if (!this.drag && !this.hover && !REDUCE) this.yaw += this.spin;
        this.t = t || performance.now();
        this.draw();
        if (this.o.after) this.o.after(this);
    };

    function rgb(hex) { var n = parseInt(hex.slice(1), 16); return [n >> 16, n >> 8 & 255, n & 255]; }
    function shade(hex, f) {
        var c = rgb(hex).map(function (v) { return Math.max(0, Math.min(255, Math.round(f >= 1 ? v + (255 - v) * (f - 1) : v * f))); });
        return 'rgb(' + c.join(',') + ')';
    }
    function poly(ctx, pts) { ctx.beginPath(); pts.forEach(function (p, i) { if (i) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y); }); ctx.closePath(); }

    Holo.prototype.isLit = function (it) { return !this.lit || this.lit.indexOf(it) !== -1; };

    Holo.prototype.draw = function () {
        var ctx = this.ctx, T = this.T, self = this, lit = this.lit;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.clearRect(0, 0, this.W, this.H);
        // the build-in: everything rises out of the floor in the first 700ms
        var grow = Math.min(1, (this.t - this.born) / 700); grow = 1 - Math.pow(1 - grow, 3);

        // discs, bottom to top, with a dashed inner ring and the layer label at the front edge
        this.layers.forEach(function (l, li) {
            [1, .62].forEach(function (f, ri) {
                ctx.beginPath();
                for (var i = 0; i <= 96; i++) {
                    var a = Math.PI * 2 * i / 96, p = self.proj(Math.cos(a) * l.r * f, l.y, Math.sin(a) * l.r * f);
                    if (i) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y);
                }
                if (!ri) { ctx.fillStyle = 'rgba(' + T.disc + ',' + (l.fill || 0.035) + ')'; ctx.fill(); }
                ctx.setLineDash(ri ? [3, 6] : []);
                ctx.strokeStyle = 'rgba(' + T.disc + ',' + (ri ? 0.14 : 0.32) + ')'; ctx.lineWidth = 1; ctx.stroke();
                ctx.setLineDash([]);
            });
            var fp = self.proj(Math.sin(self.yaw) * l.r, l.y, Math.cos(self.yaw) * l.r);
            ctx.font = '600 11px "JetBrains Mono", monospace'; ctx.textAlign = 'center';
            ctx.fillStyle = T.layerText;
            ctx.fillText(('0' + (li + 1)).slice(-2) + ' · ' + String(l.label).toUpperCase() + (l.n != null ? ' · ' + l.n : ''), fp.x, fp.y + 18);
        });

        function end(it, top) { return it.h != null ? (top ? it.y + it.h * grow : it.y) : it.y; }
        // links
        this.links.forEach(function (lk) {
            var p = self.proj(lk.a.x, end(lk.a, lk.aTop), lk.a.z), q = self.proj(lk.b.x, end(lk.b, lk.bTop), lk.b.z);
            var on = lit && lit.indexOf(lk.a) !== -1 && lit.indexOf(lk.b) !== -1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y);
            if (on) { ctx.strokeStyle = T.hot; ctx.lineWidth = 2; if (T.glow) { ctx.shadowColor = T.hot; ctx.shadowBlur = 8; } }
            else { ctx.strokeStyle = 'rgba(' + T.line + ',' + (lit ? 0.04 : (lk.alpha || 0.15)) + ')'; ctx.lineWidth = 1; }
            ctx.stroke(); ctx.shadowBlur = 0;
        });

        // flat nodes (dots) and standing markers (diamonds)
        this.nodes.forEach(function (n) {
            var p = self.proj(n.x, n.y, n.z), on = self.isLit(n), a = on ? 1 : 0.2, s = (n.size || 5) * p.sc;
            n._p = p;
            ctx.globalAlpha = a;
            if (n.shape === 'diamond') {
                var h = Math.max(7, 11 * p.sc * 1.6), w = h * .62, cy = p.y - h * 1.1;
                ctx.beginPath(); ctx.moveTo(p.x, cy - h); ctx.lineTo(p.x + w, cy); ctx.lineTo(p.x, cy + h); ctx.lineTo(p.x - w, cy); ctx.closePath();
                ctx.fillStyle = n.color || T.node;
                if (T.glow && on) { ctx.shadowColor = n.color || T.node; ctx.shadowBlur = 12; }
                ctx.fill(); ctx.shadowBlur = 0;
                ctx.beginPath(); ctx.moveTo(p.x, cy + h); ctx.lineTo(p.x, p.y); ctx.strokeStyle = 'rgba(' + T.line + ',0.5)'; ctx.stroke();
                n._p = { x: p.x, y: cy, d: p.d, sc: p.sc };
            } else {
                ctx.beginPath(); ctx.ellipse(p.x, p.y, Math.max(2.5, s), Math.max(1.5, s * Math.sin(self.pitch) * 1.4), 0, 0, Math.PI * 2);
                ctx.fillStyle = n.color || T.node;
                if (T.glow && on) { ctx.shadowColor = n.color || T.node; ctx.shadowBlur = 10; }
                ctx.fill(); ctx.shadowBlur = 0;
            }
            if (n.ring) {   // a status ring (an account waiting, disabled...)
                ctx.beginPath(); ctx.ellipse(p.x, n.shape === 'diamond' ? n._p.y : p.y, Math.max(9, s * 2.4), Math.max(5, s * 2.4 * Math.sin(self.pitch) * 1.4) + (n.shape === 'diamond' ? 10 : 0), 0, 0, Math.PI * 2);
                ctx.strokeStyle = n.ring; ctx.lineWidth = 2; ctx.stroke();
            }
            ctx.globalAlpha = 1;
        });

        // bars, far to near, each a lit box
        var order = this.bars.slice().sort(function (a, b) { return self.proj(a.x, a.y, a.z).d - self.proj(b.x, b.y, b.z).d; });
        order.forEach(function (b) {
            var w = b.w || 7, hh = Math.max(1, b.h * grow), on = self.isLit(b);
            var cs = [[-w, -w], [w, -w], [w, w], [-w, w]];
            var bot = cs.map(function (c) { return self.proj(b.x + c[0], b.y, b.z + c[1]); });
            var top = cs.map(function (c) { return self.proj(b.x + c[0], b.y + hh, b.z + c[1]); });
            var faces = [0, 1, 2, 3].map(function (i) { var j = (i + 1) % 4; return { pts: [bot[i], bot[j], top[j], top[i]], d: (bot[i].d + bot[j].d) / 2, f: i % 2 ? .6 : .8 }; });
            faces.sort(function (x, y) { return x.d - y.d; });
            ctx.globalAlpha = on ? 1 : 0.18;
            faces.forEach(function (f) { poly(ctx, f.pts); ctx.fillStyle = shade(b.color, f.f); ctx.fill(); });
            poly(ctx, top); ctx.fillStyle = shade(b.color, 1.3);
            if (T.glow && on && lit) { ctx.shadowColor = b.color; ctx.shadowBlur = 14; }
            ctx.fill(); ctx.shadowBlur = 0;
            ctx.globalAlpha = 1;
            var xs = bot.concat(top).map(function (p) { return p.x; }), ys = bot.concat(top).map(function (p) { return p.y; });
            b._box = [Math.min.apply(null, xs), Math.min.apply(null, ys), Math.max.apply(null, xs), Math.max.apply(null, ys)];
            b._d = (bot[0].d + bot[2].d) / 2;
            b._p = self.proj(b.x, b.y + hh, b.z);
        });

        // the pinned item (it has a pop-out open) pulses
        if (this.pinned && this.pinned._p) {
            var ph = ((this.t || 0) % 1400) / 1400, pp = this.pinned._p;
            ctx.beginPath(); ctx.ellipse(pp.x, pp.y, 8 + 22 * ph, (8 + 22 * ph) * .55, 0, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(' + this.T.hotRgb + ',' + (1 - ph) + ')'; ctx.lineWidth = 2; ctx.stroke();
        }

        // labels: whatever the app always wants, plus everything lit while hovering
        ctx.textAlign = 'left'; ctx.font = '600 11px "JetBrains Mono", monospace';
        this.nodes.concat(this.bars).forEach(function (it) {
            var hot = lit && lit.indexOf(it) !== -1;
            var text = self.o.label ? self.o.label(it, hot) : null;
            if (!text && hot) text = it.label;
            if (!text || !it._p) return;
            self.tag(it._p.x + 9, it._p.y - 6, text, hot);
        });
    };

    Holo.prototype.tag = function (x, y, text, hot) {
        var ctx = this.ctx;
        text = String(text).toUpperCase();
        if (text.length > 34) text = text.slice(0, 33) + '…';
        var w = ctx.measureText(text).width;
        ctx.fillStyle = hot ? this.T.hotBg : this.T.labelBg;
        ctx.fillRect(x - 4, y - 11, w + 8, 16);
        ctx.fillStyle = hot ? this.T.hot : this.T.text;
        ctx.fillText(text, x, y + 1);
    };

    Holo.prototype.pick = function (mx, my) {
        var hit = null;
        this.bars.forEach(function (b) {
            if (b._box && mx >= b._box[0] - 3 && mx <= b._box[2] + 3 && my >= b._box[1] - 3 && my <= b._box[3] + 3 && (!hit || b._d > hit._d)) hit = b;
        });
        if (hit) return hit;
        var best = null, bd = 196;
        this.nodes.forEach(function (n) { if (!n._p) return; var dx = n._p.x - mx, dy = n._p.y - my, d = dx * dx + dy * dy; if (d < bd) { bd = d; best = n; } });
        return best;
    };

    Holo.prototype.setHover = function (it, mx, my) {
        this.hover = it || null;
        this.lit = it ? (this.o.related ? this.o.related(it) : [it]) : null;
        if (this.o.onHover) this.o.onHover(this.hover, mx, my);
    };

    Holo.prototype._bind = function () {
        var self = this, c = this.c;
        c.addEventListener('pointerdown', function (e) {
            self.drag = { x: e.clientX, y: e.clientY }; self.moved = false;
            try { c.setPointerCapture(e.pointerId); } catch (x) {}
            c.classList.add('dragging');
        });
        c.addEventListener('pointermove', function (e) {
            var r = c.getBoundingClientRect(), mx = e.clientX - r.left, my = e.clientY - r.top;
            if (self.drag) {
                var dx = e.clientX - self.drag.x, dy = e.clientY - self.drag.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) self.moved = true;
                self.yaw += dx * 0.006; self.pitch = Math.max(0.1, Math.min(1.05, self.pitch + dy * 0.004));
                self.drag = { x: e.clientX, y: e.clientY };
                return;
            }
            var it = self.pick(mx, my);
            if (it !== self.hover) self.setHover(it, mx, my); else if (self.o.onHover) self.o.onHover(it, mx, my);
            c.style.cursor = it ? 'pointer' : 'grab';
        });
        c.addEventListener('pointerup', function (e) {
            var r = c.getBoundingClientRect(), was = self.moved;
            self.drag = null; c.classList.remove('dragging');
            if (!was && self.o.onClick) { var it = self.pick(e.clientX - r.left, e.clientY - r.top); if (it) self.o.onClick(it, self); }
        });
        c.addEventListener('pointerleave', function () { if (!self.drag) self.setHover(null); });
        c.addEventListener('wheel', function (e) { e.preventDefault(); self.zoom = Math.max(0.5, Math.min(2.6, self.zoom * Math.exp(-e.deltaY * 0.001))); }, { passive: false });
    };

    W.Holo = Holo;
})(window);
