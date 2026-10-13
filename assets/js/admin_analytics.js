/* =============================================================================
   The Admin's Analytics (2026-10-01). dashboards/admin_analytics.php.
   Accounts, institutions & courses, the help desk and sign-ins — all from window.AN,
   filtered client-side. "Month" means ACTIVITY in that month: accounts made, issues
   reported, sign-ins. All time shows the totals.
   ========================================================================== */
(function () {
    'use strict';
    var esc = IN.esc, plural = IN.plural;
    var F = { ym: '', inst: '', course: '', role: '', tab: 'over', sort: ['role', 1], hcat: '', hst: '' };
    var ROLES = ['Admin', 'Director', 'Secretary', 'Coordinator', 'Statistician', 'Focal Person'];
    var CAT = { email_access: 'Email access', password: 'Password', fix_details: 'Fix my details', group_change: 'Group change', suggestion: 'Suggestion', other: 'Other' };
    var WHY = { ok: 'Signed in', wrong_password: 'Wrong password', no_account: 'No such account', locked: 'Locked out', disabled: 'Disabled account', pending: 'Not approved yet' };
    var ST = { active: ['Active', 'good', 'check-circle-2'], pending: ['Waiting for approval', 'warn', 'clock'], disabled: ['Disabled', 'bad', 'ban'], rejected: ['Not approved', 'bad', 'x-circle'] };
    var tagOf = {}; AN.insts.forEach(function (i) { tagOf[i.key] = i.tag; });
    function $(id) { return document.getElementById(id); }
    function tag(d) { return d === 'ALL' ? 'Both' : (tagOf[d] || d || '—'); }
    function monthLabel(ym) { return new Date(ym + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' }); }
    function inMonth(ym) { return !F.ym || ym === F.ym; }
    function period() { return F.ym ? (AN.months.filter(function (m) { return m.ym === F.ym; })[0] || {}).label : 'all time'; }
    function chip(status) { var s = ST[status] || [status, '', 'circle']; return '<span class="in-chip ' + s[1] + '"><i data-lucide="' + s[2] + '"></i>' + esc(s[0]) + '</span>'; }

    // ---- filters ----------------------------------------------------------------------------
    function fill() {
        $('fMonth').innerHTML = '<option value="">All time</option>' + AN.months.map(function (m) { return '<option value="' + m.ym + '">' + esc(m.label) + '</option>'; }).join('');
        $('fInst').innerHTML = '<option value="">Both institutions</option>' + AN.insts.map(function (i) { return '<option value="' + esc(i.key) + '">' + esc(i.tag) + '</option>'; }).join('');
        $('fRole').innerHTML = '<option value="">Everyone</option><option value="student">Students</option><option value="staff">All staff</option>' + ROLES.map(function (r) { return '<option value="' + r + '">' + r + '</option>'; }).join('');
        courseOptions();
    }
    function courseOptions() {
        var cs = AN.courses.filter(function (c) { return !F.inst || c.dept === F.inst; });
        $('fCourse').innerHTML = '<option value="">Every course</option>' + cs.map(function (c) { return '<option value="' + esc(c.key) + '">' + esc(c.program) + (F.inst ? '' : ' (' + esc(tag(c.dept)) + ')') + '</option>'; }).join('');
        $('fCourse').value = F.course;
    }

    // ---- the filtered views of the data ------------------------------------------------------
    function stuRows() {   // student buckets in scope (month = joined that month)
        return AN.students.filter(function (r) {
            return inMonth(r.ym) && (!F.inst || r.d === F.inst) && (!F.course || r.d + '::' + r.p === F.course);
        });
    }
    function staffRows(ignoreMonth) {
        return AN.staff.filter(function (s) {
            if (!ignoreMonth && F.ym && s.ym !== F.ym) return false;
            if (F.role && F.role !== 'staff' && F.role !== 'student' && s.role !== F.role) return false;
            if (F.inst && !(s.dept === F.inst || s.dept === 'ALL' || s.courses.some(function (k) { return k.indexOf(F.inst + '::') === 0; }) || ['Admin', 'Director', 'Secretary'].indexOf(s.role) !== -1)) return false;
            if (F.course && !(s.courses.indexOf(F.course) !== -1 || s.dept === F.course.split('::')[0] || s.dept === 'ALL' || ['Admin', 'Director', 'Secretary'].indexOf(s.role) !== -1)) return false;
            return true;
        });
    }
    function helpRows() {
        return AN.help.filter(function (h) {
            return inMonth(h.ym) && (!F.inst || h.d === F.inst) && (!F.course || h.d + '::' + h.p === F.course) &&
                   (!F.role || (F.role === 'student' ? h.who === 'student' : F.role === 'staff' ? h.who === 'staff' : true));
        });
    }
    function signRows() {
        return AN.signins.filter(function (r) {
            return (!F.ym || r.d.slice(0, 7) === F.ym) && (!F.role || F.role === r.who || (F.role !== 'student' && F.role !== 'staff' ? r.who === 'staff' : false));
        });
    }
    function sum(rows, k) { return rows.reduce(function (s, r) { return s + (k ? r[k] : r.n); }, 0); }

    // ---- summary tiles ------------------------------------------------------------------------
    function tiles() {
        var st = stuRows(), staffAll = staffRows(true), hs = helpRows(), sg = signRows();
        var students = sum(st), groups = sum(st, 'g'), ok = sum(sg.filter(function (r) { return r.ok; })), bad = sum(sg.filter(function (r) { return !r.ok; }));
        var open = hs.filter(function (h) { return h.st === 'open'; }).length;
        var pending = staffAll.filter(function (s) { return s.status === 'pending'; }).length;
        $('inTiles').innerHTML = IN.tiles([
            { icon: 'graduation-cap', label: F.ym ? 'New students' : 'Students', value: students, sub: plural(groups, 'research group') + (F.ym ? ' started' : '') },
            { icon: 'user-cog', label: 'Staff accounts', value: staffAll.filter(function (s) { return s.status === 'active'; }).length, sub: staffAll.length + ' in all · ' + (F.ym ? staffRows().length + ' made in ' + period() : 'every role') },
            { icon: 'user-check', label: 'Waiting for approval', value: pending, warn: pending > 0, sub: pending ? 'Staff accounts to approve' : 'Nothing waiting' },
            { icon: 'life-buoy', label: 'Help requests', value: hs.length, sub: open + ' open, ' + (hs.length - open) + ' resolved', warn: open > 0 },
            { icon: 'log-in', label: 'Sign-ins', value: ok, sub: bad ? bad + ' failed' : 'none failed', meter: ok + bad ? [ok, ok + bad, 'var(--s-good)'] : null }
        ]);
        $('inLede').textContent = 'Accounts and the system · ' + (F.inst ? tag(F.inst) : 'both institutions') + (F.course ? ' · ' + F.course.split('::')[1] : '') + ' · ' + period();
        $('inPrinted').textContent = 'Printed ' + new Date().toLocaleString('en-US') + ' · ' + $('inLede').textContent;
    }

    // ---- 1. Overview --------------------------------------------------------------------------
    function over() {
        // new accounts per month (always the months, so the trend is visible), split students / staff
        var months = AN.months.slice().reverse();
        var pts = months.map(function (m) {
            var s = AN.students.filter(function (r) { return r.ym === m.ym && (!F.inst || r.d === F.inst) && (!F.course || r.d + '::' + r.p === F.course); });
            var t = AN.staff.filter(function (x) { return x.ym === m.ym; });
            return { x: monthLabel(m.ym).split(' ')[0], label: m.label, vals: { s: sum(s), t: t.length }, act: 'month', val: m.ym };
        });
        var st = stuRows();
        var byInst = AN.insts.map(function (i, k) {
            var n = sum(st.filter(function (r) { return r.d === i.key; }));
            return { label: i.tag, sub: i.key, value: n, color: IN.c(k), act: 'inst', val: i.key, on: F.inst === i.key };
        });
        var byCourse = {};
        st.forEach(function (r) { var k = r.d + '::' + r.p; byCourse[k] = (byCourse[k] || 0) + r.n; });
        var courseBars = Object.keys(byCourse).map(function (k) { return { label: k.split('::')[1], sub: tag(k.split('::')[0]), value: byCourse[k], color: 'var(--c1)', act: 'course', val: k, on: F.course === k }; })
            .sort(function (a, b) { return b.value - a.value; }).slice(0, 12);
        var roles = ROLES.map(function (r, i) { return { label: r, value: staffRows(true).filter(function (s) { return s.role === r; }).length, color: IN.c(i), act: 'role', val: r }; });
        return '<section class="in-panel w7"><h2>New accounts per month</h2><p>Students and staff accounts made each month. Tap a month to look at just that month.</p>' +
            IN.columns(pts, [{ k: 's', label: 'Students', color: 'var(--c1)' }, { k: 't', label: 'Staff', color: 'var(--c2)' }], { label: 'New accounts per month' }) + '</section>' +
            '<section class="in-panel w5"><h2>Students by institution</h2><p>' + (F.ym ? 'Joined in ' + period() : 'Every student account') + '. Tap one to filter.</p>' + IN.bars(byInst) +
            '<p class="in-note">Two institutions: shown as bars, not a pie.</p></section>' +
            '<section class="in-panel w7"><h2>Students by course</h2><p>' + (courseBars.length === 12 ? 'The twelve largest. ' : '') + 'Tap a course to filter everything to it.</p>' + IN.bars(courseBars, { empty: 'No students in this selection.' }) + '</section>' +
            '<section class="in-panel w5"><h2>Staff by role</h2><p>Every staff account, whatever its status.</p>' + IN.donut(roles, { unit: 'staff', label: 'Staff by role' }) + '</section>';
    }

    // ---- 2. Accounts --------------------------------------------------------------------------
    function acct() {
        var rows = staffRows(true);
        var statuses = ['active', 'pending', 'disabled', 'rejected'].map(function (s, i) { return { label: ST[s][0], value: rows.filter(function (x) { return x.status === s; }).length, color: ['var(--s-good)', 'var(--s-warn)', 'var(--s-bad)', 'var(--s-none)'][i] }; });
        var attention = [];
        rows.forEach(function (s) {
            if (s.status === 'pending') attention.push([s, 'Waiting for your approval', 'clock']);
            if (s.status === 'active' && !s.last) attention.push([s, 'Has never signed in', 'log-in']);
            if (s.status === 'active' && s.idle != null && s.idle > 14) attention.push([s, 'No sign-in for ' + s.idle + ' days', 'moon']);
            if (s.mustpw) attention.push([s, 'Must change their password', 'key-round']);
            if (s.role === 'Focal Person' && !s.courses.length && s.status === 'active') attention.push([s, 'Covers no course yet', 'book-x']);
        });
        var cols = [
            { k: 'name', label: 'Name', html: function (s) { return '<b>' + esc(s.name) + '</b>' + (s.by ? '<small>made by ' + esc(s.by) + '</small>' : ''); } },
            { k: 'role', label: 'Role', sortv: function (s) { return ROLES.indexOf(s.role); } },
            { k: 'dept', label: 'Covers', html: function (s) { return s.role === 'Focal Person' ? esc(s.courses.map(function (k) { return k.split('::')[1]; }).join(', ') || 'no course') : esc(tag(s.dept) || 'Everything'); } },
            { k: 'status', label: 'Account', html: function (s) { return chip(s.status); } },
            { k: 'joined', label: 'Joined', sortv: function (s) { return s.ym; } },
            { k: 'last', label: 'Last sign-in', html: function (s) { return s.last ? esc(s.last) + (s.idle != null ? '<small>' + (s.idle ? s.idle + ' days ago' : 'today') + '</small>' : '') : '<small>never</small>'; }, sortv: function (s) { return s.idle == null ? 99999 : s.idle; } }
        ];
        return '<section class="in-panel w8"><h2>Staff accounts</h2><p>' + plural(rows.length, 'account') + '. Tap a heading to sort.</p>' + IN.table(cols, rows, F.sort) + '</section>' +
            '<section class="in-panel w4"><h2>Account status</h2><p>Staff accounts by where they stand.</p>' + IN.donut(statuses, { unit: 'accounts', label: 'Account status' }) + '</section>' +
            '<section class="in-panel w4"><h2>Needs your attention</h2><p>' + plural(attention.length, 'thing') + ' to look at.</p><div class="in-list">' +
            (attention.length ? attention.map(function (a) { return '<div class="in-li"><span class="in-chip warn"><i data-lucide="' + a[2] + '"></i></span><div><b>' + esc(a[0].name) + '</b><small>' + esc(a[0].role) + ' · ' + esc(a[1]) + '</small></div></div>'; }).join('')
                : '<p class="in-empty">All accounts look fine.</p>') + '</div></section>';
    }

    // ---- 3. Institutions & courses ------------------------------------------------------------
    function inst() {
        var st = stuRows(), byC = {};
        st.forEach(function (r) {
            var k = r.d + '::' + r.p;
            byC[k] = byC[k] || { leaders: 0, members: 0, secs: {} };
            byC[k].leaders += r.g; byC[k].members += r.n - r.g;
            if (r.sec) byC[k].secs[r.sec] = (byC[k].secs[r.sec] || 0) + r.n;
        });
        var rows = Object.keys(byC).sort(function (a, b) { return (byC[b].leaders + byC[b].members) - (byC[a].leaders + byC[a].members); })
            .map(function (k) { return { label: k.split('::')[1], sub: tag(k.split('::')[0]), parts: { g: byC[k].leaders, m: byC[k].members }, act: 'course', val: k }; });
        var html = '<section class="in-panel w7"><h2>Students per course</h2><p>Group leaders and members. ' + (F.ym ? 'Joined in ' + period() + '. ' : '') + 'Tap a course to open its sections.</p>' +
            IN.stack(rows, [{ k: 'g', label: 'Group leaders', color: 'var(--c1)' }, { k: 'm', label: 'Members', color: 'var(--c3)' }], { empty: 'No students in this selection.' }) + '</section>';
        // one table: per institution, or the chosen course's sections
        if (F.course) {
            var c = byC[F.course] || { secs: {} }, secRows = Object.keys(c.secs).map(function (id) { return { label: (AN.sections[id] || {}).short || 'Section ' + id, value: c.secs[id], color: 'var(--c3)' }; })
                .sort(function (a, b) { return a.label.localeCompare(b.label); });
            var none = sum(st.filter(function (r) { return r.d + '::' + r.p === F.course && !r.sec; }));
            html += '<section class="in-panel w5"><h2>' + esc(F.course.split('::')[1]) + ' · sections</h2><p>Students in each section' + (none ? '; ' + plural(none, 'student') + ' in no section yet' : '') + '.</p>' + IN.bars(secRows, { empty: 'No students have a section yet.' }) + '</section>';
        } else {
            var instRows = AN.insts.filter(function (i) { return !F.inst || i.key === F.inst; }).map(function (i) {
                var s = st.filter(function (r) { return r.d === i.key; }), courses = {}, secs = {};
                s.forEach(function (r) { courses[r.p] = 1; if (r.sec) secs[r.sec] = 1; });
                return { name: i.tag, full: i.key, courses: Object.keys(courses).length, sections: Object.keys(secs).length, students: sum(s), groups: sum(s, 'g'),
                         staff: AN.staff.filter(function (x) { return x.dept === i.key || x.courses.some(function (k) { return k.indexOf(i.key + '::') === 0; }); }).length };
            });
            html += '<section class="in-panel w5"><h2>By institution</h2><p>' + period() + '.</p>' + IN.table([
                { k: 'name', label: 'Institution', html: function (r) { return '<b>' + esc(r.name) + '</b><small>' + esc(r.full) + '</small>'; } },
                { k: 'courses', label: 'Courses', num: true }, { k: 'sections', label: 'Sections', num: true },
                { k: 'students', label: 'Students', num: true }, { k: 'groups', label: 'Groups', num: true }, { k: 'staff', label: 'Staff', num: true }
            ], instRows, null) + '</section>';
        }
        return html;
    }

    // ---- 4. Help desk ------------------------------------------------------------------------
    function help() {
        var all = helpRows(), hs = all.filter(function (h) { return (!F.hcat || h.cat === F.hcat) && (!F.hst || h.st === F.hst); });
        var cats = Object.keys(CAT).map(function (k, i) { return { label: CAT[k], value: all.filter(function (h) { return h.cat === k; }).length, color: IN.c(i), act: 'hcat', val: k }; });
        var solved = all.filter(function (h) { return h.st !== 'open' && h.hrs != null; }), avg = solved.length ? solved.reduce(function (s, h) { return s + h.hrs; }, 0) / solved.length : null;
        var months = AN.months.slice().reverse().map(function (m) {
            var r = AN.help.filter(function (h) { return h.ym === m.ym && (!F.inst || h.d === F.inst); });
            return { x: monthLabel(m.ym).split(' ')[0], label: m.label, vals: { o: r.filter(function (h) { return h.st === 'open'; }).length, r: r.filter(function (h) { return h.st !== 'open'; }).length } };
        });
        return '<section class="in-panel w5"><h2>What people report</h2><p>' + plural(all.length, 'request') + ' · ' + period() + '. Tap a kind to list just those.</p>' + IN.donut(cats, { unit: 'requests', label: 'Help requests by kind' }) +
            '<p class="in-note">' + (avg == null ? 'None resolved yet in this selection.' : 'Answered in ' + (avg < 24 ? plural(Math.max(1, Math.round(avg)), 'hour') : (avg / 24).toFixed(1) + ' days') + ' on average.') + '</p></section>' +
            '<section class="in-panel w7"><h2>Requests per month</h2><p>Open and resolved, by the month they came in.</p>' +
            IN.columns(months, [{ k: 'o', label: 'Still open', color: 'var(--c2)' }, { k: 'r', label: 'Resolved', color: 'var(--c3)' }], { label: 'Help requests per month' }) + '</section>' +
            '<section class="in-panel w12"><div class="in-ph"><div><h2>The requests</h2><p>' + plural(hs.length, 'request') + '.</p></div><div>' +
            (F.hcat ? '<span class="in-pick">' + esc(CAT[F.hcat]) + ' <button type="button" data-act="hcat" data-val="" aria-label="Clear"><i data-lucide="x"></i></button></span> ' : '') +
            ['', 'open', 'resolved'].map(function (s) { return '<button type="button" class="in-btn rp-screen" style="height:34px;padding:0 13px;' + (F.hst === s ? 'background:var(--accent);color:var(--accent-ink)' : '') + '" data-act="hst" data-val="' + s + '">' + (s ? s.charAt(0).toUpperCase() + s.slice(1) : 'All') + '</button>'; }).join(' ') +
            '</div></div>' + IN.table([
                { k: 'md', label: 'Received', sortv: function (h) { return -h.id; } },
                { k: 'name', label: 'From', html: function (h) { return '<b>' + esc(h.name) + '</b><small>' + esc(h.who === 'student' ? (h.p || 'Student') + ' · ' + tag(h.d) : h.who === 'staff' ? 'Staff' : 'No account') + '</small>'; } },
                { k: 'cat', label: 'Kind', html: function (h) { return esc(CAT[h.cat] || h.cat); } },
                { k: 'msg', label: 'What they wrote', html: function (h) { return '<span style="display:block;max-width:420px">' + esc(h.msg) + '</span>'; } },
                { k: 'st', label: 'Status', html: function (h) { return h.st === 'open' ? '<span class="in-chip warn"><i data-lucide="clock"></i>Open</span>' : '<span class="in-chip good"><i data-lucide="check-circle-2"></i>Resolved</span><small>' + (h.by ? 'by ' + esc(h.by) : '') + (h.hrs != null ? ' · ' + (h.hrs < 24 ? h.hrs + ' h' : Math.round(h.hrs / 24) + ' d') : '') + '</small>'; } }
            ], hs, ['md', 1], { empty: 'No requests in this selection.' }) + '</section>';
    }

    // ---- 5. Sign-ins -------------------------------------------------------------------------
    function sign() {
        var rows = signRows(), days = {};
        rows.forEach(function (r) { days[r.d] = days[r.d] || { ok: 0, bad: 0 }; days[r.d][r.ok ? 'ok' : 'bad'] += r.n; });
        var start = F.ym ? new Date(F.ym + '-01T00:00:00') : new Date(new Date(AN.today + 'T00:00:00').getTime() - 29 * 864e5);
        var end = F.ym ? new Date(start.getFullYear(), start.getMonth() + 1, 0) : new Date(AN.today + 'T00:00:00');
        var pts = [];
        for (var d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            var k = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2), v = days[k] || { ok: 0, bad: 0 };
            pts.push({ x: d.getDate() % 5 === 1 ? String(d.getDate()) : '', label: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }), vals: v });
        }
        var why = {};
        rows.filter(function (r) { return !r.ok; }).forEach(function (r) { why[r.why] = (why[r.why] || 0) + r.n; });
        var whyParts = Object.keys(why).map(function (k, i) { return { label: WHY[k] || k, value: why[k], color: IN.c(i + 1) }; });
        var who = ['student', 'staff', 'unknown'].map(function (w, i) { return { label: { student: 'Students', staff: 'Staff', unknown: 'No such account' }[w], value: sum(rows.filter(function (r) { return r.who === w && r.ok; })), color: IN.c(i) }; });
        return '<section class="in-panel w8"><h2>Sign-ins per day</h2><p>' + (F.ym ? period() : 'The last 30 days') + '. Sign-ins are not split by institution.</p>' +
            IN.columns(pts, [{ k: 'ok', label: 'Signed in', color: 'var(--s-good)' }, { k: 'bad', label: 'Failed', color: 'var(--s-bad)' }], { label: 'Sign-ins per day' }) + '</section>' +
            '<section class="in-panel w4"><h2>Why sign-ins failed</h2><p>' + plural(sum(rows.filter(function (r) { return !r.ok; })), 'failed attempt') + '.</p>' + IN.donut(whyParts, { unit: 'failed', label: 'Failed sign-ins by reason', empty: 'No failed sign-ins.' }) + '</section>' +
            '<section class="in-panel w6"><h2>Who signed in</h2><p>Successful sign-ins by kind of account.</p>' + IN.bars(who) + '</section>' +
            '<section class="in-panel w6"><h2>Staff who have gone quiet</h2><p>Active staff with no sign-in for over two weeks, or never.</p><div class="in-list">' +
            (function () {
                var q = AN.staff.filter(function (s) { return s.status === 'active' && (!s.last || s.idle > 14); });
                return q.length ? q.map(function (s) { return '<div class="in-li"><div><b>' + esc(s.name) + '</b><small>' + esc(s.role) + '</small></div><em>' + (s.last ? s.idle + ' days' : 'never') + '</em></div>'; }).join('') : '<p class="in-empty">Everyone has signed in lately.</p>';
            })() + '</div></section>';
    }

    // ---- render, events, export --------------------------------------------------------------
    var TITLE = { over: 'Overview', acct: 'Accounts', inst: 'Institutions & courses', help: 'Help desk', sign: 'Sign-ins' };
    function rpHint(h) { return h.replace(/(Tap [^.<]*\.)/g, '<span class="rp-hint">$1</span>'); }   // screen-only instructions, hidden on paper (print.css)
    function render() {
        tiles();
        $('inTitle').textContent = TITLE[F.tab];
        $('inCrumb').textContent = 'Insights / Analytics / ' + TITLE[F.tab];
        $('inBody').innerHTML = rpHint({ over: over, acct: acct, inst: inst, help: help, sign: sign }[F.tab]());
        document.body.classList.toggle('rp-wide', F.tab === 'acct');   // wide tables print landscape
        if (window.lucide) lucide.createIcons();
    }
    function set(k, v) {
        F[k] = v;
        if (k === 'inst') { F.course = ''; courseOptions(); $('fInst').value = v; }
        if (k === 'course') { $('fCourse').value = v; if (v) { F.inst = v.split('::')[0]; $('fInst').value = F.inst; } }
        if (k === 'ym') $('fMonth').value = v;
        if (k === 'role') $('fRole').value = v;
        render();
    }
    $('fMonth').addEventListener('change', function () { set('ym', this.value); });
    $('fInst').addEventListener('change', function () { set('inst', this.value); });
    $('fCourse').addEventListener('change', function () { set('course', this.value); });
    $('fRole').addEventListener('change', function () { set('role', this.value); });
    $('fReset').addEventListener('click', function () { F.ym = F.inst = F.course = F.role = F.hcat = F.hst = ''; fill(); render(); });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-t],[data-act],[data-sort]');
        if (!t) return;
        if (t.dataset.t) { F.tab = t.dataset.t; document.querySelectorAll('.in-tabs button').forEach(function (b) { b.classList.toggle('on', b === t); }); return render(); }
        if (t.dataset.sort) { F.sort = [t.dataset.sort, F.sort[0] === t.dataset.sort ? -F.sort[1] : 1]; return render(); }
        var a = t.dataset.act, v = t.dataset.val;
        if (a === 'month') return set('ym', F.ym === v ? '' : v);
        if (a === 'inst') return set('inst', F.inst === v ? '' : v);
        if (a === 'course') return set('course', F.course === v ? '' : v);
        if (a === 'role') { F.tab = 'acct'; document.querySelectorAll('.in-tabs button').forEach(function (b) { b.classList.toggle('on', b.dataset.t === 'acct'); }); return set('role', v); }
        if (a === 'hcat') { F.hcat = F.hcat === v ? '' : v; return render(); }
        if (a === 'hst') { F.hst = v; return render(); }
    });
    window.inExport = function () {
        var out = [];
        if (F.tab === 'acct') {
            out.push(['Name', 'Role', 'Institution / covers', 'Account', 'Joined', 'Made by', 'Last sign-in', 'Days since']);
            staffRows(true).forEach(function (s) { out.push([s.name, s.role, s.role === 'Focal Person' ? s.courses.map(function (k) { return k.split('::')[1]; }).join('; ') : tag(s.dept), s.status, s.joined, s.by, s.last || 'never', s.idle == null ? '' : s.idle]); });
        } else if (F.tab === 'help') {
            out.push(['Received', 'From', 'Kind', 'Message', 'Status', 'Resolved by', 'Hours to resolve']);
            helpRows().forEach(function (h) { out.push([h.md, h.name, CAT[h.cat] || h.cat, h.msg, h.st, h.by, h.hrs == null ? '' : h.hrs]); });
        } else if (F.tab === 'sign') {
            out.push(['Date', 'Outcome', 'Reason', 'Account kind', 'Attempts']);
            signRows().forEach(function (r) { out.push([r.d, r.ok ? 'signed in' : 'failed', WHY[r.why] || r.why, r.who, r.n]); });
        } else {
            out.push(['Institution', 'Course', 'Section', 'Account status', 'Month joined', 'Students', 'Group leaders']);
            stuRows().forEach(function (r) { out.push([tag(r.d), r.p, r.sec ? ((AN.sections[r.sec] || {}).short || r.sec) : 'none', r.s, r.ym, r.n, r.g]); });
        }
        IN.csv('admin-analytics-' + F.tab + (F.ym ? '-' + F.ym : '') + '-' + AN.today + '.csv', out);
    };
    window.inToHub = function (v) {
        try { if (window.top !== window && typeof window.top.shShow === 'function') { window.top.shShow(v); return false; } } catch (e) {}
        return true;
    };
    fill();
    render();
})();
