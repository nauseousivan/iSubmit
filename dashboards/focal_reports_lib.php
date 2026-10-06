<?php
// dashboards/focal_reports_lib.php — the one dataset behind the Focal Person's Reports (2026-10-01).
//
// A focal person asked for "who has submitted, who has not — so I can send a report later". The
// Reports screen answers that and more (requirements, schedule, every group with its members) and
// the Visualize view draws the same data in 3D. Every tab, filter, export and the 3D view read THIS
// array, rendered client-side, so a number can never say one thing in one place and another in the
// next. Built in a fixed handful of queries for everything she handles. Reads only.
//
// Shape (short keys — it goes to the page as JSON):
//   phases  {proposal|final: {items: [{id, label, kid}]}}
//   rooms   {section_id: {short, program, dept, tag, year, label, course}}
//   groups  [{id, name, title, leader, sid, room, members: [{n, s, e}],
//             docs: {item_id: {st A|P|R, up, ts, by, role, at, hrs, rm}},
//             last: {proposal, final} (epoch), date: {proposal|final: {md, dd, slot, st}|null}}]
//   batches [{id, room, phase, st, md, dd, h1, h2, back, n}]

require_once __DIR__ . '/focal_lib.php';
require_once __DIR__ . '/group_roster_lib.php';

function fr_dataset(PDO $pdo, string $role, int $me): array
{
    $today = df_today();

    // ---- what each phase asks for
    $phases = [];
    foreach (['proposal', 'final'] as $ph) {
        $ids   = fp_ready_items($ph);
        $names = fp_item_names($pdo, $ids);
        $phases[$ph] = ['items' => array_map(fn($id) => [
            'id' => (int)$id, 'label' => (string)($names[$id] ?? 'Requirement ' . $id), 'kid' => fp_bundle_parent((int)$id) !== null,
        ], $ids)];
    }

    // ---- her rooms
    $rooms = [];
    foreach (fp_sections_by_course($pdo, $role, $me) as $ck => $c) {
        foreach ($c['sections'] as $s) {
            $rooms[(int)$s['section_id']] = [
                'short' => fp_short_section($s), 'program' => (string)$s['program'], 'dept' => (string)$s['department'],
                'tag' => aa_school_tag((string)$s['department']), 'year' => (string)$s['year_level'],
                'label' => (string)$s['label'], 'course' => $ck,
            ];
        }
    }

    // ---- her groups (leaders), including any still without a room
    [$scU, $scUP] = aa_scope_sql($pdo, $role, $me, 'u');
    $g = $pdo->prepare("SELECT u.user_id, u.username, u.research_group_name, u.research_title, u.student_number, u.section_id
                        FROM users u WHERE " . FP_GROUP_PREDICATE . " AND $scU ORDER BY u.research_group_name");
    $g->execute($scUP);
    $groups = [];
    foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $groups[(int)$r['user_id']] = [
            'id' => (int)$r['user_id'], 'name' => (string)$r['research_group_name'], 'title' => (string)$r['research_title'],
            'leader' => (string)$r['username'], 'sid' => (string)$r['student_number'],
            'room' => isset($rooms[(int)$r['section_id']]) ? (int)$r['section_id'] : 0,
            'members' => [], 'docs' => (object)[], 'last' => ['proposal' => 0, 'final' => 0],
            'date' => ['proposal' => null, 'final' => null],
        ];
    }
    if (!$groups) return ['today' => $today, 'phases' => $phases, 'rooms' => (object)$rooms, 'groups' => [], 'batches' => []];
    $ids = array_keys($groups);
    $idPh = implode(',', array_fill(0, count($ids), '?'));

    // ---- who is in each group
    foreach (ws_group_rosters($pdo, $ids) as $lid => $people) {
        if (!isset($groups[$lid])) continue;
        foreach ($people as $p) $groups[$lid]['members'][] = ['n' => (string)$p['username'], 's' => (string)$p['student_number'], 'e' => (string)$p['email']];
    }

    // ---- the newest upload per requirement, with who decided it and how long that took
    $items = array_merge(fp_ready_items('proposal'), fp_ready_items('final'));
    $itPh  = implode(',', array_fill(0, count($items), '?'));
    $up = $pdo->prepare("SELECT up.user_id, up.item_id, up.verification_status AS st, up.remarks,
                                DATE_FORMAT(up.uploaded_at, '%b %e') AS up_md, UNIX_TIMESTAMP(up.uploaded_at) AS up_ts,
                                v.username AS vb, v.role AS vr, DATE_FORMAT(up.verified_at, '%b %e') AS v_md,
                                TIMESTAMPDIFF(HOUR, up.uploaded_at, up.verified_at) AS hrs
                         FROM uploads up
                         JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads
                               WHERE user_id IN ($idPh) AND item_id IN ($itPh) GROUP BY user_id, item_id) l
                           ON l.user_id = up.user_id AND l.item_id = up.item_id AND l.m = up.uploaded_at
                         LEFT JOIN users v ON v.user_id = up.verified_by");
    $up->execute(array_merge($ids, $items));
    $final = fp_ready_items('final');
    $docs = [];
    foreach ($up->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $r['st'] === 'Approved' ? 'A' : ($r['st'] === 'Revision Requested' ? 'R' : 'P');
        $docs[(int)$r['user_id']][(int)$r['item_id']] = [
            'st' => $st, 'up' => (string)$r['up_md'], 'ts' => (int)$r['up_ts'],
            'by' => $st !== 'P' ? (string)($r['vb'] ?? '') : '', 'role' => $st !== 'P' ? (string)($r['vr'] ?? '') : '',
            'at' => $st !== 'P' ? (string)($r['v_md'] ?? '') : '',
            'hrs' => ($st !== 'P' && $r['hrs'] !== null && (int)$r['hrs'] >= 0) ? (int)$r['hrs'] : null,
            'rm' => $st === 'R' ? (string)$r['remarks'] : '',
        ];
        $ph = in_array((int)$r['item_id'], $final, true) ? 'final' : 'proposal';
        $groups[(int)$r['user_id']]['last'][$ph] = max($groups[(int)$r['user_id']]['last'][$ph], (int)$r['up_ts']);
    }
    foreach ($docs as $uid => $d) $groups[$uid]['docs'] = $d;

    // ---- each group's defense date per phase, and where it stands
    $bk = $pdo->prepare("SELECT db.user_id, db.phase, db.status, db.slot_no, DATE_FORMAT(db.defense_date, '%b %e') AS md,
                                DATEDIFF(db.defense_date, ?) AS dd, b.status AS bst
                         FROM defense_bookings db LEFT JOIN defense_batches b ON b.batch_id = db.batch_id
                         WHERE db.user_id IN ($idPh) AND db.status IN ('Applied','Date Selected','Scheduled')
                         ORDER BY db.defense_date ASC");
    $bk->execute(array_merge([$today], $ids));
    foreach ($bk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $r['bst'] === null ? ($r['status'] === 'Scheduled' ? 'published' : 'chain')
            : ($r['bst'] === 'Scheduled' ? 'published' : ($r['bst'] === 'Returned' ? 'back' : 'chain'));
        $groups[(int)$r['user_id']]['date'][$r['phase'] === 'final' ? 'final' : 'proposal'] =
            ['md' => (string)$r['md'], 'dd' => (int)$r['dd'], 'slot' => (int)$r['slot_no'], 'st' => $st];
    }

    // ---- every date she has sent up the chain, and how it went
    $batches = [];
    if ($rooms) {
        $rPh = implode(',', array_fill(0, count($rooms), '?'));
        $b = $pdo->prepare("SELECT b.batch_id, b.section_id, b.phase, b.status, DATE_FORMAT(b.defense_date, '%b %e, %Y') AS md,
                                   DATEDIFF(b.defense_date, ?) AS dd,
                                   TIMESTAMPDIFF(HOUR, b.focal_at, b.coord_at) AS h1, TIMESTAMPDIFF(HOUR, b.coord_at, b.sec_at) AS h2,
                                   (b.returned_at IS NOT NULL) AS was_back,
                                   (SELECT COUNT(*) FROM defense_bookings x WHERE x.batch_id = b.batch_id) AS n
                            FROM defense_batches b WHERE b.section_id IN ($rPh) ORDER BY b.defense_date DESC, b.batch_id DESC");
        $b->execute(array_merge([$today], array_keys($rooms)));
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $batches[] = [
                'id' => (int)$r['batch_id'], 'room' => (int)$r['section_id'], 'phase' => (string)$r['phase'], 'st' => (string)$r['status'],
                'md' => (string)$r['md'], 'dd' => (int)$r['dd'],
                'h1' => $r['h1'] === null ? null : max(0, (int)$r['h1']), 'h2' => $r['h2'] === null ? null : max(0, (int)$r['h2']),
                'back' => (bool)$r['was_back'], 'n' => (int)$r['n'],
            ];
        }
    }

    return ['today' => $today, 'phases' => $phases, 'rooms' => (object)$rooms, 'groups' => array_values($groups), 'batches' => $batches];
}
