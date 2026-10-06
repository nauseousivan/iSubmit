/* =============================================================================
   The Focal Person's Reports + Visualize (2026-10-01). dashboards/focal_reports.php.
   Everything renders from window.FR (focal_reports_lib.php fr_dataset()), so every
   tab, filter, export and the 3D view agree. No library: the 3D view is a small
   hand-rolled projection onto a 2D canvas, so it also works on the office Wi-Fi.
   ========================================================================== */
(function () {
    'use strict';

    // ---- state: one row of filters above everything ---------------------------------------
    var S = { phase: 'proposal', room: 0, q: '', tab: 'sub', subF: 'all', subItem: 0, reqPick: null, schPick: 'complete', schNeg: false, sort: ['name', 1] };

    var ICON = { A: 'check', P: 'clock', R: 'rotate-ccw', N: 'minus' };
    var WORD = { A: 'Approved', P: 'Waiting on you', R: 'Sent back', N: 'Not sent' };
    // Group states are STATUS: always an icon and a word beside the colour.
    var GST = {
        none:     ['Nothing sent', 'circle-dashed'],
        progress: ['In progress', 'loader'],
        complete: ['Ready for a date', 'check-circle-2'],
        dated:    ['Has a date', 'calendar-check']
    };
    var DATE_WORD = { published: 'Published', chain: 'With the office', back: 'Sent back' };

    function $(id) { return document.getElementById(id); }
    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function icons() { if (window.lucide) window.lucide.createIcons(); }
    function items() { return FR.phases[S.phase].items; }
    function roomOf(id) { return FR.rooms[id] || null; }
    function roomName(id) { var r = roomOf(id); return r ? r.short : 'No room'; }
    function letter(n) { return String(n || '?').replace(/ Group$/, '').charAt(0).toUpperCase(); }
    function plural(n, w, ws) { return n + ' ' + (n === 1 ? w : (ws || w + 's')); }
    function phaseWord() { return S.phase === 'final' ? 'Final' : 'Proposal'; }
    function stChip(k, word) { return '<span class="fr-st s-' + k + '"><i data-lucide="' + (GST[k] ? GST[k][1] : ICON[k]) + '"></i>' + esc(word || (GST[k] ? GST[k][0] : WORD[k])) + '</span>'; }

    /** Where a group stands in the chosen phase. */
    function state(g) {
        var a = 0, p = 0, r = 0, n = 0, list = items();
        list.forEach(function (it) {
            var d = g.docs[it.id];
            if (!d) n++; else if (d.st === 'A') a++; else if (d.st === 'R') r++; else p++;
        });
        var date = g.date[S.phase];
        var st = date && date.st !== 'back' ? 'dated' : (a === list.length ? 'complete' : (a + p + r === 0 ? 'none' : 'progress'));
        return { a: a, p: p, r: r, n: n, total: list.length, st: st, date: date };
    }
    /** The groups the filters let through. */
    function scoped() {
        var q = S.q.toLowerCase().trim();
        return FR.groups.filter(function (g) {
            if (S.room && g.room !== S.room) return false;
            if (!q) return true;
            var hay = [g.name, g.leader, g.sid, g.title, roomName(g.room)].concat(g.members.map(function (m) { return m.n + ' ' + m.s; })).join(' ').toLowerCase();
            return hay.indexOf(q) !== -1;
        });
    }
    function rowsOf(list) { return list.map(function (g) { return { g: g, s: state(g) }; }); }

    // ---- filters ------------------------------------------------------------------------------
    function buildRoomSelect() {
        var used = {};
        FR.groups.forEach(function (g) { used[g.room] = (used[g.room] || 0) + 1; });
        var byCourse = {};
        Object.keys(used).forEach(function (id) {
            id = parseInt(id, 10);
            var r = roomOf(id), c = r ? r.program : 'No room yet';
            (byCourse[c] = byCourse[c] || []).push(id);
        });
        var html = '<option value="0">All rooms (' + FR.groups.length + ' groups)</option>';
        Object.keys(byCourse).sort().forEach(function (c) {
            html += '<optgroup label="' + esc(c) + '">' + byCourse[c].sort(function (a, b) { return roomName(a).localeCompare(roomName(b)); })
                .map(function (id) { return '<option value="' + id + '">' + esc(roomName(id)) + ' (' + used[id] + ')</option>'; }).join('') + '</optgroup>';
        });
        $('frRoom').innerHTML = html;
    }
    function setPhase(p) {
        S.phase = p; S.subItem = 0; S.reqPick = null;
        document.querySelectorAll('#frPhase button, #frMapPhase button').forEach(function (b) { b.classList.toggle('on', b.dataset.p === p); });
        render();
        if (!$('frMap').hidden) mapBuild();
    }

    // ---- summary ------------------------------------------------------------------------------
    function kpis(rows) {
        var n = rows.length, sent = 0, files = 0, fromGroups = 0, done = 0, dated = 0, pub = 0;
        rows.forEach(function (x) {
            if (x.s.st !== 'none') sent++;
            if (x.s.p) { files += x.s.p; fromGroups++; }
            if (x.s.a === x.s.total) done++;
            if (x.s.date && x.s.date.st !== 'back') { dated++; if (x.s.date.st === 'published') pub++; }
        });
        var rooms = {}; rows.forEach(function (x) { rooms[x.g.room] = 1; });
        function pct(v) { return n ? Math.round(v / n * 100) : 0; }
        function tile(icon, label, big, of, sub, color, p) {
            return '<div class="fr-kpi"><div class="fr-kpi-top"><i data-lucide="' + icon + '"></i>' + label + '</div>' +
                '<b>' + big + (of !== null ? '<small>of ' + of + '</small>' : '') + '</b>' +
                (p !== null ? '<div class="fr-kpi-bar" role="img" aria-label="' + p + '%"><span style="width:' + p + '%;background:' + color + '"></span></div>' : '') +
                '<div class="fr-kpi-sub">' + sub + '</div></div>';
        }
        $('frKpis').innerHTML =
            tile('users', 'Groups', n, null, 'in ' + plural(Object.keys(rooms).length, 'room'), null, null) +
            tile('upload', 'Sent something', sent, n, pct(sent) + '% have started', 'var(--accent)', pct(sent)) +
            tile('clock', 'Waiting on you', files, null, files ? plural(files, 'file') + ' from ' + plural(fromGroups, 'group') : 'Nothing to review', null, null) +
            tile('check-circle-2', 'Requirements complete', done, n, pct(done) + '% can be put on a date', 'var(--st-a)', pct(done)) +
            tile('calendar-check', 'Have a date', dated, n, pub + ' published, ' + (dated - pub) + ' with the office', 'var(--st-d)', pct(dated));
        var r = S.room ? roomName(S.room) : 'all your rooms';
        $('frLede').textContent = phaseWord() + ' defense · ' + plural(n, 'group') + ' in ' + r + ' · as of ' + new Date(FR.today + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        $('frCrumb').textContent = 'Reports / ' + phaseWord() + (S.room ? ' / ' + roomName(S.room) : '');
        $('frPrinted').textContent = 'Printed ' + new Date().toLocaleString('en-US') + ' · ' + phaseWord() + ' · ' + r;
    }

    // ---- 1. Submissions -----------------------------------------------------------------------
    var SUBF = [
        ['all', 'All', function () { return true; }],
        ['none', 'Nothing sent', function (s) { return s.st === 'none'; }],
        ['missing', 'Missing some', function (s) { return s.n > 0 && s.st !== 'none'; }],
        ['you', 'Waiting on you', function (s) { return s.p > 0; }],
        ['back', 'Sent back', function (s) { return s.r > 0; }],
        ['done', 'Complete', function (s) { return s.a === s.total; }]
    ];
    function renderSub(rows) {
        var silent = rows.filter(function (x) { return x.s.st === 'none'; });
        var f = SUBF.filter(function (x) { return x[0] === S.subF; })[0] || SUBF[0];
        var list = rows.filter(function (x) { return f[2](x.s) && (!S.subItem || !x.g.docs[S.subItem]); });
        var it = items(), html = '';
        if (silent.length) {
            html += '<div class="fr-silent"><div><b>' + plural(silent.length, 'group') + ' ' + (silent.length === 1 ? 'hasn’t' : 'haven’t') + ' sent anything yet</b>' +
                '<p>Tap a name for the members. Remind emails their leaders once (at most every 6 hours).</p><div class="fr-names">' +
                silent.map(function (x) { return '<button type="button" class="fr-name" data-g="' + x.g.id + '">' + esc(x.g.name) + '</button>'; }).join('') +
                '</div></div><button type="button" class="fr-go" data-remind="' + silent.map(function (x) { return x.g.id; }).join(',') + '"><i data-lucide="bell"></i> Remind all ' + silent.length + '</button></div>';
        }
        html += '<section class="fr-panel"><div class="fr-panel-h"><div><h2>Who has sent what</h2><p>One row per group, one column per requirement. ' +
            (S.subItem ? 'Showing who has <b>not sent ' + esc((it.filter(function (x) { return x.id === S.subItem; })[0] || {}).label) + '</b>. ' : 'Tap a column to see who has not sent it. ') +
            '</p></div><div class="fr-chips">' +
            SUBF.map(function (x) {
                var c = rows.filter(function (r) { return x[2](r.s); }).length;
                return '<button type="button" class="fr-chip ' + (x[0] === S.subF ? 'on' : '') + '" data-subf="' + x[0] + '">' + x[1] + ' <em>' + c + '</em></button>';
            }).join('') + (S.subItem ? '<button type="button" class="fr-chip on" data-subitem="0"><i data-lucide="x"></i> Column filter</button>' : '') +
            '</div></div>';
        if (!list.length) {
            html += '<p class="fr-empty">No group matches.</p>';
        } else {
            html += '<div class="fr-mx-wrap"><table class="fr-mx"><thead><tr><th class="g">Group</th>' +
                it.map(function (x) { return '<th><button type="button" class="' + (S.subItem === x.id ? 'on' : '') + '" data-subitem="' + x.id + '" title="Who has not sent ' + esc(x.label) + '">' + esc(x.label) + '</button></th>'; }).join('') +
                '<th>Status</th></tr></thead><tbody>' +
                list.map(function (x) {
                    return '<tr><td class="g" data-g="' + x.g.id + '"><b>' + esc(x.g.name) + '</b><small>' + esc(roomName(x.g.room)) + ' · ' + esc(x.g.leader) + '</small></td>' +
                        it.map(function (i) {
                            var d = x.g.docs[i.id], k = d ? d.st : 'N';
                            var tip = i.label + ': ' + (k === 'N' ? 'not sent yet' : k === 'A' ? 'approved' + (d.by ? ' by ' + d.by : '') + (d.at ? ', ' + d.at : '')
                                : k === 'R' ? 'sent back' + (d.at ? ' ' + d.at : '') : 'sent ' + d.up + ', waiting on you');
                            return '<td><span class="fr-cell c-' + k + '" title="' + esc(tip) + '" aria-label="' + esc(tip) + '"><i data-lucide="' + ICON[k] + '"></i></span></td>';
                        }).join('') +
                        '<td class="st">' + stChip(x.s.st) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        html += '<div class="fr-legend">' + ['A', 'P', 'R', 'N'].map(function (k) { return '<span class="fr-st s-' + k + '"><i data-lucide="' + ICON[k] + '"></i>' + WORD[k] + '</span>'; }).join('') + '</div></section>';
        return html;
    }

    // ---- 2. Requirements ----------------------------------------------------------------------
    function reqCounts(rows, id) {
        var c = { A: [], P: [], R: [], N: [] }, hrs = [];
        rows.forEach(function (x) { var d = x.g.docs[id], k = d ? d.st : 'N'; c[k].push(x); if (d && d.hrs != null) hrs.push(d.hrs); });
        return { c: c, avg: hrs.length ? hrs.reduce(function (a, b) { return a + b; }, 0) / hrs.length : null };
    }
    function days(h) { return h == null ? '—' : h < 24 ? Math.max(1, Math.round(h)) + ' h' : (h / 24).toFixed(1) + ' days'; }
    function renderReq(rows) {
        var it = items(), n = rows.length;
        if (!S.reqPick) {   // open on the requirement with the most groups that have not sent it
            var best = null;
            it.forEach(function (x) { var c = reqCounts(rows, x.id).c.N.length; if (!best || c > best[1]) best = [x.id, c]; });
            S.reqPick = best ? { id: best[0], k: 'N' } : null;
        }
        var html = '<div class="fr-grid2"><section class="fr-panel"><div class="fr-panel-h"><div><h2>Requirement by requirement</h2><p>Each bar is ' + plural(n, 'group') + '. Tap a part to see who is in it.</p></div></div>';
        it.forEach(function (x) {
            var rc = reqCounts(rows, x.id);
            html += '<div class="fr-req ' + (x.kid ? 'is-kid' : '') + '"><div class="fr-req-n"><b>' + esc(x.label) + '</b><small>' + rc.c.A.length + ' of ' + n + ' approved' + (x.kid ? ' · cleared with the Capsule' : '') + '</small></div><div class="fr-stack">' +
                ['A', 'P', 'R', 'N'].map(function (k) {
                    var v = rc.c[k].length; if (!v) return '';
                    var on = S.reqPick && S.reqPick.id === x.id && S.reqPick.k === k;
                    return '<button type="button" class="fr-seg-bar k-' + k + (on ? ' on' : '') + '" style="flex:' + v + '" data-req="' + x.id + '" data-k="' + k + '" title="' + esc(x.label + ' — ' + WORD[k] + ': ' + v) + '">' + (v / n >= .08 ? v : '') + '</button>';
                }).join('') + '</div><div class="fr-req-t"><b>' + days(rc.avg) + '</b>to review, on average</div></div>';
        });
        html += '<div class="fr-legend">' + ['A', 'P', 'R', 'N'].map(function (k) { return '<span class="fr-st s-' + k + '"><i data-lucide="' + ICON[k] + '"></i>' + WORD[k] + '</span>'; }).join('') + '</div></section>';
        var pick = S.reqPick, label = (it.filter(function (x) { return pick && x.id === pick.id; })[0] || {}).label || '';
        var who = pick ? reqCounts(rows, pick.id).c[pick.k] : [];
        html += '<section class="fr-panel fr-list"><h3>' + esc(label) + '</h3><p>' + (pick ? stChip(pick.k) + ' · ' + plural(who.length, 'group') : '') + '</p>' +
            (who.length ? who.map(function (x) { return rowHtml(x, pick.k === 'N' ? (x.g.members.length + ' in group') : ((x.g.docs[pick.id] || {}).up ? 'sent ' + x.g.docs[pick.id].up : '')); }).join('') : '<p class="fr-empty">Nobody here.</p>') +
            '</section></div>';
        return html;
    }
    function rowHtml(x, right) {
        return '<button type="button" class="fr-row" data-g="' + x.g.id + '"><span class="fr-av">' + esc(letter(x.g.name)) + '</span><span class="fr-row-t"><b>' + esc(x.g.name) +
            '</b><small>' + esc(roomName(x.g.room)) + ' · ' + esc(x.g.leader) + (x.g.sid ? ' · ' + esc(x.g.sid) : '') + '</small></span><em>' + esc(right || '') + '</em></button>';
    }

    // ---- 3. Schedule --------------------------------------------------------------------------
    var FUN = [
        ['all', 'All groups', function () { return true; }],
        ['sent', 'Sent something', function (s) { return s.st !== 'none'; }],
        ['complete', 'Requirements complete', function (s) { return s.a === s.total; }],
        ['dated', 'Have a date', function (s) { return s.date && s.date.st !== 'back'; }],
        ['published', 'Published', function (s) { return s.date && s.date.st === 'published'; }],
        ['held', 'Already held', function (s) { return s.date && s.date.st === 'published' && s.date.dd < 0; }]
    ];
    function renderSch(rows) {
        var n = rows.length;
        var bs = FR.batches.filter(function (b) { return b.phase === S.phase && (!S.room || b.room === S.room); });
        var pub = bs.filter(function (b) { return b.st === 'Scheduled'; }), back = bs.filter(function (b) { return b.back; }), wd = bs.filter(function (b) { return b.st === 'Cancelled'; });
        var decided = pub.length + bs.filter(function (b) { return b.st === 'Returned' || b.st === 'Cancelled'; }).length;
        var chain = pub.filter(function (b) { return b.h1 != null && b.h2 != null; }).map(function (b) { return b.h1 + b.h2; });
        var avg = chain.length ? chain.reduce(function (a, b) { return a + b; }, 0) / chain.length : null;
        var html = '<div class="fr-stats">' +
            '<div class="fr-stat"><small>Dates you sent up</small><b>' + bs.length + '</b></div>' +
            '<div class="fr-stat"><small>Published first time</small><b>' + (decided ? Math.round(pub.filter(function (b) { return !b.back; }).length / decided * 100) + '%' : '—') + '</b></div>' +
            '<div class="fr-stat"><small>Sent back · withdrawn</small><b>' + back.length + ' · ' + wd.length + '</b></div>' +
            '<div class="fr-stat"><small>You to published, on average</small><b>' + days(avg) + '</b></div></div>';

        var pick = FUN.filter(function (x) { return x[0] === S.schPick; })[0] || FUN[2];
        html += '<div class="fr-grid2"><section class="fr-panel"><div class="fr-panel-h"><div><h2>How far the groups have got</h2><p>Each bar counts groups out of ' + n + '. Tap one for the names.</p></div></div><div class="fr-funnel">' +
            FUN.map(function (x) {
                var v = rows.filter(function (r) { return x[2](r.s); }).length, p = n ? v / n * 100 : 0;
                return '<button type="button" class="fr-fun ' + (x[0] === pick[0] ? 'on' : '') + '" data-fun="' + x[0] + '"><span>' + x[1] + '</span><span class="fr-fun-track"><i style="width:' + p + '%"></i></span><b>' + v + '<small>' + Math.round(p) + '%</small></b></button>';
            }).join('') + '</div></section>';
        var inStep = rows.filter(function (r) { return pick[2](r.s); }), out = rows.filter(function (r) { return !pick[2](r.s); });
        var who = S.schNeg ? out : inStep;
        html += '<section class="fr-panel fr-list"><div class="fr-panel-h"><div><h3>' + (S.schNeg ? 'Not yet: ' : '') + pick[1] + '</h3><p>' + plural(who.length, 'group') + '</p></div><div class="fr-chips">' +
            '<button type="button" class="fr-chip ' + (!S.schNeg ? 'on' : '') + '" data-neg="0">In it <em>' + inStep.length + '</em></button>' +
            '<button type="button" class="fr-chip ' + (S.schNeg ? 'on' : '') + '" data-neg="1">Not yet <em>' + out.length + '</em></button></div></div>' +
            (who.length ? who.map(function (x) {
                var d = x.s.date;
                return rowHtml(x, d ? (d.md + ' · ' + DATE_WORD[d.st]) : (x.s.a + ' of ' + x.s.total + ' approved'));
            }).join('') : '<p class="fr-empty">Nobody here.</p>') + '</section></div>';

        // Every date she has sent up the chain, and how it went.
        var OUT = { Scheduled: ['Published', 'complete'], Endorsed: ['With the Coordinator', 'progress'], Confirmed: ['With the Secretary', 'progress'],
                    Returned: ['Sent back', 'R'], Cancelled: ['Withdrawn', 'none'] };
        html += '<section class="fr-panel"><div class="fr-panel-h"><div><h2>Dates you sent up</h2><p>Newest first, with how long each step of the chain took.</p></div></div>' +
            (bs.length ? '<div class="fr-mx-wrap"><table class="fr-table"><thead><tr><th>Date</th><th>Room</th><th class="num">Groups</th><th>Outcome</th><th class="num">You → Coordinator</th><th class="num">Coordinator → Secretary</th></tr></thead><tbody>' +
            bs.map(function (b) {
                var o = OUT[b.st] || [b.st, 'none'];
                return '<tr><td>' + esc(b.md) + (b.st === 'Scheduled' && b.dd < 0 ? '<small>held</small>' : '') + '</td><td>' + esc(roomName(b.room)) + '</td><td class="num">' + (b.n || '—') + '</td><td>' +
                    stChip(o[1], o[0] + (b.back && b.st !== 'Returned' ? ' (was sent back)' : '')) + '</td><td class="num">' + days(b.h1) + '</td><td class="num">' + days(b.h2) + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<p class="fr-empty">You have not sent a date up yet in this phase.</p>') + '</section>';
        return html;
    }

    // ---- 4. Groups ----------------------------------------------------------------------------
    var COLS = [
        ['name', 'Group', function (x) { return x.g.name.toLowerCase(); }],
        ['room', 'Room', function (x) { return roomName(x.g.room); }],
        ['leader', 'Leader', function (x) { return x.g.leader.toLowerCase(); }],
        ['members', 'Members', function (x) { return x.g.members.length; }],
        ['prog', 'Progress', function (x) { return x.s.a / (x.s.total || 1); }],
        ['last', 'Last sent', function (x) { return x.g.last[S.phase] || 0; }],
        ['date', 'Defense date', function (x) { return x.s.date ? x.s.date.dd : 99999; }]
    ];
    function renderGrp(rows) {
        var col = COLS.filter(function (c) { return c[0] === S.sort[0]; })[0] || COLS[0];
        rows.sort(function (a, b) { var p = col[2](a), q = col[2](b); return (p < q ? -1 : p > q ? 1 : 0) * S.sort[1]; });
        return '<section class="fr-panel"><div class="fr-panel-h"><div><h2>Every group</h2><p>' + plural(rows.length, 'group') + ' with their members. Tap a heading to sort, a row for everything about the group.</p></div></div>' +
            (rows.length ? '<div class="fr-mx-wrap"><table class="fr-table"><thead><tr>' + COLS.map(function (c) {
                return '<th><button type="button" data-sort="' + c[0] + '">' + c[1] + (S.sort[0] === c[0] ? (S.sort[1] > 0 ? ' ↑' : ' ↓') : '') + '</button></th>';
            }).join('') + '<th>Status</th></tr></thead><tbody>' + rows.map(function (x) {
                var last = x.g.last[S.phase];
                return '<tr class="click" data-g="' + x.g.id + '"><td><b>' + esc(x.g.name) + '</b>' + (x.g.title && x.g.title !== x.g.name ? '<small>' + esc(x.g.title) + '</small>' : '') + '</td>' +
                    '<td>' + esc(roomName(x.g.room)) + '</td><td>' + esc(x.g.leader) + (x.g.sid ? '<small>' + esc(x.g.sid) + '</small>' : '') + '</td>' +
                    '<td class="fr-mem">' + x.g.members.map(function (m) { return esc(m.n); }).join(', ') + '</td>' +
                    '<td>' + pips(x.g) + '<small>' + x.s.a + ' of ' + x.s.total + ' approved</small></td>' +
                    '<td>' + (last ? new Date(last * 1000).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '<small>nothing yet</small>') + '</td>' +
                    '<td>' + (x.s.date ? esc(x.s.date.md) + '<small>' + DATE_WORD[x.s.date.st] + (x.s.date.slot ? ', slot ' + x.s.date.slot : '') + '</small>' : '<small>none</small>') + '</td>' +
                    '<td>' + stChip(x.s.st) + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<p class="fr-empty">No group matches.</p>') + '</section>';
    }
    function pips(g) {
        return '<span class="fr-pips">' + items().map(function (i) { var d = g.docs[i.id]; return '<i class="' + (d ? d.st : '') + '"></i>'; }).join('') + '</span>';
    }

    // ---- render -------------------------------------------------------------------------------
    function rpHint(h) { return h.replace(/(Tap [^.<]*\.)/g, '<span class="rp-hint">$1</span>'); }   // screen-only instructions, hidden on paper (print.css)
    function render() {
        var rows = rowsOf(scoped());
        kpis(rows);
        $('frTitle').textContent = { sub: 'Who has sent what', req: 'Requirement by requirement', sch: 'How the dates are going', grp: 'Every group' }[S.tab];
        $('frBody').innerHTML = rpHint(S.tab === 'req' ? renderReq(rows) : S.tab === 'sch' ? renderSch(rows) : S.tab === 'grp' ? renderGrp(rows) : renderSub(rows));
        document.body.classList.toggle('rp-wide', S.tab === 'sub' || S.tab === 'grp' || S.tab === 'sch');   // the matrix and the tables print landscape
        icons();
    }

    // ---- the group drawer ---------------------------------------------------------------------
    function byId(id) { return FR.groups.filter(function (g) { return g.id === id; })[0]; }
    window.frDrawer = function (id) {
        var dr = $('frDrawer'), bg = $('frDbg');
        if (!id) { dr.classList.remove('on'); bg.classList.remove('on'); dr.setAttribute('aria-hidden', 'true'); return; }
        var g = byId(id); if (!g) return;
        var s = state(g), r = roomOf(g.room);
        var html = '<div class="fr-dr"><div class="fr-dr-top"><span class="fr-av">' + esc(letter(g.name)) + '</span><div><h3>' + esc(g.name) + '</h3><p>' + esc(roomName(g.room)) +
            (r ? ' · ' + esc(r.program) : '') + '</p></div><button type="button" class="fr-dr-x" onclick="frDrawer(null)" aria-label="Close"><i data-lucide="x"></i></button></div>' +
            (g.title && g.title !== g.name ? '<p class="fr-dr-title">' + esc(g.title) + '</p>' : '') +
            '<p style="margin:12px 0 0">' + stChip(s.st) + ' &nbsp; ' + s.a + ' of ' + s.total + ' approved · ' + phaseWord() + '</p>' +
            '<h4>Members</h4><div class="fr-dr-mem">' + g.members.map(function (m, i) {
                return '<div><span>' + esc(m.n) + (i === 0 ? ' <small>(leader)</small>' : '') + '</span><small>' + esc(m.s || 'no ID') + '<br>' + esc(m.e) + '</small></div>';
            }).join('') + '</div><h4>' + phaseWord() + ' requirements</h4>' +
            items().map(function (i) {
                var d = g.docs[i.id], k = d ? d.st : 'N';
                var line = k === 'N' ? 'Not sent yet' : k === 'P' ? 'Sent ' + d.up + ', waiting on you'
                    : (k === 'A' ? 'Approved' : 'Sent back') + (d.by ? ' by ' + d.by + (d.role ? ' (' + d.role + ')' : '') : '') + (d.at ? ', ' + d.at : '') + ' · sent ' + d.up;
                return '<div class="fr-dr-req ' + (i.kid ? 'is-kid' : '') + '"><span class="fr-st s-' + k + '"><i data-lucide="' + ICON[k] + '"></i></span><div><b>' + esc(i.label) + '</b><small>' + esc(line) + '</small>' +
                    (k === 'R' && d.rm ? '<div class="fr-dr-rm">' + esc(d.rm) + '</div>' : '') + '</div></div>';
            }).join('') +
            '<h4>Defense date</h4><p style="margin:0;font-size:13.5px">' + (s.date ? '<b>' + esc(s.date.md) + '</b>' + (s.date.slot ? ', slot ' + s.date.slot : '') + ' · ' + DATE_WORD[s.date.st] : 'Not on a date yet') + '</p>' +
            '<button type="button" class="fr-go fr-dr-go" onclick="frOpenGroup(' + g.id + ')"><i data-lucide="file-check"></i> Open in Requirements</button></div>';
        dr.innerHTML = html; dr.classList.add('on'); bg.classList.add('on'); dr.setAttribute('aria-hidden', 'false');
        icons();
    };
    // Opens the group's review screen in the shell's Requirements window (or here, standing alone).
    window.frOpenGroup = function (id) {
        var g = byId(id), r = roomOf(g.room);
        var url = 'focal_desk.php?view=requirements&phase=' + S.phase + (r ? '&course=' + encodeURIComponent(r.course) + '&section=' + g.room + '&group=' + g.id : '');
        try { if (window.top !== window && typeof window.top.shOpenDesk === 'function') { window.top.shOpenDesk(url); return; } } catch (e) {}
        location.href = url;
    };
    window.frToHub = function () {
        try { if (window.top !== window && typeof window.top.shShow === 'function') { window.top.shShow('hub-reports'); return false; } } catch (e) {}
        return true;
    };

    // ---- export: the open report as CSV (opens in Excel) -----------------------------------------
    window.frExport = function () {
        var rows = rowsOf(scoped()), it = items(), out = [];
        if (S.tab === 'req') {
            out.push(['Requirement', 'Approved', 'Waiting on you', 'Sent back', 'Not sent', 'Average review (hours)']);
            it.forEach(function (x) { var rc = reqCounts(rows, x.id); out.push([x.label, rc.c.A.length, rc.c.P.length, rc.c.R.length, rc.c.N.length, rc.avg == null ? '' : Math.round(rc.avg)]); });
        } else if (S.tab === 'sch') {
            out.push(['Date', 'Room', 'Groups', 'Outcome', 'Was sent back', 'You to Coordinator (hours)', 'Coordinator to Secretary (hours)']);
            FR.batches.filter(function (b) { return b.phase === S.phase && (!S.room || b.room === S.room); })
                .forEach(function (b) { out.push([b.md, roomName(b.room), b.n, b.st, b.back ? 'yes' : 'no', b.h1 == null ? '' : b.h1, b.h2 == null ? '' : b.h2]); });
        } else if (S.tab === 'grp') {
            out.push(['Group', 'Research title', 'Room', 'Leader', 'Leader School ID', 'Members', 'Approved', 'Of', 'Last sent', 'Defense date', 'Date status', 'Status']);
            rows.forEach(function (x) {
                out.push([x.g.name, x.g.title, roomName(x.g.room), x.g.leader, x.g.sid, x.g.members.map(function (m) { return m.n + (m.s ? ' (' + m.s + ')' : ''); }).join('; '),
                          x.s.a, x.s.total, x.g.last[S.phase] ? new Date(x.g.last[S.phase] * 1000).toISOString().slice(0, 10) : '',
                          x.s.date ? x.s.date.md : '', x.s.date ? DATE_WORD[x.s.date.st] : '', GST[x.s.st][0]]);
            });
        } else {
            out.push(['Group', 'Room', 'Leader', 'Leader School ID', 'Members'].concat(it.map(function (x) { return x.label; })).concat(['Status']));
            var f = SUBF.filter(function (x) { return x[0] === S.subF; })[0] || SUBF[0];
            rows.filter(function (x) { return f[2](x.s) && (!S.subItem || !x.g.docs[S.subItem]); }).forEach(function (x) {
                out.push([x.g.name, roomName(x.g.room), x.g.leader, x.g.sid, x.g.members.map(function (m) { return m.n; }).join('; ')]
                    .concat(it.map(function (i) { var d = x.g.docs[i.id]; return WORD[d ? d.st : 'N']; })).concat([GST[x.s.st][0]]));
            });
        }
        var csv = '﻿' + out.map(function (r) { return r.map(function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'focal-report-' + { sub: 'submissions', req: 'requirements', sch: 'schedule', grp: 'groups' }[S.tab] + '-' + S.phase + (S.room ? '-' + roomName(S.room).replace(/\W+/g, '') : '') + '-' + FR.today + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
    };

    // ---- events -------------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-g],[data-subf],[data-subitem],[data-req],[data-fun],[data-neg],[data-sort],[data-remind],[data-t],#frPhase button,#frMapPhase button');
        if (!t) return;
        if (t.matches('#frPhase button, #frMapPhase button')) return setPhase(t.dataset.p);
        if (t.dataset.t) { S.tab = t.dataset.t; document.querySelectorAll('.fr-tabs button').forEach(function (b) { b.classList.toggle('on', b === t); }); return render(); }
        if (t.dataset.remind) {
            var ids = t.dataset.remind.split(',');
            if (!confirm('Email the leaders of ' + plural(ids.length, 'group') + ' that have sent nothing yet?')) return;
            $('frRemindPhase').value = S.phase;
            $('frRemindIds').innerHTML = ids.map(function (i) { return '<input type="hidden" name="user_ids[]" value="' + parseInt(i, 10) + '">'; }).join('');
            return $('frRemind').submit();
        }
        if (t.dataset.subf) { S.subF = t.dataset.subf; return render(); }
        if (t.dataset.subitem !== undefined) { var v = parseInt(t.dataset.subitem, 10); S.subItem = S.subItem === v ? 0 : v; return render(); }
        if (t.dataset.req) { S.reqPick = { id: parseInt(t.dataset.req, 10), k: t.dataset.k }; return render(); }
        if (t.dataset.fun) { S.schPick = t.dataset.fun; return render(); }
        if (t.dataset.neg !== undefined) { S.schNeg = t.dataset.neg === '1'; return render(); }
        if (t.dataset.sort) { S.sort = [t.dataset.sort, S.sort[0] === t.dataset.sort ? -S.sort[1] : 1]; return render(); }
        if (t.dataset.g) frDrawer(parseInt(t.dataset.g, 10));
    });
    $('frRoom').addEventListener('change', function () { S.room = parseInt(this.value, 10) || 0; S.reqPick = null; render(); });
    $('frQ').addEventListener('input', function () { S.q = this.value; render(); });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if ($('frDrawer').classList.contains('on')) frDrawer(null); else if (!$('frMap').hidden) frMapClose();
    });

    /* ============================== VISUALIZE ==============================
       Four stacked discs — courses, rooms, groups, defense dates — with a bar per group
       (height = how much is approved, colour = where it stands) and the lines that tie a
       group to its room, its course and its date. Hover traces the path; click opens it. */
    var M = { yaw: 0.7, pitch: 0.34, zoom: 1, drag: null, moved: false, hover: null, raf: 0, W: 0, H: 0, dpr: 1, idle: true };
    var COL = { none: '#78716c', progress: '#fab219', complete: '#0ca30c', dated: '#9f7aea' };
    var LAY = [
        { key: 'course', y: -230, r: 300, label: 'Courses' },
        { key: 'room',   y: -100, r: 330, label: 'Rooms' },
        { key: 'group',  y: 40,   r: 400, label: 'Groups' },
        { key: 'date',   y: 330,  r: 250, label: 'Defense dates' }
    ];
    var G = null;   // the built model

    function hash(n) { var x = Math.sin(n * 12.9898) * 43758.5453; return x - Math.floor(x); }
    function mapBuild() {
        var groups = FR.groups.slice().sort(function (a, b) { return roomName(a.room).localeCompare(roomName(b.room)) || a.name.localeCompare(b.name); });
        var roomIds = [], courses = [], cIdx = {};
        groups.forEach(function (g) { if (roomIds.indexOf(g.room) === -1) roomIds.push(g.room); });
        var nodes = [], links = [], bars = [], dates = {}, TAU = Math.PI * 2;
        var rooms = roomIds.map(function (id, i) {
            var a = TAU * i / roomIds.length + 0.35, r = roomOf(id), ck = r ? r.course : '_none';
            var n = { kind: 'room', id: id, a: a, label: roomName(id), course: ck, x: Math.cos(a) * LAY[1].r * .78, y: LAY[1].y, z: Math.sin(a) * LAY[1].r * .78 };
            if (!(ck in cIdx)) { cIdx[ck] = courses.length; courses.push({ key: ck, label: r ? r.program : 'No room yet', tag: r ? r.tag : '', angs: [] }); }
            courses[cIdx[ck]].angs.push(a);
            nodes.push(n); return n;
        });
        courses.forEach(function (c) {
            var sx = 0, sz = 0; c.angs.forEach(function (a) { sx += Math.cos(a); sz += Math.sin(a); });
            var a = courses.length === 1 ? 0.35 : Math.atan2(sz, sx);
            var rr = courses.length === 1 ? 0 : LAY[0].r * .7;
            c.node = { kind: 'course', id: c.key, label: c.label, x: Math.cos(a) * rr, y: LAY[0].y, z: Math.sin(a) * rr };
            nodes.push(c.node);
        });
        rooms.forEach(function (n) { links.push([courses[cIdx[n.course]].node, n]); });
        groups.forEach(function (g, i) {
            var s = state(g), a = TAU * i / groups.length + 0.35 + (hash(g.id) - .5) * .08, rr = LAY[2].r * (.55 + .38 * hash(g.id + 7));
            var b = { kind: 'group', g: g, s: s, x: Math.cos(a) * rr, y: LAY[2].y, z: Math.sin(a) * rr, h: 16 + 130 * (s.a / (s.total || 1)), col: COL[s.st] };
            b.room = rooms[roomIds.indexOf(g.room)];
            links.push([b.room, b, 'base']);
            if (s.date && s.date.st !== 'back') (dates[s.date.md] = dates[s.date.md] || { md: s.date.md, dd: s.date.dd, bars: [] }).bars.push(b);
            bars.push(b);
        });
        var dl = Object.keys(dates).map(function (k) { return dates[k]; }).sort(function (a, b) { return a.dd - b.dd; });
        dl.forEach(function (d, i) {
            var a = TAU * i / Math.max(1, dl.length) + 1.1, rr = dl.length === 1 ? 0 : LAY[3].r * .62;
            d.node = { kind: 'date', id: d.md, label: d.md + (d.dd < 0 ? ' · held' : ''), x: Math.cos(a) * rr, y: LAY[3].y, z: Math.sin(a) * rr, bars: d.bars };
            nodes.push(d.node);
            d.bars.forEach(function (b) { b.date = d.node; links.push([b, d.node, 'top']); });
        });
        G = { nodes: nodes, links: links, bars: bars, courses: courses, rooms: rooms, dates: dl };
        var counts = [courses.length, rooms.length, bars.length, dl.length];
        LAY.forEach(function (l, i) { l.n = counts[i]; });
        $('frHud1').textContent = 'Focal overview · ' + phaseWord() + ' defense · ' + plural(bars.length, 'group') + ' · ' + plural(rooms.length, 'room') + ' · ' + plural(dl.length, 'date');
        $('frMapLegend').innerHTML = ['none', 'progress', 'complete', 'dated'].map(function (k) {
            return '<span><i style="background:' + COL[k] + '"></i>' + GST[k][0] + ' (' + bars.filter(function (b) { return b.s.st === k; }).length + ')</span>';
        }).join('') + '<span>Bar height = share of requirements approved</span>';
    }

    function proj(x, y, z) {
        var cy = Math.cos(M.yaw), sy = Math.sin(M.yaw), cp = Math.cos(M.pitch), sp = Math.sin(M.pitch);
        var rx = x * cy - z * sy, rz = x * sy + z * cy;
        var d = rz * cp + (y - 50) * sp;                          // toward the viewer
        var k = 1500 / (1500 - d);
        var sc = M.base * M.zoom * k;
        return { x: M.W / 2 + rx * sc, y: M.H * 0.49 - ((y - 50) * cp - rz * sp) * sc, d: d, k: k, sc: sc };
    }
    function hexA(hex, a) { var n = parseInt(hex.slice(1), 16); return 'rgba(' + (n >> 16) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')'; }
    function shade(hex, f) {
        var n = parseInt(hex.slice(1), 16), r = n >> 16, g = n >> 8 & 255, b = n & 255;
        function c(v) { return Math.max(0, Math.min(255, Math.round(f >= 1 ? v + (255 - v) * (f - 1) : v * f))); }
        return 'rgb(' + c(r) + ',' + c(g) + ',' + c(b) + ')';
    }
    function lit(set, item) { return !M.hover || set.indexOf(item) !== -1; }

    function drawFrame() {
        var c = $('frCanvas'), ctx = c.getContext('2d');
        ctx.setTransform(M.dpr, 0, 0, M.dpr, 0, 0);
        ctx.clearRect(0, 0, M.W, M.H);
        if (!G) return;
        var hl = M.hover ? M.hover.set : [];

        // discs, bottom to top, with their labels at the front edge
        LAY.forEach(function (l, li) {
            ctx.beginPath();
            for (var i = 0; i <= 96; i++) {
                var a = Math.PI * 2 * i / 96, p = proj(Math.cos(a) * l.r, l.y, Math.sin(a) * l.r);
                if (i) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y);
            }
            ctx.fillStyle = li === 2 ? 'rgba(255,255,255,0.045)' : 'rgba(255,255,255,0.025)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,0.22)'; ctx.lineWidth = 1; ctx.stroke();
            var fx = Math.sin(M.yaw) * l.r, fz = Math.cos(M.yaw) * l.r, fp = proj(fx, l.y, fz);
            ctx.font = '600 11px "JetBrains Mono", monospace'; ctx.textAlign = 'center';
            ctx.fillStyle = 'rgba(210,204,230,0.75)';
            ctx.fillText(('0' + (li + 1)).slice(-2) + ' · ' + l.label.toUpperCase() + ' · ' + (l.n || 0), fp.x, fp.y + 18);
        });

        // the lines that tie it together
        G.links.forEach(function (lk) {
            var a = lk[0], b = lk[1];
            var ay = a.kind === 'group' ? a.y + a.h : a.y, by = b.kind === 'group' ? (lk[2] === 'base' ? b.y : b.y + b.h) : b.y;
            if (lk[2] === 'base' && b.kind === 'group') by = b.y;
            var p = proj(a.x, ay, a.z), q = proj(b.x, by, b.z), on = M.hover && hl.indexOf(a) !== -1 && hl.indexOf(b) !== -1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y);
            ctx.strokeStyle = on ? '#f5d90a' : (M.hover ? 'rgba(255,255,255,0.05)' : 'rgba(255,255,255,0.14)');
            ctx.lineWidth = on ? 2 : 1; ctx.stroke();
        });

        // nodes on the flat layers
        G.nodes.forEach(function (n) {
            var p = proj(n.x, n.y, n.z), r = (n.kind === 'course' ? 7 : 5) * p.sc;
            var on = lit(hl, n);
            ctx.beginPath(); ctx.ellipse(p.x, p.y, Math.max(2.5, r), Math.max(1.4, r * Math.sin(M.pitch)), 0, 0, Math.PI * 2);
            ctx.fillStyle = n.kind === 'date' ? hexA('#f5d90a', on ? .95 : .25) : 'rgba(233,230,242,' + (on ? .9 : .2) + ')';
            ctx.fill();
            n._p = p;
        });

        // the bars, far to near, each a lit box
        var order = G.bars.slice().sort(function (a, b) { return proj(a.x, a.y, a.z).d - proj(b.x, b.y, b.z).d; });
        order.forEach(function (b) {
            var w = 7, on = lit(hl, b), alpha = on ? 1 : .22;
            var cs = [[-w, -w], [w, -w], [w, w], [-w, w]];
            var bot = cs.map(function (c) { return proj(b.x + c[0], b.y, b.z + c[1]); });
            var top = cs.map(function (c) { return proj(b.x + c[0], b.y + b.h, b.z + c[1]); });
            var faces = [0, 1, 2, 3].map(function (i) { var j = (i + 1) % 4; return { pts: [bot[i], bot[j], top[j], top[i]], d: (bot[i].d + bot[j].d) / 2, f: i % 2 ? .62 : .8 }; });
            faces.sort(function (a, c) { return a.d - c.d; });
            ctx.globalAlpha = alpha;
            faces.forEach(function (f) { poly(ctx, f.pts); ctx.fillStyle = shade(b.col, f.f); ctx.fill(); });
            poly(ctx, top); ctx.fillStyle = shade(b.col, 1.25); ctx.fill();
            ctx.globalAlpha = 1;
            var xs = bot.concat(top).map(function (p) { return p.x; }), ys = bot.concat(top).map(function (p) { return p.y; });
            b._box = [Math.min.apply(null, xs), Math.min.apply(null, ys), Math.max.apply(null, xs), Math.max.apply(null, ys)];
            b._d = (bot[0].d + bot[2].d) / 2;
            b._top = proj(b.x, b.y + b.h, b.z);
        });

        // labels: courses and dates always, the path while hovering
        ctx.textAlign = 'left'; ctx.font = '600 11px "JetBrains Mono", monospace';
        G.nodes.forEach(function (n) {
            var show = n.kind === 'course' || n.kind === 'date' || (M.hover && hl.indexOf(n) !== -1) || (n.kind === 'room' && G.rooms.length <= 6);
            if (!show) return;
            // A course sits in the middle of its disc: its name goes underneath, clear of the rooms.
            if (n.kind === 'course') {
                var tw = ctx.measureText(String(n.label).toUpperCase()).width;
                label(ctx, n._p.x - tw / 2, n._p.y + 22, n.label, M.hover && hl.indexOf(n) !== -1);
            } else {
                label(ctx, n._p.x + 8, n._p.y - 6, n.label, M.hover && hl.indexOf(n) !== -1);
            }
        });
        if (M.hover && M.hover.bar) label(ctx, M.hover.bar._top.x + 10, M.hover.bar._top.y - 4, M.hover.bar.g.name, true);
    }
    function poly(ctx, pts) { ctx.beginPath(); pts.forEach(function (p, i) { if (i) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y); }); ctx.closePath(); }
    function label(ctx, x, y, text, hot) {
        text = String(text).toUpperCase();
        if (text.length > 34) text = text.slice(0, 33) + '…';
        var w = ctx.measureText(text).width;
        ctx.fillStyle = hot ? 'rgba(20,16,4,0.85)' : 'rgba(10,8,16,0.7)';
        ctx.fillRect(x - 4, y - 11, w + 8, 16);
        ctx.fillStyle = hot ? '#f5d90a' : 'rgba(233,230,242,0.92)';
        ctx.fillText(text, x, y + 1);
    }

    function setHover(target) {
        if (!target) { M.hover = null; $('frHud2').textContent = ''; $('frTip').hidden = true; return; }
        var set = [], path = [];
        if (target.kind === 'group') {
            var b = target, course = G.courses.filter(function (c) { return c.key === b.room.course; })[0];
            set = [course.node, b.room, b].concat(b.date ? [b.date] : []);
            path = [course.node.label, b.room.label, b.g.name].concat(b.date ? [b.date.label] : []);
            M.hover = { set: set, bar: b };
        } else if (target.kind === 'room') {
            var bs = G.bars.filter(function (x) { return x.room === target; });
            var co = G.courses.filter(function (c) { return c.key === target.course; })[0];
            set = [co.node, target].concat(bs).concat(bs.map(function (x) { return x.date; }).filter(Boolean));
            path = [co.node.label, target.label, plural(bs.length, 'group')];
            M.hover = { set: set };
        } else if (target.kind === 'date') {
            set = [target].concat(target.bars).concat(target.bars.map(function (x) { return x.room; }));
            G.courses.forEach(function (c) { if (target.bars.some(function (x) { return x.room.course === c.key; })) set.push(c.node); });
            path = [target.label, plural(target.bars.length, 'group')];
            M.hover = { set: set };
        } else {
            var rs = G.rooms.filter(function (r) { return r.course === target.id; });
            var gb = G.bars.filter(function (x) { return rs.indexOf(x.room) !== -1; });
            set = [target].concat(rs).concat(gb).concat(gb.map(function (x) { return x.date; }).filter(Boolean));
            path = [target.label, plural(rs.length, 'room'), plural(gb.length, 'group')];
            M.hover = { set: set };
        }
        $('frHud2').textContent = 'Path · ' + path.join(' → ');
    }
    function pick(mx, my) {
        if (!G) return null;
        var hit = null;
        G.bars.forEach(function (b) {
            if (!b._box) return;
            if (mx >= b._box[0] - 3 && mx <= b._box[2] + 3 && my >= b._box[1] - 3 && my <= b._box[3] + 3 && (!hit || b._d > hit._d)) hit = b;
        });
        if (hit) return hit;
        var best = null, bd = 144;
        G.nodes.forEach(function (n) { if (!n._p) return; var dx = n._p.x - mx, dy = n._p.y - my, d = dx * dx + dy * dy; if (d < bd) { bd = d; best = n; } });
        return best;
    }
    function tip(t, mx, my) {
        var el = $('frTip');
        if (!t || t.kind !== 'group') { el.hidden = true; return; }
        var s = t.s;
        el.innerHTML = '<b>' + esc(t.g.name) + '</b><small>' + esc(roomName(t.g.room)) + ' · ' + esc(t.g.leader) + '</small><br>' + GST[s.st][0] + ' · ' + s.a + ' of ' + s.total + ' approved' +
            (s.p ? '<br>' + plural(s.p, 'file') + ' waiting on you' : '') + (s.date ? '<br>Date: ' + esc(s.date.md) + ' (' + DATE_WORD[s.date.st] + ')' : '');
        el.hidden = false;
        el.style.left = Math.min(mx + 16, M.W - 290) + 'px'; el.style.top = Math.min(my + 16, M.H - 120) + 'px';
    }

    function loop() {
        if ($('frMap').hidden) { M.raf = 0; return; }
        if (!M.drag && !M.hover && !reduce) M.yaw += 0.0018;
        drawFrame();
        M.raf = requestAnimationFrame(loop);
    }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function resize() {
        var c = $('frCanvas');
        M.dpr = window.devicePixelRatio || 1; M.W = c.clientWidth; M.H = c.clientHeight;
        c.width = Math.round(M.W * M.dpr); c.height = Math.round(M.H * M.dpr);
        M.base = Math.min(M.W / 1150, M.H / 1080);
    }
    window.frMapOpen = function () {
        $('frMap').hidden = false; document.body.style.overflow = 'hidden';
        resize(); mapBuild(); icons();
        if (!M.raf) M.raf = requestAnimationFrame(loop);
    };
    window.frMapClose = function () { $('frMap').hidden = true; document.body.style.overflow = ''; setHover(null); };

    (function bindMap() {
        var c = $('frCanvas');
        c.addEventListener('pointerdown', function (e) { M.drag = { x: e.clientX, y: e.clientY }; M.moved = false; c.setPointerCapture(e.pointerId); c.classList.add('dragging'); });
        c.addEventListener('pointermove', function (e) {
            var r = c.getBoundingClientRect(), mx = e.clientX - r.left, my = e.clientY - r.top;
            if (M.drag) {
                var dx = e.clientX - M.drag.x, dy = e.clientY - M.drag.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) M.moved = true;
                M.yaw += dx * 0.006; M.pitch = Math.max(0.12, Math.min(1.0, M.pitch + dy * 0.004));
                M.drag = { x: e.clientX, y: e.clientY };
                return;
            }
            var t = pick(mx, my);
            setHover(t); tip(t, mx, my);
            c.style.cursor = t ? 'pointer' : 'grab';
        });
        c.addEventListener('pointerup', function (e) {
            var r = c.getBoundingClientRect(), wasDrag = M.moved;
            M.drag = null; c.classList.remove('dragging');
            if (!wasDrag) { var t = pick(e.clientX - r.left, e.clientY - r.top); if (t && t.kind === 'group') frDrawer(t.g.id); }
        });
        c.addEventListener('pointerleave', function () { if (!M.drag) { setHover(null); $('frTip').hidden = true; } });
        c.addEventListener('wheel', function (e) { e.preventDefault(); M.zoom = Math.max(0.55, Math.min(2.4, M.zoom * Math.exp(-e.deltaY * 0.001))); }, { passive: false });
        window.addEventListener('resize', function () { if (!$('frMap').hidden) resize(); });
    })();

    // ---- boot ---------------------------------------------------------------------------------
    buildRoomSelect();
    render();
    if (FR_START_MAP) frMapOpen();
})();
