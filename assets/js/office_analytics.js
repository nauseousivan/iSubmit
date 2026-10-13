/* =============================================================================
   The office's Analytics (2026-10-01). dashboards/office_analytics.php.
   Where the groups are (by institution, course, stage) and what happened when
   (sent in, approved, sent back, defenses held) — every number has names behind it.
   "Month" means ACTIVITY in that month; the stage of each group is always as of now.
   ========================================================================== */
(function () {
    'use strict';
    var esc = IN.esc, plural = IN.plural;
    var F = { ym: '', inst: '', course: '', stage: '', q: '', tab: 'over', sort: ['name', 1], pick: null };
    var STAGE = { proposal: ['Proposal', 'var(--c1)'], stats: ['Statistics', 'var(--c2)'], final: ['Final', 'var(--c3)'], plag: ['Plagiarism', 'var(--c4)'], done: ['Done', 'var(--c5)'] };
    var STAGES = ['proposal', 'stats', 'final', 'plag', 'done'];
    var KIND = { sub: ['Sent in', 'var(--c1)'], ok: ['Approved', 'var(--s-good)'], back: ['Sent back', 'var(--s-bad)'] };
    var BATCH = { Scheduled: ['Published', 'var(--s-good)'], Endorsed: ['With the Coordinator', 'var(--c1)'], Confirmed: ['With the Secretary', 'var(--c7)'], Returned: ['Sent back', 'var(--s-bad)'], Cancelled: ['Withdrawn', 'var(--s-none)'] };
    var tagOf = {}; OA.insts.forEach(function (i) { tagOf[i.key] = i.tag; });
    function $(id) { return document.getElementById(id); }
    function tag(d) { return tagOf[d] || d || '—'; }
    function mlabel(ym) { return new Date(ym + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' }); }
    function period() { return F.ym ? (OA.months.filter(function (m) { return m.ym === F.ym; })[0] || {}).label : 'all time'; }
    function sChip(s) { return '<span class="in-chip"><span class="in-dot" style="background:' + STAGE[s][1] + '"></span>' + STAGE[s][0] + '</span>'; }

    function fill() {
        $('fMonth').innerHTML = '<option value="">All time</option>' + OA.months.map(function (m) { return '<option value="' + m.ym + '">' + esc(m.label) + '</option>'; }).join('');
        $('fInst').innerHTML = (OA.insts.length > 1 ? '<option value="">Both institutions</option>' : '') + OA.insts.map(function (i) { return '<option value="' + esc(i.key) + '">' + esc(i.tag) + '</option>'; }).join('');
        $('fInstWrap').hidden = OA.insts.length < 2;
        $('fStage').innerHTML = '<option value="">Every stage</option>' + STAGES.map(function (s) { return '<option value="' + s + '">' + STAGE[s][0] + '</option>'; }).join('');
        courseOptions();
    }
    function courseOptions() {
        var cs = OA.courses.filter(function (c) { return !F.inst || c.dept === F.inst; });
        $('fCourse').innerHTML = '<option value="">Every course</option>' + cs.map(function (c) { return '<option value="' + esc(c.key) + '">' + esc(c.program) + (F.inst || OA.insts.length < 2 ? '' : ' (' + esc(tag(c.dept)) + ')') + '</option>'; }).join('');
        $('fCourse').value = F.course;
    }

    // ---- filtered views ---------------------------------------------------------------------
    function groups(noStage) {
        var q = F.q.toLowerCase().trim();
        return OA.groups.filter(function (g) {
            return (!F.inst || g.d === F.inst) && (!F.course || g.d + '::' + g.p === F.course) && (noStage || !F.stage || g.stage === F.stage) &&
                   (!q || (g.name + ' ' + g.leader + ' ' + g.room).toLowerCase().indexOf(q) !== -1);
        });
    }
    var STAGE_PH = { proposal: 'proposal', stats: 'stats', final: 'final', plag: 'plag', done: 'plag' };
    function activity(noMonth) {
        return OA.activity.filter(function (a) {
            return (noMonth || !F.ym || a.ym === F.ym) && (!F.inst || a.d === F.inst) && (!F.course || a.d + '::' + a.p === F.course) && (!F.stage || a.ph === STAGE_PH[F.stage]);
        });
    }
    function batches(noMonth) {
        return OA.batches.filter(function (b) {
            return (noMonth || !F.ym || b.ym === F.ym) && (!F.inst || b.d === F.inst) && (!F.course || b.d + '::' + b.p === F.course) &&
                   (!F.stage || (F.stage === 'proposal' ? b.ph === 'proposal' : F.stage === 'final' ? b.ph === 'final' : false));
        });
    }
    function sumK(rows, k) { return rows.filter(function (a) { return a.k === k; }).reduce(function (s, a) { return s + a.n; }, 0); }

    function tiles() {
        var gs = groups(), act = activity();
        var members = gs.reduce(function (s, g) { return s + g.members; }, 0);
        var done = gs.filter(function (g) { return g.stage === 'done'; }).length;
        $('inTiles').innerHTML = IN.tiles([
            { icon: 'users', label: 'Research groups', value: gs.length, sub: plural(members, 'student') + ' in them' },
            { icon: 'upload', label: 'Sent in', value: sumK(act, 'sub'), sub: 'documents · ' + period() },
            { icon: 'check-circle-2', label: 'Approved', value: sumK(act, 'ok'), sub: sumK(act, 'back') + ' sent back · ' + period() },
            { icon: 'calendar-check', label: 'Defenses held', value: sumK(act, 'def'), sub: period() },
            { icon: 'trophy', label: 'Cleared everything', value: done, of: gs.length, meter: [done, gs.length || 1, 'var(--c5)'], sub: 'Plagiarism cleared' }
        ]);
        $('inLede').textContent = 'The research groups · ' + (F.inst ? tag(F.inst) : OA.insts.length > 1 ? 'both institutions' : tag((OA.insts[0] || {}).key)) +
            (F.course ? ' · ' + F.course.split('::')[1] : '') + (F.stage ? ' · ' + STAGE[F.stage][0] : '') + ' · ' + period();
        $('inPrinted').textContent = 'Printed ' + new Date().toLocaleString('en-US') + ' · ' + $('inLede').textContent;
    }

    function monthCols(keys, rowsFn) {
        return OA.months.slice().reverse().map(function (m) {
            var vals = {}; keys.forEach(function (k) { vals[k.k] = rowsFn(m.ym, k.k); });
            return { x: mlabel(m.ym).split(' ')[0], label: m.label, vals: vals, act: 'month', val: m.ym };
        });
    }
    function whoPanel(title, list, w) {
        return '<section class="in-panel ' + (w || 'w12') + '"><div class="in-ph"><div><h2>' + esc(title) + '</h2><p>' + plural(list.length, 'group') + '.</p></div>' +
            '<span class="in-pick">Showing names <button type="button" data-act="unpick" aria-label="Close"><i data-lucide="x"></i></button></span></div><div class="in-list">' +
            (list.length ? list.map(function (g) { return '<div class="in-li"><div><b>' + esc(g.name) + '</b><small>' + esc(g.leader) + ' · ' + esc(tag(g.d)) + ' · ' + esc(g.p) + (g.room ? ' · ' + esc(g.room) : '') + '</small></div>' + sChip(g.stage) + '</div>'; }).join('') : '<p class="in-empty">Nobody here.</p>') + '</div></section>';
    }

    // ---- 1. Overview --------------------------------------------------------------------------
    function over() {
        var gs = groups(true);
        var donut = STAGES.map(function (s) { return { label: STAGE[s][0], value: gs.filter(function (g) { return g.stage === s; }).length, color: STAGE[s][1], act: 'stage', val: s }; });
        var keys = ['sub', 'ok', 'back'].map(function (k) { return { k: k, label: KIND[k][0], color: KIND[k][1] }; });
        var act = activity(true);
        var cols = monthCols(keys, function (ym, k) { return act.filter(function (a) { return a.ym === ym && a.k === k; }).reduce(function (s, a) { return s + a.n; }, 0); });
        var byC = {};
        groups().forEach(function (g) { var k = g.d + '::' + g.p; (byC[k] = byC[k] || { proposal: 0, stats: 0, final: 0, plag: 0, done: 0 })[g.stage]++; });
        var rows = Object.keys(byC).map(function (k) { return { label: k.split('::')[1], sub: tag(k.split('::')[0]), parts: byC[k], act: 'course', val: k, t: STAGES.reduce(function (s, x) { return s + byC[k][x]; }, 0) }; })
            .sort(function (a, b) { return b.t - a.t; }).slice(0, 10);
        return '<section class="in-panel w5"><h2>Where the groups are</h2><p>Each group by the stage it has reached. Tap a stage to filter.</p>' + IN.donut(donut, { unit: 'groups', label: 'Groups by stage' }) + '</section>' +
            '<section class="in-panel w7"><h2>Activity per month</h2><p>Documents sent in, approved and sent back. Tap a month to look at just that month.</p>' + IN.columns(cols, keys, { label: 'Activity per month' }) + '</section>' +
            '<section class="in-panel w12"><h2>Stage mix by course</h2><p>' + (rows.length === 10 ? 'The ten largest courses. ' : '') + 'Tap a course to filter everything to it.</p>' +
            IN.stack(rows, STAGES.map(function (s) { return { k: s, label: STAGE[s][0], color: STAGE[s][1] }; }), { empty: 'No groups in this selection.' }) + '</section>';
    }

    // ---- 2. Courses ---------------------------------------------------------------------------
    function course() {
        var gs = groups(), act = activity(), byC = {};
        gs.forEach(function (g) {
            var k = g.d + '::' + g.p, c = byC[k] = byC[k] || { key: k, name: g.p, inst: tag(g.d), groups: 0, students: 0, prop: 0, stats: 0, fin: 0, done: 0, sub: 0, ok: 0, back: 0, def: 0, rooms: {} };
            c.groups++; c.students += g.members; if (g.pa >= OA.np) c.prop++; if (g.stats >= 7) c.stats++; if (g.fa >= OA.nf) c.fin++; if (g.stage === 'done') c.done++; if (g.room) c.rooms[g.room] = 1;
        });
        act.forEach(function (a) { var c = byC[a.d + '::' + a.p]; if (c) c[a.k] += a.n; });
        var rows = Object.keys(byC).map(function (k) { var c = byC[k]; c.nrooms = Object.keys(c.rooms).length; return c; });
        var pct = function (v, of) { return of ? Math.round(v / of * 100) + '%' : '—'; };
        return '<section class="in-panel w12"><h2>Course by course</h2><p>' + plural(rows.length, 'course') + '. Completion columns count groups that finished that phase; activity is ' + period() + '. Tap a row to filter to it.</p>' +
            IN.table([
                { k: 'name', label: 'Course', html: function (c) { return '<b>' + esc(c.name) + '</b><small>' + esc(c.inst) + (c.nrooms ? ' · ' + plural(c.nrooms, 'room') : '') + '</small>'; } },
                { k: 'groups', label: 'Groups', num: true }, { k: 'students', label: 'Students', num: true },
                { k: 'prop', label: 'Proposal done', num: true, html: function (c) { return c.prop + '<small>' + pct(c.prop, c.groups) + '</small>'; } },
                { k: 'stats', label: 'Stats done', num: true, html: function (c) { return c.stats + '<small>' + pct(c.stats, c.groups) + '</small>'; } },
                { k: 'fin', label: 'Final done', num: true, html: function (c) { return c.fin + '<small>' + pct(c.fin, c.groups) + '</small>'; } },
                { k: 'done', label: 'Cleared', num: true, html: function (c) { return c.done + '<small>' + pct(c.done, c.groups) + '</small>'; } },
                { k: 'sub', label: 'Sent in', num: true }, { k: 'ok', label: 'Approved', num: true }, { k: 'back', label: 'Sent back', num: true }, { k: 'def', label: 'Defenses', num: true }
            ], rows, F.sort[0] === 'name' || byC[Object.keys(byC)[0]] && F.sort[0] in (byC[Object.keys(byC)[0]] || {}) ? F.sort : ['groups', -1], { rowAct: function (c) { return { act: 'course', val: c.key }; } }) + '</section>';
    }

    // ---- 3. Phases ----------------------------------------------------------------------------
    function phase() {
        var gs = groups();
        function dist(title, sub, n, get, label, key) {
            var rows = [];
            for (var i = 0; i <= n; i++) {
                var list = gs.filter(function (g) { return get(g) === i; });
                rows.push({ label: label(i), value: list.length, color: i === n ? 'var(--s-good)' : 'var(--c1)', act: 'pick', val: key + ':' + i, on: F.pick === key + ':' + i });
            }
            return '<section class="in-panel w6"><h2>' + title + '</h2><p>' + sub + ' Tap a bar for the names.</p>' + IN.bars(rows) + '</section>';
        }
        var html = dist('Proposal requirements', 'Groups by how many of the ' + OA.np + ' are approved.', OA.np, function (g) { return Math.min(g.pa, OA.np); }, function (i) { return i + ' of ' + OA.np + ' approved'; }, 'pa') +
            dist('Statistics', 'Groups by the step they are on (7 = released).', 7, function (g) { return g.stats; }, function (i) { return i ? 'Phase ' + i : 'Not started'; }, 'stats') +
            dist('Final requirements', 'Groups by how many of the ' + OA.nf + ' are approved.', OA.nf, function (g) { return Math.min(g.fa, OA.nf); }, function (i) { return i + ' of ' + OA.nf + ' approved'; }, 'fa') +
            dist('Plagiarism', 'Groups by the step they are on (5 = cleared).', 5, function (g) { return g.plag; }, function (i) { return i ? 'Phase ' + i : 'Not started'; }, 'plag');
        if (F.pick) {
            var p = F.pick.split(':'), v = parseInt(p[1], 10), get = { pa: function (g) { return Math.min(g.pa, OA.np); }, fa: function (g) { return Math.min(g.fa, OA.nf); }, stats: function (g) { return g.stats; }, plag: function (g) { return g.plag; } }[p[0]];
            var title = { pa: 'Proposal: ' + v + ' of ' + OA.np + ' approved', fa: 'Final: ' + v + ' of ' + OA.nf + ' approved', stats: 'Statistics: ' + (v ? 'phase ' + v : 'not started'), plag: 'Plagiarism: ' + (v ? 'phase ' + v : 'not started') }[p[0]];
            html = whoPanel(title, gs.filter(function (g) { return get(g) === v; })) + html;
        }
        return html;
    }

    // ---- 4. Schedule --------------------------------------------------------------------------
    function sched() {
        var act = activity(true).filter(function (a) { return a.k === 'def'; });
        var keys = [{ k: 'proposal', label: 'Proposal', color: 'var(--c1)' }, { k: 'final', label: 'Final', color: 'var(--c3)' }];
        var cols = monthCols(keys, function (ym, k) { return act.filter(function (a) { return a.ym === ym && a.ph === k; }).reduce(function (s, a) { return s + a.n; }, 0); });
        var bs = batches();
        var parts = Object.keys(BATCH).map(function (s) { return { label: BATCH[s][0], value: bs.filter(function (b) { return b.st === s; }).reduce(function (x, b) { return x + b.n; }, 0), color: BATCH[s][1] }; });
        var timed = bs.reduce(function (s, b) { return s + b.timed; }, 0), hrs = bs.reduce(function (s, b) { return s + b.hrs; }, 0), back = bs.reduce(function (s, b) { return s + b.back; }, 0);
        var gs = groups(), upcoming = gs.filter(function (g) { return g.pd || g.fd; });
        return '<section class="in-panel w7"><h2>Defenses held per month</h2><p>Published dates whose day has passed. Tap a month to look at just that month.</p>' + IN.columns(cols, keys, { label: 'Defenses held per month' }) + '</section>' +
            '<section class="in-panel w5"><h2>How the date requests went</h2><p>Every date a focal person sent up the chain · ' + period() + '.</p>' + IN.donut(parts, { unit: 'requests', label: 'Date requests by outcome', empty: 'No date requests in this selection.' }) +
            '<p class="in-note">' + plural(back, 'request') + ' sent back at some point' + (timed ? ' · focal person to published took ' + (hrs / timed / 24).toFixed(1) + ' days on average' : '') + '.</p></section>' +
            '<section class="in-panel w12"><h2>Groups with a date</h2><p>' + plural(upcoming.length, 'group') + ' with a published proposal or final date.</p>' + IN.table([
                { k: 'name', label: 'Group', html: function (g) { return '<b>' + esc(g.name) + '</b><small>' + esc(g.leader) + '</small>'; } },
                { k: 'p', label: 'Course', html: function (g) { return esc(g.p) + '<small>' + esc(tag(g.d)) + (g.room ? ' · ' + esc(g.room) : '') + '</small>'; } },
                { k: 'pd', label: 'Proposal date' }, { k: 'fd', label: 'Final date' },
                { k: 'stage', label: 'Stage', html: function (g) { return sChip(g.stage); }, sortv: function (g) { return STAGES.indexOf(g.stage); } }
            ], upcoming, F.sort[0] in { name: 1, p: 1, pd: 1, fd: 1, stage: 1 } ? F.sort : ['name', 1]) + '</section>';
    }

    // ---- 5. Groups ----------------------------------------------------------------------------
    function grp() {
        var gs = groups();
        return '<section class="in-panel w12"><h2>Every group</h2><p>' + plural(gs.length, 'group') + '. Tap a heading to sort.</p>' + IN.table([
            { k: 'name', label: 'Group', html: function (g) { return '<b>' + esc(g.name) + '</b><small>' + esc(g.leader) + ' · ' + plural(g.members, 'member') + '</small>'; } },
            { k: 'p', label: 'Course', html: function (g) { return esc(g.p) + '<small>' + esc(tag(g.d)) + (g.room ? ' · ' + esc(g.room) : '') + '</small>'; } },
            { k: 'stage', label: 'Stage', html: function (g) { return sChip(g.stage); }, sortv: function (g) { return STAGES.indexOf(g.stage); } },
            { k: 'pa', label: 'Proposal', num: true, html: function (g) { return g.pa + '/' + OA.np + (g.pw ? '<small>' + g.pw + ' waiting</small>' : ''); } },
            { k: 'stats', label: 'Stats', num: true, html: function (g) { return g.stats ? 'Phase ' + g.stats : '—'; } },
            { k: 'fa', label: 'Final', num: true, html: function (g) { return g.fa + '/' + OA.nf + (g.fw ? '<small>' + g.fw + ' waiting</small>' : ''); } },
            { k: 'plag', label: 'Plagiarism', num: true, html: function (g) { return g.plag ? 'Phase ' + g.plag : '—'; } },
            { k: 'last', label: 'Last sent', html: function (g) { return g.last ? esc(mlabel(g.last)) : '<small>nothing yet</small>'; } }
        ], gs, F.sort) + '</section>';
    }

    // ---- 6. People ----------------------------------------------------------------------------
    function people() {
        var st = OA.staff.filter(function (s) {
            return (!F.inst || s.dept === F.inst || s.dept === 'ALL' || s.role === 'Director' || s.role === 'Secretary' || s.courses.some(function (k) { return k.indexOf(F.inst + '::') === 0; })) &&
                   (!F.course || s.courses.indexOf(F.course) !== -1 || s.role !== 'Focal Person');
        });
        var load = function (s) {   // the groups a focal person covers
            return s.role === 'Focal Person' ? OA.groups.filter(function (g) { return s.courses.indexOf(g.d + '::' + g.p) !== -1; }) : [];
        };
        var roles = ['Director', 'Secretary', 'Coordinator', 'Statistician', 'Focal Person'].map(function (r, i) { return { label: r, value: st.filter(function (s) { return s.role === r; }).length, color: IN.c(i) }; });
        return '<section class="in-panel w8"><h2>The people</h2><p>Staff working this selection, and the groups each focal person covers.</p>' + IN.table([
            { k: 'name', label: 'Name', html: function (s) { return '<b>' + esc(s.name) + '</b><small>' + esc(s.role) + '</small>'; } },
            { k: 'dept', label: 'Covers', html: function (s) { return s.role === 'Focal Person' ? esc(s.courses.map(function (k) { return k.split('::')[1]; }).join(', ') || 'no course') : esc(s.dept === 'ALL' ? 'Both' : tag(s.dept) || 'Everything'); } },
            { k: 'load', label: 'Groups', num: true, html: function (s) { var l = load(s); return l.length ? l.length + '<small>' + l.filter(function (g) { return g.pw || g.fw; }).length + ' waiting</small>' : '—'; }, sortv: function (s) { return load(s).length; } },
            { k: 'status', label: 'Account', html: function (s) { return '<span class="in-chip ' + (s.status === 'active' ? 'good' : 'bad') + '"><i data-lucide="' + (s.status === 'active' ? 'check-circle-2' : 'ban') + '"></i>' + esc(s.status) + '</span>'; } },
            { k: 'last', label: 'Last sign-in', html: function (s) { return s.last ? esc(s.last) : '<small>never</small>'; } }
        ], st, F.sort[0] in { name: 1, dept: 1, load: 1, status: 1, last: 1 } ? F.sort : ['name', 1]) + '</section>' +
            '<section class="in-panel w4"><h2>By role</h2><p>' + plural(st.length, 'person', 'people') + '.</p>' + IN.donut(roles, { unit: 'staff', label: 'Staff by role' }) + '</section>';
    }

    // ---- render, events, export --------------------------------------------------------------
    var TITLE = { over: 'Overview', course: 'Courses', phase: 'Phases', sched: 'Schedule', groups: 'Groups', people: 'People' };
    function rpHint(h) { return h.replace(/(Tap [^.<]*\.)/g, '<span class="rp-hint">$1</span>'); }   // screen-only instructions, hidden on paper (print.css)
    function render() {
        tiles();
        $('inTitle').textContent = TITLE[F.tab];
        $('inCrumb').textContent = 'Analytics / ' + TITLE[F.tab];
        $('inBody').innerHTML = rpHint({ over: over, course: course, phase: phase, sched: sched, groups: grp, people: people }[F.tab]());
        document.body.classList.toggle('rp-wide', F.tab === 'course' || F.tab === 'groups');   // wide tables print landscape
        if (window.lucide) lucide.createIcons();
    }
    function set(k, v) {
        F[k] = v; F.pick = k === 'pick' ? v : F.pick;
        if (k === 'inst') { F.course = ''; courseOptions(); $('fInst').value = v; }
        if (k === 'course') { $('fCourse').value = v; if (v) { F.inst = v.split('::')[0]; $('fInst').value = F.inst; } }
        if (k === 'ym') $('fMonth').value = v;
        if (k === 'stage') $('fStage').value = v;
        render();
    }
    $('fMonth').addEventListener('change', function () { set('ym', this.value); });
    $('fInst').addEventListener('change', function () { set('inst', this.value); });
    $('fCourse').addEventListener('change', function () { set('course', this.value); });
    $('fStage').addEventListener('change', function () { set('stage', this.value); });
    $('fQ').addEventListener('input', function () { F.q = this.value; render(); });
    $('fReset').addEventListener('click', function () { F.ym = F.course = F.stage = F.q = ''; F.inst = OA.insts.length < 2 ? (OA.insts[0] || {}).key || '' : ''; F.pick = null; $('fQ').value = ''; fill(); render(); });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-t],[data-act],[data-sort]');
        if (!t) return;
        if (t.dataset.t) { F.tab = t.dataset.t; F.pick = null; document.querySelectorAll('.in-tabs button').forEach(function (b) { b.classList.toggle('on', b === t); }); return render(); }
        if (t.dataset.sort) { F.sort = [t.dataset.sort, F.sort[0] === t.dataset.sort ? -F.sort[1] : 1]; return render(); }
        var a = t.dataset.act, v = t.dataset.val;
        if (a === 'month') return set('ym', F.ym === v ? '' : v);
        if (a === 'course') return set('course', F.course === v ? '' : v);
        if (a === 'stage') return set('stage', F.stage === v ? '' : v);
        if (a === 'pick') { F.pick = F.pick === v ? null : v; return render(); }
        if (a === 'unpick') { F.pick = null; return render(); }
    });
    window.inExport = function () {
        var out = [], gs = groups();
        if (F.tab === 'sched') {
            out.push(['Month', 'Institution', 'Course', 'Phase', 'Outcome', 'Requests', 'Ever sent back']);
            batches().forEach(function (b) { out.push([b.ym, tag(b.d), b.p, b.ph, (BATCH[b.st] || [b.st])[0], b.n, b.back]); });
        } else if (F.tab === 'people') {
            out.push(['Name', 'Role', 'Covers', 'Account', 'Last sign-in']);
            OA.staff.forEach(function (s) { out.push([s.name, s.role, s.role === 'Focal Person' ? s.courses.map(function (k) { return k.split('::')[1]; }).join('; ') : tag(s.dept), s.status, s.last || 'never']); });
        } else if (F.tab === 'over' || F.tab === 'course') {
            out.push(['Month', 'Institution', 'Course', 'Phase', 'What', 'Count']);
            activity().forEach(function (a) { out.push([a.ym, tag(a.d), a.p, a.ph, { sub: 'Sent in', ok: 'Approved', back: 'Sent back', def: 'Defense held' }[a.k], a.n]); });
        } else {
            out.push(['Group', 'Leader', 'Members', 'Institution', 'Course', 'Room', 'Stage', 'Proposal approved', 'Statistics phase', 'Final approved', 'Plagiarism phase', 'Proposal date', 'Final date']);
            gs.forEach(function (g) { out.push([g.name, g.leader, g.members, tag(g.d), g.p, g.room, STAGE[g.stage][0], g.pa + '/' + OA.np, g.stats, g.fa + '/' + OA.nf, g.plag, g.pd, g.fd]); });
        }
        IN.csv('analytics-' + F.tab + (F.ym ? '-' + F.ym : '') + '-' + OA.today + '.csv', out);
    };
    window.inToHub = function (v) {
        try { if (window.top !== window && typeof window.top.shShow === 'function') { window.top.shShow(v); return false; } } catch (e) {}
        return true;
    };
    if (OA.insts.length === 1) F.inst = OA.insts[0].key;
    fill();
    $('fInst').value = F.inst;
    render();
})();
