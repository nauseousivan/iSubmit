/* =============================================================================
   The Admin's hologram (2026-10-01, reworked the same day). admin_visualize.php.
   Accounts, not papers. Four discs, top to bottom:
     Staff         a marker per ROLE (ring = an account waiting / disabled)
     Institutions  a node each
     Courses       a bar each = students (with a month picked: who joined then)
     Help desk     a bar per kind of reported issue (with a month: filed then)
   Everything is aggregated — people only appear inside a pop-out, so the view
   scales. Data: window.AN (an_admin_dataset, the Analytics one) + ?pod=staff.
   ========================================================================== */
HoloApp.ready(function () {   // after portal.js has put the viewer's theme on <body>
    'use strict';
    var esc = HoloApp.esc, plural = HoloApp.plural, ring = HoloApp.ring, abbr = HoloApp.abbr, height = HoloApp.height;

    // COLOURS FOLLOW THE VIEWER (colours(), re-run when they switch light/dark or accent):
    // institutions = dataviz categorical slots 1-4 for that mode, fixed by the entity's place in the
    // full list so a filter never repaints the survivors; staff roles = tones of THEIR accent;
    // status colours always carry a label.
    var INST_COL, ROLE_COL, RING, GOOD, WARN, BAD, NONE, IDLE;
    var ROLE = ['Admin', 'Director', 'Secretary', 'Coordinator', 'Statistician', 'Focal Person'];
    var CAT = { email_access: 'Email access', password: 'Password', fix_details: 'Fix my details', group_change: 'Group change', suggestion: 'Suggestion', other: 'Other' };

    var F = { inst: '', ym: '' };
    var tagOf = {}, colOf = {};
    AN.insts.forEach(function (i) { tagOf[i.key] = i.tag; });
    function colours(pal) {
        var d = pal.dark, t = pal.tones(6);
        INST_COL = d ? ['#3987e5', '#d95926', '#199e70', '#c98500'] : ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
        ROLE_COL = { Admin: t[0], Director: t[1], Secretary: t[2], Coordinator: t[3], Statistician: t[4], 'Focal Person': t[5] };
        GOOD = '#0ca30c'; WARN = d ? '#fab219' : '#e09b00'; BAD = '#d03b3b'; NONE = d ? '#3f4a52' : '#cbd5e1'; IDLE = d ? '#2a2440' : '#e9e5f5';
        RING = { pending: pal.holo.hot, disabled: BAD, rejected: BAD };
        AN.insts.forEach(function (i, n) { colOf[i.key] = INST_COL[n % INST_COL.length]; });
    }
    function $(id) { return document.getElementById(id); }
    function tag(d) { return d === 'ALL' ? 'Both institutions' : (tagOf[d] || d || 'No institution'); }
    function period() { return HoloApp.periodLabel(F.ym, AN.months); }
    function sum(rows, k) { return rows.reduce(function (s, r) { return s + (k ? r[k] : r.n); }, 0); }

    // ---- the filtered data ---------------------------------------------------------------------
    function students(extra) {
        return AN.students.filter(function (s) { return (!F.inst || s.d === F.inst) && HoloApp.inPeriod(s.ym, F.ym) && (!extra || extra(s)); });
    }
    function staffIn(inst) {
        return AN.staff.filter(function (s) {
            if (!inst) return true;
            return s.dept === inst || s.dept === 'ALL' || s.role === 'Director' || s.role === 'Secretary' || s.role === 'Admin' ||
                   s.courses.some(function (k) { return k.indexOf(inst + '::') === 0; });
        });
    }
    function help(extra) {
        return AN.help.filter(function (h) { return (!F.inst || h.d === F.inst) && HoloApp.inPeriod(h.ym, F.ym) && (!extra || extra(h)); });
    }
    function signins() {
        return AN.signins.filter(function (s) { return HoloApp.inPeriod(s.d, F.ym); });
    }
    function covers(s) {
        if (s.role === 'Focal Person') return s.courses.length ? s.courses.map(function (k) { return abbr(k.split('::')[1]); }).join(', ') : 'No courses yet';
        if (s.role === 'Coordinator' || s.role === 'Statistician') return tag(s.dept);
        return 'Everything';
    }
    function instsOf(s) {   // which institutions a staff member's work touches
        if (s.role === 'Focal Person') return s.courses.map(function (k) { return k.split('::')[0]; }).filter(function (d, i, a) { return a.indexOf(d) === i; });
        if ((s.role === 'Coordinator' || s.role === 'Statistician') && s.dept !== 'ALL') return [s.dept];
        return AN.insts.map(function (i) { return i.key; });
    }
    function statusMix(list) {
        var c = {}; list.forEach(function (s) { c[s.status] = (c[s.status] || 0) + 1; });
        return Object.keys(c).map(function (k) { return c[k] + ' ' + k; }).join(' · ') || 'none';
    }

    /* ============================== the main hologram ============================== */
    var M = {};
    var app = new HoloApp({ key: 'admin', onTheme: function (p) { retheme(p); }, tipOf: tipOf, opens: function (it) { return !!(it.kind && it.d); }, open: open });
    colours(app.pal);
    var main = new Holo($('avMain'), {
        theme: app.theme, fitW: 1200, fitH: 1270, yMid: 10, cyFrac: .53,
        related: related,
        label: function (it) { return it.kind === 'inst' || it.kind === 'role' || it.kind === 'help' ? it.always : null; },
        onHover: function (it, mx, my) { trace(it); app.tip(it, mx, my, main.c); },
        onClick: app.open,
        after: app.wires
    });

    function build() {
        var insts = AN.insts.filter(function (i) { return !F.inst || i.key === F.inst; });
        var courses = AN.courses.filter(function (c) { return !F.inst || c.dept === F.inst; });
        var staff = staffIn(F.inst);
        var roles = ROLE.map(function (r) { return { role: r, people: staff.filter(function (s) { return s.role === r; }) }; }).filter(function (r) { return r.people.length; });
        var stu = students(), hs = help();
        var L = [{ y: 345, r: 230, label: 'Staff', n: staff.length }, { y: 185, r: 260, label: 'Institutions', n: insts.length },
                 { y: -20, r: 380, label: F.ym ? 'Courses · students joined' : 'Courses · students', n: sum(stu) },
                 { y: -340, r: 240, label: F.ym ? 'Help desk · filed' : 'Help desk', n: hs.length }];
        var nodes = [], bars = [], links = [], byInst = {}, byCourse = {};

        var perCourse = {}; stu.forEach(function (s) { var k = s.d + '::' + s.p; perCourse[k] = (perCourse[k] || 0) + s.n; });
        var maxC = Math.max.apply(null, courses.map(function (c) { return perCourse[c.key] || 0; }).concat(1));
        courses.forEach(function (c, i) {
            var p = ring(courses.length, L[2].r * .8, L[2].y, i, .3), n = perCourse[c.key] || 0;
            var b = { kind: 'course', d: c, n: n, x: p.x, y: p.y, z: p.z, a: p.a, w: 8, h: height(n, maxC, 140), color: colOf[c.dept], label: abbr(c.program) + ' · ' + tag(c.dept) };
            byCourse[c.key] = b; bars.push(b);
        });
        insts.forEach(function (ins) {
            var mine = bars.filter(function (b) { return b.d.dept === ins.key; }), sx = 0, sz = 0;
            mine.forEach(function (b) { sx += Math.cos(b.a); sz += Math.sin(b.a); });
            var a = Math.atan2(sz, sx), r = insts.length === 1 ? 0 : L[1].r * .5;
            var n = { kind: 'inst', d: ins, x: Math.cos(a) * r, y: L[1].y, z: Math.sin(a) * r, size: 10, color: colOf[ins.key], always: ins.tag, label: ins.tag };
            byInst[ins.key] = n; nodes.push(n);
            mine.forEach(function (b) { links.push({ a: n, b: b, alpha: .22 }); });
        });
        roles.forEach(function (r, i) {
            var p = ring(roles.length, roles.length === 1 ? 0 : L[0].r * .62, L[0].y, i, .9);
            var waiting = r.people.filter(function (s) { return s.status === 'pending'; }).length, off = r.people.filter(function (s) { return s.status === 'disabled' || s.status === 'rejected'; }).length;
            var n = { kind: 'role', d: r, x: p.x, y: p.y, z: p.z, shape: 'diamond', color: ROLE_COL[r.role], ring: waiting ? RING.pending : off ? RING.disabled : null,
                      always: r.role + ' ×' + r.people.length, label: r.role };
            var to = {}; r.people.forEach(function (s) { instsOf(s).forEach(function (d) { to[d] = 1; }); });
            n.targets = Object.keys(to).map(function (d) { return byInst[d]; }).filter(Boolean);
            n.targets.forEach(function (t) { links.push({ a: n, b: t, alpha: .2 }); });
            nodes.push(n);
        });
        var cats = Object.keys(CAT), maxH = Math.max.apply(null, cats.map(function (k) { return hs.filter(function (h) { return h.cat === k; }).length; }).concat(1));
        cats.forEach(function (k, i) {
            var list = hs.filter(function (h) { return h.cat === k; }), open = list.filter(function (h) { return h.st === 'open'; }).length;
            var p = ring(cats.length, L[3].r * .66, L[3].y, i, .5);
            var b = { kind: 'help', d: { cat: k, list: list, open: open }, x: p.x, y: p.y, z: p.z, w: 11, h: height(list.length, maxH, 115),
                      color: !list.length ? NONE : open ? WARN : GOOD, label: CAT[k] + ' · ' + list.length, always: CAT[k] + ' · ' + list.length };
            var from = {}; list.forEach(function (h) { if (byInst[h.d]) from[h.d] = (from[h.d] || 0) + 1; });
            b.targets = Object.keys(from).map(function (d) { links.push({ a: byInst[d], b: b, bTop: false, alpha: .12 + .3 * from[d] / list.length }); return byInst[d]; });
            bars.push(b);
        });
        M = { insts: insts, courses: courses, staff: staff, roles: roles, stu: stu, hs: hs, byInst: byInst, byCourse: byCourse, nodes: nodes, bars: bars };
        main.set({ layers: L, nodes: nodes, bars: bars, links: links });
        main.pin(null);
        telemetry();
    }

    function related(it) {
        var out = [it];
        var courseOf = function (ins) { return M.bars.filter(function (b) { return b.kind === 'course' && b.d.dept === ins.d.key; }); };
        if (it.kind === 'inst') {
            out = out.concat(courseOf(it));
            M.nodes.forEach(function (n) { if (n.kind === 'role' && n.targets.indexOf(it) !== -1) out.push(n); });
            M.bars.forEach(function (b) { if (b.kind === 'help' && b.targets.indexOf(it) !== -1) out.push(b); });
        } else if (it.kind === 'course') {
            var ins = M.byInst[it.d.dept]; out.push(ins);
            M.nodes.forEach(function (n) {
                if (n.kind !== 'role') return;
                var hit = n.d.people.some(function (s) { return s.role === 'Focal Person' ? s.courses.indexOf(it.d.key) !== -1 : instsOf(s).indexOf(it.d.dept) !== -1; });
                if (hit) out.push(n);
            });
        } else if (it.kind === 'role') {
            it.targets.forEach(function (t) { out.push(t); });
            if (it.d.role === 'Focal Person') it.d.people.forEach(function (s) { s.courses.forEach(function (k) { out.push(M.byCourse[k]); }); });
        } else if (it.kind === 'help') {
            out = out.concat(it.targets);
        }
        return out.filter(Boolean);
    }

    function trace(it) {
        var p = '';
        if (it && it.kind === 'course') p = [tag(it.d.dept), it.d.program, plural(it.n, 'student')].join(' → ');
        else if (it && it.kind === 'inst') p = [it.d.tag, plural(M.courses.filter(function (c) { return c.dept === it.d.key; }).length, 'course')].join(' → ');
        else if (it && it.kind === 'role') p = [it.d.role, plural(it.d.people.length, 'account'), statusMix(it.d.people)].join(' → ');
        else if (it && it.kind === 'help') p = ['Help desk', CAT[it.d.cat], plural(it.d.list.length, 'request'), it.d.open + ' open'].join(' → ');
        $('avPath').textContent = p ? 'Trace · ' + p : ' ';
    }
    function tipOf(it) {
        var d = it.d;
        if (it.kind === 'course') {
            var all = AN.students.filter(function (s) { return s.d === d.dept && s.p === d.program; });
            return '<b>' + esc(d.program) + '</b><small>' + esc(tag(d.dept)) + '</small><br>' + plural(sum(all), 'student') + ', ' + plural(sum(all, 'g'), 'group') +
                (F.ym ? '<br>' + it.n + ' joined in ' + esc(period()) : '');
        }
        if (it.kind === 'inst') {
            var s2 = AN.students.filter(function (s) { return s.d === d.key; });
            return '<b>' + esc(d.tag) + '</b><small>' + esc(d.key) + '</small><br>' + plural(sum(s2), 'student') + ' in ' + plural(AN.courses.filter(function (c) { return c.dept === d.key; }).length, 'course') +
                '<br>' + plural(staffIn(d.key).filter(function (s) { return s.role !== 'Admin' && s.role !== 'Director' && s.role !== 'Secretary'; }).length, 'staff member') + ' of its own';
        }
        if (it.kind === 'role') {
            var made = F.ym ? d.people.filter(function (s) { return HoloApp.inPeriod(s.ym, F.ym); }).length : null;
            return '<b>' + esc(d.role) + '</b><small>' + plural(d.people.length, 'account') + '</small><br>' + esc(statusMix(d.people)) +
                (made != null ? '<br>' + made + ' made in ' + esc(period()) : '') + '<br>' + esc(d.people.slice(0, 4).map(function (s) { return s.name; }).join(', ') + (d.people.length > 4 ? '…' : ''));
        }
        if (it.kind === 'help') {
            return '<b>' + esc(CAT[d.cat]) + '</b><small>Help desk · ' + esc(period()) + '</small><br>' + plural(d.list.length, 'request') +
                (d.list.length ? '<br><em><i style="background:' + (d.open ? WARN : GOOD) + '"></i>' + (d.open ? d.open + ' still open' : 'All answered') + '</em>' : '');
        }
        if (it.kind === 'staff') {
            return '<b>' + esc(d.name) + '</b><small>' + esc(d.role) + ' · ' + esc(covers(d)) + '</small><br>Account: ' + esc(d.status) + '<br>Last sign-in: ' + esc(d.last || 'never');
        }
        return '';
    }

    function telemetry() {
        var c = AN.counts, sg = signins(), ok = sg.filter(function (s) { return s.ok; }), bad = sg.filter(function (s) { return !s.ok; });
        function t(n, label, warn) { return '<div class="av-t ' + (warn && n ? 'warn' : '') + '"><b>' + n + '</b><small>' + label + '</small></div>'; }
        function rows(list, max) {
            max = max || Math.max.apply(null, list.map(function (r) { return r[1]; }).concat(1));
            return '<div class="av-stages">' + list.map(function (r) {
                return '<div class="av-st"><span title="' + esc(r[0]) + '">' + esc(r[0]) + '</span><span><i style="width:' + (r[1] / max * 100) + '%;background:' + r[2] + '"></i></span><b>' + r[1] + '</b></div>';
            }).join('') + '</div>';
        }
        var today = AN.today, inToday = AN.signins.filter(function (s) { return s.d === today && s.ok; });
        var blocks = [
            { id: 'now', title: 'Right now', html: '<div class="av-t-grid">' + t(c.acct_pending, 'accounts waiting', true) + t(c.help_open, 'help requests open', true) +
                t(c.locked_now, 'locked out now', true) + t(sum(inToday), 'sign-ins today') + '</div>' },
            { id: 'stu', title: (F.ym ? 'Students joined · ' : 'Students · ') + period(), html: rows(M.insts.map(function (i) {
                return [i.tag, sum(M.stu.filter(function (s) { return s.d === i.key; })), colOf[i.key]]; })) +
                '<p class="av-note">' + plural(sum(M.stu), 'student') + ', ' + plural(sum(M.stu, 'g'), 'group leader') + '.</p>' },
            { id: 'help', title: 'Help desk · ' + period(), html: rows(Object.keys(CAT).map(function (k) {
                var l = M.hs.filter(function (h) { return h.cat === k; }); return [CAT[k], l.length, l.some(function (h) { return h.st === 'open'; }) ? WARN : GOOD]; }).filter(function (r) { return r[1]; })) +
                '<p class="av-note">' + (M.hs.length ? plural(M.hs.length, 'request') + ' · ' + M.hs.filter(function (h) { return h.st === 'open'; }).length + ' still open (amber).' : 'Nothing reported in this period.') + '</p>' },
            { id: 'sign', title: 'Sign-ins · ' + period(), html: rows([['Signed in', sum(ok), GOOD], ['Failed', sum(bad), BAD],
                ['  by students', sum(ok.filter(function (s) { return s.who === 'student'; })), '#5eead4'], ['  by staff', sum(ok.filter(function (s) { return s.who === 'staff'; })), '#5eead4']]) },
            { id: 'staff', title: 'Staff accounts', html: rows(M.roles.map(function (r) { return [r.role, r.people.length, ROLE_COL[r.role]]; })) +
                '<p class="av-note">' + esc(statusMix(M.staff)) + '.</p>' }
        ];
        app.tele(blocks);
        $('avRead').textContent = 'Live · ' + period() + ' · ' + [plural(M.insts.length, 'institution'), plural(M.courses.length, 'course'), plural(sum(M.stu), F.ym ? 'new student' : 'student'), plural(M.staff.length, 'staff', 'staff')].join(' · ');
        $('avLegend').innerHTML = M.insts.map(function (i) { return '<span><i style="background:' + colOf[i.key] + '"></i>' + esc(i.tag) + ' course</span>'; }).join('') +
            '<span>Bar height = ' + (F.ym ? 'students who joined' : 'students') + '</span><span><i class="dia"></i>Staff role</span><span><i class="ring"></i>Account waiting</span>' +
            '<span><i style="background:' + WARN + '"></i>Help: some open</span><span><i style="background:' + GOOD + '"></i>Help: all answered</span>';
    }

    /* ============================== pop-outs ============================== */
    var KIND = { role: 'Role', inst: 'Institution', course: 'Course', help: 'Help desk', staff: 'Staff' };
    function open(it, src) {
        var d = it.d, spec = { kind: KIND[it.kind] };
        if (it.kind === 'role') { spec.key = 'role:' + d.role; spec.title = d.role; spec.sub = plural(d.people.length, 'account') + ' · ' + (F.inst ? tag(F.inst) : 'both institutions'); spec.build = function (p) { podRole(p, d); }; }
        else if (it.kind === 'inst') { spec.key = 'inst:' + d.key; spec.title = d.tag; spec.sub = d.key + ' · ' + period(); spec.build = function (p) { podInst(p, d); }; }
        else if (it.kind === 'course') { spec.key = 'course:' + d.key; spec.title = d.program; spec.sub = tag(d.dept) + ' · ' + period(); spec.build = function (p) { podCourse(p, d); }; }
        else if (it.kind === 'help') { spec.key = 'help:' + d.cat; spec.title = CAT[d.cat]; spec.sub = 'Reported issues · last six months'; spec.build = function (p) { podHelp(p, d.cat); }; }
        else if (it.kind === 'staff') {
            spec.key = 'staff:' + d.id; spec.title = d.name; spec.sub = d.role + ' · ' + covers(d);
            spec.build = function (p) {
                fetch('admin_visualize.php?pod=staff&id=' + encodeURIComponent(d.id), { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { if (j) podStaff(p, d, j); }).catch(function () {});
            };
        } else return;
        app.pod(it, src, spec);
    }
    function person(s, p) {
        return { kind: 'staff', d: s, x: p.x, y: p.y, z: p.z, shape: 'diamond', color: ROLE_COL[s.role] || '#cffafe', ring: RING[s.status] || null, label: s.name, always: s.name };
    }
    function instNode(key, p) {
        var ins = AN.insts.filter(function (i) { return i.key === key; })[0];
        return { kind: ins ? 'inst' : null, d: ins, x: p.x, y: p.y, z: p.z, size: 7, color: colOf[key] || '#5eead4', always: tag(key), label: tag(key) };
    }

    // ---- a ROLE: its people on top (click one for their sign-ins), what they cover below
    function podRole(pod, r) {
        var people = r.people, L = [{ y: 120, r: 210, label: 'Accounts', n: people.length }, { y: -110, r: 220, label: 'Covers' }];
        var nodes = [], links = [], bars = [], targets = {};
        people.forEach(function (s, i) { var n = person(s, ring(people.length, people.length === 1 ? 0 : 150, 120, i, .3)); n.always = people.length <= 8 ? s.name : null; nodes.push(n); s._n = n; });
        if (r.role === 'Focal Person') {
            var keys = []; people.forEach(function (s) { s.courses.forEach(function (k) { if (keys.indexOf(k) === -1) keys.push(k); }); });
            keys.forEach(function (k, i) {
                var c = AN.courses.filter(function (x) { return x.key === k; })[0], p = ring(keys.length, keys.length === 1 ? 0 : 160, -110, i, .2);
                var n = { kind: c ? 'course' : null, d: c, x: p.x, y: p.y, z: p.z, size: 6, color: colOf[k.split('::')[0]] || '#5eead4', always: abbr(k.split('::')[1]), label: k.split('::')[1] };
                targets[k] = n; nodes.push(n);
            });
            people.forEach(function (s) { s._n.rel = s.courses.map(function (k) { return targets[k]; }); s._n.rel.forEach(function (t) { links.push({ a: s._n, b: t, alpha: .35 }); }); });
            L[1].label = 'Courses'; L[1].n = keys.length;
        } else {
            var ds = {}; people.forEach(function (s) { instsOf(s).forEach(function (d) { ds[d] = 1; }); });
            var ks = Object.keys(ds);
            ks.forEach(function (d, i) { targets[d] = instNode(d, ring(ks.length, ks.length === 1 ? 0 : 120, -110, i, .4)); nodes.push(targets[d]); });
            people.forEach(function (s) { s._n.rel = instsOf(s).map(function (d) { return targets[d]; }); s._n.rel.forEach(function (t) { links.push({ a: s._n, b: t, alpha: .3 }); }); });
            L[1].label = 'Institutions'; L[1].n = ks.length;
        }
        var never = people.filter(function (s) { return !s.last; }).length, quiet = people.filter(function (s) { return s.idle != null && s.idle > 14; }).length;
        app.ready(pod, { layers: L, nodes: nodes, bars: bars, links: links }, [
            ['Accounts', people.length], ['Status', esc(statusMix(people))],
            ['Never signed in', never], ['Quiet for 2+ weeks', quiet],
            ['Made ' + (F.ym ? 'in ' + period() : 'by'), esc(F.ym ? String(people.filter(function (s) { return HoloApp.inPeriod(s.ym, F.ym); }).length) : people.map(function (s) { return s.by; }).filter(Boolean).filter(function (x, i, a) { return a.indexOf(x) === i; }).join(', ') || '—')],
            ['Must change password', people.filter(function (s) { return s.mustpw; }).length],
            ['People', esc(people.map(function (s) { return s.name; }).join(', ')), true]
        ], [['Account active', ROLE_COL[r.role]], ['Waiting (ring)', RING.pending], ['Disabled (ring)', RING.disabled]]);
    }

    // ---- an INSTITUTION: a bar per course (students), its own staff above
    function podInst(pod, ins) {
        var cs = AN.courses.filter(function (c) { return c.dept === ins.key; });
        var st = students(function (s) { return s.d === ins.key; }), per = {};
        st.forEach(function (s) { per[s.p] = (per[s.p] || 0) + s.n; });
        var own = AN.staff.filter(function (s) { return s.dept === ins.key || s.courses.some(function (k) { return k.indexOf(ins.key + '::') === 0; }); });
        var max = Math.max.apply(null, cs.map(function (c) { return per[c.program] || 0; }).concat(1));
        var L = [{ y: -60, r: 250, label: F.ym ? 'Courses · joined' : 'Courses · students', n: sum(st) }, { y: 170, r: 170, label: 'Its staff', n: own.length }];
        var bars = [], nodes = [], links = [], byKey = {};
        cs.forEach(function (c, i) {
            var p = ring(cs.length, 175, -60, i, .3), n = per[c.program] || 0;
            var b = { kind: 'course', d: c, n: n, x: p.x, y: -60, z: p.z, w: 9, h: height(n, max, 130), color: colOf[ins.key], label: c.program + ' · ' + n, always: cs.length <= 12 ? abbr(c.program) : null };
            byKey[c.key] = b; bars.push(b);
        });
        own.forEach(function (s, i) {
            var n = person(s, ring(own.length, own.length === 1 ? 0 : 115, 170, i, .3)); n.always = own.length <= 6 ? s.name : null;
            n.rel = s.role === 'Focal Person' ? s.courses.map(function (k) { return byKey[k]; }).filter(Boolean) : bars.slice();
            nodes.push(n);
            if (s.role === 'Focal Person') n.rel.forEach(function (b) { links.push({ a: n, b: b, bTop: true, alpha: .3 }); });
        });
        var allSt = AN.students.filter(function (s) { return s.d === ins.key; });
        var noSec = sum(allSt.filter(function (s) { return !s.sec; })), hs = help(function (h) { return h.d === ins.key; });
        var uncovered = cs.filter(function (c) { return !AN.staff.some(function (s) { return s.role === 'Focal Person' && s.status === 'active' && s.courses.indexOf(c.key) !== -1; }); });
        app.ready(pod, { layers: L, nodes: nodes, bars: bars, links: links }, [
            ['Students', sum(allSt)], ['Groups', sum(allSt, 'g')],
            ['Courses', cs.length], ['Not in a section', noSec],
            ['Joined · ' + period(), F.ym ? sum(st) : '—'], ['Help requests · ' + period(), hs.length],
            ['Staff', esc(statusMix(own))], ['Accounts waiting', own.filter(function (s) { return s.status === 'pending'; }).length],
            ['Courses with no focal person', esc(uncovered.map(function (c) { return abbr(c.program); }).join(', ') || 'None — every course is covered'), true]
        ], [['Bar = ' + (F.ym ? 'students who joined' : 'students') + ' in the course', colOf[ins.key]], ['Staff (diamond)', ROLE_COL.Coordinator]]);
    }

    // ---- a COURSE: a bar per section (students), its focal persons above
    function podCourse(pod, c) {
        var st = students(function (s) { return s.d === c.dept && s.p === c.program; }), per = {};
        st.forEach(function (s) { per[s.sec] = (per[s.sec] || 0) + s.n; });
        var secs = Object.keys(per).sort(function (a, b) { return ((AN.sections[a] || {}).short || 'zz').localeCompare((AN.sections[b] || {}).short || 'zz'); });
        var fps = AN.staff.filter(function (s) { return s.courses.indexOf(c.key) !== -1; });
        var max = Math.max.apply(null, secs.map(function (k) { return per[k]; }).concat(1));
        var L = [{ y: -60, r: 230, label: 'Sections', n: secs.length }, { y: 170, r: 140, label: 'Focal persons', n: fps.length }];
        var bars = [], nodes = [], links = [];
        secs.forEach(function (k, i) {
            var p = ring(secs.length, secs.length === 1 ? 0 : 150, -60, i, .3), name = k === '0' ? 'No section yet' : (AN.sections[k] || {}).short || 'Section ' + k;
            bars.push({ x: p.x, y: -60, z: p.z, w: 11, h: height(per[k], max, 130), color: k === '0' ? NONE : colOf[c.dept], label: name, always: secs.length <= 10 ? name : null,
                        tip: '<b>' + esc(name) + '</b><small>' + esc(c.program) + '</small><br>' + plural(per[k], F.ym ? 'student joined' : 'student', F.ym ? 'students joined' : 'students') });
        });
        fps.forEach(function (s, i) { var n = person(s, ring(fps.length, fps.length === 1 ? 0 : 90, 170, i, .3)); n.rel = bars.slice(); nodes.push(n); bars.forEach(function (b) { links.push({ a: n, b: b, bTop: true, alpha: .18 }); }); });
        var all = AN.students.filter(function (s) { return s.d === c.dept && s.p === c.program; });
        var off = sum(all.filter(function (s) { return s.s !== 'active'; }));
        app.ready(pod, { layers: L, nodes: nodes, bars: bars, links: links }, [
            ['Students', sum(all)], ['Groups', sum(all, 'g')],
            ['Sections', secs.filter(function (k) { return k !== '0'; }).length], ['Not in a section', sum(all.filter(function (s) { return !s.sec; }))],
            ['Joined · ' + period(), F.ym ? sum(st) : '—'], ['Not active', off],
            ['Focal persons', esc(fps.map(function (s) { return s.name + (s.status !== 'active' ? ' (' + s.status + ')' : ''); }).join(', ') || 'Nobody covers it yet'), true]
        ], [['Bar = ' + (F.ym ? 'students who joined' : 'students') + ' in the section', colOf[c.dept]], ['No section yet', NONE]]);
    }

    // ---- a HELP category: six months of requests (answered / still open), the latest ones listed
    function podHelp(pod, cat) {
        var all = AN.help.filter(function (h) { return h.cat === cat && (!F.inst || h.d === F.inst); });
        var months = HoloApp.lastMonths(6), max = 1, bars = [], links = [], nodes = [];
        months.forEach(function (m) { max = Math.max(max, all.filter(function (h) { return h.ym === m.ym; }).length); });
        months.forEach(function (m, i) {
            var l = all.filter(function (h) { return h.ym === m.ym; }), op = l.filter(function (h) { return h.st === 'open'; }).length, p = ring(months.length, 150, -20, i, -Math.PI / 2);
            var lab = m.label.split(' ')[0].slice(0, 3);
            [[op, WARN, 'still open', -10], [l.length - op, GOOD, 'answered', 10]].forEach(function (x) {
                var px = p.x + Math.cos(p.a + Math.PI / 2) * x[3], pz = p.z + Math.sin(p.a + Math.PI / 2) * x[3];
                bars.push({ x: px, y: -20, z: pz, w: 7, h: x[0] ? height(x[0], max, 150) : 2, color: x[0] ? x[1] : NONE, label: lab + ' · ' + x[0] + ' ' + x[2], always: x[3] > 0 ? lab : null,
                            tip: '<b>' + esc(m.label) + '</b><small>' + esc(CAT[cat]) + '</small><br><em><i style="background:' + x[1] + '"></i>' + plural(x[0], 'request') + ' ' + x[2] + '</em>' });
            });
        });
        var done = all.filter(function (h) { return h.st !== 'open' && h.hrs != null; });
        var avg = done.length ? Math.round(sum(done, 'hrs') / done.length) : null;
        var latest = all.slice(0, 3).map(function (h) { return '<b>' + esc(h.name) + '</b> · ' + esc(h.md) + (h.st === 'open' ? ' · <span style="color:' + WARN + '">open</span>' : '') + '<br>' + esc(h.msg.length > 90 ? h.msg.slice(0, 89) + '…' : h.msg); }).join('<br>');
        app.ready(pod, { layers: [{ y: -20, r: 220, label: 'Last six months', n: all.length }], nodes: nodes, bars: bars, links: links }, [
            ['Requests', all.length], ['Still open', all.filter(function (h) { return h.st === 'open'; }).length],
            ['Average answer', avg == null ? 'None answered yet' : plural(avg, 'hour')], ['From', esc(['student', 'staff', 'unknown'].map(function (w) { var n = all.filter(function (h) { return h.who === w; }).length; return n ? n + ' ' + (w === 'unknown' ? 'not signed in' : w) : ''; }).filter(Boolean).join(', ') || '—')],
            ['Latest', latest || 'Nothing reported yet', true]
        ], [['Still open', WARN], ['Answered', GOOD]]);
    }

    // ---- one STAFF member: 30 days of sign-ins as a ring of bars, what they cover, them on top
    function podStaff(pod, s, data) {
        var L = [{ y: -150, r: 240, label: 'Sign-ins, 30 days' }, { y: 10, r: 200, label: 'Covers' }, { y: 170, r: 90, label: s.role }];
        var nodes = [], bars = [], links = [], total = 0, failed = 0;
        var start = new Date(data.start + 'T00:00:00');
        for (var i = 0; i < 30; i++) {
            var dt = new Date(start.getTime() + i * 864e5), k = dt.getFullYear() + '-' + ('0' + (dt.getMonth() + 1)).slice(-2) + '-' + ('0' + dt.getDate()).slice(-2);
            var v = data.days[k] || { ok: 0, bad: 0 }, p = ring(30, 185, -150, i, -Math.PI / 2);
            total += v.ok; failed += v.bad;
            var lab = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            bars.push({ x: p.x, y: -150, z: p.z, w: 6, h: 4 + Math.min(110, v.ok * 26 + v.bad * 14), color: v.ok ? GOOD : (v.bad ? BAD : IDLE), label: lab,
                        tip: '<b>' + lab + '</b>' + plural(v.ok, 'sign-in') + (v.bad ? ', ' + v.bad + ' failed' : '') });
        }
        L[0].n = total;
        var me = person(s, { x: 0, y: 170, z: 0 });
        me.kind = null; nodes.push(me);
        var targets = s.role === 'Focal Person' ? s.courses : instsOf(s);
        L[1].n = targets.length;
        targets.forEach(function (t, i) {
            var p = ring(targets.length, targets.length === 1 ? 0 : 120, 10, i, .5), isCourse = t.indexOf('::') !== -1;
            var n = isCourse ? { kind: 'course', d: AN.courses.filter(function (x) { return x.key === t; })[0], x: p.x, y: p.y, z: p.z, size: 6, color: colOf[t.split('::')[0]], always: abbr(t.split('::')[1]), label: t.split('::')[1] }
                             : instNode(t, p);
            if (!n.d) n.kind = null;
            nodes.push(n); links.push({ a: me, b: n, alpha: .45 });
        });
        var recent = (data.recent || []).slice(0, 4).map(function (r) { return (r.success == 1 ? '✓ ' : '✗ ') + esc(r.at) + (r.success == 1 ? '' : ' (' + esc(r.reason) + ')'); }).join('<br>');
        app.ready(pod, { layers: L, nodes: nodes, bars: bars, links: links }, [
            ['Role', esc(s.role)], ['Account', esc(s.status)], ['Covers', esc(covers(s))], ['Joined', esc(s.joined)],
            ['Last sign-in', esc(s.last || 'never')], ['30 days', plural(total, 'sign-in') + (failed ? ', ' + failed + ' failed' : '')],
            ['Latest attempts', recent || 'None recorded', true]
        ], [['Signed in', GOOD], ['Only failed', BAD], ['No attempt', IDLE]]);
    }

    function retheme(pal) { colours(pal); main.setTheme(app.theme); app.closeAll(); build(); }

    /* ============================== boot ============================== */
    app.controls(AN.months, AN.insts, function (k, v) { F[k] = v; app.closeAll(); build(); });
    window.addEventListener('resize', function () { main.resize(); });
    build();
    if (window.lucide) lucide.createIcons();
});
