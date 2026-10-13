<?php
// dashboards/focal_desk.php — the Focal Person's working screens (2026-10-01).
//
// These were the whole of focal.php until her portal moved onto the staff shell (focal.php is
// now that shell: rail, Home, a hub per phase, Workspace, Settings). The screens themselves are
// unchanged in behaviour; they open here as module windows, one per step card:
//
//   ?view=requirements&phase=  courses -> years -> sections -> groups -> documents (approve / revise)
//   ?view=schedule&phase=      courses -> years -> sections -> tick the ready groups, pick a date, endorse
//   ?view=sections             her rooms, and the groups that have none yet (the Unassigned tray)
//
// The phase is the window's, picked on the hub — there is no phase switch in here, or the window
// title ("Final requirements") would lie. The rail is the way back to Home, so the breadcrumb only
// starts once a course is open.
//
// She reviews HERE, not in admin_module_dynamic.php. That screen is the office's — pointing her
// at it would have put two roles on one surface and collided with the Secretary later.
// Approving a requirement is still single-stage: it writes 'Approved' and the student goes green
// at once. Only the defense DATE travels focal -> coordinator -> secretary.
//
// Sections with no groups are never listed: they exist so the registration dropdown always has
// rooms to offer, and an empty room holds no work for her.
//
// All writes go through focal_handler.php.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'focal_lib.php';
require_once 'group_roster_lib.php';   // ws_group_rosters() / ws_names_markup() — "View names"

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Focal Person') {
    header("Location: ../auth/login.php");
    exit();
}

$me    = (int)$_SESSION['user_id'];
$role  = 'Focal Person';
$phase = ($_GET['phase'] ?? 'proposal') === 'final' ? 'final' : 'proposal';
$viewWant = (string)($_GET['view'] ?? 'requirements');
$view     = in_array($viewWant, ['requirements', 'schedule', 'archive', 'sections'], true) ? $viewWant : 'requirements';
$curKey = trim((string)($_GET['course'] ?? ''));
$yr     = trim((string)($_GET['year'] ?? ''));
$sid    = isset($_GET['section']) ? (int)$_GET['section'] : 0;
$gid    = isset($_GET['group'])   ? (int)$_GET['group']   : 0;
if ($view === 'sections') { $curKey = ''; $yr = ''; $sid = 0; $gid = 0; }

$flash      = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$csrf = htmlspecialchars($_SESSION['csrf_token']);

$scope   = aa_focal_scope($pdo, $me);
$courses = fp_sections_by_course($pdo, $role, $me);

function fp_h(?string $v): string { return htmlspecialchars((string)$v); }
/** Build a link that keeps the current tab and phase. */
function fp_url(array $over = []): string
{
    global $view, $phase, $curKey, $yr, $sid;
    $q = array_merge(['view' => $view, 'phase' => $phase], $over);
    if (!isset($over['course'])  && $curKey !== '') $q['course']  = $curKey;
    if (!isset($over['year'])    && $yr !== '')  $q['year']    = $yr;
    if (!isset($over['section']) && $sid)           $q['section'] = $sid;
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

// ------------------------------------------------------------------ resolve where we are
$course = ($curKey !== '' && isset($courses[$curKey])) ? $courses[$curKey] : null;
if (!$course) { $curKey = ''; $yr = ''; $sid = 0; $gid = 0; }
if ($course && $yr !== '') {
    $known = array_column($course['sections'], 'year_level');
    if (!in_array($yr, $known, true)) { $yr = ''; $sid = 0; $gid = 0; }
}

$section = null;
if ($sid) {
    foreach ($course['sections'] as $s) {
        if ((int)$s['section_id'] === $sid) $section = $s;
    }
    if (!$section) { $sid = 0; $gid = 0; }
    else { $yr = (string)$section['year_level']; }
}

// REQUIREMENTS IS ONE SCREEN (2026-10-01, Claude Design concept): course, year and room sit in a
// tree beside the room's groups, so there is no course -> year -> room walk. The tree and the
// "Across your courses" totals read the same fp_board() rows as the hub and the badges. With no
// room chosen, open the one with the most files waiting on her (else the first) — within the
// course/year she picked, if she picked one.
$fpBoard = [];
if ($view === 'requirements' || $view === 'schedule') {
    $fpBoard = fp_board($pdo, $role, $me, $phase);
    if (!$section && $fpBoard) {
        $pool = array_values(array_filter($fpBoard, fn($r) => ($curKey === '' || $r['course_key'] === $curKey)
                                                             && ($yr === '' || (string)$r['year_level'] === $yr)));
        if (!$pool) $pool = $fpBoard;
        // Requirements: most files waiting. Defense dates: a room whose date is hers to set (ready
        // groups, or a date sent back), the most groups ready first.
        $prio = fn($r) => $view === 'schedule' ? [(int)($r['col'] === 1), $r['place']] : [$r['pending'], 0];
        usort($pool, fn($a, $b) => [...$prio($b), $a['course_key'], $a['short']] <=> [...$prio($a), $b['course_key'], $b['short']]);
        $pick    = $pool[0];
        $curKey  = $pick['course_key'];
        $course  = $courses[$curKey];
        $sid     = (int)$pick['section_id'];
        $yr      = (string)$pick['year_level'];
        foreach ($course['sections'] as $s) if ((int)$s['section_id'] === $sid) $section = $s;
    }
}

$groups = $section ? fp_groups_in_section($pdo, $sid) : [];
$leaderIds = array_column($groups, 'user_id');
// Who is actually in each group (leader first, with their School ID Numbers) and when the
// group last sent anything in. Both fetched once for the whole section — a card each would
// be two queries per group.
$rosters  = $leaderIds ? ws_group_rosters($pdo, $leaderIds) : [];
$lastSent = $leaderIds ? fp_last_submitted($pdo, $leaderIds, $phase) : [];

// Availability only gets built once a section is open, but the page script reads it on
// every view — so it always exists.
$fpCal     = [];
$fpSoonest = null;

$group = null;
if ($gid) {
    foreach ($groups as $g) {
        if ((int)$g['user_id'] === $gid) $group = $g;
    }
    if (!$group) $gid = 0;
}

// ------------------------------------------------------------------ per-view data
if ($group) {
    $docs   = fp_group_documents($pdo, $gid, $phase);
    $gReady = fp_readiness($pdo, [$gid], $phase)[$gid];      // the header's pips
} elseif ($section) {
    $readiness = fp_readiness($pdo, $leaderIds, $phase);
    $pending   = fp_pending_count($pdo, $leaderIds, $phase);
    $pendingBatch = fp_pending_batch($pdo, $sid, $phase);       // the one that blocks a new round
    // Holding a booking for this phase in ANY round — endorse_batch refuses these, so they are
    // never offered. The newest batch alone left an earlier round's groups tickable.
    $booked    = fp_booked($pdo, $leaderIds, $phase);

    // Availability for the whole booking window as date => cell, the same shape the student
    // calendar used. A real month grid beats a flat list of tiles: a month with 31 open days
    // ran to six rows of chips and read as a wall.
    // Built for any open room, even one with a date in the chain: she can still LOOK at the
    // month (the send bar says why she cannot send). Starts a month back so the six-week grid's
    // leading days are real days, not blanks.
    if ($view === 'schedule') {
        // A section belongs to exactly one institution, and that institution has its own pool
        // of 10 — MCNP filling a day must not close it to ISAP.
        $secSchool = (string)$section['department'];

        $cur = strtotime(df_today() . ' first day of last month');
        $endMonth = strtotime(date('Y-m-01', strtotime(df_window_end())));
        while ($cur <= $endMonth) {
            foreach (df_month_cells($pdo, $phase, (int)date('Y', $cur), (int)date('n', $cur), 0, $secSchool) as $cell) {
                $dayGroups = [];
                foreach ($cell['booking']['groups'] ?? [] as $g) {
                    $dayGroups[] = [
                        'uid'     => (int)$g['user_id'],
                        'name'    => (string)$g['research_group_name'],
                        'program' => (string)$g['program'],
                        'phase'   => (string)$g['phase'],
                        'slot'    => (int)$g['slot_no'],
                    ];
                }
                $fpCal[$cell['date']] = [
                    'state'    => $cell['state'],
                    'reason'   => $cell['reason'],
                    'is_today' => $cell['is_today'],
                    'used'     => (int)$cell['slots_used'],
                    'left'     => (int)$cell['slots_left'],
                    'groups'   => $dayGroups,
                ];
                if ($fpSoonest === null && $cell['state'] === 'open') {
                    $fpSoonest = $cell['date'];
                }
            }
            $cur = strtotime('+1 month', $cur);
        }
    }

    // THE TICKET STRIP: every date this room already has in motion for the phase — each live
    // batch, soonest first, with its groups and slots — plus any group booked outside a batch
    // (the retired student-picks flow), so nobody holding a date is missing from it. Day offsets
    // come from MySQL against df_today(): PHP runs Europe/Berlin.
    $tickets = [];
    if ($view === 'schedule') {
        $lph = implode(',', array_fill(0, count(FP_LIVE_BATCH_STATUSES), '?'));
        $tk = $pdo->prepare("SELECT b.batch_id, b.status, b.defense_date, b.remarks, b.focal_at, b.coord_at, b.sec_at,
                                    DATEDIFF(b.defense_date, ?) AS dd
                             FROM defense_batches b
                             WHERE b.section_id = ? AND b.phase = ? AND b.status IN ($lph)
                               AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
                             ORDER BY b.defense_date ASC, b.batch_id ASC");
        $tk->execute(array_merge([df_today(), $sid, $phase], FP_LIVE_BATCH_STATUSES));
        $inTicket = [];
        foreach ($tk->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['groups'] = fp_batch_groups($pdo, (int)$t['batch_id']);
            foreach ($t['groups'] as $r) $inTicket[(int)$r['user_id']] = true;
            $tickets[] = $t;
        }
        $loose = array_keys(array_diff_key($booked, $inTicket));
        if ($loose) {
            $ph = implode(',', array_fill(0, count($loose), '?'));
            $lb = $pdo->prepare("SELECT b.user_id, b.defense_date, b.slot_no, b.status, DATEDIFF(b.defense_date, ?) AS dd, u.research_group_name
                                 FROM defense_bookings b JOIN users u ON u.user_id = b.user_id
                                 WHERE b.user_id IN ($ph) AND b.phase = ? AND b.status IN ('Applied','Date Selected','Scheduled')
                                 ORDER BY b.defense_date ASC");
            $lb->execute(array_merge([df_today()], $loose, [$phase]));
            foreach ($lb->fetchAll(PDO::FETCH_ASSOC) as $r) $tickets[] = $r + ['loose' => true];
        }
    }
}

/**
 * The left column of Requirements and Defense dates: the way back to the hub, the course ->
 * year -> room tree, and the totals across her courses — from the fp_board() rows, so a badge
 * here can never disagree with the hub or the rail. Each room's badge is her move IN THIS VIEW:
 * files to read (Requirements), or groups ready for a date / a date sent back (Defense dates).
 */
function fp_side(array $rows, string $view, string $phase, string $curKey, string $yr, int $sid): void
{
    $tree = [];
    foreach ($rows as $r) {
        $t = $tree[$r['course_key']] ?? ['program' => $r['program'], 'department' => $r['department'], 'done' => 0, 'total' => 0, 'years' => []];
        $t['done']  += $r['done'];
        $t['total'] += $r['total'];
        $t['years'][(string)$r['year_level']][] = $r;
        $tree[$r['course_key']] = $t;
    }
    ksort($tree);
    foreach ($tree as &$t) {
        ksort($t['years']);
        foreach ($t['years'] as &$list) usort($list, fn($a, $b) => strcmp((string)$a['label'], (string)$b['label']));
        unset($list);
    }
    unset($t);
    $sum = fn(string $f) => array_sum(array_column($rows, $f));
    ?>
    <aside class="rq-side">
        <?php fp_hub_pill($view, $phase); ?>
        <nav class="rq-tree" aria-label="Your courses">
            <h2><?= $view === 'schedule' ? 'Defense dates' : 'Your courses' ?></h2>
            <?php if (!$tree): ?><p class="rq-note">None of your courses has a room with groups in it yet.</p><?php endif; ?>
            <?php foreach ($tree as $ck => $t):
                $tp = $t['total'] > 0 ? (int)round($t['done'] / $t['total'] * 100) : 0; ?>
                <details class="rq-course" <?= ($ck === $curKey || count($tree) <= 2) ? 'open' : '' ?>>
                    <summary>
                        <i data-lucide="chevron-down" class="rq-chev"></i>
                        <span><b><?= fp_h($t['program']) ?></b><small><?= fp_h(aa_school_tag($t['department'])) ?>, <?= $tp ?>% approved</small></span>
                    </summary>
                    <?php foreach ($t['years'] as $yl => $list): ?>
                        <details class="rq-year" <?= ($ck === $curKey && (string)$yl === $yr) || count($t['years']) === 1 ? 'open' : '' ?>>
                            <summary>
                                <i data-lucide="chevron-down" class="rq-chev"></i>
                                <span><?= $yl === '' ? 'Year not set' : fp_h($yl) ?></span>
                                <small><?= count($list) ?> section<?= count($list) === 1 ? '' : 's' ?></small>
                            </summary>
                            <?php foreach ($list as $r): $on = (int)$r['section_id'] === $sid; ?>
                                <a class="rq-sec <?= $on ? 'on' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?> title="<?= fp_h($r['do']) ?>"
                                   href="?<?= fp_h(http_build_query(['view' => $view, 'phase' => $phase, 'course' => $ck, 'section' => (int)$r['section_id']])) ?>">
                                    <span class="rq-sec-l"><?= fp_h($r['label']) ?></span>
                                    <span class="rq-sec-t">Section <?= fp_h($r['label']) ?></span>
                                    <?php if ($view === 'requirements'): ?>
                                        <?php if ($r['pending']): ?><span class="rq-sec-n hot" title="Files waiting on you"><?= (int)$r['pending'] ?></span>
                                        <?php else: ?><span class="rq-sec-n" title="Groups"><?= (int)$r['groups'] ?></span><?php endif; ?>
                                    <?php elseif ($r['tone'] === 'back'): ?><span class="rq-sec-tag back">Sent back</span>
                                    <?php elseif ($r['col'] === 2 || $r['col'] === 3): ?><span class="rq-sec-tag">With office</span>
                                    <?php else: ?><span class="rq-sec-tag <?= $r['place'] ? 'hot' : '' ?>"><?= (int)$r['place'] ?> ready</span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <p class="rq-note">Years and sections with no groups are hidden.</p>
        </nav>

        <?php if ($view === 'requirements'): ?>
        <div class="rq-acc">
            <h3>Across your courses</h3>
                <div><span>Files waiting on you</span><b class="<?= $sum('pending') ? 'hot' : '' ?>"><?= $sum('pending') ?></b></div>
                <div><span>Groups complete</span><b class="ok"><?= $sum('ready') ?> of <?= $sum('groups') ?></b></div>
                <div><span>Requirements approved</span><b><?= $sum('done') ?> of <?= $sum('total') ?></b></div>
        </div>
        <?php else: /* the chain, once, where she can always see it */ ?>
        <div class="rq-acc rq-how">
            <h3>How a date gets approved</h3>
            <ol>
                <li><span>1</span><p><b>You</b> pick groups and a day</p></li>
                <li><span>2</span><p><b>Coordinator</b> confirms</p></li>
                <li><span>3</span><p><b>Secretary</b> logs and publishes</p></li>
            </ol>
        </div>
        <?php endif; ?>
    </aside>
    <?php
}

/**
 * The way out of a window: back to the hub it was opened from (Proposal / Final / Workspace).
 * Inside the shell it closes the window onto that hub (shShow); opened on its own, it is a link.
 */
function fp_hub_pill(string $view, string $phase): void
{
    $ws    = in_array($view, ['sections', 'archive'], true);   // the Workspace screens
    $hub   = $ws ? 'workspace' : $phase;
    $label = $ws ? 'Workspace' : ucfirst($phase);
    ?><a class="fp-backpill" href="focal.php" onclick="return fpToHub('hub-<?= $hub ?>')"><i data-lucide="arrow-left"></i> <?= $label ?></a><?php
}


// THE ARCHIVE (Workspace, 2026-10-01): finished business, per phase, in two tabs —
//   Requirements  groups whose every requirement for the phase is approved, with who approved each
//   Schedule      published dates whose day has passed, with every group, slot and signature
// across her rooms or for one room (?section=). Both only GROW, so like the office's decision log
// the page renders ONE MONTH: a month index is always the page and only $arYm loads in detail.
// (The room's own ticket strip on Defense dates still shows every date it has had.)
$arTab    = ($_GET['tab'] ?? 'requirements') === 'schedule' ? 'schedule' : 'requirements';
$arMonths = []; $arYm = ''; $arRows = []; $arRooms = [];
if ($view === 'archive') {
    [$scS, $scSP] = aa_scope_sql($pdo, $role, $me, 's');
    [$scU, $scUP] = aa_scope_sql($pdo, $role, $me, 'u');
    $today = df_today();
    $want  = (string)($_GET['ym'] ?? '');

    if ($arTab === 'schedule') {
        $room   = $section ? ' AND s.section_id = ' . (int)$sid : '';
        $bWhere = "b.status = 'Scheduled' AND b.phase = ? AND b.defense_date < ? AND $scS $room
                   AND EXISTS (SELECT 1 FROM defense_bookings x WHERE x.batch_id = b.batch_id)";
        // Leader rows only, as everywhere else a group is counted (a legacy booking or two sit on a member).
        $lWhere = "db.batch_id IS NULL AND db.status = 'Scheduled' AND db.phase = ? AND db.defense_date < ? AND " . FP_GROUP_PREDICATE . " AND $scU $room";
        $bP = array_merge([$phase, $today], $scSP);
        $lP = array_merge([$phase, $today], $scUP);
        $idx = $pdo->prepare("SELECT ym, SUM(n) AS n FROM (
                                  SELECT DATE_FORMAT(b.defense_date, '%Y-%m') AS ym, COUNT(*) AS n
                                  FROM defense_batches b JOIN sections s ON s.section_id = b.section_id
                                  WHERE $bWhere GROUP BY ym
                                  UNION ALL
                                  SELECT DATE_FORMAT(db.defense_date, '%Y-%m') AS ym, COUNT(*) AS n
                                  FROM defense_bookings db JOIN users u ON u.user_id = db.user_id
                                  LEFT JOIN sections s ON s.section_id = u.section_id
                                  WHERE $lWhere GROUP BY ym
                              ) t GROUP BY ym ORDER BY ym DESC");
        $idx->execute(array_merge($bP, $lP));
        $arMonths = $idx->fetchAll(PDO::FETCH_KEY_PAIR);
        $arYm = isset($arMonths[$want]) ? $want : (string)(array_key_first($arMonths) ?? '');
        if ($arYm !== '') {
            $hb = $pdo->prepare("SELECT b.batch_id, b.defense_date, s.section_id, s.label, s.program, s.department, s.year_level,
                                        COALESCE(f.username, IF(b.focal_by IS NULL, NULL, 'Removed account')) AS focal_name,
                                        COALESCE(c.username, IF(b.coord_by IS NULL, NULL, 'Removed account')) AS coord_name,
                                        COALESCE(x.username, IF(b.sec_by   IS NULL, NULL, 'Removed account')) AS sec_name,
                                        DATE_FORMAT(b.focal_at, '%b %e') AS focal_md, DATE_FORMAT(b.coord_at, '%b %e') AS coord_md,
                                        DATE_FORMAT(b.sec_at, '%b %e') AS sec_md, DATEDIFF(?, b.defense_date) AS ago
                                 FROM defense_batches b
                                 JOIN sections s   ON s.section_id = b.section_id
                                 LEFT JOIN users f ON f.user_id = b.focal_by
                                 LEFT JOIN users c ON c.user_id = b.coord_by
                                 LEFT JOIN users x ON x.user_id = b.sec_by
                                 WHERE $bWhere AND DATE_FORMAT(b.defense_date, '%Y-%m') = ?
                                 ORDER BY b.defense_date DESC, b.batch_id DESC");
            $hb->execute(array_merge([$today], $bP, [$arYm]));
            foreach ($hb->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['groups'] = fp_batch_groups($pdo, (int)$r['batch_id']);
                $arRows[] = $r;
            }
            $hl = $pdo->prepare("SELECT db.defense_date, db.slot_no, u.username, u.research_group_name,
                                        s.section_id, s.label, s.program, s.department, s.year_level, DATEDIFF(?, db.defense_date) AS ago
                                 FROM defense_bookings db JOIN users u ON u.user_id = db.user_id
                                 LEFT JOIN sections s ON s.section_id = u.section_id
                                 WHERE $lWhere AND DATE_FORMAT(db.defense_date, '%Y-%m') = ?");
            $hl->execute(array_merge([$today], $lP, [$arYm]));
            foreach ($hl->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['loose']  = true;
                $r['groups'] = [['slot_no' => $r['slot_no'], 'research_group_name' => $r['research_group_name'], 'username' => $r['username']]];
                $arRows[] = $r;
            }
            usort($arRows, fn($a, $b) => strcmp((string)$b['defense_date'], (string)$a['defense_date']));
        }
    } else {
        // CLEARED: every requirement's NEWEST upload is Approved (the rule fp_readiness() uses).
        // Cleared on = the last of those approvals; rows older than verified_at fall back to the upload.
        $items = fp_ready_items($phase);
        $iph   = implode(',', array_fill(0, count($items), '?'));
        $room  = $section ? ' AND u.section_id = ' . (int)$sid : '';
        $cq = $pdo->prepare("SELECT u.user_id, u.research_group_name, u.username,
                                    s.section_id, s.label, s.program, s.department, s.year_level,
                                    COUNT(*) AS n_ok,
                                    MAX(COALESCE(up.verified_at, up.uploaded_at)) AS cleared_at,
                                    DATE_FORMAT(MAX(COALESCE(up.verified_at, up.uploaded_at)), '%Y-%m') AS ym,
                                    DATE_FORMAT(MAX(COALESCE(up.verified_at, up.uploaded_at)), '%b %e') AS cleared_md
                             FROM users u
                             JOIN uploads up ON up.user_id = u.user_id AND up.item_id IN ($iph)
                             JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads WHERE item_id IN ($iph) GROUP BY user_id, item_id) l
                                  ON l.user_id = up.user_id AND l.item_id = up.item_id AND l.m = up.uploaded_at
                             LEFT JOIN sections s ON s.section_id = u.section_id
                             WHERE " . FP_GROUP_PREDICATE . " AND $scU $room AND up.verification_status = 'Approved'
                             GROUP BY u.user_id, u.research_group_name, u.username, s.section_id, s.label, s.program, s.department, s.year_level
                             HAVING n_ok >= ?
                             ORDER BY cleared_at DESC");
        $cq->execute(array_merge($items, $items, $scUP, [count($items)]));
        $cleared = $cq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cleared as $r) $arMonths[$r['ym']] = ($arMonths[$r['ym']] ?? 0) + 1;
        krsort($arMonths);
        $arYm = isset($arMonths[$want]) ? $want : (string)(array_key_first($arMonths) ?? '');
        foreach ($cleared as $r) {
            if ($r['ym'] !== $arYm) continue;
            $r['docs'] = fp_group_documents($pdo, (int)$r['user_id'], $phase);   // who approved each one
            $arRows[] = $r;
        }
    }
    foreach ($courses as $ck => $c) foreach ($c['sections'] as $sx) {
        if ((int)$sx['group_count'] > 0) $arRooms[] = $sx + ['course_key' => $ck];
    }
}
// The Sections screen: rooms per course, and the groups that have none yet.
$unassigned = $view === 'sections' ? fp_unassigned_groups($pdo, $role, $me) : [];
$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];   // create_section accepts these
$deskTitle  = ['requirements' => ucfirst($phase) . ' requirements', 'schedule' => ucfirst($phase) . ' defense dates',
               'archive' => 'Archive', 'sections' => 'Sections'][$view];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= fp_h($deskTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* The bulk bar and the Download chip are display:flex / inline-flex, which beats the
           `hidden` attribute — without this they show while empty. */
        [hidden] { display: none !important; }

        .fp-alert-error { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 58%, var(--ink)); border-color: color-mix(in srgb, var(--danger) 25%, transparent); }

        /* A module window now (the staff shell frames it): no header bar of its own, one gutter.
           portal.css gives body.admin-module 28px of padding; zeroed so it cannot stack with .fp-wrap. */
        body.admin-module { padding: 0; }
        .fp-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin: 0 0 16px; }
        .fp-head h1 { font-family: "Outfit", var(--font-ui); font-size: 28px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 0; line-height: 1.15; }
        .fp-head p { font-size: 13px; color: var(--muted); margin: 4px 0 0; }
        .fp-head .fp-school { align-self: center; }

        /* ================= REQUIREMENTS (2026-10-01, Claude Design concept) =================
           Screen 1 (.rq-*): course -> year -> room as a tree beside the room's groups.
           Screen 2 (.gr-*): one group — the list, the file, and one action bar under the file. */
        /* Every desk screen is white panels on a lavender page, as in the concept; dark already has the step.
           The TOKEN, not the background: portal.css paints body with var(--canvas) !important. */
        body.admin-module:not(.theme-dark) { --canvas: color-mix(in srgb, var(--accent) 6%, #ffffff); }
        .rq { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 22px; align-items: start; }
        .rq-side { position: sticky; top: 12px; display: flex; flex-direction: column; gap: 12px; max-height: calc(100vh - 24px); overflow-y: auto; }
        .rq-side > .fp-backpill { align-self: flex-start; }
        .rq-tree, .rq-acc { background: var(--surface); border-radius: 22px; padding: 20px 16px 16px; }
        .rq-tree h2 { font-family: "Outfit", var(--font-ui); font-size: 22px; font-weight: 600; color: var(--ink); margin: 0 4px 10px; }
        .rq-tree summary { list-style: none; cursor: pointer; display: flex; align-items: flex-start; gap: 8px; padding: 8px 6px; border-radius: 12px; }
        .rq-tree summary::-webkit-details-marker { display: none; }
        .rq-tree summary:hover { background: var(--surface-2); }
        .rq-chev { width: 17px; height: 17px; flex-shrink: 0; margin-top: 2px; color: var(--muted); transition: transform var(--dur-fast); transform: rotate(-90deg); }
        details[open] > summary .rq-chev { transform: none; }
        .rq-course > summary span { display: flex; flex-direction: column; min-width: 0; }
        .rq-course > summary b { font-size: 15.5px; font-weight: 750; color: var(--ink); line-height: 1.3; }
        .rq-course > summary small { font-size: 12.5px; color: var(--muted); }
        .rq-year { margin-left: 16px; }
        .rq-year > summary { align-items: center; font-size: 14.5px; font-weight: 650; color: var(--ink); }
        .rq-year > summary span { flex: 1; }
        .rq-year > summary small { font-size: 12.5px; font-weight: 500; color: var(--muted); }
        .rq-sec { display: flex; align-items: center; gap: 11px; margin: 4px 0 4px 20px; padding: 9px 10px; border-radius: 14px;
                  text-decoration: none; color: var(--ink); font-size: 14.5px; font-weight: 700; }
        .rq-sec:hover { background: var(--surface-2); }
        .rq-sec.on { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); }
        .rq-sec-l { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; background: var(--surface-3); font-size: 13px; font-weight: 800; }
        .rq-sec.on .rq-sec-l { background: var(--surface); }
        .rq-sec-t { flex: 1; }
        .rq-sec-n { font-size: 12.5px; font-weight: 800; color: var(--muted); }
        .rq-sec-n.hot { min-width: 24px; height: 24px; padding: 0 7px; border-radius: 999px; display: grid; place-items: center; box-sizing: border-box;
                        background: var(--accent); color: var(--accent-ink); }
        .rq-sec-n.back { min-width: 24px; height: 24px; border-radius: 999px; display: grid; place-items: center; background: var(--danger); color: #fff; }
        .rq-sec-i { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; color: var(--muted); }
        .rq-sec-i.ok { background: var(--success); color: #fff; }
        .rq-sec-i i, .rq-sec-i svg { width: 13px; height: 13px; }
        .rq-lede { font-size: 14px; color: var(--muted); margin: 6px 0 0; }
        .rq-note { font-size: 12.5px; color: var(--muted); margin: 10px 4px 0; line-height: 1.5; }
        .rq-acc { background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
        .rq-acc h3 { font-size: 15px; font-weight: 800; color: var(--ink); margin: 0 4px 8px; }
        .rq-acc div { display: flex; justify-content: space-between; gap: 8px; padding: 6px 4px; font-size: 14px; color: var(--muted); }
        .rq-acc b { color: var(--ink); font-weight: 800; }
        .rq-acc b.ok { color: color-mix(in srgb, var(--success) 75%, var(--ink)); }
        .rq-acc b.hot { color: var(--accent); }

        .rq-main { min-width: 0; }
        .rq-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
        .rq-crumb { font-size: 13.5px; font-weight: 600; color: var(--muted); }
        .rq-titles h1, .gr-title h1 { font-family: "Outfit", var(--font-ui); font-size: 36px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 0; line-height: 1.15; }
        .rq-search { display: flex; align-items: center; gap: 9px; width: 320px; max-width: 100%; height: 46px; padding: 0 14px; border-radius: 999px;
                     background: var(--surface); border: 1.5px solid transparent; box-sizing: border-box; }
        .rq-search:focus-within { border-color: color-mix(in srgb, var(--accent) 50%, transparent); }
        .rq-search i, .rq-search svg { width: 16px; height: 16px; color: var(--muted); flex-shrink: 0; }
        .rq-search input { all: unset; flex: 1; min-width: 0; font-size: 14.5px; color: var(--ink); }
        .rq-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
        .rq-tile { all: unset; box-sizing: border-box; cursor: pointer; display: flex; flex-direction: column; gap: 3px; padding: 16px 20px; border-radius: 20px;
                   background: var(--fd-card); border: 2px solid transparent; transition: background var(--dur-fast), border-color var(--dur-fast); }
        .rq-tile:hover { background: var(--fd-card-hover); }
        .rq-tile.on { background: var(--surface); border-color: var(--accent); }
        .rq-tile:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .rq-tile b { font-family: "Outfit", var(--font-ui); font-size: 32px; font-weight: 600; line-height: 1.1; color: var(--ink); }
        .rq-tile span { font-size: 14px; font-weight: 600; color: var(--ink); }
        .rq-tile.t-you b  { color: var(--accent); }
        .rq-tile.t-them b { color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .rq-tile.t-done b { color: color-mix(in srgb, var(--success) 75%, var(--ink)); }
        .rq-remind { display: flex; align-items: center; gap: 14px; padding: 12px 12px 12px 16px; border-radius: 20px; background: var(--surface); margin-bottom: 14px; }
        .rq-remind p { flex: 1; margin: 0; font-size: 14.5px; color: var(--muted); }
        .rq-remind p b { color: var(--ink); }
        .rq-remind-ico { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0;
                         background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .rq-remind-ico i, .rq-remind-ico svg { width: 16px; height: 16px; }
        .rq-btn { all: unset; box-sizing: border-box; cursor: pointer; padding: 11px 22px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                  font-size: 14px; font-weight: 750; white-space: nowrap; }
        .rq-btn:hover { filter: brightness(1.08); }
        .rq-list { background: var(--surface); border-radius: 22px; padding: 8px; }
        .rq-row { display: grid; grid-template-columns: 44px minmax(0, 1fr) 220px 140px 18px; align-items: center; gap: 18px; padding: 13px 16px;
                  border-radius: 16px; text-decoration: none; color: var(--ink); }
        .rq-row + .rq-row { margin-top: 2px; }
        .rq-row:hover { background: var(--surface-2); }
        .rq-row.s-you { background: color-mix(in srgb, var(--accent) 7%, var(--surface)); }
        .rq-row.s-you:hover { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); }
        .rq-row.s-done { background: color-mix(in srgb, var(--success) 9%, var(--surface)); }
        .rq-av { width: 44px; height: 44px; border-radius: 13px; display: grid; place-items: center; font-family: "Outfit", var(--font-ui);
                 font-size: 17px; font-weight: 700; background: var(--a); color: var(--b); flex-shrink: 0; }
        .av0 { --a: #fdecc8; --b: #92400e; } .av1 { --a: #ede9fe; --b: #5b21b6; } .av2 { --a: #dbeafe; --b: #1e40af; }
        .av3 { --a: #ffe4e6; --b: #9f1239; } .av4 { --a: #dcfce7; --b: #166534; } .av5 { --a: #ffedd5; --b: #9a3412; }
        body.theme-dark .rq-av { background: color-mix(in srgb, var(--b) 45%, var(--surface)); color: var(--a); }
        .rq-id { display: flex; flex-direction: column; min-width: 0; }
        .rq-id b { font-size: 15.5px; font-weight: 750; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rq-id small { font-size: 13px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rq-prog { display: flex; flex-direction: column; gap: 5px; }
        .rq-prog small { font-size: 13px; font-weight: 650; color: var(--ink); }
        .rq-pips { display: flex; gap: 3px; }
        .rq-pips i { flex: 1; height: 6px; border-radius: 999px; background: color-mix(in srgb, var(--ink) 10%, transparent); }
        .rq-pips i.on { background: var(--accent); }
        .rq-pips i.pend { background: #f59e0b; }   /* sent, waiting on her — the concept's amber */
        .rq-row.s-done .rq-pips i.on { background: var(--success); }
        .rq-when { font-size: 13.5px; font-weight: 650; text-align: right; color: var(--ink); white-space: nowrap; }
        .rq-when em { font-style: normal; font-size: 12.5px; font-weight: 750; padding: 5px 12px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); }
        .rq-quiet { color: var(--muted); font-weight: 600; }
        .rq-go { width: 18px; height: 18px; color: var(--muted); }
        .rq-empty { text-align: center; color: var(--muted); font-size: 13px; padding: 26px 10px; margin: 0; }
        .rq-blank { background: var(--surface); border-radius: 22px; padding: 44px 20px; text-align: center; }
        .rq-blank h3 { font-family: "Outfit", var(--font-ui); font-size: 20px; color: var(--ink); margin: 10px 0 4px; }
        .rq-blank p { font-size: 13px; color: var(--muted); margin: 0; }

        .gr-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; }
        .gr-av { width: 52px; height: 52px; font-size: 21px; border-radius: 15px; }
        .gr-title { min-width: 0; flex: 1; }
        .gr-title h1 { font-size: 32px; }
        .gr-meta { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-left: auto; }
        .gr-names { position: relative; }
        .gr-names .ws-names-btn { font-size: 13px; padding: 9px 15px; background: var(--surface); }
        .gr-names .ws-names-panel { position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; width: 300px; background: var(--surface); box-shadow: var(--shadow-3); }
        .gr-prog { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
        .gr-prog .rq-pips { width: 220px; }
        .gr-prog small { font-size: 13px; color: var(--muted); }
        .gr-prog small b { color: var(--ink); }

        .gr-split { display: grid; grid-template-columns: minmax(340px, 460px) minmax(0, 1fr); gap: 18px; align-items: start; }
        .gr-list { position: sticky; top: 12px; height: calc(100vh - 150px); min-height: 440px; overflow-y: auto; box-sizing: border-box;
                   background: var(--surface); border-radius: 22px; padding: 16px 14px; }
        .gr-sec + .gr-sec { margin-top: 14px; }
        .gr-sec-h { display: flex; align-items: center; gap: 10px; padding: 4px 6px 10px; font-family: "Outfit", var(--font-ui); font-size: 19px; font-weight: 600; color: var(--ink); cursor: default; list-style: none; }
        summary.gr-sec-h { cursor: pointer; }
        summary.gr-sec-h::-webkit-details-marker { display: none; }
        .gr-sec-h em { font-style: normal; font-weight: 500; color: var(--muted); }
        .gr-sec-h .rq-chev { margin-left: auto; }
        .gr-sec-h input, .gr-item input { width: 19px; height: 19px; margin: 0; accent-color: var(--accent); flex-shrink: 0; cursor: pointer; }
        .gr-item { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 16px; border: 1.5px solid transparent; cursor: pointer; outline: none; }
        .gr-item + .gr-item { margin-top: 4px; }
        .gr-item:hover { background: var(--surface-2); }
        .gr-item:focus-visible { border-color: color-mix(in srgb, var(--accent) 50%, transparent); }
        .gr-item.on { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 5%, var(--surface)); }
        .gr-item input { margin-top: 2px; }
        .gr-item-body { flex: 1; min-width: 0; }
        .gr-item-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .gr-item-top b { font-size: 15.5px; font-weight: 750; color: var(--ink); line-height: 1.35; }
        .gr-file { display: block; font-size: 12.5px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .gr-by { display: block; font-size: 12.5px; color: var(--muted); margin-top: 5px; }
        .gr-by b { color: var(--ink); }
        .gr-remark { font-size: 13px; line-height: 1.5; color: var(--ink); margin: 8px 0 0; padding: 8px 10px; border-radius: 10px;
                     background: color-mix(in srgb, var(--danger) 8%, var(--surface)); }
        .gr-clears { margin-top: 10px; padding-left: 2px; }
        .gr-clears > span { display: block; font-size: 12.5px; font-weight: 750; color: var(--ink); margin-bottom: 4px; }
        .gr-clears div { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--ink); padding: 2px 0; }
        .gr-clears div i { width: 6px; height: 6px; border-radius: 50%; background: color-mix(in srgb, var(--accent) 45%, var(--surface)); flex-shrink: 0; }
        .gr-clears em { margin-left: auto; font-style: normal; font-size: 12px; color: var(--muted); white-space: nowrap; }
        .gr-clear { display: flex; align-items: center; gap: 8px; padding: 10px 8px 4px; font-size: 13px; font-weight: 650; color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .gr-clear i, .gr-clear svg { width: 18px; height: 18px; }
        .gr-miss { display: flex; align-items: center; gap: 12px; padding: 10px 12px; }
        .gr-miss > div { flex: 1; min-width: 0; }
        .gr-miss b { display: block; font-size: 14.5px; font-weight: 700; color: var(--ink); }
        .gr-miss small { font-size: 12.5px; color: var(--muted); }
        .gr-dash { width: 16px; height: 16px; border-radius: 50%; border: 1.5px dashed var(--muted); flex-shrink: 0; }
        .gr-remind { all: unset; cursor: pointer; padding: 8px 16px; border-radius: 999px; border: 1px solid var(--line); font-size: 13px; font-weight: 700; color: var(--ink); background: var(--surface); }
        .gr-remind:hover { border-color: var(--accent); color: var(--accent); }

        /* Fits the window with the action bar in view: the head above it is ~110px. */
        .gr-prev { position: sticky; top: 12px; height: calc(100vh - 150px); min-height: 440px; display: flex; flex-direction: column;
                   background: var(--surface); border-radius: 22px; overflow: hidden; }
        .gr-prev-bar { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .gr-prev-t { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .gr-prev-t b { font-family: "Outfit", var(--font-ui); font-size: 18px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .gr-prev-t small { font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .gr-ibtn { width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-2); color: var(--ink); flex-shrink: 0; }
        .gr-pbtn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 15px; border-radius: 999px; background: var(--surface-2); color: var(--ink);
                   font-size: 13.5px; font-weight: 700; text-decoration: none; flex-shrink: 0; }
        .gr-ibtn:hover, .gr-pbtn:hover { background: var(--surface-3); }
        .gr-ibtn i, .gr-ibtn svg, .gr-pbtn i, .gr-pbtn svg { width: 15px; height: 15px; }
        .gr-prev-body { flex: 1; position: relative; background: color-mix(in srgb, var(--accent) 7%, var(--surface)); }
        .gr-prev-body iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        .gr-prev-empty { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 8px; padding: 24px; text-align: center; }
        .gr-prev-empty i, .gr-prev-empty svg { width: 30px; height: 30px; color: var(--muted); opacity: .6; }
        .gr-prev-empty p { font-size: 13px; color: var(--muted); margin: 0; }
        .gr-act { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 16px; border-top: 1px solid var(--line); }
        .gr-seg { display: inline-flex; gap: 2px; padding: 3px; border-radius: 999px; background: var(--surface-2); flex-shrink: 0; }
        .gr-seg button { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; padding: 10px 17px; border-radius: 999px;
                         font-size: 14px; font-weight: 700; color: var(--muted); }
        .gr-seg button i, .gr-seg button svg { width: 14px; height: 14px; }
        .gr-seg button.on.ok { background: #15803d; color: #fff; }
        .gr-seg button.on.back { background: var(--danger); color: #fff; }
        .gr-seg button:disabled { opacity: .4; cursor: not-allowed; }
        .gr-note { flex: 1; min-width: 160px; box-sizing: border-box; margin: 0; padding: 13px 18px; border-radius: 999px; border: 1.5px solid transparent;
                   background: var(--surface-2); color: var(--ink); font: inherit; font-size: 14.5px; outline: none; }
        .gr-note:focus { border-color: var(--accent); background: var(--surface); }
        .gr-note:disabled { opacity: .6; }
        .gr-note.shake { border-color: var(--danger); animation: grShake .35s; }
        @keyframes grShake { 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
        .gr-go { all: unset; box-sizing: border-box; cursor: pointer; padding: 13px 26px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                 font-size: 15px; font-weight: 800; white-space: nowrap; }
        .gr-go:hover { filter: brightness(1.08); }
        .gr-go.back { background: var(--danger); color: #fff; }
        .gr-go:disabled { background: var(--surface-3); color: var(--muted); cursor: default; filter: none; }

        @media (max-width: 1000px) {
            .rq { grid-template-columns: 1fr; }
            .rq-side { position: static; }
            .gr-split { grid-template-columns: 1fr; }
            .gr-prev { position: static; height: 75vh; }
            .gr-list { position: static; height: auto; min-height: 0; }
            .rq-side { max-height: none; }
            .gr-meta { margin-left: 0; width: 100%; justify-content: space-between; }
        }
        @media (max-width: 700px) {
            .rq-tiles { grid-template-columns: 1fr 1fr; }
            .rq-row { grid-template-columns: 44px minmax(0, 1fr) auto; row-gap: 6px; gap: 12px; padding: 12px; }
            .rq-prog { grid-column: 2 / 4; grid-row: 2; }
            .rq-go { display: none; }
            .rq-search { width: 100%; }
            .rq-remind { flex-wrap: wrap; }
        }

        /* ================= DEFENSE DATES (2026-10-01, Claude Design concept) =================
           Tickets for the dates already in motion, two steps side by side, one dark send bar. */
        .rq-sec-tag { font-size: 11.5px; font-weight: 750; padding: 3px 9px; border-radius: 999px; white-space: nowrap;
                      background: var(--surface); color: var(--muted); }
        .rq-sec.on .rq-sec-tag { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .rq-sec-tag.hot  { background: var(--accent); color: var(--accent-ink); }
        .rq-sec-tag.back { background: var(--danger); color: #fff; }
        .rq-how ol { list-style: none; margin: 0; padding: 0 4px; display: flex; flex-direction: column; gap: 9px; }
        .rq-how li { display: flex; align-items: center; gap: 10px; }
        .rq-how li span { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0;
                          background: var(--surface); color: var(--ink); font-size: 12px; font-weight: 800; }
        .rq-how li:first-child span { background: var(--accent); color: var(--accent-ink); }
        .rq-how li p { margin: 0; font-size: 14px; color: var(--muted); }
        .rq-how li b { color: var(--ink); }

        .dt-strip { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .dt-card { flex: 1 1 340px; min-width: 0; display: flex; align-items: center; gap: 10px 14px; padding: 14px 16px; border-radius: 22px; background: var(--surface); flex-wrap: wrap; }
        .dt-card.dt-loose { flex: 1 1 300px; max-width: 520px; }
        .dt-card.is-back { box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--danger) 45%, transparent); }
        .dt-tile { width: 56px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; padding: 6px 0 7px; border-radius: 14px;
                   background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--ink); line-height: 1.05; }
        .dt-tile small { font-size: 10.5px; font-weight: 750; color: var(--muted); }
        .dt-tile b { font-family: "Outfit", var(--font-ui); font-size: 24px; font-weight: 700; }
        .dt-tile.ok { background: color-mix(in srgb, var(--success) 16%, var(--surface)); }
        .dt-tile.ok b { color: color-mix(in srgb, var(--success) 75%, var(--ink)); }
        .dt-tile.back { background: color-mix(in srgb, var(--danger) 13%, var(--surface)); }
        .dt-body { flex: 1 1 160px; min-width: 0; }
        .dt-body b { display: block; font-size: 15.5px; font-weight: 750; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dt-body small { font-size: 13px; color: var(--muted); }
        .dt-body small.ok { color: color-mix(in srgb, var(--success) 70%, var(--ink)); font-weight: 650; }
        .dt-why { margin: 6px 0 0; font-size: 13px; color: var(--ink); line-height: 1.45; }
        .dt-why b { display: inline; font-size: 13px; color: var(--danger); }
        .dt-chain { display: flex; align-items: center; gap: 6px; flex-shrink: 0; margin-left: auto; }   /* right-hand, wrapped or not */
        .dt-sig { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .dt-sig em { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-size: 11px; font-weight: 800;
                     background: var(--surface-3); color: var(--muted); }
        .dt-sig.on { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .dt-sig.on em { background: #15803d; color: #fff; }
        .dt-sig em i, .dt-sig em svg { width: 13px; height: 13px; }
        .dt-rail { width: 18px; height: 2px; border-radius: 2px; background: var(--surface-3); }
        .dt-rail.on { background: #15803d; }
        .dt-card form { display: contents; }
        .dt-withdraw { all: unset; cursor: pointer; padding: 7px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 700;
                       color: var(--danger); background: color-mix(in srgb, var(--danger) 8%, var(--surface)); }
        .dt-withdraw:hover { background: color-mix(in srgb, var(--danger) 15%, var(--surface)); }

        /* ---- the Archive (Workspace) ---- */
        .ar-switch { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .ar-seg { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface); }
        .ar-seg a { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 999px; text-decoration: none;
                    font-size: 14px; font-weight: 700; color: var(--muted); }
        .ar-seg a:hover { color: var(--ink); }
        .ar-seg a.on { background: var(--accent); color: var(--accent-ink); }
        .ar-seg i, .ar-seg svg { width: 16px; height: 16px; }
        .ar-count { font-size: 13.5px; color: var(--muted); margin: -4px 2px 14px; }
        .ar-reqs { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
        .ar-reqs li { display: flex; align-items: flex-start; gap: 10px; padding: 7px 10px; border-radius: 12px; background: var(--fd-card); }
        .ar-reqs li.is-kid { margin-left: 22px; background: transparent; padding-top: 3px; padding-bottom: 3px; }
        .ar-tick { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; margin-top: 1px; background: #15803d; color: #fff; }
        .ar-reqs li.is-kid .ar-tick { width: 16px; height: 16px; background: color-mix(in srgb, var(--success) 25%, var(--surface)); color: #15803d; }
        .ar-tick i, .ar-tick svg { width: 12px; height: 12px; }
        .ar-req { min-width: 0; display: flex; flex-direction: column; }
        .ar-req b { font-size: 13.5px; font-weight: 700; color: var(--ink); }
        .ar-req small { font-size: 12px; color: var(--muted); }
        .hd-top .rq-av { width: 52px; height: 52px; font-size: 20px; border-radius: 15px; }        .dt-tile.held { background: var(--surface-3); }
        .dt-tile.held b { color: var(--muted); }

        /* The Already held page: room chips, the month index, and one card per date. */
        .hd-rooms, .hd-months { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .hd-months { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; margin-bottom: 16px; }
        .hd-room { padding: 8px 15px; border-radius: 999px; background: var(--surface); color: var(--ink); text-decoration: none; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .hd-room:hover { background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
        .hd-room.on { background: var(--accent); color: var(--accent-ink); }
        .hd-month { flex-shrink: 0; display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 14px; background: var(--fd-card);
                    color: var(--ink); text-decoration: none; border: 2px solid transparent; }
        .hd-month b { font-size: 14px; font-weight: 750; }
        .hd-month em { font-style: normal; font-size: 12px; font-weight: 800; color: var(--muted); }
        .hd-month:hover { background: var(--fd-card-hover); }
        .hd-month.on { background: var(--surface); border-color: var(--accent); }
        .hd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 14px; }
        .hd-card { background: var(--surface); border-radius: 22px; padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .hd-top { display: flex; align-items: center; gap: 14px; }
        .hd-id { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .hd-id b { font-family: "Outfit", var(--font-ui); font-size: 18px; font-weight: 600; color: var(--ink); }
        .hd-id small { font-size: 13px; color: var(--muted); }
        .hd-groups { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
        .hd-groups li { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 12px; background: var(--fd-card); }
        .hd-slot { flex-shrink: 0; min-width: 54px; font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 800; color: var(--muted); }
        .hd-g { min-width: 0; display: flex; flex-direction: column; }
        .hd-g b { font-size: 14px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hd-g small { font-size: 12px; color: var(--muted); }
        .hd-sign { display: flex; gap: 10px 18px; flex-wrap: wrap; padding-top: 12px; border-top: 1px solid var(--line); }
        .hd-sig { display: inline-flex; align-items: center; gap: 8px; }
        .hd-sig em { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; background: #15803d; color: #fff; flex-shrink: 0; }
        .hd-sig em i, .hd-sig em svg { width: 13px; height: 13px; }
        .hd-sig > span { display: flex; flex-direction: column; line-height: 1.25; }
        .hd-sig b { font-size: 13px; font-weight: 700; color: var(--ink); }
        .hd-sig small { font-size: 11.5px; color: var(--muted); }
        .hd-legacy { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--muted); }
        .hd-legacy i, .hd-legacy svg { width: 15px; height: 15px; }
        @media (max-width: 640px) { .hd-grid { grid-template-columns: 1fr; } }        .dt-held-chip { margin-left: auto; display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 750;
                        background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 72%, var(--ink)); }
        .dt-held-chip i, .dt-held-chip svg { width: 13px; height: 13px; }

        .dt-steps { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
        .dt-step { background: var(--surface); border-radius: 22px; padding: 20px 22px 22px; min-width: 0; }
        .dt-step-h { display: flex; align-items: center; gap: 11px; margin-bottom: 14px; }
        .dt-step-h .fp-step-n { width: 28px; height: 28px; font-size: 13px; }
        .dt-step-h h2 { font-family: "Outfit", var(--font-ui); font-size: 22px; font-weight: 600; color: var(--ink); margin: 0; flex: 1; }
        .dt-links { font-size: 13px; color: var(--muted); }
        .dt-links button { all: unset; cursor: pointer; font-weight: 700; color: var(--accent); }
        .dt-links button:hover { text-decoration: underline; }
        .dt-nav { display: flex; gap: 6px; }
        .dt-nav button { all: unset; cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center;
                         background: var(--fd-card); color: var(--ink); }
        .dt-nav button:hover:not(:disabled) { background: var(--fd-card-hover); }
        .dt-nav button:disabled { opacity: .35; cursor: default; }
        .dt-nav i, .dt-nav svg { width: 16px; height: 16px; }
        .dt-note { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 18px;
                   background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
        .dt-note svg { flex-shrink: 0; }
        .dt-note p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .dt-note b { color: var(--ink); }
        .dt-sub { font-size: 13px; font-weight: 800; color: var(--ink); margin: 18px 2px 6px; }
        .dt-list { display: flex; flex-direction: column; gap: 4px; max-height: 470px; overflow-y: auto; }
        .dt-row { display: grid; grid-template-columns: minmax(0, 1fr) 150px 70px; align-items: center; gap: 14px; padding: 10px 12px; border-radius: 14px; }
        .dt-row.is-ready { grid-template-columns: 20px minmax(0, 1fr) 150px 70px; cursor: pointer; background: color-mix(in srgb, var(--success) 7%, var(--surface)); }
        .dt-row.is-ready:hover { background: color-mix(in srgb, var(--success) 12%, var(--surface)); }
        .dt-row input { width: 18px; height: 18px; margin: 0; accent-color: var(--accent); }
        .dt-id { display: flex; flex-direction: column; min-width: 0; }
        .dt-id b { font-size: 14.5px; font-weight: 750; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dt-id small { font-size: 12.5px; color: var(--muted); }
        .dt-pips i.on { background: var(--accent); }
        .dt-row.is-ready .dt-pips i.on { background: var(--success); }
        .dt-state { font-size: 12.5px; font-weight: 750; text-align: right; white-space: nowrap; }
        .dt-state.ok { color: color-mix(in srgb, var(--success) 75%, var(--ink)); }
        .dt-state.togo { color: color-mix(in srgb, #b45309 85%, var(--ink)); }
        .dt-rest { font-size: 13px; color: var(--muted); margin: 8px 12px 0; }

        .dt-cal-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 0 2px 10px; }
        .dt-cal-top b { font-family: "Outfit", var(--font-ui); font-size: 18px; font-weight: 600; color: var(--ink); }
        .dt-cal-top small { font-size: 12.5px; color: var(--muted); }
        .dt-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .dt-dow { text-align: center; font-size: 12px; font-weight: 700; color: var(--muted); padding-bottom: 2px; }
        .dt-d { all: unset; box-sizing: border-box; cursor: default; height: 54px; border-radius: 14px; display: flex; flex-direction: column;
                align-items: center; justify-content: center; gap: 1px; background: transparent; color: var(--muted); position: relative; }
        .dt-d b { font-size: 15px; font-weight: 700; }
        .dt-d small { font-size: 10px; font-weight: 700; line-height: 1; max-width: 92%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dt-d.is-off { background: color-mix(in srgb, var(--ink) 4%, transparent); opacity: .7; }
        .dt-d.is-other { opacity: .45; }
        .dt-d.is-open { cursor: pointer; background: var(--fd-card); color: var(--ink); transition: background var(--dur-fast), transform var(--dur-fast); }
        .dt-d.is-open:hover { background: var(--fd-card-hover); transform: translateY(-1px); }
        .dt-d.is-tight { box-shadow: inset 0 0 0 1.5px #f59e0b; }
        .dt-d.is-tight small { color: #b45309; }
        .dt-d.is-shut { background: color-mix(in srgb, var(--danger) 9%, var(--surface)); color: color-mix(in srgb, var(--danger) 70%, var(--ink));
                        box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--danger) 35%, transparent); }
        .dt-d.is-mine small { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .dt-d.is-today::after { content: ''; position: absolute; bottom: 5px; width: 4px; height: 4px; border-radius: 50%; background: var(--accent); }
        .dt-d.is-pick { background: var(--accent); color: var(--accent-ink); box-shadow: 0 6px 16px -6px color-mix(in srgb, var(--accent) 70%, transparent); }
        .dt-d.is-pick small { color: var(--accent-ink); opacity: .9; }
        .dt-d:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .dt-legend { display: flex; flex-wrap: wrap; gap: 14px; margin: 14px 2px 0; font-size: 12px; color: var(--muted); }
        .dt-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .dt-legend i { width: 11px; height: 11px; border-radius: 3px; }
        .dt-legend .lg-pick { background: var(--accent); }
        .dt-legend .lg-tight { box-shadow: inset 0 0 0 1.5px #f59e0b; }
        .dt-legend .lg-shut { box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--danger) 60%, transparent); }
        .dt-legend .lg-off { background: color-mix(in srgb, var(--ink) 8%, transparent); }
        .dt-soon { all: unset; cursor: pointer; margin-top: 12px; padding: 9px 16px; border-radius: 999px; font-size: 13px; font-weight: 750;
                   background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .dt-soon:hover { background: color-mix(in srgb, var(--accent) 20%, var(--surface)); }
        .dt-dayinfo { margin-top: 12px; padding: 11px 14px; border-radius: 14px; background: var(--fd-card); font-size: 13px; line-height: 1.6; color: var(--muted); }
        .dt-dayinfo b { color: var(--ink); }
        .dt-dayinfo em { font-style: normal; font-weight: 700; color: color-mix(in srgb, var(--success) 70%, var(--ink)); }

        .dt-commit { position: sticky; bottom: 14px; z-index: 25; margin-top: 16px; display: flex; align-items: center; gap: 16px;
                     padding: 12px 12px 12px 14px; border-radius: 24px; color: #fff;
                     background: linear-gradient(120deg, #2e1065, #4c1d95); box-shadow: 0 18px 36px -16px rgba(46, 16, 101, .6); }
        .dt-commit .dt-tile { background: #fff; color: #2e1065; }
        .dt-commit .dt-tile small { color: #6b5b95; }
        .dt-commit .dt-tile.is-empty b { color: #c4b5fd; }
        .dt-commit-t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .dt-commit-t b { font-family: "Outfit", var(--font-ui); font-size: 17px; font-weight: 600; }
        .dt-commit-t span { font-size: 13px; color: rgba(255, 255, 255, .75); }
        .dt-go { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; padding: 14px 24px; border-radius: 999px;
                 background: #fff; color: #2e1065; font-size: 15px; font-weight: 800; white-space: nowrap; }
        .dt-go i, .dt-go svg { width: 17px; height: 17px; }
        .dt-go:hover:not(:disabled) { filter: brightness(.96); }
        .dt-go:disabled { background: rgba(255, 255, 255, .14); color: rgba(255, 255, 255, .5); cursor: default; }
        @media (max-width: 1100px) { .dt-steps { grid-template-columns: 1fr;  } }
        @media (max-width: 640px) {
            .dt-row, .dt-row.is-ready { grid-template-columns: 20px minmax(0, 1fr) auto; }
            .dt-row .dt-pips { display: none; }
            .dt-row:not(.is-ready) { grid-template-columns: minmax(0, 1fr) auto; }
            .dt-d { height: 46px; }
            .dt-commit { flex-wrap: wrap; }
            .dt-go { width: 100%; justify-content: center; }
        }

        /* ---- Sections (rooms + the Unassigned tray) ---- */
        .fp-select, .fp-input { width: auto; margin: 0; padding: 8px 12px; border-radius: var(--r-md); border: 1px solid var(--line);
                                background: var(--surface); color: var(--ink); font: inherit; font-size: 12.5px; }
        .fp-input { min-width: 0; flex: 1; max-width: 220px; }
        .fp-step-n.fp-step-warn { background: var(--warning); color: #fff; }
        .fp-tray { border-color: color-mix(in srgb, var(--warning) 40%, var(--line)); background: color-mix(in srgb, var(--warning) 5%, var(--surface)); }
        .fp-tray-row { flex-wrap: wrap; }
        .fp-tray-row .btn { padding: 8px 15px; font-size: 12.5px; }
        .fp-rooms { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 14px; align-items: start; }
        @media (max-width: 640px) { .fp-rooms { grid-template-columns: 1fr;  } }
        .fp-room-card { margin: 0; }
        .fp-room-top { display: flex; align-items: center; gap: 9px; margin-bottom: 12px; }
        .fp-room-top h2 { flex: 1; min-width: 0; margin: 0; }
        .fp-room-year { display: flex; align-items: baseline; gap: 12px; padding: 9px 0; border-top: 1px solid var(--line); }
        .fp-room-year > b { width: 72px; flex-shrink: 0; font-size: 12px; font-weight: 750; color: var(--muted); }
        .fp-room-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .fp-room { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 11px; border-radius: var(--r-pill);
                   background: var(--surface-3); font-size: 13px; font-weight: 750; color: var(--ink); }
        .fp-room:not(:has(form)) { padding-right: 11px; }
        .fp-room small { font-size: 10.5px; font-weight: 650; color: var(--muted); }
        .fp-room.has { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); }
        .fp-room form { display: contents; }
        .fp-room-x { width: 20px; height: 20px; border-radius: 50%; border: 0; padding: 0; display: grid; place-items: center; cursor: pointer;
                     background: transparent; color: var(--muted); opacity: .35; transition: opacity var(--dur-fast); }
        .fp-room:hover .fp-room-x, .fp-room-x:focus-visible { opacity: 1; }
        .fp-room-x:hover { background: color-mix(in srgb, var(--danger) 15%, var(--surface)); color: var(--danger); }
        .fp-room-x i, .fp-room-x svg { width: 12px; height: 12px; }
        .fp-room-add { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line); }
        .fp-room-add .btn { padding: 8px 14px; font-size: 12.5px; }

        /* Back is a PILL that names where it goes — a bare arrow was easy to miss and said nothing. */
        .fp-nav { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .fp-backpill { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 16px 0 12px; border-radius: 999px; flex-shrink: 0;
                       background: var(--surface); color: var(--ink); font-size: 13.5px; font-weight: 700; text-decoration: none;
                       box-shadow: 0 1px 2px rgba(0, 0, 0, .05); transition: background var(--dur-fast), transform var(--dur-fast); }
        .fp-backpill:hover { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); transform: translateX(-2px); }
        .fp-backpill i, .fp-backpill svg { width: 16px; height: 16px; }
        .fp-nav .fp-crumb { margin: 0; }

        .fp-wrap { max-width: 1680px; margin: 0 auto; padding: 24px 22px 80px; }
        @media (min-width: 1100px) { .fp-wrap { padding: 28px 36px 80px;  } }
        @media (max-width: 640px) { .fp-wrap { padding: 16px 14px 90px;  } }

        /* ---- breadcrumb ---- */
        .fp-crumb { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 13.5px; margin-bottom: 16px; }
        .fp-crumb a { color: var(--accent); text-decoration: none; font-weight: 650; }
        .fp-crumb span { color: var(--muted); }
        .fp-crumb b { color: var(--ink); font-weight: 750; }

        /* Soft-tile tokens (the shell's --sh-card): rows, filter tiles and chips use them. */
        body { --fd-card: var(--surface-3); --fd-card-hover: color-mix(in srgb, var(--accent) 10%, var(--surface-3));
               --fd-hot-a: #6d28d9; --fd-hot-b: #4c1d95; }
        body.theme-dark { --fd-card: var(--surface-2); --fd-card-hover: var(--surface-3); --fd-hot-a: #7c3aed; --fd-hot-b: #5b21b6; }
        .fd-chip { font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: var(--surface); color: var(--muted); white-space: nowrap; }
        .fd-chip.ok   { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .fd-chip.back { background: color-mix(in srgb, var(--danger) 14%, var(--surface));  color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .fd-chip.wait { background: color-mix(in srgb, var(--accent) 15%, var(--surface));  color: color-mix(in srgb, var(--accent) 70%, var(--ink)); }
        .fd-chip.amb  { background: color-mix(in srgb, #f59e0b 22%, var(--surface)); color: color-mix(in srgb, #b45309 80%, var(--ink)); }
        .fd-chip-lg { font-size: 12.5px; padding: 6px 13px; align-self: center; }

        .fp-school { font-size: 9.5px; font-weight: 800; letter-spacing: .06em; padding: 3px 7px; border-radius: var(--r-pill); background: var(--surface-3); color: var(--muted); }

        .fp-pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: var(--r-pill); background: var(--surface-3); color: var(--muted); white-space: nowrap; }
        .fp-pill.ok   { background: color-mix(in srgb, var(--success) 15%, var(--surface)); color: color-mix(in srgb, var(--success) 62%, var(--ink)); }
        .fp-pill.need { background: color-mix(in srgb, var(--danger) 13%, var(--surface));  color: color-mix(in srgb, var(--danger) 62%, var(--ink)); }
        .fp-pill.wait { background: color-mix(in srgb, var(--accent) 15%, var(--surface));  color: color-mix(in srgb, var(--accent) 66%, var(--ink)); }
        .fp-pill.done { background: color-mix(in srgb, var(--success) 22%, var(--surface)); color: color-mix(in srgb, var(--success) 66%, var(--ink)); }

        /* ---- rows (groups / documents) ---- */
        .fp-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 20px; margin-bottom: 15px; }
        .fp-panel h2 { font-size: 15px; font-weight: 750; margin: 0 0 3px; color: var(--ink); }
        .fp-hint { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; line-height: 1.55; }

        .fp-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid transparent; border-radius: 16px; margin-bottom: 8px; background: var(--fd-card); }
        .fp-row.dim { opacity: .6; }
        .fp-row input[type=checkbox] { width: auto; margin: 0; accent-color: var(--accent); flex-shrink: 0; }
        .fp-row-name { font-size: 13.5px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .fp-row-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
        .fp-row-right { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        /* Numbered step heads: the Sections tray and the two Defense dates steps. */
        .fp-step-h { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
        .fp-step-n { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0;
                     background: var(--accent); color: var(--accent-ink); font-size: 11px; font-weight: 800; }
        .fp-step-h h2 { font-size: 15px; font-weight: 760; color: var(--ink); margin: 0; }
        .fp-step-h .fp-pill { margin-left: auto; }

        /* ---- roster pill + panel. Same classes and markup as the office screens
               (group_roster_lib.php), so "View names" reads identically wherever it appears. */
        .ws-names-btn { display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px;
                        border: 1px solid var(--line); border-radius: var(--r-pill); background: var(--surface);
                        font-family: inherit; font-size: 10px; font-weight: 700; color: var(--muted); cursor: pointer;
                        transition: color var(--dur-fast), border-color var(--dur-fast); }
        .ws-names-btn:hover, .ws-names-btn.is-open { color: var(--ink); border-color: var(--muted); }
        .ws-names-btn i, .ws-names-btn svg { width: 11px; height: 11px; }
        .ws-names-n { font-weight: 800; opacity: .7; }
        .ws-names-panel { padding: 10px 12px; margin-top: 9px; border: 1px solid var(--line);
                          border-radius: var(--r-sm); background: var(--surface-2); flex-direction: column; gap: 8px; }
        .ws-name-row { display: flex; align-items: center; gap: 9px; }
        .ws-name-row img { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; background: var(--surface); object-fit: cover; }
        .ws-name-text { display: flex; flex-direction: column; min-width: 0; }
        .ws-name-text strong { font-size: 12px; font-weight: 700; color: var(--ink); }
        .ws-name-text span { font-size: 10px; color: var(--muted); word-break: break-word; }
        .ws-names-solo { font-size: 10.5px; font-style: italic; color: var(--muted); }

    </style>
</head>

<body class="admin-module fp-v-<?= $view ?>">
<?php /* No announcement banner here: the shell around this window already shows it. */ ?>

<div class="fp-wrap">

    <?php if ($flash): ?>
        <div class="alert font-bold <?= $flash_type === 'error' ? 'fp-alert-error' : '' ?>">
            <i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>" style="vertical-align:middle;margin-right:8px;"></i>
            <?= fp_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php /* Breadcrumb — once a course is open. Above that the rail is the way out, and a lone
             crumb repeating the window title said nothing. */ ?>
    <?php if ($view === 'sections'): ?>
        <nav class="fp-nav" aria-label="Where you are"><?php fp_hub_pill($view, $phase); ?></nav>
    <?php endif; ?>


<?php if ($view === 'sections'): /* ============================ SECTIONS — rooms + the tray ==== */ ?>

    <?php
    // Rooms per course, by year. create_section / delete_section / assign_section were already in
    // focal_handler.php; this is the screen for them (the old portal had lost it).
    $fpSecCount = 0;
    foreach ($courses as $c) $fpSecCount += count($c['sections']);
    ?>
    <div class="fp-head">
        <div>
            <h1>Sections</h1>
            <p><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?> &middot; <?= $fpSecCount ?> room<?= $fpSecCount === 1 ? '' : 's' ?>.
               Students pick their room when they register; a group without one cannot be put on a date.</p>
        </div>
    </div>

    <?php if ($unassigned): ?>
        <?php /* The backfill tray: groups from before sections existed, transferees, late sign-ups.
                 It leads the screen because it is the only thing here that blocks a defense. */ ?>
        <div class="fp-panel fp-tray">
            <div class="fp-step-h">
                <span class="fp-step-n fp-step-warn"><?= count($unassigned) ?></span>
                <h2>Groups without a section</h2>
            </div>
            <p class="fp-hint">Move each into its room. The whole group moves together, members included.</p>
            <?php foreach ($unassigned as $g):
                $gk = $g['department'] . '::' . $g['program'];
                $opts = $courses[$gk]['sections'] ?? [];
                // Preselect the first room of the group's own year, when it has one.
                $pre = null;
                foreach ($opts as $o) if ($pre === null && (string)$g['year_level'] !== '' && (string)$o['year_level'] === (string)$g['year_level']) $pre = $o['section_id']; ?>
                <form method="POST" action="focal_handler.php" class="fp-row fp-tray-row">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="assign_section">
                    <input type="hidden" name="user_id" value="<?= (int)$g['user_id'] ?>">
                    <div style="min-width:0;flex:1;">
                        <div class="fp-row-name"><?= fp_h($g['research_group_name']) ?></div>
                        <div class="fp-row-sub"><?= fp_h($g['username']) ?> &middot; <?= fp_h($g['program']) ?><?= $g['year_level'] ? ' &middot; ' . fp_h($g['year_level']) : '' ?></div>
                    </div>
                    <?php if ($opts): ?>
                        <div class="fp-row-right">
                            <select name="section_id" class="fp-select" required aria-label="Section for <?= fp_h($g['research_group_name']) ?>">
                                <?php foreach ($opts as $o): ?>
                                    <option value="<?= (int)$o['section_id'] ?>" <?= $o['section_id'] === $pre ? 'selected' : '' ?>>
                                        <?= fp_h(html_entity_decode(fp_section_name($o))) ?> (<?= (int)$o['group_count'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">Move</button>
                        </div>
                    <?php else: ?>
                        <div class="fp-row-right"><span class="fp-pill">add a room to <?= fp_h($g['program']) ?> first</span></div>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($courses)): ?>
        <div class="empty-state"><i data-lucide="book-x"></i><h3>No courses assigned to you</h3>
            <p>Ask the research office to assign your courses.</p></div>
    <?php endif; ?>

    <div class="fp-rooms">
        <?php foreach ($courses as $k => $c):
            $byYear = [];
            foreach ($c['sections'] as $s) $byYear[(string)$s['year_level']][] = $s;
            ksort($byYear); ?>
            <section class="fp-panel fp-room-card">
                <div class="fp-room-top">
                    <span class="fp-school"><?= aa_school_tag($c['department']) ?></span>
                    <h2><?= fp_h($c['program']) ?></h2>
                    <span class="fp-pill"><?= count($c['sections']) ?> room<?= count($c['sections']) === 1 ? '' : 's' ?></span>
                </div>

                <?php if (!$byYear): ?>
                    <p class="fp-hint">No rooms yet. Add the first one below so students have one to pick.</p>
                <?php endif; ?>
                <?php foreach ($byYear as $yLabel => $list): ?>
                    <div class="fp-room-year">
                        <b><?= $yLabel === '' ? 'Year not set' : fp_h($yLabel) ?></b>
                        <div class="fp-room-chips">
                            <?php foreach ($list as $s): $n = (int)$s['group_count']; ?>
                                <span class="fp-room <?= $n ? 'has' : '' ?>">
                                    <?= fp_h($s['label']) ?><small><?= $n ?> group<?= $n === 1 ? '' : 's' ?></small>
                                    <?php if (!$n): /* the handler refuses a room with groups in it */ ?>
                                        <form method="POST" action="focal_handler.php" onsubmit="return confirm('Delete room <?= fp_h(addslashes($s['label'])) ?>? Nobody is in it.')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_section">
                                            <input type="hidden" name="section_id" value="<?= (int)$s['section_id'] ?>">
                                            <button type="submit" class="fp-room-x" title="Delete this empty room" aria-label="Delete room <?= fp_h($s['label']) ?>"><i data-lucide="x"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <form method="POST" action="focal_handler.php" class="fp-room-add">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_section">
                    <input type="hidden" name="course" value="<?= fp_h($k) ?>">
                    <select name="year_level" class="fp-select" aria-label="Year level">
                        <?php foreach ($yearLevels as $y): ?><option value="<?= fp_h($y) ?>" <?= $y === '4th Year' ? 'selected' : '' ?>><?= fp_h($y) ?></option><?php endforeach; ?>
                    </select>
                    <input type="text" name="label" class="fp-input" placeholder="Room name, e.g. A" maxlength="40" required aria-label="Room name">
                    <button type="submit" class="btn btn-secondary"><i data-lucide="plus" style="width:14px;height:14px;"></i> Add room</button>
                </form>
            </section>
        <?php endforeach; ?>
    </div>


<?php elseif ($view === 'requirements' && !$group): /* ====== REQUIREMENTS — course, year, room in ONE screen ==== */ ?>

    <?php
    // The room's groups: whose move each one is, her move first.
    $rqRows = [];
    foreach ($groups as $g) {
        $uid = (int)$g['user_id'];
        $r   = $readiness[$uid];
        $pc  = (int)$pending[$uid];
        $rqRows[] = $g + [
            'r' => $r, 'pc' => $pc,
            'state'   => $pc > 0 ? 'you' : ($r['ready'] ? 'done' : 'them'),
            'members' => max(1, count($rosters[$uid] ?? [])),
            'sent'    => $lastSent[$uid] ?? null,
        ];
    }
    $rank = ['you' => 0, 'them' => 1, 'done' => 2];
    usort($rqRows, fn($a, $b) => [$rank[$a['state']], $a['research_group_name']] <=> [$rank[$b['state']], $b['research_group_name']]);
    $rqN = ['all' => count($rqRows), 'you' => 0, 'them' => 0, 'done' => 0];
    foreach ($rqRows as $r) $rqN[$r['state']]++;
    $rqSilent = array_values(array_map(fn($r) => (int)$r['user_id'], array_filter($rqRows, fn($r) => empty($r['sent']))));
    ?>

    <div class="rq">
        <?php fp_side($fpBoard, $view, $phase, $curKey, $yr, $sid); ?>

        <section class="rq-main">
            <?php if (!$section): ?>
                <div class="rq-blank">
                    <?php $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                    <h3>No rooms with groups yet</h3>
                    <p>Groups appear here as soon as students register into your courses' sections.</p>
                </div>
            <?php else: ?>
                <div class="rq-head">
                    <div class="rq-titles">
                        <div class="rq-crumb"><?= fp_h($course['program']) ?> / <?= $yr === '' ? 'Year not set' : fp_h($yr) ?> / Section <?= fp_h($section['label']) ?></div>
                        <h1><?= $yr === '' ? '' : fp_h($yr) . ' ' ?><?= fp_h($section['label']) ?></h1>
                    </div>
                    <label class="rq-search">
                        <i data-lucide="search"></i>
                        <input type="search" id="rqQ" placeholder="Search this section" autocomplete="off" oninput="rqFilter()">
                    </label>
                </div>

                <div class="rq-tiles" role="tablist" aria-label="Filter groups">
                    <?php foreach (['all' => 'All groups', 'you' => 'Waiting on you', 'them' => 'Waiting on them', 'done' => 'Complete'] as $fk => $fl): ?>
                        <button type="button" role="tab" class="rq-tile t-<?= $fk ?> <?= $fk === 'all' ? 'on' : '' ?>" data-f="<?= $fk ?>" onclick="rqTab(this)">
                            <b><?= $rqN[$fk] ?></b><span><?= $fl ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php if ($rqSilent): ?>
                    <?php /* Email only, the office's own reminder (focal_handler.php remind_groups). */ ?>
                    <form method="POST" action="focal_handler.php" class="rq-remind"
                          onsubmit="return confirm('Email the <?= count($rqSilent) ?> group<?= count($rqSilent) === 1 ? '' : 's' ?> that have sent nothing yet?')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="remind_groups">
                        <input type="hidden" name="phase" value="<?= $phase ?>">
                        <?php foreach ($rqSilent as $u): ?><input type="hidden" name="user_ids[]" value="<?= $u ?>"><?php endforeach; ?>
                        <span class="rq-remind-ico"><i data-lucide="bell"></i></span>
                        <p><b><?= count($rqSilent) ?> group<?= count($rqSilent) === 1 ? ' hasn&rsquo;t' : 's haven&rsquo;t' ?> sent anything yet.</b> Send their leaders one reminder.</p>
                        <button type="submit" class="rq-btn">Remind <?= count($rqSilent) === 1 ? 'them' : 'all ' . count($rqSilent) ?></button>
                    </form>
                <?php endif; ?>

                <div class="rq-list" id="rqList">
                    <?php foreach ($rqRows as $r):
                        $nm = preg_replace('/ Group$/', '', (string)$r['research_group_name']);
                        $L  = mb_strtoupper(mb_substr($nm, 0, 1)); ?>
                        <a class="rq-row s-<?= $r['state'] ?>" data-state="<?= $r['state'] ?>" href="<?= fp_h(fp_url(['group' => (int)$r['user_id']])) ?>"
                           data-q="<?= fp_h(mb_strtolower($r['research_group_name'] . ' ' . $r['username'] . ' ' . $r['student_number'])) ?>">
                            <span class="rq-av av<?= ord($L) % 6 ?>"><?= fp_h($L) ?></span>
                            <span class="rq-id">
                                <b><?= fp_h($r['research_group_name']) ?></b>
                                <small><?= fp_h($r['username']) ?><?= $r['student_number'] ? ', ' . fp_h($r['student_number']) : '' ?>, <?= $r['members'] ?> member<?= $r['members'] === 1 ? '' : 's' ?></small>
                            </span>
                            <span class="rq-prog">
                                <span class="rq-pips"><?php for ($i = 0; $i < $r['r']['total']; $i++): ?><i class="<?= $i < $r['r']['done'] ? 'on' : '' ?>"></i><?php endfor; ?></span>
                                <small><?= (int)$r['r']['done'] ?> of <?= (int)$r['r']['total'] ?> approved</small>
                            </span>
                            <span class="rq-when">
                                <?php if ($r['pc']): ?><em><?= $r['pc'] ?> to review</em>
                                <?php elseif ($r['sent']): ?>Sent <?= fp_h(fp_when($r['sent'])) ?>
                                <?php else: ?><span class="rq-quiet">Nothing sent yet</span><?php endif; ?>
                            </span>
                            <i data-lucide="chevron-right" class="rq-go"></i>
                        </a>
                    <?php endforeach; ?>
                    <p class="rq-empty" id="rqEmpty" hidden>No group matches.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>


<?php elseif ($view === 'requirements' && $group): /* ====================== REQUIREMENTS — one group's review ==== */ ?>

    <?php
    $gNm    = preg_replace('/ Group$/', '', (string)$group['research_group_name']);
    $gL     = mb_strtoupper(mb_substr($gNm, 0, 1));
    $gNames = ws_names_markup($rosters[$gid] ?? [], 'fp-names-head');
    $grNeed = []; $grBack = []; $grOk = []; $grMiss = [];
    foreach ($docs as $d) {
        if (!$d['upload'])                                                   $grMiss[] = $d;
        elseif (in_array($d['status'], ['Pending', 'Under Review'], true))  $grNeed[] = $d;
        elseif ($d['status'] === 'Revision Requested')                      $grBack[] = $d;
        else                                                                 $grOk[]   = $d;
    }
    $grFirst = ($grNeed[0] ?? $grBack[0] ?? $grOk[0] ?? null)['item_id'] ?? null;

    /** One requirement in the list. Clicking it opens its file on the right and aims the action bar at it. */
    $grItem = function (array $d, string $kind) use ($grFirst) {
        $u = $d['upload'];
        // Approving the Capsule (14) clears 13/15/16 with it — the button says how many.
        $clears = 1 + count(array_filter($d['bundled'], fn($k) => $k['status'] !== 'Approved'));
        $pill = ['need' => ['Needs review', 'amb'], 'back' => ['Sent back', 'back'], 'ok' => ['Approved', 'ok']][$kind];
        ?>
        <div class="gr-item k-<?= $kind ?>" tabindex="0" role="button" <?= (int)$d['item_id'] === $grFirst ? 'data-first' : '' ?>
             data-up="<?= (int)$u['upload_id'] ?>" data-status="<?= fp_h($d['status']) ?>" data-clears="<?= $clears ?>"
             data-path="<?= fp_h($u['file_path']) ?>" data-name="<?= fp_h($u['original_filename'] ?: 'Document') ?>"
             data-label="<?= fp_h($d['label']) ?>" data-sent="<?= fp_h(date('M j, Y', strtotime($u['uploaded_at']))) ?>"
             onclick="fpPick(this)" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fpPick(this); }">
            <?php if ($kind === 'need'): ?>
                <input type="checkbox" class="fp-pick" name="upload_ids[]" value="<?= (int)$u['upload_id'] ?>"
                       aria-label="Select <?= fp_h($d['label']) ?>" onclick="event.stopPropagation()" onchange="fpPaint()">
            <?php endif; ?>
            <div class="gr-item-body">
                <div class="gr-item-top">
                    <b><?= fp_h($d['label']) ?></b>
                    <span class="fd-chip <?= $pill[1] ?>"><?= $pill[0] ?></span>
                </div>
                <small class="gr-file"><?= fp_h($u['original_filename'] ?: 'Document') ?></small>
                <?php if ($kind === 'back' && trim((string)$u['remarks']) !== ''): ?>
                    <p class="gr-remark"><?= fp_h($u['remarks']) ?></p>
                <?php endif; ?>
                <?php if ($kind !== 'need'): /* who decided: NULL = before this was recorded, never the reader */ ?>
                    <small class="gr-by"><?= $kind === 'ok' ? 'Approved' : 'Sent back' ?>
                        <?php if (!empty($u['verified_name'])): ?>by <b><?= fp_h($u['verified_name']) ?></b> (<?= fp_h($u['verified_role']) ?>)<?= !empty($u['verified_at']) ? ', ' . fp_h(date('M j', strtotime($u['verified_at']))) : '' ?>
                        <?php else: ?>before this was recorded<?php endif; ?></small>
                <?php endif; ?>
                <?php if ($d['bundled']): ?>
                    <div class="gr-clears">
                        <span>Approving this also clears</span>
                        <?php foreach ($d['bundled'] as $k): ?>
                            <div><i></i><?= fp_h($k['label']) ?>
                                <em><?= $k['status'] === 'Approved' ? 'Approved' : ($k['status'] === 'Revision Requested' ? 'Sent back' : ($k['upload'] ? 'Waiting on the Capsule' : 'Covered by the Capsule')) ?></em></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    };
    ?>

    <div class="gr">
        <header class="gr-head">
            <a class="fp-backpill" href="<?= fp_h(fp_url(['group' => null])) ?>"><i data-lucide="arrow-left"></i> Section <?= fp_h($section['label']) ?></a>
            <span class="rq-av av<?= ord($gL) % 6 ?> gr-av"><?= fp_h($gL) ?></span>
            <div class="gr-title">
                <div class="rq-crumb"><?= fp_h($course['program']) ?> / <?= $yr === '' ? 'Year not set' : fp_h($yr) ?> / Section <?= fp_h($section['label']) ?></div>
                <h1><?= fp_h($group['research_group_name']) ?></h1>
            </div>
            <div class="gr-meta">
                <div class="gr-names"><?= $gNames['button'] ?><?= $gNames['panel'] ?></div>
                <div class="gr-prog">
                    <?php // One pip per requirement: approved, then sent and waiting on her (amber), then the rest.
                          $gPips = [];
                          foreach ($docs as $d) foreach (array_merge([$d], $d['bundled']) as $x)
                              $gPips[] = $x['status'] === 'Approved' ? 'on' : (in_array($x['status'], ['Pending', 'Under Review'], true) ? 'pend' : '');
                          usort($gPips, fn($a, $b) => array_search($a, ['on', 'pend', '']) <=> array_search($b, ['on', 'pend', ''])); ?>
                    <span class="rq-pips"><?php foreach ($gPips as $pc): ?><i class="<?= $pc ?>"></i><?php endforeach; ?></span>
                    <small><b><?= (int)$gReady['done'] ?> of <?= (int)$gReady['total'] ?></b> approved<?= !empty($lastSent[$gid]) ? ', last sent ' . fp_h(fp_when($lastSent[$gid])) : '' ?></small>
                </div>
            </div>
        </header>

        <?php /* Select-and-approve clears a stack at once. Approve only: a send-back needs its own
                 reason per document, and one shared remark tells the student nothing. */ ?>
        <form method="POST" action="focal_handler.php" id="fpBulk" class="gr-split">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="review_bulk">
            <input type="hidden" name="phase" value="<?= $phase ?>">

            <div class="gr-list">
                <?php if ($grNeed): ?>
                    <div class="gr-sec">
                        <label class="gr-sec-h"><input type="checkbox" id="fpAll" onchange="fpToggleAll(this)"> Needs your review <em><?= count($grNeed) ?></em></label>
                        <?php foreach ($grNeed as $d) $grItem($d, 'need'); ?>
                    </div>
                <?php else: ?>
                    <div class="gr-clear"><i data-lucide="check-circle-2"></i> Nothing here is waiting on you.</div>
                <?php endif; ?>

                <?php if ($grBack): ?>
                    <div class="gr-sec">
                        <div class="gr-sec-h">Sent back <em><?= count($grBack) ?></em></div>
                        <?php foreach ($grBack as $d) $grItem($d, 'back'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($grOk): ?>
                    <details class="gr-sec" <?= count($grOk) <= 3 || !$grNeed ? 'open' : '' ?>>
                        <summary class="gr-sec-h">Approved <em><?= count($grOk) ?></em><i data-lucide="chevron-down" class="rq-chev"></i></summary>
                        <?php foreach ($grOk as $d) $grItem($d, 'ok'); ?>
                    </details>
                <?php endif; ?>

                <?php if ($grMiss): ?>
                    <div class="gr-sec">
                        <div class="gr-sec-h">Not sent yet <em><?= count($grMiss) ?></em></div>
                        <?php foreach ($grMiss as $d): ?>
                            <div class="gr-miss">
                                <span class="gr-dash"></span>
                                <div><b><?= fp_h($d['label']) ?></b><small>Waiting for the group to upload it</small></div>
                                <button type="button" class="gr-remind" onclick="fpRemind(<?= (int)$d['item_id'] ?>, <?= htmlspecialchars(json_encode($d['label']), ENT_QUOTES) ?>)">Remind</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* The file, right here. Office formats go through Microsoft's web viewer, which
                     needs a URL it can reach — on localhost it cannot, so Download is the reliable
                     route there. PDFs and images load directly. */ ?>
            <aside class="gr-prev" id="fpPrev">
                <div class="gr-prev-bar">
                    <div class="gr-prev-t"><b id="fpPrevTitle">Pick a document</b><small id="fpPrevSub">Its file opens here.</small></div>
                    <a class="gr-ibtn" id="fpPrevDl" href="#" download hidden title="Download" aria-label="Download"><i data-lucide="download"></i></a>
                    <a class="gr-pbtn" id="fpPrevFull" href="#" target="_blank" rel="noopener" hidden><i data-lucide="maximize"></i> Full screen</a>
                </div>
                <div class="gr-prev-body">
                    <div class="gr-prev-empty" id="fpPrevEmpty">
                        <i data-lucide="file-text"></i>
                        <p><?= ($grNeed || $grBack || $grOk) ? 'Pick a requirement on the left to read it here.' : 'Nothing has been sent yet.' ?></p>
                    </div>
                    <iframe id="fpPrevFrame" hidden title="Document preview"></iframe>
                </div>
                <div class="gr-act" id="fpAct">
                    <div class="gr-seg" role="radiogroup" aria-label="Decision">
                        <button type="button" class="on ok" data-d="Approved" onclick="fpDecide(this)"><i data-lucide="check"></i> Approve</button>
                        <button type="button" class="back" data-d="Revision Requested" onclick="fpDecide(this)"><i data-lucide="rotate-ccw"></i> Send back</button>
                    </div>
                    <input type="text" id="fpNote" class="gr-note" placeholder="Note to the group (optional)" maxlength="500"
                           onkeydown="if (event.key === 'Enter') { event.preventDefault(); fpGo(); }">
                    <button type="button" class="gr-go" id="fpGoBtn" onclick="fpGo()" disabled>Approve</button>
                </div>
            </aside>
        </form>
    </div>

    <?php /* One-document decisions post through here, so no form is ever nested inside the bulk
             form (browsers drop nested forms without a word). */ ?>
    <form method="POST" action="focal_handler.php" id="fpOneForm" hidden>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="review_doc">
        <input type="hidden" name="upload_id" id="fpOneId">
        <input type="hidden" name="decision" id="fpOneDecision">
        <input type="hidden" name="remarks" id="fpOneRemarks">
    </form>
    <form method="POST" action="focal_handler.php" id="fpRemindForm" hidden>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="remind_groups">
        <input type="hidden" name="phase" value="<?= $phase ?>">
        <input type="hidden" name="user_ids[]" value="<?= $gid ?>">
        <input type="hidden" name="item_id" id="fpRemindItem">
    </form>


<?php elseif ($view === 'archive'): /* ================= ARCHIVE (Workspace) — finished business ==== */ ?>

    <?php
    // Every link here stays on the archive and keeps the phase, the tab and the room unless told otherwise.
    $arUrl   = fn(array $o) => '?' . http_build_query(array_filter(array_merge(
                   ['view' => 'archive', 'phase' => $phase, 'tab' => $arTab, 'course' => $section ? $curKey : '',
                    'section' => $section ? $sid : '', 'ym' => $arYm], $o),
                   fn($v) => $v !== '' && $v !== null && $v !== 0));
    $arTotal = array_sum($arMonths);
    $arShort = fn($n) => preg_replace('/ Group$/', '', (string)$n);
    $arRoom  = fn(array $r) => !empty($r['label']) ? fp_short_section($r) : 'No room';
    $arWhat  = $arTab === 'requirements' ? ['group', 'groups', 'cleared every requirement'] : ['date', 'dates', 'held'];
    ?>
    <nav class="fp-nav" aria-label="Where you are"><?php fp_hub_pill($view, $phase); ?></nav>

    <div class="rq-head">
        <div class="rq-titles">
            <div class="rq-crumb">Workspace / Archive</div>
            <h1>Archive</h1>
            <p class="rq-lede">Finished business: groups that cleared their requirements and dates already held, one month at a time.</p>
        </div>
        <?php if ($arRows): ?>
            <label class="rq-search">
                <i data-lucide="search"></i>
                <input type="search" id="hdQ" placeholder="Search this month" autocomplete="off" oninput="hdFilter()">
            </label>
        <?php endif; ?>
    </div>

    <?php /* Proposal / Final, then Requirements / Schedule — the two jobs, in each phase. */ ?>
    <div class="ar-switch">
        <div class="ar-seg" role="tablist" aria-label="Phase">
            <?php foreach (['proposal' => 'Proposal', 'final' => 'Final'] as $pk => $pl): ?>
                <a role="tab" class="<?= $phase === $pk ? 'on' : '' ?>" href="<?= fp_h($arUrl(['phase' => $pk, 'ym' => ''])) ?>"><?= $pl ?></a>
            <?php endforeach; ?>
        </div>
        <div class="ar-seg ar-seg-tabs" role="tablist" aria-label="What">
            <a role="tab" class="<?= $arTab === 'requirements' ? 'on' : '' ?>" href="<?= fp_h($arUrl(['tab' => 'requirements', 'ym' => ''])) ?>"><i data-lucide="file-check"></i> Requirements</a>
            <a role="tab" class="<?= $arTab === 'schedule' ? 'on' : '' ?>" href="<?= fp_h($arUrl(['tab' => 'schedule', 'ym' => ''])) ?>"><i data-lucide="calendar-check"></i> Schedule</a>
        </div>
    </div>

    <?php if (count($arRooms) > 1): ?>
        <div class="hd-rooms" aria-label="Rooms">
            <a class="hd-room <?= !$section ? 'on' : '' ?>" href="<?= fp_h($arUrl(['course' => '', 'section' => '', 'ym' => ''])) ?>">All rooms</a>
            <?php foreach ($arRooms as $hr): ?>
                <a class="hd-room <?= (int)$hr['section_id'] === $sid ? 'on' : '' ?>"
                   href="<?= fp_h($arUrl(['course' => $hr['course_key'], 'section' => (int)$hr['section_id'], 'ym' => ''])) ?>"><?= fp_h(fp_short_section($hr)) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$arMonths): ?>
        <div class="rq-blank">
            <?php include __DIR__ . '/components/quill_empty.php'; ?>
            <h3><?= $arTab === 'requirements' ? 'No group has cleared every requirement yet' : 'Nothing held yet' ?></h3>
            <p><?= $arTab === 'requirements'
                ? 'A group lands here the moment its last ' . $phase . ' requirement is approved.'
                : 'A published date moves here the day after it happens.' ?></p>
        </div>
    <?php else: ?>
        <?php /* The month index IS the page; only the chosen month is loaded. */ ?>
        <div class="hd-months" aria-label="Months">
            <?php foreach ($arMonths as $ym => $n): ?>
                <a class="hd-month <?= $ym === $arYm ? 'on' : '' ?>" href="<?= fp_h($arUrl(['ym' => $ym])) ?>" <?= $ym === $arYm ? 'aria-current="page"' : '' ?>>
                    <b><?= date('M Y', strtotime($ym . '-01')) ?></b><em><?= (int)$n ?></em>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="ar-count"><?= $arTotal ?> <?= $arTotal === 1 ? $arWhat[0] : $arWhat[1] ?> <?= $arWhat[2] ?><?= $section ? ' in ' . fp_h(fp_short_section($section)) : ' across your rooms' ?>.</p>

        <div class="hd-grid">
        <?php if ($arTab === 'requirements'): ?>
            <?php foreach ($arRows as $r):
                $L = mb_strtoupper(mb_substr($arShort($r['research_group_name']), 0, 1));
                $q = mb_strtolower($r['research_group_name'] . ' ' . $r['username'] . ' ' . $arRoom($r) . ' ' . $r['program']); ?>
                <article class="hd-card" data-q="<?= fp_h($q) ?>">
                    <header class="hd-top">
                        <span class="rq-av av<?= ord($L) % 6 ?>"><?= fp_h($L) ?></span>
                        <div class="hd-id">
                            <b><?= fp_h($r['research_group_name']) ?></b>
                            <small><?= fp_h($arRoom($r)) ?> &middot; <?= fp_h((string)$r['username']) ?></small>
                        </div>
                        <span class="dt-held-chip"><i data-lucide="check"></i> Cleared <?= fp_h((string)$r['cleared_md']) ?></span>
                    </header>
                    <ul class="ar-reqs">
                        <?php foreach ($r['docs'] as $d) foreach (array_merge([$d], $d['bundled']) as $i => $x):
                            $u = $x['upload']; ?>
                            <li class="<?= $i ? 'is-kid' : '' ?>">
                                <span class="ar-tick"><i data-lucide="check"></i></span>
                                <span class="ar-req">
                                    <b><?= fp_h($x['label']) ?></b>
                                    <small><?php if ($u && !empty($u['verified_name'])): ?>
                                        <?= fp_h($u['verified_name']) ?> (<?= fp_h($u['verified_role']) ?>)<?= !empty($u['verified_at']) ? ' &middot; ' . fp_h(date('M j', strtotime($u['verified_at']))) : '' ?>
                                    <?php elseif ($i): ?>Cleared with the Capsule
                                    <?php else: ?>Approved before this was recorded<?php endif; ?></small>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($arRows as $r):
                $names = array_map(fn($g) => $arShort($g['research_group_name']), $r['groups']);
                $q = mb_strtolower(implode(' ', $names) . ' ' . implode(' ', array_column($r['groups'], 'username')) . ' ' . $arRoom($r) . ' ' . $r['program']);
                $ago = (int)$r['ago']; ?>
                <article class="hd-card" data-q="<?= fp_h($q) ?>">
                    <header class="hd-top">
                        <span class="dt-tile held"><small><?= date('D', strtotime($r['defense_date'])) ?></small><b><?= date('j', strtotime($r['defense_date'])) ?></b><small><?= date('M', strtotime($r['defense_date'])) ?></small></span>
                        <div class="hd-id">
                            <b><?= fp_h($arRoom($r)) ?></b>
                            <small><?= fp_h((string)$r['program']) ?> &middot; held <?= $ago === 1 ? 'yesterday' : $ago . ' days ago' ?></small>
                        </div>
                        <span class="dt-held-chip"><i data-lucide="check"></i> Held</span>
                    </header>
                    <ul class="hd-groups">
                        <?php foreach ($r['groups'] as $g): ?>
                            <li><span class="hd-slot">Slot <?= (int)$g['slot_no'] ?></span>
                                <span class="hd-g"><b><?= fp_h($g['research_group_name']) ?></b><small><?= fp_h((string)$g['username']) ?></small></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <footer class="hd-sign">
                        <?php if (!empty($r['loose'])): ?>
                            <span class="hd-legacy"><i data-lucide="history"></i> Booked before the sign-off chain existed</span>
                        <?php else: foreach ([['Focal Person', $r['focal_name'], $r['focal_md']], ['Coordinator', $r['coord_name'], $r['coord_md']], ['Secretary', $r['sec_name'], $r['sec_md']]] as [$who, $name, $md]): ?>
                            <span class="hd-sig">
                                <em><i data-lucide="check"></i></em>
                                <span><b><?= fp_h($name ?: 'Not recorded') ?></b><small><?= $who ?><?= $md ? ' &middot; ' . fp_h($md) : '' ?></small></span>
                            </span>
                        <?php endforeach; endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <p class="rq-empty" id="hdNone" hidden>Nothing this month matches.</p>
    <?php endif; ?>


<?php else: /* ============= DEFENSE DATES — the same tree, the room's date on the right ==== */ ?>

    <div class="rq">
        <?php fp_side($fpBoard, $view, $phase, $curKey, $yr, $sid); ?>

        <section class="rq-main">
    <?php if (!$section): ?>
        <div class="rq-blank">
            <?php $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
            <h3>No rooms with groups yet</h3>
            <p>Groups appear here as soon as students register into your courses' sections.</p>
        </div>
    <?php else: ?>
    <?php
    // DEFENSE DATES (2026-10-01, Claude Design concept): the room's dates already in motion as
    // tickets, then two steps side by side — who is going, which day — and one send bar.
    $secName = ($yr === '' ? '' : $yr . ' ') . $section['label'];
    // Step 1's lists. A group holding a date is on the ticket strip, never offered again.
    $dtReady = []; $dtClose = [];
    foreach ($groups as $g) {
        $uid = (int)$g['user_id'];
        if (isset($booked[$uid])) continue;
        $g['r'] = $readiness[$uid];
        if ($g['r']['ready']) $dtReady[] = $g; else $dtClose[] = $g;
    }
    usort($dtClose, fn($a, $b) => [$b['r']['done'], $a['research_group_name']] <=> [$a['r']['done'], $b['research_group_name']]);
    $dtTop  = array_slice($dtClose, 0, 3);
    $dtRest = array_slice($dtClose, 3);
    $dtSome = count(array_filter($dtRest, fn($g) => $g['r']['done'] > 0));
    $dtNone = count($dtRest) - $dtSome;
    $dtNeed = count(fp_ready_items($phase));
    $dtShort = fn(string $n) => preg_replace('/ Group$/', '', $n);
    $dtRel = fn(int $d) => $d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : ($d > 1 ? 'in ' . $d . ' days' : ($d === -1 ? 'yesterday' : abs($d) . ' days ago')));
    $dtTile = fn(string $date, string $cls = '') => '<span class="dt-tile ' . $cls . '"><small>' . date('D', strtotime($date)) . '</small><b>'
                                                  . date('j', strtotime($date)) . '</b><small>' . date('M', strtotime($date)) . '</small></span>';
    ?>
    <div class="rq-head">
        <div class="rq-titles">
            <div class="rq-crumb"><?= fp_h($course['program']) ?> / <?= $yr === '' ? 'Year not set' : fp_h($yr) ?> / Section <?= fp_h($section['label']) ?></div>
            <h1>Put <?= fp_h($secName) ?> on a date</h1>
        </div>
    </div>

    <?php
    /** One ticket. The chain on it is the three signatures, so where a date has got to never needs a click. */
    $dtTicket = function (array $t) use ($dtTile, $dtShort, $dtRel, $csrf) {
        if (!empty($t['loose'])): ?>
            <div class="dt-card dt-loose">
                <?= $dtTile((string)$t['defense_date'], 'ok') ?>
                <div class="dt-body">
                    <b><?= fp_h($dtShort((string)$t['research_group_name'])) ?></b>
                    <small class="ok">Has a date<?= $t['slot_no'] ? ', slot ' . (int)$t['slot_no'] : '' ?>, <?= $dtRel((int)$t['dd']) ?></small>
                </div>
            </div>
        <?php return; endif;
        $names   = array_map(fn($r) => $dtShort((string)$r['research_group_name']), $t['groups']);
        $slots   = array_filter(array_map(fn($r) => (int)$r['slot_no'], $t['groups']));
        $slotTxt = !$slots ? '' : (count($slots) === 1 ? 'Slot ' . reset($slots) : 'Slots ' . min($slots) . '–' . max($slots));
        $back    = $t['status'] === 'Returned';
        $done    = $t['status'] === 'Scheduled';
        $late    = !$done && !$back && (int)$t['dd'] < 0; ?>
        <div class="dt-card <?= $back || $late ? 'is-back' : ($done ? 'is-done' : '') ?>">
            <?= $dtTile((string)$t['defense_date'], $back || $late ? 'back' : 'ok') ?>
            <div class="dt-body">
                <b title="<?= fp_h(implode(', ', $names)) ?>"><?= fp_h(implode(', ', array_slice($names, 0, 2))) ?><?= count($names) > 2 ? ' +' . (count($names) - 2) . ' more' : '' ?></b>
                <small><?= $slotTxt ? $slotTxt . ', ' : '' ?><?= $dtRel((int)$t['dd']) ?></small>
                <?php if ($back): ?><p class="dt-why"><b>Sent back</b><?= trim((string)$t['remarks']) !== '' ? ': ' . fp_h($t['remarks']) : '' ?></p><?php endif; ?>
                <?php if ($late): ?><p class="dt-why"><b>The day passed</b> before the chain finished. Withdraw it and pick a new one.</p><?php endif; ?>
            </div>
                <div class="dt-chain" aria-label="<?= fp_h(fp_status_label($t['status'])) ?>">
                    <?php foreach ([['You', $t['focal_at']], ['Coordinator', $t['coord_at']], ['Secretary', $t['sec_at']]] as $i => [$who, $at]):
                        $on = !empty($at); ?>
                        <?php if ($i): ?><i class="dt-rail <?= $on ? 'on' : '' ?>"></i><?php endif; ?>
                        <span class="dt-sig <?= $on ? 'on' : '' ?>"><em><?php if ($on): ?><i data-lucide="check"></i><?php else: ?><?= $i + 1 ?><?php endif; ?></em><?= $who ?></span>
                    <?php endforeach; ?>
                </div>
            <?php if (!$done): ?>
                <form method="POST" action="focal_handler.php" onsubmit="return confirm('Withdraw this date and release its slots?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="cancel_batch">
                    <input type="hidden" name="batch_id" value="<?= (int)$t['batch_id'] ?>">
                    <button type="submit" class="dt-withdraw">Withdraw</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    };
    ?>
    <?php if ($tickets): ?>
        <div class="dt-strip"><?php foreach ($tickets as $t) $dtTicket($t); ?></div>
    <?php endif; ?>

    <form method="POST" action="focal_handler.php" id="fpEndorse">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="endorse_batch">
        <input type="hidden" name="section_id" value="<?= $sid ?>">
        <input type="hidden" name="phase" value="<?= $phase ?>">
        <input type="hidden" name="defense_date" id="fpDate" value="">

        <div class="dt-steps">
            <?php /* ---------------- 1 — who ---------------- */ ?>
            <section class="dt-step">
                <header class="dt-step-h">
                    <span class="fp-step-n">1</span><h2>Who is going?</h2>
                    <?php if ($dtReady && !$pendingBatch): ?>
                        <span class="dt-links"><button type="button" onclick="fpTickAll(true)">All</button> &middot; <button type="button" onclick="fpTickAll(false)">None</button></span>
                    <?php endif; ?>
                </header>

                <?php if ($pendingBatch): ?>
                    <div class="dt-note">
                        <?php $quill_size = 52; $quill_pose = 'wink'; include __DIR__ . '/components/quill_empty.php'; ?>
                        <p><b>This room already has a date in the chain.</b>
                           <?= $pendingBatch['status'] === 'Returned'
                               ? 'It was sent back: withdraw it above, then pick again.'
                               : 'Wait for the office, or withdraw it above to send a different day.' ?></p>
                    </div>
                <?php elseif (!$dtReady): ?>
                    <div class="dt-note">
                        <?php $quill_size = 52; $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                        <p><b>No group is ready yet.</b> A group can get a date once all <?= $dtNeed ?> of its requirements are approved.</p>
                    </div>
                <?php else: ?>
                    <div class="dt-list">
                        <?php foreach ($dtReady as $g): ?>
                            <label class="dt-row is-ready">
                                <input type="checkbox" name="groups[]" value="<?= (int)$g['user_id'] ?>" checked onchange="fpSync()">
                                <span class="dt-id"><b><?= fp_h($g['research_group_name']) ?></b><small><?= fp_h($g['username']) ?></small></span>
                                <span class="rq-pips dt-pips"><?php for ($i = 0; $i < $g['r']['total']; $i++): ?><i class="on"></i><?php endfor; ?></span>
                                <span class="dt-state ok">Ready</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($dtTop): ?>
                    <h3 class="dt-sub">Closest to ready</h3>
                    <div class="dt-list">
                        <?php foreach ($dtTop as $g): $togo = $g['r']['total'] - $g['r']['done']; ?>
                            <div class="dt-row">
                                <span class="dt-id"><b><?= fp_h($g['research_group_name']) ?></b><small><?= fp_h($g['username']) ?></small></span>
                                <span class="rq-pips dt-pips"><?php for ($i = 0; $i < $g['r']['total']; $i++): ?><i class="<?= $i < $g['r']['done'] ? 'on' : '' ?>"></i><?php endfor; ?></span>
                                <span class="dt-state togo"><?= $togo ?> to go</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($dtRest): ?>
                        <p class="dt-rest"><?= implode(', and ', array_filter([
                            $dtSome ? $dtSome . ' more with some approved' : '',
                            $dtNone ? $dtNone . ($dtSome ? '' : ' more') . ' with nothing approved yet' : ''])) ?>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php /* ---------------- 2 — which day ---------------- */ ?>
            <section class="dt-step">
                <header class="dt-step-h">
                    <span class="fp-step-n">2</span><h2>Which day?</h2>
                    <span class="dt-nav">
                        <button type="button" id="fpPrev" aria-label="Previous month"><i data-lucide="chevron-left"></i></button>
                        <button type="button" id="fpNext" aria-label="Next month"><i data-lucide="chevron-right"></i></button>
                    </span>
                </header>
                <div class="dt-cal-top">
                    <b id="fpCalMonth">&nbsp;</b>
                    <small><?= fp_h(aa_school_tag((string)$section['department'])) ?> has <?= (int)DEFENSE_SLOTS_PER_SCHOOL_PER_DAY ?> slots a day</small>
                </div>
                <div class="dt-grid" id="fpGrid"></div>
                <div class="dt-legend">
                    <span><i class="lg-pick"></i>Your pick</span>
                    <span><i class="lg-tight"></i>Filling up</span>
                    <span><i class="lg-shut"></i>Closed or full</span>
                    <span><i class="lg-off"></i>Not bookable</span>
                </div>
                <?php if ($fpSoonest): ?>
                    <button type="button" class="dt-soon" id="fpSoonest" data-date="<?= fp_h($fpSoonest) ?>">Soonest open: <?= date('D, M j', strtotime($fpSoonest)) ?></button>
                <?php endif; ?>
                <div class="dt-dayinfo" id="fpDayInfo" hidden></div>
            </section>
        </div>

        <?php /* The send bar follows the page, so the thing you press is never below the fold —
                 and it always says what pressing it will do, or why it cannot. */ ?>
        <div class="dt-commit" id="fpCommit">
            <span class="dt-tile dt-tile-lg" id="fpTile"><small>&nbsp;</small><b>?</b><small>&nbsp;</small></span>
            <div class="dt-commit-t" id="fpSum"><b>&nbsp;</b><span>&nbsp;</span></div>
            <button type="submit" class="dt-go" id="fpGo" disabled>Send to Coordinator <i data-lucide="arrow-right"></i></button>
        </div>
    </form>

    <?php endif; /* a room is open */ ?>
        </section>
    </div>

<?php endif; ?>
</div>

<script>
    /* ---------------- Defense dates: who, which day, send ---------------- */
    // The calendar is a FIXED 42 cells (six weeks, neighbouring days dimmed) like both office
    // calendars, so the card never changes height between months. A day says the one thing worth
    // knowing about it: her own group's name if one is on it, "3 left" once it is filling, Full,
    // Closed, or Too soon. The handler re-checks capacity under a lock; this only stops the
    // obvious mistake before it is made.
    var FP_CAL     = <?= json_encode($fpCal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var FP_MIN     = <?= json_encode(df_window_start()) ?>;
    var FP_MAX     = <?= json_encode(df_window_end()) ?>;
    var FP_TODAY   = <?= json_encode(df_today()) ?>;
    var FP_SLOTS   = <?= (int)DEFENSE_SLOTS_PER_SCHOOL_PER_DAY ?>;
    var FP_MINE    = <?= json_encode((object)array_column(array_map(fn($g) => ['u' => (int)$g['user_id'], 'n' => preg_replace('/ Group$/', '', (string)$g['research_group_name'])], $groups), 'n', 'u'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var FP_PENDING = <?= !empty($pendingBatch) ? 'true' : 'false' ?>;
    var FP_READY   = document.querySelectorAll('#fpEndorse input[name="groups[]"]').length;
    var FP_MON     = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var fpChosen   = null;

    function fpEsc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function fpPad(n) { return String(n).padStart(2, '0'); }
    function fpKeyOf(d) { return d.getFullYear() + '-' + fpPad(d.getMonth() + 1) + '-' + fpPad(d.getDate()); }
    function fpPretty(k, o) { return new Date(k + 'T00:00:00').toLocaleDateString('en-US', o || { weekday: 'short', month: 'short', day: 'numeric' }); }

    (function fpCalendar() {
        var grid = document.getElementById('fpGrid');
        if (!grid) return;
        var start = new Date((<?= json_encode($fpSoonest) ?> || FP_MIN) + 'T00:00:00');
        var view  = { y: start.getFullYear(), m: start.getMonth() };
        var minM  = new Date(FP_TODAY + 'T00:00:00'), maxM = new Date(FP_MAX + 'T00:00:00');

        function mineOn(info) {
            return (info.groups || []).filter(function (g) { return FP_MINE[g.uid]; }).map(function (g) { return FP_MINE[g.uid]; });
        }
        function note(k, info) {
            var mine = mineOn(info);
            if (mine.length) return mine[0] + (mine.length > 1 ? ' +' + (mine.length - 1) : '');
            if (info.state === 'taken')   return 'Full';
            if (info.state === 'blocked') return 'Closed';
            if (info.state === 'out' && k >= FP_TODAY && k < FP_MIN) return 'Too soon';
            if (info.state === 'open' && info.used > 0) return info.left + ' left';
            return '';
        }
        function render() {
            document.getElementById('fpCalMonth').textContent = FP_MON[view.m] + ' ' + view.y;
            var html = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (d) { return '<span class="dt-dow">' + d + '</span>'; }).join('');
            grid.innerHTML = html;
            var first = new Date(view.y, view.m, 1);
            var d0 = new Date(view.y, view.m, 1 - first.getDay());
            for (var i = 0; i < 42; i++) {
                var dt = new Date(d0.getFullYear(), d0.getMonth(), d0.getDate() + i);
                var k = fpKeyOf(dt);
                var info = FP_CAL[k] || { state: k < FP_TODAY ? 'past' : 'out' };
                var open = info.state === 'open';
                var cls = 'dt-d';
                if (dt.getMonth() !== view.m) cls += ' is-other';
                if (open) cls += info.left <= 3 ? ' is-open is-tight' : ' is-open';
                else if (info.state === 'taken' || info.state === 'blocked') cls += ' is-shut';
                else cls += ' is-off';
                if (mineOn(info).length) cls += ' is-mine';
                if (k === FP_TODAY) cls += ' is-today';
                if (k === fpChosen) cls += ' is-pick';
                var lab = k === fpChosen ? info.left + ' left' : note(k, info);
                var b = document.createElement('button');
                b.type = 'button';
                b.className = cls;
                b.dataset.date = k;
                b.innerHTML = '<b>' + dt.getDate() + '</b>' + (lab ? '<small>' + fpEsc(lab) + '</small>' : '');
                if (info.reason) b.title = info.reason;
                // aria-disabled, not disabled: a disabled button fires no click, so it could never say WHY.
                if (!open) b.setAttribute('aria-disabled', 'true');
                b.addEventListener('click', function () {
                    var i2 = FP_CAL[this.dataset.date];
                    if (i2 && i2.state === 'open') fpChoose(this.dataset.date); else fpDayNote(this.dataset.date, i2);
                });
                grid.appendChild(b);
            }
            document.getElementById('fpPrev').disabled = (view.y * 12 + view.m) <= (minM.getFullYear() * 12 + minM.getMonth());
            document.getElementById('fpNext').disabled = (view.y * 12 + view.m) >= (maxM.getFullYear() * 12 + maxM.getMonth());
            if (window.lucide) lucide.createIcons();
        }
        window.fpDayNote = function (k, info) {
            var box = document.getElementById('fpDayInfo');
            if (!box) return;
            var head = '<b>' + fpPretty(k, { weekday: 'long', month: 'long', day: 'numeric' }) + '</b>';
            if (!info) { box.innerHTML = head + ': ' + (k < FP_TODAY ? 'already past.' : 'outside the booking window.'); box.hidden = false; return; }
            var who = (info.groups || []).map(function (g) {
                return 'Slot ' + g.slot + ' &middot; ' + fpEsc(g.name) + (FP_MINE[g.uid] ? ' <em>(this room)</em>' : ' <span>(' + fpEsc(g.program) + ')</span>');
            });
            if (info.state !== 'open' && info.reason) box.innerHTML = head + ': ' + fpEsc(info.reason) + (who.length ? '<br>' + who.join('<br>') : '');
            else if (who.length) box.innerHTML = head + ': ' + info.used + ' of ' + FP_SLOTS + ' taken, ' + info.left + ' left.<br>' + who.join('<br>');
            else box.innerHTML = head + ': nothing booked yet. All ' + FP_SLOTS + ' slots are free.';
            box.hidden = false;
        };
        window.fpChoose = function (k) {
            fpChosen = k;
            document.getElementById('fpDate').value = k;
            fpDayNote(k, FP_CAL[k]);
            render();
            fpSync();
        };
        document.getElementById('fpPrev').addEventListener('click', function () { view.m--; if (view.m < 0) { view.m = 11; view.y--; } render(); });
        document.getElementById('fpNext').addEventListener('click', function () { view.m++; if (view.m > 11) { view.m = 0; view.y++; } render(); });
        var soon = document.getElementById('fpSoonest');
        if (soon) soon.addEventListener('click', function () {
            var d = new Date(this.dataset.date + 'T00:00:00');
            view.y = d.getFullYear(); view.m = d.getMonth();
            fpChoose(this.dataset.date);
        });
        render();
    })();

    // Already held: search narrows the month on screen (a month is the most the page ever holds).
    function hdFilter() {
        var q = (document.getElementById('hdQ') || {}).value || '', n = 0;
        q = q.toLowerCase().trim();
        document.querySelectorAll('.hd-card').forEach(function (c) { var ok = !q || c.dataset.q.indexOf(q) !== -1; c.hidden = !ok; if (ok) n++; });
        var e = document.getElementById('hdNone'); if (e) e.hidden = n > 0;
    }
    function fpTickAll(on) {
        document.querySelectorAll('#fpEndorse input[name="groups[]"]').forEach(function (c) { c.checked = on; });
        fpSync();
    }

    // The send bar: always says what pressing it will do, or why it cannot.
    function fpSync() {
        var go = document.getElementById('fpGo');
        if (!go) return;
        var info = fpChosen ? FP_CAL[fpChosen] : null;
        var n = document.querySelectorAll('#fpEndorse input[name="groups[]"]:checked').length;
        var free = info ? info.left : 0;
        var groups = n + ' group' + (n === 1 ? '' : 's');
        var pretty = fpChosen ? fpPretty(fpChosen, { weekday: 'short', month: 'short', day: 'numeric' }) : '';
        var tile = document.getElementById('fpTile');
        if (fpChosen) {
            var d = new Date(fpChosen + 'T00:00:00');
            tile.innerHTML = '<small>' + d.toLocaleDateString('en-US', { weekday: 'short' }) + '</small><b>' + d.getDate() + '</b><small>' + d.toLocaleDateString('en-US', { month: 'short' }) + '</small>';
        }
        tile.classList.toggle('is-empty', !fpChosen);
        function set(head, sub, ok) {
            document.getElementById('fpSum').innerHTML = '<b>' + head + '</b><span>' + sub + '</span>';
            go.disabled = !ok;
            document.getElementById('fpCommit').classList.toggle('ready', ok);
        }
        if (FP_PENDING) return set('This room already has a date in the chain', 'Withdraw it above to send a different day.', false);
        if (!FP_READY)  return set('Nobody is ready for a date yet', 'You can still look at the calendar. Groups show up in step 1 once they’re cleared.', false);
        if (!n && !info) return set('Tick who is going, then pick a day', FP_READY + ' ready in this room.', false);
        if (!n)          return set('No groups ticked', 'Tick at least one group to put on ' + pretty + '.', false);
        if (!info)       return set(groups + ' ticked', 'Now pick a day for them.', false);
        if (n > free)    return set(groups + ' is too many for ' + pretty,
                                    'Only ' + free + ' slot' + (free === 1 ? '' : 's') + ' left that day. Untick some, or pick another day.', false);
        set(groups + ' → ' + pretty, (free - n) + ' slot' + (free - n === 1 ? '' : 's') + ' would be left that day. The Coordinator confirms next.', true);
    }
    fpSync();

    // Back to the hub: inside the shell, close this window onto it; alone, follow the link.
    function fpToHub(v) {
        try { if (window.top !== window && typeof window.top.shShow === 'function') { window.top.shShow(v); return false; } } catch (e) {}
        return true;
    }

    /* ---------------- requirements: the room list ---------------- */
    var rqF = 'all';
    function rqTab(btn) {
        rqF = btn.dataset.f;
        document.querySelectorAll('.rq-tile').forEach(function (t) { t.classList.toggle('on', t === btn); });
        rqFilter();
    }
    function rqFilter() {
        var qi = document.getElementById('rqQ'), q = qi ? qi.value.toLowerCase().trim() : '', n = 0;
        document.querySelectorAll('.rq-row').forEach(function (r) {
            var show = (rqF === 'all' || r.dataset.state === rqF) && (!q || r.dataset.q.indexOf(q) !== -1);
            r.hidden = !show; if (show) n++;
        });
        var e = document.getElementById('rqEmpty'); if (e) e.hidden = n > 0;
    }

    /* ---------------- requirements: one group's review ---------------- */
    // The list picks WHICH document; the action bar under the file decides it. Ticking boxes
    // switches the bar to approving the ticked ones together (never sending back — each needs
    // its own reason).
    var fpCur = null, fpDec = 'Approved';

    // Read the file in place. Office formats (doc/xls/ppt) cannot render in a browser, so they
    // go through Microsoft's viewer, which fetches the URL itself — that means it works on a
    // public host and not on localhost, which is why Download sits beside it.
    function fpPick(el) {
        fpCur = el;
        document.querySelectorAll('.gr-item').forEach(function (d) { d.classList.toggle('on', d === el); });
        var path = el.dataset.path, frame = document.getElementById('fpPrevFrame');
        document.getElementById('fpPrevTitle').textContent = el.dataset.label;
        document.getElementById('fpPrevSub').textContent = el.dataset.name + ', sent ' + el.dataset.sent;
        var dl = document.getElementById('fpPrevDl'), full = document.getElementById('fpPrevFull');
        dl.href = path; dl.hidden = false;
        var ext = (path.split('.').pop() || '').toLowerCase(), src = path;
        if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].indexOf(ext) !== -1) {
            var p = window.location.pathname, i = p.indexOf('/dashboards/');
            var pub = window.location.origin + (i !== -1 ? p.substring(0, i) : '') + '/' + path.replace('../', '');
            src = 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(pub);
        }
        frame.src = src; frame.hidden = false; full.href = src; full.hidden = false;
        document.getElementById('fpPrevEmpty').hidden = true;
        // A new document starts a new decision.
        fpDec = el.dataset.status === 'Revision Requested' ? 'Revision Requested' : 'Approved';
        document.getElementById('fpNote').value = '';
        fpPaint();
        if (window.innerWidth <= 1000) document.getElementById('fpPrev').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function fpDecide(btn) { fpDec = btn.dataset.d; fpPaint(); document.getElementById('fpNote').focus(); }
    function fpPaint() {
        var go = document.getElementById('fpGoBtn');
        if (!go) return;
        var note = document.getElementById('fpNote'), picked = document.querySelectorAll('.fp-pick:checked');
        var segs = document.querySelectorAll('.gr-seg button');
        var all = document.getElementById('fpAll');
        if (all) all.checked = picked.length > 0 && picked.length === document.querySelectorAll('.fp-pick').length;
        go.classList.remove('back');
        if (picked.length) {
            var clears = 0;
            picked.forEach(function (c) { clears += parseInt(c.closest('.gr-item').dataset.clears, 10) || 1; });
            segs.forEach(function (b) { b.classList.toggle('on', b.dataset.d === 'Approved'); b.disabled = b.dataset.d !== 'Approved'; });
            note.disabled = true; note.value = '';
            note.placeholder = 'Send back one at a time: each needs its own reason';
            go.disabled = false;
            go.textContent = 'Approve ' + picked.length + ' selected' + (clears > picked.length ? ', clears ' + clears : '');
            return;
        }
        segs.forEach(function (b) { b.disabled = !fpCur; b.classList.toggle('on', b.dataset.d === fpDec); });
        note.disabled = !fpCur;
        if (!fpCur) { go.disabled = true; go.textContent = 'Pick a document'; return; }
        go.disabled = false;
        if (fpDec === 'Approved') {
            var c = parseInt(fpCur.dataset.clears, 10) || 1;
            note.placeholder = 'Note to the group (optional)';
            go.textContent = fpCur.dataset.status === 'Approved' ? 'Approve again' : (c > 1 ? 'Approve, clears ' + c : 'Approve');
        } else {
            note.placeholder = 'What needs fixing? The group sees this.';
            go.textContent = 'Send back';
            go.classList.add('back');
        }
    }
    function fpToggleAll(box) {
        document.querySelectorAll('.fp-pick').forEach(function (c) { c.checked = box.checked; });
        fpPaint();
    }
    function fpGo() {
        var picked = document.querySelectorAll('.fp-pick:checked');
        if (picked.length) { document.getElementById('fpBulk').submit(); return; }
        if (!fpCur) return;
        var note = document.getElementById('fpNote'), why = note.value.trim();
        if (fpDec === 'Revision Requested' && !why) {
            note.classList.remove('shake'); void note.offsetWidth; note.classList.add('shake'); note.focus();
            return;
        }
        document.getElementById('fpOneId').value = fpCur.dataset.up;
        document.getElementById('fpOneDecision').value = fpDec;
        document.getElementById('fpOneRemarks').value = why;
        document.getElementById('fpGoBtn').disabled = true;
        document.getElementById('fpOneForm').submit();
    }
    function fpRemind(itemId, label) {
        if (!confirm('Email this group a reminder to upload ' + label + '?')) return;
        document.getElementById('fpRemindItem').value = itemId;
        document.getElementById('fpRemindForm').submit();
    }
    (function () {
        var first = document.querySelector('.gr-item[data-first]');
        if (first) fpPick(first); else fpPaint();
    })();

    // Same function name and behaviour as the office screens: ws_names_markup() emits an
    // onclick calling wsToggleNames(id, btn), and every host page defines it.
    function wsToggleNames(id, btnEl) {
        var panel = document.getElementById(id);
        if (!panel) return;
        var open = panel.style.display === 'none';
        panel.style.display = open ? 'flex' : 'none';
        btnEl.classList.toggle('is-open', open);
        var n = panel.querySelectorAll('.ws-name-row').length;
        btnEl.innerHTML = '<i data-lucide="users"></i> ' + (open ? 'Hide names' : 'View names') +
            '<span class="ws-names-n">' + n + '</span>';
        if (window.lucide) lucide.createIcons();
    }

    if (window.lucide) lucide.createIcons();
</script>
</body>

</html>
