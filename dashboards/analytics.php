<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
    exit('Access Denied');
}

$phase_map = [
    'proposal' => ['label' => 'Proposal Defense', 'items' => [11, 12, 13, 14, 15, 16]],
    'final'    => ['label' => 'Final Manuscript', 'items' => [21, 22, 23, 24, 25, 26, 27]],
    'stats'    => ['label' => 'Statistics Clearance', 'items' => [30, 31, 32, 33, 34, 35, 36, 37]],
    'plag'     => ['label' => 'Plagiarism Verification', 'items' => [4]],
];

// Base group population — identical query to _master_overview.php's Explorer (52 leader groups,
// LEFT JOINed to their latest approvals row so groups with no approvals row still appear).
$groups = $pdo->query("
    SELECT u.user_id, u.username, u.research_group_name, u.department, u.program,
           COALESCE(ap.coordinator_status, 'Pending')  AS coordinator_status,
           COALESCE(ap.statistician_status, 'Pending') AS statistician_status,
           COALESCE(ap.director_status, 'Pending')     AS director_status,
           COALESCE(ap.payment_status, 'Unpaid')       AS payment_status,
           ap.updated_at
    FROM users u
    LEFT JOIN approvals ap ON ap.approval_id = (SELECT MAX(a2.approval_id) FROM approvals a2 WHERE a2.user_id = u.user_id)
    WHERE u.role = 'Student' AND u.research_group_name IS NOT NULL AND u.research_group_name <> '' AND u.leader_id IS NULL
    ORDER BY u.research_group_name ASC
")->fetchAll();

// --- CSV export ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="research_groups_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Group Name', 'Leader', 'Department', 'Program', 'Coordinator Status', 'Statistician Status', 'Director Status', 'Payment Status', 'Days In Current Stage']);
    foreach ($groups as $g) {
        $days = $g['updated_at'] ? floor((time() - strtotime($g['updated_at'])) / 86400) : 'N/A';
        fputcsv($out, [$g['research_group_name'], $g['username'], $g['department'], $g['program'], $g['coordinator_status'], $g['statistician_status'], $g['director_status'], $g['payment_status'], $days]);
    }
    fclose($out);
    exit;
}

// --- Phase health: latest-upload-per-item dedup across all 4 phases, in one query -------------
$all_items = array_merge(...array_column($phase_map, 'items'));
$ph = implode(',', array_fill(0, count($all_items), '?'));
$stmt = $pdo->prepare("
    SELECT up.user_id, up.item_id, up.verification_status, up.uploaded_at
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS md FROM uploads WHERE item_id IN ($ph) GROUP BY user_id, item_id
    ) l ON up.user_id = l.user_id AND up.item_id = l.item_id AND up.uploaded_at = l.md
    WHERE up.item_id IN ($ph)
");
$stmt->execute(array_merge($all_items, $all_items));

$by_phase_user = [];
foreach ($stmt->fetchAll() as $row) {
    foreach ($phase_map as $key => $info) {
        if (in_array((int) $row['item_id'], $info['items'], true)) {
            $by_phase_user[$key][$row['user_id']][] = $row;
            break;
        }
    }
}

$phase_stats = [];
foreach ($phase_map as $key => $info) {
    $counts = ['Approved' => 0, 'Revision Requested' => 0, 'Pending' => 0, 'Under Review' => 0];
    $turnarounds = [];
    foreach (($by_phase_user[$key] ?? []) as $uid => $items) {
        $min_ts = null;
        $max_approved_ts = null;
        foreach ($items as $it) {
            $st = $it['verification_status'];
            $counts[$st] = ($counts[$st] ?? 0) + 1;
            $ts = strtotime($it['uploaded_at']);
            if ($min_ts === null || $ts < $min_ts) {
                $min_ts = $ts;
            }
            if ($st === 'Approved' && ($max_approved_ts === null || $ts > $max_approved_ts)) {
                $max_approved_ts = $ts;
            }
        }
        // Only count turnaround for groups that have fully cleared this phase (every item
        // in range is Approved) — otherwise "latest approval" wouldn't mean phase completion.
        if (count($items) === count($info['items']) && $max_approved_ts !== null) {
            $turnarounds[] = ($max_approved_ts - $min_ts) / 86400;
        }
    }
    $total = $counts['Approved'] + $counts['Revision Requested'] + $counts['Pending'] + $counts['Under Review'];
    $phase_stats[$key] = [
        'label'          => $info['label'],
        'counts'         => $counts,
        'total'          => $total,
        'backlog'        => $counts['Pending'] + $counts['Under Review'],
        'pass_rate'      => $total > 0 ? round($counts['Approved'] / $total * 100) : 0,
        'avg_turnaround' => count($turnarounds) ? round(array_sum($turnarounds) / count($turnarounds), 1) : null,
        'completed'      => count($turnarounds),
    ];
}
$max_backlog = 1;
foreach ($phase_stats as $s) {
    $max_backlog = max($max_backlog, $s['backlog']);
}

// --- Stage-aging distribution (52 leader groups' latest approvals row) ------------------------
$aging = ['0-2 days' => 0, '3-5 days' => 0, '6-10 days' => 0, '10+ days' => 0, 'Not started' => 0];
foreach ($groups as $g) {
    if (!$g['updated_at']) {
        $aging['Not started']++;
        continue;
    }
    $d = floor((time() - strtotime($g['updated_at'])) / 86400);
    if ($d <= 2) $aging['0-2 days']++;
    elseif ($d <= 5) $aging['3-5 days']++;
    elseif ($d <= 10) $aging['6-10 days']++;
    else $aging['10+ days']++;
}
$aging_max = max(1, max($aging));

// --- Department / Program breakdown -------------------------------------
$dept_counts = [];
foreach ($groups as $g) {
    $d = $g['department'] ?: 'Unspecified';
    $dept_counts[$d] = ($dept_counts[$d] ?? 0) + 1;
}
arsort($dept_counts);
$dept_max = max(1, max($dept_counts ?: [1]));

// --- 30-day activity volume ----------------------------------------------
$stmt = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM activity_logs WHERE created_at >= (NOW() - INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
$volume_raw = $stmt->fetchAll();
$volume = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $volume[$day] = 0;
}
foreach ($volume_raw as $row) {
    if (isset($volume[$row['d']])) {
        $volume[$row['d']] = (int) $row['c'];
    }
}
$volume_max = max(1, max($volume));

$total_groups = count($groups);
$total_backlog = array_sum(array_column($phase_stats, 'backlog'));
$overall_approved = array_sum(array_map(function ($s) { return $s['counts']['Approved']; }, $phase_stats));
$overall_total = array_sum(array_column($phase_stats, 'total'));
$overall_rate = $overall_total > 0 ? round($overall_approved / $overall_total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Analytics &amp; Export</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .bar-row-label { width: 150px; flex-shrink: 0; font-size: 12.5px; font-weight: 600; color: var(--ink); }
        .bar-track { flex: 1; height: 10px; background: var(--surface-3); border-radius: 5px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 5px; background: var(--accent); }
        .bar-fill.warn { background: var(--warning); }
        .bar-row-value { width: 90px; flex-shrink: 0; text-align: right; font-size: 12px; font-weight: 700; color: var(--muted); }
        .analytics-section { background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 20px 22px; margin-bottom: 20px; }
        .analytics-section h3 { font-size: 15px; font-weight: 700; color: var(--ink); margin: 0 0 16px; }
        .analytics-section p.hint { font-size: 11.5px; color: var(--muted); margin: -10px 0 16px; }
        .spark-row { display: flex; align-items: flex-end; gap: 3px; height: 60px; background: var(--surface-2); border-radius: var(--r-sm); }
        .spark-bar { flex: 1; background: var(--accent); border-radius: 2px 2px 0 0; min-height: 2px; opacity: .75; }
        .spark-bar:hover { opacity: 1; }
    </style>
</head>

<body class="admin-module">
    <div class="page-header">
        <div>
            <h1 class="page-title">Analytics &amp; Export</h1>
            <p class="page-subtitle">Cohort-wide pass rates, backlog, aging and turnaround across all 52 research groups.</p>
        </div>
        <a href="analytics.php?export=csv" class="btn-update" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
            <i data-lucide="download" style="width:15px;height:15px;"></i> Export CSV
        </a>
    </div>

    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-value"><?= (int) $total_groups ?></div>
            <div class="stat-label">Research Groups</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $total_backlog ?></div>
            <div class="stat-label">Items Awaiting Review</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $overall_rate ?>%</div>
            <div class="stat-label">Overall Approval Rate</div>
        </div>
    </div>

    <div class="analytics-section">
        <h3>Phase Health</h3>
        <p class="hint">Approval rate per phase, based on the latest submitted version of each required item.</p>
        <?php foreach ($phase_stats as $s): ?>
            <div class="bar-row">
                <div class="bar-row-label"><?= htmlspecialchars($s['label']) ?></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= (int) $s['pass_rate'] ?>%;"></div></div>
                <div class="bar-row-value"><?= (int) $s['pass_rate'] ?>% (<?= (int) $s['counts']['Approved'] ?>/<?= (int) $s['total'] ?>)</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="analytics-section">
        <h3>Current Bottleneck</h3>
        <p class="hint">Items sitting in Pending / Under Review right now, by phase.</p>
        <?php foreach ($phase_stats as $s): ?>
            <div class="bar-row">
                <div class="bar-row-label"><?= htmlspecialchars($s['label']) ?></div>
                <div class="bar-track"><div class="bar-fill warn" style="width:<?= (int) round($s['backlog'] / $max_backlog * 100) ?>%;"></div></div>
                <div class="bar-row-value"><?= (int) $s['backlog'] ?> waiting</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="analytics-section">
        <h3>Turnaround Time</h3>
        <p class="hint">First submission &rarr; final item approval, averaged over groups that have fully cleared that phase.</p>
        <?php foreach ($phase_stats as $s): ?>
            <div class="bar-row">
                <div class="bar-row-label"><?= htmlspecialchars($s['label']) ?></div>
                <div class="bar-row-value" style="width:auto; flex:1; text-align:left; font-weight:600; color:var(--ink);">
                    <?= $s['avg_turnaround'] !== null ? $s['avg_turnaround'] . ' days avg' : 'No completed groups yet' ?>
                    <span style="color:var(--muted); font-weight:500;"> &middot; <?= (int) $s['completed'] ?> group<?= $s['completed'] === 1 ? '' : 's' ?> completed</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="analytics-section">
        <h3>Stage Aging</h3>
        <p class="hint">Days since each group's last status change, across all 52 groups.</p>
        <?php foreach ($aging as $label => $count): ?>
            <div class="bar-row">
                <div class="bar-row-label"><?= htmlspecialchars($label) ?></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= (int) round($count / $aging_max * 100) ?>%;"></div></div>
                <div class="bar-row-value"><?= (int) $count ?> groups</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="analytics-section">
        <h3>Department Breakdown</h3>
        <?php foreach ($dept_counts as $dept => $count): ?>
            <div class="bar-row">
                <div class="bar-row-label" title="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= (int) round($count / $dept_max * 100) ?>%;"></div></div>
                <div class="bar-row-value"><?= (int) $count ?> groups</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="analytics-section">
        <h3>30-Day Activity Volume</h3>
        <p class="hint">Submissions, reviews and status changes recorded per day (hover a bar for the date/count).</p>
        <div class="spark-row">
            <?php foreach ($volume as $day => $count): ?>
                <div class="spark-bar" title="<?= htmlspecialchars(date('M j', strtotime($day))) ?>: <?= (int) $count ?>" style="height:<?= max(3, (int) round($count / $volume_max * 60)) ?>px;"></div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>
