<?php
// Read-only JSON endpoint polled by the staff dock to keep the review-queue badges live without a
// full page refresh. Reuses the exact same per-role formulas as director.php / coordinator.php /
// statistician.php's own server-rendered badges (kept in sync manually — mirror any change there).
require_once '../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Research Coordinator', 'Research Director', 'Statistician', 'Admin', 'Focal Person'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

$role = $_SESSION['role'];

// The Admin (2026-09-30) has ONE count: staff accounts waiting for her approval. Same formula as
// admin.php's server-rendered badge (aa_pending_count). Nothing in the workflow waits on her.
if ($role === 'Admin') {
    require_once __DIR__ . '/admin_accounts_lib.php';
    echo json_encode(rd_admin_counts($pdo));   // same as admin.php's server-rendered counts
    exit;
}

// The Focal Person (2026-10-01): her two jobs per phase plus the Unassigned tray — fp_counts(),
// the same function focal.php paints its first render with.
if ($role === 'Focal Person') {
    require_once __DIR__ . '/focal_lib.php';
    echo json_encode(fp_counts($pdo, $role, (int)$_SESSION['user_id']));
    exit;
}

// All four Manuscript desks, shared with the three staff shells so the badge can't say a different
// number depending who logged in (it used to — see nav_badge_lib.php).
require_once __DIR__ . '/nav_badge_lib.php';
require_once __DIR__ . '/admin_accounts_lib.php';   // aa_scope_sql() — a Statistician is institution-scoped
$plag = rd_plag_badge_counts($pdo);
$plag_pending   = $plag['review'];
$plag_pay_count = $plag['payment'];
$plag_verdict   = $plag['verdict'];
$plag_release   = $plag['release'];
// Plagiarism's three steps (2026-09-29): the office's move at each, scoped exactly like the screens.
require_once __DIR__ . '/plag_pipeline_lib.php';
$plag_steps     = pp_badge_counts($pdo, $role, (int)$_SESSION['user_id']);

if ($role === 'Statistician') {
    $stats_checklist_pending = (int) $pdo->query("
        SELECT COUNT(*) FROM uploads up
        INNER JOIN (
            SELECT user_id, item_id, MAX(uploaded_at) AS max_date FROM uploads GROUP BY user_id, item_id
        ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
        WHERE up.verification_status = 'Pending' AND up.item_id IN (30,31,32,33,34,35)
    ")->fetchColumn();

    // Only what the desk can actually act on — see the Ready lane in admin_module_dynamic.php.
    // Institution scope: a Statistician may now cover one institution or both
    // (AA_ALL_INSTITUTIONS). aa_scope_sql() returns the literal '1' for the admin, so this is
    // spliced in unconditionally and the badge can never disagree with her Checklist.
    [$sb_sql, $sb_params] = aa_scope_sql($pdo, $role, (int)$_SESSION['user_id'], 'u');
    $sb_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM form_stat_treatment f
        JOIN users u ON u.user_id = f.user_id
        WHERE f.status = 'Phase 4: Payment Verification'
          AND EXISTS (SELECT 1 FROM uploads up WHERE up.user_id = f.user_id AND up.item_id IN (36, 37))
          AND $sb_sql
    ");
    $sb_stmt->execute($sb_params);
    $stats_payment_pending = (int) $sb_stmt->fetchColumn();

    $sr_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM form_stat_treatment f JOIN users u ON u.user_id = f.user_id
        WHERE f.status = 'Phase 7: Statistical Treatment' AND $sb_sql
    ");
    $sr_stmt->execute($sb_params);
    $stats_release_pending = (int) $sr_stmt->fetchColumn();

    // 'payment'/'release' are the STATISTICS desks; the plag_* keys are the Manuscript ones. Same
    // words, different queues — keep the prefixes or the two sets of badges swap.
    // 'pipeline' is the Pipeline's own 'Your move' band, counted from the rows that screen
    // renders so the dock and the page can never disagree. 'payment'/'release' are kept for
    // now: the desks are retired but other shells still read those keys.
    require_once __DIR__ . '/stats_pipeline_lib.php';
    $pipeline_you = sp_counts(sp_rows($pdo, $sb_sql, $sb_params))['you'];
    echo json_encode([
        'checklist' => $stats_checklist_pending,
        'pipeline' => $pipeline_you,
        'payment' => $stats_payment_pending,
        'release' => $stats_release_pending,
        'plag' => $plag_pending,
        'plag_release' => $plag_release,
        'plag_pay' => $plag_pay_count,
        'plag_cleared' => $plag_verdict,
        'plag_manu' => $plag_steps['manuscript'], 'plag_log' => $plag_steps['payment'], 'plag_check' => $plag_steps['result'],
    ]);
    exit;
}

// Coordinator acts on 'Pending' only. Director outranks Coordinator and wants full pipeline
// visibility the moment a student submits, so Director's badges count 'Pending' (with Coordinator)
// + 'Under Review' (with Director) combined — mirrors director.php's own $pending_counts query.
$status_list = ($role === 'Research Director') ? "'Pending', 'Under Review'" : "'Pending'";
// Institution-scoped like the lists they count (a Coordinator's checklist is aa_scope_sql'd;
// for the Director this is '1'). Unscoped, her badge counted both institutions over her list.
require_once __DIR__ . '/admin_accounts_lib.php';
[$nb_sc_sql, $nb_sc_params] = aa_scope_sql($pdo, $role, (int)$_SESSION['user_id'], 'u');
$counts = $pdo->prepare("
    SELECT
        SUM(CASE WHEN up.item_id IN (11,12,13,14,15,16,17) THEN 1 ELSE 0 END) as proposal,
        SUM(CASE WHEN up.item_id IN (20,22,23,24,25,26,27) THEN 1 ELSE 0 END) as final,
        SUM(CASE WHEN up.item_id IN (30,31,32,33,34,35,36,37) THEN 1 ELSE 0 END) as stats
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date
        FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    JOIN users u ON u.user_id = up.user_id
    WHERE up.verification_status IN ($status_list) AND $nb_sc_sql
");
$counts->execute($nb_sc_params);
$row = $counts->fetch();

// Schedule bundles waiting on THIS role — same helpers the shells render from.
require_once __DIR__ . '/focal_lib.php';
$nb_school = fp_school_scope($pdo, $role, (int)$_SESSION['user_id']);
// Sections this viewer can LOG (Confirmed), never 'Date Selected' bookings — those light up on
// the Focal Person's endorse, while it is still the Coordinator's move. See fp_schedule_badge().
$schedule_pending          = fp_schedule_badge($pdo, $role, 'final', $nb_school);
$schedule_pending_proposal = fp_schedule_badge($pdo, $role, 'proposal', $nb_school);
$approvals_proposal = fp_approvals_badge($pdo, $role, "proposal", $nb_school);
$approvals_final    = fp_approvals_badge($pdo, $role, "final", $nb_school);

echo json_encode([
    'approvals_proposal' => $approvals_proposal,
    'approvals_final' => $approvals_final,
    'proposal' => (int) ($row['proposal'] ?? 0),
    'final' => (int) ($row['final'] ?? 0),
    'stats' => (int) ($row['stats'] ?? 0),
    'plag' => $plag_pending,
    'plag_release' => $plag_release,
    'plag_pay' => $plag_pay_count,
    'plag_cleared' => $plag_verdict,
    'plag_manu' => $plag_steps['manuscript'], 'plag_log' => $plag_steps['payment'], 'plag_check' => $plag_steps['result'],
    'schedule' => $schedule_pending,
    'schedule_proposal' => $schedule_pending_proposal,
]);
