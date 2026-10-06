/* =============================================================================
   The office's hologram (2026-10-01). office_visualize.php.
   Four discs, top to bottom:
     Staff         a marker per ROLE (ring = an account waiting / disabled)
     Institutions  a node each
     Courses       a bar each = research groups (with a month: that month's activity)
     Phases        a bar per stage = groups there (with a month: that phase's activity)
   Courses are wired to the stages their groups sit in, thicker for more groups.
   Nothing is drawn per group, so it scales. Data: window.OA (an_office_dataset,
   the Analytics one), already scoped server-side.
   ========================================================================== */
HoloApp.ready(function () {   // after portal.js has put the viewer's theme on <body>
    'use strict';
    var esc = HoloApp.esc, plural = HoloApp.plural, ring = HoloApp.ring, abbr = HoloApp.abbr, height = HoloApp.height;

    // COLOURS FOLLOW THE VIEWER (colours(), re-run when they switch light/dark or accent):
    // stages = dataviz categorical slots 1-5 for that mode, the same order Analytics uses;
    // institutions = non-categorical tints so a course bar never reads as a stage; staff roles =
    // tones of THEIR accent.
    var STAGE = { proposal: ['Proposal'], stats: ['Statistics'], final: ['Final'], plag: ['Plagiarism'], done: ['Done'] };
    var STAGES = ['proposal', 'stats', 'final', 'plag', 'done'];
    var INST_COL, ROLE_COL, RING, GOOD, WARN, BAD, NONE;
    var ROLE = ['Director', 'Secretary', 'Coordinator', 'Statistician', 'Focal Person'];
    var KINDS = { sub: 'Sent in', ok: 'Approved', back: 'Sent back', def: 'Defenses held' };

    var F = { inst: OA.insts.length === 1 ? OA.insts[0].key : '', ym: '' };
    var tagOf = {}, colOf = {};
    OA.insts.forEach(function (i) { tagOf[i.key] = i.tag; });
    function colours(pal) {
        var d = pal.dark, t = pal.tones(5);
        var slots = d ? ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181'] : ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];
        STAGES.forEach(function (s, i) { STAGE[s][1] = slots[i]; });
        INST_COL = d ? ['#a5f3fc', '#f5d0fe', '#bbf7d0', '#fde68a'] : ['#0891b2', '#a21caf', '#15803d', '#b45309'];
        ROLE_COL = { Director: t[0], Secretary: t[1], Coordinator: t[2], Statistician: t[3], 'Focal Person': t[4] };
        GOOD = '#0ca30c'; WARN = d ? '#fab219' : '#e09b00'; BAD = '#d03b3b'; NONE = d ? '#3f4a52' : '#cbd5e1';
        RING = { pending: pal.holo.hot, disabled: BAD, rejected: BAD };
        OA.insts.forEach(function (i, n) { colOf[i.key] = INST_COL[n % INST_COL.length]; });
    }
    function $(id) { return document.getElementById(id); }
    function tag(d) { return d === 'ALL' ? 'Both institutions' : (tagOf[d] || d || '—'); }
    function period() { return HoloApp.periodLabel(F.ym, OA.months); }
    function sum(rows) { return rows.reduce(function (s, r) { return s + r.n; }, 0); }
    function ckey(g) { return g.d + '::' + g.p; }

    // ---- the filtered data ---------------------------------------------------------------------
    function groups(extra) { return OA.groups.filter(function (g) { return (!F.inst || g.d === F.inst) && (!extra || extra(g)); }); }
    function activity(extra) { return OA.activity.filter(function (a) { return (!F.inst || a.d === F.inst) && HoloApp.inPeriod(a.ym, F.ym) && (!extra || extra(a)); }); }
    function batches() { return OA.batches.filter(function (b) { return (!F.inst || b.d === F.inst) && HoloApp.inPeriod(b.ym, F.ym); }); }
    function staffIn(inst) {
        return OA.staff.filter(function (s) {
            if (!inst) return true;
            return s.dept === inst || s.dept === 'ALL' || s.role === 'Director' || s.role === 'Secretary' || s.courses.some(function (k) { return k.indexOf(inst + '::') === 0; });
        });
    }
    function instsOf(s) {
        if (s.role === 'Focal Person') return s.courses.map(function (k) { return k.split('::')[0]; }).filter(function (d, i, a) { return a.indexOf(d) === i; });
        if ((s.role === 'Coordinator' || s.role === 'Statistician') && s.dept !== 'ALL') return [s.dept];
        return OA.insts.map(function (i) { return i.key; });
    }
    function covers(s) {
        if (s.role === 'Focal Person') return s.courses.length ? s.courses.map(function (k) { return abbr(k.split('::')[1]); }).join(', ') : 'No courses yet';
        if (s.role === 'Coordinator' || s.role === 'Statistician') return tag(s.dept);
        return 'Everything';
    }
    function mix(list) {
        var c = {}; list.forEach(function (g) { c[g.stage] = (c[g.stage] || 0) + 1; });
        return STAGES.filter(function (s) { return c[s]; }).map(function (s) { return STAGE[s][0] + ' ' + c[s]; }).join(' · ') || 'no groups';
    }
    function actLine(list) {
        return ['sub', 'ok', 'back', 'def'].map(function (k) { return KINDS[k] + ' ' + sum(list.filter(function (a) { return a.k === k; })); }).join(' · ');
    }
    // The phase a STAGE's activity is counted under ('done' has none: those groups are finished).
    var PH = { proposal: 'proposal', stats: 'stats', final: 'final', plag: 'plag' };

    /* ============================== the main hologram ============================== */
    var M = {};
    var app = new HoloApp({ key: 'office', onTheme: function (p) { retheme(p); }, tipOf: tipOf, opens: function (it) { return !!(it.kind && it.d && it.kind !== 'person'); }, open: open });
    colours(app.pal);
    var main = new Holo($('avMain'), {
        theme: app.theme, fitW: 1200, fitH: 1270, yMid: 10, cyFrac: .53,
        related: related,
        label: function (it) { return it.kind === 'inst' || it.kind === 'role' || it.kind === 'stage' ? it.always : null; },
        onHover: function (it, mx, my) { trace(it); app.tip(it, mx, my, main.c); },
        onClick: app.open,
        after: app.wires
    });

    function build() {
        var insts = OA.insts.filter(function (i) { return !F.inst || i.key === F.inst; });
        var courses = OA.courses.filter(function (c) { return !F.inst || c.dept === F.inst; });
        var gs = groups(), act = activity(), staff = staffIn(F.inst);
        var roles = ROLE.map(function (r) { return { role: r, people: staff.filter(function (s) { return s.role === r; }) }; }).filter(function (r) { return r.people.length; });
        var stages = F.ym ? STAGES.filter(function (s) { return PH[s]; }) : STAGES;
        var L = [{ y: 345, r: 230, label: 'Staff', n: staff.length }, { y: 185, r: 260, label: 'Institutions', n: insts.length },
                 { y: -20, r: 380, label: F.ym ? 'Courses · activity' : 'Courses · groups', n: F.ym ? sum(act) : gs.length },
                 { y: -340, r: 250, label: F.ym ? 'Phases · activity' : 'Phases · groups', n: F.ym ? sum(act) : gs.length }];
        var nodes = [], bars = [], links = [], byInst = {}, byCourse = {}, byStage = {};

        var val = {}; // per course: groups (all time) or activity (a month)
        if (F.ym) act.forEach(function (a) { val[a.d + '::' + a.p] = (val[a.d + '::' + a.p] || 0) + a.n; });
        else gs.forEach(function (g) { val[ckey(g)] = (val[ckey(g)] || 0) + 1; });
        var maxC = Math.max.apply(null, courses.map(function (c) { return val[c.key] || 0; }).concat(1));
        courses.forEach(function (c, i) {
            var p = ring(courses.length, L[2].r * .8, L[2].y, i, .3), n = val[c.key] || 0;
            var b = { kind: 'course', d: c, n: n, x: p.x, y: p.y, z: p.z, a: p.a, w: 8, h: height(n, maxC, 140), color: colOf[c.dept], label: abbr(c.program) + ' · ' + tag(c.dept) };
            byCourse[c.key] = b; bars.push(b);
        });
        insts.forEach(function (ins) {
            var mine = bars.filter(function (b) { return b.d.dept === ins.key; }), sx = 0, sz = 0;
            mine.forEach(function (b) { sx += Math.cos(b.a); sz += Math.sin(b.a); });
            var a = Math.atan2(sz, sx), r = insts.length === 1 ? 0 : L[1].r * .5;
            var n = { kind: 'inst', d: ins, x: Math.cos(a) * r, y: L[1].y, z: Math.sin(a) * r, size: 10, color: colOf[ins.key], always: ins.tag, label: ins.tag };
            byInst[ins.key] = n; nodes.push(n);
            mine.forEach(function (b) { links.push({ a: n, b: b, alpha: .2 }); });
        });
        roles.forEach(function (r, i) {
            var p = ring(roles.length, roles.length === 1 ? 0 : L[0].r * .62, L[0].y, i, .9);
            var waiting = r.people.some(function (s) { return s.status === 'pending'; }), off = r.people.some(function (s) { return s.status !== 'active' && s.status !== 'pending'; });
            var n = { kind: 'role', d: r, x: p.x, y: p.y, z: p.z, shape: 'diamond', color: ROLE_COL[r.role], ring: waiting ? RING.pending : off ? RING.disabled : null,
                      always: r.role + ' ×' + r.people.length, label: r.role };
            var to = {}; r.people.forEach(function (s) { instsOf(s).forEach(function (d) { to[d] = 1; }); });
            n.targets = Object.keys(to).map(function (d) { return byInst[d]; }).filter(Boolean);
            n.targets.forEach(function (t) { links.push({ a: n, b: t, alpha: .2 }); });
            nodes.push(n);
        });
        // the phases, and each course wired to them by how much of it sits there
        var sv = {}, cs = {};
        stages.forEach(function (s) {
            if (F.ym) { var l = act.filter(function (a) { return a.ph === PH[s]; }); sv[s] = sum(l); l.forEach(function (a) { var k = a.d + '::' + a.p; (cs[k] = cs[k] || {})[s] = (cs[k][s] || 0) + a.n; }); }
            else { var g2 = gs.filter(function (g) { return g.stage === s; }); sv[s] = g2.length; g2.forEach(function (g) { var k = ckey(g); (cs[k] = cs[k] || {})[s] = (cs[k][s] || 0) + 1; }); }
        });
        var maxS = Math.max.apply(null, stages.map(function (s) { return sv[s]; }).concat(1));
        stages.forEach(function (s, i) {
            var p = ring(stages.length, L[3].r * .62, L[3].y, i, .6);
            var b = { kind: 'stage', d: { stage: s }, n: sv[s], x: p.x, y: p.y, z: p.z, w: 14, h: height(sv[s], maxS, 120), color: STAGE[s][1], always: STAGE[s][0] + ' · ' + sv[s], label: STAGE[s][0] };
            byStage[s] = b; bars.push(b);
        });
        Object.keys(cs).forEach(function (k) {
            var c = byCourse[k]; if (!c) return;
            var tot = 0; Object.keys(cs[k]).forEach(function (s) { tot += cs[k][s]; });
            Object.keys(cs[k]).forEach(function (s) { if (byStage[s]) links.push({ a: c, b: byStage[s], alpha: .05 + .25 * cs[k][s] / Math.max(1, tot) }); });
        });
        M = { insts: insts, courses: courses, gs: gs, act: act, staff: staff, roles: roles, cs: cs, byInst: byInst, byCourse: byCourse, byStage: byStage, nodes: nodes, bars: bars };
        main.set({ layers: L, nodes: nodes, bars: bars, links: links });
        main.pin(null);
        telemetry();
    }

    function related(it) {
        var out = [it];
        if (it.kind === 'inst') {
            M.bars.forEach(function (b) { if (b.kind === 'course' && b.d.dept === it.d.key) { out.push(b); Object.keys(M.cs[b.d.key] || {}).forEach(function (s) { out.push(M.byStage[s]); }); } });
            M.nodes.forEach(function (n) { if (n.kind === 'role' && n.targets.indexOf(it) !== -1) out.push(n); });
        } else if (it.kind === 'course') {
            out.push(M.byInst[it.d.dept]);
            Object.keys(M.cs[it.d.key] || {}).forEach(function (s) { out.push(M.byStage[s]); });
            M.nodes.forEach(function (n) {
                if (n.kind === 'role' && n.d.people.some(function (s) { return s.role === 'Focal Person' ? s.courses.indexOf(it.d.key) !== -1 : instsOf(s).indexOf(it.d.dept) !== -1; })) out.push(n);
            });
        } else if (it.kind === 'stage') {
            Object.keys(M.cs).forEach(function (k) { if (M.cs[k][it.d.stage] && M.byCourse[k]) { out.push(M.byCourse[k]); out.push(M.byInst[k.split('::')[0]]); } });
        } else if (it.kind === 'role') {
            it.targets.forEach(function (t) { out.push(t); });
            if (it.d.role === 'Focal Person') it.d.people.forEach(function (s) { s.courses.forEach(function (k) { out.push(M.byCourse[k]); }); });
        }
        return out.filter(Boolean);
    }

    function trace(it) {
        var p = '';
        if (it && it.kind === 'course') p = [tag(it.d.dept), it.d.program, F.ym ? plural(it.n, 'thing') + ' happened' : plural(it.n, 'group')].join(' → ');
        else if (it && it.kind === 'inst') p = [it.d.tag, plural(M.courses.filter(function (c) { return c.dept === it.d.key; }).length, 'course'), plural(M.gs.filter(function (g) { return g.d === it.d.key; }).length, 'group')].join(' → ');
        else if (it && it.kind === 'stage') p = [STAGE[it.d.stage][0], F.ym ? it.n + ' in ' + period() : plural(it.n, 'group'), plural(Object.keys(M.cs).filter(function (k) { return M.cs[k][it.d.stage]; }).length, 'course')].join(' → ');
        else if (it && it.kind === 'role') p = [it.d.role, plural(it.d.people.length, 'person', 'people')].join(' → ');
        $('avPath').textContent = p ? 'Trace · ' + p : ' ';
    }
    function tipOf(it) {
        var d = it.d;
        if (it.kind === 'course') {
            var g = OA.groups.filter(function (x) { return ckey(x) === d.key; }), a = activity(function (x) { return x.d + '::' + x.p === d.key; });
            return '<b>' + esc(d.program) + '</b><small>' + esc(tag(d.dept)) + '</small><br>' + plural(g.length, 'group') + ' · ' + esc(mix(g)) + '<br><small>' + esc(period()) + ': ' + esc(actLine(a)) + '</small>';
        }
        if (it.kind === 'inst') {
            var g2 = OA.groups.filter(function (x) { return x.d === d.key; });
            return '<b>' + esc(d.tag) + '</b><small>' + esc(d.key) + '</small><br>' + plural(g2.length, 'group') + ' in ' + plural(OA.courses.filter(function (c) { return c.dept === d.key; }).length, 'course') + '<br>' + esc(mix(g2));
        }
        if (it.kind === 'stage') {
            var s = d.stage, at = groups(function (g) { return g.stage === s; });
            return '<b><em><i style="background:' + STAGE[s][1] + '"></i>' + STAGE[s][0] + '</em></b><small>' + (F.ym ? 'Activity · ' + esc(period()) : 'Where the groups are now') + '</small><br>' +
                plural(at.length, 'group') + ' here now' + (PH[s] ? '<br><small>' + esc(period()) + ': ' + esc(actLine(activity(function (a) { return a.ph === PH[s]; }))) + '</small>' : '');
        }
        if (it.kind === 'role') {
            return '<b>' + esc(d.role) + '</b><small>' + plural(d.people.length, 'person', 'people') + '</small><br>' + esc(d.people.slice(0, 4).map(function (s) { return s.name; }).join(', ') + (d.people.length > 4 ? '…' : ''));
        }
        if (it.kind === 'person') {
            var load = d.role === 'Focal Person' ? OA.groups.filter(function (g) { return d.courses.indexOf(ckey(g)) !== -1; }) : null;
            return '<b>' + esc(d.name) + '</b><small>' + esc(d.role) + ' · ' + esc(covers(d)) + '</small><br>Account: ' + esc(d.status) + '<br>Last sign-in: ' + esc(d.last || 'never') +
                (load ? '<br>' + plural(load.length, 'group') + ' in their courses' : '');
        }
        return '';
    }

    function telemetry() {
        var gs = M.gs, act = M.act, bs = batches();
        function t(n, label, warn) { return '<div class="av-t ' + (warn && n ? 'warn' : '') + '"><b>' + n + '</b><small>' + label + '</small></div>'; }
        function rows(list) {
            var max = Math.max.apply(null, list.map(function (r) { return r[1]; }).concat(1));
            return '<div class="av-stages">' + list.map(function (r) {
                return '<div class="av-st"><span title="' + esc(r[0]) + '">' + esc(r[0]) + '</span><span><i style="width:' + (r[1] / max * 100) + '%;background:' + r[2] + '"></i></span><b>' + r[1] + '</b></div>';
            }).join('') + '</div>';
        }
        var waiting = gs.reduce(function (s, g) { return s + g.pw + g.fw; }, 0);
        var inChain = OA.batches.filter(function (b) { return (!F.inst || b.d === F.inst) && (b.st === 'Endorsed' || b.st === 'Confirmed'); });
        var noFocal = M.courses.filter(function (c) { return !OA.staff.some(function (s) { return s.role === 'Focal Person' && s.status === 'active' && s.courses.indexOf(c.key) !== -1; }); });
        var blocks = [
            { id: 'now', title: 'Right now', html: '<div class="av-t-grid">' + t(gs.length, 'research groups') + t(waiting, 'documents waiting', true) +
                t(sum(inChain), 'dates in the chain', true) + t(gs.filter(function (g) { return g.stage === 'done'; }).length, 'cleared everything') + '</div>' },
            { id: 'stage', title: 'Where the groups are', html: rows(STAGES.map(function (s) { return [STAGE[s][0], gs.filter(function (g) { return g.stage === s; }).length, STAGE[s][1]]; })) },
            { id: 'act', title: 'Activity · ' + period(), html: rows([['Sent in', sum(act.filter(function (a) { return a.k === 'sub'; })), '#5eead4'],
                ['Approved', sum(act.filter(function (a) { return a.k === 'ok'; })), GOOD], ['Sent back', sum(act.filter(function (a) { return a.k === 'back'; })), BAD],
                ['Defenses held', sum(act.filter(function (a) { return a.k === 'def'; })), '#5eead4']]) },
            { id: 'chain', title: 'Date requests · ' + period(), html: bs.length ? rows([['Published', sum(bs.filter(function (b) { return b.st === 'Scheduled'; })), GOOD],
                ['In the chain', sum(bs.filter(function (b) { return b.st === 'Endorsed' || b.st === 'Confirmed'; })), WARN],
                ['Sent back', sum(bs.filter(function (b) { return b.st === 'Returned'; })), BAD], ['Withdrawn', sum(bs.filter(function (b) { return b.st === 'Cancelled'; })), NONE]]) :
                '<p class="av-note">No date requests in this period.</p>' }
        ];
        if (OA.insts.length > 1) blocks.push({ id: 'inst', title: 'By institution', html: rows(OA.insts.map(function (i) { return [i.tag, OA.groups.filter(function (g) { return g.d === i.key; }).length, colOf[i.key]]; })) + '<p class="av-note">Research groups in each.</p>' });
        blocks.push({ id: 'people', title: 'People', html: rows(M.roles.map(function (r) { return [r.role, r.people.length, ROLE_COL[r.role]]; })) +
            '<p class="av-note">' + (noFocal.length ? plural(noFocal.length, 'course') + ' with no focal person: ' + esc(noFocal.map(function (c) { return abbr(c.program); }).join(', ')) + '.' : 'Every course has a focal person.') + '</p>' });
        app.tele(blocks);
        $('avRead').textContent = 'Live · ' + period() + ' · ' + [F.inst ? tag(F.inst) : plural(M.insts.length, 'institution'), plural(M.courses.length, 'course'), plural(gs.length, 'group')].join(' · ');
        $('avLegend').innerHTML = (F.ym ? STAGES.filter(function (s) { return PH[s]; }) : STAGES).map(function (s) { return '<span><i style="background:' + STAGE[s][1] + '"></i>' + STAGE[s][0] + '</span>'; }).join('') +
            M.insts.map(function (i) { return '<span><i style="background:' + colOf[i.key] + '"></i>' + esc(i.tag) + ' course</span>'; }).join('') +
            '<span>Bar height = ' + (F.ym ? 'what happened in ' + period() : 'research groups') + '</span><span><i class="dia"></i>Staff role</span>';
    }

    /* ============================== pop-outs ============================== */
    var KIND = { role: 'Role', inst: 'Institution', course: 'Course', stage: 'Phase' };
    function open(it, src) {
        var d = it.d, spec = { kind: KIND[it.kind] };
        if (it.kind === 'role') { spec.key = 'role:' + d.role; spec.title = d.role; spec.sub = plural(d.people.length, 'person', 'people'); spec.build = function (p) { podRole(p, d); }; }
        else if (it.kind === 'inst') { spec.key = 'inst:' + d.key; spec.title = d.tag; spec.sub = d.key + ' · ' + period(); spec.build = function (p) { podInst(p, d); }; }
        else if (it.kind === 'course') { spec.key = 'course:' + d.key; spec.title = d.program; spec.sub = tag(d.dept) + ' · ' + period(); spec.build = function (p) { podCourse(p, d); }; }
        else if (it.kind === 'stage') { spec.key = 'stage:' + d.stage; spec.title = STAGE[d.stage][0]; spec.sub = (F.inst ? tag(F.inst) : 'Every institution') + ' · ' + period(); spec.build = function (p) { podStage(p, d.stage); }; }
        else return;
        app.pod(it, src, spec);
    }
    function stageRing(list, y, r, rel) {   // five bars: how many of these groups sit at each stage
        var max = 1, out = [];
        STAGES.forEach(function (s) { max = Math.max(max, list.filter(function (g) { return g.stage === s; }).length); });
        STAGES.forEach(function (s, i) {
            var n = list.filter(function (g) { return g.stage === s; }).length, p = ring(5, r, y, i, .6);
            out.push({ kind: 'stage', d: { stage: s }, n: n, x: p.x, y: y, z: p.z, w: 11, h: height(n, max, 120), color: n ? STAGE[s][1] : NONE, always: STAGE[s][0] + ' ' + n, label: STAGE[s][0],
                       tip: '<b>' + STAGE[s][0] + '</b><br>' + plural(n, 'group') + ' here now', rel: rel || [] });
        });
        return out;
    }

    // ---- a ROLE: its people, and what each one covers
    function podRole(pod, r) {
        var people = r.people, L = [{ y: 120, r: 210, label: 'People', n: people.length }, { y: -110, r: 220, label: 'Covers' }];
        var nodes = [], links = [], targets = {}, ps = [];
        people.forEach(function (s, i) {
            var p = ring(people.length, people.length === 1 ? 0 : 150, 120, i, .3);
            var n = { kind: 'person', d: s, x: p.x, y: p.y, z: p.z, shape: 'diamond', color: ROLE_COL[s.role], ring: RING[s.status] || null, label: s.name, always: people.length <= 8 ? s.name : null };
            ps.push(n); nodes.push(n);
        });
        var keys = [];
        if (r.role === 'Focal Person') { people.forEach(function (s) { s.courses.forEach(function (k) { if (keys.indexOf(k) === -1) keys.push(k); }); }); L[1].label = 'Courses'; }
        else { people.forEach(function (s) { instsOf(s).forEach(function (d) { if (keys.indexOf(d) === -1) keys.push(d); }); }); L[1].label = 'Institutions'; }
        L[1].n = keys.length;
        keys.forEach(function (k, i) {
            var p = ring(keys.length, keys.length === 1 ? 0 : 160, -110, i, .2), isC = k.indexOf('::') !== -1;
            var c = isC ? OA.courses.filter(function (x) { return x.key === k; })[0] : OA.insts.filter(function (x) { return x.key === k; })[0];
            targets[k] = { kind: c ? (isC ? 'course' : 'inst') : null, d: c, x: p.x, y: p.y, z: p.z, size: 6, color: colOf[k.split('::')[0]] || '#5eead4', always: isC ? abbr(k.split('::')[1]) : tag(k), label: isC ? k.split('::')[1] : tag(k) };
            nodes.push(targets[k]);
        });
        ps.forEach(function (n) {
            n.rel = (r.role === 'Focal Person' ? n.d.courses : instsOf(n.d)).map(function (k) { return targets[k]; }).filter(Boolean);
            n.rel.forEach(function (t) { links.push({ a: n, b: t, alpha: .35 }); });
        });
        var load = r.role === 'Focal Person' ? people.map(function (s) { return s.name + ' ' + OA.groups.filter(function (g) { return s.courses.indexOf(ckey(g)) !== -1; }).length; }).join(' · ') : null;
        app.ready(pod, { layers: L, nodes: nodes, bars: [], links: links }, [
            ['People', people.length], ['Never signed in', people.filter(function (s) { return !s.last; }).length],
            ['Accounts', esc(people.filter(function (s) { return s.status === 'active'; }).length + ' active' + (people.some(function (s) { return s.status !== 'active'; }) ? ', ' + people.filter(function (s) { return s.status !== 'active'; }).length + ' not' : ''))],
            [r.role === 'Focal Person' ? 'Courses covered' : 'Institutions', keys.length],
            [load ? 'Groups each one covers' : 'Names', esc(load || people.map(function (s) { return s.name; }).join(', ')), true]
        ], [['Person (diamond)', ROLE_COL[r.role]], ['Waiting (ring)', RING.pending], ['Disabled (ring)', RING.disabled]]);
    }

    // ---- an INSTITUTION: its courses (groups / activity), and where its groups are
    function podInst(pod, ins) {
        var cs = OA.courses.filter(function (c) { return c.dept === ins.key; }), gs = OA.groups.filter(function (g) { return g.d === ins.key; });
        var act = OA.activity.filter(function (a) { return a.d === ins.key && HoloApp.inPeriod(a.ym, F.ym); });
        var val = {}; if (F.ym) act.forEach(function (a) { val[a.d + '::' + a.p] = (val[a.d + '::' + a.p] || 0) + a.n; }); else gs.forEach(function (g) { val[ckey(g)] = (val[ckey(g)] || 0) + 1; });
        var max = Math.max.apply(null, cs.map(function (c) { return val[c.key] || 0; }).concat(1));
        var L = [{ y: 110, r: 240, label: F.ym ? 'Courses · activity' : 'Courses · groups', n: cs.length }, { y: -170, r: 190, label: 'Where its groups are', n: gs.length }];
        var bars = [], links = [], byKey = {};
        cs.forEach(function (c, i) {
            var p = ring(cs.length, 170, 110, i, .3), n = val[c.key] || 0;
            byKey[c.key] = { kind: 'course', d: c, n: n, x: p.x, y: 110, z: p.z, w: 9, h: height(n, max, 110), color: colOf[ins.key], label: c.program + ' · ' + n, always: cs.length <= 12 ? abbr(c.program) : null };
            bars.push(byKey[c.key]);
        });
        var st = stageRing(gs, -170, 130);
        st.forEach(function (b) {
            b.rel = cs.filter(function (c) { return gs.some(function (g) { return ckey(g) === c.key && g.stage === b.d.stage; }); }).map(function (c) { return byKey[c.key]; });
            b.rel.forEach(function (c) { links.push({ a: c, b: b, alpha: .2 }); });
            bars.push(b);
        });
        cs.forEach(function (c) { byKey[c.key].rel = st.filter(function (b) { return b.rel.indexOf(byKey[c.key]) !== -1; }); });
        app.ready(pod, { layers: L, nodes: [], bars: bars, links: links }, [
            ['Groups', gs.length], ['Courses', cs.length],
            ['Students', gs.reduce(function (s, g) { return s + g.members; }, 0)], ['Cleared everything', gs.filter(function (g) { return g.stage === 'done'; }).length],
            ['Documents waiting', gs.reduce(function (s, g) { return s + g.pw + g.fw; }, 0)], ['Defenses held · ' + period(), sum(act.filter(function (a) { return a.k === 'def'; }))],
            [period(), esc(actLine(act)), true]
        ], STAGES.map(function (s) { return STAGE[s]; }).concat([['Course', colOf[ins.key]]]));
    }

    // ---- a COURSE: its rooms on top (groups in each), its stage mix below, its focal persons
    function podCourse(pod, c) {
        var gs = OA.groups.filter(function (g) { return ckey(g) === c.key; }), act = OA.activity.filter(function (a) { return a.d + '::' + a.p === c.key && HoloApp.inPeriod(a.ym, F.ym); });
        var rooms = {}; gs.forEach(function (g) { var r = g.room || 'No room'; rooms[r] = (rooms[r] || 0) + 1; });
        var rk = Object.keys(rooms).sort(), fps = OA.staff.filter(function (s) { return s.courses.indexOf(c.key) !== -1; });
        var L = [{ y: 190, r: 150, label: 'Focal persons', n: fps.length }, { y: 40, r: 200, label: 'Rooms', n: rk.length }, { y: -170, r: 190, label: 'Where its groups are', n: gs.length }];
        var nodes = [], bars = [], links = [], rn = {};
        rk.forEach(function (r, i) {
            var p = ring(rk.length, rk.length === 1 ? 0 : 130, 40, i, .3);
            rn[r] = { x: p.x, y: 40, z: p.z, size: 4 + Math.min(8, rooms[r]), color: r === 'No room' ? NONE : colOf[c.dept], always: r + ' · ' + rooms[r], label: r,
                      tip: '<b>' + esc(r) + '</b><br>' + plural(rooms[r], 'group') + '<br>' + esc(mix(gs.filter(function (g) { return (g.room || 'No room') === r; }))) };
            nodes.push(rn[r]);
        });
        var st = stageRing(gs, -170, 130);
        st.forEach(function (b) {
            b.rel = rk.filter(function (r) { return gs.some(function (g) { return (g.room || 'No room') === r && g.stage === b.d.stage; }); }).map(function (r) { return rn[r]; });
            b.rel.forEach(function (n) { links.push({ a: n, b: b, alpha: .25 }); });
            bars.push(b);
        });
        fps.forEach(function (s, i) {
            var p = ring(fps.length, fps.length === 1 ? 0 : 90, 190, i, .3);
            var n = { kind: 'person', d: s, x: p.x, y: p.y, z: p.z, shape: 'diamond', color: ROLE_COL[s.role], ring: RING[s.status] || null, always: s.name, label: s.name };
            n.rel = rk.map(function (r) { return rn[r]; }); nodes.push(n); n.rel.forEach(function (t) { links.push({ a: n, b: t, alpha: .2 }); });
        });
        var done = function (f) { return gs.filter(f).length + ' of ' + gs.length; };
        app.ready(pod, { layers: L, nodes: nodes, bars: bars, links: links }, [
            ['Groups', gs.length], ['Students', gs.reduce(function (s, g) { return s + g.members; }, 0)],
            ['Proposal done', done(function (g) { return g.pa >= OA.np; })], ['Statistics done', done(function (g) { return g.stats >= 7; })],
            ['Final done', done(function (g) { return g.fa >= OA.nf; })], ['Cleared', done(function (g) { return g.stage === 'done'; })],
            ['Documents waiting', gs.reduce(function (s, g) { return s + g.pw + g.fw; }, 0)], ['Focal persons', esc(fps.map(function (s) { return s.name; }).join(', ') || 'Nobody yet')],
            [period(), esc(actLine(act)), true]
        ], STAGES.map(function (s) { return STAGE[s]; }));
    }

    // ---- a STAGE: which courses hold it (a bar each), and that phase's month by month
    function podStage(pod, s) {
        var at = groups(function (g) { return g.stage === s; }), per = {};
        at.forEach(function (g) { per[ckey(g)] = (per[ckey(g)] || 0) + 1; });
        var ks = Object.keys(per).sort(function (a, b) { return per[b] - per[a]; }), max = Math.max.apply(null, ks.map(function (k) { return per[k]; }).concat(1));
        var L = [{ y: 90, r: 230, label: 'Courses with groups here', n: ks.length }];
        var bars = [], links = [];
        ks.forEach(function (k, i) {
            var c = OA.courses.filter(function (x) { return x.key === k; })[0], p = ring(ks.length, ks.length === 1 ? 0 : 165, 90, i, .3);
            bars.push({ kind: c ? 'course' : null, d: c, x: p.x, y: 90, z: p.z, w: 9, h: height(per[k], max, 110), color: colOf[k.split('::')[0]], label: k.split('::')[1] + ' · ' + per[k], always: ks.length <= 12 ? abbr(k.split('::')[1]) : null });
        });
        if (PH[s]) {   // that phase's activity over the last six months
            var months = HoloApp.lastMonths(6), mx = 1, rows = [];
            months.forEach(function (m) { var l = OA.activity.filter(function (a) { return a.ph === PH[s] && a.ym === m.ym && (!F.inst || a.d === F.inst); }); rows.push([m, l]); mx = Math.max(mx, sum(l)); });
            L.push({ y: -170, r: 190, label: 'Last six months', n: null });
            rows.forEach(function (r, i) {
                var p = ring(rows.length, 140, -170, i, -Math.PI / 2), n = sum(r[1]), lab = r[0].label.split(' ')[0].slice(0, 3);
                bars.push({ x: p.x, y: -170, z: p.z, w: 8, h: height(n, mx, 110), color: STAGE[s][1], label: lab + ' · ' + n, always: lab,
                            tip: '<b>' + esc(r[0].label) + '</b><small>' + STAGE[s][0] + '</small><br>' + esc(actLine(r[1])) });
            });
        }
        var avg = at.length ? Math.round(at.reduce(function (x, g) { return x + g.prog; }, 0) / at.length * 100) : 0;
        app.ready(pod, { layers: L, nodes: [], bars: bars, links: links }, [
            ['Groups here now', at.length], ['Share of all groups', groups().length ? Math.round(at.length / groups().length * 100) + '%' : '—'],
            ['Courses', ks.length], ['Average progress', avg + '% of the four phases'],
            ['Most groups', esc(ks.slice(0, 3).map(function (k) { return abbr(k.split('::')[1]) + ' ' + per[k]; }).join(' · ') || '—')],
            ['Waiting on review', at.reduce(function (x, g) { return x + g.pw + g.fw; }, 0)],
            [PH[s] ? period() : 'Done', PH[s] ? esc(actLine(activity(function (a) { return a.ph === PH[s]; }))) : 'These groups have finished every phase.', true]
        ], OA.insts.map(function (i) { return [i.tag + ' course', colOf[i.key]]; }).concat(PH[s] ? [[STAGE[s][0] + ' activity', STAGE[s][1]]] : []));
    }

    function retheme(pal) { colours(pal); main.setTheme(app.theme); app.closeAll(); build(); }

    /* ============================== boot ============================== */
    app.controls(OA.months, OA.insts, function (k, v) { F[k] = v; app.closeAll(); build(); });
    window.addEventListener('resize', function () { main.resize(); });
    build();
    if (window.lucide) lucide.createIcons();
});
