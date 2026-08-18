<?php
// Read-only JSON endpoint polled by the staff dock to keep the review-queue badges live without a
// full page refresh. Reuses the exact same per-role formulas as director.php / coordinator.php /
// statistician.php's own server-rendered badges (kept in sync manually — mirror any change there).
require_once '../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

$role = $_SESSION['role'];

// Plagiarism (item 4) is single-stage: it stays 'Pending' the whole time it's actionable by any of
// Coordinator/Director/Statistician (it never reaches 'Under Review' — see CLAUDE.md), so every
// role counts it against 'Pending' regardless of that role's normal status filter below.
$plag_pending = (int) $pdo->query("
    SELECT COUNT(*) FROM uploads up
    INNER JOIN (
        SELECT user_id, MAX(upload_id) AS max_id FROM uploads WHERE item_id = 4 GROUP BY user_id
    ) latest ON latest.max_id = up.upload_id
    WHERE up.item_id = 4 AND up.verification_status = 'Pending'
")->fetchColumn();

if ($role === 'Statistician') {
    $stats_checklist_pending = (int) $pdo->query("
        SELECT COUNT(*) FROM uploads up
        INNER JOIN (
            SELECT user_id, item_id, MAX(uploaded_at) AS max_date FROM uploads GROUP BY user_id, item_id
        ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
        WHERE up.verification_status = 'Pending' AND up.item_id IN (30,31,32,33,34,35)
    ")->fetchColumn();

    $stats_payment_pending = (int) $pdo->query("
        SELECT COUNT(*) FROM form_stat_treatment
        WHERE status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')
    ")->fetchColumn();

    $stats_release_pending = (int) $pdo->query("
        SELECT COUNT(*) FROM form_stat_treatment WHERE status = 'Phase 7: Statistical Treatment'
    ")->fetchColumn();

    echo json_encode([
        'checklist' => $stats_checklist_pending,
        'payment' => $stats_payment_pending,
        'release' => $stats_release_pending,
        'plag' => $plag_pending,
    ]);
    exit;
}

// Coordinator acts on 'Pending' only. Director outranks Coordinator and wants full pipeline
// visibility the moment a student submits, so Director's badges count 'Pending' (with Coordinator)
// + 'Under Review' (with Director) combined — mirrors director.php's own $pending_counts query.
$status_list = ($role === 'Research Director') ? "'Pending', 'Under Review'" : "'Pending'";
$counts = $pdo->query("
    SELECT
        SUM(CASE WHEN up.item_id IN (11,12,13,14,15,16) THEN 1 ELSE 0 END) as proposal,
        SUM(CASE WHEN up.item_id IN (21,22,23,24,25,26,27) THEN 1 ELSE 0 END) as final,
        SUM(CASE WHEN up.item_id IN (30,31,32,33,34,35,36,37) THEN 1 ELSE 0 END) as stats
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date
        FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    WHERE up.verification_status IN ($status_list)
");
$row = $counts->fetch();

echo json_encode([
    'proposal' => (int) ($row['proposal'] ?? 0),
    'final' => (int) ($row['final'] ?? 0),
    'stats' => (int) ($row['stats'] ?? 0),
    'plag' => $plag_pending,
]);
