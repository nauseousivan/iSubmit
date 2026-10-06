<?php
// dashboards/admin_analytics_lib.php — the Admin's numbers (2026-10-01).
//
// The Admin looks after ACCOUNTS and the SYSTEM, not the papers: who has an account, where they
// are (institution, course, section), the issues people report (help desk) and who signs in. One
// dataset feeds both her Analytics (admin_analytics.php) and her Visualizer (admin_visualize.php),
// filtered client-side (month / institution / course / role), so the two always agree.
//
// SCALE: students are never shipped one by one. They arrive as BUCKETS (role x institution x
// course x section x status x month-joined) and sign-ins as one row per day x outcome, so the
// payload grows with courses and days, not with people. Staff (a few dozen) and help requests
// (the Admin reads each one anyway) are rows. Reads only.

require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/focal_lib.php';

/** The Secretary shares the Director's role (CLAUDE.md); say which one a row is. */
function an_role_label(string $role, string $email): string
{
    if ($role === 'Research Director') return stripos($email, 'secretary') !== false ? 'Secretary' : 'Director';
    return $role === 'Research Coordinator' ? 'Coordinator' : $role;
}

/**
 * Every month from the first one with data to this one, newest first ('YYYY-MM'), and the label
 * for each. CONTINUOUS: a quiet month is listed (and charted as zero) rather than skipped, or a
 * per-month chart would join July straight onto September and read as steady.
 */
function an_months(PDO $pdo, array $sources): array
{
    $now = date('Y-m', strtotime(df_today()));
    $first = $now;
    foreach ($sources as $sql) foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $m) if ($m && $m < $first) $first = $m;
    $out = [];
    for ($t = strtotime($now . '-01'); date('Y-m', $t) >= $first; $t = strtotime('-1 month', $t)) {
        $out[] = ['ym' => date('Y-m', $t), 'label' => date('F Y', $t)];
    }
    return $out;
}

function an_admin_dataset(PDO $pdo): array
{
    // ---- institutions and courses, from STUDENT rows (CLAUDE.md: never a bare DISTINCT department)
    $insts = [];
    foreach ($pdo->query("SELECT DISTINCT department FROM users WHERE role = 'Student' AND department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $insts[] = ['key' => (string)$d, 'tag' => aa_school_tag((string)$d)];
    }
    $courses = [];
    foreach ($pdo->query("SELECT DISTINCT department, program FROM users WHERE role = 'Student' AND department <> '' AND program IS NOT NULL AND program <> '' ORDER BY department, program")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $courses[] = ['key' => $r['department'] . '::' . $r['program'], 'dept' => (string)$r['department'], 'program' => (string)$r['program']];
    }

    // ---- students as buckets: institution x course x section x status x month joined
    //      g = how many of them lead a research group (a group = its leader row)
    $students = $pdo->query("SELECT u.department AS d, u.program AS p, COALESCE(u.section_id, 0) AS sec, u.account_status AS s,
                                    DATE_FORMAT(u.created_at, '%Y-%m') AS ym, COUNT(*) AS n,
                                    SUM(u.leader_id IS NULL AND u.research_group_name IS NOT NULL AND u.research_group_name <> '') AS g
                             FROM users u WHERE u.role = 'Student'
                             GROUP BY d, p, sec, s, ym")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($students as &$r) { $r['n'] = (int)$r['n']; $r['g'] = (int)$r['g']; $r['sec'] = (int)$r['sec']; $r['d'] = (string)$r['d']; $r['p'] = (string)$r['p']; }
    unset($r);

    // ---- sections that hold students (course pop-outs drill into these)
    $sections = [];
    foreach ($pdo->query("SELECT s.section_id, s.department, s.program, s.year_level, s.label FROM sections s
                          WHERE EXISTS (SELECT 1 FROM users u WHERE u.section_id = s.section_id AND u.role = 'Student')")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sections[(int)$s['section_id']] = ['short' => fp_short_section($s), 'year' => (string)$s['year_level'], 'label' => (string)$s['label']];
    }

    // ---- staff, one row each (a few dozen at most)
    $staff = [];
    foreach ($pdo->query("SELECT u.user_id, u.username, u.email, u.role, u.department, u.account_status, u.must_change_password,
                                 DATE_FORMAT(u.created_at, '%Y-%m') AS ym, DATE_FORMAT(u.created_at, '%b %e, %Y') AS joined,
                                 c.username AS made_by,
                                 (SELECT DATE_FORMAT(MAX(la.created_at), '%b %e, %Y') FROM login_attempts la WHERE la.success = 1 AND (la.user_id = u.user_id OR la.email = u.email)) AS last_in,
                                 (SELECT DATEDIFF(CURDATE(), MAX(la.created_at)) FROM login_attempts la WHERE la.success = 1 AND (la.user_id = u.user_id OR la.email = u.email)) AS idle_days
                          FROM users u LEFT JOIN users c ON c.user_id = u.created_by
                          WHERE u.role <> 'Student'
                          ORDER BY FIELD(u.role, 'Admin', 'Research Director', 'Research Coordinator', 'Statistician', 'Focal Person'), u.username")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $staff[(int)$r['user_id']] = [
            'id' => (int)$r['user_id'], 'name' => (string)$r['username'], 'role' => an_role_label((string)$r['role'], (string)$r['email']),
            'dept' => (string)$r['department'], 'status' => (string)$r['account_status'], 'ym' => (string)$r['ym'], 'joined' => (string)$r['joined'],
            'by' => (string)($r['made_by'] ?? ''), 'last' => (string)($r['last_in'] ?? ''), 'idle' => $r['idle_days'] === null ? null : (int)$r['idle_days'],
            'mustpw' => (int)$r['must_change_password'] === 1, 'courses' => [],
        ];
    }
    foreach ($pdo->query("SELECT user_id, department, program FROM focal_scope")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($staff[(int)$r['user_id']])) $staff[(int)$r['user_id']]['courses'][] = $r['department'] . '::' . $r['program'];
    }

    // ---- sign-ins: one row per day x outcome x who (student / staff / unknown address)
    $signins = $pdo->query("SELECT DATE_FORMAT(la.created_at, '%Y-%m-%d') AS d, la.success AS ok, la.reason AS why,
                                   CASE WHEN u.user_id IS NULL THEN 'unknown' WHEN u.role = 'Student' THEN 'student' ELSE 'staff' END AS who,
                                   COUNT(*) AS n
                            FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id OR (la.user_id IS NULL AND u.email = la.email)
                            GROUP BY d, ok, why, who ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($signins as &$r) { $r['ok'] = (int)$r['ok']; $r['n'] = (int)$r['n']; }
    unset($r);

    // ---- the help desk: each request (the Admin reads every one anyway)
    $help = [];
    foreach ($pdo->query("SELECT h.request_id, h.name, h.category, h.status, LEFT(h.message, 160) AS msg,
                                 DATE_FORMAT(h.created_at, '%Y-%m') AS ym, DATE_FORMAT(h.created_at, '%b %e, %Y') AS md,
                                 TIMESTAMPDIFF(HOUR, h.created_at, h.resolved_at) AS hrs, r.username AS by_name,
                                 u.department AS d, u.program AS p, u.role AS urole
                          FROM help_requests h
                          LEFT JOIN users u ON u.user_id = h.user_id
                          LEFT JOIN users r ON r.user_id = h.resolved_by
                          ORDER BY h.created_at DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $help[] = ['id' => (int)$r['request_id'], 'name' => (string)$r['name'], 'cat' => (string)$r['category'], 'st' => (string)$r['status'],
                   'msg' => (string)$r['msg'], 'ym' => (string)$r['ym'], 'md' => (string)$r['md'],
                   'hrs' => $r['hrs'] === null ? null : max(0, (int)$r['hrs']), 'by' => (string)($r['by_name'] ?? ''),
                   'd' => (string)($r['d'] ?? ''), 'p' => (string)($r['p'] ?? ''), 'who' => $r['urole'] === null ? 'unknown' : ($r['urole'] === 'Student' ? 'student' : 'staff')];
    }

    $months = an_months($pdo, [
        "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') FROM users",
        "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') FROM login_attempts",
        "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') FROM help_requests",
    ]);

    return ['today' => df_today(), 'months' => $months, 'insts' => $insts, 'courses' => $courses, 'students' => $students,
            'sections' => (object)$sections, 'staff' => array_values($staff), 'signins' => $signins, 'help' => $help,
            'counts' => rd_admin_counts($pdo)];
}

/** One staff member's sign-ins over the last 30 days, day by day, and their latest attempts. */
function an_staff_signins(PDO $pdo, int $id): ?array
{
    $u = $pdo->prepare("SELECT user_id, email FROM users WHERE user_id = ? AND role <> 'Student'");
    $u->execute([$id]);
    $u = $u->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $d = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS d, SUM(success = 1) AS ok, SUM(success = 0) AS bad
                        FROM login_attempts WHERE (user_id = ? OR email = ?) AND created_at >= CURDATE() - INTERVAL 29 DAY
                        GROUP BY d ORDER BY d");
    $d->execute([$id, $u['email']]);
    $days = [];
    foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $r) $days[$r['d']] = ['ok' => (int)$r['ok'], 'bad' => (int)$r['bad']];
    $l = $pdo->prepare("SELECT success, reason, DATE_FORMAT(created_at, '%b %e, %l:%i %p') AS at
                        FROM login_attempts WHERE (user_id = ? OR email = ?) ORDER BY created_at DESC LIMIT 6");
    $l->execute([$id, $u['email']]);
    return ['id' => $id, 'start' => (string)$pdo->query("SELECT DATE_FORMAT(CURDATE() - INTERVAL 29 DAY, '%Y-%m-%d')")->fetchColumn(),
            'days' => (object)$days, 'recent' => $l->fetchAll(PDO::FETCH_ASSOC)];
}
