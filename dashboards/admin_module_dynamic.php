<?php
require_once '../config/db.php';
require_once '../config/mail.php';   // milestone mail — sendGroupEmail() / rd_mail_html()
require_once 'defense_lib.php';   // df_application_items() — the Defense application review card
require_once 'stats_pipeline_lib.php';  // sp_rows() / sp_control_prefix() — the statistician's ladder
require_once 'plag_pipeline_lib.php';   // pp_rows() / pp_control_prefix() — the plagiarism ladder, its twin
require_once 'group_roster_lib.php';  // ws_group_rosters() / ws_names_markup() — the "View names" roster
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'admin_accounts_lib.php';
require_once 'staff_home_lib.php';   // sh_activity_phase() — the History screen's Recent card  // aa_scope_sql() / aa_may_touch_student() — course scoping
// THIS SCREEN IS THE OFFICE'S. A Focal Person is deliberately NOT admitted: she reviews on
// focal.php, which is built around her course -> section -> group world. Letting both roles
// share this surface would put her and the Secretary on the same screen with different powers,
// which is exactly the collision to avoid.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director', 'Statistician', 'Admin'])) {
    exit("Access Denied");
}

$role = $_SESSION['role'];
$message = "";

// Group-workspace flow uses Post/Redirect/Get so the reviewer lands back on the group they
// were working in. The success banner survives that redirect in the session.
if (!empty($_SESSION['module_flash'])) {
    $message = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}

$phase = $_GET['phase'] ?? 'proposal';

if ($_SESSION['role'] === 'Statistician' && !in_array($phase, ['stats', 'plag'], true)) {
    exit("Access Denied");
}
// Final carries BOTH of its stages here. Item 20 (Form 002) is the phase 1 requirement — the
// office approving it is what opens the group's schedule panel — and 22-27 are the six documents
// that travel with the date pick in phase 2. They share one Checklist screen because the group
// workspace already buckets by status: 20 shows up under "Needs your review" on its own, and the
// six appear as "Not submitted yet" until phase 2 opens.
$phase_map = [
    'proposal' => ['title' => 'Proposal Defense Validations', 'items' => [17, 11, 13, 14, 15, 16, 12]],
    'final' => ['title' => 'Final Defense Validations', 'items' => [20, 22, 23, 24, 25, 26, 27]],
    'stats' => ['title' => 'Statistics Clearances', 'items' => [30, 31, 32, 33, 34, 35, 36, 37]],
    'plag' => ['title' => 'Plagiarism Scan Clearances', 'items' => [4]],
];

$current_phase = $phase_map[$phase] ?? $phase_map['proposal'];
$item_list = implode(',', $current_phase['items']);

// Defense scheduling (RDC application flow steps 2-3) is its own screen: a booking queue and a slot
// manager, sharing no query, action or layout with the requirement review inbox below. It
// branches out here, before any of that page's work happens.
if (in_array($phase, ['final', 'proposal'], true) && ($_GET['view'] ?? '') === 'schedule') {
    include 'admin_defense_schedule.php';
    exit();
}

// Payment decision audit trail (statistician accept/reject). Self-healing create so the table
// exists on any environment restored from the .sql dumps (no migration runner in this project).
if ($phase === 'stats') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        form_id INT NOT NULL,
        student_id INT NOT NULL,
        actor_id INT NOT NULL,
        action VARCHAR(20) NOT NULL,
        control_no VARCHAR(60) DEFAULT NULL,
        item_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_form (form_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Same self-heal for the Manuscript trail. Normally created by the 2026-08-29 migration; this keeps
// a checkout that skipped it from 500-ing on the History view instead of just showing an empty log.
// No FK on plag_id — reset_handler.php deletes plagiarism_checks rows and must stay order-free.
if ($phase === 'plag') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS plagiarism_payment_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        plag_id INT NOT NULL,
        student_id INT NOT NULL,
        actor_id INT NOT NULL,
        action VARCHAR(20) NOT NULL,
        control_no VARCHAR(60) DEFAULT NULL,
        item_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_plag (plag_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Statistician nav split: statistician.php now links here with a `view` param so its
// Statistics Clearance / Payment Verification / Release Results tabs each show only their
// own section. Coordinator/Director keep linking with no `view` param (defaults to 'all'),
// which preserves today's combined single-page behavior for them.
$stats_view = 'all';
if ($phase === 'stats') {
    $stats_view = $_GET['view'] ?? 'all';
    // RETIRED 2026-09-27: 'payments' and 'release' were two screens showing slices of the ladder
    // the Pipeline now shows whole. The routes stay and redirect rather than 404ing, because the
    // Director's nav still points at view=payments and so will any bookmark.
    if (in_array($stats_view, ['payments', 'release'], true)) $stats_view = 'pipeline';
    if (!in_array($stats_view, ['all', 'checklist', 'history', 'pipeline'], true)) {
        $stats_view = 'all';
    }
}
// Plagiarism sub-nav. Four desks, in the order the office works them:
//   'release'  — the post-defense gate ("Notify" in the nav). Nothing in the system can know the
//                face-to-face defense happened, so this is the one place a human says so.
//   'review'   — is this the right manuscript?
//   'payments' — the finance desk.
//   'cleared'  — the Turnitin verdict.
//
// Three of them hold TWO lists: what still needs doing, and what is already done. `arch=1` is that
// second list, and every screen uses the same word for it so the pattern is learnable — on Notify
// it is who has been told, on Payment it is the decision log, on Cleared it is the finished
// manuscripts. Only 'review' has no archive: a received manuscript moves to another desk entirely.
$plag_view = 'review';
$plag_arch = false;
if ($phase === 'plag') {
    $plag_view = $_GET['view'] ?? 'manuscript';
    // `view=history` was its own nav button for one release. Anything still pointing at it — a
    // bookmark, an open window in the dock — is asking for the payment log, so send it there
    // rather than letting the fallback drop it on Manuscript with no explanation.
    if ($plag_view === 'history') {
        header('Location: admin_module_dynamic.php?phase=plag&view=payments&arch=1');
        exit;
    }
    // THREE STEPS, ONE SCREEN EACH (2026-09-29, Claude Design concept): manuscript · payment ·
    // result. Every older plagiarism view REDIRECTS to its step rather than 404 — open windows and
    // bookmarks still point at them: review → manuscript, payments → payment (its arch=1 is the
    // History log and stays), cleared → result, and the short-lived `progress` → manuscript.
    // GET only; after a POST the same mapping just renders the step, so a decision sent from a
    // page opened before the switch is still processed.
    $pz_map = ['review' => 'manuscript', 'cleared' => 'result', 'progress' => 'manuscript'];
    if (!in_array($plag_view, ['manuscript', 'payment', 'result', 'payments', 'release', 'review', 'cleared', 'progress'], true)) $plag_view = 'manuscript';
    $plag_arch = ($_GET['arch'] ?? '') === '1' && in_array($plag_view, ['payments', 'release'], true);
    $pz_to = $pz_map[$plag_view] ?? (($plag_view === 'payments' && !$plag_arch) ? 'payment' : null);
    if ($pz_to !== null) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Location: admin_module_dynamic.php?phase=plag&view=' . $pz_to);
            exit;
        }
        $plag_view = $pz_to;
    }
}

// The audit log is a POS-style record across every group, not a group-scoped review queue — the
// group sidebar and the search/department/program filter bar don't apply to it (they don't touch
// the log query at all), so both are hidden wherever it renders.
//
// Both phases have one, and neither has its own nav entry any more: the log IS the payment desk's
// "already done" list, so it lives behind Payment's archive pill rather than a fifth button that
// only ever answered a payment question. Statistics logs its payment desk to `payment_logs`;
// Manuscript logs its WHOLE flow — release, receive/return, payment, Turnitin verdict — to
// `plagiarism_payment_logs`, because the office re-checks a clearance, not a till receipt.
$is_history_view = ($phase === 'stats' && $stats_view === 'history')
                || ($phase === 'plag' && $plag_view === 'payments' && $plag_arch);
// Notify (the manuscript call) is its own screen with its own header, search and tabs; the
// group-queue filter bar and sidebar never touched its query, so they are hidden there too.
$is_notify_view = ($phase === 'plag' && $plag_view === 'release');
// The three plagiarism steps carry their own header, search and list — same reason.
$is_pz_view = ($phase === 'plag' && in_array($plag_view, ['manuscript', 'payment', 'result'], true));
$hist_is_plag = ($phase === 'plag');
$hist_table   = $hist_is_plag ? 'plagiarism_payment_logs' : 'payment_logs';
$hist_doc_names = $hist_is_plag
    ? [4 => 'Manuscript', 40 => 'Turnitin Report', 41 => 'Validated Form', 42 => 'Official Receipt']
    : [36 => 'Validated Form', 37 => 'Official Receipt'];
$hist_base    = $hist_is_plag
    ? 'admin_module_dynamic.php?phase=plag&view=payments&arch=1'
    : 'admin_module_dynamic.php?phase=stats&view=history';
$hist_actor_fallback = $hist_is_plag ? 'Research Office' : 'Statistician';

// Every action the log can hold, in one place: label, tone, and whether it counts as a "pass" for
// the summary widgets. Statistics only ever writes accepted/rejected; Manuscript writes all eight.
$hist_actions = [
    'released' => ['label' => 'Released',        'icon' => 'send',        'tone' => 'ok'],
    'received' => ['label' => 'Received',        'icon' => 'inbox',       'tone' => 'ok'],
    'returned' => ['label' => 'Sent back',       'icon' => 'undo-2',      'tone' => 'no'],
    'accepted' => ['label' => 'Accepted',        'icon' => 'badge-check', 'tone' => 'ok'],
    'rejected' => ['label' => 'Rejected',        'icon' => 'x-circle',    'tone' => 'no'],
    'cleared'  => ['label' => 'Cleared',         'icon' => 'shield-check','tone' => 'ok'],
    'revision' => ['label' => 'Revision',        'icon' => 'file-warning','tone' => 'no'],
    'report'   => ['label' => 'Report replaced', 'icon' => 'refresh-cw',  'tone' => 'neutral'],
    // Payment-desk housekeeping. Deliberately 'neutral': the accepted/rejected summary tiles
    // match on explicit action names, so these are counted in the total and in neither column.
    'parked'   => ['label' => 'Parked',          'icon' => 'pause-circle','tone' => 'neutral'],
    'unparked' => ['label' => 'Un-parked',       'icon' => 'play-circle', 'tone' => 'neutral'],
    'nudged'   => ['label' => 'Reminder sent',   'icon' => 'bell-ring',   'tone' => 'neutral'],
];

// History filters (month/year) + CSV export. Parsed here — before any HTML output — so the
// export branch can send download headers. Shared with the display query further down so the
// exported file always matches exactly what's on screen.
$hist_month = 0;
$hist_year = 0;
if ($is_history_view) {
    $hist_month = (int) ($_GET['hist_month'] ?? 0);
    if ($hist_month < 1 || $hist_month > 12) $hist_month = 0;
    $hist_year = (int) ($_GET['hist_year'] ?? 0);
    if ($hist_year < 2000 || $hist_year > 2100) $hist_year = 0;
}
// A log that only grows is searched, not browsed: once it runs to a thousand rows "which month
// was that" is a worse question than the group's name. Matches the group, the control number
// and whoever decided — the three things anyone actually looks a row up by.
$hist_q = trim((string)($_GET['hist_q'] ?? ''));
$hist_where = '';
$hist_params = [];
if ($hist_month) { $hist_where .= ' AND MONTH(pl.created_at) = ?'; $hist_params[] = $hist_month; }
if ($hist_year) { $hist_where .= ' AND YEAR(pl.created_at) = ?'; $hist_params[] = $hist_year; }
if ($hist_q !== '') {
    $hist_where .= ' AND (u.research_group_name LIKE ? OR pl.control_no LIKE ? OR actor.username LIKE ?)';
    $hq = '%' . $hist_q . '%';
    array_push($hist_params, $hq, $hq, $hq);
}
// Institution scope, like every other list here. The log was unscoped, so a Coordinator or a
// per-institution Statistician read BOTH institutions' decisions. Every query below joins the
// student as `u`, so this one clause covers the month index, the entries and the CSV export.
if ($is_history_view) {
    [$hist_scope_sql, $hist_scope_params] = aa_scope_sql($pdo, $role, (int)$_SESSION['user_id'], 'u');
    $hist_where .= ' AND ' . $hist_scope_sql;
    $hist_params = array_merge($hist_params, $hist_scope_params);
}
// The three pills over the log are FILTERS: Moved forward / Sent back narrow the months, the
// entries and the export. Kept OUT of $hist_where on purpose — the pills' own numbers are counted
// without it, so picking "Sent back" never turns the other two pills into zeros.
$hist_act_sets = [
    'ok' => "('accepted','received','released','cleared')",
    'no' => "('rejected','returned','revision')",
    'rem' => "('nudged')",
];
$hist_act = (string)($_GET['hist_act'] ?? '');
if (!isset($hist_act_sets[$hist_act])) $hist_act = '';
$hist_act_sql = $hist_act !== '' ? ' AND pl.action IN ' . $hist_act_sets[$hist_act] : '';

if ($is_history_view && ($_GET['export'] ?? '') === 'csv') {
    $export_stmt = $pdo->prepare("
        SELECT pl.action, pl.control_no, pl.item_id, pl.remarks, pl.created_at,
               u.research_group_name, actor.username AS actor_name
        FROM $hist_table pl
        LEFT JOIN users u ON pl.student_id = u.user_id
        LEFT JOIN users actor ON pl.actor_id = actor.user_id
        WHERE 1=1 $hist_where $hist_act_sql
        ORDER BY pl.created_at DESC
    ");
    $export_stmt->execute($hist_params);
    $export_rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    $export_doc_names = $hist_doc_names;

    header('Content-Type: text/csv; charset=utf-8');
    $fname = ($hist_is_plag ? 'manuscript_history' : 'payment_history') . ($hist_year ? '_' . $hist_year : '') . ($hist_month ? '_' . str_pad($hist_month, 2, '0', STR_PAD_LEFT) : '') . '.csv';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Time', 'Decision', 'Research Group', 'Control No.', 'Document', 'Remarks', 'Decided By']);
    foreach ($export_rows as $r) {
        $ts = strtotime($r['created_at']);
        fputcsv($out, [
            date('Y-m-d', $ts),
            date('h:i A', $ts),
            $hist_actions[$r['action']]['label'] ?? ucfirst($r['action']),
            $r['research_group_name'] ?? 'Unknown group',
            $r['control_no'] ?? '',
            $export_doc_names[$r['item_id']] ?? '',
            $r['remarks'] ?? '',
            $r['actor_name'] ?? 'Statistician',
        ]);
    }
    fclose($out);
    exit;
}

// Define Form No. 008 Assessment Rubric Structure Natively
$rubric_stats_sections = [
    "Deliverables Quality" => [
        "s1" => "Is the Statement of the Problem clear and well-defined?",
        "s2" => "Is the sample questionnaire correctly formatted and appropriate for the objectives?",
        "s3" => "Is the coded data in Excel complete and properly labeled?",
        "s4" => "Is the Communication Letter signed and approved by necessary authorities?",
        "s5" => "Are the Minutes of the Meeting coherent and accurately reflect the group's progress?"
    ],
    "Statistical Compliance" => [
        "s6" => "Are the statistical methods aligned with the proposed research design?",
        "s7" => "Are payment records and OR numbers validated?"
    ]
];

/**
 * Group-workspace Post/Redirect/Get.
 *
 * Historically every decision fell through and re-rendered the page, which reset the whole
 * client view — the reviewer was dumped back at the top of the group list after each approval.
 * Callers that came from a group workspace post a `return_group`; they get redirected back to
 * that group instead. Callers that don't post it (the By-requirement view, stats, plagiarism,
 * payments) return false and fall through exactly as before.
 *
 * Must be called before any HTML output — every handler in this file runs well above the markup.
 */
function redirectToGroupWorkspace($message)
{
    // Only requests that actually came from a workspace redirect back into one. Callers that
    // post neither field (the plagiarism queue, the payment queue) fall through as before.
    $hasGroup = !empty($_POST['return_group']);
    $hasItem  = !empty($_POST['return_item']);
    if (!$hasGroup && !$hasItem) {
        return false;
    }
    $_SESSION['module_flash'] = $message;
    $query = $_GET;                     // keep phase/view/department/program/search/sort_date
    unset($query['group'], $query['item'], $query['open_item']);
    if ($hasGroup) {
        $query['group'] = (int)$_POST['return_group'];
    } else {
        $query['item'] = (int)$_POST['return_item'];
    }
    if (!empty($_POST['open_form008'])) {
        $query['open_item'] = 14;       // pop the Form 008 rubric as the next step
    }
    header('Location: admin_module_dynamic.php?' . http_build_query($query));
    exit();
}

/**
 * Apply one review decision, with every side effect that hangs off it.
 *
 * Extracted so the single-document drawer and the batch approvals share ONE implementation.
 * Stats items in particular carry heavy consequences — form_stat_treatment phase transitions,
 * the payment rollback, the student email — and item 14 carries the cascade. A second copy of
 * that logic for the batch path would drift the moment either side changed.
 *
 * $ctx keys: upload_id, student_user_id, item_id, req_name, status (the DECISION, pre-downgrade),
 *            remarks, role. Optional 'rubric' => ['data' => json|null, 'score' => int,
 *            'decision' => string] — omit it entirely to leave the form_008_* columns alone
 *            (the batch path does; it must never blank a stored rubric).
 *
 * Returns ['upload_status' => the value actually written, 'cascaded' => bool].
 */
function applyReviewDecision(PDO $pdo, array $ctx)
{
    $item_id  = (int)$ctx['item_id'];
    $status   = $ctx['status'];
    $remarks  = $ctx['remarks'];
    $role     = $ctx['role'];
    $upload_id       = $ctx['upload_id'];
    $student_user_id = $ctx['student_user_id'];
    $req_name        = $ctx['req_name'];

    // Scope, enforced at the one place every decision passes through. LIVE since 2026-09-26:
    // a Statistician is institution-scoped (AA_SCOPED_ROLES), so a posted upload_id belonging to
    // the other institution is refused here rather than merely absent from her page. Hiding a
    // group from a list is not the same as protecting it.
    $actor_id = (int)($_SESSION['user_id'] ?? 0);
    if (!aa_may_touch_student($pdo, $role, $actor_id, (int)$student_user_id)) {
        exit('Access Denied');
    }

    // Single-stage: Coordinator and Director hold the same power, so an approval is an approval
    // whoever makes it. (This used to downgrade a Coordinator's Approve to 'Under Review' to
    // queue it for the Director; there is no such chain — see the RDC roles note.)
    $upload_status = $status;

    if (array_key_exists('rubric', $ctx)) {
        $stmt = $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ?, verified_by = ?, verified_at = NOW(), form_008_data = ?, form_008_score = ?, form_008_decision = ? WHERE upload_id = ?");
        $stmt->execute([$upload_status, $remarks, $actor_id, $ctx['rubric']['data'], $ctx['rubric']['score'], $ctx['rubric']['decision'], $upload_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ?, verified_by = ?, verified_at = NOW() WHERE upload_id = ?");
        $stmt->execute([$upload_status, $remarks, $actor_id, $upload_id]);
    }

    // ---- Statistical treatment consequences (items 30-37) --------------------------------
    if (in_array($item_id, [30, 31, 32, 33, 34, 35, 36, 37])) {
        $f_stmt = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
        $f_stmt->execute([$student_user_id]);
        $f_data = $f_stmt->fetch();

        if ($f_data) {
            $form_id = $f_data['form_id'];

            if ($item_id === 30) {
                // Initial Data Approval -> Move to Phase 2 Form Download
                $new_state = ($upload_status === 'Approved') ? 'Phase 2: Form Download' : (($upload_status === 'Revision Requested' || $upload_status === 'revision') ? 'Phase 1: Coded Data Rejected' : 'Phase 1: Pending Coded Data');
                $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = ?, statistician_remarks = ? WHERE form_id = ?");
                $upd_stmt->execute([$new_state, $remarks, $form_id]);
            } elseif (in_array($item_id, [31, 32, 33, 34, 35])) {
                if ($upload_status === 'Revision Requested') {
                    // Flag the whole request so the student sees the revision alert
                    $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 6: Revision Requested', statistician_remarks = ? WHERE form_id = ?");
                    $upd_stmt->execute([$remarks, $form_id]);
                } else {
                    // All 5 approved (checking the LATEST version of each item, since older
                    // reviewed versions remain as history) -> move into processing, not Completed;
                    // the request completes when the statistician releases the result file.
                    $stmt_latest = $pdo->prepare("SELECT COUNT(*) FROM uploads u
                        INNER JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id IN (31,32,33,34,35) GROUP BY item_id) latest
                        ON latest.max_id = u.upload_id
                        WHERE u.verification_status = 'Approved'");
                    $stmt_latest->execute([$student_user_id]);
                    if ($stmt_latest->fetchColumn() == 5) {
                        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 7: Statistical Treatment', statistician_remarks = ? WHERE form_id = ?");
                        $upd_stmt->execute([$remarks, $form_id]);
                    } else {
                        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ? WHERE form_id = ?");
                        $upd_stmt->execute([$remarks, $form_id]);
                    }
                }
            } elseif (in_array($item_id, [36, 37])) {
                $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ? WHERE form_id = ?")->execute([$remarks, $form_id]);
                if ($upload_status === 'Revision Requested') {
                    // Rejected payment document: send the group back to Phase 2 so the
                    // upload cards unlock and they can submit a corrected file.
                    $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 2: Form Download' WHERE form_id = ? AND status = 'Phase 4: Payment Verification'")->execute([$form_id]);

                    // Audit trail: who rejected which payment document, and why.
                    $pdo->prepare("INSERT INTO payment_logs (form_id, student_id, actor_id, action, item_id, remarks, created_at) VALUES (?, ?, ?, 'rejected', ?, ?, CURRENT_TIMESTAMP)")
                        ->execute([$form_id, $student_user_id, $_SESSION['user_id'], $item_id, $remarks]);
                }
            }

            // No email here on purpose. This fires on EVERY statistics document decision, which is
            // routine review traffic rather than a milestone — mailing it would put a message in
            // their inbox for each of eight documents. It already lands in the bell and the
            // activity feed. (What used to sit here was PHP mail() behind an @, which XAMPP has no
            // MTA for, so it silently sent nothing; removing it changes nothing observable.)
            // Milestones that DO send: control number issued, results released — see below.
        }
    }

    // ---- MILESTONE AUTO-LINKING CASCADE: item 14 (Capsule Proposal) clears 13/15/16 -------
    $cascaded = false;
    if ($item_id === 14 && $upload_status === 'Approved') {
        $cascade_items = [13, 15, 16]; // Auto-clear Form 008 (13), Data Gathering (15), and Lit Matrix (16)
        foreach ($cascade_items as $c_item) {
            $check_stmt = $pdo->prepare("SELECT upload_id FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
            $check_stmt->execute([$student_user_id, $c_item]);
            $existing_upload_id = $check_stmt->fetchColumn();

            if ($existing_upload_id) {
                $up_stmt = $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Automatically verified via Capsule Proposal Approval Matrix (Form No. 008).', verified_by = ?, verified_at = NOW() WHERE upload_id = ?");
                $up_stmt->execute([$actor_id, $existing_upload_id]);
            } else {
                $ins_stmt = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status, verified_by, verified_at, remarks, uploaded_at) VALUES (?, ?, '../uploads/cascaded_clearance.pdf', 'Cascaded_Institutional_Verification.pdf', 'Approved', ?, NOW(), 'Bundled milestone validation completed natively inside Capsule Proposal Form No. 008 approval.', CURRENT_TIMESTAMP)");
                $ins_stmt->execute([$student_user_id, $c_item, $actor_id]);
            }
        }
        $cascaded = true;
    }

    // ---- Notify the student ---------------------------------------------------------------
    // Always reports the DECISION, not the endorsed-to-director state, so a student's feed reads
    // the same however the reviewer happened to clear the requirement.
    // No score to quote any more — item 14 is an ordinary document review.
    $log_title = "Document Review - " . $status;
    $log_desc  = "Your " . htmlspecialchars($req_name) . " has been reviewed by the " . $role . ". Status: " . $status . ".";

    $log_status = ($status === 'Approved') ? 'success' : 'warning';
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_user_id, $upload_id, $log_title, $log_desc, $log_status]);

    return ['upload_status' => $upload_status, 'cascaded' => $cascaded];
}

// Process Document Verifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'verify_upload') {
    // CSRF protection: reject forged reviews. Now covers every phase — `final` used to be
    // exempt for no good reason, and the only form that posts here (#modalForm) has always
    // carried the token, so closing the gap breaks nothing.
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $upload_id = $_POST['upload_id'];
    $status = $_POST['verification_status'];
    $remarks = trim($_POST['remarks']);
    $student_user_id = $_POST['student_user_id'];
    $req_name = $_POST['req_name'] ?? 'Document';
    $target_item_id = (int)($_POST['target_item_id'] ?? 0);

    // Form No. 008 / Form No. 011 Rubric Payload Elements Process Handling
    $rubric_payload = $_POST['rubric'] ?? [];
    $yes_count = 0;

    foreach ($rubric_payload as $q) {
        if (($q['val'] ?? '') === 'YES') {
            $yes_count++;
        }
    }

    // Mapping Institutional Guidelines Rules Set Criteria
    $decision_string = "Deferred";

    if ($target_item_id === 3) {
        // Stats Form 011 has 7 items
        if ($yes_count === 7) {
            $decision_string = "Accepted with no revision";
        } elseif ($yes_count >= 5) {
            $decision_string = "Accepted with minor revision";
        } elseif ($yes_count >= 2) {
            $decision_string = "Accepted with major revision";
        }
    } else {
        // Capsule Form 008 has 22 items
        if ($yes_count === 22) {
            $decision_string = "Accepted with no revision";
        } elseif ($yes_count >= 15) {
            $decision_string = "Accepted with minor revision";
        } elseif ($yes_count >= 8) {
            $decision_string = "Accepted with major revision";
        }
    }

    $json_rubric_data = !empty($rubric_payload) ? json_encode($rubric_payload) : null;

    // Plagiarism clearance can only be decided via the dedicated Plagiarism Review & Clearance
    // section (plag_accept/plag_revise below), which requires a Turnitin report — this generic
    // modal must never be able to set item 4 to Approved directly.
    if ($target_item_id === 4 && $status === 'Approved') {
        exit('Plagiarism clearance must be decided via the Plagiarism Review & Clearance section (requires a Turnitin report).');
    }

    // One shared implementation for single and batch decisions — see applyReviewDecision().
    $review_ctx = [
        "upload_id"       => $upload_id,
        "student_user_id" => $student_user_id,
        "item_id"         => $target_item_id,
        "req_name"        => $req_name,
        "status"          => $status,
        "remarks"         => $remarks,
        "role"            => $role,
    ];
    // Only when a rubric was actually submitted (Form 011 / stats still uses one). Passing it
    // unconditionally wrote NULL/0/'Deferred' into form_008_* on every save, which blanked a
    // stored score as soon as anyone re-approved the document. See applyReviewDecision().
    if (!empty($rubric_payload)) {
        $review_ctx["rubric"] = [
            "data"     => $json_rubric_data,
            "score"    => $yes_count,
            "decision" => $decision_string,
        ];
    }
    // THE SECOND DOOR TO REGISTRATION. Approving the Official Receipt of a group still awaiting
    // registration IS registering it: the drawer asks for the control number (prefix pre-typed)
    // and the same sp_register_payment() the Progress composer uses mints it. A send-back needs no
    // number. Refused, not just hidden, without one — an approved receipt with no number would
    // leave the group stuck at Phase 4 looking finished.
    $cn_form = null;
    $cn_seq  = trim($_POST['control_no'] ?? '');
    if ($target_item_id === 37 && $status === 'Approved' && $role === 'Statistician') {
        $cn_q = $pdo->prepare("SELECT form_id FROM form_stat_treatment WHERE user_id = ?
                               AND status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')
                               ORDER BY date_submitted DESC LIMIT 1");
        $cn_q->execute([(int)$student_user_id]);
        $cn_form = $cn_q->fetchColumn() ?: null;
    }

    if ($cn_form && $cn_seq === '') {
        $message = "Type the control number to approve the Official Receipt — approving it registers the payment.";
        redirectToGroupWorkspace($message);
    } else {
        $outcome = applyReviewDecision($pdo, $review_ctx);   // scope-checked in there

        $message = "Review saved.";
        if ($outcome["cascaded"]) {
            $message .= " Milestone items 13, 15, and 16 have been auto-cleared and locked.";
        }
        if ($cn_form) {
            $minted = sp_register_payment($pdo, $cn_form, (int)$student_user_id, $cn_seq);
            $message = $minted
                ? "Receipt approved and payment registered. Control No: $minted"
                : "Receipt approved, but the request was no longer awaiting registration.";
        }
        redirectToGroupWorkspace($message);
    }
}

// Bulk verification from a group workspace: approve several requirements of ONE group in a
// single action. Everything the UI offers is re-checked here — posted ids are re-read from the
// database and anything that isn't legitimately approvable by this role right now is skipped.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'bulk_verify') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }

    // Two scoping modes, mirroring the two workspaces:
    //   return_group -> one group's several requirements (By group)
    //   return_item  -> one requirement across several groups (By requirement)
    $bulk_group_id = (int)($_POST['return_group'] ?? 0);
    $bulk_item_id  = (int)($_POST['return_item'] ?? 0);
    $bulk_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['upload_ids'] ?? [])))));
    $bulk_remarks = trim($_POST['remarks'] ?? '');

    // Items that can never be cleared by a checkbox:
    //   4 - Plagiarism, decided only via the dedicated clearance section (needs a Turnitin report).
    //  13/15/16 - cascade children, auto-cleared when 14 is approved.
    $bulk_blocked_items = [4, 13, 15, 16];

    // Anything still in flight is actionable by any reviewing role. 'Under Review' only appears on
    // rows written before the endorsement chain was removed, and must stay decidable.
    $bulk_source_states = ['Pending', 'Under Review'];

    $bulk_done = 0;
    $bulk_skipped = 0;

    if (($bulk_group_id > 0 || $bulk_item_id > 0) && !empty($bulk_ids)) {
        // Re-read from the database, scoped to the workspace the request came from, so forged,
        // stale or out-of-scope ids drop out before anything is written.
        $placeholders = implode(',', array_fill(0, count($bulk_ids), '?'));
        $bulk_scope_sql = $bulk_group_id > 0 ? 'up.user_id = ?' : 'up.item_id = ?';
        $bulk_scope_val = $bulk_group_id > 0 ? $bulk_group_id : $bulk_item_id;

        $bulk_stmt = $pdo->prepare("SELECT up.upload_id, up.user_id, up.item_id, up.verification_status, ci.item_name
            FROM uploads up
            LEFT JOIN checklist_items ci ON ci.item_id = up.item_id
            WHERE up.upload_id IN ($placeholders) AND $bulk_scope_sql");
        $bulk_stmt->execute(array_merge($bulk_ids, [$bulk_scope_val]));
        $bulk_rows = $bulk_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bulk_rows as $row) {
            $r_item = (int)$row['item_id'];
            // Out of phase, rubric-gated, or not in the state this role may act on.
            if (!in_array($r_item, $current_phase['items'], true)
                || in_array($r_item, $bulk_blocked_items, true)
                || !in_array($row['verification_status'], $bulk_source_states, true)) {
                $bulk_skipped++;
                continue;
            }

            // Same code path as the drawer, so stats phase transitions and the student email
            // fire identically. No 'rubric' key: the batch must never blank a stored Form 008.
            applyReviewDecision($pdo, [
                'upload_id'       => $row['upload_id'],
                'student_user_id' => $row['user_id'],
                'item_id'         => $r_item,
                'req_name'        => $row['item_name'] ?: ('Requirement ' . $r_item),
                'status'          => 'Approved',
                'remarks'         => $bulk_remarks,
                'role'            => $role,
            ]);
            $bulk_done++;
        }
    }

    $bulk_verb = 'Approved';
    if ($bulk_done > 0) {
        $bulk_noun = $bulk_item_id > 0 ? 'group' : 'requirement';
        $message = $bulk_verb . ' ' . $bulk_done . ' ' . $bulk_noun . ($bulk_done === 1 ? '' : 's') . '.';
    } else {
        $message = 'Nothing was updated — those submissions are no longer awaiting your review.';
    }
    if ($bulk_skipped > 0) {
        $message .= ' ' . $bulk_skipped . ' skipped (already decided, or needs its own review form).';
    }
    if (!empty($_POST['open_form008'])) {
        $message .= ' Opening the Capsule Proposal for Form 008 review.';
    }

    redirectToGroupWorkspace($message);
}

// ── PARK / UNPARK / NUDGE — the payment desk's only exits that are not "the student paid" ──
// All three write to payment_logs and nothing else: park state is DERIVED from the latest
// parked/unparked row per form (see the desk query below), so there is no schema change and no
// state to fall out of sync. Nothing here touches form_stat_treatment.status — a parked group
// is still exactly where it was in the workflow, it has just left this screen's active lanes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])
    && in_array($_POST['action_type'], ['park_payment', 'unpark_payment', 'nudge_payment'], true)) {
    if ($role !== 'Statistician') {
        $message = "Only the Statistician can manage the payment queue.";
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $message = "Security token mismatch. Please try again.";
    } else {
        $pk_form    = (int)($_POST['form_id'] ?? 0);
        $pk_student = (int)($_POST['student_id'] ?? 0);
        $pk_act     = $_POST['action_type'];

        // Only act on a row that is genuinely sitting on this desk, and take the student id from
        // the table rather than the form post so a tampered field cannot log against someone else.
        $pk_row = $pdo->prepare("SELECT f.form_id, f.user_id, f.status, u.research_group_name
                                 FROM form_stat_treatment f JOIN users u ON u.user_id = f.user_id
                                 WHERE f.form_id = ? AND f.status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')");
        $pk_row->execute([$pk_form]);
        $pk_row = $pk_row->fetch(PDO::FETCH_ASSOC);

        if (!$pk_row) {
            $message = "That request is no longer awaiting payment.";
        } elseif (!aa_may_touch_student($pdo, $role, (int)$_SESSION['user_id'], (int)$pk_row['user_id'])) {
            // Institution scope — a hidden row is not a protected row.
            $message = "That group is not in your institution.";
        } else {
            $pk_student = (int)$pk_row['user_id'];
            $pk_name    = $pk_row['research_group_name'];

            if ($pk_act === 'nudge_payment') {
                $pk_note = trim($_POST['remarks'] ?? '') ?: null;
                sendGroupEmail(
                    $pdo,
                    $pk_student,
                    'Reminder: your statistical treatment payment',
                    rd_mail_html(
                        'Your payment is still outstanding',
                        array_filter([
                            'The Research Office has not yet recorded a payment for your statistical '
                                . 'treatment request, so your control number cannot be issued yet.',
                            'Settle it at the Finance Office, then upload the validated Form 011 and the '
                                . 'Official Receipt in your Statistics card — or present the receipt at the '
                                . 'Research Office and it will be recorded for you.',
                            $pk_note,
                        ])
                    )
                );
                $pdo->prepare("INSERT INTO payment_logs (form_id, student_id, actor_id, action, remarks, created_at) VALUES (?, ?, ?, 'nudged', ?, CURRENT_TIMESTAMP)")
                    ->execute([$pk_form, $pk_student, $_SESSION['user_id'], $pk_note]);
                $message = "Reminder sent to " . $pk_name . ".";
            } else {
                $pk_is_park = ($pk_act === 'park_payment');
                $pk_note = trim($_POST['remarks'] ?? '') ?: null;
                $pdo->prepare("INSERT INTO payment_logs (form_id, student_id, actor_id, action, remarks, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
                    ->execute([$pk_form, $pk_student, $_SESSION['user_id'], $pk_is_park ? 'parked' : 'unparked', $pk_note]);
                $message = $pk_is_park
                    ? $pk_name . " parked — it will reappear the moment they upload a receipt."
                    : $pk_name . " is back on the active queue.";
            }
        }
    }
}

/**
 * Register a statistics payment: mint the control number, move the request to Phase 5, clear the
 * pending payment documents, rename them, log, and mail the group. ONE implementation for both
 * doors — the Progress composer (acknowledge_payment) and approving the Official Receipt in the
 * review drawer (verify_upload, item 37). Returns the minted number, or null when the request is
 * not awaiting registration (already registered, or not at Phase 2/4). Caller has already
 * checked role, CSRF and institution scope.
 */
function sp_register_payment(PDO $pdo, $form_id, int $student_id, string $sequence_no): ?string
{
    $sequence_no = trim($sequence_no);
    if ($sequence_no === '') return null;

    // Get student department and program to auto-generate prefix
    $stmt_s = $pdo->prepare("SELECT department, program FROM users WHERE user_id = ?");
    $stmt_s->execute([$student_id]);
    $s_info = $stmt_s->fetch();

    // The prefix she does not choose — year, institution, course code — comes from
    // sp_control_prefix(), the same function the composer previews with. Two copies of
    // this would drift, and a wrong preview of a permanent identifier is worse than none.
    $full_control_no = sp_control_prefix($s_info['program'] ?? '')
                     . str_pad($sequence_no, 3, "0", STR_PAD_LEFT);

    // Only registrable while awaiting/verifying payment (guards double registration)
    $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 5: Registered', formatted_control_no = ?
                               WHERE form_id = ? AND status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')");
    $upd_stmt->execute([$full_control_no, $form_id]);

    if ($upd_stmt->rowCount() > 0) {
        // Payment documents uploaded in-system are implicitly verified by registering
        $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Verified during official registration.'
                       WHERE user_id = ? AND item_id IN (36, 37) AND verification_status = 'Pending'")
            ->execute([$student_id]);

        // Auto-rename the LATEST upload of each processed document to the official
        // control-number format. Older reviewed versions stay untouched as history
        // (renaming them too would collide on identical target filenames).
        $rename_labels = [30 => 'InitialData', 36 => 'ValidatedForm', 37 => 'Receipt'];
        $stmt_up = $pdo->prepare("SELECT u.upload_id, u.item_id, u.file_path FROM uploads u
            INNER JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id IN (30, 36, 37) GROUP BY item_id) latest
            ON latest.max_id = u.upload_id");
        $stmt_up->execute([$student_id]);

        foreach ($stmt_up->fetchAll() as $up) {
            $old_path = $up['file_path'];
            if (!file_exists($old_path)) continue;
            $ext = pathinfo($old_path, PATHINFO_EXTENSION);
            $new_filename = $full_control_no . "_" . $rename_labels[$up['item_id']] . "." . $ext;
            $new_path = dirname($old_path) . '/' . $new_filename;

            if ($old_path !== $new_path && rename($old_path, $new_path)) {
                $pdo->prepare("UPDATE uploads SET file_path = ?, original_filename = ? WHERE upload_id = ?")
                    ->execute([$new_path, $new_filename, $up['upload_id']]);
                if ((int)$up['item_id'] === 30) {
                    $pdo->prepare("UPDATE form_stat_treatment SET file_coded_data = ? WHERE form_id = ?")
                        ->execute([$new_path, $form_id]);
                }
            }
        }

        // Notify student
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Payment Acknowledged (Registered)', ?, 'success', CURRENT_TIMESTAMP)");
        $log_stmt->execute([$student_id, "Your statistical treatment payment has been acknowledged. Your Control Number is: $full_control_no. You may now upload the remaining deliverables in Phase 6."]);

        // Audit trail: who registered this payment, when, and under what control number.
        $pdo->prepare("INSERT INTO payment_logs (form_id, student_id, actor_id, action, control_no, created_at) VALUES (?, ?, ?, 'accepted', ?, CURRENT_TIMESTAMP)")
            ->execute([$form_id, $student_id, $_SESSION['user_id'], $full_control_no]);

        // Same milestone as the plagiarism one: the number they will be asked to quote.
        sendGroupEmail(
            $pdo,
            (int)$student_id,
            'Statistical treatment payment verified — ' . $full_control_no,
            rd_mail_html(
                'Your payment has been verified',
                [
                    'The statistician has recorded your statistical treatment payment. This is your '
                        . 'official control number — keep it, you will be asked for it.',
                    'You may now upload your remaining deliverables in the Statistics card.',
                ],
                $full_control_no
            )
        );

        return $full_control_no;
    }
    return null;
}

// ── NUDGE FOCAL / REMIND GROUP (Checklist, 2026-09-29) ─────────────────────────────────────────
// Two reminders the Checklist offers. Email only — they change no status. Scoped like every
// write here (aa_may_touch_student), and once per target per 6 hours per person, so a double tap
// is not two emails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action_type'] ?? '', ['nudge_focal', 'remind_upload'], true)) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $rm_gid = (int)($_POST['group_id'] ?? 0);
    if (!$rm_gid || !aa_may_touch_student($pdo, $role, (int)($_SESSION['user_id'] ?? 0), $rm_gid)) exit('Access Denied');
    $rm_g = $pdo->prepare("SELECT research_group_name, department, program FROM users WHERE user_id = ?");
    $rm_g->execute([$rm_gid]);
    $rm_g = $rm_g->fetch(PDO::FETCH_ASSOC);
    $rm_key = $_POST['action_type'] . ':' . $rm_gid . ':' . (int)($_POST['item_id'] ?? 0);
    if (!$rm_g) {
        $message = 'That group no longer exists.';
    } elseif (time() - ($_SESSION['ck_reminded'][$rm_key] ?? 0) < 6 * 3600) {
        $message = 'Already sent — give them a few hours.';
    } elseif ($_POST['action_type'] === 'nudge_focal') {
        $fp = $pdo->prepare("SELECT DISTINCT u.username, u.email FROM focal_scope fs JOIN users u ON u.user_id = fs.user_id
                             WHERE fs.department = ? AND fs.program = ? AND u.role = 'Focal Person' AND u.is_verified = 1 AND u.email <> ''");
        $fp->execute([$rm_g['department'], $rm_g['program']]);
        $fp = $fp->fetchAll(PDO::FETCH_ASSOC);
        if (!$fp) {
            $message = 'No focal person covers ' . $rm_g['program'] . ' yet.';
        } else {
            $html = rd_mail_html('Requirements are waiting for your review', [
                $rm_g['research_group_name'] . ' (' . $rm_g['program'] . ') has requirements waiting for your review in iSubmit.',
                'Open your Requirements in the Focal Person portal to check them.',
            ]);
            $sent = 0;
            foreach ($fp as $p) if (sendSystemEmail($p['email'], 'Reminder: requirements waiting for your review', $html)) $sent++;
            if ($sent) { $_SESSION['ck_reminded'][$rm_key] = time(); $message = 'Nudged ' . implode(', ', array_column($fp, 'username')) . '.'; }
            else $message = 'The reminder could not be sent. Please try again.';
        }
    } else {
        $rm_item = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
        $rm_item->execute([(int)($_POST['item_id'] ?? 0)]);
        $rm_item = (string)($rm_item->fetchColumn() ?: 'a requirement');
        $sent = sendGroupEmail($pdo, $rm_gid, 'Reminder: please upload ' . $rm_item, rd_mail_html('A requirement is still missing', [
            'Your ' . $rm_item . ' has not been uploaded yet.',
            'Upload it in iSubmit so your checklist can be completed.',
        ]));
        if ($sent) { $_SESSION['ck_reminded'][$rm_key] = time(); $message = 'Reminder sent to ' . $rm_g['research_group_name'] . '.'; }
        else $message = 'The reminder could not be sent. Please try again.';
        redirectToGroupWorkspace($message);
    }
}

// Handle Statistician Acknowledging Payment (Phase 5 Transition)
// Hybrid flow: works from Phase 4 (payment documents uploaded in-system) AND from
// Phase 2 (receipt presented physically at the Research Office, nothing uploaded).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'acknowledge_payment') {
    if ($role === 'Statistician') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            exit('Invalid security token. Please refresh and try again.');
        }
        $form_id = $_POST['form_id'];
        $sequence_no = trim($_POST['control_no']);

        // SCOPE. A Statistician is institution-scoped now (see AA_SCOPED_ROLES), so the row
        // has to be refused, not merely hidden: $student_id arrives in the POST and a crafted
        // one would otherwise reach another institution's group. Resolved from form_id as well,
        // so the id acted on is the one the table says owns that request.
        $sc_owner = $pdo->prepare("SELECT user_id FROM form_stat_treatment WHERE form_id = ?");
        $sc_owner->execute([$form_id]);
        $sc_owner = (int)($sc_owner->fetchColumn() ?: 0);
        if (!$sc_owner || !aa_may_touch_student($pdo, $role, (int)($_SESSION['user_id'] ?? 0), $sc_owner)) {
            exit('Access Denied');
        }
        $student_id = $sc_owner;

        $full_control_no = sp_register_payment($pdo, $form_id, (int)$student_id, $sequence_no);
        if ($full_control_no !== null) {
            $message = "Payment acknowledged and Registered successfully! Generated Control No: $full_control_no";
        } else {
            $message = "This request is not awaiting registration (it may already be registered).";
        }
    }
}

// Handle Statistician Releasing Final Results (Phase 7 Completion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'upload_stat_result') {
    if ($role === 'Statistician') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            exit('Invalid security token. Please refresh and try again.');
        }
        $form_id = $_POST['form_id'];

        // SCOPE. A Statistician is institution-scoped now (see AA_SCOPED_ROLES), so the row
        // has to be refused, not merely hidden: $student_id arrives in the POST and a crafted
        // one would otherwise reach another institution's group. Resolved from form_id as well,
        // so the id acted on is the one the table says owns that request.
        $sc_owner = $pdo->prepare("SELECT user_id FROM form_stat_treatment WHERE form_id = ?");
        $sc_owner->execute([$form_id]);
        $sc_owner = (int)($sc_owner->fetchColumn() ?: 0);
        if (!$sc_owner || !aa_may_touch_student($pdo, $role, (int)($_SESSION['user_id'] ?? 0), $sc_owner)) {
            exit('Access Denied');
        }
        $student_id = $sc_owner;
        $verdict    = ($_POST['verdict'] ?? 'release') === 'revise' ? 'revise' : 'release';
        $has_file   = isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK;

        if ($verdict === 'revise') {
            // NEEDS REVISION. Treating the data showed something the group has to fix. This is the
            // Turnitin shape (a verdict on the release, with a note), but what comes back is not a
            // new kind of upload: she ticks which of the five deliverables need fixing (each with an
            // optional note of its own), those reopen as Revision Requested, and the ordinary
            // checklist loop brings the group back to Treat once she re-approves them
            // (applyReviewDecision: all five Approved -> Phase 7). Statistics is paid ONCE, so this
            // never touches the payment phases.
            $rev_note  = trim($_POST['revision_note'] ?? '');
            $rev_items = array_values(array_intersect([31, 32, 33, 34, 35], array_map('intval', (array)($_POST['items'] ?? []))));
            $item_note = (array)($_POST['item_note'] ?? []);

            $chk = $pdo->prepare("SELECT 1 FROM form_stat_treatment WHERE form_id = ? AND status = 'Phase 7: Statistical Treatment'");
            $chk->execute([$form_id]);
            if (!$chk->fetchColumn()) {
                $message = "This request is not in the processing stage.";
            } elseif (!$rev_items) {
                $message = "Tick at least one requirement that needs fixing.";
            } elseif ($rev_note === '') {
                $message = "Write a note telling the group what to revise.";
            } else {
                // Optional: the treated file that shows the problem. Held in result_file with
                // date_released still NULL — the final release overwrites it.
                $att_path = null;
                if ($has_file) {
                    $ext = strtolower(pathinfo($_FILES['result_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'])) {
                        $result_dir = '../uploads/stats/results/';
                        if (!is_dir($result_dir)) mkdir($result_dir, 0777, true);
                        $cn = $pdo->prepare("SELECT formatted_control_no FROM form_stat_treatment WHERE form_id = ?");
                        $cn->execute([$form_id]);
                        $prefix = $cn->fetchColumn() ?: ('RESULT_' . $student_id);
                        $att_path = $result_dir . $prefix . '_ForRevision_' . time() . '.' . $ext;
                        if (!move_uploaded_file($_FILES['result_file']['tmp_name'], $att_path)) $att_path = null;
                    }
                }

                $actor_id = (int)($_SESSION['user_id'] ?? 0);
                $latest = $pdo->prepare("SELECT MAX(upload_id) FROM uploads WHERE user_id = ? AND item_id = ?");
                $flag   = $pdo->prepare("UPDATE uploads SET verification_status = 'Revision Requested', remarks = ?, verified_by = ?, verified_at = NOW() WHERE upload_id = ?");
                $flagged = 0;
                foreach ($rev_items as $iid) {
                    $latest->execute([$student_id, $iid]);
                    $uid = (int)$latest->fetchColumn();
                    if (!$uid) continue;
                    $one = trim((string)($item_note[$iid] ?? ''));
                    $flag->execute([$one !== '' ? $one : $rev_note, $actor_id, $uid]);
                    $flagged++;
                }

                if ($flagged) {
                    $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 6: Revision Requested', statistician_remarks = ?"
                                  . ($att_path ? ", result_file = ?" : "") . " WHERE form_id = ?")
                        ->execute($att_path ? [$rev_note, $att_path, $form_id] : [$rev_note, $form_id]);

                    $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Statistical Treatment: Revision Needed', ?, 'warning', CURRENT_TIMESTAMP)")
                        ->execute([$student_id, "The statistician found something to fix while treating your data. Open the Statistics card, read the notes on the flagged requirements and re-upload them. No additional payment is needed."]);

                    sendGroupEmail(
                        $pdo,
                        (int)$student_id,
                        'Statistical treatment: revision needed',
                        rd_mail_html(
                            'Your statistical treatment needs a revision',
                            [
                                'While treating your data the statistician found something that needs fixing.',
                                'Open the Statistics card in iSubmit, read the notes on the flagged requirements and '
                                    . 're-upload them. You do not need to pay again.',
                            ],
                            null
                        )
                    );
                    $message = "Sent back for revision. $flagged requirement" . ($flagged > 1 ? 's' : '') . " reopened for the group.";
                } else {
                    $message = "None of the ticked requirements has a file to send back.";
                }
            }
        } elseif ($has_file) {
            $ext = strtolower(pathinfo($_FILES['result_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'])) {
                $f_stmt = $pdo->prepare("SELECT formatted_control_no, contact_email FROM form_stat_treatment WHERE form_id = ? AND status = 'Phase 7: Statistical Treatment'");
                $f_stmt->execute([$form_id]);
                $f_row = $f_stmt->fetch();

                if ($f_row) {
                    $result_dir = '../uploads/stats/results/';
                    if (!is_dir($result_dir)) mkdir($result_dir, 0777, true);
                    $prefix = $f_row['formatted_control_no'] ?: ('RESULT_' . $student_id);
                    $result_path = $result_dir . $prefix . '_FinalResults_' . time() . '.' . $ext;

                    if (move_uploaded_file($_FILES['result_file']['tmp_name'], $result_path)) {
                        // The release note REPLACES statistician_remarks: that column still holds the
                        // last checklist or revision remark, which must not reappear on the success card.
                        $rel_note = trim($_POST['release_note'] ?? '');
                        $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 7: Completed', result_file = ?, date_released = CURRENT_TIMESTAMP, statistician_remarks = ? WHERE form_id = ?")
                            ->execute([$result_path, $rel_note !== '' ? $rel_note : null, $form_id]);

                        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Statistical Treatment Completed', ?, 'success', CURRENT_TIMESTAMP)");
                        $log_stmt->execute([$student_id, "Your statistical treatment results are ready. Download them from the Statistics card."]);

                        // Was PHP's mail() with an @ in front of it — XAMPP has no MTA, so this
                        // milestone has never actually reached anybody. Goes through SMTP now.
                        sendGroupEmail(
                            $pdo,
                            (int)$student_id,
                            'Statistical treatment results released'
                                . ($f_row['formatted_control_no'] ? ' — ' . $f_row['formatted_control_no'] : ''),
                            rd_mail_html(
                                'Your statistical treatment results are ready',
                                [
                                    'The statistician has released your results. Download them from the Statistics '
                                        . 'card in iSubmit.',
                                ],
                                $f_row['formatted_control_no'] ?: null
                            )
                        );

                        $message = "Final results released. The request is now marked Completed.";
                    } else {
                        $message = "Failed to save the results file.";
                    }
                } else {
                    $message = "This request is not in the processing stage.";
                }
            } else {
                $message = "Invalid results file format.";
            }
        } else {
            $message = "No results file was selected.";
        }
    }
}

// Correcting a release. She sent the wrong file, or wants to reword the note: the request stays
// Completed and only the file and/or note change. The replaced file is kept on disk, not deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'update_stat_result' && $role === 'Statistician') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $form_id = (int)($_POST['form_id'] ?? 0);
    $own = $pdo->prepare("SELECT user_id, formatted_control_no FROM form_stat_treatment WHERE form_id = ? AND status = 'Phase 7: Completed'");
    $own->execute([$form_id]);
    $own = $own->fetch();
    if (!$own) {
        $message = "This request has not been released yet.";
    } elseif (!aa_may_touch_student($pdo, $role, (int)($_SESSION['user_id'] ?? 0), (int)$own['user_id'])) {
        exit('Access Denied');
    } else {
        $student_id = (int)$own['user_id'];
        $note = trim($_POST['release_note'] ?? '');
        $new_path = null;
        if (isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['result_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'])) {
                $message = "Invalid results file format.";
            } else {
                $result_dir = '../uploads/stats/results/';
                if (!is_dir($result_dir)) mkdir($result_dir, 0777, true);
                $prefix = $own['formatted_control_no'] ?: ('RESULT_' . $student_id);
                $new_path = $result_dir . $prefix . '_FinalResults_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['result_file']['tmp_name'], $new_path)) {
                    $new_path = null;
                    $message = "Failed to save the results file.";
                }
            }
        }
        if (empty($message)) {
            $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ?" . ($new_path ? ", result_file = ?" : "") . " WHERE form_id = ?")
                ->execute($new_path ? [$note !== '' ? $note : null, $new_path, $form_id] : [$note !== '' ? $note : null, $form_id]);

            $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Statistical Results Updated', ?, 'success', CURRENT_TIMESTAMP)")
                ->execute([$student_id, $new_path
                    ? "The statistician replaced your statistical treatment results with a corrected file. Download the new one from the Statistics card."
                    : "The statistician updated the note on your statistical treatment results."]);

            if ($new_path) {
                sendGroupEmail(
                    $pdo,
                    $student_id,
                    'Statistical treatment results updated' . ($own['formatted_control_no'] ? ' — ' . $own['formatted_control_no'] : ''),
                    rd_mail_html(
                        'Your statistical treatment results were updated',
                        ['The statistician replaced your results with a corrected file. Download the new one from the Statistics card in iSubmit.'],
                        $own['formatted_control_no'] ?: null
                    )
                );
            }
            $message = $new_path ? "Results file replaced. The group has been notified." : "Note updated.";
        }
    }
}

/**
 * Append one staff decision to the Manuscript audit trail.
 *
 * `plagiarism_payment_logs` started as the payment-desk twin of `payment_logs`, but the office
 * needs to re-check a whole clearance, not one till receipt — "who released them, who received the
 * manuscript, who registered the payment, who signed off the scan" is a single question asked
 * months later. So every decision in the flow lands here, separated by `action`, and the History
 * screen reads the lot. (The table name is now narrower than its contents; renaming it would mean
 * touching reset_handler.php's archive block, which is not worth a word.)
 *
 * Never throws: an audit write must not be able to undo a decision the office already made.
 * $action is one of released|received|returned|accepted|rejected|cleared|revision|report.
 */
function plag_log(PDO $pdo, int $studentId, string $action, ?string $controlNo = null, ?int $itemId = null, ?string $remarks = null): void
{
    try {
        $pc = $pdo->prepare("SELECT id FROM plagiarism_checks WHERE user_id = ?");
        $pc->execute([$studentId]);
        $plagId = (int) $pc->fetchColumn();

        $pdo->prepare("INSERT INTO plagiarism_payment_logs
                       (plag_id, student_id, actor_id, action, control_no, item_id, remarks, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([$plagId, $studentId, (int) ($_SESSION['user_id'] ?? 0), $action,
                       $controlNo, $itemId, ($remarks === '' ? null : $remarks)]);
    } catch (Throwable $e) {
        error_log('plag_log failed: ' . $e->getMessage());
    }
}

// ── PLAGIARISM / MANUSCRIPT DECISIONS ────────────────────────────────────────────────────────
// Five staff decisions, in two groups.
//
//   RECEIVING (the review screen) — plag_receive / plag_return. Accepting a manuscript does NOT
//   clear the group: it means the office has the right document, and sends them to the finance
//   office to pay for the scan. Clearance is the LAST step, not this one.
//
//   THE TURNITIN VERDICT (the cleared screen) — plag_accept / plag_revise / plag_replace, taken
//   after the group has paid and been registered. A clean pass needs NO file: there is nothing to
//   show. A failed one must carry the report, because that is the evidence the group has to act
//   on. Reports are versioned `uploads` rows under item_id 40, same as every other document.
$plag_actions = ['plag_receive', 'plag_return', 'plag_accept', 'plag_revise', 'plag_replace'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action_type'] ?? '', $plag_actions, true)) {
    $plag_action = $_POST['action_type'];
    if (in_array($role, ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            exit('Invalid security token. Please refresh and try again.');
        }
        $student_id = (int)$_POST['student_id'];
        $notes = trim($_POST['notes'] ?? '');

        $chk_stmt = $pdo->prepare("SELECT upload_id FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1");
        $chk_stmt->execute([$student_id]);
        $manuscript_exists = $chk_stmt->fetchColumn();

        if (!$manuscript_exists) {
            $message = "No manuscript submission found for this group.";
        } elseif ($plag_action === 'plag_receive' || $plag_action === 'plag_return') {
            // ---- Stage 1: is this the right document? ----
            $recv = ($plag_action === 'plag_receive');
            if (!$recv && $notes === '') {
                $message = "Say what needs fixing — the group only sees your note.";
            } else {
                $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ?
                               WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1")
                    ->execute([
                        $recv ? 'Approved' : 'Revision Requested',
                        $notes ?: ($recv ? 'Manuscript received — proceed to the finance office.' : null),
                        $student_id,
                    ]);
                $pdo->prepare("UPDATE plagiarism_checks SET status = ?, remarks = ? WHERE user_id = ?")
                    ->execute([
                        $recv ? 'Phase 2: Form Download' : 'Phase 1: Manuscript Returned',
                        $notes ?: null,
                        $student_id,
                    ]);
                $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)")
                    ->execute($recv
                        ? [$student_id, 'Manuscript received',
                           'Your manuscript has been received. Download the RDC plagiarism form, pay at the finance office, then upload your validated form and official receipt.',
                           'success']
                        : [$student_id, 'Manuscript sent back',
                           'Your manuscript was sent back before the scan: ' . $notes, 'warning']);
                plag_log($pdo, $student_id, $recv ? 'received' : 'returned', null, 4, $notes);
                $message = $recv
                    ? 'Manuscript received — the group has been sent to the finance office.'
                    : 'Manuscript sent back to the group.';
            }
        } else {
            // ---- Stage 2: the Turnitin verdict ----
            $has_file = isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK;
            $ext = $has_file ? strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION)) : '';

            if (!$has_file && $plag_action !== 'plag_accept') {
                $message = "A Turnitin report is required for this action.";
            } elseif ($has_file && !in_array($ext, ['pdf', 'doc', 'docx'], true)) {
                $message = "Invalid report file format. Use PDF or Word document.";
            } else {
                $report_saved = true;
                if ($has_file) {
                    $pc_stmt = $pdo->prepare("SELECT formatted_control_no FROM plagiarism_checks WHERE user_id = ?");
                    $pc_stmt->execute([$student_id]);
                    $control_no = $pc_stmt->fetchColumn();
                    $prefix = $control_no ?: ('PLAG_' . $student_id);

                    $report_dir = '../uploads/plagiarism/reports/';
                    if (!is_dir($report_dir)) mkdir($report_dir, 0777, true);
                    $report_filename = $prefix . '_TurnitinReport_' . time() . '.' . $ext;
                    $report_path = $report_dir . $report_filename;

                    if (move_uploaded_file($_FILES['report_file']['tmp_name'], $report_path)) {
                        $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status, remarks, uploaded_at) VALUES (?, 40, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
                            ->execute([
                                $student_id, $report_path, $report_filename,
                                ($plag_action === 'plag_revise') ? 'Revision Requested' : 'Approved',
                                $notes ?: null,
                            ]);
                    } else {
                        $report_saved = false;
                        $message = "Failed to save the Turnitin report file.";
                    }
                }

                if ($report_saved) {
                    if ($plag_action !== 'plag_replace') {
                        $cleared = ($plag_action === 'plag_accept');
                        $default_note = $cleared
                            ? 'Cleared — Turnitin check passed.'
                            : 'Revision requested — see the attached Turnitin report.';
                        $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ? WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1")
                            ->execute([$cleared ? 'Approved' : 'Revision Requested', $notes ?: $default_note, $student_id]);
                        $pdo->prepare("UPDATE plagiarism_checks SET status = ?, remarks = ? WHERE user_id = ?")
                            ->execute([$cleared ? 'Phase 5: Cleared' : 'Phase 5: Revision Requested', $notes ?: null, $student_id]);
                    }

                    $log_titles = [
                        'plag_accept' => 'Plagiarism check passed',
                        'plag_revise' => 'Plagiarism revision requested',
                        'plag_replace' => 'Turnitin report replaced',
                    ];
                    $log_descs = [
                        'plag_accept' => 'Your paper passed the Turnitin check. You are now ready to bookbind your paper.'
                            . ($has_file ? ' Your official report is available in the Plagiarism card.' : ''),
                        'plag_revise' => 'Your manuscript needs revision — check the attached Turnitin report, revise, then re-upload it. A revised manuscript is scanned again, so you will pay the scan fee again after the office receives it.',
                        'plag_replace' => 'Your official Turnitin report has been updated with a corrected version.',
                    ];
                    $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)")
                        ->execute([$student_id, $log_titles[$plag_action], $log_descs[$plag_action],
                                   $plag_action === 'plag_revise' ? 'warning' : 'success']);
                    plag_log($pdo, $student_id,
                        ['plag_accept' => 'cleared', 'plag_revise' => 'revision', 'plag_replace' => 'report'][$plag_action],
                        null, $has_file ? 40 : null, $notes);

                    // The verdict ends (or restarts) the whole journey, so it is mailed too.
                    // Replacing a report is housekeeping on an already-cleared group — no mail.
                    if ($plag_action === 'plag_accept') {
                        sendGroupEmail(
                            $pdo,
                            $student_id,
                            'Plagiarism check passed — you are cleared',
                            rd_mail_html(
                                'Your paper passed the Turnitin check',
                                [
                                    'Congratulations! Your manuscript has cleared the plagiarism check.',
                                    'You are now <strong>ready to bookbind your paper</strong>.'
                                        . ($has_file ? ' Your official report is in the Plagiarism card.' : ''),
                                ],
                                null,
                                $notes !== '' ? 'Note from the research office: &ldquo;' . htmlspecialchars($notes) . '&rdquo;' : null
                            )
                        );
                    } elseif ($plag_action === 'plag_revise') {
                        sendGroupEmail(
                            $pdo,
                            $student_id,
                            'Plagiarism check — your manuscript needs revision',
                            rd_mail_html(
                                'Your manuscript needs revision',
                                [
                                    'The Turnitin check flagged your manuscript. The official report is attached to '
                                        . 'your Plagiarism card — read it, revise the paper, then re-upload.',
                                    'A revised manuscript is a new scan: once the office receives it you will pay the scan fee <strong>again</strong> at the finance office. Your control number stays the same.',
                                ],
                                null,
                                $notes !== '' ? 'What the report found: &ldquo;' . htmlspecialchars($notes) . '&rdquo;' : null
                            )
                        );
                    }

                    $messages = [
                        'plag_accept' => 'Cleared' . ($has_file ? ' and report released.' : ' — no report attached.'),
                        'plag_revise' => 'Revision requested and Turnitin report attached.',
                        'plag_replace' => 'Turnitin report replaced successfully.',
                    ];
                    $message = $messages[$plag_action];
                }
            }
        }
    }
}

// ── PAYMENT VERIFICATION (mirrors the Statistics payment desk) ───────────────────────────────
// The group has paid at the finance office and uploaded its validated form + official receipt.
// Staff checks both, types the sequence number, and the group is registered — from then on they
// upload nothing more; they wait for the Turnitin result.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'plag_acknowledge_payment') {
    if (!in_array($role, ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
        exit('Access Denied');
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $pay_sid = (int)($_POST['student_id'] ?? 0);
    $pay_seq = trim($_POST['control_no'] ?? '');

    $pay_row = $pdo->prepare("SELECT id, status, formatted_control_no FROM plagiarism_checks WHERE user_id = ?");
    $pay_row->execute([$pay_sid]);
    $pay_pc = $pay_row->fetch();

    if (!$pay_pc || !in_array($pay_pc['status'], ['Phase 2: Form Download', 'Phase 3: Payment Verification'], true)) {
        $message = "That group is not awaiting payment verification (it may already be registered).";
    } elseif ($pay_seq === '') {
        // MANDATORY. The number is printed on the official receipt the group uploaded — the whole
        // job here is to read it off that scan and record it, exactly as the Statistics desk does.
        // Registering without it would leave the group with no number to quote.
        $message = "Type the control number from their official receipt before registering.";
    } else {
        // Built the same way the STAT number is: school + course from the student's own program
        // (pp_control_prefix — the ONE source; Progress previews with it), then whatever the receipt
        // says. The first registration overwrites any placeholder: the receipt is the authority.
        $pay_who = $pdo->prepare("SELECT program FROM users WHERE user_id = ?");
        $pay_who->execute([$pay_sid]);
        $pay_receipt_no = pp_control_prefix($pay_who->fetchColumn() ?: null)
                        . strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $pay_seq));
        // A PAID-AGAIN ROUND (every Turnitin revision costs a new payment) keeps the group's number:
        // it is theirs for good, and the new receipt is logged against it instead.
        $pay_again = $pdo->prepare("SELECT registered_at FROM plagiarism_checks WHERE user_id = ?");
        $pay_again->execute([$pay_sid]);
        $pay_is_again = (bool)$pay_again->fetchColumn() && !empty($pay_pc['formatted_control_no']);
        $pay_no = $pay_is_again ? $pay_pc['formatted_control_no'] : $pay_receipt_no;

        $pdo->prepare("UPDATE plagiarism_checks SET status = 'Phase 4: Registered', registered_at = NOW(),
                       formatted_control_no = ? WHERE user_id = ?")
            ->execute([$pay_no, $pay_sid]);
        // Whatever they sent is accepted by the act of registering, same as Statistics does.
        $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Verified during official registration.'
                       WHERE user_id = ? AND item_id IN (41, 42) AND verification_status = 'Pending'")
            ->execute([$pay_sid]);

        plag_log($pdo, $pay_sid, 'accepted', $pay_receipt_no, null, $pay_is_again ? 'Paid again for a revised manuscript' : null);

        $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)")
            ->execute([
                $pay_sid,
                'Plagiarism payment verified',
                'Your payment has been verified. Your control number is ' . $pay_no
                    . '. Nothing more to upload — the research office is running your Turnitin check and will post the result here.',
            ]);
        // The control number is the thing they will be asked to quote at the office, so it goes
        // out by mail as well as into the portal.
        sendGroupEmail(
            $pdo,
            $pay_sid,
            'Plagiarism payment verified — ' . $pay_no,
            rd_mail_html(
                'Your payment has been verified',
                [
                    'The research office has recorded your plagiarism scan payment. This is your official '
                        . 'control number — keep it, you will be asked for it.',
                    'There is <strong>nothing left to upload</strong>. The office is running your manuscript '
                        . 'through Turnitin and the result will appear in your Plagiarism card.',
                ],
                $pay_no
            )
        );

        $message = "Payment verified and registered. Control No: " . htmlspecialchars((string)$pay_no);
    }
}

// Sending a payment document back drops the group to the form-download step so they can replace it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'plag_payment_reject') {
    if (!in_array($role, ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
        exit('Access Denied');
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $rej_sid  = (int)($_POST['student_id'] ?? 0);
    $rej_item = (int)($_POST['item_id'] ?? 0);
    $rej_note = trim($_POST['remarks'] ?? '');

    if (!in_array($rej_item, [41, 42], true) || $rej_note === '') {
        $message = "Pick a document and say what is wrong with it.";
    } else {
        $pdo->prepare("UPDATE uploads SET verification_status = 'Revision Requested', remarks = ?
                       WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1")
            ->execute([$rej_note, $rej_sid, $rej_item]);
        $pdo->prepare("UPDATE plagiarism_checks SET status = 'Phase 2: Form Download' WHERE user_id = ? AND status = 'Phase 3: Payment Verification'")
            ->execute([$rej_sid]);

        plag_log($pdo, $rej_sid, 'rejected', null, $rej_item, $rej_note);

        $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, 'warning', CURRENT_TIMESTAMP)")
            ->execute([$rej_sid, 'Payment document sent back',
                       'One of your payment documents was sent back: ' . $rej_note]);
        $message = "Document sent back to the group.";
    }
}

// ── RELEASE A GROUP TO SEND ITS FINAL MANUSCRIPT ────────────────────────────────────────────
// The final defense happens face to face, outside this system, so nothing here can tell that it
// actually went ahead — only the office can. This action is that statement. One flip does three
// things for the group: opens the Plagiarism card, swaps its dashboard billboard, and lights the
// "I'm ready" button on its defense ticket.
//
// The flag lives on plagiarism_checks (released_at/released_by), and this action CREATES that row
// — the control number is still minted later, on the group's first manuscript upload.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'release_manuscript') {
    if (!in_array($role, ['Research Coordinator', 'Research Director', 'Statistician'], true)) {
        exit('Access Denied');
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        exit('Invalid security token. Please refresh and try again.');
    }
    $rel_sid = (int)($_POST['student_id'] ?? 0);

    // The rule is CLEARANCE, not the calendar: a 'Scheduled' booking means both final phases were
    // approved and the office confirmed the date. Whether the day has passed is the office's
    // judgement, shown on the row — a defense can run early or be recorded late, and gating on the
    // date would block a release the office knows is correct. Re-checked here so a stale page
    // cannot release a group that never got that far.
    $rel_booking = df_get_booking($pdo, $rel_sid, 'final');
    if ($rel_sid <= 0 || !df_requirements_unlocked($rel_booking)) {
        $message = "That group has not cleared its final defense requirements yet.";
    } else {
        // UNIQUE(user_id): a row may already exist from an earlier cycle or a re-release.
        $pdo->prepare("INSERT INTO plagiarism_checks (user_id, status, released_at, released_by)
                       VALUES (?, 'Phase 1: Pending Manuscript', NOW(), ?)
                       ON DUPLICATE KEY UPDATE released_at = COALESCE(released_at, NOW()),
                                               released_by = COALESCE(released_by, VALUES(released_by))")
            ->execute([$rel_sid, (int)$_SESSION['user_id']]);

        // Logged after the INSERT: plag_log() looks the row up by user_id, and before this statement
        // a first-time release has no row to point at.
        plag_log($pdo, $rel_sid, 'released');

        df_log(
            $pdo,
            $rel_sid,
            'Send your final manuscript',
            'Your final defense is done. The research office has opened your Plagiarism card — '
                . 'submit your final manuscript there to begin the Turnitin check.',
            'success'
        );

        // They are not sitting in the portal waiting for this — the defense is over and they have
        // gone home. Mail is the only thing that reaches them.
        sendGroupEmail(
            $pdo,
            $rel_sid,
            'You may now send your final manuscript',
            rd_mail_html(
                'Send your final manuscript',
                [
                    'Your final defense is done and the research office has opened your <strong>Plagiarism</strong> card.',
                    'Submit your final manuscript there to begin the Turnitin check. After it is received you will '
                        . 'be asked to pay the scan fee at the finance office.',
                ],
                null,
                'Open iSubmit &rarr; Plagiarism to upload it.'
            )
        );

        $rel_name = $pdo->prepare("SELECT research_group_name FROM users WHERE user_id = ?");
        $rel_name->execute([$rel_sid]);
        $message = 'Released — ' . htmlspecialchars((string)$rel_name->fetchColumn())
                 . ' can now send their final manuscript.';
    }
}

// Get filter values
$selected_department = $_GET['department'] ?? '';
$selected_program = $_GET['program'] ?? '';
$search_query = trim($_GET['search'] ?? '');
$sort_date = $_GET['sort_date'] ?? 'DESC';
$order_dir = ($sort_date === 'ASC') ? 'ASC' : 'DESC';

// Set by redirectToGroupWorkspace(): reopen the workspace the reviewer was in, and optionally
// pop a rubric-gated document straight after a bulk approve.
$restore_group = (int)($_GET['group'] ?? 0);
$restore_item = (int)($_GET['item'] ?? 0);
$restore_open_item = (int)($_GET['open_item'] ?? 0);

// Fetch distinct departments and programs for filters
// Institutions, from the STUDENTS — the same predicate df_schools() uses. Not "every
// distinct users.department": staff rows live in that column too, and a Statistician's
// AA_ALL_INSTITUTIONS marker would otherwise show up as an institution called "ALL" in
// this filter, which selects nothing and is not a place.
$depts_stmt = $pdo->query("SELECT DISTINCT department FROM users
                           WHERE role = 'Student' AND department IS NOT NULL AND department <> ''
                           ORDER BY department ASC");
$departments = $depts_stmt->fetchAll(PDO::FETCH_COLUMN);

$program_options = [
    "BS Radiologic Technology",
    "BS Nursing",
    "BS Medical Technology",
    "BS Physical Therapy",
    "BS Pharmacy",
    "BS Midwifery",
    "BS 2-year Dental Technology",
    "BS 2-year Pharmacy Aide",
    "BS Caregiving and TVET Course",
    "BS Information Technology",
    "BS Computer Engineering",
    "BS Business Administration",
    "BS Custom Administration",
    "BS Hospitality Management",
    "BS Tourism Management",
    "BS Accountancy",
    "BS Education",
    "BS Science Criminology",
    "BS Science in Social Work",
    "BS Secondary Education",
    "BS Science in Psychology",
    "BS Physical Education"
];
sort($program_options);

// Build WHERE clause for filtering
$where_clauses = [];
$params = [];

// Role scope, ahead of the user's own filters. Every role admitted to this screen today is
// UNSCOPED, so aa_scope_sql() returns the literal '1' and this is inert — it is kept as a
// safety net so that admitting a course-scoped role here later cannot silently expose every
// group. It feeds the main query and the group selector alike, since both are built from
// $where_clauses and the two must never disagree about who is visible.
[$fp_scope_sql, $fp_scope_params] = aa_scope_sql($pdo, $role, (int)$_SESSION['user_id'], 'u');
$where_clauses[] = $fp_scope_sql;
foreach ($fp_scope_params as $p) {
    $params[] = $p;
}

if ($selected_department) {
    $where_clauses[] = "u.department = ?";
    $params[] = $selected_department;
}
if ($selected_program) {
    $where_clauses[] = "u.program = ?";
    $params[] = $selected_program;
}
if ($search_query) {
    $where_clauses[] = "(u.username LIKE ? OR u.research_group_name LIKE ? OR u.department LIKE ? OR u.program LIKE ? OR u.student_number LIKE ?)";
    $wildcard = "%" . $search_query . "%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}
$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : "";

// Fetch checklist items
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE item_id IN ($item_list) ORDER BY CASE WHEN item_id = 17 THEN 10 WHEN item_id = 13 THEN 14.1 WHEN item_id = 15 THEN 14.2 WHEN item_id = 16 THEN 14.3 ELSE item_id END ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Fetch ALL uploads (including historical ones) for this phase
$where_clauses_uploads = $where_clauses;
$where_clauses_uploads[] = "up.item_id IN ($item_list)";
$where_uploads_sql = "WHERE " . implode(' AND ', $where_clauses_uploads);

$plag_join_sql = ($phase === 'plag') ? "LEFT JOIN plagiarism_checks pc ON pc.user_id = u.user_id" : "";
$plag_select_sql = ($phase === 'plag') ? ", pc.formatted_control_no AS plag_control_no" : "";

$uploads_query = "
    SELECT up.upload_id, up.item_id, up.file_path, up.original_filename, up.verification_status, up.remarks, up.uploaded_at,
           -- The DATABASE computes the epoch. PHP is Europe/Berlin and the MySQL session clock
           -- is ahead of it, so strtotime() on the stored DATETIME lands in the future and every
           -- age came out negative — see ws_ago().
           UNIX_TIMESTAMP(up.uploaded_at) AS uploaded_ts,
           up.form_008_data, up.form_008_score, up.form_008_decision, u.department, u.program,
           u.email, u.username, u.student_number, u.research_group_name, u.user_id as student_user_id, u.profile_pic,
           -- Who signed it off, and in what capacity. A requirement is single-stage, so this is
           -- ONE signature, not a chain — but WHICH role signed it varies (a Focal Person owns
           -- her courses; the office can clear anything), and that is worth showing on the card.
           -- Left as NULL for the 279 rows approved before verified_by existed: an unknown
           -- signer must read as unknown, never as whoever is looking at it now.
           up.verified_at, vb.username AS verified_name, vb.role AS verified_role,
           vb.profile_pic AS verified_pfp
           $plag_select_sql
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    LEFT JOIN users vb ON vb.user_id = up.verified_by
    $plag_join_sql
    $where_uploads_sql
    ORDER BY up.uploaded_at DESC
";
$uploads_stmt = $pdo->prepare($uploads_query);
$uploads_stmt->execute($params);
$all_uploads = $uploads_stmt->fetchAll(PDO::FETCH_ASSOC);

// Map uploads into array groups mapped by the requirement item ID
$uploads_by_item = [];
$uploads_history = [];

foreach ($all_uploads as $up) {
    $itemId = $up['item_id'];
    $studId = $up['student_user_id'];

    // Group ALL uploads by item and student for history tracking
    if (!isset($uploads_history[$itemId])) {
        $uploads_history[$itemId] = [];
    }
    if (!isset($uploads_history[$itemId][$studId])) {
        $uploads_history[$itemId][$studId] = [];
    }
    $uploads_history[$itemId][$studId][] = $up; // Ordered by uploaded_at DESC
}

// Populate $uploads_by_item with ONLY the latest upload per student per item
foreach ($uploads_history as $itemId => $students) {
    foreach ($students as $studId => $studentSubs) {
        $uploads_by_item[$itemId][] = $studentSubs[0];
    }
}

// GROUP-INBOX: pivot the latest-per-item rows into per-group profiles so the admin can review one
// group's whole checklist in one card. Presentation-only; reuses the same upload rows the requirement
// tables use. Applies to Proposal, Final, and the Stats checklist (never the payment docs 36/37, which
// live in the Pending Payments queue; and not the Stats payments/release sub-views).
$use_group_inbox = ($phase === 'proposal' || $phase === 'final'
    || ($phase === 'stats' && in_array($stats_view, ['all', 'checklist'], true)));
// WORKSPACE NAVIGATION: instead of accordions, both views open one subject as a full-width
// workspace — By group opens a group's whole checklist, By requirement opens one requirement
// across every group. The reviewer stays there until they press Back. Applies wherever the
// group inbox applies (Proposal, Final, Stats checklist); the plagiarism, payment, release and
// history screens are queues/logs rather than group review, so they keep their own layouts.
$use_group_workspace = $use_group_inbox;
$group_inbox_items = $current_phase['items'];
if ($phase === 'stats') {
    $group_inbox_items = array_values(array_diff($group_inbox_items, [36, 37]));
}

$submissions_by_group = [];
if ($use_group_inbox) {
    foreach ($group_inbox_items as $p_item_id) {
        foreach ($uploads_by_item[$p_item_id] ?? [] as $sub) {
            $sid = $sub['student_user_id'];
            if (!isset($submissions_by_group[$sid])) {
                $submissions_by_group[$sid] = [
                    'meta' => [
                        'user_id' => $sid,
                        'research_group_name' => $sub['research_group_name'],
                        'username' => $sub['username'],
                        'department' => $sub['department'],
                        'program' => $sub['program'],
                        'profile_pic' => $sub['profile_pic'] ?? '',
                        'email' => $sub['email'] ?? '',
                        'student_number' => $sub['student_number'] ?? '',
                    ],
                    'items' => [],
                    'action_count' => 0,
                    'file_count' => 0,
                    'approved_count' => 0,
                    'revision_count' => 0,
                ];
            }
            $submissions_by_group[$sid]['items'][$p_item_id] = $sub;
            $submissions_by_group[$sid]['file_count']++;
            $vs = $sub['verification_status'];
            if ($vs === 'Approved') $submissions_by_group[$sid]['approved_count']++;
            elseif ($vs === 'Revision Requested') $submissions_by_group[$sid]['revision_count']++;
            // Cascaded items (13/15/16) are never actioned directly.
            if (!in_array($p_item_id, [13, 15, 16], true)) {
                // Still in flight = still this reviewer's to decide; there is no role handoff.
                if ($vs === 'Pending' || $vs === 'Under Review') $submissions_by_group[$sid]['action_count']++;
            }
        }
    }
    // Action-needed groups first, then most files.
    uasort($submissions_by_group, function ($a, $b) {
        if ($a['action_count'] !== $b['action_count']) return $b['action_count'] <=> $a['action_count'];
        return $b['file_count'] <=> $a['file_count'];
    });

    // FAIRNESS ORDER — "who got here first". The tiles are sorted by workload (action first), which
    // is the right order to *work* in but hides who has been waiting longest. So each group also
    // carries two timestamps and a queue rank:
    //   first_cleared  — when its first requirement in this phase was approved
    //   first_upload   — when it first submitted anything in this phase; ALSO the ranking key
    // `uploads` has no verified_at column, so the approval time comes from the activity_logs row
    // every decision writes (status_type 'success', linked to the upload it decided).
    $g_in = implode(',', array_map('intval', array_keys($submissions_by_group)));
    $i_in = implode(',', array_map('intval', $group_inbox_items));

    $first_cleared_rows = $first_upload_rows = [];
    if ($g_in !== '') {
        $first_cleared_rows = $pdo->query("
            SELECT al.user_id, MIN(al.created_at) AS t
            FROM activity_logs al
            JOIN uploads up ON up.upload_id = al.upload_id
            WHERE al.status_type = 'success' AND up.item_id IN ($i_in) AND al.user_id IN ($g_in)
            GROUP BY al.user_id
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        // The epoch, not the wall-clock string: ws_ago() on a DATETIME is six hours out here.
        $first_upload_rows = $pdo->query("
            SELECT user_id, UNIX_TIMESTAMP(MIN(uploaded_at)) AS t
            FROM uploads WHERE item_id IN ($i_in) AND user_id IN ($g_in)
            GROUP BY user_id
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // THERE IS NO QUEUE NUMBER ANY MORE, and the reasoning is worth keeping.
    //
    // It used to render "33rd in queue", derived from the group's earliest upload in the phase.
    // The defence for it was that the placing never moves once earned — which is true, and is
    // exactly why it had to go. A number that never moves when a group is SERVED is an arrival
    // index, not a queue position: a fully-approved group still read "3rd", and a group that
    // failed a requirement and was sent back kept its early place and went on looking urgent
    // while it was actually stuck. It also never resets — next school year it climbs into the
    // eighties — and the tiles sort by workload, so the ordinals appeared out of order anyway.
    //
    // What a reviewer actually wants is one line down and already there: HOW LONG this has been
    // waiting. That is bounded, it is actionable, and it corrects itself. The only ranking worth
    // showing is the extreme, so exactly one group is marked: the one that has waited longest
    // and still has something outstanding. A badge is bounded; an ordinal is not.
    foreach ($submissions_by_group as $sid => &$g_ref) {
        $g_ref['first_cleared'] = $first_cleared_rows[$sid] ?? null;
        $g_ref['first_upload']  = $first_upload_rows[$sid] ?? null;
    }
    unset($g_ref);

    // The one group worth singling out: waited longest AND still has something outstanding.
    // A group with nothing left to review is not waiting, however early it arrived.
    $ws_oldest_uid = 0; $ws_oldest_t = PHP_INT_MAX;
    foreach ($submissions_by_group as $sid => $g_row) {
        if ((int)($g_row['action_count'] ?? 0) < 1) continue;
        $t = (int)($g_row['first_upload'] ?? 0);
        if ($t && $t < $ws_oldest_t) { $ws_oldest_t = $t; $ws_oldest_uid = (int)$sid; }
    }

    // THE DEFENSE APPLICATION DOCUMENT — reviewed here, but never a checklist requirement.
    //
    // Proposal's endorsement (item 12) is uploaded in the Schedule sheet, not the requirement
    // grid, so it has never had a review surface: the office could open it from the scheduling
    // screen but could not approve or send it back. It gets its own card in the workspace below,
    // using the same review modal every other document uses.
    //
    // Skipped when the item is already a requirement of this phase — Final's Form 002 is item 20,
    // which IS in $phase_map, so it must not be rendered twice.
    $app_item = df_application_items($phase === 'stats' || $phase === 'plag' ? 'final' : $phase)[0] ?? 0;
    $ws_app_item = (in_array($phase, ['proposal', 'final'], true)
                    && !in_array($app_item, $current_phase['items'], true)) ? $app_item : 0;
    $ws_app_docs = [];
    if ($ws_app_item && $submissions_by_group) {
        $a_in = implode(',', array_map('intval', array_keys($submissions_by_group)));
        $a_stmt = $pdo->prepare("
            SELECT up.upload_id, up.item_id, up.file_path, up.original_filename, up.verification_status,
                   up.remarks, up.uploaded_at, u.user_id AS student_user_id, u.research_group_name
            FROM uploads up
            JOIN users u ON u.user_id = up.user_id
            JOIN (SELECT user_id, MAX(upload_id) AS max_id FROM uploads
                  WHERE item_id = ? AND user_id IN ($a_in) GROUP BY user_id) latest
              ON latest.max_id = up.upload_id");
        $a_stmt->execute([$ws_app_item]);
        foreach ($a_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['form_008_data'] = null;   // the review modal reads this key
            $ws_app_docs[(int)$r['student_user_id']] = $r;
        }
        $a_name = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
        $a_name->execute([$ws_app_item]);
        $ws_app_name = $a_name->fetchColumn() ?: ('Requirement ' . $ws_app_item);
    }

}

/** "1st" / "2nd" / "3rd" / "4th" — the queue rank reads as a placing, not a row number. */
function ws_ordinal(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
}

/** Lucide name for a verification_status, so every card leads with the same glyph per state. */
function ws_status_icon(string $status): string
{
    return [
        'Approved' => 'check-circle-2',
        'Revision Requested' => 'undo-2',
        'Under Review' => 'eye',
    ][$status] ?? 'clock';
}

/** "3d ago" / "5h ago" / "just now" — a compact age next to an absolute date. */
/**
 * How long ago, in words.
 *
 * Pass an EPOCH (int) wherever you can — `UNIX_TIMESTAMP(col)` straight out of the query.
 * Passing the DATETIME string is the legacy path and it is WRONG BY SIX HOURS: PHP runs
 * Europe/Berlin here while the MySQL session clock is ahead of it, so `strtotime()` on a stored
 * DATETIME lands in the future and the age comes out negative. Measured on upload 645 —
 * uploaded 5h50m earlier, reported as "just now".
 *
 * The string branch is kept only so the three older callers (payments, first upload, first
 * cleared) behave exactly as they did; each reads from a different query that needs its own
 * UNIX_TIMESTAMP column before it can be moved over. Half-fixing them would turn one wrong
 * number into an inconsistent pair.
 */
function ws_ago(int|string|null $ts, string $suffix = ' ago'): string
{
    if (!$ts) return '';
    // $suffix = '' gives a bare DURATION ("2d"), for captions that already say "Waiting" —
    // "Waiting 2d ago" is two grammars glued together.
    $secs = is_int($ts) ? time() - $ts : time() - strtotime($ts);
    if ($secs < 90) return $suffix === '' ? 'moments' : 'just now';
    if ($secs < 3600) return floor($secs / 60) . 'm' . $suffix;
    if ($secs < 86400) return floor($secs / 3600) . 'h' . $suffix;
    if ($secs < 2592000) return floor($secs / 86400) . 'd' . $suffix;
    return floor($secs / 2592000) . 'mo' . $suffix;
}

// Fetch all student groups for the Interactive SELECTOR feature (leaders only, where leader_id is NULL)
// We also apply the same department, program, and search filters so that the sidebar is in sync with the search filters!
$group_selector_clauses = $where_clauses;
$group_selector_clauses[] = "u.role = 'Student'";
$group_selector_clauses[] = "u.research_group_name IS NOT NULL";
$group_selector_clauses[] = "u.research_group_name != ''";
$group_selector_clauses[] = "u.leader_id IS NULL";

$group_selector_where_sql = "WHERE " . implode(' AND ', $group_selector_clauses);

// Sort by most recently active group first (activity_logs covers both student uploads and
// staff signoffs, so it's a more complete "last touched" signal than uploads alone). This list
// is system-wide, not phase-scoped, so the recency source is too.
$group_selector_query = "
    SELECT DISTINCT u.user_id, u.username, u.student_number, u.research_group_name, u.department, u.program, u.profile_pic, u.email,
           la.last_activity
    FROM users u
    LEFT JOIN (
        SELECT user_id, MAX(created_at) AS last_activity FROM activity_logs GROUP BY user_id
    ) la ON la.user_id = u.user_id
    $group_selector_where_sql
    ORDER BY (la.last_activity IS NULL) ASC, la.last_activity DESC, u.research_group_name ASC
";
$group_selector_stmt = $pdo->prepare($group_selector_query);
$group_selector_stmt->execute($params);
$all_student_groups = $group_selector_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate actual progress for each group in the current phase
$group_progress = [];
foreach ($all_student_groups as $g) {
    $g_id = $g['user_id'];
    $approved_count = 0;
    $total_items = count($checklist_items);

    foreach ($checklist_items as $item) {
        $itemId = $item['item_id'];
        if (isset($uploads_history[$itemId][$g_id])) {
            $latest_sub = $uploads_history[$itemId][$g_id][0];
            if ($latest_sub['verification_status'] === 'Approved') {
                $approved_count++;
            }
        }
    }

    $pct = $total_items > 0 ? round(($approved_count / $total_items) * 100) : 0;
    $group_progress[$g_id] = [
        'pct' => $pct,
        'approved' => $approved_count,
        'total' => $total_items
    ];
}

// Helper function to securely highlight search matches
function highlightSearchTerm($text, $term)
{
    $text = htmlspecialchars($text ?? '');
    if (!empty($term)) {
        $term_escaped = preg_quote(htmlspecialchars($term), '/');
        $text = preg_replace("/($term_escaped)/i", "<mark class='search-highlight'>$1</mark>", $text);
    }
    return $text;
}

$pending_payments = [];
$release_queue = [];
$payment_history = [];
$hist_total_rows = 0;
$hist_total_pages = 1;
$hist_years = [];
if ($phase === 'stats') {
    // The Payment and Release desks that used to be built here are retired — the Pipeline
    // (stats_pipeline_lib.php) carries both jobs, and carries them scoped and in one list.

    // Release queue: all deliverables approved, awaiting the final results upload

}

// The audit log, for whichever phase asked for it — see $hist_table / $hist_doc_names near
// the top. Only built on the History view: it is a full-table scan of a log that only grows,
// and every other view would pay for it without showing a row.
if ($is_history_view) {
    // THE LOG IS LOADED ONE MONTH AT A TIME.
    //
    // Paging a log that only grows does not bound it — it just decides how many thousand rows you
    // scroll past before the next button. What bounds it is never rendering more than one month:
    // a month index (one row per month, however many years accumulate) plus the entries of the
    // single month that is open. Three years of daily decisions is 36 header rows and one month
    // of detail, not 1,100 rows behind a pager.
    //
    // The index is a GROUP BY over the same $hist_where the list uses, so searching narrows the
    // months themselves — a search with three hits shows the three months holding them.
    $hist_month_index = function (string $extra) use ($pdo, $hist_table, $hist_where, $hist_params) {
        $months_stmt = $pdo->prepare("
            SELECT DATE_FORMAT(pl.created_at, '%Y-%m') AS ym,
                   COUNT(*) AS n,
                   SUM(CASE WHEN pl.action IN ('accepted','received','released','cleared') THEN 1 ELSE 0 END) AS ok,
                   SUM(CASE WHEN pl.action IN ('rejected','returned','revision') THEN 1 ELSE 0 END) AS no,
                   SUM(CASE WHEN pl.action = 'nudged' THEN 1 ELSE 0 END) AS rem,
                   MAX(pl.created_at) AS last_at
            FROM $hist_table pl
            LEFT JOIN users u ON pl.student_id = u.user_id
            LEFT JOIN users actor ON pl.actor_id = actor.user_id
            WHERE 1=1 $hist_where $extra
            GROUP BY ym
            ORDER BY ym DESC
        ");
        $months_stmt->execute($hist_params);
        return $months_stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    // The pills count WITHOUT the pill filter; the index and the entries are counted WITH it.
    $hist_count_months = $hist_month_index('');
    $hist_months = $hist_act_sql !== '' ? $hist_month_index($hist_act_sql) : $hist_count_months;

    $hist_total_rows = 0;
    foreach ($hist_months as $m) $hist_total_rows += (int)$m['n'];

    // Which month is open: the one asked for, else the most recent that has anything in it. A
    // month the filters emptied is not offered, so this can never open on nothing.
    $hist_ym = (string)($_GET['hist_ym'] ?? '');
    $hist_valid_ym = array_column($hist_months, 'ym');
    if (!in_array($hist_ym, $hist_valid_ym, true)) {
        $hist_ym = $hist_valid_ym[0] ?? '';
    }

    // Within a month, a pager is still worth having — an office can clear two hundred receipts in
    // a busy week — but it is now paging one month, not the whole history.
    $hist_page = max(1, (int) ($_GET['hist_page'] ?? 1));
    $hist_per_page = 80;
    $payment_history = [];
    $hist_month_rows = 0;
    if ($hist_ym !== '') {
        $hist_month_rows = (int)($hist_months[array_search($hist_ym, $hist_valid_ym, true)]['n'] ?? 0);
        $hist_total_pages = max(1, (int) ceil($hist_month_rows / $hist_per_page));
        $hist_page = min($hist_page, $hist_total_pages);
        $hist_offset = ($hist_page - 1) * $hist_per_page;

        $hist_stmt = $pdo->prepare("
            SELECT pl.action, pl.control_no, pl.item_id, pl.remarks, pl.created_at,
                   u.research_group_name, actor.username AS actor_name
            FROM $hist_table pl
            LEFT JOIN users u ON pl.student_id = u.user_id
            LEFT JOIN users actor ON pl.actor_id = actor.user_id
            WHERE DATE_FORMAT(pl.created_at, '%Y-%m') = ? $hist_where $hist_act_sql
            ORDER BY pl.created_at DESC
            LIMIT $hist_per_page OFFSET $hist_offset
        ");
        $hist_stmt->execute(array_merge([$hist_ym], $hist_params));
        $payment_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $hist_total_pages = 1;
    }

    // The summary line counts the WHOLE filtered log, not the open month — it is the answer to
    // "how much is there", which a single month cannot give.
    $hist_stats = ['total' => 0, 'accepted' => 0, 'rejected' => 0, 'reminders' => 0];
    foreach ($hist_count_months as $m) {
        $hist_stats['reminders'] += (int)$m['rem'];
        $hist_stats['total']    += (int)$m['n'];
        $hist_stats['accepted'] += (int)$m['ok'];
        $hist_stats['rejected'] += (int)$m['no'];
    }
    $hist_stats['today'] = (int) ($hist_stats['today'] ?? 0);
    $hist_stats['accept_rate'] = $hist_stats['total'] > 0 ? round($hist_stats['accepted'] / $hist_stats['total'] * 100) : 0;

}

// ws_group_rosters() / ws_names_markup() now live in group_roster_lib.php — the scheduling screen
// needs the same roster and is included (and exits) long before this file gets here.

// Rosters for every group on the review inbox, so "View names" on a tile is one query for the
// screen rather than one per card.
$ws_rosters = $use_group_inbox ? ws_group_rosters($pdo, array_keys($submissions_by_group)) : [];

// The release queue: every group that has CLEARED BOTH FINAL PHASES — a 'Scheduled' booking means
// Form 002 was approved, all six phase-2 documents were approved, and the office confirmed the
// date, so the group holds its official ticket. Those are the only groups a manuscript can be
// asked of, and they all appear here.
//
// The defense day itself is NOT a filter. It is shown on each row, and rows whose day has passed
// sort first, but the office decides when a group is actually done — a defense can run early, be
// moved, or be recorded oddly, and a hard date gate would make this screen unusable in all three
// cases. Driven off defense_bookings, not uploads: at this point the group has uploaded nothing
// for this phase, which is the whole point.
/**
 * Where a notified group got to in Plagiarism, for the Notify screen's record: a position on the
 * four-node spine (Manuscript · Payment · Turnitin · Cleared), the words, whether it is a
 * send-back, and the side-panel bucket. Reads plagiarism_checks.status; writes nothing.
 */
function nt_plag_stage(string $status): array
{
    $map = [
        'Phase 1: Pending Manuscript'   => [0, 'Waiting for their manuscript', false, 'wait'],
        'Phase 1: Manuscript Review'    => [0, 'Manuscript received',          false, 'wait'],
        'Phase 1: Manuscript Returned'  => [0, 'Manuscript sent back',         true,  'wait'],
        'Phase 2: Form Download'        => [1, 'Paying at finance',            false, 'pay'],
        'Phase 3: Payment Verification' => [1, 'Payment being checked',        false, 'pay'],
        'Phase 4: Registered'           => [2, 'Turnitin running',             false, 'scan'],
        'Phase 5: Revision Requested'   => [2, 'Revision requested',           true,  'scan'],
        'Phase 5: Cleared'              => [4, 'Cleared',                      false, 'done'],
    ];
    [$at, $label, $bad, $bucket] = $map[$status] ?? [0, $status !== '' ? $status : 'Waiting for their manuscript', false, 'wait'];
    return ['at' => $at, 'label' => $label, 'bad' => $bad, 'bucket' => $bucket];
}
$plag_release_rows = [];
$plag_release_done = $nt_ready = $nt_soon = $nt_recent = [];
$nt_stage_counts = ['wait' => 0, 'pay' => 0, 'scan' => 0, 'done' => 0];
$plag_release_waiting = 0;
$plag_release_done_count = 0;
if ($phase === 'plag' && $plag_view === 'release') {
    $plag_release_rows = $pdo->prepare("
        SELECT u.user_id, u.username, u.student_number, u.research_group_name, u.department,
               u.program, u.profile_pic,
               b.defense_date, b.slot_no,
               pc.released_at, pc.status AS plag_status, pc.formatted_control_no,
               a.username AS released_by_name,
               -- Ages in MySQL: PHP runs Europe/Berlin and would misread a stored DATETIME.
               TIMESTAMPDIFF(DAY, pc.released_at, NOW()) AS released_days,
               (b.defense_date < ?) AS has_defended
        FROM defense_bookings b
        JOIN users u ON u.user_id = b.user_id
        LEFT JOIN plagiarism_checks pc ON pc.user_id = u.user_id
        LEFT JOIN users a ON a.user_id = pc.released_by
        WHERE b.phase = 'final' AND b.status = 'Scheduled'
        ORDER BY (pc.released_at IS NOT NULL) ASC,   -- still to do, first
                 has_defended DESC,                  -- of those, the ones already defended
                 b.defense_date ASC");
    $plag_release_rows->execute([df_today()]);
    $plag_release_rows = $plag_release_rows->fetchAll(PDO::FETCH_ASSOC);

    // Two lists behind one pill, same as every other desk: groups still to be told, and groups
    // already told. Mixing them meant the office scrolled past everyone it had already notified to
    // find the one it hadn't — the list only ever grows, so that gets worse every cycle.
    $plag_release_todo = [];
    $plag_release_done = [];
    foreach ($plag_release_rows as $r) {
        if (empty($r['released_at'])) $plag_release_todo[] = $r;
        else $plag_release_done[] = $r;
    }
    $plag_release_waiting = count($plag_release_todo);
    // Notified reads newest-first — it is a record of what was sent, not a queue to work down.
    usort($plag_release_done, function ($a, $b) {
        return strcmp($b['released_at'] ?: '', $a['released_at'] ?: '');
    });
    $plag_release_rows = $plag_arch ? $plag_release_done : $plag_release_todo;
    $plag_release_done_count = count($plag_release_done);

    // ── The Notify screen's view model (2026-09-29 redesign, .nt-*) ──────────────────────────
    // Waiting splits in two: groups whose defense day has PASSED (ready — the one waiting longest
    // leads as the big card) and groups still to defend (notifying them is allowed, but early).
    // The todo list is already sorted defended-first, oldest date first, so order is kept.
    $nt_ready = array_values(array_filter($plag_release_todo, fn($r) => !empty($r['has_defended'])));
    $nt_soon  = array_values(array_filter($plag_release_todo, fn($r) => empty($r['has_defended'])));
    // Notified is a follow-up list, not a trophy case: where each group got to in Plagiarism.
    $nt_stage_counts = ['wait' => 0, 'pay' => 0, 'scan' => 0, 'done' => 0];
    foreach ($plag_release_done as &$r) {
        $r['nt'] = nt_plag_stage((string)($r['plag_status'] ?? ''));
        $nt_stage_counts[$r['nt']['bucket']]++;
    }
    unset($r);
    $plag_release_rows = $plag_arch ? $plag_release_done : $plag_release_todo;
    $nt_recent = array_slice($plag_release_done, 0, 4);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <title>Admin - <?= htmlspecialchars($current_phase['title']) ?></title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <!-- Shared design system (portal.js also listens for the parent shell's theme postMessage) -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* ── Payment Verification: first-come-first-served queue cards ───────────── */
        .pay-queue { display: flex; flex-direction: column; gap: 12px; }

        .pay-card {
            display: flex;
            background: var(--bg-white, #fff);
            border: 1.5px solid var(--border-line);
            border-radius: 16px;
            overflow: hidden;
            transition: box-shadow .2s ease, transform .2s ease;
        }
        .pay-card:hover { box-shadow: 0 12px 28px -14px rgba(15, 23, 42, .22); transform: translateY(-1px); }
        .pay-card.is-ready { background: color-mix(in srgb, var(--warning) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 26%, var(--line)); }

        /* Ticket stub (queue number) */
        .pay-ticket {
            flex-shrink: 0;
            width: 66px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            background: linear-gradient(160deg, rgba(245, 158, 11, .12), rgba(245, 158, 11, .02));
            border-right: 1.5px dashed var(--border-line);
        }
        .pay-card.is-waiting .pay-ticket { background: linear-gradient(160deg, rgba(100, 116, 139, .10), rgba(100, 116, 139, .02)); }
        .pay-ticket-label { font-size: 8px; font-weight: 800; letter-spacing: .14em; color: var(--text-muted); }
        .pay-ticket-no { font-family: 'JetBrains Mono', monospace; font-size: 25px; font-weight: 800; color: var(--warning); line-height: 1; }
        .pay-card.is-waiting .pay-ticket-no { color: var(--muted); }
        .pay-wait { font-size: 8px; font-weight: 700; color: var(--text-muted); text-align: center; line-height: 1.1; margin-top: 2px; }

        .pay-main { flex: 1; min-width: 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }

        .pay-identity { display: flex; align-items: center; gap: 12px; }
        .pay-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-line); flex-shrink: 0; background: var(--surface-2); }
        .pay-id-text { flex: 1; min-width: 0; }
        .pay-group { font-size: 14px; font-weight: 750; color: var(--mcnp-teal); margin: 0; line-height: 1.25; }
        .pay-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pay-email { font-size: 11px; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; }
        .pay-email i { width: 11px; height: 11px; flex-shrink: 0; }
        .pay-status-chip { flex-shrink: 0; font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
        .pay-status-chip.ready { background: rgba(245, 158, 11, .14); color: var(--warning); }
        .pay-status-chip.waiting { background: rgba(100, 116, 139, .12); color: var(--muted); }

        .pay-docs-label { display: block; font-size: 9.5px; font-weight: 750; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-bottom: 7px; }
        .pay-docs-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .pay-doc-chip {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 7px 11px; border: 1.5px solid var(--border-line); border-radius: 10px;
            background: transparent; cursor: pointer; font-size: 12px; font-weight: 600;
            color: inherit; font-family: inherit; transition: all .18s ease;
        }
        .pay-doc-chip:hover { border-color: var(--mcnp-teal); background: rgba(20, 184, 166, .06); }
        .pay-doc-chip i { width: 13px; height: 13px; }
        .pay-doc-missing { font-size: 11.5px; color: var(--muted); display: inline-flex; align-items: center; gap: 5px; }
        .pay-doc-missing i { width: 12px; height: 12px; }

        .pay-register { display: flex; gap: 8px; align-items: center; }
        .pay-register input[type="text"] {
            flex: 1; min-width: 0; padding: 9px 12px; border: 1.5px solid var(--border-line);
            border-radius: var(--control-radius, 10px); font-size: 13px; background: transparent; color: inherit;
            font-family: 'JetBrains Mono', monospace;
        }
        .pay-register input[type="text"]:focus { outline: none; border-color: var(--warning); }
        .pay-register .btn-update { white-space: nowrap; flex-shrink: 0; }
        /* The send-back row carries a document picker as well as a reason. Without a fixed basis
           the select grows to its widest option and pushes the rest onto a second line. */
        .pay-register select {
            flex: 0 0 190px; width: 190px; padding: 9px 12px;
            border: 1.5px solid var(--border-line); border-radius: var(--control-radius, 10px);
            background: transparent; color: inherit; font-family: inherit; font-size: 12.5px;
        }
        .pay-register + .pay-register { margin-top: 8px; }
        .pay-readonly-note { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; color: var(--text-muted); background: var(--surface-2); border: 1px dashed var(--border-line); border-radius: 8px; padding: 8px 12px; }




        /* ── The decision log ────────────────────────────────────────────────────────────
           This log only grows, and paging it did not bound it — it just chose how many
           thousand rows you scrolled past before the next button. So the page never renders
           more than ONE MONTH: a row per month always, the open one carrying its entries.
           Three years of daily decisions is 36 header rows plus one month of detail. */
        .lg { /* flat and full width, like the Progress and Checklist views beside it */ }

        .lg-top { display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
            padding: 0 0 14px; margin-bottom: 4px; border-bottom: 1px solid var(--line); }
        .lg-title { flex: 1; min-width: 200px; }
        .lg-title h3 { margin: 0; font-size: 15px; font-weight: 800; letter-spacing: -.015em; color: var(--ink); }
        .lg-title p { margin: 3px 0 0; font-size: 11.5px; color: var(--muted); }
        .lg-title b { color: var(--ink); font-weight: 800; }
        .lg-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .lg-stat { text-decoration: none; }
        .lg-stat.is-zero:not(.active) { opacity: .5; }
        .lg-stat.is-ok .pl-count { background: color-mix(in srgb, var(--success) 16%, var(--surface));
            color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .lg-stat.is-no .pl-count { background: color-mix(in srgb, var(--danger) 13%, var(--surface));
            color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .lg-matching { font-size: 12px; font-weight: 600; color: var(--muted); }

        .lg-tools { display: flex; align-items: center; gap: 8px; }
        .lg-search { position: relative; }
        .lg-search i, .lg-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            width: 15px; height: 15px; color: var(--muted); pointer-events: none; }
        /* portal.css:346 puts width:100% on every text input — an inline field must say otherwise. */
        .lg-search input { width: 218px; box-sizing: border-box; font-family: inherit; font-size: 12.5px;
            color: var(--ink); background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--r-pill); padding: 9px 14px 9px 34px; }
        .lg-search input:focus { outline: none; border-color: color-mix(in srgb, var(--accent) 45%, var(--line));
            box-shadow: 0 0 0 3px var(--accent-ghost); }

        .lg-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700;
            color: var(--ink-soft); background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--r-pill); padding: 8px 12px; text-decoration: none; }
        .lg-btn:hover { background: var(--surface-3); color: var(--ink); }
        .lg-btn i, .lg-btn svg { width: 14px; height: 14px; }

        /* ── month rows ── */
        .lg-month + .lg-month { border-top: 1px solid var(--line); }
        .lg-mhead { display: flex; align-items: center; gap: 12px; padding: 14px 2px;
            text-decoration: none; cursor: pointer; transition: background var(--dur-fast); }
        .lg-mhead:hover { background: var(--surface-2); }
        .lg-caret { width: 15px; height: 15px; flex-shrink: 0; color: var(--muted);
            transition: transform var(--dur-fast); }
        .lg-month.is-open .lg-caret { transform: rotate(90deg); color: var(--accent); }
        .lg-mname { flex-shrink: 0; width: 132px; font-size: 13px; font-weight: 750; color: var(--ink); }
        .lg-month.is-open .lg-mname { color: var(--accent); }
        /* The month's shape without opening it: how much of it moved forward. */
        /* Three segments, not two: the remainder is reminders and parks, which are neither a
           pass nor a fail. A pink track claimed they were failures. */
        .lg-bar { flex: 0 1 260px; height: 5px; min-width: 60px; border-radius: 3px; overflow: hidden;
            background: var(--surface-3); display: flex; }
        .lg-seg { height: 100%; }
        .lg-seg.ok { background: var(--success); }
        .lg-seg.no { background: var(--danger); }
        .lg-mn { flex-shrink: 0; margin-left: auto; min-width: 34px; text-align: right; font-family: 'JetBrains Mono', monospace;
            font-size: 12px; font-weight: 800; color: var(--muted); }
        .lg-month.is-open .lg-mn { color: var(--ink); }

        /* ── the open month ── */
        .lg-body { padding: 2px 0 18px 16px; margin-left: 6px;
            border-left: 2px solid color-mix(in srgb, var(--accent) 22%, var(--line)); }
        .lg-day { display: flex; align-items: center; gap: 10px; margin: 14px 0 2px; }
        .lg-dname { font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
            color: var(--muted); }
        .lg-drule { flex: 1; height: 1px; background: var(--line); }

        .lg-row { display: flex; gap: 12px; padding: 9px 10px; margin: 0 -4px; border-radius: 10px;
            transition: background var(--dur-fast); }
        .lg-row:hover { background: var(--surface-2); }
        /* One soft disc carries the verdict — the row itself stays uncoloured, so a screen of
           them reads as a list rather than as bunting. */
        .lg-ico { flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; display: grid;
            place-items: center; background: var(--surface-3); color: var(--muted); }
        .lg-ico i, .lg-ico svg { width: 14px; height: 14px; }
        .lg-row.ok .lg-ico { background: color-mix(in srgb, var(--success) 15%, var(--surface));
            color: color-mix(in srgb, var(--success) 62%, var(--ink)); }
        .lg-row.no .lg-ico { background: color-mix(in srgb, var(--danger) 14%, var(--surface));
            color: color-mix(in srgb, var(--danger) 62%, var(--ink)); }

        .lg-main { flex: 1; min-width: 0; }
        .lg-line { display: flex; align-items: baseline; gap: 12px; }
        .lg-group { flex: 1; min-width: 0; font-size: 13px; font-weight: 700; color: var(--ink); line-height: 1.4; }
        .lg-time { flex-shrink: 0; font-size: 11px; color: var(--muted); }
        .lg-sub { margin-top: 2px; font-size: 11.5px; color: var(--muted); display: flex;
            flex-wrap: wrap; align-items: center; gap: 4px 10px; }
        .lg-act { font-weight: 700; color: var(--ink-soft); }
        .lg-row.ok .lg-act { color: color-mix(in srgb, var(--success) 58%, var(--ink)); }
        .lg-row.no .lg-act { color: color-mix(in srgb, var(--danger) 58%, var(--ink)); }
        .lg-cn { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; color: var(--ink-soft); }
        .lg-doc { font-size: 10.5px; }
        .lg-note { margin: 5px 0 0; padding-left: 9px; border-left: 2px solid var(--line);
            font-size: 11.5px; font-style: italic; color: var(--muted); }

        .lg-pager { display: flex; align-items: center; justify-content: center; gap: 12px;
            margin-top: 16px; font-size: 11.5px; font-weight: 700; color: var(--muted); }

        @media (max-width: 760px) {
            .lg-tools { width: 100%; }
            .lg-search { flex: 1; }
            .lg-search input { width: 100%; }
            .lg-mname { width: auto; flex: 1; }
            .lg-bar { display: none; }
        }

        /* ── THE PIPELINE ────────────────────────────────────────────────────────────────
           Her work used to be three screens — Checklist, Payment, Release — each showing a
           slice of the same journey, so "where is this group" meant opening all three and
           "who is holding this up" could not be asked at all. This is one row per request,
           carrying the whole ladder, sorted so her move comes first.

           The SPINE is the point. Six nodes; everything behind them filled, the one they are
           standing on coloured by whose move it is, everything ahead hollow. Colour carries
           the only thing she cannot infer: amber = yours, slate = theirs, green = done. */
        .sp-wrap { margin-bottom: 25px; }

        .sp-legend { display: flex; gap: 14px; margin-left: auto; }
        .sp-legend span { display: inline-flex; align-items: center; gap: 6px; font-size: 11px;
            font-weight: 600; color: var(--muted); }
        .sp-k { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
        .sp-k.done { background: var(--success); }
        .sp-k.you  { background: var(--warning); }
        .sp-k.them { background: var(--muted); }
        .sp-k.todo { border: 1.5px dashed var(--line); }

        .sp-row { background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
            margin-bottom: 8px; transition: border-color var(--dur-fast), box-shadow var(--dur-fast); }
        .sp-row[hidden] { display: none !important; }
        /* Her move gets the only accent on the screen — everything else is reference. */
        .sp-row.is-you { border-color: color-mix(in srgb, var(--warning) 40%, var(--line));
            background: color-mix(in srgb, var(--warning) 4%, var(--surface)); }
        .sp-row.is-done { opacity: .62; }
        .sp-row:hover { box-shadow: 0 6px 16px -12px rgba(0,0,0,.45); }

        .sp-head { display: flex; align-items: center; gap: 14px; padding: 13px 15px; cursor: pointer; }
        .sp-who { flex: 0 0 254px; min-width: 0; }
        .sp-cn { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; color: var(--ink-soft); }
        .sp-flag { font-size: 9.5px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase;
            background: color-mix(in srgb, var(--danger) 14%, var(--surface));
            color: color-mix(in srgb, var(--danger) 64%, var(--ink));
            border-radius: var(--r-pill); padding: 2px 7px; }

        /* ── the spine ── */
        .sp-spine { flex: 1; min-width: 0; display: flex; align-items: flex-start;
            list-style: none; margin: 0; padding: 0; }
        .sp-node { flex: 1; position: relative; display: flex; flex-direction: column;
            align-items: center; gap: 6px; }
        /* The connector is drawn by each node except the first, so it always spans exactly the
           gap between two dots and never overshoots the ends of the row. */
        .sp-node + .sp-node::before { content: ''; position: absolute; top: 6px; height: 2px;
            right: 50%; left: -50%; background: var(--line); }
        .sp-node.done + .sp-node.done::before,
        .sp-node.done + .sp-node.you::before,
        .sp-node.done + .sp-node.them::before { background: var(--success); }
        .sp-dot { width: 13px; height: 13px; border-radius: 50%; position: relative; z-index: 1;
            background: var(--surface); border: 2px solid var(--line); }
        .sp-node.done .sp-dot { background: var(--success); border-color: var(--success); }
        .sp-node.them .sp-dot { background: var(--muted); border-color: var(--muted); }
        .sp-node.you  .sp-dot { background: var(--warning); border-color: var(--warning);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--warning) 26%, transparent); }
        .sp-node.todo .sp-dot { width: 9px; height: 9px; margin: 2px 0;
            background: var(--surface-2); border-color: var(--line); }
        .sp-lab { font-size: 9.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--muted); white-space: nowrap; }
        .sp-node.you .sp-lab { color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        .sp-node.done .sp-lab { color: var(--ink-soft); }

        .sp-next { flex: 0 0 186px; text-align: right; line-height: 1.3; }
        .sp-age { display: block; font-family: 'JetBrains Mono', monospace; font-size: 13px;
            font-weight: 800; color: var(--ink); }
        .sp-do { font-size: 11px; font-weight: 600; color: var(--muted); }
        .sp-row.is-you .sp-do { color: color-mix(in srgb, var(--warning) 64%, var(--ink)); }

        .sp-body { padding: 0 15px 14px; border-top: 1px solid var(--line);
            display: flex; flex-direction: column; gap: 11px; }
        .sp-body[hidden] { display: none !important; }
        .sp-body > *:first-child { margin-top: 13px; }
        .sp-act { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .sp-act input[type=file] { flex: 1; min-width: 0; font-size: 12px; color: var(--ink); }

        /* ── the Treat verdict: release, or send back with ticks ── */
        .sp-rel { display: flex; flex-direction: column; gap: 10px; }
        .sp-rel [hidden] { display: none !important; }
        .sp-rel textarea, .sp-tick input[type=text] { width: 100%; box-sizing: border-box; font-family: inherit;
            font-size: 12.5px; color: var(--ink); background: var(--surface); border: 1.5px solid var(--line);
            border-radius: 9px; padding: 8px 11px; resize: vertical; }
        .sp-rel textarea:focus, .sp-tick input[type=text]:focus { outline: none; border-color: var(--accent); }
        .sp-verdict { display: inline-flex; align-self: flex-start; padding: 3px; gap: 3px;
            background: var(--surface-2); border: 1px solid var(--line); border-radius: 11px; }
        .sp-verdict label { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
            border-radius: 8px; font-size: 12px; font-weight: 700; color: var(--muted); cursor: pointer; }
        .sp-verdict label i, .sp-verdict label svg { width: 14px; height: 14px; }
        .sp-verdict input { position: absolute; opacity: 0; pointer-events: none; }
        .sp-verdict label:has(input:checked) { background: var(--surface); color: var(--ink);
            box-shadow: 0 1px 3px color-mix(in srgb, var(--ink) 14%, transparent); }
        .sp-verdict label:has(input[value=revise]:checked) { color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .sp-v-revise { display: flex; flex-direction: column; gap: 6px; }
        .sp-hint { margin: 0; font-size: 11.5px; color: var(--muted); }
        .sp-tick { display: grid; grid-template-columns: minmax(150px, 220px) 1fr; align-items: center; gap: 8px;
            padding: 6px 8px; border: 1px solid var(--line); border-radius: 9px; }
        .sp-tick.on { background: color-mix(in srgb, var(--warning) 9%, transparent);
            border-color: color-mix(in srgb, var(--warning) 45%, var(--line)); }
        .sp-tick label { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px;
            font-weight: 700; color: var(--ink); cursor: pointer; }
        .sp-tick input[type=text] { padding: 6px 9px; }
        .sp-tick:not(.on) input[type=text] { opacity: .5; }
        @media (max-width: 640px) { .sp-tick { grid-template-columns: 1fr; } }
        .sp-released { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
        .sp-rel-note { margin: 0; font-size: 12.5px; color: var(--ink); line-height: 1.45; }
        .sp-fix summary { display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
            font-size: 12px; font-weight: 700; color: var(--muted); list-style: none; }
        .sp-fix summary::-webkit-details-marker { display: none; }
        .sp-fix summary i, .sp-fix summary svg { width: 14px; height: 14px; }
        .sp-fix[open] summary { margin-bottom: 10px; color: var(--ink); }

        /* ── the control-number composer ──
           She supplies the sequence; year, institution and course code are derived from the
           group and are not hers to pick. Showing the fixed part as text she cannot edit, and
           the whole string before she commits, replaces finding out what she minted afterwards. */
        .sp-cn-build { display: inline-flex; align-items: stretch; border: 1.5px solid var(--line);
            border-radius: 10px; overflow: hidden; background: var(--surface); }
        .sp-cn-fixed { display: inline-flex; align-items: center; padding: 9px 2px 9px 12px;
            font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700;
            color: var(--muted); background: var(--surface-2); white-space: nowrap; user-select: none; }
        .sp-cn-seq { width: 96px; border: none; background: transparent; padding: 9px 12px 9px 4px;
            font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 800; color: var(--ink); }
        .sp-cn-seq:focus { outline: none; }
        .sp-cn-build:focus-within { border-color: var(--warning); }
        .sp-cn-out { margin: 0; font-size: 12px; color: var(--muted); }
        /* The global text-input style (border, fill, radius, padding) was drawing a second box
           INSIDE the composer. The composer is the box; the input is just the typing area. */
        .sp-cn-build .sp-cn-seq { border: none !important; box-shadow: none !important; background: transparent !important;
            border-radius: 0 !important; margin: 0 !important; height: auto; }
        .sp-cn-seq::placeholder { color: color-mix(in srgb, var(--muted) 50%, transparent); font-weight: 600; }
        .sp-cn-seq.cn-wide { width: auto; flex: 1; min-width: 0; }
        /* The instruction rides on the preview line, after a dot: there if you look, never a line of its own. */
        .sp-cn-hint { font-size: 11px; color: color-mix(in srgb, var(--muted) 72%, transparent); }
        .sp-cn-hint::before { content: '·'; margin: 0 7px; }
        /* Progress: box + button on ONE row, the preview line under both. */
        .sp-reg { display: grid; grid-template-columns: minmax(0, 400px) auto; justify-content: start;
            align-items: stretch; gap: 7px 10px; }
        .sp-reg .sp-cn-build { display: flex; }
        .sp-reg .sp-cn-out { grid-column: 1 / -1; }
        @media (max-width: 560px) { .sp-reg { grid-template-columns: 1fr; } }
        .modal-cn { display: flex; flex-direction: column; gap: 6px; margin-top: 4px; }
        .modal-cn[hidden] { display: none !important; }
        .modal-cn .sp-cn-build { display: flex; }
        .sp-cn-out b { font-family: 'JetBrains Mono', monospace; font-size: 12.5px; color: var(--ink); }

        /* The spine is the first thing to give up its room; identity and next action matter more. */
        @media (max-width: 1100px) {
            .sp-head { flex-wrap: wrap; }
            .sp-who { flex: 1 1 100%; order: -1; }
            .sp-spine { order: 1; flex: 1 1 100%; margin-top: 4px; }
            .sp-next { order: 2; flex: 1 1 auto; text-align: left; }
            .pl-open { order: 3; margin-left: auto; }
        }
        /* ── Payment desk: ROWS, not cards (2026-09-26) ──────────────────────────────────
           Same call admin_accounts.php made for the same reason — a list that grows needs a
           row. The old card spent ~250px per group on a QUEUE ticket stub; this is ~64px
           collapsed, so the whole desk fits on one screen and still scales past fifty.
           Tokens are the canonical --line / --muted / --ink / --surface set, the ones
           portal.css defines per theme: a lane styled light-only prints grey on white in dark. */
        .pl-desk { margin-bottom: 25px; }

        .pl-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }

        .pl-pills { display: inline-flex; gap: 4px; background: var(--surface-2);
            border: 1px solid var(--line); border-radius: var(--r-pill); padding: 4px; }
        .pl-pill-btn { font-family: inherit; font-size: 12.5px; font-weight: 700; color: var(--muted);
            background: transparent; border: none; border-radius: var(--r-pill); padding: 7px 14px;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            transition: background var(--dur-fast), color var(--dur-fast); }
        .pl-pill-btn:hover:not(:disabled) { color: var(--ink); }
        .pl-pill-btn.active { background: var(--surface); color: var(--ink);
            box-shadow: 0 1px 3px rgba(0,0,0,.10); }
        /* portal.css styles no :disabled, so an empty lane needs this or it looks clickable. */
        .pl-pill-btn:disabled { opacity: .42; cursor: default; }
        .pl-count { font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 800;
            background: var(--surface-3); color: var(--muted); border-radius: var(--r-pill); padding: 1px 7px; }
        .pl-pill-btn.active .pl-count { background: color-mix(in srgb, var(--warning) 20%, var(--surface));
            color: color-mix(in srgb, var(--warning) 64%, var(--ink)); }

        /* ── one group: a row ── */
        .pl-row { background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
            margin-bottom: 8px; transition: border-color var(--dur-fast), box-shadow var(--dur-fast); }
        .pl-row:hover { border-color: color-mix(in srgb, var(--warning) 38%, var(--line));
            box-shadow: 0 6px 16px -12px rgba(0,0,0,.45); }
        .pl-row.is-parked { opacity: .68; }
        .pl-av { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; object-fit: cover;
            background: var(--surface-3); }
        .pl-nm { font-size: 13.5px; font-weight: 750; color: var(--ink); line-height: 1.35;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .pl-sid { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 700;
            color: var(--muted); background: var(--surface-2); border: 1px solid var(--line);
            border-radius: var(--r-pill); padding: 1px 7px; }
        .pl-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Two dots, one per document. Colour IS the status, so the row answers "can I register
           this?" without being opened — which is the whole point of collapsing it. */
        .pl-docs { display: flex; gap: 5px; flex-shrink: 0; }

        .pl-open { flex-shrink: 0; font-family: inherit; font-size: 12px; font-weight: 700;
            color: var(--ink-soft); background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--r-pill); padding: 7px 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; }
        .pl-open:hover { background: var(--surface-2); }
        /* lucide swaps <i> for <svg>, so size BOTH or the chevron jumps to 24px once it runs. */
        .pl-open i, .pl-open svg { width: 13px; height: 13px; transition: transform var(--dur-fast); }
        .pl-open[aria-expanded="true"] i, .pl-open[aria-expanded="true"] svg { transform: rotate(180deg); }
        .pl-docs-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .pl-acts { display: flex; flex-wrap: wrap; gap: 8px; }

        /* Everything she would otherwise have opened a group card to read. */
        .rl-facts { display: flex; flex-wrap: wrap; gap: 8px 18px; }
        .rl-facts span { display: inline-flex; align-items: center; gap: 7px; font-size: 11.5px;
            font-weight: 600; color: var(--muted); }
        .rl-facts i, .rl-facts svg { width: 14px; height: 14px; flex-shrink: 0; }

        @media (max-width: 720px) { .rl-cn { order: 3; } }

        .pl-note { display: inline-flex; align-items: center; gap: 7px; font-size: 11.5px;
            font-weight: 600; color: var(--muted); }
        .pl-note i, .pl-note svg { width: 14px; height: 14px; flex-shrink: 0; }

        .pay-btn-ghost { display: inline-flex; align-items: center; gap: 7px; font-family: inherit;
            font-size: 12px; font-weight: 700; color: var(--ink); background: var(--surface);
            border: 1.5px solid var(--line); border-radius: 9px; padding: 8px 13px; cursor: pointer;
            transition: border-color .15s ease, background .15s ease; }
        .pay-btn-ghost:hover { border-color: var(--warning); background: color-mix(in srgb, var(--warning) 8%, var(--surface)); }
        .pay-btn-ghost i, .pay-btn-ghost svg { width: 14px; height: 14px; flex-shrink: 0; }

        /* Below ~720px the row gives up its middle columns rather than scrolling sideways. */
        @media (max-width: 720px) {
            .pl-top { flex-wrap: wrap; gap: 10px; }
            .pl-open { margin-left: auto; }
        }

        @media (max-width: 640px) {
            .pay-register { flex-wrap: wrap; }
            .pay-register .btn-update { width: 100%; }
        }

        /* Payment decision history (accept/reject audit trail) */
        /* Housekeeping actions (a replaced Turnitin report) — neither a pass nor a rejection. */

        /* ── Group Inbox (Proposal): one profile card, requirement mini-cards inside ───────── */

        /* One toolbar row: status filters left, layout lens hard right. Wraps to two rows only
           when the viewport genuinely can't hold both. */
        .review-toolbar {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            margin-bottom: 22px; padding-bottom: 13px; border-bottom: 1px solid var(--line);
        }
        .status-tabs-container { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; min-width: 0; }
        /* The count, on the tab. Inherits the tab's own ink so it reads correctly on both the
           active (accent) and idle (muted) states without a second colour rule. */
        .status-filter-tab .tab-n { font-size: 11px; font-weight: 800; line-height: 1;
            background: rgba(0,0,0,.09); border-radius: var(--r-pill); padding: 2px 7px; }
        .status-filter-tab.active .tab-n { background: rgba(255,255,255,.22); }
        /* An empty tab stops inviting the click. It stays visible — knowing there is no revision
           history IS the answer — but it cannot be entered, so nobody lands on a blank page. */
        /* Dimmed, NEVER dead. It used to be pointer-events:none, so clicking Approved Archive
           locked out every zero-count tab — including Action Needed, the way back to the queue.
           A zero tab just opens onto its empty state. */
        .status-filter-tab.is-empty { opacity: .5; }
        .status-filter-tab.is-empty:hover { opacity: .8; }
        .status-filter-tab.is-empty .tab-n { background: transparent; }

        .grp-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-line); background: var(--surface-2); flex-shrink: 0; }
        .grp-name { font-size: 15px; font-weight: 800; color: var(--mcnp-teal); font-family: var(--font-ui); letter-spacing: -0.02em; line-height: 1.2; }
        .grp-sub { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }

        /* School ID number chip — reused on every card that identifies a group's leader.
           Fixed-width content (2023-10078), so it never needs to wrap or truncate. */
        .school-id-chip {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10.5px; font-weight: 700; letter-spacing: 0.02em;
            padding: 2px 7px; border-radius: 20px; white-space: nowrap;
            background: rgba(100, 116, 139, .12); color: var(--text-muted);
        }
        .school-id-chip i { width: 11px; height: 11px; flex-shrink: 0; }
        .grp-file-badge { background: var(--surface-2) !important; color: var(--muted) !important; }

        .grp-req-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; padding: 4px; }

        /* ---- arrival ----
           A grid of twenty cards appearing in one frame reads as a jump; the same twenty landing
           over a fifth of a second reads as a page settling. The delay is capped so the last card
           in a long grid is not still arriving after the reviewer has started reading.
           --i is set by the renderer; absent, every card is simply at 0. */
        @media (prefers-reduced-motion: no-preference) {
            .grp-req-grid > .grp-req-card, .grp-tiles > .grp-tile {
                animation: nxRise .34s var(--ease, cubic-bezier(.2,.7,.3,1)) backwards;
                animation-delay: calc(var(--i, 0) * 26ms);
            }
            @keyframes nxRise { from { opacity: 0; transform: translateY(7px); } }
            /* The signature lands rather than blinks on. */
            .sg-mark { animation: nxPop .32s cubic-bezier(.2,1.5,.4,1) backwards; animation-delay: .18s; }
            @keyframes nxPop { from { opacity: 0; transform: scale(.4); } }

            /* THE one live signal on a requirement card: you have not opened this file yet.
               It is a fact about the reviewer, true at this second, and it stops the instant
               the document is opened — which is exactly what earns it motion. Nothing else on
               the card changes without a reload, so nothing else moves.

               Deliberately NO entrance stagger on .ws-card: opening a group is a display swap
               over already-rendered DOM, and display:none -> block RE-RUNS css animations, so
               a stagger would replay all twenty cards every time the reviewer comes back from
               a decision. */
            .ws-unseen { animation: wsUnseen 2.6s var(--ease) infinite; }
            @keyframes wsUnseen {
                0%, 70%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--info) 45%, transparent); }
                35%           { box-shadow: 0 0 0 5px color-mix(in srgb, var(--info) 0%, transparent); }
            }
        }
        /* No coloured rail down the edge. The card already says its state twice — a tint and a
           pill — and a third, louder voice turned a wall of these into a colour chart. State is
           now the faintest possible ground plus the border it borrows, which is enough to scan
           and quiet enough to read. */
        .grp-req-card {
            position: relative; display: flex; flex-direction: column; gap: 8px; padding: 14px;
            border: 1.5px solid var(--line); border-radius: 13px; background: var(--surface);
            transition: box-shadow .22s var(--ease, ease), transform .22s var(--ease, ease), border-color .22s ease;
        }
        .grp-req-card:hover { box-shadow: var(--shadow-2); transform: translateY(-2px);
            border-color: color-mix(in srgb, var(--accent) 38%, var(--line)); }
        .grp-req-card.pending, .grp-req-card.review {
            background: color-mix(in srgb, var(--warning) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 26%, var(--line)); }
        .grp-req-card.approved {
            background: color-mix(in srgb, var(--success) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--success) 24%, var(--line)); }
        .grp-req-card.revision {
            background: color-mix(in srgb, var(--danger) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 26%, var(--line)); }
        .grp-req-card.cascaded { background: var(--surface-2); border-color: var(--line); }

        /* ---- who signed it ----
           Outside the card: one mark, so a grid of requirements shows at a glance which are
           somebody's signature and which are still nobody's. Inside: the name, the capacity and
           the day, because "approved" without an author is not a record of anything. */
        .sg-mark { position: absolute; top: -7px; right: -7px; width: 22px; height: 22px;
            border-radius: 50%; display: grid; place-items: center; z-index: 1;
            background: var(--success); color: #fff;
            box-shadow: 0 0 0 3px var(--surface); }
        .sg-mark.unknown { background: var(--surface-3); color: var(--muted); }
        .sg-mark i, .sg-mark svg { width: 13px; height: 13px; }

        .sg-by { display: flex; align-items: center; gap: 8px; margin-top: 2px; padding-top: 9px;
            border-top: 1px dashed var(--line); }
        .sg-by img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
            background: var(--surface-2); }
        .sg-by span { min-width: 0; line-height: 1.25; }
        .sg-by b { display: block; font-size: 11.5px; font-weight: 750; color: var(--ink);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sg-by em { display: block; font-style: normal; font-size: 10px; font-weight: 700;
            letter-spacing: .03em; text-transform: uppercase; color: var(--success); }
        .sg-by .sg-none { font-size: 10.5px; font-style: italic; color: var(--muted); }

        .grp-req-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .grp-req-name { font-size: 13px; font-weight: 750; color: var(--text-dark, var(--ink)); line-height: 1.3; }
        .grp-req-score { font-size: 11px; font-weight: 700; color: var(--warning); }
        .grp-req-file {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 9px; border: 1.5px solid var(--border-line);
            border-radius: 8px; background: transparent; cursor: pointer; font-size: 11.5px; font-weight: 600;
            color: var(--info); font-family: inherit; max-width: 100%; transition: all .18s ease;
        }
        .grp-req-file:hover { border-color: var(--info); background: rgba(59,130,246,.06); }
        .grp-req-fname { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .grp-req-date { font-size: 10px; color: var(--muted); }
        .grp-req-cascade-note { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600; color: var(--info); }
        .grp-req-review { width: 100%; justify-content: center; margin-top: 2px; }

        @media (max-width: 560px) { .grp-req-grid { grid-template-columns: 1fr; } }

        /* ── GROUP WORKSPACE (Proposal) ────────────────────────────────────────────────────
           Tiles list -> click a group -> that group's whole checklist as a full-width
           workspace, so the reviewer stays put while clearing requirements. Tokens only
           (--surface/--line/--muted/...) so both themes flip correctly. */

        /* ---- nothing here, and that is fine ----
           The same block as Schedule Approvals and Scheduling. Four screens, one empty state:
           staff move between them all day and a blank Checklist should not look like a different
           product from a blank queue. */
        .nx { display: flex; flex-direction: column; align-items: center; text-align: center;
            padding: 44px 24px 40px; }
        .nx .quill-still { width: 96px; height: 96px; margin-bottom: 4px; opacity: .95; }
        .nx h3 { font-size: 16px; font-weight: 800; color: var(--ink); margin: 6px 0 0;
            letter-spacing: -.015em; }
        .nx p { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 7px 0 0; max-width: 44ch; }

        .grp-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; align-items: stretch; }
        .grp-tile {
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; gap: 12px; width: 100%; text-align: left;
            padding: 16px 17px; border: 1px solid var(--line); border-radius: var(--card-radius);
            background: var(--surface); box-shadow: var(--shadow-1); cursor: pointer;
            font-family: inherit; color: inherit;
            transition: box-shadow var(--dur) var(--ease), transform var(--dur) var(--ease), border-color var(--dur-fast);
        }
        /* No rail. The tint below carries the state on its own; an edge stripe as well made a
           page of tiles read as a colour chart. */
        .grp-tile:hover { box-shadow: var(--shadow-2); transform: translateY(-2px); border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); }
        .grp-tile:active { transform: translateY(0); }
        /* Whole-card tint, kept faint (5–7%) so a wall of tiles still reads as one surface. */
        .grp-tile.needs    { background: color-mix(in srgb, var(--warning) 5%, var(--surface)); }
        .grp-tile.revision { background: color-mix(in srgb, var(--danger) 5%, var(--surface)); }
        .grp-tile.done     { background: color-mix(in srgb, var(--success) 4%, var(--surface)); }

        .grp-tile-row { display: flex; align-items: center; gap: 11px; }
        .grp-tile-id { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        /* Research titles run long — clamp to two lines so every tile stays the same shape. */
        .grp-tile-name {
            font-size: 14px; font-weight: 750; color: var(--ink); line-height: 1.32; letter-spacing: -.005em;
            display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .grp-tile-lead { font-size: 11.5px; font-weight: 600; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .grp-tile-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: auto; padding-top: 2px; }

        /* Footer strip: queue placing + the timestamp that earned it. Recessed onto surface-2 so it
           reads as provenance rather than another action. */
        .grp-tile-foot {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin: 4px -17px -16px; padding: 9px 17px;
            border-top: 1px solid var(--line-soft); background: color-mix(in srgb, var(--surface-2) 60%, transparent);
        }
        .grp-tile-age { font-size: 10px; font-weight: 600; color: var(--muted); opacity: .8; margin-left: auto; }
        .grp-tile-todo {
            display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .4px; padding: 3px 9px; border-radius: var(--r-pill);
            background: color-mix(in srgb, var(--warning) 18%, var(--surface));
            color: color-mix(in srgb, var(--warning) 62%, var(--ink));
            border: 1px solid color-mix(in srgb, var(--warning) 35%, transparent);
        }
        .grp-tile-done {
            display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .4px; padding: 3px 9px; border-radius: var(--r-pill);
            background: color-mix(in srgb, var(--success) 16%, var(--surface));
            color: color-mix(in srgb, var(--success) 58%, var(--ink));
            border: 1px solid color-mix(in srgb, var(--success) 32%, transparent);
        }
        /* Empty state that explains itself and offers the way out, instead of a bare line. */
        .ws-empty {
            display: flex; flex-direction: column; align-items: center; gap: 8px;
            padding: 46px 26px; text-align: center;
            border: 1.5px dashed var(--line); border-radius: var(--card-radius); background: var(--surface-2);
        }
        .ws-empty i { width: 30px; height: 30px; color: var(--muted); }
        /* Explicit, because lucide swaps <i> for <svg> and an i-only rule stops applying. */
        .ws-empty .quill-still { width: 74px; height: 74px; opacity: .95; margin-bottom: 2px; }
        .ws-empty h4 { font-size: 14.5px; font-weight: 750; color: var(--ink); margin: 0; }
        .ws-empty p { font-size: 12.5px; color: var(--muted); margin: 0 0 6px 0; max-width: 380px; }

        /* By-requirement tiles carry an icon where the group tiles carry an avatar. */
        .req-tile-icon {
            width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent-weak); border: 1px solid color-mix(in srgb, var(--accent) 28%, var(--line));
        }
        .req-tile-icon i { width: 19px; height: 19px; color: var(--accent); }
        .req-tile-icon.lg { width: 44px; height: 44px; }
        .grp-tile-back {
            display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .4px; padding: 3px 9px; border-radius: var(--r-pill);
            background: color-mix(in srgb, var(--danger) 13%, var(--surface));
            color: color-mix(in srgb, var(--danger) 58%, var(--ink));
            border: 1px solid color-mix(in srgb, var(--danger) 30%, transparent);
        }
        /* Requirement-workspace cards lead with the group, so the name block carries two lines. */
        .ws-card-avatar { width: 34px; height: 34px; border-radius: 10px; object-fit: cover; border: 1px solid var(--line); background: var(--surface-2); flex-shrink: 0; }
        .ws-card-title {
            display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .ws-card-lead { display: block; font-size: 10.5px; font-weight: 600; color: var(--muted); margin-top: 2px; }

        .grp-tile-ring { position: relative; width: 46px; height: 46px; flex-shrink: 0; }
        .grp-tile-ring svg { transform: rotate(-90deg); display: block; }
        .grp-tile-ring .ring-track { stroke: var(--surface-3); }
        .grp-tile-ring .ring-fill { stroke: var(--accent); stroke-linecap: round; transition: stroke-dashoffset var(--dur) var(--ease); }
        .grp-tile-ring.complete .ring-fill { stroke: var(--success); }
        .grp-tile-pct {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 10.5px; font-weight: 800; color: var(--ink);
        }

        /* -- the workspace itself ------------------------------------------------------- */
        .grp-workspace { display: none; }
        .grp-workspace.active { display: block; animation: fadeIn var(--dur-fast) var(--ease); }

        /* Navigation row — sits above the profile card so Back is never mistaken for a fact about
           the group. Solid --ink so it reads as the one way out of the workspace; --ink/--surface
           invert together, so it stays high-contrast in both themes. */
        .ws-topbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .ws-back {
            display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; flex-shrink: 0;
            border: 1px solid var(--ink); border-radius: var(--control-radius); background: var(--ink);
            font-family: inherit; font-size: 12.5px; font-weight: 750; color: var(--surface); cursor: pointer;
            box-shadow: var(--shadow-1);
            transition: transform var(--dur-fast) var(--ease), opacity var(--dur-fast), box-shadow var(--dur-fast);
        }
        .ws-back i { width: 15px; height: 15px; }
        .ws-back:hover { opacity: .88; box-shadow: var(--shadow-2); }
        .ws-back:active { transform: scale(.97); }
        .ws-crumb {
            display: inline-flex; align-items: center; gap: 4px; min-width: 0;
            font-size: 11.5px; font-weight: 650; color: var(--muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .ws-crumb i { width: 13px; height: 13px; flex-shrink: 0; opacity: .7; }

        .ws-head {
            position: sticky; top: 0; z-index: 20; margin-bottom: 18px; padding: 16px 18px;
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius);
            box-shadow: var(--shadow-1); display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .ws-head-avatar { width: 46px; height: 46px; border-radius: 14px; object-fit: cover; border: 1px solid var(--line); background: var(--surface-2); flex-shrink: 0; }
        .ws-head-id { flex: 1; min-width: 190px; display: flex; flex-direction: column; gap: 4px; }
        .ws-head-name { font-size: 17px; font-weight: 800; color: var(--ink); font-family: var(--font-ui); letter-spacing: -0.02em; line-height: 1.2; }
        .ws-head-sub { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11.5px; font-weight: 600; color: var(--muted); }
        .ws-head-progress { min-width: 150px; text-align: right; }
        .ws-head-count { font-size: 12.5px; font-weight: 800; color: var(--ink); }
        .ws-head-label { font-size: 9.5px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; color: var(--muted); }

        /* When this group first cleared something. */
        .ws-stamp {
            display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 650;
            color: var(--muted); font-variant-numeric: tabular-nums;
        }
        .ws-stamp i { width: 12px; height: 12px; flex-shrink: 0; }
        .ws-stamp.ok { color: color-mix(in srgb, var(--success) 55%, var(--ink)); }

        .ws-section { margin-bottom: 22px; }
        .ws-section-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .ws-section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--ink-soft); }
        .ws-section-count {
            font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: var(--r-pill);
            background: var(--surface-2); color: var(--muted); border: 1px solid var(--line);
        }
        .ws-section.action .ws-section-title { color: color-mix(in srgb, var(--warning) 65%, var(--ink)); }
        .ws-section-rule { flex: 1; height: 1px; background: var(--line); }
        .ws-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }

        .ws-card {
            /* visible, not hidden: the signature mark sits proud of the corner */
            position: relative;
            display: flex; flex-direction: column; gap: 9px; padding: 15px 16px;
            border: 1px solid var(--line);
            border-radius: var(--card-radius); background: var(--surface); box-shadow: var(--shadow-1);
            transition: box-shadow var(--dur) var(--ease), border-color var(--dur-fast), transform var(--dur) var(--ease);
        }
        /* No state rail. The tint and the pill already say it twice; the stripe was a third,
           louder voice, and a screen of them read as a colour chart. The border picks up the
           faintest hint of the state instead, which is enough to scan a column by. */
        .ws-card:hover { box-shadow: var(--shadow-2); transform: translateY(-1px);
            border-color: color-mix(in srgb, var(--accent) 38%, var(--line)); }
        .ws-card.pending, .ws-card.review { background: color-mix(in srgb, var(--warning) 4%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 24%, var(--line)); }
        .ws-card.approved { background: color-mix(in srgb, var(--success) 4%, var(--surface));
            border-color: color-mix(in srgb, var(--success) 22%, var(--line)); }
        .ws-card.revision { background: color-mix(in srgb, var(--danger) 4%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 24%, var(--line)); }
        .ws-card.selected { border-color: var(--accent); background: var(--accent-weak); box-shadow: 0 0 0 1px var(--accent), var(--shadow-2); }
        .ws-card.ghost { border-style: dashed; background: var(--surface-2); opacity: .85; }

        .ws-card-top { display: flex; align-items: flex-start; gap: 10px; }
        /* Status glyph in a tinted tile — the student dashboard's card language, so a reviewer
           reads state from shape and colour before reading a word. */
        .ws-card-icon {
            width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--surface-2); border: 1px solid var(--line);
        }
        .ws-card-icon i { width: 17px; height: 17px; color: var(--muted); }
        .ws-card.pending .ws-card-icon, .ws-card.review .ws-card-icon {
            background: color-mix(in srgb, var(--warning) 15%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 30%, transparent);
        }
        .ws-card.pending .ws-card-icon i, .ws-card.review .ws-card-icon i { color: color-mix(in srgb, var(--warning) 68%, var(--ink)); }
        .ws-card.approved .ws-card-icon {
            background: color-mix(in srgb, var(--success) 14%, var(--surface));
            border-color: color-mix(in srgb, var(--success) 28%, transparent);
        }
        .ws-card.approved .ws-card-icon i { color: color-mix(in srgb, var(--success) 62%, var(--ink)); }
        .ws-card.revision .ws-card-icon {
            background: color-mix(in srgb, var(--danger) 13%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 28%, transparent);
        }
        .ws-card.revision .ws-card-icon i { color: color-mix(in srgb, var(--danger) 62%, var(--ink)); }

        .ws-card-name { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 750; color: var(--ink); line-height: 1.35; padding-top: 2px; }
        .ws-card-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 10.5px; color: var(--muted); }
        .ws-card-score { font-size: 11px; font-weight: 700; color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        .ws-next {
            display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 650;
            color: var(--muted); line-height: 1.3;
        }
        .ws-next i { width: 12px; height: 12px; flex-shrink: 0; }
        .ws-unseen { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
            background: var(--info); flex-shrink: 0; }

        /* Cascade children (13/15/16) nested under the Capsule Proposal that clears them, so all
           seven requirements are visible without looking like three extra chores. */
        .ws-cascade {
            display: flex; flex-direction: column; gap: 5px; padding: 10px 11px;
            border-left: 2px solid var(--line);   /* an indent marker, not a status colour */
            border-radius: 0 var(--r-sm) var(--r-sm) 0; background: var(--surface-2);
        }
        .ws-cascade-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .ws-cascade-name { font-size: 11.5px; font-weight: 650; color: var(--ink-soft); line-height: 1.3; }
        .ws-cascade-row .status-pill { font-size: 8.5px; padding: 2px 7px; white-space: nowrap; flex-shrink: 0; }

        .ws-card-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
        .ws-card-actions .btn { flex: 1; min-width: 110px; padding: 9px 12px; font-size: 12px; }
        .ws-btn-revise {
            background: transparent; color: color-mix(in srgb, var(--danger) 62%, var(--ink));
            border: 1px solid color-mix(in srgb, var(--danger) 34%, var(--line));
        }
        .ws-btn-revise:hover { background: color-mix(in srgb, var(--danger) 10%, var(--surface)); }

        /* ══ the requirement card, rebuilt ══════════════════════════════════════════════
           Scoped under .ws-card-doc and nowhere else: .ws-card / .ws-card-top / .ws-card-name /
           .ws-card-meta are shared by four other renderers (the defense application, two ghost
           cards, and the by-requirement view, which nests .ws-card-title INSIDE .ws-card-name).
           Unscoped edits would redesign five cards to fix one.

           The old card carried up to fourteen elements on a seven-step type scale with no
           hierarchy — everything was almost the same size, so nothing led. Five steps now, one
           job each: the requirement name, the buttons, the one sentence telling you what to do,
           the quiet sub-line, and the pill. */
        .ws-card-doc { gap: 10px; padding: 14px 16px 15px; }
        /* Twenty cards that each jump a pixel on mouse-over reads as jelly. The shadow and the
           border tint carry the hover on their own. */
        .ws-card-doc:hover { transform: none; }

        .ws-card-doc .ws-card-id { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        /* .ws-card-name must keep holding the requirement name and NOTHING else — wsConfirmBulk
           reads its textContent straight into the irreversible "Approve N requirements" dialog.
           That is the whole reason the sub-line is a sibling and not a child. */
        .ws-card-doc .ws-card-name { font-size: 14.5px; font-weight: 700; letter-spacing: -.01em;
            line-height: 1.3; padding-top: 1px; }
        .ws-card-doc .ws-card-sub { display: flex; align-items: center; gap: 6px; min-width: 0;
            font-size: 10.5px; font-weight: 550; color: var(--muted); }
        .ws-card-doc .ws-fname { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ws-card-doc .ws-age { font-weight: 600; font-variant-numeric: tabular-nums; flex-shrink: 0; }
        .ws-card-doc .status-pill { font-size: 9.5px; padding: 2px 7px; letter-spacing: .06em; flex-shrink: 0; }

        /* One line, never two: a real remark always beats the generic "what to do next" hint. */
        .ws-card-doc .ws-line { display: flex; align-items: flex-start; gap: 6px;
            font-size: 11.5px; font-weight: 600; line-height: 1.35; color: var(--muted); }
        .ws-card-doc .ws-line i, .ws-card-doc .ws-line svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 1px; }
        .ws-card-doc .ws-line.said { font-style: italic; color: color-mix(in srgb, var(--danger) 55%, var(--ink)); }

        .ws-card-doc .ws-card-actions .btn { font-size: 12.5px; font-weight: 700; }
        /* The revise button loses its outline until you reach for it — two equally-weighted
           buttons made every card ask a question it had already answered in the pill. */
        .ws-card-doc .ws-btn-revise { background: transparent; border-color: transparent; }
        .ws-card-doc .ws-btn-revise:hover { border-color: color-mix(in srgb, var(--danger) 34%, var(--line)); }
        /* History is opened about once a week. It does not get a row of its own. */
        .ws-card-doc .ws-hist-btn { flex: 0 0 34px; min-width: 0; padding: 9px 0; justify-content: center;
            background: transparent; border: 1px solid var(--line); color: var(--muted); }
        .ws-card-doc .ws-hist-btn:hover { color: var(--ink); border-color: color-mix(in srgb, var(--accent) 38%, var(--line)); }

        /* The signature IS the leading tile. A corner chip fought the status pill for the same
           top-right space, and on an approved card the tile was showing a check-circle that the
           pill had already said. So the slot stops repeating the status and names the person:
           their face, with a tick on it. The name survives in the title and in .ws-sr — it was a
           whole three-line paragraph inside the card before this. */
        .ws-card-doc .ws-signer { position: relative; overflow: visible; padding: 0; background: var(--surface-2); }
        .ws-card-doc .ws-signer img { width: 100%; height: 100%; border-radius: inherit; object-fit: cover; }
        .ws-card-doc .ws-signer > i, .ws-card-doc .ws-signer > svg {
            position: absolute; right: -4px; bottom: -4px; width: 15px; height: 15px; padding: 2px;
            border-radius: 50%; background: var(--success); color: #fff;
            /* the ring matches the card it sits on: an approved card is tinted, and a white halo
               on a green ground is a hole */
            box-shadow: 0 0 0 2.5px color-mix(in srgb, var(--success) 4%, var(--surface)); }
        .ws-card-doc .ws-signer.unknown > i, .ws-card-doc .ws-signer.unknown > svg {
            position: static; width: 17px; height: 17px; padding: 0; background: none;
            color: var(--muted); box-shadow: none; }

        /* Batch selection is the secondary path, so it stops being the loudest thing on the card. */
        .ws-card-doc .ws-check { padding: 6px 10px; }
        .ws-card-doc .ws-check-box { width: 16px; height: 16px; border-radius: 5px; }
        .ws-card-doc .ws-check-label { font-size: 11px; }

        /* Visually hidden, still read aloud and still found by Ctrl+F. */
        /* ---- the filter row ----
           Scoped: .filter-bar / .search-input-wrapper live in portal.css and are shared by every
           staff page, so this restyles one screen rather than the product. */
        .cl-filters { padding: 10px 12px; border-radius: var(--r-pill); }
        .cl-filters form { gap: 8px; }
        .cl-filters .search-input-wrapper { border-radius: var(--r-pill); padding: 0 8px 0 14px;
            background: var(--surface); }
        .cl-filters .search-input-wrapper input[type="text"] { font-size: 13px; padding: 9px 5px; }

        /* Real <select>s wearing our chrome. Replacing them with custom dropdowns would cost
           keyboard support, type-ahead and the native picker on a phone to gain a rounder corner. */
        .f-sel { position: relative; display: inline-flex; align-items: center; }
        .f-sel select {
            appearance: none; -webkit-appearance: none; cursor: pointer;
            font-family: inherit; font-size: 12.5px; font-weight: 700; color: var(--ink-soft);
            background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--r-pill);
            padding: 8px 30px 8px 14px; max-width: 190px; text-overflow: ellipsis;
            transition: color var(--dur-fast), background var(--dur-fast), border-color var(--dur-fast);
        }
        .f-sel select:hover { color: var(--ink); }
        .f-sel select:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .f-sel i, .f-sel svg { position: absolute; right: 11px; width: 13px; height: 13px;
            color: var(--muted); pointer-events: none; }
        /* A set filter looks set, so the shape of the query reads without opening anything. */
        .f-sel.on select { background: var(--accent-weak); color: color-mix(in srgb, var(--accent) 78%, var(--ink));
            border-color: color-mix(in srgb, var(--accent) 40%, transparent); }
        .f-sel.on i, .f-sel.on svg { color: color-mix(in srgb, var(--accent) 78%, var(--ink)); }

        .f-clear { display: inline-flex; align-items: center; gap: 5px; text-decoration: none;
            font-size: 12.5px; font-weight: 700; color: var(--muted); white-space: nowrap;
            background: none; border: 1px solid var(--line); border-radius: var(--r-pill); padding: 8px 14px;
            transition: color var(--dur-fast), border-color var(--dur-fast); }
        .f-clear:hover { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 40%, var(--line)); }
        .f-clear i, .f-clear svg { width: 13px; height: 13px; }

        /* The single extreme, not a running number. */
        .ws-oldest { display: inline-flex; align-items: center; gap: 5px; font-size: 10px;
            font-weight: 800; letter-spacing: .04em; text-transform: uppercase;
            color: color-mix(in srgb, var(--danger) 62%, var(--ink));
            background: color-mix(in srgb, var(--danger) 12%, var(--surface));
            border-radius: var(--r-pill); padding: 3px 9px; white-space: nowrap; }
        .ws-oldest i, .ws-oldest svg { width: 11px; height: 11px; }

        .ws-sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
            overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0; }
        /* Custom checkbox — the staff system has none, so this is built from tokens. It is a whole
           labelled strip rather than a lone tick: the old 19px box beside the title was invisible
           until you already knew it was there. */
        .ws-check {
            display: flex; align-items: center; gap: 9px; cursor: pointer; user-select: none;
            padding: 8px 11px; border: 1px dashed var(--line); border-radius: var(--r-sm);
            background: var(--surface-2);
            transition: background var(--dur-fast), border-color var(--dur-fast);
        }
        .ws-check input { position: absolute; opacity: 0; width: 0; height: 0; }
        .ws-check-box {
            width: 20px; height: 20px; border-radius: 6px; border: 2px solid var(--muted); flex-shrink: 0;
            background: var(--surface); display: flex; align-items: center; justify-content: center;
            transition: background var(--dur-fast) var(--ease), border-color var(--dur-fast) var(--ease);
        }
        .ws-check-box::after {
            content: ''; width: 5px; height: 10px; border: solid var(--accent-ink);
            border-width: 0 2.5px 2.5px 0; transform: rotate(45deg) scale(0); margin-top: -2px;
            transition: transform var(--dur-fast) var(--spring);
        }
        .ws-check-label { font-size: 11.5px; font-weight: 700; color: var(--ink-soft); line-height: 1.2; }
        .ws-check:hover { background: var(--surface-3); border-color: var(--accent); }
        .ws-check:hover .ws-check-box { border-color: var(--accent); }
        .ws-check input:checked + .ws-check-box { background: var(--accent); border-color: var(--accent); }
        .ws-check input:checked + .ws-check-box::after { transform: rotate(45deg) scale(1); }
        .ws-check:has(input:checked) {
            background: var(--accent-weak); border-style: solid;
            border-color: color-mix(in srgb, var(--accent) 45%, transparent);
        }
        .ws-check:has(input:checked) .ws-check-label { color: color-mix(in srgb, var(--accent) 72%, var(--ink)); }
        .ws-check input:focus-visible + .ws-check-box { outline: 2px solid var(--accent); outline-offset: 2px; }

        /* inline "request revision" reason, attached to the card it belongs to */
        .ws-reason { display: none; flex-direction: column; gap: 8px; padding: 12px; margin-top: 2px;
            border: 1px solid color-mix(in srgb, var(--danger) 28%, var(--line));
            border-radius: var(--r-md); background: color-mix(in srgb, var(--danger) 6%, var(--surface)); }
        .ws-reason.open { display: flex; animation: fadeIn var(--dur-fast) var(--ease); }
        .ws-reason label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: color-mix(in srgb, var(--danger) 60%, var(--ink)); }
        .ws-reason textarea {
            width: 100%; min-height: 82px; resize: vertical; padding: 9px 11px; font-family: inherit;
            font-size: 12.5px; color: var(--ink); background: var(--surface);
            border: 1px solid var(--line); border-radius: var(--r-sm);
        }
        .ws-reason textarea:focus { outline: none; border-color: var(--danger); }
        .ws-reason-row { display: flex; gap: 8px; }
        .ws-reason-row .btn { flex: 1; padding: 8px 12px; font-size: 12px; }

        /* ── Batch-approval confirmation dialog ──────────────────────────────────────────
           The decision this dialog commits is irreversible for the student, so it gets the same
           care as the rest of the screen instead of a browser alert. */
        .ws-confirm {
            display: none; position: fixed; inset: 0; z-index: 3000;
            align-items: center; justify-content: center; padding: 24px;
            background: color-mix(in srgb, var(--ink) 42%, transparent);
            -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
        }
        .ws-confirm.open { display: flex; animation: fadeIn var(--dur-fast) var(--ease); }
        .ws-confirm-card {
            width: 100%; max-width: 460px; max-height: min(84vh, 640px);
            display: flex; flex-direction: column; overflow: hidden;
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--card-radius); box-shadow: var(--shadow-3);
            animation: wsConfirmIn var(--dur) var(--spring);
        }
        @keyframes wsConfirmIn { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }

        .ws-confirm-head { display: flex; align-items: flex-start; gap: 13px; padding: 20px 20px 16px; }
        .ws-confirm-icon {
            width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            background: color-mix(in srgb, var(--success) 15%, var(--surface));
            border: 1px solid color-mix(in srgb, var(--success) 30%, transparent);
        }
        .ws-confirm-icon i { width: 20px; height: 20px; color: color-mix(in srgb, var(--success) 62%, var(--ink)); }
        .ws-confirm-heading { flex: 1; min-width: 0; }
        .ws-confirm-heading h3 { margin: 0; font-size: 16.5px; font-weight: 800; color: var(--ink); line-height: 1.25; letter-spacing: -.01em; }
        .ws-confirm-heading p { margin: 4px 0 0; font-size: 12px; font-weight: 600; color: var(--muted); line-height: 1.4; }
        .ws-confirm-x {
            flex-shrink: 0; width: 30px; height: 30px; border-radius: 8px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            background: transparent; border: none; color: var(--muted);
            transition: background var(--dur-fast), color var(--dur-fast);
        }
        .ws-confirm-x:hover { background: var(--surface-2); color: var(--ink); }
        .ws-confirm-x i { width: 17px; height: 17px; }

        .ws-confirm-body { padding: 0 20px 4px; overflow-y: auto; }
        .ws-confirm-listlabel {
            font-size: 9.5px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase;
            color: var(--muted); margin-bottom: 8px;
        }
        /* Named rows with a tick, not a bullet list — each one is a document, so it looks like one. */
        .ws-confirm-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
        .ws-confirm-list li {
            display: flex; align-items: center; gap: 9px; padding: 9px 12px;
            border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--surface-2);
            font-size: 12.5px; font-weight: 650; color: var(--ink); line-height: 1.3;
        }
        .ws-confirm-list li::before {
            content: ''; width: 15px; height: 15px; border-radius: 50%; flex-shrink: 0;
            background: color-mix(in srgb, var(--success) 22%, var(--surface));
            border: 1.5px solid color-mix(in srgb, var(--success) 55%, transparent);
        }
        .ws-confirm-note {
            display: flex; align-items: flex-start; gap: 8px; margin-top: 12px; padding: 11px 12px;
            border: 1px solid color-mix(in srgb, var(--warning) 30%, transparent);
            border-radius: var(--r-sm); background: color-mix(in srgb, var(--warning) 9%, var(--surface));
            font-size: 11.5px; font-weight: 650; line-height: 1.45;
            color: color-mix(in srgb, var(--warning) 58%, var(--ink));
        }
        .ws-confirm-note i { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; }

        .ws-confirm-foot {
            display: flex; gap: 9px; padding: 16px 20px 20px; margin-top: 8px;
            border-top: 1px solid var(--line-soft);
        }
        .ws-confirm-foot .btn { flex: 1; justify-content: center; padding: 11px 16px; font-size: 12.5px; }

        .rel-blurb { margin: 0 0 12px; font-size: 12.5px; line-height: 1.5; color: var(--muted); max-width: 70ch; }

        /* ================= PLAGIARISM, THREE STEPS (.pz-*), 2026-09-29 — Claude Design concept =====
           Confirm manuscripts · Log payments · Release results. Tokens only, so dark mode and the
           accent palettes follow; the group header wears the hero gradient in both themes. */
        body.pz-page { --pz-display: "Outfit", var(--font-ui); }
        .pz { max-width: 1240px; }
        .pz [hidden] { display: none !important; }
        .pz-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; flex-wrap: wrap; margin: 2px 0 18px; }
        .pz-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: 0; padding: 0; cursor: pointer;
            font: inherit; font-size: 12px; font-weight: 600; color: var(--muted); }
        .pz-back:hover { color: var(--accent); }
        .pz-back i, .pz-back svg { width: 13px; height: 13px; }
        .pz-head h1 { font-family: var(--pz-display); font-size: 29px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin: 6px 0 4px; }
        .pz-head p { margin: 0; font-size: 13px; color: var(--muted); }
        .pz-steps { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); align-self: center; }
        .pz-steps a { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px 7px 7px; border-radius: 999px; text-decoration: none;
            font-size: 12.5px; font-weight: 700; color: var(--ink); }
        .pz-steps a:hover { background: color-mix(in srgb, var(--surface) 60%, transparent); }
        .pz-steps a b { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; background: var(--surface); color: var(--ink); }
        .pz-steps a span { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; display: grid; place-items: center; font-size: 10.5px;
            background: color-mix(in srgb, var(--accent) 16%, var(--surface)); color: var(--accent); }
        .pz-steps a.on { background: var(--sh-hero-a, #2e1065); color: #fff; box-shadow: var(--shadow-1); }
        .pz-steps a.on b { background: #fff; color: var(--sh-hero-a, #2e1065); }
        .pz-steps a.on span { background: rgba(255, 255, 255, .18); color: #fff; }

        .pz-grid { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 18px; align-items: start; }
        .pz-list { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 14px; position: sticky; top: 12px;
            max-height: calc(100vh - 40px); overflow-y: auto; }
        .pz-search { display: flex; align-items: center; gap: 8px; padding: 9px 13px; border-radius: 999px; background: var(--surface-3); margin-bottom: 14px; }
        .pz-search i, .pz-search svg { width: 15px; height: 15px; color: var(--muted); flex-shrink: 0; }
        .pz-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit; font-size: 12.5px; color: var(--ink); }
        .pz-sect + .pz-sect { margin-top: 14px; }
        .pz-sect-h { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 0 4px 7px;
            font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .pz-sect-h b { color: var(--ink); }
        .pz-sect.is-fold .pz-sect-h { cursor: pointer; }
        .pz-fold-i { width: 13px !important; height: 13px !important; transition: transform .2s; }
        .pz-sect.is-fold.open .pz-fold-i { transform: rotate(180deg); }
        .pz-sect.is-fold:not(.open):not(.is-searching) .pz-item { display: none; }
        .pz-item { width: 100%; display: flex; align-items: center; gap: 11px; padding: 9px 10px; margin-bottom: 4px; border-radius: 14px; cursor: pointer;
            background: transparent; border: 1.5px solid transparent; font: inherit; text-align: left; color: inherit; }
        .pz-item:hover { background: var(--surface-2); }
        .pz-item.on { background: color-mix(in srgb, var(--accent) 9%, var(--surface)); border-color: color-mix(in srgb, var(--accent) 38%, var(--line)); }
        .pz-tile { width: 34px; height: 34px; border-radius: 11px; flex-shrink: 0; display: grid; place-items: center; font-weight: 800; font-size: 13px;
            background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .pz-item.t-you .pz-tile { background: var(--accent); color: var(--accent-ink); }
        .pz-item.t-back .pz-tile { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .pz-item.t-them .pz-tile { background: color-mix(in srgb, var(--warning) 16%, var(--surface)); color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .pz-item.t-done .pz-tile { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .pz-item-t { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .pz-item-t b { font-size: 13px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pz-item-t small { font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pz-item-r { flex-shrink: 0; font-size: 11px; font-weight: 700; color: var(--muted); }
        .pz-item.t-you .pz-item-r { color: var(--accent); }
        .pz-chip { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 800; }
        .pz-chip.ok { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .pz-chip.bad { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .pz-none { font-size: 12px; color: var(--muted); padding: 4px 6px 8px; margin: 0; }
        .pz-logged { display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 11px 12px; border-radius: 14px; background: var(--surface-3);
            text-decoration: none; font-size: 12.5px; font-weight: 700; color: var(--ink); }
        .pz-logged span { flex: 1; }
        .pz-logged b { color: var(--muted); }
        .pz-logged i, .pz-logged svg { width: 14px; height: 14px; color: var(--muted); }

        .pz-detail { min-width: 0; }
        .pz-pane { display: flex; flex-direction: column; gap: 14px; }
        .pz-hero { position: relative; overflow: hidden; border-radius: 24px; padding: 20px 22px 16px; color: #fff;
            background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); box-shadow: 0 18px 40px -22px rgba(46, 16, 101, .7); }
        .pz-hero::after { content: ''; position: absolute; right: -10%; top: -45%; width: 42%; height: 160%; border-radius: 50%;
            background: rgba(255, 255, 255, .07); pointer-events: none; }
        .pz-hero > * { position: relative; z-index: 1; }
        .pz-hero-top { display: flex; align-items: flex-start; gap: 14px; }
        .pz-hero-tile { width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center;
            background: #fff; color: var(--sh-hero-a, #2e1065); font-family: var(--pz-display); font-size: 21px; font-weight: 700; }
        .pz-hero-id { flex: 1; min-width: 0; }
        .pz-hero-id h2 { margin: 0; font-family: var(--pz-display); font-size: 21px; font-weight: 700; line-height: 1.25; color: #fff; }
        .pz-hero-id p { margin: 3px 0 0; font-size: 12.5px; color: rgba(255, 255, 255, .78); }
        .pz-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
        .pz-chips span { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
            background: rgba(255, 255, 255, .14); color: #fff; }
        .pz-chips span.warn { background: #fcd34d; color: #78350f; }
        .pz-chips i, .pz-chips svg { width: 12px; height: 12px; }
        .pz-hero-meta { flex-shrink: 0; text-align: right; display: flex; flex-direction: column; gap: 1px; }
        .pz-hero-meta small { font-size: 10.5px; color: rgba(255, 255, 255, .7); }
        .pz-hero-meta b { font-family: var(--pz-display); font-size: 16px; font-weight: 700; color: #fff; }
        .pz-bar { list-style: none; margin: 18px 0 0; padding: 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .pz-bar li { display: flex; flex-direction: column; gap: 6px; }
        .pz-bar li i { height: 4px; border-radius: 99px; background: rgba(255, 255, 255, .22); }
        .pz-bar li.done i { background: #86efac; }
        .pz-bar li.now i { background: #fff; }
        .pz-bar li span { font-size: 11px; font-weight: 600; color: rgba(255, 255, 255, .7); }
        .pz-bar li.now span { color: #fff; font-weight: 800; }

        .pz-card { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 16px 18px; }
        .pz-file { display: flex; align-items: center; gap: 13px; }
        .pz-file > div { flex: 1; min-width: 0; }
        .pz-file b, .pz-doc b { display: block; font-size: 13.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pz-file small, .pz-doc small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 1px; }
        .pz-file-ico { width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0; display: grid; place-items: center;
            background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .pz-file-ico i, .pz-file-ico svg { width: 18px; height: 18px; }
        .pz-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line);
            background: var(--surface); color: var(--ink); font-size: 12.5px; font-weight: 700; text-decoration: none; white-space: nowrap; margin-top: 4px; }
        .pz-ghost:hover { border-color: var(--accent); }
        .pz-ghost i, .pz-ghost svg { width: 14px; height: 14px; }

        .pz-decide { display: flex; flex-direction: column; gap: 14px; }
        .pz-decide:not(.is-no) .pz-no, .pz-decide.is-no .pz-yes { display: none !important; }
        .pz-lab > span.pz-yes, .pz-lab > span.pz-no { display: inline; }
        .pz-q { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .pz-q h3, .pz-res h3 { margin: 0; font-family: var(--pz-display); font-size: 17px; font-weight: 600; color: var(--ink); }
        .pz-q h4 { margin: 0; font-size: 13px; font-weight: 700; color: var(--ink); }
        .pz-seg { display: inline-flex; gap: 3px; padding: 3px; border-radius: 999px; background: var(--surface-3); }
        .pz-seg label { position: relative; padding: 7px 14px; border-radius: 999px; font-size: 12px; font-weight: 700; color: var(--ink); cursor: pointer; }
        .pz-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .pz-seg label:has(input:checked) { background: var(--sh-hero-a, #2e1065); color: #fff; }
        .pz-seg.ok label:first-child:has(input:checked) { background: #15803d; }
        .pz-seg label:has(input[value=plag_return]:checked), .pz-seg label:has(input[value=plag_payment_reject]:checked),
        .pz-seg label:has(input[value=plag_revise]:checked) { background: color-mix(in srgb, var(--danger) 80%, #000); }
        .pz-cap { font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
        .pz-tick { display: flex; align-items: center; gap: 9px; padding: 4px 0; font-size: 12.5px; color: var(--ink); cursor: pointer; }
        .pz-tick input, .pz-good input, .pz-radios input { accent-color: var(--accent); width: 16px; height: 16px; }
        .pz-lab { display: block; font-size: 12px; font-weight: 700; color: var(--ink); }
        .pz-lab > input, .pz-lab > .pz-pct { margin-top: 6px; }
        .pz-lab em { font-style: normal; font-weight: 500; color: var(--muted); }
        .pz-lab input[type=text], .pz-lab input[type=number], .pz-inline input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 13px; border-radius: 12px;
            border: 1px solid var(--line); background: var(--surface-2); color: var(--ink); font: inherit; font-size: 13px; }
        .pz-lab input:focus, .pz-inline input:focus { outline: none; border-color: var(--accent); background: var(--surface); }
        .pz .mono { font-family: "JetBrains Mono", monospace; }
        .pz-hint { margin: 6px 0 0; font-size: 11.5px; color: var(--muted); }
        .pz-hint b { color: var(--ink); }
        .pz-radios { display: flex; gap: 16px; margin-bottom: 10px; }
        .pz-radios label { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 600; color: var(--ink); cursor: pointer; }
        .pz-foot { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px;
            background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
        .pz-foot p { flex: 1; margin: 0; font-size: 12.5px; line-height: 1.45; color: var(--ink); }
        .pz-foot-ico { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; background: var(--surface); color: var(--accent); }
        .pz-foot-ico i, .pz-foot-ico svg { width: 15px; height: 15px; }
        .pz-foot-ok { background: color-mix(in srgb, var(--success) 12%, var(--surface)); }
        .pz-foot-ok .pz-foot-ico { color: var(--success); }
        .pz-decide.is-no .pz-foot { background: color-mix(in srgb, var(--danger) 9%, var(--surface)); }
        .pz-decide.is-no .pz-foot-ico { color: var(--danger); }
        .pz-go { flex-shrink: 0; display: inline-flex; align-items: center; gap: 7px; padding: 11px 20px; border-radius: 999px; border: 0; cursor: pointer;
            background: var(--accent); color: var(--accent-ink); font: inherit; font-size: 13px; font-weight: 700; }
        .pz-go:hover { filter: brightness(1.08); }
        .pz-foot-ok .pz-go { background: #15803d; color: #fff; }
        .pz-decide.is-no .pz-go { background: color-mix(in srgb, var(--danger) 82%, #000); color: #fff; }
        .pz-go:disabled { opacity: .45; cursor: not-allowed; filter: none; }
        .pz-go.is-busy { opacity: .7; }

        .pz-docs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .pz-doc { display: flex; align-items: flex-start; gap: 12px; }
        .pz-doc > div { flex: 1; min-width: 0; }
        .pz-doc a { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 12px; font-weight: 700; color: var(--accent); text-decoration: none; }
        .pz-doc a i, .pz-doc a svg { width: 13px; height: 13px; }
        .pz-good { display: inline-flex; align-items: center; gap: 7px; padding: 6px 11px; border-radius: 999px; flex-shrink: 0; cursor: pointer;
            background: var(--surface-3); font-size: 11.5px; font-weight: 700; color: var(--ink); }
        .pz-good:has(input:checked) { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .pz-good:has(input:disabled) { opacity: .5; cursor: not-allowed; }

        .pz-res-row { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 12px; align-items: end; }
        .pz-res-row .pz-lab { white-space: nowrap; }
        .pz-drop { position: relative; display: flex; align-items: center; gap: 11px; padding: 12px 14px; border-radius: 14px; cursor: pointer;
            border: 1.5px dashed color-mix(in srgb, var(--accent) 35%, var(--line)); background: var(--surface-2); }
        .pz-drop:hover, .pz-drop.has { border-color: var(--accent); }
        .pz-drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .pz-drop-ico { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0;
            background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .pz-drop-ico i, .pz-drop-ico svg { width: 16px; height: 16px; }
        .pz-drop b { display: block; font-size: 12.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
        .pz-drop small { font-size: 11px; color: var(--muted); }
        .pz-pct { position: relative; display: block; }
        .pz-pct input { display: block; }
        .pz-pct em { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); font-weight: 700; }

        .pz-wait { display: flex; align-items: flex-start; gap: 13px; background: var(--surface-2); }
        .pz-wait > i, .pz-wait > svg { width: 20px; height: 20px; color: var(--muted); flex-shrink: 0; margin-top: 2px; }
        .pz-wait b { font-size: 14px; color: var(--ink); }
        .pz-wait p { margin: 4px 0 0; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
        .pz-wait.is-ok { background: color-mix(in srgb, var(--success) 9%, var(--surface)); }
        .pz-wait.is-ok > i, .pz-wait.is-ok > svg { color: var(--success); }
        .pz-wait.is-bad { background: color-mix(in srgb, var(--danger) 8%, var(--surface)); }
        .pz-wait.is-bad > i, .pz-wait.is-bad > svg { color: var(--danger); }
        .pz-more summary { display: flex; align-items: center; gap: 8px; cursor: pointer; list-style: none; font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .pz-more summary::-webkit-details-marker { display: none; }
        .pz-more summary i, .pz-more summary svg { width: 15px; height: 15px; }
        .pz-inline { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
        .pz-inline input[type=text] { flex: 1; min-width: 180px; width: auto; }

        @media (max-width: 900px) {
            .pz-grid { grid-template-columns: minmax(0, 1fr); }
            .pz-list { position: static; max-height: none; }
            .pz-steps { width: 100%; justify-content: space-between; }
        }
        @media (max-width: 640px) {
            .pz-head h1 { font-size: 24px; }
            .pz-steps a { padding: 6px 8px 6px 6px; font-size: 11.5px; gap: 5px; }
            .pz-docs, .pz-res-row { grid-template-columns: minmax(0, 1fr); }
            .pz-hero-top { flex-wrap: wrap; }
            .pz-hero-meta { text-align: left; flex-direction: row; gap: 6px; align-items: baseline; }
            .pz-foot { flex-wrap: wrap; }
            .pz-foot .pz-go { width: 100%; justify-content: center; }
        }

        /* ================= NOTIFY, 2026-09-29 redesign (.nt-*) =================
           Same language as the Approvals queue (admin_batch_review.php .ap-*): big feature card
           for the one to do now, compact rows, a side panel. Tokens only, so dark mode and the
           accent palettes follow; the feature card wears the hero gradient in both themes. */
        body.admin-module { --nt-display: "Outfit", var(--font-ui); }
        .nt { max-width: 1180px; }
        .nt-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; margin: 4px 0 20px; }
        .nt-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .nt-head h1 { font-family: var(--nt-display); font-size: 30px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin: 2px 0 6px; }
        .nt-head p { font-size: 13.5px; color: var(--muted); max-width: 520px; line-height: 1.5; margin: 0; }
        .nt-steps { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); align-self: center; }
        .nt-steps a { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px;
            font-size: 13px; font-weight: 600; color: var(--ink); text-decoration: none; }
        .nt-steps a:hover { background: color-mix(in srgb, var(--surface) 60%, transparent); }
        .nt-steps a.on { background: var(--surface); box-shadow: var(--shadow-1); font-weight: 700; }
        .nt-steps b { min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; display: grid; place-items: center; font-size: 11px;
            background: color-mix(in srgb, var(--accent) 16%, var(--surface)); color: var(--accent); }
        .nt-steps a.on b { background: var(--accent); color: var(--accent-ink); }

        .nt-grid { display: grid; grid-template-columns: minmax(0, 1fr) 290px; gap: 22px; align-items: start; }
        .nt-main { min-width: 0; }
        .nt-sect + .nt-sect { margin-top: 28px; }
        .nt-sect h2 { font-family: var(--nt-display); font-size: 19px; font-weight: 600; color: var(--ink); margin: 0 0 12px;
            display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .nt-sect h2 small { font-family: var(--font-ui); font-size: 12.5px; font-weight: 500; color: var(--muted); }
        .nt-also { font-size: 13px; font-weight: 700; color: var(--ink); margin: 18px 0 8px; }
        .nt-also span { font-size: 11px; font-weight: 800; color: var(--accent); margin-left: 4px; }

        .nt-date { flex-shrink: 0; width: 56px; padding: 7px 0; border-radius: 14px; background: var(--surface);
            display: flex; flex-direction: column; align-items: center; line-height: 1.1; color: var(--ink); box-shadow: var(--shadow-1); }
        .nt-date b { font-family: var(--nt-display); font-size: 24px; font-weight: 700; }
        .nt-date small { font-size: 10.5px; font-weight: 600; color: var(--muted); }
        .nt-date.light { width: 64px; background: #fff; color: var(--sh-hero-a, #2e1065); box-shadow: none; }
        .nt-date.light small { color: #6b5b95; }
        .nt-date.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); box-shadow: none; }
        .nt-date.sm { width: 44px; padding: 5px 0; border-radius: 11px; }
        .nt-date.sm b { font-size: 17px; }

        .nt-feature { position: relative; overflow: hidden; border-radius: 26px; padding: 22px 24px; color: #fff;
            background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); box-shadow: 0 18px 40px -20px rgba(46, 16, 101, .7); }
        .nt-feature::after { content: ''; position: absolute; right: -12%; top: -40%; width: 46%; height: 150%;
            border-radius: 50%; background: rgba(255, 255, 255, .07); pointer-events: none; }
        .nt-feature > * { position: relative; z-index: 1; }
        .nt-feature-top { display: flex; align-items: center; gap: 16px; }
        .nt-feature-id { flex: 1; min-width: 0; }
        .nt-feature-id h3 { font-family: var(--nt-display); font-size: 21px; font-weight: 700; letter-spacing: -.01em; color: #fff; line-height: 1.25; margin: 0; }
        .nt-feature-id p { font-size: 12.5px; color: rgba(255, 255, 255, .75); margin: 4px 0 0; }
        .nt-age { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px;
            background: rgba(255, 255, 255, .12); font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .nt-age > i { width: 6px; height: 6px; border-radius: 50%; background: #fbbf24; }
        .nt-chain { list-style: none; display: grid; grid-template-columns: repeat(3, 1fr); margin: 22px 0 20px; padding: 0; position: relative; }
        .nt-chain::before { content: ''; position: absolute; top: 13px; left: 14px; right: calc(33.3% - 14px); height: 2px; background: rgba(255, 255, 255, .35); }
        .nt-chain li { position: relative; display: flex; flex-direction: column; gap: 1px; padding-right: 10px; }
        .nt-node { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 10px;
            background: #3b1a7a; border: 2px solid rgba(255, 255, 255, .35); }
        .nt-chain li.done .nt-node { background: #86efac; border-color: #86efac; color: #14532d; }
        .nt-node i, .nt-node svg { width: 14px; height: 14px; stroke-width: 3; }
        .nt-chain li.now .nt-node { background: #fff; border-color: #fff; box-shadow: 0 0 0 5px rgba(255, 255, 255, .18); }
        .nt-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--sh-hero-b, #4c1d95); animation: ntPulse 1.8s ease-in-out infinite; }
        @keyframes ntPulse { 50% { transform: scale(.6); opacity: .6; } }
        .nt-chain b { font-size: 13px; font-weight: 700; }
        .nt-chain small { font-size: 11.5px; color: rgba(255, 255, 255, .7); }
        .nt-feature-acts { display: flex; gap: 10px; flex-wrap: wrap; }

        .nt-form { margin: 0; display: inline-flex; }
        .nt-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; cursor: pointer;
            font-family: inherit; font-size: 13px; font-weight: 700; text-decoration: none; border: 1.5px solid transparent; white-space: nowrap; }
        .nt-btn i, .nt-btn svg { width: 15px; height: 15px; }
        .nt-btn-light { background: #fff; color: var(--sh-hero-a, #2e1065); }
        .nt-btn-light:hover { background: #f3e8ff; }
        .nt-btn-solid { background: var(--accent); color: var(--accent-ink); padding: 8px 15px; font-size: 12.5px; }
        .nt-btn-solid:hover { filter: brightness(1.08); }
        .nt-btn-outline { background: transparent; color: var(--ink); border-color: var(--line); padding: 7px 14px; font-size: 12.5px; }
        .nt-btn-outline:hover { border-color: var(--warning); background: var(--surface); }

        .nt-list { display: flex; flex-direction: column; gap: 10px; }
        .nt-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 20px; background: var(--surface-3); }
        .nt-row[hidden] { display: none !important; }
        .nt-row.soon { background: var(--surface); border: 1px dashed var(--line); }
        .nt-row.done { background: var(--surface); border: 1px solid var(--line); }
        .nt-row.done.is-cleared { background: color-mix(in srgb, var(--success) 4%, var(--surface)); }
        .nt-row-main { flex: 1; min-width: 0; }
        .nt-row-main h4 { font-family: var(--nt-display); font-size: 15.5px; font-weight: 600; color: var(--ink); margin: 0; line-height: 1.3; }
        .nt-row-main p { font-size: 12px; color: var(--muted); margin: 2px 0 0; }
        .nt-row-act { flex-shrink: 0; }
        .nt-by { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px !important; }
        .nt-chip { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
            background: var(--surface-3); color: var(--ink); white-space: nowrap; }
        .nt-chip.warn { background: color-mix(in srgb, var(--warning) 14%, var(--surface)); color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        .nt-chip i, .nt-chip svg { width: 11px; height: 11px; }

        .nt-mini { display: flex; align-items: center; margin-top: 8px; }
        .nt-mini > i { width: 12px; height: 12px; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--accent) 30%, var(--line)); background: var(--surface); flex-shrink: 0; }
        .nt-mini > i.done { background: #86efac; border-color: #4ade80; }
        .nt-mini > i.now { width: 16px; height: 16px; background: var(--accent); border-color: color-mix(in srgb, var(--accent) 40%, var(--surface)); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
        .nt-mini > i.bad { width: 16px; height: 16px; background: var(--danger); border-color: var(--danger); }
        .nt-mini > em { width: 34px; height: 2px; background: color-mix(in srgb, var(--accent) 22%, var(--line)); }
        .nt-mini > em.on { background: #4ade80; }
        .nt-mini > span { margin-left: 10px; font-size: 11.5px; color: var(--muted); }
        .nt-track { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
        .nt-track .nt-mini { margin-top: 0; }
        .nt-stage { font-size: 11.5px; font-weight: 700; color: var(--accent); }
        .nt-stage.ok { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .nt-stage.bad { color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }

        .nt-clear { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-radius: 20px; border: 1px dashed var(--line); background: var(--surface); }
        .nt-clear > i, .nt-clear > svg { width: 22px; height: 22px; color: var(--accent); flex-shrink: 0; }
        .nt-clear b { display: block; font-size: 15px; color: var(--ink); }
        .nt-clear span { font-size: 12.5px; color: var(--muted); }

        .nt-tools { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .nt-search { flex: 1; max-width: 420px; display: flex; align-items: center; gap: 9px; padding: 10px 14px; border-radius: 999px;
            background: var(--surface); border: 1px solid var(--line); }
        .nt-search:focus-within { border-color: var(--accent); }
        .nt-search i, .nt-search svg { width: 16px; height: 16px; color: var(--muted); flex-shrink: 0; }
        .nt-search input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font: inherit; font-size: 13px; color: var(--ink); }
        .nt-legend { font-size: 11.5px; font-weight: 600; color: var(--muted); }
        .nt-nomatch { font-size: 12.5px; color: var(--muted); padding: 10px 4px; margin: 0; }
        .nt-nomatch[hidden] { display: none; }

        .nt-side { display: flex; flex-direction: column; gap: 14px; }
        .nt-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 18px 16px; }
        .nt-panel h3 { font-family: var(--nt-display); font-size: 16.5px; font-weight: 600; color: var(--ink); margin: 0 0 12px; }
        .nt-panel-head { display: flex; justify-content: space-between; align-items: baseline; }
        .nt-panel-head a { font-size: 12px; font-weight: 700; color: var(--accent); text-decoration: none; }
        .nt-how { list-style: none; position: relative; margin: 0; padding: 0; }
        .nt-how::before { content: ''; position: absolute; left: 11px; top: 14px; bottom: 14px; width: 2px; background: var(--line); }
        .nt-how li { position: relative; display: flex; gap: 12px; padding: 5px 0; }
        .nt-how li > b { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: 11px;
            background: var(--surface-3); color: var(--accent); }
        .nt-how li.me > b { background: var(--accent); color: var(--accent-ink); }
        .nt-how strong { display: block; font-size: 13px; color: var(--ink); }
        .nt-how small { font-size: 11.5px; color: var(--muted); }
        .nt-pub { display: flex; align-items: center; gap: 11px; padding: 8px; border-radius: 14px; text-decoration: none; color: inherit; }
        .nt-pub:hover { background: var(--surface-2); }
        .nt-pub-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .nt-pub-body b { font-size: 12.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nt-pub-body small { font-size: 11px; color: var(--muted); }
        .nt-pub-body small.bad { color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .nt-none { font-size: 12.5px; color: var(--muted); padding: 6px 4px; margin: 0; }
        .nt-buckets { display: flex; flex-direction: column; gap: 6px; }
        .nt-bucket { display: flex; align-items: center; gap: 11px; width: 100%; padding: 9px 10px; border-radius: 14px; cursor: pointer;
            background: transparent; border: 1.5px solid transparent; font: inherit; text-align: left; color: inherit; }
        .nt-bucket:hover:not(:disabled) { background: var(--surface-2); }
        .nt-bucket.on { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); border-color: color-mix(in srgb, var(--accent) 40%, var(--line)); }
        .nt-bucket:disabled { opacity: .45; cursor: default; }
        .nt-bucket-ico { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0;
            background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .nt-bucket-ico.pay { background: color-mix(in srgb, var(--warning) 14%, var(--surface)); color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .nt-bucket-ico.done { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .nt-bucket-ico i, .nt-bucket-ico svg { width: 16px; height: 16px; }
        .nt-bucket-t { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .nt-bucket-t b { font-size: 13px; color: var(--ink); }
        .nt-bucket-t small { font-size: 11px; color: var(--muted); }
        .nt-bucket-n { font-family: var(--nt-display); font-size: 18px; font-weight: 700; color: var(--ink); }
        .nt-hint { font-size: 11.5px; color: var(--muted); margin: 10px 4px 0; }

        @media (max-width: 980px) { .nt-grid { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 620px) {
            .nt-head h1 { font-size: 25px; }
            .nt-steps { display: flex; width: 100%; }
            .nt-steps a { flex: 1; justify-content: center; }
            .nt-feature { padding: 18px; border-radius: 22px; }
            .nt-feature-top { flex-wrap: wrap; }
            .nt-age { order: -1; flex-basis: 100%; background: none; padding: 0; }
            .nt-chain { grid-template-columns: 1fr; gap: 12px; }
            .nt-chain::before { display: none; }
            .nt-chain li { flex-direction: row; flex-wrap: wrap; align-items: center; column-gap: 10px; }
            .nt-chain li .nt-node { margin-bottom: 0; }
            .nt-chain small { flex-basis: 100%; padding-left: 38px; }
            .nt-feature-acts .nt-form, .nt-feature-acts .nt-btn { width: 100%; justify-content: center; }
            .nt-row { flex-wrap: wrap; }
            .nt-row-act, .nt-track { width: 100%; }
            .nt-row-act .nt-form, .nt-row-act .nt-btn { width: 100%; justify-content: center; }
            .nt-track { align-items: flex-start; padding-left: 70px; }
            .nt-mini > em { width: 22px; }
        }
        @media (prefers-reduced-motion: reduce) { .nt-dot { animation: none; } }

        /* Scan viewer for the payment desk. */
        .ds-prev { display: none; position: fixed; inset: 0; z-index: 3000; padding: 24px;
                   background: color-mix(in srgb, var(--ink) 45%, transparent);
                   -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
        .ds-prev.open { display: flex; align-items: center; justify-content: center; }
        .ds-prev-box { width: 100%; max-width: 760px; max-height: 86vh; display: flex; flex-direction: column;
                       background: var(--surface); border: 1px solid var(--line);
                       border-radius: var(--card-radius); box-shadow: var(--shadow-3); overflow: hidden; }
        .ds-prev-head { display: flex; align-items: center; justify-content: space-between; gap: 12px;
                        padding: 14px 18px; border-bottom: 1px solid var(--line-soft);
                        font-size: 13px; font-weight: 750; color: var(--ink); }
        .ds-prev-head button { background: none; border: none; font-size: 22px; line-height: 1;
                               color: var(--muted); cursor: pointer; }
        .ds-prev-body { background: var(--surface-2); overflow: auto; text-align: center; }
        .ds-prev-body img { max-width: 100%; display: block; margin: 0 auto; }
        .ds-prev-body iframe { width: 100%; height: 70vh; border: none; display: block; }
        .ws-stamp.soon { color: var(--muted); }

        /* sticky batch bar */
        .ws-bar {
            position: sticky; bottom: 0; z-index: 20; margin-top: 20px; padding: 13px 16px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius);
            box-shadow: var(--shadow-2);
        }
        .ws-bar-count { flex: 1; min-width: 150px; font-size: 12.5px; font-weight: 700; color: var(--ink); }
        .ws-bar-note { display: block; font-size: 10.5px; font-weight: 600; color: var(--muted); margin-top: 2px; }
        .ws-bar .btn { padding: 11px 17px; font-size: 12.5px; }
        .ws-bar .btn:disabled { opacity: .45; cursor: not-allowed; }
        .ws-linkbtn {
            background: none; border: none; padding: 0; font-family: inherit; font-size: 12px;
            font-weight: 700; color: var(--accent); cursor: pointer; text-decoration: underline;
        }

        /* The group picker is collapsed by default. portal.css's .full-width only collapses the
           grid columns, so the aside itself has to be hidden too. Scoped here so the History
           view, which also uses .full-width but renders no sidebar, is unaffected. */
        .workspace-grid.full-width > .group-sidebar { display: none; }

        /* Reclaim the vertical space the removed page header used to take. */
        body.admin-module { padding-top: 18px; }

        .groups-toggle {
            display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0;
            padding: 9px 13px; border: 1px solid var(--line); border-radius: var(--control-radius);
            background: var(--surface-2); font-family: inherit; font-size: 12.5px; font-weight: 700;
            color: var(--ink-soft); cursor: pointer; transition: all var(--dur-fast) var(--ease);
        }
        .groups-toggle:hover { border-color: var(--accent); color: var(--ink); }
        .groups-toggle.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        @media (max-width: 720px) { .groups-toggle-label { display: none; } }

        @media (max-width: 640px) {
            .ws-grid, .grp-tiles { grid-template-columns: 1fr; }
            .ws-head-progress { text-align: left; }
        }

        /* ── Plagiarism review: profile cards (matches the group-inbox look) ───────────────── */
        .plag-queue { display: flex; flex-direction: column; gap: 12px; }

        /* ── Cleared: two lists behind one toggle ────────────────────────────────── */
        .plag-arch-toggle { display: inline-flex; gap: 2px; padding: 3px; background: var(--surface-2); border-radius: 10px; margin-left: 10px; }
        .plag-arch-toggle a {
            display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 8px;
            font-size: 11px; font-weight: 700; color: var(--muted); text-decoration: none; white-space: nowrap;
            transition: background .16s ease, color .16s ease;
        }
        .plag-arch-toggle a:hover { color: var(--ink); }
        .plag-arch-toggle a.is-on { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px rgba(15, 23, 42, .10); }
        .plag-arch-toggle a span {
            font-size: 9.5px; font-weight: 800; padding: 1px 6px; border-radius: 20px;
            background: color-mix(in srgb, var(--muted) 18%, transparent);
        }
        .plag-arch-toggle a.is-on span { background: color-mix(in srgb, var(--success) 20%, transparent); color: color-mix(in srgb, var(--success) 62%, var(--ink)); }

        /* The end of the whole journey — worth more than an empty state. */
        .plag-congrats {
            display: flex; gap: 14px; align-items: flex-start; padding: 18px 20px; margin-bottom: 16px;
            border: 1px solid color-mix(in srgb, var(--success) 26%, var(--border-line));
            border-radius: 14px;
            background: linear-gradient(160deg, color-mix(in srgb, var(--success) 10%, var(--surface)), var(--surface));
        }
        .plag-congrats i { width: 22px; height: 22px; flex-shrink: 0; color: var(--success); margin-top: 2px; }
        .plag-congrats h4 { margin: 0 0 4px; font-size: 14px; font-weight: 800; color: var(--ink); }
        .plag-congrats p { margin: 0; font-size: 12px; line-height: 1.55; color: var(--muted); }
        .plag-congrats a { color: var(--success); font-weight: 700; }

        /* Position in the paid-first queue. */
        .plag-rank {
            flex-shrink: 0; align-self: flex-start; min-width: 34px; text-align: center;
            padding: 4px 8px; border-radius: 8px; background: var(--surface-2);
            font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 800; color: var(--muted);
        }

        /* ── "View names": the group behind the leader ───────────────────────────── */
        .ws-names-btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px;
            border: 1px solid var(--border-line); border-radius: 20px; background: var(--surface);
            font-size: 10px; font-weight: 700; color: var(--muted); cursor: pointer;
            transition: color .16s ease, border-color .16s ease;
        }
        .ws-names-btn:hover { color: var(--ink); border-color: var(--muted); }
        .ws-names-btn i { width: 11px; height: 11px; }
        .ws-names-btn.is-open { color: var(--ink); border-color: var(--muted); }
        .ws-names-n { font-weight: 800; opacity: .7; }
        .ws-names-panel {
            margin-top: 8px; padding: 10px 12px; border: 1px solid var(--border-line);
            border-radius: 10px; background: var(--surface-2);
            display: flex; flex-direction: column; gap: 8px;
        }
        .ws-name-row { display: flex; align-items: center; gap: 9px; }
        .ws-name-row img { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; background: var(--surface); object-fit: cover; }
        .ws-name-text { display: flex; flex-direction: column; min-width: 0; }
        .ws-name-text strong { font-size: 12px; font-weight: 700; color: var(--ink); }
        .ws-name-text span { font-size: 10px; color: var(--muted); word-break: break-word; }
        .ws-names-solo { font-size: 10.5px; font-style: italic; color: var(--muted); }

        /* ── Approved archive: the record, not the queue ─────────────────────────── */
        .plag-record {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px 22px;
            padding: 12px 14px; border-radius: 10px; background: var(--surface-2);
            border: 1px solid var(--border-line);
        }
        .plag-record-item { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .plag-record-item span { font-size: 9.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
        .plag-record-item strong { font-size: 12px; font-weight: 700; color: var(--ink); }
        .plag-record-more { margin-left: auto; }
        .plag-decision.is-tucked { display: none; }
        .plag-card {
            border: 1px solid var(--line);
            border-radius: var(--card-radius); background: var(--surface);
            box-shadow: var(--shadow-1); overflow: hidden;
            transition: box-shadow var(--dur) var(--ease);
        }
        .plag-card:hover { box-shadow: var(--shadow-2); }
        /* Tint, not a rail — see .grp-req-card. */
        .plag-card.pending  { background: color-mix(in srgb, var(--warning) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 26%, var(--line)); }
        .plag-card.revision { background: color-mix(in srgb, var(--danger) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 26%, var(--line)); }
        .plag-card.approved { background: color-mix(in srgb, var(--success) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--success) 24%, var(--line)); }
        .plag-card-head { display: flex; align-items: center; gap: 12px; padding: 13px 16px; border-bottom: 1px solid var(--line); background: var(--surface-2); }
        .plag-head-id { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .plag-ctrl { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--mcnp-teal); }
        .plag-card-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 14px; }
        .plag-docs { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .plag-doc-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px; border: 1.5px solid var(--border-line); border-radius: 9px; font-size: 12px; font-weight: 600; color: var(--info); text-decoration: none; transition: all .18s ease; }
        .plag-doc-link:hover { border-color: var(--info); background: rgba(59,130,246,.06); }
        .plag-doc-link i { width: 13px; height: 13px; }
        .plag-doc-link.report { color: var(--success); }
        .plag-doc-link.report:hover { border-color: var(--success); background: rgba(16,185,129,.06); }
        .plag-report-date { color: var(--text-muted); font-weight: 500; }
        .plag-report-remark { width: 100%; font-size: 11px; color: var(--muted); font-style: italic; }
        .plag-noreport { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--muted); }
        .plag-decision { background: var(--surface-2); border: 1px solid var(--border-line); border-radius: 10px; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
        .plag-decision-label { display: inline-flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 750; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
        .plag-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .plag-form input[type="file"] { flex: 1 1 170px; font-size: 12px; min-width: 0; }
        .plag-form input[type="text"] { flex: 2 1 200px; font-size: 12px; padding: 8px 10px; border: 1.5px solid var(--border-line); border-radius: 8px; background: transparent; color: inherit; min-width: 0; }
        .plag-form .btn-update { flex-shrink: 0; white-space: nowrap; }
        @media (max-width: 640px) { .plag-form { flex-direction: column; align-items: stretch; } .plag-form .btn-update { width: 100%; } }

        /* ================= REQUIREMENTS CHECKLIST, 2026-09-29 (body.ck) =================
           Look only. Every form, id, checkbox and the .ws-card-name / .ws-head-name that the
           approve dialog reads are untouched; the row layout below is CSS over the same markup. */
        body.ck { --ck-display: "Outfit", var(--font-ui); }
        body.ck.ws-open .ck-head, body.ck.ws-open .ck-note, body.ck.ws-open .filter-bar,
        body.ck.ws-open .review-toolbar, body.ck.ws-open #ckFoot { display: none !important; }
        .ck-head { margin-bottom: 14px; }
        .ck-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .ck-head h1 { font-family: var(--ck-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin: 2px 0 6px; }
        .ck-head p { font-size: 13.5px; color: var(--muted); }
        .ck-note { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 20px; background: var(--surface-3); margin-bottom: 16px; }
        .ck-note-ico { width: 34px; height: 34px; border-radius: 11px; flex-shrink: 0; display: grid; place-items: center; background: var(--surface); color: var(--accent); }
        .ck-note-ico i, .ck-note-ico svg { width: 17px; height: 17px; }
        .ck-note p { font-size: 13px; color: var(--ink); line-height: 1.5; margin: 0; }

        /* filters and tabs */
        body.ck .cl-filters .search-input-wrapper { border-radius: 999px; background: var(--surface-3); border-color: transparent; }
        body.ck .cl-filters .f-sel { border-radius: 12px; }
        body.ck .status-filter-tab { border-radius: 999px; background: var(--surface); border: 1px solid var(--line); font-weight: 600; }
        body.ck .status-filter-tab i, body.ck .status-filter-tab svg { display: none; }
        body.ck .status-filter-tab.active { background: var(--sh-hero-a, #2e1065); border-color: var(--sh-hero-a, #2e1065); color: #fff; }
        body.ck .status-filter-tab.active .tab-n { background: #fff; color: var(--sh-hero-a, #2e1065); }

        /* the list: one card, one row per group */
        body.ck #grpTiles { display: flex; flex-direction: column; gap: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .ck-cols, body.ck #grpTiles > .grp-tile { display: grid; grid-template-columns: minmax(220px, 1.25fr) minmax(210px, 1fr) minmax(190px, .95fr) auto; align-items: center; gap: 18px; }
        .ck-cols { padding: 12px 22px; font-size: 11.5px; font-weight: 600; color: var(--muted); border-bottom: 1px solid var(--line); }
        body.ck #grpTiles > .grp-tile { min-height: 0; padding: 16px 22px; margin: 0; border: 0; border-radius: 0; background: var(--surface);
            box-shadow: none; transform: none; cursor: pointer; }
        body.ck #grpTiles > .grp-tile + .grp-tile { border-top: 1px solid var(--line); }
        body.ck #grpTiles > .grp-tile:hover { background: var(--surface-2); transform: none; box-shadow: none; }
        body.ck #grpTiles > .grp-tile::before, body.ck #grpTiles > .grp-tile::after { display: none; }
        body.ck #grpTiles .ws-empty { border: 0; }
        .ck-g { display: flex; align-items: center; gap: 13px; min-width: 0; }
        .ck-av { width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0; display: grid; place-items: center;
            background: color-mix(in srgb, #38bdf8 22%, var(--surface)); color: #0c4a6e;
            font-family: var(--ck-display); font-size: 19px; font-weight: 700; }
        body.theme-dark .ck-av { color: #bae6fd; }
        .ck-av.big { width: 72px; height: 72px; border-radius: 20px; font-size: 30px; }
        .ck-gid { display: flex; flex-direction: column; min-width: 0; }
        .ck-gid b { font-family: var(--ck-display); font-size: 16.5px; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ck-gid small { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ck-req { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
        .ck-segs { display: flex; gap: 4px; }
        .ck-segs i { flex: 1; max-width: 34px; height: 5px; border-radius: 3px; background: var(--surface-3); }
        .ck-segs i.ok { background: color-mix(in srgb, var(--success) 70%, #14532d); }
        .ck-segs i.wait { background: #eab308; }
        .ck-segs i.back { background: var(--danger); }
        .ck-segs i.miss { background: transparent; box-shadow: inset 0 0 0 1.5px var(--muted); opacity: .5; }
        .ck-req small { font-size: 12px; color: var(--muted); }
        .ck-req small b { color: var(--ink); }
        .ck-req .wait { color: color-mix(in srgb, #eab308 70%, var(--ink)); }
        .ck-req .back { color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .ck-wait { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }
        .ck-wait small { font-size: 11.5px; color: var(--muted); }
        .ck-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 8px; font-size: 11.5px; font-weight: 700; }
        .ck-chip i, .ck-chip svg { width: 13px; height: 13px; }
        .ck-chip.hot { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 75%, var(--ink)); }
        .ck-chip.warn { background: color-mix(in srgb, #eab308 20%, var(--surface)); color: color-mix(in srgb, #eab308 55%, var(--ink)); }
        .ck-chip.ok { background: color-mix(in srgb, var(--success) 15%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .ck-chip.back { background: var(--surface-2); color: var(--muted); }
        .ck-act { display: flex; align-items: center; gap: 10px; justify-content: flex-end; }
        .ck-act form { margin: 0; }
        .ck-btn-outline { display: inline-flex; align-items: center; gap: 7px; padding: 8px 15px; border-radius: 999px; border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--line));
            background: var(--surface); color: var(--ink); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .ck-btn-outline:hover { border-color: var(--accent); }
        .ck-btn-outline i, .ck-btn-outline svg { width: 14px; height: 14px; }
        .ck-go { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-3); color: var(--ink); }
        .ck-go i, .ck-go svg { width: 16px; height: 16px; }
        .ck-foot { text-align: center; font-size: 12.5px; color: var(--muted); margin: 18px 0 0; display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .ck-more { padding: 9px 18px; border-radius: 999px; border: 0; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .ck-more:hover { background: color-mix(in srgb, var(--accent) 14%, var(--surface-3)); }

        /* the group page */
        body.ck .ws-topbar { margin-bottom: 14px; }
        body.ck .ws-back { border-radius: 999px; background: var(--surface-3); border: 0; padding: 9px 16px; font-weight: 700; }
        body.ck .ws-crumb { font-size: 13px; color: var(--muted); }
        body.ck .ws-head { display: flex; align-items: center; gap: 20px; padding: 24px 26px; border-radius: 26px; background: var(--surface-3); border: 0; box-shadow: none; margin-bottom: 16px; }
        body.ck .ws-head-id { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
        body.ck .ws-head-name { font-family: var(--ck-display); font-size: 30px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.1; }
        .ck-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .ck-chips > span { padding: 5px 11px; border-radius: 9px; background: var(--surface); font-size: 12px; font-weight: 600; color: var(--ink); }
        .ck-chips > span.hot { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 75%, var(--ink)); }
        body.ck .ck-chips .ws-names-btn { padding: 5px 11px; border-radius: 9px; border: 0; background: var(--surface); color: var(--accent); font-size: 12px; font-weight: 700; }
        .ck-since { font-size: 12px; color: var(--muted); }
        .ck-progcard { min-width: 250px; padding: 18px 20px; border-radius: 22px; background: var(--surface); box-shadow: var(--shadow-2); display: flex; flex-direction: column; gap: 10px; }
        .ck-big { font-size: 13px; color: var(--muted); }
        .ck-big b { font-family: var(--ck-display); font-size: 32px; font-weight: 700; color: var(--ink); margin-right: 4px; }
        .ck-progcard .ck-segs i { max-width: none; }
        .ck-progcard small { font-size: 11.5px; color: var(--muted); }

        /* sections: one white card each, requirements as rows */
        body.ck .ws-section { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 18px 16px 10px; margin-bottom: 14px; }
        body.ck .ws-section-head { padding: 0 6px; margin-bottom: 8px; }
        body.ck .ws-section-head > i, body.ck .ws-section-head > svg, body.ck .ws-section-rule { display: none; }
        body.ck .ws-section-title { font-family: var(--ck-display); font-size: 17px; font-weight: 600; text-transform: none; letter-spacing: 0; color: var(--ink); }
        body.ck .ws-section-count { background: none; color: var(--muted); font-size: 15px; font-weight: 500; padding: 0; }
        body.ck .ws-section.action .ws-section-head::after { content: 'Check the file is the right one, then approve.'; margin-left: auto; font-size: 12px; color: var(--muted); }
        body.ck .ws-grid { display: flex; flex-direction: column; gap: 4px; }
        body.ck .ws-card { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; grid-template-areas: "chk top act" ". line line" ". more more";
            align-items: center; column-gap: 10px; row-gap: 4px; padding: 10px 12px; border-radius: 16px; border: 0; box-shadow: none; background: transparent; }
        body.ck .ws-card:hover { background: var(--surface-2); transform: none; }
        body.ck .ws-card.selected { background: var(--surface-3); }
        body.ck .ws-card::before, body.ck .ws-card::after { display: none; }
        body.ck .ws-card > .ws-check { grid-area: chk; padding: 0; margin: 0; border: 0; background: none; justify-content: center; }
        body.ck .ws-check-label { display: none; }
        body.ck .ws-check-box { width: 20px; height: 20px; border-radius: 6px; }
        body.ck .ws-card > .ws-card-top { grid-area: top; min-width: 0; }
        body.ck .ws-card > .ws-card-actions { grid-area: act; display: flex; align-items: center; gap: 8px; margin: 0; flex-wrap: nowrap; }
        body.ck .ws-card > .ws-line, body.ck .ws-card > .ws-next { grid-area: line; }
        body.ck .ws-card > .ws-cascade, body.ck .ws-card > .history-content-box, body.ck .ws-card > .ws-reason { grid-column: 2 / -1; }
        body.ck .ws-card > .ck-remind { grid-area: act; margin: 0; }
        body.ck .ws-card-doc .ws-card-icon, body.ck .ws-card .ws-card-icon { width: 36px; height: 36px; border-radius: 11px; }
        body.ck .ws-section.action .ws-card-top .status-pill { display: none; }
        body.ck .ws-card .ws-line:not(.said) { display: none; }   /* the section head already says it */
        body.ck .ws-card-actions .btn-primary { border-radius: 999px; padding: 9px 16px; background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        body.ck .ws-card-actions .ws-btn-revise { color: color-mix(in srgb, var(--danger) 80%, var(--ink)); padding: 9px 10px; }
        body.ck .ws-card-actions .ws-btn-revise i, body.ck .ws-card-actions .ws-btn-revise svg { display: none; }
        body.ck .ws-card.ghost .ws-card-icon { background: transparent; box-shadow: inset 0 0 0 1.5px var(--line); border: 0; }
        body.ck .ws-card.ghost .ws-card-name { color: var(--muted); }
        body.ck .ws-card.ghost .status-pill { display: none; }

        body.ck .ws-back { color: var(--ink); }
        body.ck .ws-back i, body.ck .ws-back svg { width: 15px; height: 15px; }
        body.ck .ws-section.action .ws-section-title { color: var(--ink); }
        body.ck .ws-card-actions .btn { white-space: nowrap; }
        body.ck .ck-chips .ws-names-btn i, body.ck .ck-chips .ws-names-btn svg { width: 14px; height: 14px; }
        body.ck .ws-card.ghost > .ws-next { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); margin: 0; }
        body.ck .ws-card.ghost > .ws-next i, body.ck .ws-card.ghost > .ws-next svg { width: 13px; height: 13px; }
        /* the batch bar: a floating violet pill */
        body.ck .ws-bar { position: sticky; bottom: 16px; z-index: 30; width: fit-content; max-width: 100%; margin: 18px auto 4px; display: flex; align-items: center; gap: 10px;
            padding: 10px 10px 10px 22px; border-radius: 999px; border: 0; background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff;
            box-shadow: 0 18px 44px -12px rgba(46, 16, 101, .6); }
        body.ck .ws-bar-count { color: #fff; min-width: 0; flex: none; }
        body.ck .ws-bar-note { color: rgba(255, 255, 255, .7); }
        body.ck .ws-bar .ws-linkbtn { color: #fff; text-decoration: underline; background: none; border: 0; font-weight: 700; cursor: pointer; }
        body.ck .ws-bar .btn { border-radius: 999px; }
        body.ck .ws-bar .btn-secondary { background: transparent; color: #fff; border: 1.5px solid rgba(255, 255, 255, .5); }
        body.ck .ws-bar .btn-dispatch { background: #fff; color: var(--sh-hero-a, #2e1065); border-color: #fff; }

        @media (max-width: 900px) {
            .ck-cols { display: none; }
            body.ck #grpTiles > .grp-tile { grid-template-columns: minmax(0, 1fr) auto; row-gap: 10px; }
            body.ck #grpTiles > .grp-tile .ck-req, body.ck #grpTiles > .grp-tile .ck-wait { grid-column: 1 / -1; }
            body.ck #grpTiles > .grp-tile .ck-act { grid-row: 1; grid-column: 2; }
            body.ck .ws-head { flex-wrap: wrap; }
            .ck-progcard { width: 100%; }
            body.ck .ws-card { grid-template-columns: 30px minmax(0, 1fr); grid-template-areas: "chk top" ". act" ". line" ". more"; }
            body.ck .ws-section.action .ws-section-head::after { display: none; }
            body.ck .ws-bar { border-radius: 22px; flex-wrap: wrap; justify-content: center; }
        }

        /* ================= PROGRESS, 2026-09-29 (body.spx) — from the Claude Design concept =========
           Look + a header, hero and filters. Every form the rows carry (register, release/revise,
           update, nudge) is unchanged. */
        body.spx { --spx-display: "Outfit", var(--font-ui); }
        body.spx .filter-bar, body.spx .sp-legend { display: none !important; }
        .spx-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
        .spx-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .spx-head h1 { font-family: var(--spx-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.1; }
        .spx-search { display: flex; align-items: center; gap: 9px; width: 360px; max-width: 100%; height: 44px; padding: 0 16px; border-radius: 999px; background: var(--surface-3); }
        .spx-search i, .spx-search svg { width: 16px; height: 16px; color: var(--muted); flex-shrink: 0; }
        .spx-search input[type=search] { all: unset; flex: 1; min-width: 0; font-size: 13px; color: var(--ink); }
        .spx-hero { display: flex; justify-content: space-between; align-items: center; gap: 22px; flex-wrap: wrap; padding: 22px 26px; border-radius: 26px; margin-bottom: 16px;
            color: #fff; background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); position: relative; overflow: hidden; }
        .spx-hero-copy { flex: 1; min-width: 260px; }
        .spx-hero h2 { font-family: var(--spx-display); font-size: 28px; font-weight: 700; letter-spacing: -.02em; color: #fff; }
        .spx-hero p { font-size: 13px; color: rgba(255, 255, 255, .8); margin: 6px 0 14px; max-width: 480px; line-height: 1.5; }
        .spx-hero p b { color: #fff; }
        .spx-go { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; border: 0; cursor: pointer;
            background: #fff; color: var(--sh-hero-a, #2e1065); font: inherit; font-size: 13px; font-weight: 700; }
        .spx-go i, .spx-go svg { width: 15px; height: 15px; }
        .spx-chart { padding: 14px 16px 12px; border-radius: 18px; background: rgba(255, 255, 255, .09); }
        .spx-chart > small { display: block; font-size: 11px; color: rgba(255, 255, 255, .75); margin-bottom: 10px; }
        .spx-bars { display: flex; align-items: flex-end; gap: 8px; }
        .spx-bar { width: 44px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .spx-bar b { font-family: var(--spx-display); font-size: 16px; font-weight: 700; color: #fff; }
        .spx-bar i { width: 100%; border-radius: 7px; background: #fff; }
        .spx-bar.done i { background: #86efac; }
        .spx-bar.done b { color: #86efac; }
        .spx-bar small { font-size: 10.5px; color: rgba(255, 255, 255, .75); }

        body.spx .pl-bar { justify-content: space-between; }
        body.spx .pl-pills { background: var(--surface-3); border: 0; }
        .spx-filters { display: flex; gap: 8px; margin: 0; }
        .spx-filters label { position: relative; display: inline-flex; align-items: center; }
        .spx-filters select { appearance: none; -webkit-appearance: none; padding: 8px 32px 8px 14px; border-radius: 12px; border: 1px solid var(--line);
            background: var(--surface); color: var(--ink); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
        .spx-filters label i, .spx-filters label svg { position: absolute; right: 11px; width: 14px; height: 14px; color: var(--muted); pointer-events: none; }

        body.spx .sp-row { border-radius: 20px; border: 1px solid var(--line); background: var(--surface); box-shadow: none; overflow: hidden; }
        body.spx .sp-row.is-you { box-shadow: inset 4px 0 0 var(--accent); }
        body.spx .sp-head { gap: 16px; padding: 14px 18px; }
        .spx-av { width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0; display: grid; place-items: center;
            background: color-mix(in srgb, #fbbf24 24%, var(--surface)); color: #78350f; font-family: var(--spx-display); font-size: 17px; font-weight: 700; }
        body.spx .sp-row.is-you .spx-av { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); color: var(--accent); }
        body.spx .sp-row.is-done .spx-av { background: color-mix(in srgb, var(--success) 18%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        body.theme-dark.spx .spx-av { color: var(--ink); }
        body.spx .pl-nm { font-family: var(--spx-display); font-size: 15.5px; font-weight: 600; }
        body.spx .sp-cn { display: inline-block; padding: 1px 8px; border-radius: 7px; background: var(--surface-3); color: var(--accent); font-weight: 700; }
        body.spx .sp-next { flex-basis: 200px; }
        body.spx .sp-do { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink); }
        body.spx .sp-row.is-you .sp-do { color: var(--accent); }
        body.spx .sp-age { display: block; font-family: inherit; font-size: 11.5px; font-weight: 500; color: var(--muted); margin-top: 2px; }
        body.spx .pl-open { border-radius: 999px; padding: 8px 16px; font-weight: 700; }
        body.spx .pl-open.is-you { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        body.spx .sp-body { background: var(--surface-2); }
        .spx-label { font-size: 13px; font-weight: 700; color: var(--ink); margin-top: 4px; }
        body.spx .pl-docs-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
        body.spx .pay-doc-chip { justify-content: flex-start; padding: 14px 16px; border-radius: 16px; background: var(--surface); border: 1px solid var(--line); font-size: 13px; }
        body.spx .sp-reg { padding: 14px 16px; border-radius: 16px; background: var(--surface); border: 1px solid var(--line); }
        body.spx .sp-reg .btn-update { border-radius: 999px; background: var(--accent) !important; color: var(--accent-ink); }
        @media (max-width: 760px) { .spx-chart { width: 100%; } .spx-bars { justify-content: space-between; } .spx-bar { width: auto; flex: 1; } }
        /* ================= HISTORY, 2026-09-29 (.lh-*) — from the Claude Design concept ================= */
        .lh { --lh-display: "Outfit", var(--font-ui); }
        /* the log on the left, Recent on the right (Statistics); plagiarism keeps the one column */
        .lh-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; align-items: start; }
        .lh-grid.solo { grid-template-columns: minmax(0, 1fr); }
        .lh-col { min-width: 0; }
        .lh-recent { position: sticky; top: 14px; padding: 18px 14px 12px; border-radius: 24px; background: var(--surface); border: 1px solid var(--line); }
        .lh-recent h3 { margin: 0 8px 8px; font-family: var(--lh-display); font-size: 18px; font-weight: 600; color: var(--ink); }
        .lh-acts { display: flex; flex-direction: column; gap: 2px; }
        .lh-act { display: flex; align-items: center; gap: 11px; padding: 9px 8px; border-radius: 14px; text-decoration: none; color: inherit; }
        .lh-act:hover { background: var(--surface-2); }
        .lh-act-ico { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; background: var(--surface-3); color: var(--accent); }
        .lh-act-ico.success { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 75%, var(--ink)); }
        .lh-act-ico.warning { background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .lh-act-ico i, .lh-act-ico svg { width: 16px; height: 16px; }
        .lh-act-body { display: flex; flex-direction: column; min-width: 0; flex: 1; }
        .lh-act-body b { font-size: 13px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .lh-act-body small { font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lh-act-when { font-size: 11px; color: var(--muted); white-space: nowrap; flex-shrink: 0; align-self: flex-start; padding-top: 2px; }
        .lh-act-none { margin: 6px 8px 10px; font-size: 13px; color: var(--muted); }
        @media (max-width: 1100px) { .lh-grid { grid-template-columns: minmax(0, 1fr); } .lh-recent { position: static; } }
        .lh-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
        .lh-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .lh-head h1 { font-family: var(--lh-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.1; }
        .lh-head p { font-size: 13px; color: var(--muted); margin-top: 4px; }
        .lh-tools { display: flex; align-items: center; gap: 8px; margin: 0; }
        .lh-search { display: flex; align-items: center; gap: 8px; width: 290px; max-width: 60vw; height: 42px; padding: 0 16px; border-radius: 999px; background: var(--surface-3); }
        .lh-search i, .lh-search svg { width: 15px; height: 15px; color: var(--muted); flex-shrink: 0; }
        .lh-search input { all: unset; flex: 1; min-width: 0; font-size: 13px; color: var(--ink); }
        .lh-btn { display: inline-flex; align-items: center; gap: 7px; height: 42px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--line);
            background: var(--surface); color: var(--ink); font-size: 13px; font-weight: 700; text-decoration: none; }
        .lh-btn.icon { width: 42px; padding: 0; justify-content: center; }
        .lh-btn i, .lh-btn svg { width: 15px; height: 15px; }
        .lh-sum { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; padding: 22px 24px; border-radius: 24px; background: var(--surface-3); margin-bottom: 14px; }
        .lh-big { flex: 1; min-width: 120px; display: flex; flex-direction: column; }
        .lh-big b { font-family: var(--lh-display); font-size: 44px; font-weight: 700; line-height: 1; }
        .lh-big small { font-size: 12.5px; color: var(--muted); margin-top: 6px; }
        .lh-big.ok b { color: color-mix(in srgb, var(--success) 70%, #14532d); }
        .lh-big.no b { color: color-mix(in srgb, var(--danger) 80%, #7f1d1d); }
        .lh-big.rem b { color: var(--accent); }
        .lh-chart { padding: 14px 18px 12px; border-radius: 18px; background: var(--surface); box-shadow: var(--shadow-1); }
        .lh-chart > small { display: block; font-size: 11px; color: var(--muted); margin-bottom: 8px; }
        .lh-bars { display: flex; align-items: flex-end; gap: 16px; }
        .lh-bar { display: flex; flex-direction: column; align-items: center; gap: 3px; min-width: 34px; }
        .lh-bar > b { font-size: 12px; font-weight: 800; color: var(--ink); }
        .lh-stack { display: flex; flex-direction: column; width: 30px; border-radius: 7px; overflow: hidden; }
        .lh-stack i { display: block; width: 100%; }
        .lh-stack i.ok { background: color-mix(in srgb, var(--success) 70%, #14532d); }
        .lh-stack i.no { background: color-mix(in srgb, var(--danger) 85%, #7f1d1d); }
        .lh-stack i.rem { background: color-mix(in srgb, var(--accent) 45%, var(--surface)); }
        .lh-bar > small { font-size: 10.5px; color: var(--muted); }
        .lh-pills { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); width: fit-content; max-width: 100%; margin-bottom: 14px; }
        .lh-pills a { display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 600; color: var(--ink); text-decoration: none; }
        .lh-pills a span { min-width: 18px; padding: 1px 6px; border-radius: 999px; background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); font-size: 10.5px; font-weight: 800; text-align: center; }
        .lh-pills a.on { background: var(--surface); box-shadow: var(--shadow-1); font-weight: 700; }
        .lh-pills a.on span { background: var(--sh-hero-a, #2e1065); color: #fff; }
        .lh-pills a.zero { opacity: .55; }
        .lh-matching { font-size: 12px; color: var(--muted); padding: 0 10px; }
        .lh-months { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 6px 18px; }
        .lh-months .lg-month + .lg-month { border-top: 1px solid var(--line); }
        .lh-months .lg-mname { font-family: var(--lh-display); font-size: 16px; font-weight: 600; }
        .lh-mbar { display: flex; gap: 3px; width: 190px; height: 6px; flex-shrink: 0; }
        .lh-mbar i { display: block; height: 100%; border-radius: 3px; flex-basis: 0; }
        .lh-mbar i.ok { background: color-mix(in srgb, var(--success) 70%, #14532d); }
        .lh-mbar i.no { background: color-mix(in srgb, var(--danger) 85%, #7f1d1d); }
        .lh-mbar i.rem { background: color-mix(in srgb, var(--accent) 45%, var(--surface)); }
        .lh-mbar i.oth { background: var(--surface-3); }
        .lh-msum { flex: 1; font-size: 12px; color: var(--muted); padding-left: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lh-day { display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 14px; padding: 12px 0; border-top: 1px solid var(--line); }
        .lh-day:first-child { border-top: 0; }
        .lh-when { display: flex; flex-direction: column; padding-top: 6px; }
        .lh-when b { font-size: 13px; color: var(--ink); }
        .lh-when.near b { color: var(--accent); }
        .lh-when small { font-size: 11px; color: var(--muted); }
        .lh-list { display: flex; flex-direction: column; gap: 6px; }
        .lh-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 14px; border-radius: 16px; background: var(--surface-3); }
        .lh-ico { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; background: var(--surface); color: var(--muted); }
        .lh-ico i, .lh-ico svg { width: 15px; height: 15px; }
        .lh-row.ok .lh-ico { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 72%, var(--ink)); }
        .lh-row.no .lh-ico { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: var(--danger); }
        .lh-row.rem .lh-ico { background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .lh-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
        .lh-main > b { font-size: 13.5px; color: var(--ink); line-height: 1.3; }
        .lh-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 11.5px; color: var(--muted); }
        .lh-chip { padding: 2px 8px; border-radius: 6px; font-weight: 800; font-size: 10.5px; background: var(--surface); color: var(--muted); }
        .lh-chip.ok { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .lh-chip.no { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 72%, var(--ink)); }
        .lh-chip.rem { background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .lh-cn { padding: 2px 8px; border-radius: 6px; background: var(--surface); color: var(--accent); font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 700; }
        .lh-doc { font-weight: 700; color: var(--ink); }
        .lh-note { margin: 2px 0 0; font-size: 12px; color: var(--ink); font-style: italic; }
        .lh-time { font-size: 11.5px; color: var(--muted); white-space: nowrap; padding-top: 7px; }
        @media (max-width: 760px) {
            .lh-search { width: 100%; max-width: none; }
            .lh-tools { width: 100%; }
            .lh-mbar { width: 90px; }
            .lh-msum { display: none; }
            .lh-day { grid-template-columns: 1fr; gap: 6px; }
            .lh-chart { width: 100%; }
        }
        /* ================= THE FILE VIEWER, 2026-09-29 (.dv-*) — from the Claude Design concept ================= */
        .preview-modal { background: rgba(46, 16, 101, .34); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); padding: 14px; align-items: center; }
        .preview-content { width: min(1180px, 96vw); height: calc(100vh - 28px); border-radius: 30px; border: 0; box-shadow: 0 30px 80px -20px rgba(46, 16, 101, .5); }
        .preview-header.dv-head { background: var(--surface); border: 0; padding: 18px 22px 10px; }
        .dv-form { align-self: flex-start; padding: 4px 10px; border-radius: 8px; background: var(--surface-3); font-size: 11px; font-weight: 700; color: var(--accent) !important; }
        .dv-head #modalTitle { font-size: 13px; font-weight: 600; color: var(--ink); margin: 4px 0 0; }
        .dv-pager { display: inline-flex; align-items: center; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); margin-left: auto; }
        .dv-pager span { font-size: 12px; font-weight: 700; padding: 0 6px; }
        .dv-pager button, .dv-x { width: 32px; height: 32px; border-radius: 50%; border: 0; display: grid; place-items: center; cursor: pointer; background: var(--surface); color: var(--ink); }
        .dv-pager button:disabled { opacity: .35; cursor: default; }
        .dv-x { width: 40px; height: 40px; background: var(--surface-3); flex-shrink: 0; }
        .dv-pager i, .dv-pager svg, .dv-x i, .dv-x svg { width: 16px; height: 16px; }
        .preview-body { padding: 4px 22px 22px; gap: 20px; background: var(--surface); }
        .dv-stage { flex: 1.35; position: relative; border-radius: 24px; background: var(--surface-3); overflow: hidden; display: flex; }
        .dv-canvas { flex: 1; overflow: auto; padding: 18px 18px 64px; display: flex; }
        .dv-canvas .preview-frame { flex: none; width: 100%; height: 100%; min-height: 60vh; border-radius: 6px; background: #fff;
            box-shadow: 0 12px 30px -12px rgba(46, 16, 101, .35); transform-origin: 0 0; }
        .dv-tools { position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%); display: flex; align-items: center; gap: 4px;
            padding: 5px; border-radius: 999px; background: var(--sh-hero-a, #2e1065); color: #fff; box-shadow: 0 10px 24px -8px rgba(46, 16, 101, .6); }
        .dv-tools button { width: 30px; height: 30px; border-radius: 50%; border: 0; background: transparent; color: #fff; display: grid; place-items: center; cursor: pointer; }
        .dv-tools button:hover { background: rgba(255, 255, 255, .14); }
        .dv-tools .dv-full { background: #6d28d9; }
        .dv-tools i, .dv-tools svg { width: 15px; height: 15px; }
        .dv-tools span { font-size: 12px; font-weight: 700; min-width: 44px; text-align: center; }
        .dv-stage:fullscreen { border-radius: 0; }
        .preview-sidebar { flex: .8; min-width: 300px; padding: 4px 4px 0; border: 0; display: flex; flex-direction: column; }
        .dv-name { font-family: "Outfit", var(--font-ui); font-size: 24px; font-weight: 700; letter-spacing: -.01em; color: var(--ink); margin: 6px 0 2px; }
        .dv-meta { font-size: 12.5px; color: var(--muted); margin: 0 0 14px; }
        .preview-sidebar .action-form-modal { flex: 1; gap: 10px; }
        .dv-lab { font-size: 12.5px; font-weight: 700; color: var(--ink); margin-top: 6px; }
        .dv-lab span { font-weight: 500; color: var(--muted); }
        .dv-choices { display: flex; flex-direction: column; gap: 8px; }
        .dv-choice { display: flex !important; align-items: center; gap: 12px; padding: 12px 14px !important; border-radius: 16px !important; cursor: pointer;
            border: 1.5px solid var(--line) !important; background: var(--surface) !important; color: var(--ink) !important; font: inherit; font-size: 14px !important; font-weight: 700; text-align: left !important; }
        .dv-choice i, .dv-choice svg { width: 26px; height: 26px; padding: 6px; border-radius: 50%; color: #fff; flex-shrink: 0; }
        .dv-choice.ok i, .dv-choice.ok svg { background: color-mix(in srgb, var(--success) 75%, #14532d); }
        .dv-choice.back i, .dv-choice.back svg { background: color-mix(in srgb, var(--danger) 80%, #7f1d1d); }
        .dv-choice.ok.on { border-color: color-mix(in srgb, var(--success) 70%, #14532d) !important; background: color-mix(in srgb, var(--success) 12%, var(--surface)) !important; }
        .dv-choice.back.on { border-color: var(--danger) !important; background: color-mix(in srgb, var(--danger) 9%, var(--surface)) !important; }
        .dv-choices.shake { animation: dvShake .45s; }
        @keyframes dvShake { 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }
        .dv-hidden-select { display: none !important; }
        .preview-sidebar textarea#modal_remarks { width: 100%; min-height: 110px; padding: 12px 14px; border-radius: 16px; border: 1px solid transparent; resize: vertical;
            background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; box-sizing: border-box; }
        .preview-sidebar textarea#modal_remarks:focus { outline: none; border-color: color-mix(in srgb, var(--accent) 45%, transparent); background: var(--surface); }
        .dv-foot { margin-top: auto; display: flex; flex-direction: column; gap: 8px; padding-top: 14px; }
        .dv-go { padding: 14px !important; border-radius: 999px !important; border: 0; cursor: pointer; background: var(--accent) !important; color: var(--accent-ink) !important; font-size: 14px !important; }
        .dv-go.back { background: var(--danger) !important; color: #fff !important; }
        .dv-go:disabled { opacity: .5; cursor: not-allowed; }
        .dv-dl { display: flex !important; align-items: center; justify-content: center; gap: 8px; padding: 12px !important; border-radius: 999px !important;
            border: 1.5px solid var(--line) !important; background: var(--surface) !important; color: var(--ink) !important; font-size: 13px !important; }
        .dv-dl i, .dv-dl svg { width: 15px; height: 15px; }
        @media (max-width: 820px) {
            .preview-modal { padding: 0; }
            .preview-content { width: 100vw; height: 100vh; border-radius: 0; }
            .preview-body { flex-direction: column; padding: 4px 14px 14px; overflow-y: auto; }
            .dv-stage { flex: none; height: 46vh; }
            .preview-sidebar { flex: none; min-width: 0; }
        }    </style>
</head>

<body class="admin-module<?= $use_group_inbox ? ' ck' : '' ?><?= ($phase === 'stats' && $stats_view === 'pipeline') ? ' spx' : '' ?><?= $is_pz_view ? ' pz-page' : '' ?>">
<?php include __DIR__ . '/components/admin_view_only.php'; ?>

    <?php // No page header: the side rail already says which phase is open, and the title +
    // blurb cost ~90px of vertical space on every screen. The filter bar starts at the top. ?>

    <?php if ($use_group_inbox): ?>
        <?php // Requirements checklist (2026-09-29 concept). Hidden while a group is open — the
              // group page has its own header and the way back. ?>
        <header class="ck-head">
            <span class="ck-eyebrow"><?= $phase === 'stats' ? 'Statistics' : ucfirst($phase) ?></span>
            <h1>Requirements checklist</h1>
            <p><?= $phase === 'stats'
                ? 'Each group&rsquo;s statistics requirements, and where they stand.'
                : 'Focal persons review each group&rsquo;s requirements. Follow their progress here.' ?></p>
        </header>
        <?php if ($phase !== 'stats'): ?>
            <?php // Worded to be TRUE for every viewer: the focal person approves and consolidates;
                  // the office can still open, and step in. Not "you are only viewing". ?>
            <div class="ck-note">
                <span class="ck-note-ico"><i data-lucide="users-round"></i></span>
                <p><b>Focal persons check these requirements</b> and put each section forward once every group
                   in it is ready. You can open any group to follow along, or step in when needed.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert font-bold" id="adminModuleAlert">
            <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 8px;"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Filter Bar (not shown on the History log — it filters the group review queue, which
         the payment-decision log isn't; History has its own month/year filter instead) -->
    <?php if (!$is_history_view && !$is_notify_view && !$is_pz_view): ?>
    <div class="filter-bar cl-filters">
        <form method="GET" action="">
            <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">
            <?php // Keep the screen you searched FROM. Without this a search on Progress
                  // (view=pipeline) reloaded as the phase default, the requirement checklist. ?>
            <?php if (!empty($_GET['view'])): ?>
                <input type="hidden" name="view" value="<?= htmlspecialchars($_GET['view']) ?>">
            <?php endif; ?>

            <!-- The group picker is collapsed by default so the review area gets the full width;
                 this reveals it on demand and the choice sticks per browser. -->
            <button type="button" class="groups-toggle" id="groupsToggle" onclick="toggleGroupSidebar()" title="Show or hide the group list">
                <i data-lucide="panel-left-open" style="width:15px;height:15px;"></i>
                <span class="groups-toggle-label">Groups</span>
            </button>

            <div class="search-input-wrapper">
                <i data-lucide="search" style="color: var(--muted); margin-right: 8px; width: 18px; height: 18px;"></i>
                <input type="text" name="search" placeholder="Search a group, a student, a course&hellip;" value="<?= htmlspecialchars($search_query) ?>" onchange="this.form.submit()">
            </div>

            <?php /* A pill that LOOKS set when it is set: an untouched filter is quiet, a chosen
                     one carries the accent, so the state of the query is readable without
                     reading three dropdowns. */ ?>
            <label class="f-sel<?= $selected_department !== '' ? ' on' : '' ?>">
                <select name="department" onchange="this.form.submit()" aria-label="Filter by institution">
                    <option value="">All institutions</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= ($selected_department === $dept) ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="f-sel<?= $selected_program !== '' ? ' on' : '' ?>">
                <select name="program" onchange="this.form.submit()" aria-label="Filter by course">
                    <option value="">All courses</option>
                    <?php foreach ($program_options as $prog): ?>
                        <option value="<?= htmlspecialchars($prog) ?>" <?= ($selected_program === $prog) ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                    <?php endforeach; ?>
                </select>
                <i data-lucide="chevron-down"></i>
            </label>
            <label class="f-sel<?= $sort_date === 'ASC' ? ' on' : '' ?>">
                <select name="sort_date" onchange="this.form.submit()" aria-label="Sort order">
                    <option value="DESC" <?= ($sort_date === 'DESC') ? 'selected' : '' ?>>Newest first</option>
                    <option value="ASC" <?= ($sort_date === 'ASC') ? 'selected' : '' ?>>Oldest first</option>
                </select>
                <i data-lucide="arrow-up-down"></i>
            </label>

            <?php /* Reset only exists when there is something to reset. A red button at the end of
                     the row, lit whether or not the query was ever touched, is an alarm for a
                     situation that is not happening. */ ?>
            <?php $cl_filtered = $search_query !== '' || $selected_department !== '' || $selected_program !== '' || $sort_date === 'ASC'; ?>
            <?php if ($cl_filtered): ?>
                <a href="admin_module_dynamic.php?phase=<?= htmlspecialchars($phase) ?>" class="f-clear">
                    <i data-lucide="x"></i>Clear
                </a>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <!-- MAIN GRID. Starts collapsed (full-width) so the review area owns the whole module; the
         Groups button in the filter bar brings the picker back. -->
    <div class="workspace-grid full-width" id="workspaceGrid">

        <!-- SIDEBAR: Interactive Selector Directory (hidden on the History log — see note above) -->
        <?php if (!$is_history_view && !$is_notify_view && !$is_pz_view): ?>
        <aside class="group-sidebar">
            <h3 class="sidebar-title">Select Student Group</h3>

            <!-- Sidebar Quick Search -->
            <div class="sidebar-search-container" style="position: relative; display: flex; align-items: center; background: var(--surface-2); border: 1.5px solid var(--border-line); border-radius: 10px; padding: 8px 12px; margin-bottom: 2px;">
                <i data-lucide="search" style="color: var(--muted); width: 14px; height: 14px; margin-right: 8px; flex-shrink:0;"></i>
                <input type="text" id="sidebarGroupSearch" placeholder="Filter groups/students..." onkeyup="filterSidebarGroups()" style="background: transparent; border: none; outline: none; font-size: 11.5px; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark); font-family: var(--ui-sans);">
            </div>

            <div class="group-select-item active" onclick="selectStudentGroup(null)" id="sidebar-g-all" style="margin-bottom: 2px;">
                <div class="mini-avatar" style="display:flex; align-items:center; justify-content:center; background:var(--mcnp-teal); color:white; font-size:11px; font-weight:bold;">ALL</div>
                <div style="overflow:hidden;">
                    <h4 class="mini-group-name">All Student Groups</h4>
                    <p class="mini-group-lead">Display all uploaded files</p>
                </div>
            </div>

            <div id="sidebarGroupsList" style="display: flex; flex-direction: column; gap: 10px;">
                <?php if (empty($all_student_groups)): ?>
                    <div style="font-size: 11px; color:var(--muted); text-align:center; padding: 10px 0;">No students mapped.</div>
                <?php else: ?>
                    <?php $sidebar_gi = 0; foreach ($all_student_groups as $g):
                        $pfp = $g['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($g['username']);
                        $sidebar_gi++;
                        $sidebar_extra = $sidebar_gi > 7;
                    ?>
                        <div class="group-select-item" <?= $sidebar_extra ? 'data-extra="1" style="display:none;"' : '' ?> onclick="selectStudentGroup(<?= htmlspecialchars(json_encode($g)) ?>)" id="sidebar-g-<?= $g['user_id'] ?>" data-group-name="<?= htmlspecialchars(strtolower($g['research_group_name'])) ?>" data-student-name="<?= htmlspecialchars(strtolower($g['username'])) ?>" data-program-name="<?= htmlspecialchars(strtolower($g['program'])) ?>" data-school-id="<?= htmlspecialchars(strtolower($g['student_number'] ?? '')) ?>">
                            <img src="<?= htmlspecialchars($pfp) ?>" class="mini-avatar">
                            <div style="overflow:hidden;">
                                <h4 class="mini-group-name"><?= htmlspecialchars($g['research_group_name']) ?></h4>
                                <p class="mini-group-lead">Leader: <?= htmlspecialchars($g['username']) ?><?= !empty($g['student_number']) ? ' &middot; ' . htmlspecialchars($g['student_number']) : '' ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($all_student_groups) > 7): ?>
                        <button type="button" id="sidebarShowMoreGroups" onclick="toggleSidebarShowMore()" style="width:100%; padding:8px; font-size:11px; font-weight:700; color:var(--ink); background:var(--surface-2); border:1.5px dashed var(--border-line); border-radius:8px; cursor:pointer;">
                            Show all <?= count($all_student_groups) ?> groups
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </aside>
        <?php endif; ?>

        <!-- RIGHT PANEL: Action items & Active workspace -->
        <section class="main-content-panel">

            <!-- Dynamic Group Detail profile card (when selected) -->
            <div class="group-profile-card" id="activeProfileCard" style="display: none;">
                <div style="display:flex; gap:16px; align-items:start;">
                    <img id="cardPfp" src="" class="pfp-large">
                    <div style="flex:1;">
                        <h3 id="cardGroupName" style="color:var(--mcnp-teal); font-family:var(--font-ui); font-weight:800; letter-spacing:-0.02em; font-size:16px; margin:0 0 4px 0;">Group Research</h3>
                        <p id="cardLeader" style="font-size:12.5px; color:var(--ink-soft); margin:0 0 2px 0; font-weight:600;">Leader:</p>
                        <p id="cardEmail" style="font-size:11.5px; color:var(--muted); margin:0 0 2px 0; font-family:'JetBrains Mono', monospace;"></p>
                        <p id="cardDetails" style="font-size:11px; color:var(--muted); margin:0;"></p>
                        <p id="cardSchoolIdWrap" style="margin:5px 0 0 0; display:none;"><span class="school-id-chip"><i data-lucide="id-card"></i><span id="cardSchoolId"></span></span></p>
                    </div>

                    <div style="text-align:right; min-width: 140px;">
                        <span style="font-size:10px; font-weight:800; color:var(--muted); letter-spacing:0.5px; text-transform:uppercase;">Overall Checklist Complete</span>
                        <div style="font-size:15px; font-weight:800; color:var(--mcnp-teal); margin-top:2px;" id="cardProgressText">0%</div>
                        <div class="shimmer-bar">
                            <div class="shimmer-progress" id="cardProgressBar"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review toolbar: status filters on the left, the group/requirement lens pushed to the
                 far right of the same row. They used to be stacked, which cost a whole band of
                 vertical space above the group tiles for two controls that fit side by side. -->
            <?php if ($phase !== 'plag' && !in_array($stats_view, ['payments', 'release', 'history', 'pipeline'], true)): ?>
            <div class="review-toolbar">
                <div class="status-tabs-container">
                    <button type="button" class="status-filter-tab active" data-filter="action" onclick="filterByStatus('action')">
                        <i data-lucide="inbox" style="width: 14px; height: 14px;"></i> Action Needed
                        <span class="tab-n" data-n="action"></span>
                    </button>
                    <button type="button" class="status-filter-tab" data-filter="revision" onclick="filterByStatus('revision')">
                        <i data-lucide="alert-circle" style="width: 14px; height: 14px;"></i> Revision History
                        <span class="tab-n" data-n="revision"></span>
                    </button>
                    <button type="button" class="status-filter-tab" data-filter="approved" onclick="filterByStatus('approved')">
                        <i data-lucide="archive" style="width: 14px; height: 14px;"></i> Approved Archive
                        <span class="tab-n" data-n="approved"></span>
                    </button>
                    <button type="button" class="status-filter-tab" data-filter="all" onclick="filterByStatus('all')">
                        <i data-lucide="layers" style="width: 14px; height: 14px;"></i> All Submissions
                        <span class="tab-n" data-n="all"></span>
                    </button>
                </div>

            </div>
            <?php endif; ?>

            <?php if ($phase === 'stats' && $stats_view === 'pipeline'):
                $sp_stages = sp_stages();
                $sp_rows   = sp_rows($pdo, $fp_scope_sql, $fp_scope_params);
                // The toolbar above already searches; honour it here rather than adding a box.
                if ($search_query !== '' || $selected_department !== '' || $selected_program !== '') {
                    $sp_rows = array_values(array_filter($sp_rows, function ($r) use ($search_query, $selected_department, $selected_program) {
                        if ($selected_department !== '' && $r['department'] !== $selected_department) return false;
                        if ($selected_program !== '' && $r['program'] !== $selected_program) return false;
                        if ($search_query === '') return true;
                        $hay = strtolower($r['research_group_name'] . ' ' . $r['username'] . ' '
                             . $r['student_number'] . ' ' . $r['program'] . ' ' . $r['formatted_control_no']);
                        return strpos($hay, strtolower($search_query)) !== false;
                    }));
                }
                $sp_counts   = sp_counts($sp_rows);
                $sp_filtered = ($search_query !== '' || $selected_department !== '' || $selected_program !== '');
                // Open on the band with work in it.
                $sp_open = $sp_counts['you'] ? 'you' : ($sp_counts['them'] ? 'them' : 'done');
                $sp_mine  = ($role === 'Statistician');
                // The five deliverables she can reopen from the Treat stage's "Needs revision".
                $sp_deliv = $sp_mine
                    ? $pdo->query("SELECT item_id, item_name FROM checklist_items WHERE item_id IN (31,32,33,34,35) ORDER BY item_id")->fetchAll(PDO::FETCH_KEY_PAIR)
                    : [];
                $sp_bands = [
                    'you'  => [$sp_mine ? 'Your move'       : 'With the statistician', $sp_counts['you']],
                    'them' => [$sp_mine ? 'Waiting on them' : 'Waiting on the group',  $sp_counts['them']],
                    'done' => ['Released',                                             $sp_counts['done']],
                ];
                // The hero: how much is on her desk, the one that has waited longest, and a small
                // bar per stage of what is waiting on HER (Done shows what has been released).
                $spx_you = array_values(array_filter($sp_rows, fn($r) => $r['owner'] === 'you'));
                usort($spx_you, fn($a, $b) => $b['days'] <=> $a['days']);
                $spx_old = $spx_you[0] ?? null;
                $spx_bar = array_fill(0, count($sp_stages), 0);
                foreach ($sp_rows as $r) {
                    if ($r['owner'] === 'you') $spx_bar[(int)$r['at']]++;
                    if ($r['owner'] === 'done') $spx_bar[count($sp_stages) - 1]++;
                }
                $spx_max = max(1, max($spx_bar));
            ?>
                <header class="spx-head">
                    <div>
                        <span class="spx-eyebrow">Statistics</span>
                        <h1>Progress</h1>
                    </div>
                    <form method="GET" class="spx-search">
                        <input type="hidden" name="phase" value="stats"><input type="hidden" name="view" value="pipeline">
                        <?php if ($selected_department !== ''): ?><input type="hidden" name="department" value="<?= htmlspecialchars($selected_department) ?>"><?php endif; ?>
                        <?php if ($selected_program !== ''): ?><input type="hidden" name="program" value="<?= htmlspecialchars($selected_program) ?>"><?php endif; ?>
                        <i data-lucide="search"></i>
                        <input type="search" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Group, student, course or control number">
                    </form>
                </header>

                <section class="spx-hero">
                    <div class="spx-hero-copy">
                        <?php if ($spx_old): ?>
                            <h2><?= count($spx_you) ?> request<?= count($spx_you) === 1 ? '' : 's' ?> <?= $sp_mine ? (count($spx_you) === 1 ? 'needs you' : 'need you') : (count($spx_you) === 1 ? 'is with the statistician' : 'are with the statistician') ?></h2>
                            <p>The oldest is <b><?= htmlspecialchars($spx_old['research_group_name']) ?></b>. It has waited
                               <?= $spx_old['days'] ?> day<?= $spx_old['days'] === 1 ? '' : 's' ?> to <?= htmlspecialchars(lcfirst($spx_old['do'])) ?>.</p>
                            <button type="button" class="spx-go" onclick="spxStart('sp<?= (int)$spx_old['form_id'] ?>')"><?= $sp_mine ? 'Start with the oldest' : 'See the oldest' ?> <i data-lucide="arrow-right"></i></button>
                        <?php else: ?>
                            <h2><?= $sp_mine ? 'Nothing is waiting on you' : 'Nothing is with the statistician' ?></h2>
                            <p>Every request is either with its group or already released.</p>
                        <?php endif; ?>
                    </div>
                    <div class="spx-chart" aria-label="Waiting on <?= $sp_mine ? 'you' : 'the statistician' ?>, by stage">
                        <small>Waiting on <?= $sp_mine ? 'you' : 'the statistician' ?>, by stage (Done shows released)</small>
                        <div class="spx-bars">
                            <?php foreach ($sp_stages as $si => $st): $v = $spx_bar[$si]; $last = $si === count($sp_stages) - 1; ?>
                                <div class="spx-bar<?= $last ? ' done' : '' ?>">
                                    <b><?= $v ?></b>
                                    <i style="height:<?= $v ? max(8, round($v / $spx_max * 44)) : 3 ?>px"></i>
                                    <small><?= $st['label'] ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <div class="sp-wrap">
                    <div class="pl-bar">
                        <div class="pl-pills" role="group" aria-label="Whose move">
                            <?php foreach ($sp_bands as $bk => [$blabel, $bn]): ?>
                                <button type="button" class="pl-pill-btn<?= $bk === $sp_open ? ' active' : '' ?>"
                                        data-band="<?= $bk ?>" onclick="spBand('<?= $bk ?>', this)"
                                        <?= $bn === 0 && $bk !== $sp_open ? 'disabled' : '' ?>>
                                    <?= $blabel ?> <span class="pl-count"><?= $bn ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <?php // The legend earns its place: the spine is the only thing on screen
                              // whose shapes are not self-explanatory. ?>
                        <form method="GET" class="spx-filters">
                            <input type="hidden" name="phase" value="stats"><input type="hidden" name="view" value="pipeline">
                            <?php if ($search_query !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>"><?php endif; ?>
                            <label><select name="department" onchange="this.form.submit()" aria-label="Institution">
                                <option value="">All institutions</option>
                                <?php foreach ($departments as $dept): ?><option value="<?= htmlspecialchars($dept) ?>" <?= $selected_department === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option><?php endforeach; ?>
                            </select><i data-lucide="chevron-down"></i></label>
                            <label><select name="program" onchange="this.form.submit()" aria-label="Course">
                                <option value="">All courses</option>
                                <?php foreach ($program_options as $prog): ?><option value="<?= htmlspecialchars($prog) ?>" <?= $selected_program === $prog ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option><?php endforeach; ?>
                            </select><i data-lucide="chevron-down"></i></label>
                        </form>
                    </div>

                    <?php if (!$sp_rows): ?>
                        <div class="nx">
                            <?php $quill_pose = $sp_filtered ? 'thinking' : 'sleeping'; include __DIR__ . '/components/quill_empty.php'; ?>
                            <h3><?= $sp_filtered ? 'No request matches those filters' : 'No statistics requests yet' ?></h3>
                            <p><?= $sp_filtered
                                ? 'Clear the search or the institution filter above to see the whole cohort.'
                                : 'A group appears here the moment it asks for statistical treatment.' ?></p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($sp_rows as $r):
                        $sp_pfp = !empty($r['profile_pic']) ? $r['profile_pic']
                                : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($r['username']);
                        $sp_id  = 'sp' . (int)$r['form_id'];
                    ?>
                    <div class="sp-row is-<?= $r['owner'] ?>" data-band="<?= $r['owner'] ?>" <?= $r['owner'] === $sp_open ? '' : 'hidden' ?>>
                        <div class="sp-head" onclick="spToggle('<?= $sp_id ?>', this)">
                            <span class="spx-av"><?= htmlspecialchars(strtoupper(mb_substr($r['research_group_name'] ?: 'G', 0, 1))) ?></span>
                            <div class="sp-who">
                                <div class="pl-nm">
                                    <?= htmlspecialchars($r['research_group_name']) ?>
                                    <?php if (!empty($r['student_number'])): ?><span class="pl-sid"><?= htmlspecialchars($r['student_number']) ?></span><?php endif; ?>
                                    <?php if ($r['flag']): ?><span class="sp-flag"><?= htmlspecialchars($r['flag']) ?></span><?php endif; ?>
                                </div>
                                <div class="pl-sub"><?= htmlspecialchars($r['username']) ?> &middot; <?= htmlspecialchars($r['program'] ?: $r['department']) ?><?php
                                    if ($r['formatted_control_no']): ?> &middot; <span class="sp-cn"><?= htmlspecialchars($r['formatted_control_no']) ?></span><?php endif; ?></div>
                            </div>

                            <?php // THE SPINE. Six nodes, the one they are standing on coloured by
                                  // whose move it is. This is the whole point of the screen: where a
                                  // group is, and who is holding it up, without opening anything. ?>
                            <ol class="sp-spine" aria-label="Progress">
                                <?php foreach ($sp_stages as $i => $st):
                                    $cls = $i < $r['at'] ? 'done' : ($i > $r['at'] ? 'todo' : ($r['owner'] === 'done' ? 'done' : $r['owner']));
                                ?>
                                    <li class="sp-node <?= $cls ?>" title="<?= htmlspecialchars($st['tip']) ?>">
                                        <span class="sp-dot"></span>
                                        <span class="sp-lab"><?= $st['label'] ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>

                            <div class="sp-next">
                                <span class="sp-do"><?= htmlspecialchars($r['do']) ?></span>
                                <span class="sp-age">Last activity <?= $r['days'] === 0 ? 'today' : ($r['days'] === 1 ? 'yesterday' : $r['days'] . ' days ago') ?></span>
                            </div>

                            <button type="button" class="pl-open<?= $r['owner'] === 'you' && $sp_mine ? ' is-you' : '' ?>" aria-expanded="false">
                                <?= $r['owner'] === 'you' && $sp_mine ? 'Act' : 'Open' ?> <i data-lucide="chevron-down"></i>
                            </button>
                        </div>

                        <div class="sp-body" id="<?= $sp_id ?>" hidden>
                            <div class="rl-facts">
                                <span><i data-lucide="building-2"></i><?= htmlspecialchars($r['department']) ?></span>
                                <?php if (!empty($r['email'])): ?><span><i data-lucide="mail"></i><?= htmlspecialchars($r['email']) ?></span><?php endif; ?>
                                <span><i data-lucide="calendar"></i>Requested <?= date('M j, Y', strtotime($r['date_submitted'])) ?></span>
                                <?php if ($r['at'] >= 3): ?><span><i data-lucide="check-check"></i><?= (int)$r['reqs_ok'] ?>/5 deliverables approved</span><?php endif; ?>
                                <?php if ($r['date_released']): ?><span><i data-lucide="package-check"></i>Released <?= date('M j, Y', strtotime($r['date_released'])) ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($r['research_title'])): ?>
                                <div class="pl-note"><i data-lucide="book-open"></i><?= htmlspecialchars($r['research_title']) ?></div>
                            <?php endif; ?>
                            <?php if ($r['at'] === 5 && !empty($r['result_file'])): ?>
                                <div class="sp-released">
                                    <a class="pay-btn-ghost" href="<?= htmlspecialchars($r['result_file']) ?>" target="_blank" rel="noopener"><i data-lucide="download"></i> Released file</a>
                                    <?php if (!empty($r['statistician_remarks'])): ?>
                                        <p class="sp-rel-note"><b>Note to the group:</b> <?= nl2br(htmlspecialchars($r['statistician_remarks'])) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($role !== 'Statistician'): ?>
                                <div class="pay-readonly-note"><i data-lucide="lock" style="width:12px;height:12px;"></i> Acting on a request is handled by the Statistician.</div>

                            <?php elseif ($r['owner'] === 'you' && $r['at'] === 2): ?>
                                <?php // WHAT SHE IS TRUSTING. The number is permanent, so the two
                                      // documents behind it belong above the box that mints it —
                                      // registering blind is the one thing the old payment desk did
                                      // better, and this screen replaces that desk. ?>
                                <div class="pl-docs-row">
                                    <?php foreach ([36 => 'Validated Form', 37 => 'Official Receipt'] as $d_id => $d_label): ?>
                                        <?php if (isset($r['pay_docs'][$d_id])):
                                            $d = $r['pay_docs'][$d_id];
                                            $d_modal = [
                                                'upload_id' => $d['upload_id'],
                                                'student_user_id' => $r['user_id'],
                                                'research_group_name' => $r['research_group_name'],
                                                'file_path' => $d['file_path'],
                                                'original_filename' => $d['original_filename'],
                                                'verification_status' => $d['verification_status'],
                                                'remarks' => $d['remarks'],
                                                'form_008_data' => null,
                                            ];
                                            $d_pill = strtolower($d['verification_status']) === 'revision requested' ? 'revision'
                                                    : (strtolower($d['verification_status']) === 'approved' ? 'approved' : 'review');
                                        ?>
                                            <button type="button" class="pay-doc-chip" onclick='openDocumentModal(<?= htmlspecialchars(json_encode($d_modal), ENT_QUOTES) ?>, "<?= htmlspecialchars($d_label, ENT_QUOTES) ?>", <?= $d_id ?>)'>
                                                <i data-lucide="eye"></i> <?= $d_label ?>
                                                <span class="status-pill <?= $d_pill ?>" style="font-size:8px; padding:1px 6px;"><?= htmlspecialchars($d['verification_status']) ?></span>
                                            </button>
                                        <?php else: ?>
                                            <span class="pay-doc-missing"><i data-lucide="file-x"></i><?= $d_label ?>: not uploaded</span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!$r['pay_docs']): ?>
                                        <span class="pay-doc-missing"><i data-lucide="hand-coins"></i>Nothing uploaded &mdash; paid at the Research Office</span>
                                    <?php endif; ?>
                                </div>

                                <?php // THE COMPOSER. She supplies the sequence; the rest is derived
                                      // from the group's course. Previewing it matters because the
                                      // number is permanent and she used to only see it afterwards. ?>
                                <div class="spx-label">Issue the control number</div>
                                <form method="POST" class="sp-act sp-reg">
                                    <input type="hidden" name="action_type" value="acknowledge_payment">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="form_id" value="<?= $r['form_id'] ?>">
                                    <div class="sp-cn-build">
                                        <span class="sp-cn-fixed"><?= htmlspecialchars(sp_control_prefix($r['program'])) ?></span>
                                        <input type="text" name="control_no" class="sp-cn-seq cn-wide" placeholder="CU88759938" required
                                               autocomplete="off" oninput="spPreview(this)"
                                               data-prefix="<?= htmlspecialchars(sp_control_prefix($r['program']), ENT_QUOTES) ?>">
                                    </div>
                                    <button type="submit" class="btn-update" style="background: var(--warning);"><i data-lucide="badge-check" style="width:14px;height:14px;"></i> Issue number</button>
                                    <p class="sp-cn-out">Will issue <b>&mdash;</b><span class="sp-cn-hint">The control number is printed on the Official Receipt.</span></p>
                                </form>

                            <?php elseif ($r['owner'] === 'you' && $r['at'] === 4): ?>
                                <?php // A VERDICT, like the Turnitin desk: release, or send back. Send
                                      // back reopens the deliverables she ticks (each with its own
                                      // optional note) — the group never pays again for it. ?>
                                <form method="POST" enctype="multipart/form-data" class="sp-rel" onsubmit="return spRelCheck(this)">
                                    <input type="hidden" name="action_type" value="upload_stat_result">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="form_id" value="<?= $r['form_id'] ?>">
                                    <div class="sp-verdict" role="radiogroup">
                                        <label><input type="radio" name="verdict" value="release" checked onchange="spVerdict(this)"><i data-lucide="package-check"></i> Release results</label>
                                        <label><input type="radio" name="verdict" value="revise" onchange="spVerdict(this)"><i data-lucide="rotate-ccw"></i> Needs revision</label>
                                    </div>

                                    <div class="sp-v-revise" hidden>
                                        <p class="sp-hint">Tick what the group has to fix. Each tick can carry its own note; the ones left blank get the main note. They do not pay again.</p>
                                        <?php foreach ($sp_deliv as $d_id => $d_name): ?>
                                            <div class="sp-tick">
                                                <label><input type="checkbox" name="items[]" value="<?= (int)$d_id ?>" onchange="this.closest('.sp-tick').classList.toggle('on', this.checked)"> <?= htmlspecialchars($d_name) ?></label>
                                                <input type="text" name="item_note[<?= (int)$d_id ?>]" placeholder="Note for this one (optional)">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <textarea name="release_note" class="sp-v-release-note" rows="2" placeholder="Note to the group (optional)"></textarea>
                                    <textarea name="revision_note" class="sp-v-revise-note" rows="3" placeholder="What needs revising, and why (required)" hidden></textarea>

                                    <div class="sp-act">
                                        <input type="file" name="result_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip" required>
                                        <button type="submit" class="btn-update sp-rel-go" style="background: var(--success);"><i data-lucide="package-check" style="width:14px;height:14px;"></i> <span>Release Results</span></button>
                                    </div>
                                    <p class="sp-hint sp-file-hint" hidden>Attaching the treated file is optional here.</p>
                                </form>

                            <?php elseif ($r['at'] === 5): ?>
                                <?php // Released is not sealed: a wrong file or a note worth rewording is
                                      // corrected here, and the group is told. ?>
                                <details class="sp-fix">
                                    <summary><i data-lucide="pencil"></i> Replace file or edit note</summary>
                                    <form method="POST" enctype="multipart/form-data" class="sp-rel">
                                        <input type="hidden" name="action_type" value="update_stat_result">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="form_id" value="<?= $r['form_id'] ?>">
                                        <textarea name="release_note" rows="2" placeholder="Note to the group (optional)"><?= htmlspecialchars($r['statistician_remarks'] ?? '') ?></textarea>
                                        <div class="sp-act">
                                            <input type="file" name="result_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip">
                                            <button type="submit" class="btn-update"><i data-lucide="save" style="width:14px;height:14px;"></i> Save changes</button>
                                        </div>
                                        <p class="sp-hint">Leave the file empty to keep the one already released.</p>
                                    </form>
                                </details>

                            <?php elseif ($r['owner'] === 'you'): ?>
                                <?php // Reviewing documents is the Checklist's job; sending her there
                                      // with the group already picked beats rebuilding it here. ?>
                                <a class="pay-btn-ghost" href="admin_module_dynamic.php?phase=stats&view=checklist&search=<?= urlencode($r['research_group_name']) ?>">
                                    <i data-lucide="list-checks"></i> Open the checklist for this group
                                </a>

                            <?php elseif ($r['at'] === 1): ?>
                                <div class="pl-acts">
                                    <form method="POST" style="display:contents;">
                                        <input type="hidden" name="action_type" value="nudge_payment">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="form_id" value="<?= $r['form_id'] ?>">
                                        <button type="submit" class="pay-btn-ghost"><i data-lucide="bell-ring"></i> Send a reminder</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>




            <?php if ($is_history_view): ?>
                <?php
                // The open month's entries, grouped by day inside it.
                $hist_groups = [];
                foreach ($payment_history as $h) $hist_groups[substr($h['created_at'], 0, 10)][] = $h;
                $hist_today = df_today();
                $hist_yday  = df_shift($hist_today, '-1 day');
                $hist_qs    = '&hist_q=' . urlencode($hist_q) . ($hist_act !== '' ? '&hist_act=' . $hist_act : '');
                $hist_pill  = function ($act) use ($hist_base, $hist_q) {
                    return $hist_base . '&hist_q=' . urlencode($hist_q) . ($act !== '' ? '&hist_act=' . $act : '');
                };
                ?>
                <?php
                /* HISTORY (2026-09-29, from the Claude Design concept). Same data, same month-at-a-
                   time rule (only the open month loads entries), same export. What is new: a header,
                   a summary with a decisions-per-month chart, a Reminders filter, and each open
                   month read day by day in two columns. */
                $lh_title = $hist_is_plag ? 'Manuscript history' : 'History';
                $lh_chart = array_reverse(array_slice($hist_count_months, 0, 6));   // oldest -> newest
                $lh_max   = max(1, ...array_map(fn($m) => (int)$m['n'], $lh_chart ?: [['n' => 1]]));
                $lh_sum   = function (array $m): string {   // "7 accepted, 2 reminders"
                    $bits = [];
                    if ((int)$m['ok'])  $bits[] = (int)$m['ok'] . ' moved forward';
                    if ((int)$m['no'])  $bits[] = (int)$m['no'] . ' sent back';
                    if ((int)$m['rem']) $bits[] = (int)$m['rem'] . ' reminder' . ((int)$m['rem'] === 1 ? '' : 's');
                    $other = (int)$m['n'] - (int)$m['ok'] - (int)$m['no'] - (int)$m['rem'];
                    if ($other > 0) $bits[] = $other . ' other';
                    return implode(', ', $bits);
                };
                ?>
                <section class="lg lh">
                    <header class="lh-head">
                        <div>
                            <span class="lh-eyebrow"><?= $hist_is_plag ? 'Plagiarism' : 'Statistics' ?></span>
                            <h1><?= $lh_title ?></h1>
                            <p><?= $hist_is_plag ? 'Every manuscript decision, by month.' : 'Every payment decision and reminder, by month.' ?></p>
                        </div>
                        <form method="GET" class="lh-tools">
                            <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">
                            <input type="hidden" name="view" value="<?= htmlspecialchars($hist_is_plag ? 'payments' : 'history') ?>">
                            <?php if ($hist_is_plag): ?><input type="hidden" name="arch" value="1"><?php endif; ?>
                            <?php if ($hist_act !== ''): ?><input type="hidden" name="hist_act" value="<?= $hist_act ?>"><?php endif; ?>
                            <label class="lh-search"><i data-lucide="search"></i>
                                <input type="search" name="hist_q" value="<?= htmlspecialchars($hist_q) ?>" placeholder="Group, title or control number"></label>
                            <?php if ($hist_q !== ''): ?><a class="lh-btn icon" href="<?= $hist_base ?>" title="Clear the search"><i data-lucide="x"></i></a><?php endif; ?>
                            <a class="lh-btn" href="<?= $hist_base ?>&export=csv<?= $hist_qs ?>" title="Export what is on screen"><i data-lucide="download"></i>Export</a>
                        </form>
                    </header>

                    <?php
                    // RECENT, beside the log (Statistics only). The hub's Statistics page used to carry
                    // a "Recent in Statistics" panel next to a History card; History took that panel's
                    // place, and Recent moved in here. Same rows as that panel — activity_logs, phase
                    // decided by sh_activity_phase() — but scoped to this viewer's institution.
                    $lh_recent = [];
                    $lh_phase  = $hist_is_plag ? 'plag' : 'stats';   // Plagiarism got the same panel on 2026-09-29
                    {
                        [$lr_sql, $lr_params] = aa_scope_sql($pdo, $role, (int)$_SESSION['user_id'], 'u');
                        $lr = $pdo->prepare("
                            SELECT al.user_id, al.title, al.description, al.status_type, u.research_group_name, up.item_id, pc.status AS plag_status,
                                   (DATE(al.created_at) = CURDATE()) is_today, (DATE(al.created_at) = CURDATE() - INTERVAL 1 DAY) is_yday,
                                   DATE_FORMAT(al.created_at, '%l:%i %p') hhmm, DATE_FORMAT(al.created_at, '%b %e') md
                            FROM activity_logs al
                            JOIN users u ON u.user_id = al.user_id
                            LEFT JOIN uploads up ON up.upload_id = al.upload_id
                            LEFT JOIN plagiarism_checks pc ON pc.user_id = al.user_id
                            WHERE $lr_sql
                            ORDER BY al.created_at DESC LIMIT 250");
                        $lr->execute($lr_params);
                        foreach ($lr->fetchAll(PDO::FETCH_ASSOC) as $a) {
                            if (sh_activity_phase($a['item_id'] !== null ? (int)$a['item_id'] : null, (string)$a['title'], (string)$a['description']) !== $lh_phase) continue;
                            $st = strtolower(trim((string)$a['status_type']));
                            $a['cls'] = in_array($st, ['approved', 'success'], true) ? 'success'
                                     : (in_array($st, ['revision requested', 'warning'], true) ? 'warning' : 'info');
                            $lh_recent[] = $a;
                            if (count($lh_recent) >= 8) break;
                        }
                    }
                    ?>
                    <div class="lh-grid">
                    <div class="lh-col">

                    <section class="lh-sum">
                        <div class="lh-big ok"><b><?= (int)$hist_stats['accepted'] ?></b><small>moved forward</small></div>
                        <div class="lh-big no"><b><?= (int)$hist_stats['rejected'] ?></b><small>sent back</small></div>
                        <?php if (!$hist_is_plag): ?><div class="lh-big rem"><b><?= (int)$hist_stats['reminders'] ?></b><small>reminder<?= (int)$hist_stats['reminders'] === 1 ? '' : 's' ?> sent</small></div><?php endif; ?>
                        <?php if ($lh_chart): ?>
                        <div class="lh-chart" aria-label="Decisions per month">
                            <small>Decisions per month</small>
                            <div class="lh-bars">
                                <?php foreach ($lh_chart as $m):
                                    $h = fn($v) => round((int)$v / $lh_max * 46); ?>
                                    <div class="lh-bar" title="<?= htmlspecialchars($lh_sum($m)) ?>">
                                        <b><?= (int)$m['n'] ?></b>
                                        <span class="lh-stack">
                                            <i class="rem" style="height:<?= $h($m['rem']) ?>px"></i>
                                            <i class="no" style="height:<?= $h($m['no']) ?>px"></i>
                                            <i class="ok" style="height:<?= max((int)$m['n'] ? 4 : 0, $h($m['ok'])) ?>px"></i>
                                        </span>
                                        <small><?= date('M', strtotime($m['ym'] . '-01')) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </section>

                    <?php // The pills FILTER the log (hist_act); their numbers are the unfiltered totals. ?>
                    <nav class="lh-pills" aria-label="Filter the <?= $hist_is_plag ? 'manuscript' : 'decision' ?> log">
                        <?php foreach ([['', 'All', $hist_stats['total']], ['ok', 'Moved forward', $hist_stats['accepted']],
                                        ['no', 'Sent back', $hist_stats['rejected']], ['rem', 'Reminders', $hist_stats['reminders']]] as [$ak, $al, $an]): ?>
                            <?php if ($ak === 'rem' && !$an && $hist_act !== 'rem') continue; ?>
                            <a class="<?= $hist_act === $ak ? 'on' : '' ?><?= $an ? '' : ' zero' ?>" href="<?= $hist_pill($ak) ?>"><?= $al ?><span><?= (int)$an ?></span></a>
                        <?php endforeach; ?>
                        <?php if ($hist_q !== ''): ?><span class="lh-matching">matching &ldquo;<?= htmlspecialchars($hist_q) ?>&rdquo;</span><?php endif; ?>
                    </nav>

                    <?php if (!$hist_months): ?>
                        <div class="nx">
                            <?php $quill_pose = $hist_q !== '' ? 'thinking' : 'sleeping'; include __DIR__ . '/components/quill_empty.php'; ?>
                            <h3><?= $hist_q !== '' ? 'Nothing matches that' : 'Nothing has been decided yet' ?></h3>
                            <p><?= $hist_q !== ''
                                ? 'Try a group name, a control number, or who decided.'
                                : 'Every accept, send-back and reminder from this desk lands here.' ?></p>
                        </div>
                    <?php else: ?>
                        <?php // ONE ROW PER MONTH, always — the page cannot grow past the number of
                              // months the office has been running. Only the open one loads entries. ?>
                        <div class="lg-months lh-months">
                            <?php foreach ($hist_months as $m):
                                $m_open = ($m['ym'] === $hist_ym);
                                $m_n    = max(1, (int)$m['n']);
                            ?>
                                <div class="lg-month<?= $m_open ? ' is-open' : '' ?>">
                                    <a class="lg-mhead" href="<?= $hist_base . $hist_qs ?>&hist_ym=<?= $m_open ? '' : urlencode($m['ym']) ?>">
                                        <i data-lucide="chevron-right" class="lg-caret"></i>
                                        <span class="lg-mname"><?= date('F Y', strtotime($m['ym'] . '-01')) ?></span>
                                        <span class="lh-mbar">
                                            <i class="ok"  style="flex-grow:<?= (int)$m['ok'] ?>"></i>
                                            <i class="no"  style="flex-grow:<?= (int)$m['no'] ?>"></i>
                                            <i class="rem" style="flex-grow:<?= (int)$m['rem'] ?>"></i>
                                            <i class="oth" style="flex-grow:<?= max(0, (int)$m['n'] - (int)$m['ok'] - (int)$m['no'] - (int)$m['rem']) ?>"></i>
                                        </span>
                                        <span class="lh-msum"><?= htmlspecialchars($lh_sum($m)) ?></span>
                                        <span class="lg-mn"><?= (int)$m['n'] ?></span>
                                    </a>

                                    <?php if ($m_open): ?>
                                        <div class="lg-body lh-body">
                                            <?php foreach ($hist_groups as $day => $entries):
                                                $d_ts = strtotime($day);
                                                $d_lab = $day === $hist_today ? 'Today' : ($day === $hist_yday ? 'Yesterday' : date('D j', $d_ts));
                                            ?>
                                                <div class="lh-day">
                                                    <div class="lh-when<?= in_array($d_lab, ['Today', 'Yesterday'], true) ? ' near' : '' ?>"><b><?= $d_lab ?></b><small><?= date('D, M j', $d_ts) ?></small></div>
                                                    <div class="lh-list">
                                                        <?php foreach ($entries as $h):
                                                            // Anything unrecognised renders AS ITSELF rather than
                                                            // silently reading as "Rejected" — that is how an audit log lies.
                                                            $act = $hist_actions[$h['action']]
                                                                ?? ['label' => ucfirst($h['action']), 'icon' => 'circle-dot', 'tone' => 'neutral'];
                                                            $tone = $h['action'] === 'nudged' ? 'rem' : $act['tone'];
                                                        ?>
                                                            <div class="lh-row <?= $tone ?>">
                                                                <span class="lh-ico"><i data-lucide="<?= $h['action'] === 'nudged' ? 'bell' : ($tone === 'ok' ? 'check' : $act['icon']) ?>"></i></span>
                                                                <div class="lh-main">
                                                                    <b><?= htmlspecialchars($h['research_group_name'] ?? 'Unknown group') ?></b>
                                                                    <span class="lh-meta">
                                                                        <span class="lh-chip <?= $tone ?>"><?= $act['label'] ?></span>
                                                                        <?php if (!empty($h['control_no'])): ?><span class="lh-cn"><?= htmlspecialchars($h['control_no']) ?></span><?php endif; ?>
                                                                        <?php if (isset($hist_doc_names[$h['item_id']])): ?><span class="lh-doc"><?= $hist_doc_names[$h['item_id']] ?></span><?php endif; ?>
                                                                        <span class="lh-by">by <?= htmlspecialchars($h['actor_name'] ?? $hist_actor_fallback) ?></span>
                                                                    </span>
                                                                    <?php if (!empty($h['remarks'])): ?><p class="lh-note"><?= htmlspecialchars($h['remarks']) ?></p><?php endif; ?>
                                                                </div>
                                                                <span class="lh-time"><?= date('g:i A', strtotime($h['created_at'])) ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>

                                            <?php if ($hist_total_pages > 1): ?>
                                                <div class="lg-pager">
                                                    <?php if ($hist_page > 1): ?>
                                                        <a class="lh-btn" href="<?= $hist_base . $hist_qs ?>&hist_ym=<?= urlencode($hist_ym) ?>&hist_page=<?= $hist_page - 1 ?>">&larr; Newer</a>
                                                    <?php endif; ?>
                                                    <span>Page <?= $hist_page ?> of <?= $hist_total_pages ?> in this month</span>
                                                    <?php if ($hist_page < $hist_total_pages): ?>
                                                        <a class="lh-btn" href="<?= $hist_base . $hist_qs ?>&hist_ym=<?= urlencode($hist_ym) ?>&hist_page=<?= $hist_page + 1 ?>">Older &rarr;</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    </div><!-- /lh-col -->

                    <aside class="lh-recent">
                        <h3>Recent in <?= $hist_is_plag ? 'Plagiarism' : 'Statistics' ?></h3>
                        <div class="lh-acts">
                            <?php foreach ($lh_recent as $a):
                                $when = $a['is_today'] ? trim($a['hhmm']) : ($a['is_yday'] ? 'Yesterday' : $a['md']); ?>
                                <?php // A row opens the group where it stands now: its Progress row, or
                                      // its plagiarism step with the group selected. ?>
                                <a class="lh-act" href="<?= $hist_is_plag
                                    ? 'admin_module_dynamic.php?phase=plag&view=' . pp_step((string)($a['plag_status'] ?? ''))['step'] . '&g=' . (int)$a['user_id']
                                    : 'admin_module_dynamic.php?phase=stats&view=pipeline&search=' . urlencode((string)$a['research_group_name']) ?>" title="Open this group">
                                    <span class="lh-act-ico <?= $a['cls'] ?>"><i data-lucide="<?= ['success' => 'check', 'warning' => 'rotate-ccw', 'info' => 'file-up'][$a['cls']] ?>"></i></span>
                                    <span class="lh-act-body">
                                        <b><?= htmlspecialchars((string)$a['title']) ?></b>
                                        <small><?= htmlspecialchars((string)$a['research_group_name']) ?></small>
                                    </span>
                                    <span class="lh-act-when"><?= $when ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$lh_recent): ?><p class="lh-act-none">Nothing has happened here yet.</p><?php endif; ?>
                        </div>
                    </aside>
                    </div><!-- /lh-grid -->
                </section>
            <?php endif; ?>

            <?php // ── RELEASE: let a group send its final manuscript ──────────────────────────
            // The defense itself happened in a room somewhere. Until someone here says it went
            // ahead, the group's Plagiarism card stays shut. One button per group, one flip.
            if ($phase === 'plag' && $plag_view === 'release'): ?>
                <?php
                // ── NOTIFY, 2026-09-29 redesign (.nt-*) — the Approvals screen's language ─────────
                // One big card for the group that defended longest ago, compact rows for the rest,
                // the not-yet-defended kept apart, and a Notified record that follows each group
                // into Plagiarism. The form, its fields and both confirm texts are unchanged.
                $nt_base = 'admin_module_dynamic.php?phase=plag&view=release';
                $nt_when = function (array $r): string {
                    $d = (int)round((strtotime(df_today()) - strtotime($r['defense_date'])) / 86400);
                    if ($d === 0) return 'today';
                    return $d > 0 ? ($d === 1 ? 'yesterday' : $d . ' days ago') : ($d === -1 ? 'tomorrow' : 'in ' . abs($d) . ' days');
                };
                $nt_meta = function (array $r): string {
                    $bits = [htmlspecialchars((string)$r['username'])];
                    if (!empty($r['program']))    $bits[] = htmlspecialchars((string)$r['program']);
                    if (!empty($r['department'])) $bits[] = aa_school_tag((string)$r['department']);
                    if (!empty($r['slot_no']))    $bits[] = 'Slot ' . (int)$r['slot_no'];
                    return implode(' &middot; ', $bits);
                };
                $nt_date = function (array $r, string $cls = ''): void {
                    $ts = strtotime($r['defense_date']); ?>
                    <span class="nt-date <?= $cls ?>"><small><?= date('D', $ts) ?></small><b><?= date('j', $ts) ?></b><small><?= date('M', $ts) ?></small></span>
                <?php };
                // THE action. Same POST as before; the early case still says so before it commits.
                $nt_form = function (array $r, string $cls, string $label): void {
                    $defended = !empty($r['has_defended']); ?>
                    <form method="POST" class="nt-form" onsubmit="return confirm(<?= $defended
                        ? "'Tell this group they may send their final manuscript? They are notified straight away.'"
                        : "'Their defense date (" . addslashes(date('M d, Y', strtotime($r['defense_date']))) . ") has NOT passed yet.\\n\\nOnly release them if the defense has actually been heard. Continue?'" ?>);">
                        <input type="hidden" name="action_type" value="release_manuscript">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="student_id" value="<?= (int)$r['user_id'] ?>">
                        <button type="submit" class="nt-btn <?= $cls ?>"><i data-lucide="send"></i> <?= $label ?></button>
                    </form>
                <?php };
                ?>
                <div class="nt">
                    <header class="nt-head">
                        <div>
                            <div class="nt-eyebrow">Final</div>
                            <h1>Notify to send manuscript</h1>
                            <p>The final defense happens face to face, so only the office can say it was heard.
                               Notifying opens the group&rsquo;s Plagiarism card and emails them &mdash; it cannot be taken back.</p>
                        </div>
                        <nav class="nt-steps" aria-label="Notify lists">
                            <a href="<?= $nt_base ?>" class="<?= $plag_arch ? '' : 'on' ?>">Waiting <b><?= $plag_release_waiting ?></b></a>
                            <a href="<?= $nt_base ?>&arch=1" class="<?= $plag_arch ? 'on' : '' ?>">Notified <b><?= $plag_release_done_count ?></b></a>
                        </nav>
                    </header>

                    <div class="nt-grid">
                    <div class="nt-main">
                    <?php if (!$plag_arch): ?>
                        <?php if (!$nt_ready && !$nt_soon): ?>
                            <div class="ws-empty">
                                <?php $quill_size = 74; $quill_pose = 'happy'; include __DIR__ . '/components/quill_empty.php'; ?>
                                <?php if ($plag_release_done_count > 0): ?>
                                    <h4>Everyone has been notified</h4>
                                    <p><?= $plag_release_done_count ?> <?= $plag_release_done_count === 1 ? 'group is' : 'groups are' ?>
                                       clear to send their final manuscript &mdash; follow them under
                                       <a href="<?= $nt_base ?>&arch=1">Notified</a>.</p>
                                <?php else: ?>
                                    <h4>No group has cleared its final defense yet</h4>
                                    <p>A group appears here once its final requirements are approved and its defense date is confirmed.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <section class="nt-sect">
                                <h2>Ready to notify <small>Their defense day has passed.</small></h2>
                                <?php if ($nt_ready): $h = $nt_ready[0]; ?>
                                    <article class="nt-feature">
                                        <div class="nt-feature-top">
                                            <?php $nt_date($h, 'light'); ?>
                                            <div class="nt-feature-id">
                                                <h3><?= htmlspecialchars((string)$h['research_group_name']) ?></h3>
                                                <p><?= $nt_meta($h) ?></p>
                                            </div>
                                            <span class="nt-age"><i></i>Defended <?= $nt_when($h) ?></span>
                                        </div>
                                        <ol class="nt-chain">
                                            <li class="done"><span class="nt-node"><i data-lucide="check"></i></span>
                                                <b>Defense day</b><small><?= date('M j', strtotime($h['defense_date'])) ?> &middot; face to face</small></li>
                                            <li class="now"><span class="nt-node"><span class="nt-dot"></span></span>
                                                <b>You notify</b><small>Opens Plagiarism, emails the group</small></li>
                                            <li><span class="nt-node"></span>
                                                <b>They send it</b><small>Arrives on the Manuscript desk</small></li>
                                        </ol>
                                        <div class="nt-feature-acts"><?php $nt_form($h, 'nt-btn-light', 'Notify &amp; unlock'); ?></div>
                                    </article>
                                    <?php if (count($nt_ready) > 1): ?>
                                        <div class="nt-also">Also ready <span><?= count($nt_ready) - 1 ?></span></div>
                                        <div class="nt-list">
                                            <?php foreach (array_slice($nt_ready, 1) as $r): ?>
                                                <div class="nt-row">
                                                    <?php $nt_date($r); ?>
                                                    <div class="nt-row-main">
                                                        <h4><?= htmlspecialchars((string)$r['research_group_name']) ?></h4>
                                                        <p><?= $nt_meta($r) ?></p>
                                                        <div class="nt-mini"><i class="done"></i><em class="on"></em><i class="now"></i><em></em><i></i>
                                                            <span>Defended <?= $nt_when($r) ?></span></div>
                                                    </div>
                                                    <div class="nt-row-act"><?php $nt_form($r, 'nt-btn-solid', 'Notify'); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="nt-clear">
                                        <i data-lucide="calendar-clock"></i>
                                        <div><b>Nobody has defended yet</b>
                                            <span><?= count($nt_soon) ?> <?= count($nt_soon) === 1 ? 'group defends' : 'groups defend' ?> soon &mdash; they move up here once the day passes.</span></div>
                                    </div>
                                <?php endif; ?>
                            </section>

                            <?php if ($nt_soon): ?>
                                <section class="nt-sect">
                                    <h2>Defends soon <small>Notify only if the defense was already heard.</small></h2>
                                    <div class="nt-list">
                                        <?php foreach ($nt_soon as $r): ?>
                                            <div class="nt-row soon">
                                                <?php $nt_date($r); ?>
                                                <div class="nt-row-main">
                                                    <h4><?= htmlspecialchars((string)$r['research_group_name']) ?></h4>
                                                    <p><?= $nt_meta($r) ?></p>
                                                    <div class="nt-mini"><i class="now"></i><em></em><i></i><em></em><i></i>
                                                        <span>Defends <?= $nt_when($r) ?></span></div>
                                                </div>
                                                <div class="nt-row-act"><?php $nt_form($r, 'nt-btn-outline', 'Notify early'); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php else: /* ── NOTIFIED: the record, following each group into Plagiarism ── */ ?>
                        <?php if (!$plag_release_done): ?>
                            <div class="ws-empty">
                                <?php $quill_size = 74; $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                                <h4>Nobody has been notified yet</h4>
                                <p>Groups land here the moment you notify them from the Waiting list.</p>
                            </div>
                        <?php else: ?>
                            <div class="nt-tools">
                                <label class="nt-search"><i data-lucide="search"></i>
                                    <input type="search" id="ntSearch" placeholder="Find a group, a student, a course" oninput="ntFilter()" autocomplete="off"></label>
                                <span class="nt-legend">Manuscript &rarr; Payment &rarr; Turnitin &rarr; Cleared</span>
                            </div>
                            <div class="nt-list" id="ntRecord">
                                <?php foreach ($plag_release_done as $r):
                                    $st = $r['nt'];
                                    $early = !empty($r['defense_date']) && strtotime($r['released_at']) < strtotime($r['defense_date']);
                                    $stale = $st['at'] === 0 && !$st['bad'] && (int)$r['released_days'] >= 7;
                                    $q = strtolower($r['research_group_name'] . ' ' . $r['username'] . ' ' . $r['program'] . ' ' . $r['student_number'] . ' ' . aa_school_tag((string)$r['department'])); ?>
                                    <div class="nt-row done<?= $st['bucket'] === 'done' ? ' is-cleared' : '' ?>" data-b="<?= $st['bucket'] ?>" data-q="<?= htmlspecialchars($q) ?>">
                                        <?php $nt_date($r, $st['bucket'] === 'done' ? 'ok' : ''); ?>
                                        <div class="nt-row-main">
                                            <h4><?= htmlspecialchars((string)$r['research_group_name']) ?></h4>
                                            <p><?= $nt_meta($r) ?></p>
                                            <p class="nt-by">Notified <?= date('M j, g:i A', strtotime($r['released_at'])) ?><?= $r['released_by_name'] ? ' by ' . htmlspecialchars($r['released_by_name']) : '' ?>
                                                <?php if ($early): ?><span class="nt-chip warn" title="Notified before the defense date. Allowed, but worth knowing."><i data-lucide="alert-triangle"></i>Before the defense</span><?php endif; ?>
                                                <?php if ($stale): ?><span class="nt-chip" title="Notified <?= (int)$r['released_days'] ?> days ago and nothing has arrived yet."><i data-lucide="hourglass"></i>No manuscript after <?= (int)$r['released_days'] ?> days</span><?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="nt-track">
                                            <div class="nt-mini">
                                                <?php for ($k = 0; $k < 4; $k++):
                                                    $cls = ($st['at'] > $k) ? 'done' : ($st['at'] === $k ? ($st['bad'] ? 'bad' : 'now') : '');
                                                    if ($k): ?><em class="<?= $st['at'] >= $k ? 'on' : '' ?>"></em><?php endif; ?><i class="<?= $cls ?>"></i><?php
                                                endfor; ?>
                                            </div>
                                            <span class="nt-stage<?= $st['bad'] ? ' bad' : ($st['bucket'] === 'done' ? ' ok' : '') ?>"><?= htmlspecialchars($st['label']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <p class="nt-nomatch" id="ntNoMatch" hidden>No notified group matches.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div><!-- /nt-main -->

                    <aside class="nt-side">
                        <?php if ($plag_arch): ?>
                            <section class="nt-panel">
                                <h3>Where they are now</h3>
                                <div class="nt-buckets">
                                    <?php foreach ([['wait', 'file-up', 'Manuscript', 'Not sent yet, or in review'], ['pay', 'banknote', 'Payment', 'At the finance office'],
                                                    ['scan', 'scan-search', 'Turnitin', 'Scan running, or revising'], ['done', 'shield-check', 'Cleared', 'Finished']] as [$bk, $ic, $lb, $sub]): ?>
                                        <button type="button" class="nt-bucket" data-b="<?= $bk ?>" onclick="ntBucket(this)" <?= $nt_stage_counts[$bk] ? '' : 'disabled' ?>>
                                            <span class="nt-bucket-ico <?= $bk ?>"><i data-lucide="<?= $ic ?>"></i></span>
                                            <span class="nt-bucket-t"><b><?= $lb ?></b><small><?= $sub ?></small></span>
                                            <span class="nt-bucket-n"><?= $nt_stage_counts[$bk] ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <p class="nt-hint">Tap one to show only those groups.</p>
                            </section>
                        <?php else: ?>
                            <section class="nt-panel">
                                <h3>What notifying does</h3>
                                <ol class="nt-how">
                                    <li><b>1</b><div><strong>The defense is heard</strong><small>Face to face &mdash; the system cannot see it</small></div></li>
                                    <li class="me"><b>2</b><div><strong>You notify</strong><small>Opens their Plagiarism card and emails the group</small></div></li>
                                    <li><b>3</b><div><strong>They send the manuscript</strong><small>It lands on the Manuscript desk</small></div></li>
                                </ol>
                            </section>
                            <section class="nt-panel">
                                <div class="nt-panel-head"><h3>Recently notified</h3><?php if ($plag_release_done_count): ?><a href="<?= $nt_base ?>&arch=1">See all</a><?php endif; ?></div>
                                <?php foreach ($nt_recent as $r): ?>
                                    <a class="nt-pub" href="<?= $nt_base ?>&arch=1">
                                        <?php $nt_date($r, 'sm' . ($r['nt']['bucket'] === 'done' ? ' ok' : '')); ?>
                                        <span class="nt-pub-body"><b><?= htmlspecialchars((string)$r['research_group_name']) ?></b>
                                            <small class="<?= $r['nt']['bad'] ? 'bad' : '' ?>"><?= htmlspecialchars($r['nt']['label']) ?></small></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$nt_recent): ?><p class="nt-none">Nobody notified yet.</p><?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </aside>
                    </div><!-- /nt-grid -->
                </div>
                <script>
                (function () {
                    var bucket = '';
                    window.ntBucket = function (btn) {
                        var b = btn.getAttribute('data-b');
                        bucket = (bucket === b) ? '' : b;
                        document.querySelectorAll('.nt-bucket').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-b') === bucket); });
                        ntFilter();
                    };
                    window.ntFilter = function () {
                        var s = document.getElementById('ntSearch'), q = s ? s.value.toLowerCase().trim() : '', shown = 0;
                        document.querySelectorAll('#ntRecord .nt-row').forEach(function (r) {
                            var ok = (!bucket || r.getAttribute('data-b') === bucket) && (!q || r.getAttribute('data-q').indexOf(q) !== -1);
                            r.hidden = !ok; if (ok) shown++;
                        });
                        var n = document.getElementById('ntNoMatch'); if (n) n.hidden = shown !== 0;
                    };
                })();
                </script>

            <?php // ── PLAGIARISM, THREE STEPS (2026-09-29, from the Claude Design concept) ──────────
            // Confirm manuscripts · Log payments · Release results. One screen per step: the groups
            // on the left, the selected one on the right, one decision at a time. Every form posts
            // the fields its handler already took (plag_receive / plag_return,
            // plag_acknowledge_payment / plag_payment_reject, plag_accept / plag_revise /
            // plag_replace). Rows come from pp_rows() — scoped — and pp_step() files them.
            elseif ($is_pz_view):
                $pz_all   = pp_rows($pdo, $fp_scope_sql, $fp_scope_params);
                $pz_need  = pp_step_counts($pz_all);
                $pz_rows  = array_values(array_filter($pz_all, fn($r) => pp_step((string)$r['status'])['step'] === $plag_view));
                $pz_ros   = ws_group_rosters($pdo, array_column($pz_rows, 'user_id'));
                $pz_csrf  = htmlspecialchars($_SESSION['csrf_token'] ?? '');
                $pz_today = df_today();
                $pz_when  = function (?string $ts) use ($pz_today): string {
                    if (!$ts) return '';
                    $t = strtotime($ts);
                    $d = substr($ts, 0, 10);
                    if ($d === $pz_today) return 'Today, ' . date('g:i A', $t);
                    if ($d === date('Y-m-d', strtotime($pz_today . ' -1 day'))) return 'Yesterday, ' . date('g:i A', $t);
                    return date('M j, g:i A', $t);
                };
                $pz_day   = fn(?string $ts): string => $ts ? date('M j', strtotime($ts)) : '';
                $pz_short = fn(string $name): string => preg_replace('/ Group$/', '', $name);
                $pz_seq   = function (array $r): string {   // the part of the control number the office typed
                    $cn = (string)$r['formatted_control_no']; $p = pp_control_prefix($r['program']);
                    return strpos($cn, $p) === 0 ? substr($cn, strlen($p)) : $cn;
                };
                // Verdict dates, for "Released this week" (the log carries them; there is no column).
                $pz_verdict = [];
                if ($plag_view === 'result' && $pz_rows) {
                    $vq = $pdo->query("SELECT student_id, MAX(created_at) AS at, TIMESTAMPDIFF(DAY, MAX(created_at), NOW()) AS d
                                       FROM plagiarism_payment_logs WHERE action IN ('cleared','revision') GROUP BY student_id");
                    foreach ($vq->fetchAll(PDO::FETCH_ASSOC) as $v) $pz_verdict[(int)$v['student_id']] = $v;
                }
                // Lists per step, each in its fair order.
                $pz_lists = [];
                $by_tone = function (array $tones) use ($pz_rows) {
                    return array_values(array_filter($pz_rows, fn($r) => in_array(pp_step((string)$r['status'])['tone'], $tones, true)));
                };
                if ($plag_view === 'manuscript') {
                    $need = $by_tone(['you']);
                    usort($need, fn($a, $b) => strcmp($a['docs'][4]['uploaded_at'] ?? '', $b['docs'][4]['uploaded_at'] ?? ''));
                    $pz_lists = [
                        ['Needs you', $need],
                        ['Sent back · waiting on group', $by_tone(['back'])],
                        ['Not sent yet', $by_tone(['them'])],
                    ];
                    $pz_title = 'Confirm manuscripts';
                    $pz_sub   = 'Make sure each group sent the right file before they pay.';
                } elseif ($plag_view === 'payment') {
                    $need = $by_tone(['you']);
                    $paid = function (array $r) { return max($r['docs'][41]['uploaded_at'] ?? '', $r['docs'][42]['uploaded_at'] ?? ''); };
                    usort($need, fn($a, $b) => strcmp($paid($a), $paid($b)));   // earliest payment first
                    foreach ($need as $qi => &$nr) { $nr['queue'] = $qi + 1; $nr['paid_at'] = $paid($nr); }
                    unset($nr);
                    $pz_lists = [
                        ['Documents in · needs you', $need],
                        ['Waiting on the group', $by_tone(['them'])],
                    ];
                    $lg = $pdo->prepare("SELECT COUNT(*) FROM plagiarism_payment_logs l JOIN users u ON u.user_id = l.student_id
                                         WHERE l.action = 'accepted' AND $fp_scope_sql");
                    $lg->execute($fp_scope_params);
                    $pz_logged = (int)$lg->fetchColumn();
                    $pz_title = 'Log payments';
                    $pz_sub   = 'Check the form and receipt, then enter the control number. Earliest payment first.';
                } else {
                    $need = $by_tone(['you']);
                    usort($need, fn($a, $b) => strcmp($a['registered_at'] ?? '', $b['registered_at'] ?? ''));
                    $released = $by_tone(['done', 'back']);
                    usort($released, fn($a, $b) => strcmp($pz_verdict[(int)$b['user_id']]['at'] ?? '', $pz_verdict[(int)$a['user_id']]['at'] ?? ''));
                    $week    = array_values(array_filter($released, fn($r) => isset($pz_verdict[(int)$r['user_id']]) && (int)$pz_verdict[(int)$r['user_id']]['d'] <= 7));
                    $earlier = array_values(array_filter($released, fn($r) => !in_array($r, $week, true)));
                    $pz_lists = [
                        ['Waiting on your check', $need],
                        ['Released this week', $week],
                        ['Released earlier', $earlier],
                    ];
                    $pz_title = 'Release results';
                    $pz_sub   = 'Run the check, attach the report, and tell the group where they stand.';
                }
                // Which group is open: the one asked for (?g=, from the hub's board), else the first
                // that needs the office, else the first at all.
                $pz_flat = array_merge(...array_map(fn($l) => $l[1], $pz_lists));
                $pz_ids  = array_map(fn($r) => (int)$r['user_id'], $pz_flat);
                $pz_sel  = (int)($_GET['g'] ?? 0);
                if (!in_array($pz_sel, $pz_ids, true)) $pz_sel = $pz_ids[0] ?? 0;
                $pz_steps = ['manuscript' => 'Manuscript', 'payment' => 'Payment', 'result' => 'Result'];
                $pz_order = array_keys($pz_steps);
            ?>
                <div class="pz">
                    <header class="pz-head">
                        <div>
                            <button type="button" class="pz-back" onclick="pzBack()"><i data-lucide="arrow-left"></i> Plagiarism</button>
                            <h1><?= $pz_title ?></h1>
                            <p><?= $pz_sub ?></p>
                        </div>
                        <nav class="pz-steps" aria-label="Plagiarism steps">
                            <?php $si = 0; foreach ($pz_steps as $sk => $sl): $si++; ?>
                                <a href="admin_module_dynamic.php?phase=plag&view=<?= $sk ?>" class="<?= $sk === $plag_view ? 'on' : '' ?>">
                                    <b><?= $si ?></b> <?= $sl ?> <span><?= (int)$pz_need[$sk] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </header>

                    <?php if (!$pz_flat): ?>
                        <div class="nx">
                            <?php include __DIR__ . '/components/quill_empty.php'; ?>
                            <h3><?= ['manuscript' => 'No manuscripts here', 'payment' => 'No payments here', 'result' => 'No scans here'][$plag_view] ?></h3>
                            <p><?= ['manuscript' => 'A group lands here the moment it is notified to send its final manuscript.',
                                    'payment'    => 'A group lands here once you confirm its manuscript.',
                                    'result'     => 'A group lands here once you log its payment.'][$plag_view] ?></p>
                            <?php if ($plag_view === 'manuscript'): ?><a class="nx-go" href="admin_module_dynamic.php?phase=plag&view=release">Open Notify</a><?php endif; ?>
                        </div>
                    <?php else: ?>
                    <div class="pz-grid">
                        <aside class="pz-list">
                            <label class="pz-search"><i data-lucide="search"></i>
                                <input type="search" placeholder="<?= $plag_view === 'manuscript' ? 'Search groups' : 'Search groups or control no.' ?>" oninput="pzFind(this.value)" autocomplete="off"></label>
                            <?php foreach ($pz_lists as $li => [$label, $list]):
                                if (!$list && $li > 0) continue;
                                $earlierFold = ($plag_view === 'result' && $li === 2); ?>
                                <?php $foldOpen = $earlierFold && in_array($pz_sel, array_map(fn($x) => (int)$x['user_id'], $list), true); ?>
                                <div class="pz-sect<?= $earlierFold ? ' is-fold' . ($foldOpen ? ' open' : '') : '' ?>">
                                    <div class="pz-sect-h"<?= $earlierFold ? ' onclick="this.parentNode.classList.toggle(\'open\')" role="button"' : '' ?>><span><?= $label ?></span><b><?= count($list) ?></b><?php if ($earlierFold): ?><i data-lucide="chevron-down" class="pz-fold-i"></i><?php endif; ?></div>
                                    <?php if (!$list): ?><p class="pz-none">Nothing is waiting on you.</p><?php endif; ?>
                                    <?php foreach ($list as $r):
                                        $st = pp_step((string)$r['status']); $uid = (int)$r['user_id'];
                                        if ($plag_view === 'manuscript') {
                                            $sub = $st['tone'] === 'back' ? 'Sent back · ' . $pz_day($r['since'])
                                                 : ($st['tone'] === 'them' ? 'Notified ' . $pz_day($r['released_at']) : ($r['program'] ?: ''));
                                            $right = $st['tone'] === 'you' ? ($pz_when($r['docs'][4]['uploaded_at'] ?? null)) : '';
                                            $right = preg_replace('/^Today, /', '', $right);
                                        } elseif ($plag_view === 'payment') {
                                            $sub = $st['tone'] === 'you' ? 'Paid ' . $pz_when($r['paid_at'] ?? null) : 'Hasn\'t uploaded yet · since ' . $pz_day($r['since']);
                                            $right = $st['tone'] === 'you' ? '#' . str_pad((string)$r['queue'], 2, '0', STR_PAD_LEFT) : '';
                                        } else {
                                            $sub = $st['tone'] === 'you' ? 'Logged ' . $pz_day($r['registered_at']) . ' · ' . $pz_seq($r) : ($r['program'] ?: '');
                                            $right = $st['tone'] === 'done' ? '<span class="pz-chip ok">Cleared</span>' : ($st['tone'] === 'back' ? '<span class="pz-chip bad">Needs revision</span>' : '');
                                        }
                                        $q = strtolower($r['research_group_name'] . ' ' . $r['username'] . ' ' . $r['program'] . ' ' . $r['formatted_control_no'] . ' ' . $r['student_number']); ?>
                                        <button type="button" class="pz-item t-<?= $st['tone'] ?><?= $uid === $pz_sel ? ' on' : '' ?>" data-g="<?= $uid ?>" data-q="<?= htmlspecialchars($q) ?>" onclick="pzPick(<?= $uid ?>)">
                                            <span class="pz-tile"><?= htmlspecialchars(strtoupper(mb_substr($pz_short((string)$r['research_group_name']) ?: 'G', 0, 1))) ?></span>
                                            <span class="pz-item-t"><b><?= htmlspecialchars($r['research_group_name']) ?></b><small><?= htmlspecialchars($sub) ?></small></span>
                                            <?php if ($right !== ''): ?><span class="pz-item-r"><?= $plag_view === 'result' ? $right : htmlspecialchars($right) ?></span><?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($plag_view === 'payment'): ?>
                                <a class="pz-logged" href="admin_module_dynamic.php?phase=plag&view=payments&arch=1"><span>Logged payments</span><b><?= $pz_logged ?></b><i data-lucide="chevron-right"></i></a>
                            <?php endif; ?>
                            <p class="pz-none pz-nomatch" hidden>No group matches.</p>
                        </aside>

                        <div class="pz-detail">
                        <?php foreach ($pz_flat as $r):
                            $st  = pp_step((string)$r['status']); $uid = (int)$r['user_id']; $tone = $st['tone'];
                            $nm  = (string)$r['research_group_name']; $short = $pz_short($nm);
                            // Many groups are filed under their research TITLE; in a sentence that reads
                            // as a paragraph, so a long name becomes "this group".
                            $who = mb_strlen($nm) <= 26 ? htmlspecialchars($nm) : 'this group';
                            $Who = mb_strlen($nm) <= 26 ? htmlspecialchars($nm) : 'This group';
                            $ros = $pz_ros[$uid] ?? [];
                            $again = $r['round'] > 1;
                            // The three-step bar: finished steps, the one they are on, the rest.
                            $at  = array_search($plag_view, $pz_order, true);
                            $allDone = ($tone === 'done');
                            if ($plag_view === 'manuscript')   { $mk = ['you' => 'Submitted', 'back' => 'Sent back'][$tone] ?? 'Notified';
                                                 $mv = $tone === 'you' ? ($pz_when($r['docs'][4]['uploaded_at'] ?? null) ?: 'Not yet') : $pz_day($tone === 'back' ? $r['since'] : $r['released_at']); }
                            elseif ($plag_view === 'payment')  { $mk = $tone === 'you' ? 'Queue' : 'Waiting since'; $mv = $tone === 'you' ? '#' . str_pad((string)$r['queue'], 2, '0', STR_PAD_LEFT) : $pz_day($r['since']); }
                            else                               { $mk = $tone === 'you' ? 'Waiting since' : 'Released'; $mv = $tone === 'you' ? $pz_day($r['registered_at']) : $pz_day($pz_verdict[$uid]['at'] ?? $r['since']); }
                        ?>
                            <section class="pz-pane" data-g="<?= $uid ?>" <?= $uid === $pz_sel ? '' : 'hidden' ?>>
                                <div class="pz-hero">
                                    <div class="pz-hero-top">
                                        <span class="pz-hero-tile"><?= htmlspecialchars(strtoupper(mb_substr($short ?: 'G', 0, 1))) ?></span>
                                        <div class="pz-hero-id">
                                            <h2><?= htmlspecialchars($nm) ?></h2>
                                            <?php if ($plag_view === 'result' && !empty($r['research_title']) && $r['research_title'] !== $nm): ?><p><?= htmlspecialchars($r['research_title']) ?></p><?php endif; ?>
                                            <div class="pz-chips">
                                                <?php if ($r['program']): ?><span><?= htmlspecialchars($r['program']) ?></span><?php endif; ?>
                                                <?php if ($r['student_number']): ?><span class="mono"><?= htmlspecialchars($r['student_number']) ?></span><?php endif; ?>
                                                <?php if ($ros): ?><span title="<?= htmlspecialchars(implode(', ', array_column($ros, 'username'))) ?>"><i data-lucide="users"></i><?= count($ros) ?> member<?= count($ros) === 1 ? '' : 's' ?></span><?php endif; ?>
                                                <?php if ($plag_view === 'result' && $r['formatted_control_no']): ?><span class="mono">Paid · <?= htmlspecialchars($pz_seq($r)) ?></span><?php endif; ?>
                                                <?php if ($again): ?><span class="warn">Round <?= (int)$r['round'] ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="pz-hero-meta"><small><?= $mk ?></small><b><?= htmlspecialchars($mv) ?></b>
                                            <?php if ($plag_view === 'payment' && $tone === 'you' && !empty($r['paid_at'])): ?><small>Paid <?= htmlspecialchars($pz_when($r['paid_at'])) ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                    <ol class="pz-bar">
                                        <?php foreach ($pz_order as $oi => $ok):
                                            $cls = ($allDone || $oi < $at) ? 'done' : ($oi === $at ? 'now' : ''); ?>
                                            <li class="<?= $cls ?>"><i></i><span><?= $oi + 1 ?> · <?= $pz_steps[$ok] ?><?= $cls === 'done' ? ' ✓' : ($cls === 'now' ? ' — now' : '') ?></span></li>
                                        <?php endforeach; ?>
                                    </ol>
                                </div>

                            <?php if ($plag_view === 'manuscript'): $m = $r['docs'][4] ?? null; ?>
                                <?php if ($m): ?>
                                    <div class="pz-card pz-file">
                                        <span class="pz-file-ico"><i data-lucide="file-text"></i></span>
                                        <div><b><?= htmlspecialchars($m['original_filename']) ?></b>
                                            <small><?= strtoupper(pathinfo($m['original_filename'], PATHINFO_EXTENSION)) ?> · uploaded <?= htmlspecialchars(lcfirst($pz_when($m['uploaded_at']))) ?></small></div>
                                        <a class="pz-ghost" href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank" rel="noopener"><i data-lucide="eye"></i> Open file</a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($tone === 'you'): ?>
                                    <form method="POST" class="pz-card pz-decide" onsubmit="return pzSubmit(this)">
                                        <input type="hidden" name="csrf_token" value="<?= $pz_csrf ?>">
                                        <input type="hidden" name="student_id" value="<?= $uid ?>">
                                        <div class="pz-q"><h3>Is this the right manuscript?</h3>
                                            <div class="pz-seg">
                                                <label><input type="radio" name="action_type" value="plag_receive" checked onchange="pzSeg(this)"> Yes, it's right</label>
                                                <label><input type="radio" name="action_type" value="plag_return" onchange="pzSeg(this)"> No, send back</label>
                                            </div></div>
                                        <div class="pz-yes">
                                            <div class="pz-cap">Quick look (optional)</div>
                                            <label class="pz-tick"><input type="checkbox"> Title matches the approved proposal</label>
                                            <label class="pz-tick"><input type="checkbox"> All chapters are included</label>
                                            <label class="pz-tick"><input type="checkbox"> Follows the required format</label>
                                        </div>
                                        <label class="pz-lab"><span class="pz-yes">Note for the group <em>(optional)</em></span><span class="pz-no">What is wrong with it? <em>(the group only sees this)</em></span>
                                            <input type="text" name="notes" placeholder="e.g. Looks good — see you at Finance."></label>
                                        <div class="pz-foot">
                                            <span class="pz-foot-ico"><i data-lucide="arrow-right"></i></span>
                                            <p class="pz-yes">Next for <?= $who ?>: <b>Payment.</b> They pay at the Finance Office<?= $again ? ' again (round ' . (int)$r['round'] . ')' : '' ?>, then upload their validated form and official receipt.</p>
                                            <p class="pz-no"><?= $Who ?> gets your note and sends the right file.</p>
                                            <button type="submit" class="pz-go"><span class="pz-yes">Confirm manuscript</span><span class="pz-no">Send back</span></button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="pz-card pz-wait"><i data-lucide="hourglass"></i>
                                        <div><b><?= $tone === 'back' ? 'Sent back — waiting on the group' : 'Waiting for their manuscript' ?></b>
                                            <?php if ($tone === 'back' && $r['plag_remarks']): ?><p>Your note: &ldquo;<?= htmlspecialchars($r['plag_remarks']) ?>&rdquo;</p><?php endif; ?>
                                            <p><?= $tone === 'back' ? 'It comes back here the moment they re-send it.' : 'Notified ' . htmlspecialchars($pz_day($r['released_at'])) . '. It lands here the moment they send it.' ?></p></div></div>
                                <?php endif; ?>

                            <?php elseif ($plag_view === 'payment'): ?>
                                <?php if ($tone === 'you'): ?>
                                    <form method="POST" class="pz-decide pz-pay" onsubmit="return pzSubmit(this)" oninput="pzPayReady(this)" onchange="pzPayReady(this)">
                                        <div class="pz-docs">
                                            <?php foreach ([41 => ['Validated form', 'RDC Form No. 13', 'file-text'], 42 => ['Official receipt', 'Finance Office', 'receipt']] as $di => [$dl, $ds, $dic]): $d = $r['docs'][$di] ?? null; ?>
                                                <div class="pz-card pz-doc">
                                                    <span class="pz-file-ico"><i data-lucide="<?= $dic ?>"></i></span>
                                                    <div><b><?= $dl ?></b><small><?= $ds ?><?= $d ? ' · ' . $pz_day($d['uploaded_at']) : ' · not uploaded' ?></small>
                                                        <?php if ($d): ?><a href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" rel="noopener"><i data-lucide="eye"></i> Open</a><?php endif; ?></div>
                                                    <label class="pz-good"><input type="checkbox" class="pz-ok" <?= $d ? '' : 'disabled' ?>> Looks good</label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="pz-card">
                                            <input type="hidden" name="csrf_token" value="<?= $pz_csrf ?>">
                                            <input type="hidden" name="student_id" value="<?= $uid ?>">
                                            <div class="pz-q"><h3>Are both documents in order?</h3>
                                                <div class="pz-seg">
                                                    <label><input type="radio" name="action_type" value="plag_acknowledge_payment" checked onchange="pzSeg(this)"> Yes, log it</label>
                                                    <label><input type="radio" name="action_type" value="plag_payment_reject" onchange="pzSeg(this)"> No, send back</label>
                                                </div></div>
                                            <div class="pz-yes">
                                                <label class="pz-lab">Control number on the receipt
                                                    <input type="text" name="control_no" class="mono pz-cn" placeholder="e.g. CVGT53" autocomplete="off" required
                                                           data-prefix="<?= htmlspecialchars(pp_control_prefix($r['program']), ENT_QUOTES) ?>"></label>
                                                <p class="pz-hint"><?= $again && $r['formatted_control_no']
                                                    ? 'Round ' . (int)$r['round'] . ': they keep <b class="mono">' . htmlspecialchars($r['formatted_control_no']) . '</b>. This receipt is logged as <b class="mono pz-cn-out">—</b>'
                                                    : 'Type it exactly as printed. The group will see <b class="mono pz-cn-out">—</b> on their tracker.' ?></p>
                                            </div>
                                            <div class="pz-no">
                                                <div class="pz-cap">Which one is wrong?</div>
                                                <div class="pz-radios">
                                                    <label><input type="radio" name="item_id" value="41" checked disabled> Validated form</label>
                                                    <label><input type="radio" name="item_id" value="42" disabled> Official receipt</label>
                                                </div>
                                                <label class="pz-lab">What is wrong with it? <em>(the group only sees this)</em>
                                                    <input type="text" name="remarks" placeholder="e.g. The receipt is cut off — upload the whole page." disabled></label>
                                            </div>
                                            <div class="pz-foot">
                                                <span class="pz-foot-ico"><i data-lucide="arrow-right"></i></span>
                                                <p class="pz-yes"><b><?= $Who ?> is done uploading.</b> They move to Result and wait while you run the check.</p>
                                                <p class="pz-no"><?= $Who ?> gets your note and uploads that document again.</p>
                                                <button type="submit" class="pz-go"><span class="pz-yes">Log payment</span><span class="pz-no">Send back</span></button>
                                            </div>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="pz-card pz-wait"><i data-lucide="hourglass"></i>
                                        <div><b>Waiting on the group</b>
                                            <p>Manuscript confirmed <?= htmlspecialchars($pz_day($r['since'])) ?>. They have not uploaded their validated form and receipt<?= $again ? ' for round ' . (int)$r['round'] : '' ?> yet.</p></div></div>
                                    <details class="pz-card pz-more">
                                        <summary><i data-lucide="hand-coins"></i> Paid at the office in cash? Log it anyway</summary>
                                        <form method="POST" class="pz-inline" onsubmit="return pzSubmit(this)">
                                            <input type="hidden" name="action_type" value="plag_acknowledge_payment">
                                            <input type="hidden" name="csrf_token" value="<?= $pz_csrf ?>">
                                            <input type="hidden" name="student_id" value="<?= $uid ?>">
                                            <input type="text" name="control_no" class="mono pz-cn" placeholder="Control number on the receipt" required autocomplete="off"
                                                   data-prefix="<?= htmlspecialchars(pp_control_prefix($r['program']), ENT_QUOTES) ?>">
                                            <button type="submit" class="pz-go">Log payment</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                            <?php else: $m = $r['docs'][4] ?? null; $rep = $r['docs'][40] ?? null; ?>
                                <?php if ($m): ?>
                                    <div class="pz-card pz-file">
                                        <span class="pz-file-ico"><i data-lucide="file-text"></i></span>
                                        <div><b>Confirmed manuscript</b>
                                            <small><?= strtoupper(pathinfo($m['original_filename'], PATHINFO_EXTENSION)) ?> · confirmed <?= htmlspecialchars($pz_day($m['uploaded_at'])) ?><?= $tone === 'you' ? ' — run this file through your check' : '' ?></small></div>
                                        <a class="pz-ghost" href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank" rel="noopener" download><i data-lucide="download"></i> Download</a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($tone === 'you'): ?>
                                    <form method="POST" enctype="multipart/form-data" class="pz-card pz-decide pz-res" onsubmit="return pzSubmit(this)">
                                        <input type="hidden" name="csrf_token" value="<?= $pz_csrf ?>">
                                        <input type="hidden" name="student_id" value="<?= $uid ?>">
                                        <input type="hidden" name="notes" value="">
                                        <h3>Record the result</h3>
                                        <div class="pz-res-row">
                                            <label class="pz-drop"><input type="file" name="report_file" accept=".pdf,.doc,.docx" onchange="pzFile(this)">
                                                <span class="pz-drop-ico"><i data-lucide="upload"></i></span>
                                                <span><b class="pz-drop-t">Attach the report</b><small>PDF or Word</small></span></label>
                                            <label class="pz-lab">Similarity score<span class="pz-pct"><input type="number" min="0" max="100" step="1" class="pz-sim" placeholder="0"><em>%</em></span></label>
                                            <label class="pz-lab">AI-writing score <em>(optional)</em><span class="pz-pct"><input type="number" min="0" max="100" step="1" class="pz-ai" placeholder="0"><em>%</em></span></label>
                                        </div>
                                        <div class="pz-q"><h4>Your verdict</h4>
                                            <div class="pz-seg ok">
                                                <label><input type="radio" name="action_type" value="plag_accept" checked onchange="pzSeg(this)"> Cleared</label>
                                                <label><input type="radio" name="action_type" value="plag_revise" onchange="pzSeg(this)"> Needs revision</label>
                                            </div></div>
                                        <label class="pz-lab">Note for the group <em>(optional)</em>
                                            <input type="text" class="pz-note" placeholder="e.g. Well done — no issues found."></label>
                                        <div class="pz-foot pz-foot-ok">
                                            <span class="pz-foot-ico"><i data-lucide="check"></i></span>
                                            <p class="pz-yes"><b><?= $Who ?> is cleared.</b> They get the report and your note right away.</p>
                                            <p class="pz-no"><b><?= $Who ?> must revise.</b> They re-send the manuscript and pay again for round <?= (int)$r['round'] + 1 ?>. The report is required.</p>
                                            <button type="submit" class="pz-go"><span class="pz-yes">Release result</span><span class="pz-no">Send back for revision</span></button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="pz-card pz-wait <?= $tone === 'done' ? 'is-ok' : 'is-bad' ?>"><i data-lucide="<?= $tone === 'done' ? 'shield-check' : 'rotate-ccw' ?>"></i>
                                        <div><b><?= $tone === 'done' ? 'Cleared' : 'Needs revision — waiting on the group' ?><?= isset($pz_verdict[$uid]) ? ' · ' . htmlspecialchars($pz_day($pz_verdict[$uid]['at'])) : '' ?></b>
                                            <?php if ($r['plag_remarks']): ?><p>Your note: &ldquo;<?= htmlspecialchars($r['plag_remarks']) ?>&rdquo;</p><?php endif; ?>
                                            <?php if ($tone === 'back'): ?><p>They re-send the manuscript and pay again for round <?= (int)$r['round'] ?>. It comes back to Confirm manuscripts.</p><?php endif; ?>
                                            <?php if ($rep): ?><a class="pz-ghost" href="<?= htmlspecialchars($rep['file_path']) ?>" target="_blank" rel="noopener"><i data-lucide="file-check"></i> Turnitin report</a><?php endif; ?>
                                        </div></div>
                                    <?php if ($tone === 'done'): ?>
                                        <details class="pz-card pz-more">
                                            <summary><i data-lucide="pencil"></i> Replace the Turnitin report</summary>
                                            <form method="POST" enctype="multipart/form-data" class="pz-inline">
                                                <input type="hidden" name="action_type" value="plag_replace">
                                                <input type="hidden" name="csrf_token" value="<?= $pz_csrf ?>">
                                                <input type="hidden" name="student_id" value="<?= $uid ?>">
                                                <input type="file" name="report_file" accept=".pdf,.doc,.docx" required>
                                                <input type="text" name="notes" placeholder="Note for the group (optional)">
                                                <button type="submit" class="pz-go">Replace report</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <script>
                (function () {
                    // One group at a time; switching is instant (every pane is already on the page)
                    // and the URL follows, so a refresh or a board link lands on the same group.
                    window.pzPick = function (g) {
                        document.querySelectorAll('.pz-item').forEach(function (b) { b.classList.toggle('on', +b.dataset.g === g); });
                        document.querySelectorAll('.pz-pane').forEach(function (p) { p.hidden = +p.dataset.g !== g; });
                        try { var u = new URL(location.href); u.searchParams.set('g', g); history.replaceState(null, '', u); } catch (e) {}
                        var d = document.querySelector('.pz-detail'); if (d && window.innerWidth < 900) d.scrollIntoView({ behavior: 'smooth' });
                    };
                    window.pzFind = function (q) {
                        q = q.toLowerCase().trim(); var n = 0;
                        document.querySelectorAll('.pz-item').forEach(function (b) { var ok = !q || b.dataset.q.indexOf(q) !== -1; b.hidden = !ok; if (ok) n++; });
                        document.querySelectorAll('.pz-sect').forEach(function (s) { s.classList.toggle('is-searching', !!q); });
                        var m = document.querySelector('.pz-nomatch'); if (m) m.hidden = n !== 0;
                    };
                    // Yes / No. The "no" half's fields are DISABLED while hidden so they neither submit
                    // nor block the form with a required field nobody can see — and vice versa.
                    window.pzSeg = function (radio) {
                        var f = radio.closest('form'), no = /return|reject|revise/.test(radio.value);
                        f.classList.toggle('is-no', no);
                        f.querySelectorAll('.pz-yes input, .pz-yes select').forEach(function (i) { i.disabled = no; });
                        f.querySelectorAll('.pz-no input, .pz-no select').forEach(function (i) { i.disabled = !no; });
                        var n = f.querySelector('input[name=notes]:not([type=hidden])');
                        if (n) { n.required = no; n.placeholder = no ? 'e.g. Wrong file — this is the proposal, not the final manuscript.' : 'e.g. Looks good — see you at Finance.'; }
                        var rep = f.querySelector('input[name=report_file]'); if (rep) rep.required = no;
                        if (f.classList.contains('pz-pay')) pzPayReady(f);
                    };
                    // Log payment waits for both "Looks good" ticks and a control number.
                    window.pzPayReady = function (f) {
                        var go = f.querySelector('.pz-go'); if (!go) return;
                        var cn = f.querySelector('.pz-cn'), out = f.querySelector('.pz-cn-out');
                        var raw = cn ? cn.value.replace(/[^A-Za-z0-9\-]/g, '').toUpperCase() : '';
                        if (out) out.textContent = raw ? cn.dataset.prefix + raw : '—';
                        if (f.classList.contains('is-no')) { go.disabled = false; return; }
                        var ticks = f.querySelectorAll('.pz-ok'), all = ticks.length > 0;
                        ticks.forEach(function (t) { if (!t.checked) all = false; });
                        go.disabled = !(all && raw);
                        go.title = go.disabled ? 'Tick "Looks good" on both documents and type the control number' : '';
                    };
                    window.pzFile = function (input) {
                        var t = input.closest('.pz-drop').querySelector('.pz-drop-t');
                        t.textContent = input.files && input.files[0] ? input.files[0].name : 'Attach the report';
                        input.closest('.pz-drop').classList.toggle('has', !!(input.files && input.files[0]));
                    };
                    // The scores have no column of their own: they travel in the note the group gets.
                    window.pzSubmit = function (f) {
                        if (f.classList.contains('pz-res')) {
                            var parts = [], sim = f.querySelector('.pz-sim').value, ai = f.querySelector('.pz-ai').value, note = f.querySelector('.pz-note').value.trim();
                            if (sim !== '') parts.push('Similarity: ' + sim + '%');
                            if (ai !== '') parts.push('AI-writing: ' + ai + '%');
                            f.querySelector('input[name=notes]').value = parts.join(' · ') + (parts.length && note ? ' — ' : '') + note;
                            if (f.classList.contains('is-no')) return confirm('Send this group back to revise? They re-send the manuscript and pay the scan fee AGAIN before the next scan.');
                        }
                        var go = f.querySelector('.pz-go'); if (go) { go.disabled = true; go.classList.add('is-busy'); }
                        return true;
                    };
                    window.pzBack = function () {
                        try { if (window.parent !== window && window.parent.shShow) { window.parent.shShow('hub-plag'); return; } } catch (e) {}
                        history.back();
                    };
                    document.querySelectorAll('.pz-pay').forEach(pzPayReady);
                })();
                </script>
            <?php endif; ?>

            <?php
            // ── WORKSPACE SETUP (shared by both views) ───────────────────────────────────
            // Defined unconditionally so #viewByGroup can rely on it.
            $prop_names = [];
            foreach ($checklist_items as $ci) $prop_names[$ci['item_id']] = $ci['item_name'];

            // Display order: $checklist_items is already sorted 17, 11, 12, 14, 13, 15, 16 for
            // proposal (the cascade children hang off 14), so reuse it instead of the raw
            // numeric $group_inbox_items order.
            $ws_order = [];
            foreach ($checklist_items as $ci) {
                if (in_array((int)$ci['item_id'], $group_inbox_items, true)) $ws_order[] = (int)$ci['item_id'];
            }
            // Proposal is the only phase with a cascade (14 clears 13/15/16) and the only one
            // with a rubric-gated item. Final (22-27) and the Stats checklist (30-35) are all
            // plain documents, so every one of their requirements is batch-approvable.
            $ws_cascade_items = ($phase === 'proposal') ? [13, 15, 16] : [];
            // Item 14's 22-question Form 008 rubric was retired: the office approves or sends
            // it back like any other document. Nothing is rubric-gated in this phase now.
            $ws_rubric_items  = [];
            $ws_rubric_label  = 'Form 008';
            // NOTE: $ws_rubric_items is now permanently empty — Form 008 was retired and no item
            // in any phase is rubric-gated. Every `in_array($w_item, $ws_rubric_items)` branch
            // below, $rubric_pending, the 'all+rubric' bulk mode and the open_form008 flag are
            // therefore unreachable. They are kept as ONE switch rather than being torn out of a
            // dozen places: set this back to [14] and the whole path returns. Do not read them as
            // live behaviour.
            // Never batch-approvable in any phase: rubric-gated, cascade children, or
            // plagiarism (which needs a Turnitin report through its own section).
            $ws_blocked_items = array_merge([4], $ws_cascade_items, $ws_rubric_items);
            // Anything still in flight is this reviewer's to decide — there is no role handoff.
            // 'Under Review' only survives on rows written before the chain was removed.
            $ws_active_states = ['Pending', 'Under Review'];
            $ws_approve_verb  = 'Approve';
            $ws_all_verb      = 'Approve all';

            // ── BY GROUP ─────────────────────────────────────────────────────────────────
            // Pick a group, then clear its whole checklist without leaving the screen.
            if ($use_group_inbox):
                // One segment per requirement, in checklist order: approved green, waiting amber,
                // sent back red, not handed in dashed, cascade items (cleared WITH item 14) faint.
                $ck_segs = function (array $grp) use ($ws_order, $ws_cascade_items): string {
                    $h = '<span class="ck-segs">';
                    foreach ($ws_order as $it) {
                        $st = $grp['items'][$it]['verification_status'] ?? null;
                        $c = $st === 'Approved' ? 'ok' : ($st === 'Revision Requested' ? 'back'
                            : ($st !== null ? 'wait' : (in_array($it, $ws_cascade_items ?? [], true) ? 'casc' : 'miss')));
                        $h .= '<i class="' . $c . '"></i>';
                    }
                    return $h . '</span>';
                };
            ?>
            <div id="viewByGroup">
                <?php if (empty($submissions_by_group)): ?>
                    <div class="nx">
                        <?php $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                        <h3>Nothing has been handed in yet</h3>
                        <p>A group appears here the moment it uploads its first requirement for this
                           phase. Nothing is missing &mdash; there is simply nothing to check.</p>
                    </div>

                <?php elseif ($use_group_workspace): ?>
                    <!-- ── GROUP TILES: pick a group, then work inside its own workspace ───────── -->
                    <div class="grp-tiles" id="grpTiles">
                        <div class="ck-cols" aria-hidden="true"><span>Group</span><span>Requirements</span><span>Waiting</span><span></span></div>
                        <?php foreach ($submissions_by_group as $sid => $grp):
                            $meta = $grp['meta'];
                            $g_pfp = $meta['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($meta['username']);
                            $t_total = count($ws_order);
                            $t_done = $grp['approved_count'];
                            $t_pct = $t_total > 0 ? round(($t_done / $t_total) * 100) : 0;
                            $t_circ = 125.66;   // 2 * pi * r, r = 20
                            $t_complete = ($t_done >= $t_total && $t_total > 0);
                            // One state per tile, tinting the whole card rather than a stripe bolted
                            // to its top edge: what's waiting on you > what's still out for fixes >
                            // finished > idle.
                            $t_state = $grp['action_count'] > 0 ? 'needs'
                                     : ($grp['revision_count'] > 0 ? 'revision' : ($t_complete ? 'done' : 'idle'));
                        ?>
<?php // A DIV, not a button: the tile now carries its own "View names" button and the roster
                        // panel that opens under it, and neither a nested <button> nor a <div> is legal
                        // inside a <button>. role/tabindex/keydown keep it operable from the keyboard. ?>
                            <div role="button" tabindex="0" class="grp-tile <?= $t_state ?>"
                                    data-group="<?= $sid ?>"
                                    data-action="<?= $grp['action_count'] ?>"
                                    data-approved="<?= $grp['approved_count'] ?>"
                                    data-revision="<?= $grp['revision_count'] ?>"
                                    onclick="openGroupWorkspace(<?= $sid ?>)"
                                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openGroupWorkspace(<?= $sid ?>);}">
                                <?php
                                // ROW, not card (2026-09-29 concept): who · where their checklist is ·
                                // how long it has waited · the next move. Outer div, its data-* and its
                                // onclick are unchanged — the status tabs filter on them.
                                $ck_n_members = max(1, count($ws_rosters[(int)$sid] ?? []));
                                $ck_days = !empty($grp['first_upload']) ? max(0, (int)floor((time() - (int)$grp['first_upload']) / 86400)) : null;
                                ?>
                                <span class="ck-g">
                                    <span class="ck-av"><?= htmlspecialchars(strtoupper(mb_substr($meta['research_group_name'] ?: 'G', 0, 1))) ?></span>
                                    <span class="ck-gid">
                                        <b><?= highlightSearchTerm($meta['research_group_name'], $search_query) ?></b>
                                        <small><?= htmlspecialchars($meta['program'] ?: $meta['department']) ?><?php if (!empty($meta['student_number'])): ?>, ID <?= highlightSearchTerm($meta['student_number'], $search_query) ?><?php endif; ?></small>
                                        <small><?= $ck_n_members ?> member<?= $ck_n_members === 1 ? '' : 's' ?> &middot; <?= highlightSearchTerm($meta['username'], $search_query) ?></small>
                                    </span>
                                </span>
                                <span class="ck-req">
                                    <?= $ck_segs($grp) ?>
                                    <small><b><?= $t_done ?> of <?= $t_total ?> approved</b><?php if ($grp['action_count'] > 0): ?>, <span class="wait"><?= $grp['action_count'] ?> waiting on review</span><?php endif; ?><?php if ($grp['revision_count'] > 0): ?>, <span class="back"><?= $grp['revision_count'] ?> sent back</span><?php endif; ?></small>
                                </span>
                                <span class="ck-wait">
                                    <?php if ($ws_oldest_uid === (int)$sid): ?>
                                        <span class="ck-chip hot"><i data-lucide="alarm-clock"></i>Longest waiting<?= $ck_days !== null ? ', ' . $ck_days . ' day' . ($ck_days === 1 ? '' : 's') : '' ?></span>
                                    <?php elseif ($grp['action_count'] > 0): ?>
                                        <span class="ck-chip warn"><i data-lucide="clock"></i>Waiting<?= $ck_days !== null ? ' ' . $ck_days . ' day' . ($ck_days === 1 ? '' : 's') : '' ?></span>
                                    <?php elseif ($t_complete): ?>
                                        <span class="ck-chip ok"><i data-lucide="check"></i>Complete</span>
                                    <?php elseif ($grp['revision_count'] > 0): ?>
                                        <span class="ck-chip back"><i data-lucide="undo-2"></i>With the group</span>
                                    <?php endif; ?>
                                    <?php if (!empty($grp['first_cleared'])): ?>
                                        <small>First cleared <?= date('M j, g:i A', strtotime($grp['first_cleared'])) ?></small>
                                    <?php elseif (!empty($grp['first_upload'])): ?>
                                        <small>First submitted <?= date('M j', (int)$grp['first_upload']) ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="ck-act">
                                    <?php if ($grp['action_count'] > 0 && $phase !== 'stats'): ?>
                                        <?php // Emails the focal person(s) of this group's course. Stops the click here:
                                              // the row itself opens the group. ?>
                                        <form method="POST" onclick="event.stopPropagation();">
                                            <input type="hidden" name="action_type" value="nudge_focal">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="group_id" value="<?= (int)$sid ?>">
                                            <button type="submit" class="ck-btn-outline" title="Email this course's focal person a reminder"><i data-lucide="bell"></i>Nudge focal</button>
                                        </form>
                                    <?php endif; ?>
                                    <span class="ck-go"><i data-lucide="chevron-right"></i></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <div class="ws-empty" id="grpTilesEmpty" style="display:none; grid-column:1/-1;">
                            <?php $quill_size = 74; include __DIR__ . '/components/quill_empty.php'; ?>
                            <h4>Nothing is waiting on you here</h4>
                            <p>No group has a submission in this state right now.</p>
                            <button type="button" class="btn btn-secondary" onclick="filterByStatus('all')">
                                <i data-lucide="layers" style="width:14px;height:14px;"></i> Show all submissions
                            </button>
                        </div>
                    </div>
                    <p class="ck-foot" id="ckFoot"></p>

                    <!-- ── ONE WORKSPACE PER GROUP: the whole checklist, reviewed in place ─────── -->
                    <?php foreach ($submissions_by_group as $sid => $grp):
                        $meta = $grp['meta'];
                        $g_pfp = $meta['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($meta['username']);
                        $w_total = count($ws_order);
                        $w_done = $grp['approved_count'];
                        $w_pct = $w_total > 0 ? round(($w_done / $w_total) * 100) : 0;

                        // Bucket every requirement so the reviewer's own work sits at the top and
                        // "not submitted yet" is visible instead of silently absent.
                        $buckets = ['action' => [], 'approved' => [], 'revision' => [], 'missing' => []];
                        foreach ($ws_order as $w_item) {
                            if (in_array($w_item, $ws_cascade_items, true)) continue;  // shown nested under 14
                            if (!isset($grp['items'][$w_item])) { $buckets['missing'][] = $w_item; continue; }
                            $w_status = $grp['items'][$w_item]['verification_status'];
                            if ($w_status === 'Approved') $buckets['approved'][] = $w_item;
                            elseif ($w_status === 'Revision Requested') $buckets['revision'][] = $w_item;
                            else $buckets['action'][] = $w_item;
                        }
                        $ws_sections = [
                            'action'   => ['Needs review', 'inbox'],
                            'revision' => ['Sent back for revision', 'alert-circle'],
                            'approved' => ['Approved', 'check-circle'],
                            'missing'  => ['Not submitted yet', 'circle-dashed'],
                        ];
                    ?>
                    <div class="grp-workspace" id="ws-g-<?= $sid ?>" data-group="<?= $sid ?>">

                        <!-- Batch form carries no visible markup: the checkboxes and the action bar
                             attach to it with the HTML5 form= attribute, so each card can still hold
                             its own inline "request revision" form without nesting forms. -->
                        <form method="POST" id="wsBulk-g-<?= $sid ?>">
                            <input type="hidden" name="action_type" value="bulk_verify">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="return_group" value="<?= $sid ?>">
                            <input type="hidden" name="open_form008" id="wsOpen008-g-<?= $sid ?>" value="">
                        </form>

                        <!-- Back sits on its own row above the profile card, not inside it: it is a
                             navigation control, not a fact about this group. -->
                        <div class="ws-topbar">
                            <button type="button" class="ws-back" onclick="closeGroupWorkspace()">
                                <i data-lucide="arrow-left"></i> Checklist
                            </button>
                            <span class="ws-crumb"><?= htmlspecialchars($meta['research_group_name']) ?></span>
                        </div>

                        <?php $w_names = ws_names_markup($ws_rosters[(int)$sid] ?? [], 'ws-names-' . (int)$sid); ?>
                        <div class="ws-head">
                            <span class="ck-av big"><?= htmlspecialchars(strtoupper(mb_substr($meta['research_group_name'] ?: 'G', 0, 1))) ?></span>
                            <div class="ws-head-id">
                                <?php /* .ws-head-name holds the group name and nothing else — wsConfirmBulk()
                                         reads it into the approve dialog. */ ?>
                                <span class="ws-head-name"><?= htmlspecialchars($meta['research_group_name']) ?></span>
                                <span class="ck-chips">
                                    <span><?= htmlspecialchars($meta['program'] ?: $meta['department']) ?></span>
                                    <?php if (!empty($meta['student_number'])): ?><span>ID <?= htmlspecialchars($meta['student_number']) ?></span><?php endif; ?>
                                    <?= $w_names['button'] ?>
                                    <?php if ($ws_oldest_uid === (int)$sid): ?><span class="hot">Longest waiting</span><?php endif; ?>
                                </span>
                                <?= $w_names['panel'] ?>
                                <span class="ck-since">
                                    <?php if (!empty($grp['first_cleared'])): ?>First cleared <?= date('M j, Y \a\t g:i A', strtotime($grp['first_cleared'])) ?>
                                    <?php elseif (!empty($grp['first_upload'])): ?>First submitted <?= date('M j, Y', (int)$grp['first_upload']) ?>, waiting <?= ws_ago((int)$grp['first_upload'], '') ?><?php endif; ?>
                                </span>
                            </div>
                            <div class="ck-progcard">
                                <div class="ck-big"><b><?= $w_done ?></b> of <?= $w_total ?> approved</div>
                                <?= $ck_segs($grp) ?>
                                <small><?php
                                    $ck_bits = [];
                                    if ($buckets['action'])   $ck_bits[] = count($buckets['action']) . ' to review';
                                    if ($buckets['revision']) $ck_bits[] = count($buckets['revision']) . ' sent back';
                                    if ($buckets['missing'])  $ck_bits[] = count($buckets['missing']) . ' not submitted';
                                    echo $ck_bits ? implode(', ', $ck_bits) : 'Nothing outstanding';
                                ?></small>
                            </div>
                        </div>
                        <?php // ── DEFENSE APPLICATION ────────────────────────────────────────────
                        // Not a checklist requirement and deliberately not counted in the progress
                        // above — the group submits it in the Schedule sheet. It sits here so the
                        // office can actually open it and decide on it, which until now it could
                        // not: this screen never showed it and the scheduling screen was view-only.
                        // Reviewing it is OPTIONAL and never blocks the booking.
                        $a_doc = $ws_app_docs[$sid] ?? null;
                        if ($a_doc):
                            $a_status = $a_doc['verification_status'];
                            $a_pill = 'pending';
                            if ($a_status === 'Approved') $a_pill = 'approved';
                            elseif ($a_status === 'Under Review') $a_pill = 'review';
                            elseif ($a_status === 'Revision Requested') $a_pill = 'revision';
                            $a_uid = (int)$a_doc['upload_id'];
                            $a_json = htmlspecialchars(json_encode($a_doc), ENT_QUOTES);
                            $a_attr = htmlspecialchars($ws_app_name, ENT_QUOTES);
                        ?>
                        <div class="ws-section">
                            <div class="ws-section-head">
                                <i data-lucide="calendar-check" style="width:14px;height:14px;color:var(--muted);"></i>
                                <span class="ws-section-title">Defense application</span>
                                <span class="ws-section-count">1</span>
                                <span class="ws-section-rule"></span>
                            </div>
                            <div class="ws-grid">
                                <div class="ws-card <?= $a_pill ?>" data-status="<?= htmlspecialchars(strtolower($a_status)) ?>"
                                     data-group="<?= $sid ?>" data-upload="<?= $a_uid ?>" id="wscard-a-<?= $a_uid ?>">
                                    <div class="ws-card-top">
                                        <span class="ws-card-icon"><i data-lucide="<?= ws_status_icon($a_status) ?>"></i></span>
                                        <span class="ws-card-name"><?= htmlspecialchars($ws_app_name) ?></span>
                                        <span class="status-pill <?= $a_pill ?>"><?= htmlspecialchars($a_status) ?></span>
                                    </div>

                                    <div class="ws-card-meta">
                                        <button type="button" class="grp-req-file" title="<?= htmlspecialchars($a_doc['original_filename']) ?>"
                                                onclick="openDocumentModal(<?= $a_json ?>, '<?= $a_attr ?>', <?= $ws_app_item ?>, <?= $sid ?>)">
                                            <i data-lucide="file-text" style="width:13px;height:13px;flex-shrink:0;"></i>
                                            <span class="grp-req-fname"><?= htmlspecialchars($a_doc['original_filename'] ?: 'Document') ?></span>
                                        </button>
                                        <span><?= date('M d, Y h:i A', strtotime($a_doc['uploaded_at'])) ?></span>
                                    </div>

                                    <?php if (!empty($a_doc['remarks'])): ?>
                                        <div class="ws-card-meta" style="font-style:italic;">&ldquo;<?= htmlspecialchars($a_doc['remarks']) ?>&rdquo;</div>
                                    <?php endif; ?>

                                    <span class="ws-next">
                                        <i data-lucide="corner-down-right"></i>
                                        Submitted with their defense request &mdash; not part of the checklist count.
                                    </span>

                                    <div class="ws-card-actions">
                                        <button type="button" class="btn btn-primary"
                                                onclick="openDocumentModal(<?= $a_json ?>, '<?= $a_attr ?>', <?= $ws_app_item ?>, <?= $sid ?>)">
                                            <i data-lucide="eye" style="width:14px;height:14px;"></i> Open &amp; decide
                                        </button>
                                        <button type="button" class="btn ws-btn-revise" onclick="wsToggleReason('a-<?= $a_uid ?>')">
                                            <i data-lucide="undo-2" style="width:14px;height:14px;"></i> Request revision
                                        </button>
                                    </div>

                                    <form method="POST" class="ws-reason" id="wsreason-a-<?= $a_uid ?>">
                                        <input type="hidden" name="action_type" value="verify_upload">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="verification_status" value="Revision Requested">
                                        <input type="hidden" name="upload_id" value="<?= $a_uid ?>">
                                        <input type="hidden" name="student_user_id" value="<?= $sid ?>">
                                        <input type="hidden" name="req_name" value="<?= $a_attr ?>">
                                        <input type="hidden" name="target_item_id" value="<?= $ws_app_item ?>">
                                        <input type="hidden" name="return_group" value="<?= $sid ?>">
                                        <label for="wsreasontext-a-<?= $a_uid ?>">Why is this being sent back?</label>
                                        <textarea id="wsreasontext-a-<?= $a_uid ?>" name="remarks" required
                                                  placeholder="e.g. The adviser's signature is missing — please re-upload the signed copy."></textarea>
                                        <div class="ws-reason-row">
                                            <button type="button" class="btn btn-secondary" onclick="wsToggleReason('a-<?= $a_uid ?>')">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Send back to student</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php foreach ($ws_sections as $b_key => $b_def):
                            if (empty($buckets[$b_key])) continue; ?>
                            <div class="ws-section <?= $b_key ?>">
                                <div class="ws-section-head">
                                    <i data-lucide="<?= $b_def[1] ?>" style="width:14px;height:14px;color:var(--muted);"></i>
                                    <span class="ws-section-title"><?= htmlspecialchars($b_def[0]) ?></span>
                                    <span class="ws-section-count"><?= count($buckets[$b_key]) ?></span>
                                    <span class="ws-section-rule"></span>
                                </div>
                                <div class="ws-grid">
                                <?php foreach ($buckets[$b_key] as $w_item):
                                    $ci_name = $prop_names[$w_item] ?? ('Requirement ' . $w_item);

                                    /* ---- Not submitted yet: a ghost card, so 4-of-6 is readable ---- */
                                    if ($b_key === 'missing'): ?>
                                        <div class="ws-card ghost" data-status="missing" data-group="<?= $sid ?>">
                                            <div class="ws-card-top">
                                                <span class="ws-card-icon"><i data-lucide="circle-dashed"></i></span>
                                                <span class="ws-card-name"><?= htmlspecialchars($ci_name) ?></span>
                                                <span class="status-pill pending">Not submitted</span>
                                            </div>
                                            <span class="ws-next"><i data-lucide="hourglass"></i> Waiting for the group to upload this.</span>
                                            <form method="POST" class="ck-remind">
                                                <input type="hidden" name="action_type" value="remind_upload">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="group_id" value="<?= (int)$sid ?>">
                                                <input type="hidden" name="item_id" value="<?= (int)$w_item ?>">
                                                <input type="hidden" name="return_group" value="<?= (int)$sid ?>">
                                                <button type="submit" class="ck-btn-outline">Remind group</button>
                                            </form>
                                            <?php if (in_array($w_item, $ws_rubric_items, true) && $ws_cascade_items): // this item owns the cascade ?>
                                                <div class="ws-cascade">
                                                    <span class="ws-next"><i data-lucide="git-merge"></i> These clear with it:</span>
                                                    <?php foreach ($ws_cascade_items as $c_item):
                                                        $c_sub = $grp['items'][$c_item] ?? null;
                                                        $c_status = $c_sub ? $c_sub['verification_status'] : 'Not submitted';
                                                    ?>
                                                        <div class="ws-cascade-row">
                                                            <span class="ws-cascade-name"><?= htmlspecialchars($prop_names[$c_item] ?? ('Item ' . $c_item)) ?></span>
                                                            <span class="status-pill <?= $c_status === 'Approved' ? 'approved' : 'pending' ?>"><?= htmlspecialchars($c_status) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php continue; endif;

                                    $sub = $grp['items'][$w_item];
                                    $uid = (int)$sub['upload_id'];
                                    $w_status = $sub['verification_status'];
                                    $pill_class = 'pending';
                                    if ($w_status === 'Approved') $pill_class = 'approved';
                                    elseif ($w_status === 'Under Review') $pill_class = 'review';
                                    elseif ($w_status === 'Revision Requested') $pill_class = 'revision';

                                    $is_actionable = in_array($w_status, $ws_active_states, true);
                                    // Rubric-gated items, cascade children and plagiarism can never be
                                    // cleared by a checkbox — see $ws_blocked_items.
                                    $is_selectable = $is_actionable && !in_array($w_item, $ws_blocked_items, true);
                                    $w_hist = $uploads_history[$w_item][$sid] ?? [];
                                    $sub_json = htmlspecialchars(json_encode($sub), ENT_QUOTES);
                                    $name_attr = htmlspecialchars($ci_name, ENT_QUOTES);
                                ?>
                                    <?php
                                    // Who signed this off. ONE signature: requirement approval is
                                    // single-stage by design, and the focal -> coordinator ->
                                    // secretary chain governs the DATE, not each document. What
                                    // varies is the CAPACITY it was signed in, and that is the
                                    // part worth showing.
                                    $sg_signed = $w_status === 'Approved';
                                    $sg_who    = $sub['verified_name'] ?? null;
                                    $sg_role   = $sub['verified_role'] ?? null;
                                    $sg_short  = $sg_role === 'Focal Person' ? 'Focal Person'
                                               : ($sg_role === 'Research Coordinator' ? 'Coordinator'
                                               : ($sg_role === 'Research Director' ? 'Office' : $sg_role));
                                    ?>
                                    <div class="ws-card ws-card-doc <?= $pill_class ?>" data-status="<?= htmlspecialchars(strtolower($w_status)) ?>"
                                         data-group="<?= $sid ?>" data-upload="<?= $uid ?>" id="wscard-g-<?= $uid ?>">

                                        <?php /* The signature, as one corner chip: the signer's own face with a tick on
                                                 it. It replaces both the generic badge and the three-line
                                                 "VERIFIED · FOCAL PERSON · 24 SEP 2026" block that used to sit inside the
                                                 card — a whole paragraph to say a thing a face says instantly. The name
                                                 is not lost: it is in the title for a mouse and in .ws-sr for a screen
                                                 reader and for Ctrl+F. */ ?>
                                        <div class="ws-card-top">
                                            <?php if ($sg_signed && $sg_who): ?>
                                                <span class="ws-card-icon ws-signer"
                                                      title="<?= htmlspecialchars($sg_who . ' · ' . $sg_short . (!empty($sub['verified_at']) ? ' · ' . date('j M Y', strtotime($sub['verified_at'])) : '')) ?>">
                                                    <img src="<?= htmlspecialchars($sub['verified_pfp'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($sg_who)) ?>" alt=""
                                                         onerror="this.onerror=null;this.src='https://api.dicebear.com/9.x/bottts/svg?seed=<?= urlencode($sg_who) ?>';">
                                                    <i data-lucide="check"></i>
                                                    <span class="ws-sr">Verified by <?= htmlspecialchars($sg_who) ?>, <?= htmlspecialchars($sg_short) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="ws-card-icon<?= $sg_signed ? ' ws-signer unknown' : '' ?>"
                                                      <?= $sg_signed ? 'title="Signed before this system recorded who signed"' : '' ?>>
                                                    <i data-lucide="<?= $sg_signed ? 'help-circle' : ws_status_icon($w_status) ?>"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php /* .ws-card-name must contain the requirement name and NOTHING else —
                                                     wsConfirmBulk() reads its textContent straight into the irreversible
                                                     "Approve N requirements" dialog. Hence the wrapper: the sub-line is
                                                     its sibling, never its child. */ ?>
                                            <span class="ws-card-id">
                                                <span class="ws-card-name"><?= htmlspecialchars($ci_name) ?></span>
                                                <span class="ws-card-sub" title="Uploaded <?= date('M d, Y h:i A', strtotime($sub['uploaded_at'])) ?>">
                                                    <span class="ws-fname"><?= htmlspecialchars($sub['original_filename'] ?: 'Document') ?></span>
                                                    <?php /* Relative, from a DB-computed epoch. The absolute stamp is the
                                                             school's wall clock and stays in the title, where it is
                                                             available without being read a hundred times a day. */ ?>
                                                    <span class="ws-age">&middot; <?= ws_ago(isset($sub['uploaded_ts']) ? (int)$sub['uploaded_ts'] : $sub['uploaded_at']) ?></span>
                                                    <?php if ($is_actionable): ?><span class="ws-unseen" data-unseen="<?= $uid ?>" title="You haven't opened this file yet"></span><?php endif; ?>
                                                </span>
                                            </span>
                                            <span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($w_status) ?></span>
                                        </div>

                                        <?php /* Batch selection: still the whole strip as the hit area, but quieter. It
                                                 is the secondary path and it was the loudest thing on the card. */ ?>
                                        <?php if ($is_selectable): ?>
                                            <label class="ws-check">
                                                <input type="checkbox" name="upload_ids[]" value="<?= $uid ?>"
                                                       form="wsBulk-g-<?= $sid ?>" data-group="<?= $sid ?>"
                                                       onchange="wsSyncSelection('g-<?= $sid ?>')">
                                                <span class="ws-check-box"></span>
                                                <span class="ws-check-label">Add to batch</span>
                                            </label>
                                        <?php endif; ?>

                                        <?php /* ONE line, never a pile. A real remark from a previous decision always
                                                 beats the generic "what to do next" hint — if somebody wrote something,
                                                 that is the thing to read. */ ?>
                                        <?php if (!empty($sub['remarks'])): ?>
                                            <div class="ws-line said">
                                                <i data-lucide="message-square-quote"></i>
                                                <span>&ldquo;<?= htmlspecialchars($sub['remarks']) ?>&rdquo;</span>
                                            </div>
                                        <?php elseif (in_array($w_item, $ws_rubric_items, true) && !empty($sub['form_008_decision'])): ?>
                                            <div class="ws-line">
                                                <i data-lucide="clipboard-check"></i>
                                                <span><?= $ws_rubric_label ?>: <?= (int)$sub['form_008_score'] ?>/22 &mdash; <?= htmlspecialchars($sub['form_008_decision']) ?></span>
                                            </div>
                                        <?php elseif ($is_actionable): ?>
                                            <div class="ws-line">
                                                <i data-lucide="corner-down-right"></i>
                                                <span><?= in_array($w_item, $ws_rubric_items, true)
                                                    ? 'Needs the ' . $ws_rubric_label . ' rubric &mdash; open it to decide.'
                                                    : 'Check the file is the right one, then approve.' ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php // Item 14 owns the cascade. Its children are listed here as nested rows rather
                                        // than three separate chores, so the reviewer can see all 7 requirements in one
                                        // place and understand why 3 of them have no buttons.
                                        if (in_array($w_item, $ws_rubric_items, true) && $ws_cascade_items): ?>
                                            <div class="ws-cascade">
                                                <span class="ws-next"><i data-lucide="git-merge"></i> Approving this clears these automatically:</span>
                                                <?php foreach ($ws_cascade_items as $c_item):
                                                    $c_name = $prop_names[$c_item] ?? ('Item ' . $c_item);
                                                    $c_sub = $grp['items'][$c_item] ?? null;
                                                    $c_status = $c_sub ? $c_sub['verification_status'] : 'Not submitted';
                                                    $c_pill = 'pending';
                                                    if ($c_status === 'Approved') $c_pill = 'approved';
                                                    elseif ($c_status === 'Under Review') $c_pill = 'review';
                                                    elseif ($c_status === 'Revision Requested') $c_pill = 'revision';
                                                ?>
                                                    <div class="ws-cascade-row" data-status="<?= htmlspecialchars(strtolower($c_status)) ?>" data-group="<?= $sid ?>">
                                                        <span class="ws-cascade-name"><?= htmlspecialchars($c_name) ?></span>
                                                        <span class="status-pill <?= $c_pill ?>"><?= htmlspecialchars($c_status) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="ws-card-actions">
                                            <button type="button" class="btn btn-primary" id="revbtn-<?= $w_item ?>-<?= $sid ?>"
                                                    onclick="openDocumentModal(<?= $sub_json ?>, '<?= $name_attr ?>', <?= $w_item ?>, <?= $sid ?>)">
                                                <i data-lucide="eye" style="width:14px;height:14px;"></i>
                                                <?= $is_actionable ? 'Open &amp; decide' : 'View document' ?>
                                            </button>
                                            <?php // Inline revision keeps the reason attached to this exact requirement.
                                            // Rubric-gated items are excluded: a rubric-less write would wipe them.
                                            if ($is_actionable && !in_array($w_item, $ws_rubric_items, true)): ?>
                                                <button type="button" class="btn ws-btn-revise" onclick="wsToggleReason('g-<?= $uid ?>')">
                                                    <i data-lucide="undo-2" style="width:14px;height:14px;"></i> Request revision
                                                </button>
                                            <?php endif; ?>
                                            <?php /* History is opened about once a week, so it is an icon on this row
                                                     rather than the labelled button and whole row of its own it used to
                                                     own. The panel still drops in underneath. */ ?>
                                            <?php if (count($w_hist) > 1): ?>
                                                <button type="button" class="btn ws-hist-btn" aria-expanded="false"
                                                        title="<?= count($w_hist) - 1 ?> earlier version<?= count($w_hist) === 2 ? '' : 's' ?> of this file"
                                                        onclick="toggleHistory('wshist-<?= $w_item ?>-<?= $sid ?>', this)">
                                                    <i data-lucide="history" style="width:15px;height:15px;"></i>
                                                    <span class="ws-sr"><?= count($w_hist) - 1 ?> earlier versions</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (count($w_hist) > 1): ?>
                                            <div id="wshist-<?= $w_item ?>-<?= $sid ?>" class="history-content-box" style="display:none;padding:9px;background:var(--surface-2);border:1px solid var(--line);border-radius:8px;font-size:11px;max-height:150px;overflow-y:auto;">
                                                <?php for ($i = 1; $i < count($w_hist); $i++):
                                                    $prev = $w_hist[$i];
                                                    $p_pill = 'pending';
                                                    if ($prev['verification_status'] === 'Approved') $p_pill = 'approved';
                                                    elseif ($prev['verification_status'] === 'Under Review') $p_pill = 'review';
                                                    elseif ($prev['verification_status'] === 'Revision Requested') $p_pill = 'revision';
                                                ?>
                                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding-bottom:6px;margin-bottom:6px;border-bottom:1px dashed var(--line);">
                                                        <div style="flex:1;min-width:0;">
                                                            <button type="button" class="file-link" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:11px;padding:0;text-align:left;color:var(--info);"
                                                                    onclick="openDocumentModal(<?= htmlspecialchars(json_encode($prev), ENT_QUOTES) ?>, '<?= $name_attr ?>', <?= $w_item ?>, <?= $sid ?>)">
                                                                <i data-lucide="file" style="width:11px;height:11px;vertical-align:middle;margin-right:3px;"></i><?= htmlspecialchars($prev['original_filename']) ?>
                                                            </button>
                                                            <div style="font-size:9.5px;color:var(--muted);margin-top:2px;"><?= ws_ago(isset($prev['uploaded_ts']) ? (int)$prev['uploaded_ts'] : $prev['uploaded_at']) ?></div>
                                                        </div>
                                                        <span class="status-pill <?= $p_pill ?>" style="font-size:8px;padding:2px 6px;white-space:nowrap;"><?= htmlspecialchars($prev['verification_status']) ?></span>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_actionable && !in_array($w_item, $ws_rubric_items, true)): ?>
                                            <form method="POST" class="ws-reason" id="wsreason-g-<?= $uid ?>">
                                                <input type="hidden" name="action_type" value="verify_upload">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="verification_status" value="Revision Requested">
                                                <input type="hidden" name="upload_id" value="<?= $uid ?>">
                                                <input type="hidden" name="student_user_id" value="<?= $sid ?>">
                                                <input type="hidden" name="req_name" value="<?= $name_attr ?>">
                                                <input type="hidden" name="target_item_id" value="<?= $w_item ?>">
                                                <input type="hidden" name="return_group" value="<?= $sid ?>">
                                                <label for="wsreasontext-g-<?= $uid ?>">Why is this being sent back?</label>
                                                <textarea id="wsreasontext-g-<?= $uid ?>" name="remarks" required
                                                          placeholder="e.g. Missing the dean's signature on page 2 — please re-upload the signed copy."></textarea>
                                                <div class="ws-reason-row">
                                                    <button type="button" class="btn btn-secondary" onclick="wsToggleReason('g-<?= $uid ?>')">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Send back to student</button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php
                        // What the batch bar can actually act on, and whether Form 008 is still open.
                        $n_selectable = 0;
                        foreach ($buckets['action'] as $a_item) {
                            if (!in_array($a_item, $ws_blocked_items, true)) $n_selectable++;
                        }
                        $rubric_pending = array_values(array_intersect($ws_rubric_items, $buckets['action']));
                        ?>
                        <?php if ($n_selectable > 0 || $rubric_pending): ?>
                        <div class="ws-bar">
                            <div class="ws-bar-count">
                                <span id="wsCount-g-<?= $sid ?>">0 of <?= $n_selectable ?> selected</span>
                                <?php if ($rubric_pending): ?>
                                    <span class="ws-bar-note"><?= htmlspecialchars($prop_names[$rubric_pending[0]] ?? 'One requirement') ?> needs its <?= $ws_rubric_label ?> rubric — it is never approved by checkbox.</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($n_selectable > 0): ?>
                                <?php // Granular path: tick what you want, approve just those. ?>
                                <button type="button" class="ws-linkbtn" id="wsSelectAll-g-<?= $sid ?>" onclick="wsSelectAll('g-<?= $sid ?>')">Select all</button>
                                <button type="submit" form="wsBulk-g-<?= $sid ?>" class="btn btn-secondary" id="wsApprove-g-<?= $sid ?>" disabled
                                        onclick="return wsConfirmBulk('g-<?= $sid ?>', 'selected')">
                                    <i data-lucide="check" style="width:15px;height:15px;"></i>
                                    <span id="wsApproveLabel-g-<?= $sid ?>"><?= $ws_approve_verb ?></span>
                                </button>
                            <?php endif; ?>
                            <?php // One-click path: clear everything that can be cleared, and on Proposal walk
                            // straight into the rubric-gated one so nothing is left dangling. ?>
                            <?php if ($rubric_pending): ?>
                                <button type="submit" form="wsBulk-g-<?= $sid ?>" class="btn btn-dispatch"
                                        onclick="return wsConfirmBulk('g-<?= $sid ?>', 'all+rubric')">
                                    <i data-lucide="zap" style="width:15px;height:15px;"></i>
                                    <?= $n_selectable > 0 ? $ws_all_verb . ' &amp; review ' . $ws_rubric_label : 'Review ' . $ws_rubric_label ?>
                                </button>
                            <?php elseif ($n_selectable > 0): ?>
                                <button type="submit" form="wsBulk-g-<?= $sid ?>" class="btn btn-dispatch"
                                        onclick="return wsConfirmBulk('g-<?= $sid ?>', 'all')">
                                    <i data-lucide="check-check" style="width:15px;height:15px;"></i>
                                    <?= $ws_all_verb ?> <?= $n_selectable ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                <?php else: foreach ($submissions_by_group as $sid => $grp):
                    $meta = $grp['meta'];
                    $g_pfp = $meta['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($meta['username']);
                ?>
                    <div class="req-card grp-card" data-group="<?= $sid ?>" style="margin-bottom:16px;">
                        <div class="req-header" onclick="toggleReq('grp-<?= $sid ?>', this)">
                            <div class="req-title" style="display:flex;align-items:center;gap:12px;">
                                <img src="<?= htmlspecialchars($g_pfp) ?>" class="grp-avatar" onerror="this.onerror=null;this.src='https://api.dicebear.com/9.x/bottts/svg?seed=<?= urlencode($meta['username']) ?>';">
                                <span style="display:flex;flex-direction:column;gap:2px;">
                                    <span class="grp-name"><?= highlightSearchTerm($meta['research_group_name'], $search_query) ?></span>
                                    <span class="grp-sub" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                        <span><?= highlightSearchTerm($meta['username'], $search_query) ?> &middot; <?= htmlspecialchars($meta['program'] ?: $meta['department']) ?></span>
                                        <?php if (!empty($meta['student_number'])): ?>
                                            <span class="school-id-chip"><i data-lucide="id-card"></i><?= highlightSearchTerm($meta['student_number'], $search_query) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="req-meta">
                                <span class="badge animate-pulse grp-action-badge" id="grp-action-<?= $sid ?>" style="background:var(--danger);<?= $grp['action_count'] > 0 ? '' : 'display:none;' ?>"><?= $grp['action_count'] ?> to review</span>
                                <span class="badge grp-file-badge"><?= $grp['file_count'] ?> files</span>
                                <i data-lucide="chevron-down" class="chevron" style="width:20px;height:20px;color:var(--mcnp-teal);"></i>
                            </div>
                        </div>
                        <div class="req-body" id="body-grp-<?= $sid ?>">
                            <div class="grp-req-grid">
                                <?php $rq_i = 0; foreach ($group_inbox_items as $p_item_id):
                                    if (!isset($grp['items'][$p_item_id])) continue;
                                    $sub = $grp['items'][$p_item_id];
                                    $ci_name = $prop_names[$p_item_id] ?? ('Requirement ' . $p_item_id);
                                    $is_cascaded = in_array($p_item_id, [13, 15, 16], true);
                                    $pill_class = 'pending';
                                    if ($sub['verification_status'] === 'Approved') $pill_class = 'approved';
                                    elseif ($sub['verification_status'] === 'Under Review') $pill_class = 'review';
                                    elseif ($sub['verification_status'] === 'Revision Requested') $pill_class = 'revision';
                                    $g_hist = $uploads_history[$p_item_id][$sid] ?? [];
                                ?>
                                    <div class="grp-req-card <?= $pill_class ?><?= $is_cascaded ? ' cascaded' : '' ?>" style="--i:<?= $rq_i++ ?>" data-status="<?= htmlspecialchars(strtolower($sub['verification_status'])) ?>" data-group="<?= $sid ?>">
                                        <?php
                                        // The signature, if there is one. Rows approved before
                                        // verified_by existed have no signer — say so plainly
                                        // rather than implying the current reviewer signed it.
                                        $sg_signed = $sub['verification_status'] === 'Approved' && !$is_cascaded;
                                        $sg_who    = $sub['verified_name'] ?? null;
                                        $sg_role   = $sub['verified_role'] ?? null;
                                        $sg_short  = $sg_role === 'Focal Person' ? 'Focal Person'
                                                   : ($sg_role === 'Research Coordinator' ? 'Coordinator'
                                                   : ($sg_role === 'Research Director' ? 'Office' : $sg_role));
                                        ?>
                                        <?php if ($sg_signed): ?>
                                            <?php /* The mark sits ON the card, top corner, so a wall
                                                     of them is readable without opening anything. */ ?>
                                            <span class="sg-mark<?= $sg_who ? '' : ' unknown' ?>"
                                                  title="<?= $sg_who ? htmlspecialchars($sg_who . ' · ' . $sg_short) : 'Signed before the signature was recorded' ?>">
                                                <i data-lucide="<?= $sg_who ? 'badge-check' : 'help-circle' ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div class="grp-req-top">
                                            <span class="grp-req-name"><?= htmlspecialchars($ci_name) ?></span>
                                            <span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($sub['verification_status']) ?></span>
                                        </div>
                                        <?php if ($p_item_id == 14 && !empty($sub['form_008_decision'])): ?>
                                            <div class="grp-req-score">Score: <?= $sub['form_008_score'] ?>/22 (<?= htmlspecialchars($sub['form_008_decision']) ?>)</div>
                                        <?php endif; ?>
                                        <button type="button" class="grp-req-file" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($sub), ENT_QUOTES) ?>, '<?= htmlspecialchars($ci_name, ENT_QUOTES) ?>', <?= $p_item_id ?>)" title="<?= htmlspecialchars($sub['original_filename']) ?>">
                                            <i data-lucide="file-text" style="width:13px;height:13px;flex-shrink:0;"></i>
                                            <span class="grp-req-fname"><?= htmlspecialchars($sub['original_filename'] ?: 'Document') ?></span>
                                        </button>
                                        <div class="grp-req-date"><?= date('M d, Y h:i A', strtotime($sub['uploaded_at'])) ?></div>
                                        <?php if ($sg_signed): ?>
                                            <div class="sg-by">
                                                <?php if ($sg_who): ?>
                                                    <img src="<?= htmlspecialchars($sub['verified_pfp'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($sg_who)) ?>" alt=""
                                                         onerror="this.onerror=null;this.src='https://api.dicebear.com/9.x/bottts/svg?seed=<?= urlencode($sg_who) ?>';">
                                                    <span>
                                                        <b><?= htmlspecialchars($sg_who) ?></b>
                                                        <em><?= htmlspecialchars($sg_short) ?><?php
                                                            if (!empty($sub['verified_at'])) {
                                                                echo ' &middot; ' . date('j M Y', strtotime($sub['verified_at']));
                                                            } ?></em>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="sg-none">Verified before this system recorded who signed.</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (count($g_hist) > 1): ?>
                                            <button type="button" class="history-toggle-btn" onclick="toggleHistory('ghist-<?= $p_item_id ?>-<?= $sid ?>', this)" style="background:var(--surface-2);border:none;padding:4px 8px;border-radius:6px;font-size:10.5px;font-weight:600;color:var(--mcnp-teal);cursor:pointer;display:inline-flex;align-items:center;gap:4px;margin-top:6px;">
                                                <i data-lucide="history" style="width:12px;height:12px;"></i> Show History (<?= count($g_hist) - 1 ?>)
                                            </button>
                                            <div id="ghist-<?= $p_item_id ?>-<?= $sid ?>" class="history-content-box" style="display:none;margin-top:6px;padding:8px;background:var(--surface-2);border:1px solid var(--border-line);border-radius:8px;font-size:11px;max-height:150px;overflow-y:auto;">
                                                <?php for ($i = 1; $i < count($g_hist); $i++):
                                                    $prev = $g_hist[$i];
                                                    $p_pill = 'pending';
                                                    if ($prev['verification_status'] === 'Approved') $p_pill = 'approved';
                                                    elseif ($prev['verification_status'] === 'Under Review') $p_pill = 'review';
                                                    elseif ($prev['verification_status'] === 'Revision Requested') $p_pill = 'revision';
                                                ?>
                                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding-bottom:6px;margin-bottom:6px;border-bottom:1px dashed var(--line);">
                                                        <div style="flex:1;min-width:0;">
                                                            <button type="button" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($prev), ENT_QUOTES) ?>, '<?= htmlspecialchars($ci_name, ENT_QUOTES) ?>', <?= $p_item_id ?>)" class="file-link" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:11px;padding:0;text-align:left;color:var(--info);">
                                                                <i data-lucide="file" style="width:11px;height:11px;vertical-align:middle;margin-right:3px;"></i><?= htmlspecialchars($prev['original_filename']) ?>
                                                            </button>
                                                            <div style="font-size:9.5px;color:var(--muted);margin-top:2px;"><?= date('M d, Y h:i A', strtotime($prev['uploaded_at'])) ?></div>
                                                        </div>
                                                        <span class="status-pill <?= $p_pill ?>" style="font-size:8px;padding:2px 6px;white-space:nowrap;"><?= htmlspecialchars($prev['verification_status']) ?></span>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($is_cascaded): ?>
                                            <div class="grp-req-cascade-note"><i data-lucide="info" style="width:11px;height:11px;"></i> Auto-cleared via Capsule Proposal</div>
                                        <?php else: ?>
                                            <button type="button" class="btn-evaluate grp-req-review" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($sub), ENT_QUOTES) ?>, '<?= htmlspecialchars($ci_name, ENT_QUOTES) ?>', <?= $p_item_id ?>)">
                                                <i data-lucide="eye" style="width:14px;height:14px;"></i> Review
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>


        </section>
    </div>

    <!-- Batch-approval confirmation. Replaces window.confirm(), which rendered the review as a
         browser alert titled "localhost says" with the requirements as a bullet list. -->
    <div class="ws-confirm" id="wsConfirm" role="dialog" aria-modal="true" aria-labelledby="wsConfirmTitle"
         onclick="if (event.target === this) wsCloseConfirm();">
        <div class="ws-confirm-card">
            <div class="ws-confirm-head">
                <span class="ws-confirm-icon"><i data-lucide="check-check"></i></span>
                <div class="ws-confirm-heading">
                    <h3 id="wsConfirmTitle">Approve requirements</h3>
                    <p id="wsConfirmSub">Confirm before this is written to the students' records.</p>
                </div>
                <button type="button" class="ws-confirm-x" onclick="wsCloseConfirm()" aria-label="Cancel">
                    <i data-lucide="x"></i>
                </button>
            </div>

            <div class="ws-confirm-body">
                <div class="ws-confirm-listlabel" id="wsConfirmListLabel">These will be marked approved</div>
                <ul class="ws-confirm-list" id="wsConfirmList"></ul>
                <div class="ws-confirm-note" id="wsConfirmNote" style="display:none;">
                    <i data-lucide="clipboard-list"></i><span id="wsConfirmNoteText"></span>
                </div>
            </div>

            <div class="ws-confirm-foot">
                <button type="button" class="btn btn-secondary" onclick="wsCloseConfirm()">Cancel</button>
                <button type="button" class="btn btn-dispatch" id="wsConfirmGo" onclick="wsRunConfirm()">
                    <i data-lucide="check" style="width:15px;height:15px;"></i> <span id="wsConfirmGoLabel">Approve</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Premium Split Window Evaluation Console Modal -->
    <div class="preview-modal" id="docModal" onclick="if (event.target === this) closeDocumentModal();">
        <div class="preview-content">
            <?php /* THE FILE VIEWER (2026-09-29, from the Claude Design concept). Every id the review JS
                     contract reads is kept: modalTitle, modalReqName, previewFrame, modal_* fields,
                     modalDownloadBtn, form011RubricBox, modalCnBox. The decision cards only drive the
                     same #modal_status select, which stays in the form. */ ?>
            <div class="preview-header dv-head">
                <div class="preview-header-titles">
                    <span class="dv-form"><?= htmlspecialchars([
                        'proposal' => 'ISAP-QMS-DCO-RDC Form 008, Research Proposal Review',
                        'final'    => 'ISAP-QMS-DCO-RDC, Final Defense Review',
                        'stats'    => 'ISAP-QMS-DCO-RDC Form 011, Statistical Treatment',
                        'plag'     => 'ISAP-QMS-DCO-RDC, Plagiarism Review',
                    ][$phase] ?? 'Document review') ?></span>
                    <h4 id="modalTitle" title="Document">Document</h4>
                </div>
                <div class="dv-pager" id="dvPager" hidden>
                    <button type="button" onclick="dvStep(-1)" aria-label="Previous document"><i data-lucide="chevron-left"></i></button>
                    <span id="dvPos">1 of 1</span>
                    <button type="button" onclick="dvStep(1)" aria-label="Next document"><i data-lucide="chevron-right"></i></button>
                </div>
                <button type="button" class="dv-x" onclick="closeDocumentModal()" aria-label="Close"><i data-lucide="x"></i></button>
            </div>
            <div class="preview-body">
                <div class="dv-stage" id="dvStage">
                    <div class="dv-canvas" id="dvCanvas"><iframe class="preview-frame" id="previewFrame" src="about:blank"></iframe></div>
                    <div class="dv-tools">
                        <button type="button" onclick="dvZoom(-0.1)" aria-label="Zoom out"><i data-lucide="minus"></i></button>
                        <span id="dvZoomPct">100%</span>
                        <button type="button" onclick="dvZoom(0.1)" aria-label="Zoom in"><i data-lucide="plus"></i></button>
                        <button type="button" class="dv-full" onclick="dvFull()" aria-label="Full screen"><i data-lucide="maximize"></i></button>
                    </div>
                </div>
                <div class="preview-sidebar">
                    <h3 class="dv-name" id="modalReqName">Evaluate Document</h3>
                    <p class="dv-meta" id="dvMeta"></p>

                    <form method="POST" class="action-form-modal" id="modalForm">
                        <input type="hidden" name="action_type" value="verify_upload">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="upload_id" id="modal_upload_id">
                        <input type="hidden" name="student_user_id" id="modal_student_id">
                        <input type="hidden" name="req_name" id="modal_req_name_input">
                        <input type="hidden" name="target_item_id" id="modal_target_item_id">
                        <!-- Set only when the drawer was opened from a workspace: tells the handler
                             to redirect back into it instead of re-rendering the whole list. -->
                        <input type="hidden" name="return_group" id="modal_return_group" value="">
                        <input type="hidden" name="return_item" id="modal_return_item" value="">

                        <div id="form011RubricBox" style="display:none; flex-direction:column; margin-bottom:15px; width:100%;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label style="font-size:11px; font-weight:800; color:var(--muted); text-transform:uppercase; margin:0; letter-spacing:0.02em;">Statistical Clearance Checklist (011)</label>
                            </div>

                            <?php
                            $section_idx_stat = 100;
                            foreach ($rubric_stats_sections as $title => $questions): ?>
                                <button type="button" class="rubric-accordion-btn" onclick="toggleAccordion('sec-<?= $section_idx_stat ?>', this)">
                                    <span><?= $title ?></span>
                                    <i data-lucide="chevron-down" style="width:14px; height:14px; color:var(--mcnp-teal);"></i>
                                </button>
                                <div class="rubric-accordion-content" id="sec-<?= $section_idx_stat ?>">
                                    <?php foreach ($questions as $key => $label): ?>
                                        <div class="criteria-item-block">
                                            <p class="criteria-text"><?= $label ?></p>
                                            <div class="radio-options-row">
                                                <label class="radio-label" style="color:var(--success);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="YES" class="rubric-radio stat-radio" onchange="runForm011Tally()" required> YES
                                                </label>
                                                <label class="radio-label" style="color:var(--danger);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="NO" class="rubric-radio stat-radio" onchange="runForm011Tally()" required> NO
                                                </label>
                                            </div>
                                            <input type="text" name="rubric[<?= $key ?>][comment]" placeholder="Feedback notes for this row criteria..." style="padding:6px 10px; font-size:11px; margin-top:4px;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php
                                $section_idx_stat++;
                            endforeach; ?>

                            <div style="background:#155563; color:white; padding:15px; border-radius:10px; display:flex; flex-direction:column; gap:6px; margin:10px 0; box-shadow:0 4px 10px rgba(12,52,61,0.15);">
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; border-bottom:1px solid rgba(255,255,255,0.15); padding-bottom:4px; justify-content:space-between;">
                                    <span>Calculated Tally:</span>
                                    <span id="form011LiveScore">0 / 7 Points (0%)</span>
                                </div>
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; justify-content:space-between;">
                                    <span>Decision Rule:</span>
                                    <span id="form011LiveDecision" style="color:var(--warning); text-transform:uppercase;">Awaiting Form Input</span>
                                </div>
                            </div>
                        </div>

                        <label class="dv-lab">Your decision</label>
                        <div class="dv-choices" role="radiogroup">
                            <button type="button" class="dv-choice ok" data-v="Approved" onclick="dvChoose('Approved')"><i data-lucide="check"></i>Approve</button>
                            <button type="button" class="dv-choice back" data-v="Revision Requested" onclick="dvChoose('Revision Requested')"><i data-lucide="rotate-ccw"></i>Needs revision</button>
                        </div>
                        <?php // The real field. Hidden, never `required`: a hidden required control fails the
                              // submit SILENTLY (not focusable) — the submit handler checks it instead. ?>
                        <select name="verification_status" id="modal_status" class="dv-hidden-select" onchange="cnDrawer(); dvPaint()">
                            <option value="" disabled selected>Select Evaluation...</option>
                            <option value="Approved">Approve Document</option>
                            <option value="Revision Requested">Reject / Request Revision</option>
                        </select>

                        <?php // Shown only for an Official Receipt (37) being APPROVED for a group not yet
                              // registered — see cnDrawer(). Approving it registers the payment. ?>
                        <div id="modalCnBox" class="modal-cn" hidden>
                            <label style="font-size:11px; font-weight:bold; color:var(--muted); text-transform:uppercase; margin-top:4px;">Control Number</label>
                            <div class="sp-cn-build">
                                <span class="sp-cn-fixed" id="modalCnPrefix"></span>
                                <input type="text" name="control_no" id="modal_control_no" class="sp-cn-seq cn-wide"
                                       placeholder="CU88759938" autocomplete="off" oninput="spPreview(this)" data-prefix="">
                            </div>
                            <p class="sp-cn-out">Will issue <b>&mdash;</b><span class="sp-cn-hint">The control number is printed on the Official Receipt.</span></p>
                        </div>

                        <label class="dv-lab" for="modal_remarks">Note to the group <span>(optional)</span></label>
                        <textarea name="remarks" id="modal_remarks" rows="4" placeholder="Anything they should know"></textarea>

                        <div class="dv-foot">
                            <button type="submit" class="dv-go" id="dvGo">Choose a decision</button>
                            <a id="modalDownloadBtn" href="#" download class="dv-dl"><i data-lucide="download"></i>Download original</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Script Setup and Live UTC Real-time Sync Widgets -->
    <script>
        lucide.createIcons();

        // Auto-dismiss the post-action success banner so it doesn't linger until a manual refresh.
        (function () {
            const bannerEl = document.getElementById('adminModuleAlert');
            if (!bannerEl) return;
            setTimeout(function () {
                bannerEl.style.transition = 'opacity .4s ease, transform .4s ease';
                bannerEl.style.opacity = '0';
                bannerEl.style.transform = 'translateY(-6px)';
                setTimeout(function () { bannerEl.remove(); }, 400);
            }, 3500);
        })();

        const groupProgressData = <?= json_encode($group_progress) ?>;
        let activeGroupId = null; // null means "all"
        let activeStatusFilter = 'action'; // 'action' (Pending+Under Review), 'revision', 'approved', 'all'
        // There is one review axis: by group. ("By requirement" was removed 2026-09-29; currentView stays
        // because older checks read it.)
        const currentView = 'group';
        // The list is paged: Approved archive and All submissions only ever grow.
        const CK_PAGE = 15;
        let ckLimit = CK_PAGE, ckMore = 0;

        /* ── WORKSPACES ─────────────────────────────────────────────────────────────────
           Two mirrored views, one implementation. Every workspace is addressed by a scope
           key: 'g-<groupId>' (By group) or 'r-<itemId>' (By requirement). All the markup is
           already in the DOM, so opening one is a view swap — no fetch, no reload. The
           reviewer stays until they press Back; decisions redirect back in. */
        const useGroupWorkspace = <?= $use_group_workspace ? 'true' : 'false' ?>;
        const wsApproveVerb = 'Approve';
        const wsSeenKey = 'ws-seen-<?= htmlspecialchars($phase, ENT_QUOTES) ?>';
        let openWorkspaceKey = null;

        function wsOpen(key) {
            const ws = document.getElementById('ws-' + key);
            if (!ws) return false;
            document.querySelectorAll('.grp-workspace').forEach(el => el.classList.remove('active'));
            ws.classList.add('active');
            document.body.classList.add('ws-open');
            // Hide whichever tile grid we came from; the other view is hidden already.
            const grpTiles = document.getElementById('grpTiles');
            if (grpTiles) grpTiles.style.display = 'none';
            openWorkspaceKey = key;
            wsRestoreSelection(key);
            wsSyncSelection(key);
            wsPaintSeen();
            window.scrollTo({ top: 0 });
            lucide.createIcons();
            return true;
        }

        function openGroupWorkspace(sid) {
            if (!wsOpen('g-' + sid)) return;
            // Keep the sidebar highlight in step; the workspace header already carries the
            // group's identity, so the floating profile card would just be a duplicate.
            activeGroupId = sid;
            document.querySelectorAll('.group-select-item').forEach(el => el.classList.remove('active'));
            const sideItem = document.getElementById('sidebar-g-' + sid);
            if (sideItem) sideItem.classList.add('active');
            const profileCard = document.getElementById('activeProfileCard');
            if (profileCard) profileCard.style.display = 'none';
        }


        function closeGroupWorkspace() {
            if (openWorkspaceKey === null) return;
            document.querySelectorAll('.grp-workspace').forEach(el => el.classList.remove('active'));
            document.body.classList.remove('ws-open');
            openWorkspaceKey = null;

            // Show the tile grid belonging to whichever view is on screen.
            const grpTiles = document.getElementById('grpTiles');
            if (grpTiles) grpTiles.style.display = '';

            activeGroupId = null;
            document.querySelectorAll('.group-select-item').forEach(el => el.classList.remove('active'));
            const allItem = document.getElementById('sidebar-g-all');
            if (allItem) allItem.classList.add('active');
            filterTableRows();
            lucide.createIcons();
        }

        // The group picker is collapsed by default so the review area owns the full module width.
        // This brings it back (and remembers the choice for next time).
        function toggleGroupSidebar() {
            const grid = document.getElementById('workspaceGrid');
            if (!grid) return;
            const open = grid.classList.toggle('full-width') === false;
            const btn = document.getElementById('groupsToggle');
            if (btn) {
                btn.classList.toggle('on', open);
                const icon = btn.querySelector('[data-lucide]');
                if (icon) icon.setAttribute('data-lucide', open ? 'panel-left-close' : 'panel-left-open');
            }
            try { localStorage.setItem('rd-module-groups', open ? 'open' : 'closed'); } catch (e) { /* ignore */ }
            if (window.lucide) lucide.createIcons();
        }

        /* -- batch selection ---------------------------------------------------------- */
        function wsSelKey(key) { return 'ws-sel-<?= htmlspecialchars($phase, ENT_QUOTES) ?>-' + key; }

        function wsBoxes(key) {
            return Array.from(document.querySelectorAll('#ws-' + key + ' .ws-check input'));
        }

        function wsSyncSelection(key) {
            const boxes = wsBoxes(key);
            const picked = boxes.filter(b => b.checked);
            boxes.forEach(b => {
                const card = b.closest('.ws-card');
                if (card) card.classList.toggle('selected', b.checked);
            });

            const label = document.getElementById('wsCount-' + key);
            if (label) label.textContent = picked.length + ' of ' + boxes.length + ' selected';

            const btn = document.getElementById('wsApprove-' + key);
            if (btn) btn.disabled = (picked.length === 0);
            const btnLabel = document.getElementById('wsApproveLabel-' + key);
            if (btnLabel) btnLabel.textContent = wsApproveVerb + (picked.length ? ' (' + picked.length + ')' : '');

            const all = document.getElementById('wsSelectAll-' + key);
            if (all) all.textContent = (boxes.length && picked.length === boxes.length) ? 'Clear' : 'Select all';

            wsPersistSelection(key);
        }

        function wsSelectAll(key) {
            const boxes = wsBoxes(key);
            const allOn = boxes.length > 0 && boxes.every(b => b.checked);
            boxes.forEach(b => { b.checked = !allOn; });
            wsSyncSelection(key);
        }

        // A decision reloads the page, so remember what was ticked and put it back.
        function wsPersistSelection(key) {
            try {
                const ids = wsBoxes(key).filter(b => b.checked).map(b => b.value);
                sessionStorage.setItem(wsSelKey(key), JSON.stringify(ids));
            } catch (e) { /* private mode — selection just doesn't survive the reload */ }
        }

        function wsRestoreSelection(key) {
            try {
                const raw = sessionStorage.getItem(wsSelKey(key));
                if (!raw) return;
                const ids = JSON.parse(raw) || [];
                wsBoxes(key).forEach(b => { b.checked = ids.indexOf(b.value) !== -1; });
            } catch (e) { /* ignore */ }
        }

        // Spell out exactly what is about to change before committing a batch.
        //   mode 'selected'   - only what the reviewer ticked
        //   mode 'all'        - everything that can be cleared here, one click
        //   mode 'all+rubric' - the same, then open the rubric-gated document (Proposal item 14)
        //
        // Always returns false: the submit no longer happens on this click but from the dialog's
        // own Approve button, once the reviewer has read what they are about to sign off.
        let wsConfirmKey = null;

        function wsConfirmBulk(key, mode) {
            const alsoRubric = (mode === 'all+rubric');
            const takeAll    = (mode === 'all' || alsoRubric);

            const flag = document.getElementById('wsOpen008-' + key);
            if (flag) flag.value = alsoRubric ? '1' : '';

            if (takeAll) {
                wsBoxes(key).forEach(b => { b.checked = true; });
                wsSyncSelection(key);
            }

            const names = wsBoxes(key).filter(b => b.checked).map(b => {
                const card = b.closest('.ws-card');
                if (!card) return '';
                // By group: the name IS the requirement. By requirement: the name block holds the
                // group title in .ws-card-title with the leader on a second line beneath it.
                const el = card.querySelector('.ws-card-title') || card.querySelector('.ws-card-name');
                return el ? el.textContent.trim() : '';
            }).filter(Boolean);

            if (!alsoRubric && names.length === 0) return false;

            // Whose records this touches — the group in a group workspace, the requirement in a
            // requirement workspace. Named, so a mis-click is obvious before it is committed.
            const ws = document.getElementById('ws-' + key);
            const subject = ws ? (ws.querySelector('.ws-head-name') || {}).textContent : '';

            const n = names.length;
            document.getElementById('wsConfirmTitle').textContent =
                n ? wsApproveVerb + ' ' + n + ' requirement' + (n === 1 ? '' : 's')
                  : 'Review the <?= addslashes($ws_rubric_label ?? 'Form 008') ?>';
            document.getElementById('wsConfirmSub').textContent =
                (subject ? subject.trim() + ' · ' : '') + 'the students are notified straight away.';

            const list = document.getElementById('wsConfirmList');
            list.innerHTML = '';
            names.forEach(nm => {
                const li = document.createElement('li');
                li.textContent = nm;          // textContent, never innerHTML — these are file/group names
                list.appendChild(li);
            });
            document.getElementById('wsConfirmListLabel').style.display = n ? '' : 'none';

            const note = document.getElementById('wsConfirmNote');
            if (alsoRubric) {
                document.getElementById('wsConfirmNoteText').textContent =
                    'The <?= addslashes($ws_rubric_label ?? 'Form 008') ?> rubric opens next so you can finish the one requirement a checkbox can\'t clear.';
                note.style.display = '';
            } else {
                note.style.display = 'none';
            }

            document.getElementById('wsConfirmGoLabel').textContent = n ? wsApproveVerb + ' ' + n : 'Open the rubric';

            wsConfirmKey = key;
            const dlg = document.getElementById('wsConfirm');
            dlg.classList.add('open');
            if (window.lucide) lucide.createIcons();
            setTimeout(() => document.getElementById('wsConfirmGo').focus(), 60);
            return false;
        }

        function wsCloseConfirm() {
            document.getElementById('wsConfirm').classList.remove('open');
            wsConfirmKey = null;
        }

        function wsRunConfirm() {
            if (!wsConfirmKey) return;
            const key = wsConfirmKey;
            try { sessionStorage.removeItem(wsSelKey(key)); } catch (e) { /* ignore */ }
            const form = document.getElementById('wsBulk-' + key);
            const go = document.getElementById('wsConfirmGo');
            if (go) go.disabled = true;   // a double-click would post the batch twice
            if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && document.getElementById('wsConfirm').classList.contains('open')) wsCloseConfirm();
        });

        /* -- inline "request revision", attached to the card it belongs to -------------- */
        function wsToggleReason(key) {
            const box = document.getElementById('wsreason-' + key);
            if (!box) return;
            const opening = !box.classList.contains('open');
            box.classList.toggle('open', opening);
            if (opening) {
                const ta = box.querySelector('textarea');
                if (ta) ta.focus();
            }
        }

        /* -- "you haven't opened this yet" dot ----------------------------------------- */
        function wsMarkSeen(uploadId) {
            if (!uploadId) return;
            try {
                const seen = JSON.parse(sessionStorage.getItem(wsSeenKey) || '[]');
                if (seen.indexOf(String(uploadId)) === -1) {
                    seen.push(String(uploadId));
                    sessionStorage.setItem(wsSeenKey, JSON.stringify(seen));
                }
            } catch (e) { /* ignore */ }
            wsPaintSeen();
        }

        function wsPaintSeen() {
            let seen = [];
            try { seen = JSON.parse(sessionStorage.getItem(wsSeenKey) || '[]'); } catch (e) { /* ignore */ }
            document.querySelectorAll('.ws-unseen').forEach(dot => {
                dot.style.display = (seen.indexOf(String(dot.dataset.unseen)) !== -1) ? 'none' : '';
            });
        }
        // Sidebar search filtering function. The 7 most-recent groups show by default (server
        // sorts by recency); typing a query reveals matches from the full list regardless of that cutoff.
        let sidebarGroupsExpanded = false;
        const sidebarTotalGroups = <?= (int) count($all_student_groups) ?>;

        function toggleSidebarShowMore() {
            sidebarGroupsExpanded = !sidebarGroupsExpanded;
            document.querySelectorAll('#sidebarGroupsList [data-extra="1"]').forEach(el => {
                el.style.display = sidebarGroupsExpanded ? 'flex' : 'none';
            });
            const btn = document.getElementById('sidebarShowMoreGroups');
            if (btn) btn.textContent = sidebarGroupsExpanded ? 'Show fewer groups' : ('Show all ' + sidebarTotalGroups + ' groups');
        }

        function filterSidebarGroups() {
            const query = document.getElementById('sidebarGroupSearch').value.toLowerCase().trim();
            const searching = query.length > 0;
            const items = document.querySelectorAll('#sidebarGroupsList .group-select-item');

            items.forEach(item => {
                const groupName = item.getAttribute('data-group-name') || '';
                const studentName = item.getAttribute('data-student-name') || '';
                const programName = item.getAttribute('data-program-name') || '';
                const schoolId = item.getAttribute('data-school-id') || '';
                const matches = groupName.includes(query) || studentName.includes(query) || programName.includes(query) || schoolId.includes(query);
                const isExtra = item.getAttribute('data-extra') === '1';

                item.style.display = (matches && (searching || sidebarGroupsExpanded || !isExtra)) ? 'flex' : 'none';
            });

            const btn = document.getElementById('sidebarShowMoreGroups');
            if (btn) btn.style.display = searching ? 'none' : 'block';
        }

        // 2. Select Student Group Profile function
        function selectStudentGroup(data) {
            // Group workspace: picking a group from the sidebar opens that group's workspace
            // directly, which is the same destination as clicking its tile.
            if (useGroupWorkspace && currentView === 'group') {
                if (data === null) { closeGroupWorkspace(); return; }
                if (document.getElementById('ws-g-' + data.user_id)) { openGroupWorkspace(data.user_id); return; }
                // No workspace = this group has submitted nothing in this phase yet. Fall through
                // to the plain profile card, but drop out of whatever workspace was open.
                closeGroupWorkspace();
            }

            // Remove active style from all sidebar items
            document.querySelectorAll('.group-select-item').forEach(el => el.classList.remove('active'));

            const activeProfileCard = document.getElementById('activeProfileCard');

            if (data === null) {
                activeGroupId = null;
                document.getElementById('sidebar-g-all').classList.add('active');
                activeProfileCard.style.display = 'none';
            } else {
                activeGroupId = data.user_id;
                document.getElementById('sidebar-g-' + data.user_id).classList.add('active');

                // Show dynamic detail profile card at top of content area
                activeProfileCard.style.display = 'block';

                // Update details
                const pfpUrl = data.profile_pic || 'https://api.dicebear.com/9.x/bottts/svg?seed=' + encodeURIComponent(data.username);
                document.getElementById('cardPfp').src = pfpUrl;
                document.getElementById('cardGroupName').textContent = data.research_group_name;
                document.getElementById('cardLeader').innerHTML = `<i data-lucide="user" style="width:14px; height:14px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> Primary Contact: ${data.username}`;
                document.getElementById('cardEmail').innerHTML = `<i data-lucide="mail" style="width:13px; height:13px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> ${data.email}`;
                document.getElementById('cardDetails').textContent = `${data.program} • ${data.department}`;

                // School ID number — hidden entirely for accounts that predate the field.
                const schoolIdWrap = document.getElementById('cardSchoolIdWrap');
                document.getElementById('cardSchoolId').textContent = data.student_number || '';
                schoolIdWrap.style.display = data.student_number ? 'block' : 'none';

                // Update actual progress metrics
                const progressInfo = groupProgressData[data.user_id] || {
                    pct: 0,
                    approved: 0,
                    total: 0
                };
                document.getElementById('cardProgressText').textContent = progressInfo.pct + '% (' + progressInfo.approved + '/' + progressInfo.total + ' Approved)';
                document.getElementById('cardProgressBar').style.width = progressInfo.pct + '%';
            }

            // Group inbox: auto-expand the picked group's profile card so its requirements are visible.
            if (currentView === 'group' && data !== null) {
                document.querySelectorAll('#viewByGroup .grp-card').forEach(card => {
                    const isSel = String(card.getAttribute('data-group')) === String(data.user_id);
                    const body = card.querySelector('.req-body');
                    const header = card.querySelector('.req-header');
                    const chevron = header ? header.querySelector('.chevron') : null;
                    if (!body || !header) return;
                    body.classList.toggle('active', isSel);
                    header.classList.toggle('active', isSel);
                    if (chevron) chevron.style.transform = isSel ? 'rotate(180deg)' : 'rotate(0deg)';
                });
            }

            filterTableRows();
            lucide.createIcons();
        }

        // Counted from the tiles the filter itself reads, so the number and the list can never
        // disagree. Re-run whenever the lens or the group picker changes what is countable.
        function paintTabCounts() {
            const wrap = document.getElementById('grpTiles');
            const tabs = document.querySelectorAll('.status-filter-tab');
            if (!wrap || !tabs.length) return;

            const tiles = Array.prototype.filter.call(wrap.querySelectorAll('.grp-tile'), tile => {
                const gid = tile.getAttribute('data-group');
                return (gid === null) || (activeGroupId === null) || (String(activeGroupId) === String(gid));
            });
            const n = {
                action:   tiles.filter(t => parseInt(t.dataset.action, 10) > 0).length,
                revision: tiles.filter(t => parseInt(t.dataset.revision, 10) > 0).length,
                approved: tiles.filter(t => parseInt(t.dataset.approved, 10) > 0).length,
                all:      tiles.length
            };
            tabs.forEach(tab => {
                const k = tab.getAttribute('data-filter');
                const el = tab.querySelector('.tab-n');
                if (el) el.textContent = n[k] ?? 0;
                // Dim only (see .is-empty) — never disable: a disabled tab is a trap you cannot
                // click your way back out of.
                tab.classList.toggle('is-empty', (n[k] ?? 0) === 0 && !tab.classList.contains('active'));
            });
            // "That's everyone in Action needed." — so an empty-looking tab is not a mystery.
            const foot = document.getElementById('ckFoot');
            if (foot) {
                const labels = { action: 'Action needed', revision: 'Revision history', approved: 'Approved archive', all: 'All submissions' };
                const shown = n[activeStatusFilter] ?? 0;
                const rest = n.all - shown;
                if (!shown) {
                    foot.innerHTML = '';
                } else if (ckMore > 0) {
                    foot.innerHTML = 'Showing ' + (shown - ckMore) + ' of ' + shown + ' in ' + labels[activeStatusFilter] + '.' +
                        ' <button type="button" class="ck-more" onclick="ckShowMore()">Show ' + Math.min(CK_PAGE, ckMore) + ' more</button>';
                } else {
                    foot.textContent = 'That\u2019s everyone in ' + labels[activeStatusFilter] + '.' +
                        (activeStatusFilter !== 'all' && rest > 0 ? ' The other ' + rest + ' ' + (rest === 1 ? 'group is' : 'groups are') + ' under All submissions.' : '');
                }
            }
        }
        function ckShowMore() {
            ckLimit += CK_PAGE;
            filterTableRows();
        }

        function filterByStatus(status) {
            activeStatusFilter = status;
            ckLimit = CK_PAGE;   // a new tab starts at its first page
            document.querySelectorAll('.status-filter-tab').forEach(btn => {
                if (btn.getAttribute('data-filter') === status) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            filterTableRows();
        }

        function filterTableRows() {
            // Only the requirement submission tables are filtered. The Pending Payments and
            // Ready-for-Release tables are their own self-contained workflows and must never be
            // hidden by the status tabs (their pills don't map to Pending/Under Review/etc.).
            document.querySelectorAll('table.submissions-table').forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                let visibleRowsCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.no-records-row)');
                // Tallied regardless of the group filter — badges summarize the whole
                // requirement item, not just the currently-selected student group.
                const statusCounts = { pending: 0, revision: 0, approved: 0, total: 0 };

                rows.forEach(row => {
                    const statusText = (row.dataset.status || '').trim().toLowerCase();
                    statusCounts.total++;
                    if (statusText === 'pending' || statusText === 'under review') statusCounts.pending++;
                    else if (statusText === 'revision requested' || statusText === 'revision needed') statusCounts.revision++;
                    else if (statusText === 'approved') statusCounts.approved++;

                    // Check Group Filter
                    let groupMatches = true;
                    if (activeGroupId !== null) {
                        groupMatches = row.classList.contains('sub-row-g-' + activeGroupId);
                    }

                    // Check Status Filter — read the row's OWN current status from data-status.
                    // (Reading .status-pill was buggy: the "Show History" box in the first cell
                    // renders older versions' pills first, so a resubmitted-Pending row was being
                    // misfiled under Revision History based on its previous rejected version.)
                    let statusMatches = true;
                    if (activeStatusFilter !== 'all') {
                        if (statusText) {
                            if (activeStatusFilter === 'action' || activeStatusFilter === 'pending') {
                                // Active queue: only submissions that still need an admin decision
                                statusMatches = statusText === 'pending' || statusText === 'under review';
                            } else if (activeStatusFilter === 'approved') {
                                statusMatches = statusText === 'approved';
                            } else if (activeStatusFilter === 'revision') {
                                statusMatches = statusText === 'revision requested' || statusText === 'revision needed';
                            }
                        }
                    }

                    if (groupMatches && statusMatches) {
                        row.style.display = '';
                        visibleRowsCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Handle no records row
                const noRecordsRow = tbody.querySelector('.no-records-row');
                if (noRecordsRow) {
                    if (visibleRowsCount === 0) {
                        noRecordsRow.style.display = '';
                    } else {
                        noRecordsRow.style.display = 'none';
                    }
                }

                // Sync this item's badges so they always reflect what's actually in the
                // currently active tab, instead of a stale lifetime total.
                const reqBody = table.closest('.req-body');
                const itemId = reqBody ? reqBody.id.replace('body-', '') : null;
                if (itemId) {
                    const actionBadge = document.getElementById('action-badge-' + itemId);
                    if (actionBadge) {
                        if (statusCounts.pending > 0) {
                            actionBadge.textContent = statusCounts.pending + ' Action Needed';
                            actionBadge.style.display = '';
                        } else {
                            actionBadge.style.display = 'none';
                        }
                    }
                    const totalBadge = document.getElementById('total-badge-' + itemId);
                    if (totalBadge) {
                        let label = 'Total Submitted';
                        let count = statusCounts.total;
                        if (activeStatusFilter === 'action') {
                            label = 'Action Needed'; count = statusCounts.pending;
                        } else if (activeStatusFilter === 'revision') {
                            label = 'In Revision History'; count = statusCounts.revision;
                        } else if (activeStatusFilter === 'approved') {
                            label = 'In Approved Archive'; count = statusCounts.approved;
                        }
                        totalBadge.textContent = count + ' ' + label;
                    }
                }
            });

            // GROUP INBOX view (proposal): filter requirement mini-cards by status, hide profiles with
            // nothing to show under the current filter or non-selected in the sidebar, and refresh the
            // per-group "N to review" badge. Mirrors the table logic above on the .grp-card DOM.
            document.querySelectorAll('#viewByGroup .grp-card').forEach(card => {
                const gid = card.getAttribute('data-group');
                let visibleTotal = 0;
                let visibleActionable = 0;

                card.querySelectorAll('.grp-req-card').forEach(mc => {
                    const st = (mc.dataset.status || '').trim().toLowerCase();
                    let statusMatches = true;
                    if (activeStatusFilter !== 'all') {
                        if (activeStatusFilter === 'action' || activeStatusFilter === 'pending') {
                            statusMatches = (st === 'pending' || st === 'under review');
                        } else if (activeStatusFilter === 'approved') {
                            statusMatches = (st === 'approved');
                        } else if (activeStatusFilter === 'revision') {
                            statusMatches = (st === 'revision requested' || st === 'revision needed');
                        }
                    }
                    if (statusMatches) {
                        mc.style.display = '';
                        visibleTotal++;
                        if (st === 'pending' || st === 'under review') visibleActionable++;
                    } else {
                        mc.style.display = 'none';
                    }
                });

                const groupMatches = (activeGroupId === null) || (String(activeGroupId) === String(gid));
                card.style.display = (groupMatches && visibleTotal > 0) ? '' : 'none';

                const gb = document.getElementById('grp-action-' + gid);
                if (gb) {
                    if (visibleActionable > 0) {
                        gb.textContent = visibleActionable + ' to review';
                        gb.style.display = '';
                    } else {
                        gb.style.display = 'none';
                    }
                }
            });

            // WORKSPACE views: the status tabs pick which *tiles* are worth opening. They never
            // hide requirements inside a group workspace, because seeing the whole checklist
            // (including what's missing) is the point of that screen.
            // One page at a time — CK_PAGE rows, then "Show more". A tab that grows forever (the
            // archive, All submissions) must not render the whole year.
            ckMore = 0;
            [['grpTiles', 'grpTilesEmpty']].forEach(([wrapId, emptyId]) => {
                const tileWrap = document.getElementById(wrapId);
                if (!tileWrap) return;
                let shown = 0;
                tileWrap.querySelectorAll('.grp-tile').forEach(tile => {
                    let statusMatches = true;
                    if (activeStatusFilter === 'action' || activeStatusFilter === 'pending') {
                        statusMatches = parseInt(tile.dataset.action, 10) > 0;
                    } else if (activeStatusFilter === 'approved') {
                        statusMatches = parseInt(tile.dataset.approved, 10) > 0;
                    } else if (activeStatusFilter === 'revision') {
                        statusMatches = parseInt(tile.dataset.revision, 10) > 0;
                    }
                    // Only group tiles answer to the sidebar's group picker; requirement tiles
                    // span every group by definition.
                    const gid = tile.getAttribute('data-group');
                    const groupMatches = (gid === null) || (activeGroupId === null) || (String(activeGroupId) === String(gid));
                    const visible = statusMatches && groupMatches;
                    if (visible) shown++;
                    const onPage = visible && shown <= ckLimit;
                    if (visible && !onPage) ckMore++;
                    tile.style.display = onPage ? '' : 'none';
                });
                const tilesEmpty = document.getElementById(emptyId);
                if (tilesEmpty) tilesEmpty.style.display = shown === 0 ? '' : 'none';
            });


            paintTabCounts();
        }

        function toggleHistory(id, btnEl) {
            const box = document.getElementById(id);
            if (box.style.display === 'none') {
                box.style.display = 'block';
                btnEl.innerHTML = `<i data-lucide="history" style="width: 12px; height: 12px;"></i> Hide History`;
            } else {
                box.style.display = 'none';
                btnEl.innerHTML = `<i data-lucide="history" style="width: 12px; height: 12px;"></i> Show History`;
            }
            lucide.createIcons();
        }

        // "View names" — the roster is already rendered, so this only shows it. Keeping the count
        // in the label means the button still says how many people are in there while it's open.
        function wsToggleNames(id, btnEl) {
            const panel = document.getElementById(id);
            if (!panel) return;
            const open = panel.style.display === 'none';
            panel.style.display = open ? 'flex' : 'none';
            btnEl.classList.toggle('is-open', open);
            const n = panel.querySelectorAll('.ws-name-row').length;
            btnEl.innerHTML = `<i data-lucide="users"></i> ${open ? 'Hide names' : 'View names'}<span class="ws-names-n">${n}</span>`;
            lucide.createIcons();
        }

        // ── Pipeline: band filter, row fold-out, live control-number preview ───────────────
        var spBandKey = document.querySelector('.pl-pill-btn.active[data-band]')
            ? document.querySelector('.pl-pill-btn.active[data-band]').getAttribute('data-band') : 'you';

        function spBand(band, btn) {
            spBandKey = band;
            document.querySelectorAll('.pl-pill-btn[data-band]').forEach(function (b) { b.classList.toggle('active', b === btn); });
            document.querySelectorAll('.sp-row').forEach(function (row) {
                row.hidden = row.getAttribute('data-band') !== band;
            });
        }

        function spxStart(bodyId) {
            var pill = document.querySelector('.pl-pill-btn[data-band="you"]');
            if (pill) spBand('you', pill);
            var body = document.getElementById(bodyId);
            if (!body) return;
            if (body.hidden) spToggle(bodyId, body.previousElementSibling);
            body.closest('.sp-row').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function spToggle(bodyId, headEl) {
            var body = document.getElementById(bodyId);
            var btn = headEl.querySelector('.pl-open');
            if (!body) return;
            var open = body.hidden;
            body.hidden = !open;
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        // Treat verdict. Release needs the file; a send-back needs ticks + a note and the file is
        // optional. The server refuses the same things — this only saves a round trip.
        function spVerdict(radio) {
            var f = radio.closest('form'), rev = radio.value === 'revise';
            f.querySelector('.sp-v-revise').hidden = !rev;
            f.querySelector('.sp-v-revise-note').hidden = !rev;
            f.querySelector('.sp-v-revise-note').required = rev;
            f.querySelector('.sp-v-release-note').hidden = rev;
            f.querySelector('.sp-file-hint').hidden = !rev;
            f.querySelector('input[type=file]').required = !rev;
            var go = f.querySelector('.sp-rel-go');
            go.style.background = rev ? 'var(--warning)' : 'var(--success)';
            go.querySelector('span').textContent = rev ? 'Send back for revision' : 'Release Results';
        }
        function spRelCheck(f) {
            var v = f.querySelector('input[name=verdict]:checked');
            if (v && v.value === 'revise' && !f.querySelector('input[name="items[]"]:checked')) {
                alert('Tick at least one requirement that needs fixing.');
                return false;
            }
            return true;
        }

        // The sequence is padded to three the same way the handler pads it, so the preview is
        // the string that will actually be minted rather than an approximation of it.
        function spPreview(input) {
            var out = input.closest('form').querySelector('.sp-cn-out b');
            if (!out) return;
            var raw = input.value.trim();
            out.textContent = raw === '' ? '—' : input.getAttribute('data-prefix') + (raw.length < 3 ? raw.padStart(3, '0') : raw);
        }
        function toggleReq(id, headerEl) {
            const body = document.getElementById('body-' + id);
            const icon = headerEl.querySelector('.chevron');
            if (!body.classList.contains('active')) {
                body.classList.add('active');
                headerEl.classList.add('active');
                if (icon) icon.style.transform = 'rotate(180deg)';
            } else {
                body.classList.remove('active');
                headerEl.classList.remove('active');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }

        function toggleAccordion(secId, btnEl) {
            const content = document.getElementById(secId);
            const icon = btnEl.querySelector('[data-lucide="chevron-down"]');
            if (content.classList.contains('open')) {
                content.classList.remove('open');
                if (icon) icon.style.transform = 'rotate(0deg)';
            } else {
                content.classList.add('open');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        }

        function runForm011Tally() {
            const selectedRadios = document.querySelectorAll('.stat-radio:checked');
            let yesCounter = 0;

            selectedRadios.forEach(radio => {
                if (radio.value === 'YES') yesCounter++;
            });

            const totalQuestions = 7;
            const percentage = Math.round((yesCounter / totalQuestions) * 100);
            document.getElementById('form011LiveScore').textContent = `${yesCounter} / ${totalQuestions} Points (${percentage}%)`;

            let ruleLabel = "Deferred";
            let systemStatusSuggestion = "Revision Requested";

            if (yesCounter === 7) {
                ruleLabel = "Accepted with no revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 5) {
                ruleLabel = "Accepted with minor revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 2) {
                ruleLabel = "Accepted with major revision";
                systemStatusSuggestion = "Revision Requested";
            } else if (yesCounter >= 1) {
                ruleLabel = "Deferred";
                systemStatusSuggestion = "Revision Requested";
            }

            const txtElement = document.getElementById('form011LiveDecision');
            txtElement.textContent = ruleLabel;
            txtElement.style.color = (yesCounter >= 5) ? 'var(--success)' : ((yesCounter >= 2) ? 'var(--warning)' : 'var(--danger)');

            document.getElementById('modal_status').value = systemStatusSuggestion;
        }

        // `returnGroup` / `returnItem` are optional and only passed from a workspace. When one is
        // present the handler redirects back into that workspace after the decision instead of
        // re-rendering the whole list. Callers that pass three arguments behave exactly as before.
        function openDocumentModal(data, reqName, itemId, returnGroup, returnItem) {
            document.getElementById('docModal').style.display = 'flex';
            document.getElementById('modal_return_group').value = returnGroup ? returnGroup : '';
            document.getElementById('modal_return_item').value = returnItem ? returnItem : '';
            if (returnGroup || returnItem) wsMarkSeen(data.upload_id);
            dvMeta(data); dvScale = 1.1; dvZoom(-0.1);
            const modalTitleText = data.research_group_name + ' - ' + (data.original_filename || 'Document');
            const modalTitleEl = document.getElementById('modalTitle');
            modalTitleEl.textContent = modalTitleText;
            modalTitleEl.title = modalTitleText; // full text on hover — the header truncates long names with an ellipsis
            document.getElementById('modalReqName').textContent = reqName;

            document.getElementById('modal_upload_id').value = data.upload_id;
            document.getElementById('modal_student_id').value = data.student_user_id;
            document.getElementById('modal_req_name_input').value = reqName;
            document.getElementById('modal_target_item_id').value = itemId;
            document.getElementById('modal_remarks').value = data.remarks || '';
            document.getElementById('modal_status').value = (data.verification_status === 'Approved' || data.verification_status === 'Revision Requested') ? data.verification_status : '';

            // Clean state parameters inside Form No. 008 checklist workspace blocks
            const statRubricContainer = document.getElementById('form011RubricBox');
            const allRadioInputs = document.querySelectorAll('.rubric-radio');
            const allCommentInputs = document.querySelectorAll('input[type="text"][name^="rubric"]');

            allRadioInputs.forEach(r => {
                r.checked = false;
                r.required = false;
            });
            allCommentInputs.forEach(c => c.value = '');
            document.getElementById('form011LiveScore').textContent = "0 / 7 Points (0%)";
            document.getElementById('form011LiveDecision').textContent = "Awaiting Form Input";
            document.getElementById('form011LiveDecision').style.color = "var(--warning)";

            statRubricContainer.style.display = 'none';

            let activeContainer = null;
            let runTallyFunc = null;

            // Item 14's Form 008 rubric was retired — the Capsule Proposal is approved or sent
            // back like any other document now, so nothing opens a rubric for it. Form 011
            // (stats, item 3) still has one and is untouched.
            if (parseInt(itemId) === 3) {
                statRubricContainer.style.display = 'flex';
                activeContainer = statRubricContainer;
                runTallyFunc = runForm011Tally;
            }
            // NOTE: rubric radios are intentionally NOT HTML-`required`. Hidden required radios inside
            // collapsed accordions caused native submit to fail silently ("control not focusable"),
            // which broke Approve when re-evaluating a submission with no stored rubric. We validate
            // completeness in the #modalForm submit handler instead (see below).

            if (activeContainer && data.form_008_data) {
                try {
                    const parsingRubric = JSON.parse(data.form_008_data);
                    Object.keys(parsingRubric).forEach(key => {
                        const valState = parsingRubric[key]['val'];
                        const commentText = parsingRubric[key]['comment'];

                        const radioMatch = activeContainer.querySelector(`input[name="rubric[${key}][val]"][value="${valState}"]`);
                        if (radioMatch) radioMatch.checked = true;

                        const textMatch = activeContainer.querySelector(`input[name="rubric[${key}][comment]"]`);
                        if (textMatch) textMatch.value = commentText || '';
                    });
                    if (runTallyFunc) runTallyFunc();
                } catch (e) {
                    console.error("Data tracking initialization failed", e);
                }
            }

            // Check if it's the custom Statistical Form
            let targetSrc = data.file_path;
            if (targetSrc && targetSrc.startsWith('form_stat:')) {
                const formId = targetSrc.split(':')[1];
                targetSrc = 'view_stat_form.php?id=' + formId;
                document.getElementById('modalDownloadBtn').style.display = 'none';
            } else {
                document.getElementById('modalDownloadBtn').style.display = 'inline-block';
                document.getElementById('modalDownloadBtn').href = targetSrc;
            }

            const iframe = document.getElementById('previewFrame');
            const fileExt = (data.file_path || '').split('.').pop().toLowerCase();

            if (!targetSrc.startsWith('view_stat_form.php') && ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(fileExt)) {
                iframe.removeAttribute('srcdoc');
                const pathname = window.location.pathname;
                const dashIndex = pathname.indexOf('/dashboards/');
                const base = dashIndex !== -1 ? pathname.substring(0, dashIndex) : '';
                const cleanPath = targetSrc.replace('../', '');
                const publicUrl = window.location.origin + base + '/' + cleanPath;
                iframe.src = 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(publicUrl);
            } else {
                iframe.removeAttribute('srcdoc');
                iframe.src = targetSrc;
            }
            cnDrawer();
            dvPaint();
        }

        // Groups still awaiting registration -> their control-number prefix (same
        // sp_control_prefix() the handler mints with). Statistician only; scoped like every list.
        const SP_CN = <?php
            $sp_cn_map = [];
            if ($role === 'Statistician' && $phase === 'stats') {
                $cn_q = $pdo->prepare("SELECT f.user_id, u.program FROM form_stat_treatment f JOIN users u ON u.user_id = f.user_id
                                       WHERE f.status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification') AND $fp_scope_sql");
                $cn_q->execute($fp_scope_params);
                foreach ($cn_q->fetchAll(PDO::FETCH_ASSOC) as $cn_r) $sp_cn_map[(string)$cn_r['user_id']] = sp_control_prefix($cn_r['program']);
            }
            echo json_encode((object)$sp_cn_map);
        ?>;

        // Approving an Official Receipt (37) for an unregistered group registers it, so the drawer
        // asks for the number then — and only then. Revise hides it again.
        function cnDrawer() {
            const box = document.getElementById('modalCnBox');
            if (!box) return;
            const input  = document.getElementById('modal_control_no');
            const item   = parseInt(document.getElementById('modal_target_item_id').value, 10);
            const prefix = SP_CN[document.getElementById('modal_student_id').value];
            const show   = item === 37 && prefix !== undefined && document.getElementById('modal_status').value === 'Approved';
            box.hidden = !show;
            input.required = show;
            if (prefix !== undefined) {
                document.getElementById('modalCnPrefix').textContent = prefix;
                input.setAttribute('data-prefix', prefix);
            }
            spPreview(input);
        }

        // Lightweight scan viewer for the plagiarism payment desk. Deliberately not the review
        // modal — a payment document is decided by the Register / Send back buttons beside it,
        // never from a preview, so this only has to show the file.
        function dsPlagPreview(path, name) {
            var box = document.getElementById('plagPrev');
            if (!box) return;
            document.getElementById('plagPrevName').textContent = name || 'Document';
            var ext = (path.split('.').pop() || '').toLowerCase();
            var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1;
            document.getElementById('plagPrevBody').innerHTML = isImg
                ? '<img src="' + path + '" alt="">'
                : '<iframe src="' + path + '"></iframe>';
            box.classList.add('open');
        }
        function dsPlagClose() {
            var box = document.getElementById('plagPrev');
            if (!box) return;
            box.classList.remove('open');
            document.getElementById('plagPrevBody').innerHTML = '';
        }

        // ---- the viewer: decision cards, pager, zoom, open-next ----------------------------
        // The documents still waiting in the open group, in order: the pager steps through them
        // by clicking their own Open & decide buttons, so every open goes through the same path.
        function dvQueue() {
            return Array.from(document.querySelectorAll('.grp-workspace.active .ws-section.action .ws-card[data-upload] .ws-card-actions .btn-primary'));
        }
        function dvIndex() {
            var uid = String(document.getElementById('modal_upload_id').value || '');
            return dvQueue().findIndex(function (b) { var c = b.closest('.ws-card'); return c && c.dataset.upload === uid; });
        }
        function dvStep(d) {
            var q = dvQueue(), i = dvIndex();
            if (i < 0 || !q[i + d]) return;
            q[i + d].click();
        }
        function dvChoose(v) {
            var sel = document.getElementById('modal_status');
            sel.value = v;
            sel.dispatchEvent(new Event('change'));
        }
        function dvPaint() {
            var v = document.getElementById('modal_status').value;
            document.querySelectorAll('.dv-choice').forEach(function (c) { c.classList.toggle('on', c.dataset.v === v); });
            var q = dvQueue(), i = dvIndex(), hasNext = i >= 0 && i < q.length - 1;
            var go = document.getElementById('dvGo');
            go.disabled = !v;
            go.classList.toggle('back', v === 'Revision Requested');
            go.textContent = !v ? 'Choose a decision'
                : v === 'Revision Requested' ? 'Send back to the group'
                : (hasNext ? 'Approve and open next' : 'Approve');
            var pager = document.getElementById('dvPager');
            pager.hidden = !(i >= 0 && q.length > 1);
            if (!pager.hidden) {
                document.getElementById('dvPos').textContent = (i + 1) + ' of ' + q.length;
                pager.querySelector('button:first-child').disabled = i === 0;
                pager.querySelector('button:last-child').disabled = !hasNext;
            }
        }
        var dvScale = 1;
        function dvZoom(d) {
            dvScale = Math.min(2, Math.max(0.5, Math.round((dvScale + d) * 10) / 10));
            var f = document.getElementById('previewFrame');
            f.style.transform = 'scale(' + dvScale + ')';
            f.style.width = (100 / dvScale) + '%';
            f.style.height = (100 / dvScale) + '%';
            document.getElementById('dvZoomPct').textContent = Math.round(dvScale * 100) + '%';
        }
        function dvFull() {
            var st = document.getElementById('dvStage');
            if (document.fullscreenElement) document.exitFullscreen(); else if (st.requestFullscreen) st.requestFullscreen();
        }
        // Who sent it and how long ago — from the upload's own epoch when the row carries one.
        function dvMeta(data) {
            var el = document.getElementById('dvMeta');
            if (!el) return;
            var ago = '';
            if (data.uploaded_ts) {
                var s = Math.max(0, Math.floor(Date.now() / 1000) - parseInt(data.uploaded_ts, 10));
                ago = s < 3600 ? 'just now' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + ' day' + (Math.floor(s / 86400) === 1 ? '' : 's') + ' ago';
            }
            el.textContent = 'Uploaded by ' + (data.research_group_name || 'the group') + (ago ? ' ' + ago : '');
        }

        function closeDocumentModal() {
            document.getElementById('docModal').style.display = 'none';
            document.getElementById('previewFrame').src = 'about:blank';
            document.querySelectorAll('.rubric-radio').forEach(r => r.required = false);
        }

        // Validate the rubric in JS (not via hidden HTML5 `required`) so submit never fails silently.
        document.getElementById('modalForm').addEventListener('submit', function (e) {
            if (!document.getElementById('modal_status').value) {
                e.preventDefault();
                document.querySelector('.dv-choices').classList.add('shake');
                setTimeout(function () { document.querySelector('.dv-choices').classList.remove('shake'); }, 500);
                return false;
            }
            try {
                var q = dvQueue(), i = dvIndex();
                if (document.getElementById('modal_status').value === 'Approved' && i >= 0 && q[i + 1]) {
                    sessionStorage.setItem('rd-dv-next', q[i + 1].closest('.ws-card').dataset.upload);
                } else sessionStorage.removeItem('rd-dv-next');
            } catch (x) {}
            // Form 008 was retired; Form 011 (stats) is the only rubric left.
            const box011 = document.getElementById('form011RubricBox');
            const activeBox = (box011 && box011.style.display !== 'none') ? box011 : null;

            if (activeBox) {
                // Group radios by name and ensure each criterion has a selected YES/NO
                const answered = {};
                activeBox.querySelectorAll('.rubric-radio').forEach(r => {
                    if (!(r.name in answered)) answered[r.name] = false;
                    if (r.checked) answered[r.name] = true;
                });
                const firstUnanswered = Object.keys(answered).find(name => !answered[name]);
                if (firstUnanswered) {
                    e.preventDefault();
                    const radio = activeBox.querySelector('.rubric-radio[name="' + firstUnanswered.replace(/"/g, '\\"') + '"]');
                    const section = radio ? radio.closest('.rubric-accordion-content') : null;
                    if (section && !section.classList.contains('open')) {
                        const btn = section.previousElementSibling;
                        if (btn && btn.classList.contains('rubric-accordion-btn')) toggleAccordion(section.id, btn);
                    }
                    if (radio) radio.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    alert('Please complete every rubric criterion (YES / NO) before submitting the evaluation.');
                    return false;
                }
            }
        });

        // Apply the default "Action Needed" filter on load so Approved submissions start in the archive
        filterTableRows();

        // Action Needed stays the landing tab even when it matches nothing — an empty inbox is
        // the honest answer, and the empty state says so and offers Show all submissions.

        // Restore the reviewer's last group-picker choice (collapsed by default).
        (function () {
            let want = null;
            try { want = localStorage.getItem('rd-module-groups'); } catch (e) { /* ignore */ }
            if (want === 'open') toggleGroupSidebar();
        })();

        <?php if ($use_group_workspace): ?>
        wsPaintSeen();

        // Esc backs out one level: the document drawer first, then the workspace.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            const drawer = document.getElementById('docModal');
            if (drawer && drawer.style.display === 'flex') { closeDocumentModal(); return; }
            if (openWorkspaceId !== null) closeGroupWorkspace();
        });

        <?php if ($restore_group): ?>
        // A decision redirected back here — reopen the group the reviewer was working in.
        openGroupWorkspace(<?= $restore_group ?>);
        <?php if ($restore_open_item): ?>
        // "Approve all & review <rubric>": the simple requirements are cleared, so walk straight
        // into the rubric-gated one rather than leaving it as a loose end.
        (function () {
            const btn = document.getElementById('revbtn-<?= $restore_open_item ?>-<?= $restore_group ?>');
            if (btn) setTimeout(function () { btn.click(); }, 260);
        })();
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
        (function () {
            var next = null; try { next = sessionStorage.getItem('rd-dv-next'); sessionStorage.removeItem('rd-dv-next'); } catch (e) {}
            if (!next) return;
            setTimeout(function () {
                var b = document.querySelector('.grp-workspace.active .ws-card[data-upload="' + next + '"] .ws-card-actions .btn-primary');
                if (b) b.click();
            }, 450);
        })();
    </script>
</body>

</html>