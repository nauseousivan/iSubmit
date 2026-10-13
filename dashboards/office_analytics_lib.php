<?php
// dashboards/office_analytics_lib.php — the office's numbers (2026-10-01).
//
// For the Director, Secretary, Coordinators and Statistician: where the research groups are in
// the workflow and what happened when. One dataset feeds the office Analytics (office_analytics.php)
// and the office Visualizer (office_visualize.php), filtered client-side (month / institution /
// course / phase), so the two always agree.
//
// SCOPE: everything goes through aa_scope_sql() — a Coordinator (or an institution's Statistician)
// sees her own institution only, and nothing at all while it is unset (fails closed).
// SCALE: activity arrives as BUCKETS (month x course x phase x kind), batches as buckets too; only
// the group list is per row, because "who" is the question a report has to answer. Reads only.

require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/focal_lib.php';
require_once __DIR__ . '/admin_analytics_lib.php';   // an_months(), an_role_label()

const OA_STATS_ITEMS = [30, 31, 32, 33, 34, 35, 36, 37];
const OA_PLAG_ITEMS  = [4, 40, 41, 42];

function oa_phase_no(?string $status): int
{
    return preg_match('/Phase\s+(\d+)/i', (string)$status, $m) ? (int)$m[1] : 0;
}

/** Which phase a checklist item belongs to. */
function oa_item_phase(int $id): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (fp_ready_items('proposal') as $i) $map[$i] = 'proposal';
        foreach (fp_ready_items('final') as $i) $map[$i] = 'final';
        foreach (OA_STATS_ITEMS as $i) $map[$i] = 'stats';
        foreach (OA_PLAG_ITEMS as $i) $map[$i] = 'plag';
    }
    return $map[$id] ?? 'other';
}

function an_office_dataset(PDO $pdo, string $role, int $uid): array
{
    [$sc, $scP] = aa_scope_sql($pdo, $role, $uid, 'u');
    [$scS, $scSP] = aa_scope_sql($pdo, $role, $uid, 's');
    $prop = fp_ready_items('proposal'); $final = fp_ready_items('final');
    $items = array_merge($prop, $final, OA_STATS_ITEMS, OA_PLAG_ITEMS);
    $iph = implode(',', array_fill(0, count($items), '?'));

    // ---- institutions and courses in scope (from student rows)
    $insts = []; $courses = [];
    $q = $pdo->prepare("SELECT DISTINCT u.department, u.program FROM users u
                        WHERE u.role = 'Student' AND u.department <> '' AND u.program IS NOT NULL AND u.program <> '' AND $sc
                        ORDER BY u.department, u.program");
    $q->execute($scP);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $insts[$r['department']] = ['key' => (string)$r['department'], 'tag' => aa_school_tag((string)$r['department'])];
        $courses[] = ['key' => $r['department'] . '::' . $r['program'], 'dept' => (string)$r['department'], 'program' => (string)$r['program']];
    }

    // ---- groups (leaders) in scope, with where each one stands
    $q = $pdo->prepare("SELECT u.user_id, u.research_group_name, u.username, u.department, u.program, u.section_id,
                               (SELECT COUNT(*) FROM users m WHERE m.leader_id = u.user_id) + 1 AS members,
                               DATE_FORMAT(u.created_at, '%Y-%m') AS ym
                        FROM users u WHERE " . FP_GROUP_PREDICATE . " AND $sc ORDER BY u.research_group_name");
    $q->execute($scP);
    $groups = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $groups[(int)$r['user_id']] = ['id' => (int)$r['user_id'], 'name' => (string)$r['research_group_name'], 'leader' => (string)$r['username'],
            'd' => (string)$r['department'], 'p' => (string)$r['program'], 'sec' => (int)$r['section_id'], 'room' => '', 'members' => (int)$r['members'],
            'ym' => (string)$r['ym'], 'pa' => 0, 'fa' => 0, 'pw' => 0, 'fw' => 0, 'stats' => 0, 'plag' => 0, 'pd' => '', 'fd' => '', 'last' => ''];
    }
    $sections = [];
    if ($groups) {
        $ids = array_keys($groups); $gph = implode(',', array_fill(0, count($ids), '?'));
        $secs = array_values(array_unique(array_filter(array_column($groups, 'sec'))));
        if ($secs) {
            $s = $pdo->prepare("SELECT section_id, program, year_level, label FROM sections WHERE section_id IN (" . implode(',', array_fill(0, count($secs), '?')) . ")");
            $s->execute($secs);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $sections[(int)$r['section_id']] = fp_short_section($r);
            foreach ($groups as &$g) $g['room'] = $sections[$g['sec']] ?? '';
            unset($g);
        }
        // the newest upload per item: approved (a) and waiting (w) per phase
        $u = $pdo->prepare("SELECT up.user_id, up.item_id, up.verification_status AS st FROM uploads up
                            JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads WHERE user_id IN ($gph) AND item_id IN ($iph) GROUP BY user_id, item_id) l
                              ON l.user_id = up.user_id AND l.item_id = up.item_id AND l.m = up.uploaded_at");
        $u->execute(array_merge($ids, $items));
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ph = oa_item_phase((int)$r['item_id']); $g = &$groups[(int)$r['user_id']];
            if ($ph === 'proposal') { if ($r['st'] === 'Approved') $g['pa']++; elseif ($r['st'] !== 'Revision Requested') $g['pw']++; }
            if ($ph === 'final')    { if ($r['st'] === 'Approved') $g['fa']++; elseif ($r['st'] !== 'Revision Requested') $g['fw']++; }
            unset($g);
        }
        $u = $pdo->prepare("SELECT user_id, DATE_FORMAT(MAX(uploaded_at), '%Y-%m') AS ym FROM uploads WHERE user_id IN ($gph) GROUP BY user_id");
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $ym) $groups[(int)$id]['last'] = (string)$ym;
        $u = $pdo->prepare("SELECT user_id, status FROM form_stat_treatment WHERE user_id IN ($gph) ORDER BY form_id ASC");
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $groups[(int)$r['user_id']]['stats'] = oa_phase_no($r['status']);
        $u = $pdo->prepare("SELECT user_id, status FROM plagiarism_checks WHERE user_id IN ($gph)");
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $groups[(int)$r['user_id']]['plag'] = oa_phase_no($r['status']);
        $u = $pdo->prepare("SELECT user_id, phase, DATE_FORMAT(defense_date, '%b %e') AS md FROM defense_bookings WHERE user_id IN ($gph) AND status = 'Scheduled'");
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $groups[(int)$r['user_id']][$r['phase'] === 'final' ? 'fd' : 'pd'] = (string)$r['md'];
    }
    $np = max(1, count($prop)); $nf = max(1, count($final));
    foreach ($groups as &$g) {
        $g['stage'] = $g['plag'] >= 5 ? 'done' : ($g['plag'] > 0 ? 'plag'
                    : (($g['fa'] > 0 || $g['fd'] !== '' || $g['stats'] >= 7) ? 'final'
                    : (($g['stats'] > 0 || $g['pd'] !== '') ? 'stats' : 'proposal')));
        $g['prog'] = round((min(1, $g['pa'] / $np) + min(1, $g['stats'] / 7) + min(1, $g['fa'] / $nf) + min(1, $g['plag'] / 5)) / 4, 3);
    }
    unset($g);

    // ---- activity buckets: month x course x phase x kind (sub = sent in, ok = approved, back = sent back, def = defense held)
    $act = [];
    $add = function ($ym, $d, $p, $ph, $k, $n) use (&$act) {
        if (!$ym || $ph === 'other') return;
        $key = "$ym|$d|$p|$ph|$k";
        if (!isset($act[$key])) $act[$key] = ['ym' => $ym, 'd' => $d, 'p' => $p, 'ph' => $ph, 'k' => $k, 'n' => 0];
        $act[$key]['n'] += (int)$n;
    };
    $q = $pdo->prepare("SELECT DATE_FORMAT(up.uploaded_at, '%Y-%m') AS ym, u.department AS d, u.program AS p, up.item_id, COUNT(*) AS n
                        FROM uploads up JOIN users u ON u.user_id = up.user_id
                        WHERE up.item_id IN ($iph) AND " . FP_GROUP_PREDICATE . " AND $sc GROUP BY ym, d, p, up.item_id");
    $q->execute(array_merge($items, $scP));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['ym'], $r['d'], $r['p'], oa_item_phase((int)$r['item_id']), 'sub', $r['n']);
    $q = $pdo->prepare("SELECT DATE_FORMAT(up.verified_at, '%Y-%m') AS ym, u.department AS d, u.program AS p, up.item_id, up.verification_status AS st, COUNT(*) AS n
                        FROM uploads up JOIN users u ON u.user_id = up.user_id
                        WHERE up.verified_at IS NOT NULL AND up.item_id IN ($iph) AND up.verification_status IN ('Approved','Revision Requested')
                          AND " . FP_GROUP_PREDICATE . " AND $sc GROUP BY ym, d, p, up.item_id, st");
    $q->execute(array_merge($items, $scP));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['ym'], $r['d'], $r['p'], oa_item_phase((int)$r['item_id']), $r['st'] === 'Approved' ? 'ok' : 'back', $r['n']);
    $q = $pdo->prepare("SELECT DATE_FORMAT(db.defense_date, '%Y-%m') AS ym, u.department AS d, u.program AS p, db.phase, COUNT(*) AS n
                        FROM defense_bookings db JOIN users u ON u.user_id = db.user_id
                        WHERE db.status = 'Scheduled' AND db.defense_date < ? AND $sc GROUP BY ym, d, p, db.phase");
    $q->execute(array_merge([df_today()], $scP));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['ym'], $r['d'], $r['p'], $r['phase'] === 'final' ? 'final' : 'proposal', 'def', $r['n']);

    // ---- the date chain: batches by month x course x phase x status, with how long the chain took
    $q = $pdo->prepare("SELECT DATE_FORMAT(b.defense_date, '%Y-%m') AS ym, s.department AS d, s.program AS p, b.phase AS ph, b.status AS st,
                               COUNT(*) AS n, SUM(b.returned_at IS NOT NULL) AS back,
                               SUM(TIMESTAMPDIFF(HOUR, b.focal_at, b.sec_at)) AS hrs, SUM(b.sec_at IS NOT NULL AND b.focal_at IS NOT NULL) AS timed
                        FROM defense_batches b JOIN sections s ON s.section_id = b.section_id
                        WHERE $scS GROUP BY ym, d, p, ph, st");
    $q->execute($scSP);
    $batches = array_map(fn($r) => ['ym' => (string)$r['ym'], 'd' => (string)$r['d'], 'p' => (string)$r['p'], 'ph' => (string)$r['ph'], 'st' => (string)$r['st'],
                                    'n' => (int)$r['n'], 'back' => (int)$r['back'], 'hrs' => (int)$r['hrs'], 'timed' => (int)$r['timed']], $q->fetchAll(PDO::FETCH_ASSOC));

    // ---- staff who work this scope
    $staff = [];
    $school = fp_school_scope($pdo, $role, $uid);
    $rows = $pdo->query("SELECT u.user_id, u.username, u.email, u.role, u.department, u.account_status,
                                (SELECT DATE_FORMAT(MAX(la.created_at), '%b %e, %Y') FROM login_attempts la WHERE la.success = 1 AND (la.user_id = u.user_id OR la.email = u.email)) AS last_in
                         FROM users u WHERE u.role IN ('Research Director','Research Coordinator','Statistician','Focal Person') AND u.account_status <> 'rejected'
                         ORDER BY FIELD(u.role, 'Research Director', 'Research Coordinator', 'Statistician', 'Focal Person'), u.username")->fetchAll(PDO::FETCH_ASSOC);
    $scope = [];
    foreach ($pdo->query("SELECT user_id, department, program FROM focal_scope")->fetchAll(PDO::FETCH_ASSOC) as $r) $scope[(int)$r['user_id']][] = $r['department'] . '::' . $r['program'];
    $courseKeys = array_column($courses, 'key');
    foreach ($rows as $r) {
        $id = (int)$r['user_id']; $mine = $scope[$id] ?? [];
        if ($r['role'] === 'Focal Person' && !array_intersect($mine, $courseKeys)) continue;
        if (in_array($r['role'], ['Research Coordinator', 'Statistician'], true) && $school !== null && $r['department'] !== $school && $r['department'] !== 'ALL') continue;
        if ($r['role'] === 'Research Director' && $school !== null) continue;
        $staff[] = ['id' => $id, 'name' => (string)$r['username'], 'role' => an_role_label((string)$r['role'], (string)$r['email']), 'dept' => (string)$r['department'],
                    'status' => (string)$r['account_status'], 'last' => (string)($r['last_in'] ?? ''), 'courses' => array_values(array_intersect($mine, $courseKeys))];
    }

    $months = an_months($pdo, ["SELECT DISTINCT DATE_FORMAT(uploaded_at, '%Y-%m') FROM uploads", "SELECT DISTINCT DATE_FORMAT(defense_date, '%Y-%m') FROM defense_bookings WHERE status = 'Scheduled'"]);
    return ['today' => df_today(), 'role' => $role, 'scoped' => $school !== null || ($sc !== '1'), 'months' => $months, 'insts' => array_values($insts),
            'courses' => $courses, 'groups' => array_values($groups), 'activity' => array_values($act), 'batches' => $batches, 'staff' => $staff,
            'np' => count($prop), 'nf' => count($final)];
}
