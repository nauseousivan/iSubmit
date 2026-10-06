/* =============================================================================
   insights.js — the charts behind the Analytics screens (2026-10-01).
   Plain HTML/SVG, no library (works offline), theme-aware through portal.css
   tokens. Every mark carries a hover tooltip (data-tip); clickable marks carry
   data-act/data-val for the page's one click handler.

     IN.bars(rows, opts)     horizontal bars          rows: [{label, value, sub, color, act, val}]
     IN.stack(rows, keys)    a stacked bar per row    rows: [{label, parts: {key: n}}], keys: [{k, label, color}]
     IN.columns(points, keys, opts)  stacked columns over time    points: [{x, label, vals: {k: n}}]
     IN.donut(parts, opts)   part-to-whole, <= 6 parts (never 2 — use a tile)
     IN.table(cols, rows, sort)      sortable table   cols: [{k, label, num, html}]
     IN.legend(keys)  IN.csv(name, rows)  IN.tiles(list)
   Colours: categorical slots (dataviz reference palette, fixed order) as CSS vars
   --c1..--c8 with a light and a dark step; status colours as --s-good/--s-warn/--s-bad.
   ========================================================================== */
(function (W) {
    'use strict';
    var IN = {};
    IN.esc = function (t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };
    IN.plural = function (n, w, ws) { return n + ' ' + (n === 1 ? w : (ws || w + 's')); };
    IN.c = function (i) { return 'var(--c' + ((i % 8) + 1) + ')'; };
    IN.pct = function (v, of) { return of ? Math.round(v / of * 100) : 0; };
    function tipAttr(html) { return ' data-tip="' + IN.esc(html) + '"'; }
    function act(o) { return o && o.act ? ' data-act="' + IN.esc(o.act) + '" data-val="' + IN.esc(o.val == null ? '' : o.val) + '"' : ''; }

    /** Horizontal bars, longest first unless told otherwise. */
    IN.bars = function (rows, opts) {
        opts = opts || {};
        if (!rows.length) return '<p class="in-empty">' + (opts.empty || 'Nothing here.') + '</p>';
        var max = opts.max || Math.max.apply(null, rows.map(function (r) { return r.value; })) || 1;
        return '<div class="in-bars">' + rows.map(function (r) {
            var tag = r.act ? 'button type="button"' : 'div';
            return '<' + tag + ' class="in-bar' + (r.act ? ' is-click' : '') + (r.on ? ' on' : '') + '"' + act(r) + tipAttr('<b>' + IN.esc(r.label) + '</b>' + IN.esc(r.value) + (opts.unit ? ' ' + opts.unit : '') + (r.tip ? '<br>' + r.tip : '')) + '>' +
                '<span class="in-bar-l">' + IN.esc(r.label) + (r.sub ? '<small>' + IN.esc(r.sub) + '</small>' : '') + '</span>' +
                '<span class="in-bar-t"><i style="width:' + Math.max(r.value ? 1.5 : 0, r.value / max * 100) + '%;background:' + (r.color || 'var(--c1)') + '"></i></span>' +
                '<b>' + (opts.fmt ? opts.fmt(r.value) : r.value) + '</b></' + tag.split(' ')[0] + '>';
        }).join('') + '</div>';
    };

    /** A stacked bar per row (absolute: bars share one scale). */
    IN.stack = function (rows, keys, opts) {
        opts = opts || {};
        if (!rows.length) return '<p class="in-empty">' + (opts.empty || 'Nothing here.') + '</p>';
        var totals = rows.map(function (r) { return keys.reduce(function (s, k) { return s + (r.parts[k.k] || 0); }, 0); });
        var max = opts.max || Math.max.apply(null, totals) || 1;
        return '<div class="in-bars">' + rows.map(function (r, i) {
            var tag = r.act ? 'button type="button"' : 'div';
            return '<' + tag + ' class="in-bar' + (r.act ? ' is-click' : '') + '"' + act(r) + '>' +
                '<span class="in-bar-l">' + IN.esc(r.label) + (r.sub ? '<small>' + IN.esc(r.sub) + '</small>' : '') + '</span>' +
                '<span class="in-bar-t in-stack">' + keys.map(function (k) {
                    var v = r.parts[k.k] || 0; if (!v) return '';
                    return '<i style="width:' + (v / max * 100) + '%;background:' + k.color + '"' + tipAttr('<b>' + IN.esc(r.label) + '</b>' + IN.esc(k.label) + ': ' + v) + '></i>';
                }).join('') + '</span><b>' + totals[i] + '</b></' + tag.split(' ')[0] + '>';
        }).join('') + '</div>' + IN.legend(keys);
    };

    /** Stacked columns over time, one axis, recessive grid, direct total above each column. */
    IN.columns = function (points, keys, opts) {
        opts = opts || {};
        if (!points.length) return '<p class="in-empty">' + (opts.empty || 'No activity in this range.') + '</p>';
        var W0 = 640, H0 = opts.h || 200, padL = 34, padB = 26, padT = 18, n = points.length;
        var totals = points.map(function (p) { return keys.reduce(function (s, k) { return s + (p.vals[k.k] || 0); }, 0); });
        var max = Math.max(1, Math.max.apply(null, totals));
        var step = Math.pow(10, Math.floor(Math.log10(max))), nice = Math.max(2, Math.ceil(max / step) * step);   // whole-number ticks, even for tiny counts
        var cw = (W0 - padL) / n, bw = Math.max(6, Math.min(48, cw * .62));
        var svg = '<svg class="in-cols" viewBox="0 0 ' + W0 + ' ' + H0 + '" preserveAspectRatio="none" role="img" aria-label="' + IN.esc(opts.label || 'Columns over time') + '">';
        [0, .5, 1].forEach(function (f) {
            var y = padT + (H0 - padT - padB) * (1 - f);
            svg += '<line x1="' + padL + '" x2="' + W0 + '" y1="' + y + '" y2="' + y + '" class="in-grid"/><text x="' + (padL - 6) + '" y="' + (y + 4) + '" class="in-ax" text-anchor="end">' + Math.round(nice * f) + '</text>';
        });
        points.forEach(function (p, i) {
            var x = padL + cw * i + (cw - bw) / 2, base = H0 - padB, acc = 0;
            keys.forEach(function (k) {
                var v = p.vals[k.k] || 0; if (!v) return;
                var h = (H0 - padT - padB) * v / nice, y = base - acc - h;
                svg += '<rect x="' + x + '" y="' + (y + 1) + '" width="' + bw + '" height="' + Math.max(1, h - 1) + '" rx="3" fill="' + k.color + '"' + tipAttr('<b>' + IN.esc(p.label) + '</b>' + IN.esc(k.label) + ': ' + v) + act(p) + '/>';
                acc += h;
            });
            if (totals[i]) svg += '<text x="' + (x + bw / 2) + '" y="' + (base - acc - 5) + '" class="in-val" text-anchor="middle">' + totals[i] + '</text>';
            svg += '<text x="' + (x + bw / 2) + '" y="' + (H0 - 8) + '" class="in-ax" text-anchor="middle">' + IN.esc(p.x) + '</text>';
        });
        return svg + '</svg>' + (keys.length > 1 ? IN.legend(keys) : '');
    };

    /** Part-to-whole at a glance (<= 6 parts). The total sits in the middle. */
    IN.donut = function (parts, opts) {
        opts = opts || {};
        parts = parts.filter(function (p) { return p.value > 0; });
        var total = parts.reduce(function (s, p) { return s + p.value; }, 0);
        if (!total) return '<p class="in-empty">' + (opts.empty || 'Nothing here.') + '</p>';
        var R = 70, r = 46, cx = 90, cy = 90, a0 = -Math.PI / 2, svg = '<svg class="in-donut" viewBox="0 0 180 180" role="img" aria-label="' + IN.esc(opts.label || 'Share') + '">';
        parts.forEach(function (p) {
            var a1 = a0 + Math.PI * 2 * p.value / total, gap = parts.length > 1 ? .012 : 0;
            var s = a0 + gap, e = a1 - gap, large = e - s > Math.PI ? 1 : 0;
            var d = parts.length === 1
                ? 'M ' + (cx + R) + ' ' + cy + ' A ' + R + ' ' + R + ' 0 1 1 ' + (cx - R) + ' ' + cy + ' A ' + R + ' ' + R + ' 0 1 1 ' + (cx + R) + ' ' + cy +
                  ' M ' + (cx + r) + ' ' + cy + ' A ' + r + ' ' + r + ' 0 1 0 ' + (cx - r) + ' ' + cy + ' A ' + r + ' ' + r + ' 0 1 0 ' + (cx + r) + ' ' + cy + ' Z'
                : 'M ' + (cx + R * Math.cos(s)) + ' ' + (cy + R * Math.sin(s)) + ' A ' + R + ' ' + R + ' 0 ' + large + ' 1 ' + (cx + R * Math.cos(e)) + ' ' + (cy + R * Math.sin(e)) +
                  ' L ' + (cx + r * Math.cos(e)) + ' ' + (cy + r * Math.sin(e)) + ' A ' + r + ' ' + r + ' 0 ' + large + ' 0 ' + (cx + r * Math.cos(s)) + ' ' + (cy + r * Math.sin(s)) + ' Z';
            svg += '<path d="' + d + '" fill="' + p.color + '" fill-rule="evenodd"' + tipAttr('<b>' + IN.esc(p.label) + '</b>' + p.value + ' (' + IN.pct(p.value, total) + '%)') + act(p) + (p.act ? ' class="is-click"' : '') + '/>';
            a0 = a1;
        });
        svg += '<text x="90" y="88" class="in-donut-n" text-anchor="middle">' + total + '</text><text x="90" y="106" class="in-ax" text-anchor="middle">' + IN.esc(opts.unit || 'total') + '</text></svg>';
        return '<div class="in-donut-wrap">' + svg + '<div class="in-donut-l">' + parts.map(function (p) {
            return '<' + (p.act ? 'button type="button"' : 'div') + ' class="in-dl' + (p.act ? ' is-click' : '') + '"' + act(p) + '><i style="background:' + p.color + '"></i><span>' + IN.esc(p.label) + '</span><b>' + p.value + '</b><small>' + IN.pct(p.value, total) + '%</small></' + (p.act ? 'button' : 'div') + '>';
        }).join('') + '</div></div>';
    };

    IN.legend = function (keys) {
        return '<div class="in-legend">' + keys.map(function (k) { return '<span><i style="background:' + k.color + '"></i>' + IN.esc(k.label) + '</span>'; }).join('') + '</div>';
    };

    /** Summary tiles. t: [{label, value, sub, icon, warn, meter: [v, of, color]}] */
    IN.tiles = function (list) {
        return list.map(function (t) {
            return '<div class="in-tile' + (t.warn ? ' warn' : '') + '"><div class="in-tile-top"><i data-lucide="' + (t.icon || 'circle') + '"></i>' + IN.esc(t.label) + '</div><b>' + t.value +
                (t.of != null ? '<small>of ' + t.of + '</small>' : '') + '</b>' +
                (t.meter ? '<div class="in-meter"><i style="width:' + IN.pct(t.meter[0], t.meter[1]) + '%;background:' + (t.meter[2] || 'var(--c1)') + '"></i></div>' : '') +
                '<div class="in-tile-sub">' + (t.sub || '') + '</div></div>';
        }).join('');
    };

    /** A sortable table; the page re-renders on data-sort clicks. */
    IN.table = function (cols, rows, sort, opts) {
        opts = opts || {};
        if (!rows.length) return '<p class="in-empty">' + (opts.empty || 'Nothing matches.') + '</p>';
        if (sort) {
            var c = cols.filter(function (x) { return x.k === sort[0]; })[0];
            if (c) rows = rows.slice().sort(function (a, b) {
                var p = c.sortv ? c.sortv(a) : a[c.k], q = c.sortv ? c.sortv(b) : b[c.k];
                if (typeof p === 'string') p = p.toLowerCase(); if (typeof q === 'string') q = q.toLowerCase();
                return (p < q ? -1 : p > q ? 1 : 0) * sort[1];
            });
        }
        return '<div class="in-tw"><table class="in-table"><thead><tr>' + cols.map(function (c) {
            return '<th class="' + (c.num ? 'num' : '') + '"><button type="button" data-sort="' + c.k + '">' + IN.esc(c.label) + (sort && sort[0] === c.k ? (sort[1] > 0 ? ' ↑' : ' ↓') : '') + '</button></th>';
        }).join('') + '</tr></thead><tbody>' + rows.map(function (r) {
            return '<tr' + (opts.rowAct ? ' class="click"' + act(opts.rowAct(r)) : '') + '>' + cols.map(function (c) {
                return '<td class="' + (c.num ? 'num' : '') + '">' + (c.html ? c.html(r) : IN.esc(r[c.k])) + '</td>';
            }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>';
    };

    /** Download rows as CSV (Excel-ready: BOM + CRLF). */
    IN.csv = function (name, rows) {
        var csv = '﻿' + rows.map(function (r) { return r.map(function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = name; document.body.appendChild(a); a.click(); a.remove();
    };

    // ---- one tooltip for every chart -------------------------------------------------------
    var tip = null;
    document.addEventListener('mouseover', function (e) {
        var t = e.target.closest && e.target.closest('[data-tip]');
        if (!t) { if (tip) tip.hidden = true; return; }
        if (!tip) { tip = document.createElement('div'); tip.className = 'in-tip'; document.body.appendChild(tip); }
        tip.innerHTML = t.getAttribute('data-tip'); tip.hidden = false;
    });
    document.addEventListener('mousemove', function (e) {
        if (!tip || tip.hidden) return;
        tip.style.left = Math.min(e.clientX + 14, innerWidth - tip.offsetWidth - 8) + 'px';
        tip.style.top = Math.min(e.clientY + 14, innerHeight - tip.offsetHeight - 8) + 'px';
    });

    W.IN = IN;
})(window);
