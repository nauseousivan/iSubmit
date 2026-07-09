<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

header('Content-Type: application/json');

$group_user_id = (int) ($_GET['user_id'] ?? 0);
if ($group_user_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user_id']);
    exit;
}

// Must be a real student-leader group (same population as the Explorer/sidebar).
$stmt = $pdo->prepare("SELECT user_id, username, research_group_name, department, program, profile_pic, email, research_title, created_at
                        FROM users WHERE user_id = ? AND role = 'Student' AND leader_id IS NULL");
$stmt->execute([$group_user_id]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found']);
    exit;
}

// Team roster (free-text member names collected at group setup).
$stmt = $pdo->prepare("SELECT member_name FROM research_group_members WHERE owner_user_id = ? ORDER BY id ASC");
$stmt->execute([$group_user_id]);
$roster = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Milestones: identical LEFT JOIN + COALESCE pattern as _master_overview.php's Explorer query,
// so this can never disagree with what the Explorer card itself shows for the same group.
$stmt = $pdo->prepare("
    SELECT COALESCE(ap.coordinator_status, 'Pending')  AS coordinator_status,
           COALESCE(ap.statistician_status, 'Pending') AS statistician_status,
           COALESCE(ap.director_status, 'Pending')     AS director_status,
           COALESCE(ap.payment_status, 'Unpaid')       AS payment_status,
           ap.updated_at
    FROM users u
    LEFT JOIN approvals ap ON ap.approval_id = (SELECT MAX(a2.approval_id) FROM approvals a2 WHERE a2.user_id = u.user_id)
    WHERE u.user_id = ?
");
$stmt->execute([$group_user_id]);
$milestones = $stmt->fetch();
$milestones['stage_days'] = $milestones['updated_at'] ? (int) floor((time() - strtotime($milestones['updated_at'])) / 86400) : null;
unset($milestones['updated_at']);

// Phase progress — the exact latest-upload-per-item Approved-count formula already shipped in
// admin_module_dynamic.php ($group_progress, lines ~522-545), just run across all 4 phases for
// this one group instead of the current phase only. Same numbers the review module itself shows.
$phase_map = [
    'proposal' => ['label' => 'Proposal Defense', 'items' => [11, 12, 13, 14, 15, 16]],
    'final'    => ['label' => 'Final Manuscript', 'items' => [21, 22, 23, 24, 25, 26, 27]],
    'stats'    => ['label' => 'Statistics Clearance', 'items' => [30, 31, 32, 33, 34, 35, 36, 37]],
    'plag'     => ['label' => 'Plagiarism Verification', 'items' => [4]],
];
$all_items = array_merge(...array_column($phase_map, 'items'));
$ph = implode(',', array_fill(0, count($all_items), '?'));
$stmt = $pdo->prepare("
    SELECT up.item_id, up.verification_status
    FROM uploads up
    INNER JOIN (
        SELECT item_id, MAX(uploaded_at) AS md FROM uploads WHERE user_id = ? AND item_id IN ($ph) GROUP BY item_id
    ) l ON up.item_id = l.item_id AND up.uploaded_at = l.md
    WHERE up.user_id = ? AND up.item_id IN ($ph)
");
$stmt->execute(array_merge([$group_user_id], $all_items, [$group_user_id], $all_items));
$latest_by_item = [];
foreach ($stmt->fetchAll() as $row) {
    $latest_by_item[(int) $row['item_id']] = $row['verification_status'];
}

$phases = [];
foreach ($phase_map as $key => $info) {
    $approved = 0;
    foreach ($info['items'] as $iid) {
        if (($latest_by_item[$iid] ?? null) === 'Approved') {
            $approved++;
        }
    }
    $total = count($info['items']);
    $phases[$key] = [
        'label'    => $info['label'],
        'approved' => $approved,
        'total'    => $total,
        'pct'      => $total > 0 ? (int) round($approved / $total * 100) : 0,
    ];
}

// Timeline: the same source/shape already trusted for the master dashboard's Recent Activity
// feed, just scoped to this one student — no second timeline-derivation algorithm to diverge.
$stmt = $pdo->prepare("SELECT title, description, status_type, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$group_user_id]);
$timeline = $stmt->fetchAll();

echo json_encode([
    'group'      => $group,
    'roster'     => $roster,
    'milestones' => $milestones,
    'phases'     => $phases,
    'timeline'   => $timeline,
]);
