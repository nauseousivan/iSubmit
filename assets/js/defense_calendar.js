/* assets/js/defense_calendar.js — the shared month calendar (2026-10-14).
 *
 * Used by the student "View more" calendar (dashboards/calendar_all.php) and the public schedule
 * (schedule.php). Data in, nothing fetched here: the page passes items and may add() more later
 * (public holidays arrive after a fetch). Styles: assets/css/defense_calendar.css.
 *
 *   DefenseCalendar.mount(el, { items, today: 'YYYY-MM-DD', school: 'MCNP'|'ISAP'|null })
 *   item = { date: 'YYYY-MM-DD', kind: 'defense'|'mine'|'event'|'closed'|'holiday',
 *            title, sub?, short?, school?: 'MCNP'|'ISAP', phase?: 'proposal'|'final' }
 *
 * A fixed 42-cell month (six weeks, neighbours dimmed) so the grid never changes height between months.
 * Each day carries at most three chips, then "+N more"; the side panel lists the chosen day in full, or
 * "Coming up" when no day is chosen.
 */
(function () {
    'use strict';
    var KIND = {
        mine:    { label: 'Your defense',  order: 0 },
        defense: { label: 'Defenses',      order: 1 },
        event:   { label: 'School events', order: 2 },
        closed:  { label: 'Office closed', order: 3 },
        holiday: { label: 'Holidays',      order: 4 }
    };
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function longDate(s) { var d = parse(s); return DOW[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }

    function mount(root, opt) {
        var items = (opt.items || []).slice();
        var today = opt.today || ymd(new Date());
        var t = parse(today);
        var view = new Date(t.getFullYear(), t.getMonth(), 1);
        var school = opt.school || 'all';
        var hidden = {};                 // kind -> true when switched off in the legend
        var picked = null;               // a chosen 'YYYY-MM-DD', or null for "Coming up"

        // Open on the month of the next thing that happens if this month is empty — an empty first
        // screen reads as "nothing is scheduled" when the next defense is a week into next month.
        (function () {
            var first = items.filter(function (i) { return i.date >= today && i.kind !== 'holiday'; }).map(function (i) { return i.date; }).sort()[0];
            if (first && first.slice(0, 7) !== today.slice(0, 7)) {
                var f = parse(first); view = new Date(f.getFullYear(), f.getMonth(), 1);
            }
        })();

        root.classList.add('dc');
        root.innerHTML =
            '<div class="dc-bar">' +
              '<div class="dc-nav">' +
                '<button type="button" class="dc-ic" data-go="-1" aria-label="Previous month">&#8249;</button>' +
                '<h2 class="dc-month" aria-live="polite"></h2>' +
                '<button type="button" class="dc-ic" data-go="1" aria-label="Next month">&#8250;</button>' +
                '<button type="button" class="dc-today" data-go="0">Today</button>' +
              '</div>' +
              '<div class="dc-seg" role="group" aria-label="Institution">' +
                ['all', 'MCNP', 'ISAP'].map(function (s) { return '<button type="button" data-school="' + s + '">' + (s === 'all' ? 'Both' : s) + '</button>'; }).join('') +
              '</div>' +
            '</div>' +
            '<div class="dc-legend" role="group" aria-label="Show"></div>' +
            '<div class="dc-body">' +
              '<div class="dc-grid" role="grid"></div>' +
              '<aside class="dc-side"><div class="dc-side-h"></div><div class="dc-side-list"></div></aside>' +
            '</div>';

        var $ = function (s) { return root.querySelector(s); };

        function visible(i) {
            if (hidden[i.kind]) return false;
            if (school !== 'all' && i.school && i.school !== school) return false;
            return true;
        }
        function byKind(a, b) { return (KIND[a.kind].order - KIND[b.kind].order) || (a.title < b.title ? -1 : 1); }

        function legend() {
            var present = {};
            items.forEach(function (i) { present[i.kind] = true; });
            $('.dc-legend').innerHTML = Object.keys(KIND).filter(function (k) { return present[k]; }).map(function (k) {
                return '<button type="button" class="dc-key k-' + k + '" data-kind="' + k + '" aria-pressed="' + (hidden[k] ? 'false' : 'true') + '"><i></i>' + KIND[k].label + '</button>';
            }).join('') + (items.some(function (i) { return i.phase; }) ? '<span class="dc-pf"><em>P</em>Proposal <em>F</em>Final</span>' : '');
        }

        function grid() {
            $('.dc-month').textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
            var start = new Date(view.getFullYear(), view.getMonth(), 1 - view.getDay());
            var map = {};
            items.forEach(function (i) { if (visible(i)) (map[i.date] = map[i.date] || []).push(i); });
            var html = DOW.map(function (d) { return '<div class="dc-dow" role="columnheader">' + d + '</div>'; }).join('');
            for (var n = 0; n < 42; n++) {
                var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + n), key = ymd(d);
                var list = (map[key] || []).sort(byKind);
                var cls = 'dc-day' + (d.getMonth() !== view.getMonth() ? ' out' : '') + (key === today ? ' today' : '') + (key < today ? ' past' : '') +
                          (key === picked ? ' picked' : '') + (list.length ? ' has' : '') + (d.getDay() === 0 ? ' sun' : '');
                var chips = list.slice(0, 3).map(function (i) {
                    return '<span class="dc-chip k-' + i.kind + (i.phase ? ' ph-' + i.phase : '') + '">' + (i.phase ? '<em>' + (i.phase === 'final' ? 'F' : 'P') + '</em>' : '') + esc(i.short || i.title) + '</span>';
                }).join('');
                var dots = list.slice(0, 4).map(function (i) { return '<i class="k-' + i.kind + '"></i>'; }).join('');
                html += '<button type="button" role="gridcell" class="' + cls + '" data-date="' + key + '" aria-label="' + esc(longDate(key)) + (list.length ? ', ' + list.length + ' item' + (list.length > 1 ? 's' : '') : '') + '">' +
                        '<span class="dc-n">' + d.getDate() + '</span>' +
                        '<span class="dc-chips">' + chips + (list.length > 3 ? '<span class="dc-more">+' + (list.length - 3) + ' more</span>' : '') + '</span>' +
                        '<span class="dc-dots" aria-hidden="true">' + dots + '</span></button>';
            }
            $('.dc-grid').innerHTML = html;
        }

        function row(i, withDate) {
            return '<li class="dc-item k-' + i.kind + '">' +
                   (withDate ? '<span class="dc-when"><b>' + parse(i.date).getDate() + '</b>' + MONTHS[parse(i.date).getMonth()].slice(0, 3) + '</span>' : '') +
                   '<span class="dc-what"><b>' + esc(i.title) + '</b>' + (i.sub ? '<small>' + esc(i.sub) + '</small>' : '') + '</span>' +
                   (i.school ? '<span class="dc-badge">' + esc(i.school) + '</span>' : '') + '</li>';
        }

        function side() {
            var head = $('.dc-side-h'), list = $('.dc-side-list');
            if (picked) {
                var day = items.filter(function (i) { return i.date === picked && visible(i); }).sort(byKind);
                head.innerHTML = '<span>' + esc(longDate(picked)) + '</span><button type="button" class="dc-x" data-clear="1">Coming up</button>';
                list.innerHTML = day.length ? '<ul>' + day.map(function (i) { return row(i, false); }).join('') + '</ul>'
                                            : '<p class="dc-empty">Nothing on this day.</p>';
                return;
            }
            var next = items.filter(function (i) { return i.date >= today && visible(i); })
                            .sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : byKind(a, b); }).slice(0, 12);
            head.innerHTML = '<span>Coming up</span>';
            list.innerHTML = next.length ? '<ul>' + next.map(function (i) { return row(i, true); }).join('') + '</ul>'
                                         : '<p class="dc-empty">Nothing coming up yet. Published defense dates appear here.</p>';
        }

        function draw() {
            root.querySelectorAll('.dc-seg button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.school === school ? 'true' : 'false'); });
            legend(); grid(); side();
        }

        root.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b || !root.contains(b)) return;
            if (b.dataset.go !== undefined) {
                var g = +b.dataset.go;
                view = g === 0 ? new Date(t.getFullYear(), t.getMonth(), 1) : new Date(view.getFullYear(), view.getMonth() + g, 1);
                if (g === 0) picked = null;
            } else if (b.dataset.school) { school = b.dataset.school; }
            else if (b.dataset.kind) { hidden[b.dataset.kind] = !hidden[b.dataset.kind]; }
            else if (b.dataset.date) { picked = picked === b.dataset.date ? null : b.dataset.date; }
            else if (b.dataset.clear) { picked = null; }
            else return;
            draw();
        });

        draw();
        return {
            add: function (more) { items = items.concat(more || []); draw(); },
            items: function () { return items.slice(); }
        };
    }

    window.DefenseCalendar = { mount: mount };
})();
