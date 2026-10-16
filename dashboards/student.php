<?php
// dashboards/student.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once 'defense_lib.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Determine the effective user_id for data retrieval (leader's ID if current user is a member)
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id; // Use leader_id if exists, otherwise current user_id

// Safely add the 'last_chat_read' column if it doesn't exist yet
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_chat_read TIMESTAMP NULL DEFAULT NULL");
} catch (Exception $e) {
}

// BACKGROUND AJAX HANDLERS FOR CHAT NOTIFICATIONS
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'chat_badge') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_messages WHERE group_identifier = ? AND sender_id != ? AND created_at > (SELECT COALESCE(last_chat_read, '2000-01-01') FROM users WHERE user_id = ?)");
            $stmt->execute([$effective_user_id, $user_id, $user_id]);
            echo (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            echo 0; // Failsafe if the group_messages table hasn't been created yet
        }
        exit();
    }
    if ($_GET['ajax'] === 'mark_chat_read') {
        $pdo->prepare("UPDATE users SET last_chat_read = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$user_id]);
        exit('ok');
    }
}

$group_name = $_SESSION['research_group_name'] ?? 'MCNP-ISAP Research Group';
$account_holder = $_SESSION['username'] ?? 'User';

// Fetch group department and program directly from the effective group leader to ensure consistency for all members
$stmt_leader_info = $pdo->prepare("SELECT department, program FROM users WHERE user_id = ?");
$stmt_leader_info->execute([$effective_user_id]);
$leader_info = $stmt_leader_info->fetch();

$department_raw = !empty($leader_info['department']) ? $leader_info['department'] : ($_SESSION['department'] ?? '');
$program_name_from_session = !empty($leader_info['program']) ? $leader_info['program'] : ($_SESSION['program'] ?? '');

$department_code = $department_raw;
if (strpos($department_raw, 'Medical Colleges') !== false) {
    $department_code = 'MCNP';
} elseif (strpos($department_raw, 'International School') !== false) {
    $department_code = 'ISAP';
}

// Mapping for full program names
$program_display_map = [
    "BS Radiologic Technology" => "Bachelor of Science in Radiologic Technology",
    "BS Nursing" => "Bachelor of Science in Nursing",
    "BS Medical Technology" => "Bachelor of Science in Medical Technology",
    "BS Physical Therapy" => "Bachelor of Science in Physical Therapy",
    "BS Pharmacy" => "Bachelor of Science in Pharmacy",
    "BS Midwifery" => "Bachelor of Science in Midwifery",
    "BS 2-year Dental Technology" => "Two-year Dental Technology",
    "BS 2-year Pharmacy Aide" => "Two-year Pharmacy Aide",
    "BS Caregiving and TVET Course" => "Caregiving and TVET Course",
    "BS Information Technology" => "Bachelor of Science in Information Technology",
    "BS Computer Engineering" => "Bachelor of Science in Computer Engineering",
    "BS Business Administration" => "Bachelor of Science in Business Administration",
    "BS Custom Administration" => "Bachelor of Science in Custom Administration",
    "BS Hospitality Management" => "Bachelor of Science in Hospitality Management",
    "BS Tourism Management" => "Bachelor of Science in Tourism Management",
    "BS Accountancy" => "Bachelor of Science in Accountancy",
    "BS Education" => "Bachelor of Science in Education",
    "BS Science Criminology" => "Bachelor of Science in Criminology",
    "BS Science in Social Work" => "Bachelor of Science in Social Work",
    "BS Secondary Education" => "Bachelor of Secondary Education",
    "BS Science in Psychology" => "Bachelor of Science in Psychology",
    "BS Physical Education" => "Bachelor of Science in Physical Education"
];

$display_program_name = $program_display_map[$program_name_from_session] ?? $program_name_from_session;

// Smart Fallback to prevent blank subtitles
if (empty($department_code) && empty($display_program_name)) {
    $institutional_subtitle = "Institutional Research Group";
} else {
    $institutional_subtitle = trim($department_code . (!empty($department_code) && !empty($display_program_name) ? " • " : "") . $display_program_name);
}

$stmt_pfp = $pdo->prepare("SELECT profile_pic FROM users WHERE user_id = ?");
$stmt_pfp->execute([$user_id]);
$user_pfp = $stmt_pfp->fetchColumn() ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($account_holder);

$message = "";
$msg_type = "success";

// PROGRESS CALCULATION LOGIC
function getSpecificItemProgress($pdo, $userId, $itemId)
{
    $progress = 0;
    $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$userId, $itemId]);
    $latest_status = $stmt->fetchColumn();

    if ($latest_status === 'Approved') {
        $progress = 100;
    } elseif ($latest_status === 'Under Review') {
        $progress = 75;
    } elseif ($latest_status === 'Revision Requested') {
        $progress = 50;
    } elseif ($latest_status === 'Pending') {
        $progress = 25;
    }
    return $progress;
}

function getStageProgress($pdo, $userId, $itemIds)
{
    if (empty($itemIds)) return 0;

    $total_score = 0;
    $max_score = count($itemIds) * 100;

    foreach ($itemIds as $itemId) {
        $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
        $stmt->execute([$userId, $itemId]);
        $latest_status = $stmt->fetchColumn();

        if ($latest_status === 'Approved') {
            $total_score += 100;
        } elseif ($latest_status === 'Under Review') {
            $total_score += 75;
        } elseif ($latest_status === 'Revision Requested') {
            $total_score += 50;
        } elseif ($latest_status === 'Pending') {
            $total_score += 25;
        }
    }

    if ($max_score === 0) return 0;
    return (int)round(($total_score / $max_score) * 100);
}

// Statistics has no single checklist item to track — its real progress lives in
// form_stat_treatment.status (a 7-phase workflow). item_id 3 doesn't exist in checklist_items
// and never receives uploads, so deriving the card off it (old behavior) was permanently stuck at 0%.
function getStatsPhaseStatus($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT status, date_submitted FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'status' => $row['status'] ?? 'Phase 1: Pending Coded Data',
        'date_submitted' => $row['date_submitted'] ?? null,
    ];
}

$proposal_progress = getStageProgress($pdo, $effective_user_id, [17, 11, 12, 13, 14, 15, 16]);
$final_progress    = getStageProgress($pdo, $effective_user_id, [20, 22, 23, 24, 25, 26, 27]);
$stats_phase = getStatsPhaseStatus($pdo, $effective_user_id);
// Statistics runs off form_stat_treatment's 7-phase workflow. Each phase maps to a MONOTONIC ring %
// (progress climbs in workflow order and never regresses — a rejection/revision HOLDS the ring at the
// level already reached and only turns the chip red) PLUS a SEMANTIC chip + footer derived from the
// phase itself, not from the % number. Decoupling matters: a %-threshold chip would paint a
// mid-workflow "Registered" (control number issued, just needs more uploads) red like a revision.
// Fields: [pct, chip-class, chip-icon, chip-label, footer-tone, footer-full (desktop), footer-short (phone)]
$stats_phase_meta = [
    // 2026-10-16 flow: five requirements -> the Secretary issues the number (= treatment starts;
    // payment is on paper, no receipt step). Phase 3/4 are no longer written; Phase 5 is legacy.
    'Phase 1: Pending Coded Data'    => [0,   '',              '',               '',             'tone-default',  'Upload the five requirements',   'Upload files'],
    'Phase 1: Coded Data Review'     => [15,  'chip-review',   'clock',          'Under Review', 'tone-review',   'Requirements under review',      'Under review'],
    'Phase 1: Coded Data Rejected'   => [15,  'chip-revision', 'alert-circle',   'Revision',     'tone-default',  'Fix the requirements sent back', 'Fix files'],
    'Phase 2: Form Download'         => [35,  'chip-pending',  'hash',           'Approved',     'tone-default',  'Get Form 11 at the RDC office',  'Go to RDC'],
    'Phase 3: Control Number Issued' => [85,  'chip-review',   'beaker',         'Processing',   'tone-review',   'Statistical treatment ongoing',  'Processing'],
    'Phase 4: Payment Verification'  => [85,  'chip-review',   'beaker',         'Processing',   'tone-review',   'Statistical treatment ongoing',  'Processing'],
    'Phase 5: Registered'            => [70,  'chip-pending',  'clock',          'In Progress',  'tone-default',  'Upload remaining requirements',  'Upload files'],
    'Phase 6: Revision Requested'    => [85,  'chip-revision', 'alert-circle',   'Revision',     'tone-default',  'Revise a requirement',           'Revise file'],
    'Phase 6: Under Review'          => [85,  'chip-review',   'beaker',         'Reviewed',     'tone-review',   'Revised files under review',     'Under review'],
    'Phase 7: Statistical Treatment' => [85,  'chip-review',   'beaker',         'Processing',   'tone-review',   'Statistical treatment ongoing',  'Processing'],
    'Phase 7: Completed'             => [100, 'chip-approved', 'check-circle-2', 'Completed',    'tone-approved', 'Results released',               'Results released'],
];
$sm = $stats_phase_meta[$stats_phase['status']] ?? $stats_phase_meta['Phase 1: Pending Coded Data'];
$stats_progress     = $sm[0];
$stats_chip_class   = $sm[1];
$stats_chip_icon    = $sm[2];
$stats_chip_label   = $sm[3];
$stats_footer_tone  = $sm[4];
$stats_footer_full  = $sm[5];
$stats_footer_short = $sm[6];
// Ring fill colour follows the chip's semantic state (approved/review), else the neutral default.
$stats_ring_class   = $stats_chip_class === 'chip-approved' ? 'approved' : ($stats_chip_class === 'chip-review' ? 'review' : '');
// PLAGIARISM CARD — phase-driven, like Statistics' (2026-09-29). It used to read item 4's upload
// status, so the moment the office RECEIVED the manuscript (item 4 = Approved) the ring said 100%
// and "Completed" while the group was only at step 2, paying — and $overall_complete fired too.
// Monotonic in workflow order; a send-back holds the level and only the chip turns red.
// [pct, chip class, chip icon, chip label, footer tone, footer full, footer short]
$plag_phase_meta = [
    'Phase 1: Pending Manuscript'   => [0,   '',              '',               '',             'tone-default',  'Upload your final manuscript',      'Upload file'],
    'Phase 1: Manuscript Review'    => [15,  'chip-review',   'clock',          'Under Review', 'tone-review',   'Manuscript under review',           'Under review'],
    'Phase 1: Manuscript Returned'  => [15,  'chip-revision', 'alert-circle',   'Revision',     'tone-default',  'Re-send your manuscript',           'Re-send file'],
    'Phase 2: Form Download'        => [30,  'chip-pending',  'clock',          'Pending',      'tone-default',  'Pay the scan fee and upload proof', 'Submit payment'],
    'Phase 3: Payment Verification' => [50,  'chip-review',   'clock',          'Under Review', 'tone-review',   'Payment under review',              'Under review'],
    'Phase 4: Registered'           => [70,  'chip-review',   'beaker',         'Processing',   'tone-review',   'Turnitin check running',            'Processing'],
    'Phase 5: Revision Requested'   => [70,  'chip-revision', 'alert-circle',   'Revision',     'tone-default',  'Revise and re-send your manuscript', 'Revise file'],
    'Phase 5: Cleared'              => [100, 'chip-approved', 'check-circle-2', 'Completed',    'tone-approved', 'Cleared — ready to bookbind',       'Cleared'],
];
require_once __DIR__ . '/plag_pipeline_lib.php';   // pp_round_start() — a revision is a new, paid round
$plag_status_now = $pdo->prepare("SELECT status FROM plagiarism_checks WHERE user_id = ?");
$plag_status_now->execute([$effective_user_id]);
$plag_status_now = $plag_status_now->fetchColumn() ?: 'Phase 1: Pending Manuscript';
$pm = $plag_phase_meta[$plag_status_now] ?? $plag_phase_meta['Phase 1: Pending Manuscript'];
$plag_progress      = $pm[0];
// A paid-again round starts at "manuscript" again, but the ring never goes backwards (the same
// hold-steady rule as Statistics): it stays where the last scan got them; the chip says the rest.
if ($plag_progress < 100 && pp_round_start($pdo, (int)$effective_user_id) !== null) $plag_progress = max($plag_progress, 70);
$plag_chip_class    = $pm[1];
$plag_chip_icon     = $pm[2];
$plag_chip_label    = $pm[3];
$plag_footer_tone   = $pm[4];
$plag_footer_full   = $pm[5];
$plag_footer_short  = $pm[6];
$plag_ring_class    = $plag_chip_class === 'chip-approved' ? 'approved' : ($plag_chip_class === 'chip-review' ? 'review' : '');

// Fetch dynamic asset validation status rows
$stmt = $pdo->prepare("SELECT item_id, verification_status, remarks, file_path, uploaded_at FROM uploads WHERE user_id = ? ORDER BY uploaded_at ASC");
$stmt->execute([$effective_user_id]);
$uploads = $stmt->fetchAll();

// Latest upload per item_id — $uploads is ASC by time, so later entries overwrite earlier ones (latest wins)
$latest_by_item = [];
foreach ($uploads as $up) {
    $latest_by_item[$up['item_id']] = $up;
    if ($up['item_id'] == 4) {
        $plag_remarks = $up['remarks'] ?? '';
    }
}

// MILESTONE CARD DATA — req counts, last-updated, and "what's next" text for the 4 dashboard cards
function formatRelativeDate($timestamp)
{
    if (!$timestamp) return '';
    $diff = time() - $timestamp;
    if ($diff < 3600) return max(1, (int)round($diff / 60)) . 'm ago';
    if ($diff < 86400) return (int)round($diff / 3600) . 'h ago';
    if ($diff < 604800) return (int)round($diff / 86400) . 'd ago';
    return date('M j', $timestamp);
}

// Footer text comes in a "full" (desktop/tablet, room for the specific item name) and a "short"
// (phone, always a couple words so the pill never wraps or gets ellipsis-cut) version.
function getStageCardMeta($pdo, $latestByItem, $itemIds, $cascadedIds = [])
{
    $lastTs = 0;
    $revItem = $noUploadItem = null;
    $actionableIds = array_diff($itemIds, $cascadedIds);

    $itemNames = [];
    if ($actionableIds) {
        $in = implode(',', array_map('intval', $actionableIds));
        $rows = $pdo->query("SELECT item_id, item_name FROM checklist_items WHERE item_id IN ($in)")->fetchAll();
        foreach ($rows as $r) $itemNames[$r['item_id']] = $r['item_name'];
    }

    foreach ($itemIds as $id) {
        $u = $latestByItem[$id] ?? null;
        $status = $u['verification_status'] ?? 'No Upload';
        if (!empty($u['uploaded_at'])) $lastTs = max($lastTs, strtotime($u['uploaded_at']));

        if (in_array($id, $cascadedIds)) continue;
        if ($status === 'Revision Requested' && !$revItem) $revItem = $itemNames[$id] ?? 'a requirement';
        if ($status === 'No Upload' && !$noUploadItem) $noUploadItem = $itemNames[$id] ?? 'the next requirement';
    }

    if ($revItem) {
        $nextFull = 'Revise: ' . $revItem;
        $nextShort = 'Revise a file';
    } elseif ($noUploadItem) {
        $nextFull = 'Upload ' . $noUploadItem;
        $nextShort = 'Upload next file';
    } else {
        $nextFull = $nextShort = 'Awaiting review';
    }

    return ['updated' => formatRelativeDate($lastTs), 'next_full' => $nextFull, 'next_short' => $nextShort];
}

$proposal_meta = getStageCardMeta($pdo, $latest_by_item, [17, 11, 12, 13, 14, 15, 16], [13, 15, 16]);
$final_meta    = getStageCardMeta($pdo, $latest_by_item, [20, 22, 23, 24, 25, 26, 27]);

// Statistics card: phase status (form_stat_treatment) drives the ring/chip and the "updated" meta date.
// Chip/footer/ring values are all computed above from $stats_phase_meta (monotonic % + semantic state).
$stats_updated = formatRelativeDate($stats_phase['date_submitted'] ? strtotime($stats_phase['date_submitted']) : 0);

// Plagiarism card: item 4 drives the ring/chip and the "updated" meta date
$plag_updated = formatRelativeDate(!empty($latest_by_item[4]['uploaded_at']) ? strtotime($latest_by_item[4]['uploaded_at']) : 0);
// The "what next" line comes from $plag_phase_meta (see above), like Statistics'.
$plag_next_full  = $plag_footer_full;
$plag_next_short = $plag_footer_short;

// ── STAGE PEEK DATA (desktop hover-preview on the 4 milestone cards) ──────────────────────
// Read-only projection of data already fetched above ($latest_by_item, $stats_progress, the
// *_meta arrays) into one JSON blob the peek panel renders client-side. No new workflow logic:
// it mirrors what each module already shows, so the preview can never disagree with the module.
$peek_item_names = [];
$peek_rows = $pdo->query(
    "SELECT item_id, item_name FROM checklist_items WHERE item_id IN (4,11,12,13,14,15,16,17,20,22,23,24,25,26,27,40)"
)->fetchAll();
foreach ($peek_rows as $r) $peek_item_names[(int)$r['item_id']] = $r['item_name'];

// uploads.verification_status → student-facing label + tone class. 'Pending' reads as "Submitted"
// here: to the student the file IS in, it just hasn't been picked up by a reviewer yet.
$peek_status_map = [
    'Approved'           => ['Approved',      'ok'],
    'Under Review'       => ['Under review',  'review'],
    'Revision Requested' => ['Needs revision', 'revision'],
    'Pending'            => ['Submitted',     'pending'],
];

function peekItemRow($itemId, $names, $latestByItem, $statusMap, $note = null)
{
    $status = $latestByItem[$itemId]['verification_status'] ?? null;
    [$label, $tone] = $statusMap[$status] ?? ['Not uploaded', 'empty'];
    return [
        'name' => $names[$itemId] ?? ('Requirement #' . $itemId),
        'label' => $label,
        'tone' => $tone,
        'note' => $note,
    ];
}

// Proposal display order mirrors module_proposal.php's ORDER BY (the Form 002 application 17
// leads, then the adviser pair 11/12, cascaded 13/15/16
// tucked under their parent 14) so the peek lists requirements in the same sequence as the module.
$peek_proposal = [];
foreach ([17, 11, 12, 14] as $id) $peek_proposal[] = peekItemRow($id, $peek_item_names, $latest_by_item, $peek_status_map);
foreach ([13, 15, 16] as $id) $peek_proposal[] = peekItemRow($id, $peek_item_names, $latest_by_item, $peek_status_map, 'Follows Capsule Proposal');

// The Final card's front stage (apply -> schedule) comes before any requirement, so the peek
// leads with it. While it is unresolved the requirements below read "Locked" rather than
// "Not uploaded", which would otherwise invite a student to try something they cannot do yet.
$df_booking_peek  = df_get_booking($pdo, $effective_user_id, 'final');
// Pass whether the requirements are cleared: with no booking, that is what decides between
// "finish your requirements" and "waiting for your date" (the default would claim cleared).
$df_stage_peek    = df_stage_meta($df_booking_peek, 'final', df_phase1_cleared($pdo, $effective_user_id, 'final'));
$df_unlocked_peek = df_requirements_unlocked($df_booking_peek);

// Proposal runs the other way round: requirements first, then the defense application. Its
// stage is what GATES the rest of the journey — the Minutes that Final asks for only exists
// once the proposal defense has been scheduled and heard.
$prop_booking_peek = df_get_booking($pdo, $effective_user_id, 'proposal');
$prop_stage_peek   = df_stage_meta($prop_booking_peek, 'proposal', df_phase1_cleared($pdo, $effective_user_id, 'proposal'));
$prop_scheduled    = df_requirements_unlocked($prop_booking_peek);

// Stage gates for the four cards, in the order the office actually works: Proposal -> Statistics
// -> Final -> Plagiarism. Proposal is always open — it is where everyone starts.
$unlock_proposal = true;
// Statistics opens once the proposal is COMPLETE overall. A Scheduled booking is that proof: the
// Focal Person can only endorse a group whose requirements are all approved, so Scheduled implies
// cleared. Data collection begins here.
$unlock_stats    = $prop_scheduled;
// FINAL WAITS FOR STATISTICS (2026-09-25, confirmed with the office). You cannot defend Chapter 4
// without the numbers, so the face-to-face final defense must come after the statistician has
// released the results — 'Phase 7: Completed' is the one terminal state of that ladder
// (form_stat_treatment.status; see $stats_phase_meta, where it is the only 100%).
$stats_complete  = ($stats_phase['status'] === 'Phase 7: Completed');
$unlock_final    = $stats_complete;

// The final defense happens face to face, so the system cannot tell it went ahead. The research
// office says so from the Manuscript screen, and THAT is what opens the Plagiarism card.
$manuscript_released = df_manuscript_released($pdo, $effective_user_id);
// Which plagiarism phase they are in, so the billboard can keep pace with them.
$plag_phase_peek = $pdo->prepare("SELECT status FROM plagiarism_checks WHERE user_id = ?");
$plag_phase_peek->execute([$effective_user_id]);
$plag_phase_peek = $plag_phase_peek->fetchColumn() ?: 'Phase 1: Pending Manuscript';
// Has each phase cleared its own phase 1? The billboard promotes "book your defense" the
// moment a group earns it, not only once they have already applied.
$prop_p1_peek  = df_phase1_cleared($pdo, $effective_user_id, 'proposal');
$final_p1_peek = df_phase1_cleared($pdo, $effective_user_id, 'final');
// Turnitin runs on the finished manuscript, which is a post-final-defense step (poster 3).
// Having defended is necessary but not sufficient — the office has to release them.
$unlock_plag     = ($manuscript_released !== null);

$lock_reason_next = 'Opens once your proposal defense is scheduled';
// Final's wait is a different one and must say so, or a group with a scheduled proposal reads
// "opens once your proposal defense is scheduled" on a card that is already past that.
$lock_reason_final = 'Opens once your statistics results are released';
// Two different waits, so two different explanations: before the defense there is nothing to do
// yet; after it, they are waiting on the office rather than on themselves.
$lock_reason_plag = df_defense_done($df_booking_peek)
    ? 'Waiting for the research office to open this'
    : 'Opens after your final defense';

// Final reads in journey order: the one phase-1 requirement, then the booking stage, then the
// six documents that travel with it. The phase-2 rows stay locked until the office clears
// Form 002, because that approval is what opens the panel they live in.
$peek_final = [peekItemRow(20, $peek_item_names, $latest_by_item, $peek_status_map)];
$final_p1_cleared = (($latest_by_item[20]['verification_status'] ?? '') === 'Approved');
$peek_final[] = [
    'name'  => 'Application & Schedule',
    'label' => $final_p1_cleared ? $df_stage_peek['label'] : 'Locked',
    'tone'  => $final_p1_cleared ? $df_stage_peek['tone'] : 'empty',
    'note'  => $df_unlocked_peek && !empty($df_booking_peek['defense_date'])
        ? date('M j, Y', strtotime($df_booking_peek['defense_date']))
        . ($df_booking_peek['slot_no'] ? ' · Slot ' . (int)$df_booking_peek['slot_no'] : '')
        : ($final_p1_cleared ? null : 'Opens once Form 002 is approved'),
];
foreach ([22, 23, 24, 25, 26, 27] as $id) {
    $row = peekItemRow($id, $peek_item_names, $latest_by_item, $peek_status_map);
    if (!$final_p1_cleared) {
        $row['label'] = 'Locked';
        $row['tone']  = 'empty';
        $row['note']  = 'Opens once Form 002 is approved';
    }
    $peek_final[] = $row;
}

// Proposal's own stage sits at the END of its peek: unlike Final, it comes after the
// requirements rather than before them.
$peek_proposal[] = [
    'name'  => 'Defense Schedule',
    'label' => $proposal_progress >= 100 ? $prop_stage_peek['label'] : 'Locked',
    'tone'  => $proposal_progress >= 100 ? $prop_stage_peek['tone'] : 'empty',
    'note'  => ($prop_scheduled && !empty($prop_booking_peek['defense_date']))
        ? date('M j, Y', strtotime($prop_booking_peek['defense_date']))
        . ($prop_booking_peek['slot_no'] ? ' · Slot ' . (int)$prop_booking_peek['slot_no'] : '')
        : ($proposal_progress >= 100 ? null : 'Opens at 100%'),
];

// A locked card still shows what is inside, but every row says so rather than inviting a
// student to try something the system will refuse.
$lockPeek = function (array $rows, string $why): array {
    foreach ($rows as &$r) {
        $r['label'] = 'Locked';
        $r['tone']  = 'empty';
        $r['note']  = $why;
    }
    return $rows;
};
if (!$unlock_final) $peek_final = $lockPeek($peek_final, $lock_reason_final);

// Plagiarism runs off plagiarism_checks' phase ladder now, same shape as Statistics: each step is
// done/current against the SAME % the ring uses, so the peek and the card cannot disagree.
$peek_plag = [];
$peek_plag_current_used = false;
foreach ([[15, 'Manuscript sent'], [30, 'Manuscript received'], [50, 'Payment submitted'], [70, 'Registered'], [100, 'Turnitin cleared']] as [$pct, $name]) {
    if ($plag_progress >= $pct) {
        $peek_plag[] = ['name' => $name, 'label' => 'Done', 'tone' => 'ok', 'note' => null];
    } elseif (!$peek_plag_current_used) {
        $peek_plag_current_used = true;
        $peek_plag[] = ['name' => $name, 'label' => $plag_chip_label ?: 'Not started',
                        'tone' => $plag_chip_class === 'chip-revision' ? 'revision' : ($plag_chip_class === 'chip-review' ? 'review' : ($plag_chip_class ? 'pending' : 'empty')), 'note' => null];
    } else {
        $peek_plag[] = ['name' => $name, 'label' => 'Not started', 'tone' => 'empty', 'note' => null];
    }
}
// Statistics has no checklist rows — it runs off form_stat_treatment's phase ladder. Steps are
// marked done/current against the SAME monotonic % the ring uses ($stats_progress), so the peek
// and the card can never disagree. See $stats_phase_meta for where these pcts come from.
$peek_stats_ladder = [
    [15,  'Coded data submitted'],
    [30,  'Form download'],
    [45,  'Payment verification'],
    [55,  'Registered'],
    [80,  'Requirements review'],
    [92,  'Statistical treatment'],
    [100, 'Results released'],
];
$peek_stats = [];
$peek_stats_current_used = false;
foreach ($peek_stats_ladder as [$pct, $name]) {
    if ($stats_progress >= $pct) {
        $row = ['name' => $name, 'label' => 'Done', 'tone' => 'ok', 'note' => null];
    } elseif (!$peek_stats_current_used) {
        $peek_stats_current_used = true;
        // Label the one in-flight step with the real phase text, not a generic "in progress"
        $row = ['name' => $name, 'label' => $stats_chip_label ?: 'In progress', 'tone' => $stats_chip_class === 'chip-revision' ? 'revision' : ($stats_chip_class === 'chip-review' ? 'review' : 'pending'), 'note' => null];
    } else {
        $row = ['name' => $name, 'label' => 'Not started', 'tone' => 'empty', 'note' => null];
    }
    $peek_stats[] = $row;
}
if (!$unlock_stats) $peek_stats = $lockPeek($peek_stats, $lock_reason_next);
if (!$unlock_plag)  $peek_plag  = $lockPeek($peek_plag, $lock_reason_plag);

// Chip + ring tone mirror the small card's own logic (see the .stage-card-tab markup) so the
// blown-up preview always reads the same state as the card it grew out of.
function peekChip($pct)
{
    if ($pct == 100) return ['chip-approved', 'Completed'];
    if ($pct >= 75)  return ['chip-review',   'Reviewed'];
    if ($pct >= 50)  return ['chip-revision', 'Revision'];
    if ($pct > 0)    return ['chip-pending',  'Pending'];
    return ['', ''];
}
function peekRingTone($pct)
{
    if ($pct >= 100) return 'approved';
    if ($pct >= 75)  return 'review';
    return '';
}

$stage_peek_data = [
    'proposal' => [
        'num' => 1, 'title' => 'Proposal Defense', 'open' => 'zoom-proposal', 'url' => 'module_proposal.php',
        'chip' => peekChip($proposal_progress)[0], 'chipLabel' => peekChip($proposal_progress)[1],
        'ring' => peekRingTone($proposal_progress),
        'pct' => $proposal_progress, 'updated' => $proposal_meta['updated'],
        'next' => $proposal_progress >= 100 ? 'Download clearing form' : $proposal_meta['next_full'],
        'heading' => 'Requirements', 'items' => $peek_proposal,
        'locked' => !$unlock_proposal, 'lockWhy' => '',
    ],
    'stats' => [
        'num' => 2, 'title' => 'Statistics Review', 'open' => 'zoom-stats', 'url' => 'module_statistics.php',
        // Stats chip/ring are phase-driven, not %-threshold-driven — reuse the card's own values.
        'chip' => $stats_chip_class, 'chipLabel' => $stats_chip_label,
        'ring' => $stats_ring_class,
        'pct' => $stats_progress, 'updated' => $stats_updated,
        'next' => $stats_footer_full,
        'heading' => 'Phases', 'items' => $peek_stats,
        'locked' => !$unlock_stats, 'lockWhy' => $lock_reason_next,
    ],
    'final' => [
        'num' => 3, 'title' => 'Final Defense', 'open' => 'zoom-final', 'url' => 'module_final.php',
        'chip' => peekChip($final_progress)[0], 'chipLabel' => peekChip($final_progress)[1],
        'ring' => peekRingTone($final_progress),
        'pct' => $final_progress, 'updated' => $final_meta['updated'],
        'next' => !$df_unlocked_peek
            ? $df_stage_peek['headline']
            : ($final_progress >= 100 ? 'Download approval form' : $final_meta['next_full']),
        'heading' => 'Requirements', 'items' => $peek_final,
        'locked' => !$unlock_final, 'lockWhy' => $lock_reason_final,
    ],
    'plag' => [
        'num' => 4, 'title' => 'Plagiarism Test', 'open' => 'zoom-plag', 'url' => 'module_plagiarism.php',
        'chip' => $plag_chip_class, 'chipLabel' => $plag_chip_label,
        'ring' => $plag_ring_class,
        'pct' => $plag_progress, 'updated' => $plag_updated,
        'next' => $plag_next_full,
        'heading' => 'Phases', 'items' => $peek_plag,
        'locked' => !$unlock_plag, 'lockWhy' => $lock_reason_plag,
    ],
];

// Fetch Announcements
try {
    $stmt_ann = $pdo->query("SELECT * FROM announcements WHERE expires_at IS NULL OR expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $announcements = $stmt_ann->fetchAll();
} catch (Exception $e) {
    $announcements = [];
}

// Fetch Action Required (notifications or warning logs)
try {
    $stmt_act = $pdo->prepare("SELECT title, message as description, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 4");
    $stmt_act->execute([$user_id]);
    $action_items = $stmt_act->fetchAll();

    if (empty($action_items)) {
        $stmt_act2 = $pdo->prepare("SELECT title, description, created_at, status_type FROM activity_logs WHERE user_id = ? AND status_type IN ('warning', 'error', 'success') ORDER BY created_at DESC LIMIT 4");
        $stmt_act2->execute([$user_id]);
        $action_items = $stmt_act2->fetchAll();
    }
} catch (Exception $e) {
    $action_items = [];
}

$act_stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? AND status_type = 'info' ORDER BY created_at DESC LIMIT 10");
$act_stmt->execute([$user_id]);
$recent_activities = $act_stmt->fetchAll();

$cal_stmt = $pdo->query("SELECT title, description, event_date FROM calendar_events ORDER BY event_date ASC");
$calendar_events = $cal_stmt->fetchAll();

// Fold the defense calendar into the mini-calendar. The renderer derives busy/available by
// keyword-matching event titles, so matching that vocabulary colours the dates with no change
// to the calendar JS at all. Only exceptions and genuinely full days are worth a marker —
// under the open-by-default model every other working day still has slots.
// The group's live booking is whichever defense is in front of them right now.
$df_mini_booking = $prop_booking_peek && !in_array($prop_booking_peek['status'], ['Scheduled', 'Cancelled', 'Rejected'], true)
    ? $prop_booking_peek
    : ($df_booking_peek ?: $prop_booking_peek);
foreach (['proposal', 'final'] as $df_ph) {
    foreach (df_blackouts($pdo, $df_ph, df_today(), df_window_end()) as $df_date => $df_bo) {
        $calendar_events[] = [
            'title' => 'Research office closed — not available',
            'description' => $df_bo['reason'] ?: 'The research office is closed on this date.',
            'event_date' => $df_date,
        ];
    }
}
// The published defense schedule (2026-10-14, dashboards/schedule_lib.php): which ROOMS of the group's own
// institution defend on which day — the mini-calendar marks those days and says who, instead of the old
// "available / full" colouring of the retired students-pick-a-date flow. Sections only, never names.
require_once 'schedule_lib.php';
$df_mini_school = df_user_school($pdo, $effective_user_id);
$mini_inst = rd_institution_of((string)$df_mini_school);
$mini_defense = [];   // date => ['rooms' => ['BSIT 4-A (Proposal)', ...], 'mine' => bool]
foreach (sch_calendar_items(sch_published($pdo, date('Y-m-01', strtotime(df_today() . ' -1 month')), df_window_end())) as $sch) {
    if ($sch['school'] !== $mini_inst) continue;
    $mini_defense[$sch['date']]['rooms'][] = $sch['short'] . ($sch['phase'] === 'final' ? ' (Final)' : ' (Proposal)');
}
if ($df_mini_booking && !empty($df_mini_booking['defense_date'])
    && in_array($df_mini_booking['status'], ['Date Selected', 'Scheduled'], true)) {
    if ($df_mini_booking['status'] === 'Scheduled') {
        $mini_defense[$df_mini_booking['defense_date']]['mine'] = true;      // published: the green ring
    } else {
        $calendar_events[] = [                                                // proposed: can still move
            'title' => 'Your proposed defense date',
            'description' => 'Waiting for the research office to publish it.',
            'event_date' => $df_mini_booking['defense_date'],
        ];
    }
}

// Notification bell badge: epoch timestamps of the review-outcome logs shown in the drawer.
// JS compares these against a per-browser "last read" marker to render the unread count.
$notif_ts_stmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE user_id = ? AND status_type IN ('success','warning') ORDER BY created_at DESC LIMIT 50");
$notif_ts_stmt->execute([$user_id]);
$notif_times = array_map(fn($r) => strtotime($r['created_at']), $notif_ts_stmt->fetchAll(PDO::FETCH_ASSOC));

$overall_complete = ($proposal_progress === 100 && $final_progress === 100 && $stats_progress === 100 && $plag_progress === 100);

// RANK LOGIC
$overall_avg = ($proposal_progress + $final_progress + $stats_progress + $plag_progress) / 4;
if ($overall_avg >= 100) {
    $rank_title = "Legend";
    $rank_color = "#059669";
} elseif ($overall_avg >= 80) {
    $rank_title = "Epic";
    $rank_color = "#d97706";
} elseif ($overall_avg >= 60) {
    $rank_title = "Grandmaster";
    $rank_color = "#2563eb";
} elseif ($overall_avg >= 40) {
    $rank_title = "Master";
    $rank_color = "#8b5cf6";
} elseif ($overall_avg >= 20) {
    $rank_title = "Elite";
    $rank_color = "#14b8a6";
} else {
    $rank_title = "Warrior";
    $rank_color = "#6b7280";
}

// Dynamic theme properties based on student department selection
$theme_accent = '#7c3aed';
$theme_dark = '#6d28d9';
$theme_glow = 'rgba(124, 58, 237, 0.12)';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Workspace | MCNP-ISAP Institutional Portal</title>
    <!-- Fonts - Inter as primary, Cinzel on brand only, JetBrains Mono for stats & times -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest">
    </script>
    <style>
        /* COLOR PALETTES */
        body.theme-blue {
            --bg-canvas: #f3f7fa;
            --bg-card: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-subtle: #e2e8f0;
            --active-accent: #1e40af;
            --active-accent-dark: #172554;
            --active-glow: rgba(30, 64, 175, 0.12);
            --mcnp-teal: #1e40af;
            --eagle-gold: #d97706;
        }

        body.theme-red {
            --bg-canvas: #fef2f2;
            --bg-card: #ffffff;
            --text-primary: #450a0a;
            --text-secondary: #7f1d1d;
            --text-muted: #ca8a04;
            --border-subtle: #fee2e2;
            --active-accent: #b91c1c;
            --active-accent-dark: #7f1d1d;
            --active-glow: rgba(185, 28, 28, 0.12);
            --mcnp-teal: #b91c1c;
            --eagle-gold: #d97706;
        }

        body.theme-dark {
            --bg-canvas: #090d12;
            --bg-card: #151c24;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-subtle: #202b36;
            --active-accent: #38bdf8;
            --active-accent-dark: #0284c7;
            --active-glow: rgba(56, 189, 248, 0.15);
            --mcnp-teal: #38bdf8;
            --eagle-gold: #fbbf24;
        }

        body.theme-pink,
        body.theme-rose {
            --bg-canvas: #fde8f5;
            --bg-card: #ffffff;
            --text-primary: #4c2346;
            --text-secondary: #7a3870;
            --text-muted: #9f628d;
            --border-subtle: #f3c7dc;
            --active-accent: #c56ba8;
            --active-accent-dark: #ac5e94;
            --active-glow: rgba(197, 107, 168, 0.12);
            --mcnp-teal: #c56ba8;
            --eagle-gold: #d97706;
        }

        body.theme-green {
            --bg-canvas: #e8f6ea;
            --bg-card: #ffffff;
            --text-primary: #2f4a33;
            --text-secondary: #4a7550;
            --text-muted: #6d8b75;
            --border-subtle: #c9dec9;
            --active-accent: #4a9e7b;
            --active-accent-dark: #3a8565;
            --active-glow: rgba(74, 158, 123, 0.12);
            --mcnp-teal: #4a9e7b;
            --eagle-gold: #d97706;
        }

        body.theme-purple,
        body.theme-lavender {
            --bg-canvas: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #4c1d95;
            --text-secondary: #5b21b6;
            --text-muted: #9c9284;
            --border-subtle: #ddd6fe;
            --active-accent: #6d28d9;
            --active-accent-dark: #4c1d95;
            --active-glow: rgba(109, 40, 217, 0.12);
            --mcnp-teal: #6d28d9;
            --eagle-gold: #d97706;
        }

        body.theme-orange,
        body.theme-amber {
            --bg-canvas: #fffbeb;
            --bg-card: #ffffff;
            --text-primary: #78350f;
            --text-secondary: #92400e;
            --text-muted: #9c9284;
            --border-subtle: #fde68a;
            --active-accent: #b45309;
            --active-accent-dark: #78350f;
            --active-glow: rgba(180, 83, 9, 0.12);
            --mcnp-teal: #b45309;
            --eagle-gold: #d97706;
        }

        :root {
            /* Default Base Fallbacks */
            --bg-canvas: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-subtle: #e2e8f0;

            --active-accent: <?= $theme_accent ?>;
            --active-accent-dark: <?= $theme_dark ?>;
            --active-glow: <?= $theme_glow ?>;
            --mcnp-teal: <?= $theme_accent ?>;
            --eagle-gold: #d97706;

            /* Status Colors */
            --color-approved: #10b981;
            --color-review: #f59e0b;
            --color-revision: #ef4444;
            --color-pending: #64748b;

            --radius-app: 24px;
            --radius-interactive: 16px;

            /* ONE SHADOW SYSTEM */
            --shadow-sm: 0 2px 8px -1px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px -4px rgba(15, 23, 42, 0.08);
            --shadow-lg: 0 16px 32px -8px rgba(15, 23, 42, 0.12);
            --shadow-xl: 0 24px 60px -12px rgba(15, 23, 42, 0.18);

            /* ONE EASING SYSTEM */
            --spring: cubic-bezier(0.175, 0.885, 0.32, 1.275);
            --smooth: cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            display: flex;
            padding: 24px;
            position: relative;
            transition: background-color 0.5s var(--smooth), color 0.4s var(--smooth);
        }

        /* Clean Apple-style solid canvas — card auras stay on milestone cards only */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: transparent;
            transition: opacity 0.6s var(--smooth);
        }

        /* Dark theme override — deeper, richer version */
        body.theme-dark::before {
            background:
                radial-gradient(ellipse 80% 60% at -5% -10%, rgba(139, 92, 246, 0.18) 0%, transparent 55%),
                radial-gradient(ellipse 65% 55% at 110% 5%, rgba(168, 85, 247, 0.12) 0%, transparent 55%),
                radial-gradient(ellipse 70% 50% at 50% -5%, rgba(67, 56, 202, 0.12) 0%, transparent 50%),
                radial-gradient(ellipse 55% 40% at 5% 90%, rgba(236, 72, 153, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse 60% 45% at 95% 95%, rgba(109, 40, 217, 0.10) 0%, transparent 50%),
                radial-gradient(ellipse 40% 30% at 50% 110%, rgba(190, 24, 93, 0.06) 0%, transparent 50%);
        }

        /* Red/ISAP theme override */
        body.theme-red::before {
            background:
                radial-gradient(ellipse 80% 60% at -5% -10%, rgba(251, 113, 133, 0.16) 0%, transparent 55%),
                radial-gradient(ellipse 65% 55% at 110% 5%, rgba(244, 114, 182, 0.12) 0%, transparent 55%),
                radial-gradient(ellipse 70% 50% at 50% -5%, rgba(220, 38, 38, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse 55% 40% at 5% 90%, rgba(253, 164, 175, 0.10) 0%, transparent 50%),
                radial-gradient(ellipse 60% 45% at 95% 95%, rgba(185, 28, 28, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse 40% 30% at 50% 110%, rgba(236, 72, 153, 0.06) 0%, transparent 50%);
        }

        /* App Frame - Frameless aesthetic but elevated */
        .app-dashboard-frame {
            width: 100%;
            max-width: 1680px;
            height: 100%;
            margin: 0 auto;
            display: flex;
            position: relative;
            gap: 24px;
            z-index: 10;
        }

        /* Dock selection tokens — the tray behind a selected item is neutral gray on
           purpose; the accent colour belongs to the glyph, never the whole button. */
        :root {
            --dock-tray: rgba(15, 23, 42, 0.06);
            --dock-tray-hover: rgba(15, 23, 42, 0.035);
        }

        body.theme-dark {
            --dock-tray: rgba(255, 255, 255, 0.10);
            --dock-tray-hover: rgba(255, 255, 255, 0.055);
        }

        /* FLUTTER-STYLE BOTTOM NAVIGATION DOCK */
        .app-dock-navigation {
            position: fixed;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%);
            width: auto;
            height: 68px;
            background: rgba(255, 255, 255, 0.76);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 1px solid rgba(15, 23, 42, 0.035);
            border-radius: 26px;
            box-shadow: 0 10px 30px -18px rgba(15, 23, 42, 0.14), 0 1px 2px rgba(15, 23, 42, 0.03), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            padding: 8px 20px;
            z-index: 500;
            transition: transform 0.4s var(--spring), background 0.4s var(--smooth), box-shadow 0.4s var(--smooth), bottom 0.4s var(--smooth);
            gap: 14px;
        }

        body.theme-dark .app-dock-navigation {
            background: rgba(21, 28, 36, 0.72);
            border-color: rgba(255, 255, 255, 0.055);
            box-shadow: 0 10px 30px -18px rgba(0, 0, 0, 0.55), 0 1px 2px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        /* macOS Window Traffic Lights */
        .dock-traffic-lights {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .traffic-light {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6px;
            font-weight: 900;
            color: rgba(0, 0, 0, 0.5);
            transition: all 0.2s;
        }

        .traffic-light::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            opacity: 0;
            background-color: rgba(0, 0, 0, 0.2);
            transition: opacity 0.2s;
        }

        .traffic-light:hover::before {
            opacity: 1;
        }

        .traffic-light.red {
            background-color: #ff5f56;
        }

        .traffic-light.yellow {
            background-color: #ffbd2e;
        }

        .traffic-light.green {
            background-color: #27c93f;
        }

        /* User Profile Trigger on Dock */
        .dock-user-profile {
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .dock-avatar-wrapper {
            position: relative;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            padding: 2px;
            border: 2px solid var(--active-accent);
            transition: transform 0.3s var(--spring);
        }

        .dock-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            background-color: #f1f5f9;
        }

        .dock-user-profile:hover .dock-avatar-wrapper {
            transform: scale(1.08) rotate(4deg);
        }

        .dock-rank-badge {
            position: absolute;
            bottom: -4px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--active-accent);
            color: white;
            font-size: 7px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1.5px solid var(--bg-card);
            white-space: nowrap;
            box-shadow: var(--shadow-sm);
        }

        /* Navigation List - Tactile Zooming Buttons */
        .dock-menu-list {
            list-style: none;
            display: flex;
            flex-direction: row;
            gap: 12px;
            align-items: center;
            width: auto;
        }

        .dock-item {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .dock-btn {
            background: transparent;
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            opacity: 1;
            position: relative;
            transition: transform 0.25s var(--spring), color 0.25s var(--smooth), background-color 0.25s var(--smooth);
            z-index: 10;
        }

        .dock-btn svg {
            width: 24px;
            height: 24px;
            stroke-width: 2px;
            transition: transform 0.25s var(--spring);
        }

        .dock-btn:hover {
            color: #0f172a;
            background-color: var(--dock-tray-hover);
            transform: scale(1.06) translateY(-2px);
        }

        body.theme-dark .dock-btn {
            color: #94a3b8;
        }

        body.theme-dark .dock-btn:hover {
            color: #f8fafc;
            background-color: var(--dock-tray-hover);
        }

        .dock-btn:hover svg {
            transform: scale(1.05);
        }

        /* Active — neutral gray tray, accent lives on the glyph only */
        .dock-btn.active {
            color: var(--active-accent);
            background-color: var(--dock-tray);
            box-shadow: none;
        }

        .dock-btn.active svg {
            stroke-width: 2.4px;
        }

        .dock-btn.active:hover {
            background-color: var(--dock-tray);
            color: var(--active-accent);
        }

        .dock-btn.active::after {
            display: none;
        }

        /* Logout — neutral gray, red on hover */
        .dock-btn.dock-btn-logout {
            color: #64748b;
        }

        body.theme-dark .dock-btn.dock-btn-logout {
            color: #94a3b8;
        }

        .dock-btn.dock-btn-logout:hover {
            color: #ef4444;
            background-color: rgba(239, 68, 68, 0.08);
        }

        /* Crisp Floating Tooltips */
        .dock-tooltip {
            position: absolute;
            left: 50%;
            top: -42px;
            transform: translateY(10px) translateX(-50%);
            background-color: var(--text-primary);
            color: var(--bg-card);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s var(--smooth), transform 0.2s var(--smooth);
            z-index: 1000;
        }

        .dock-item:hover .dock-tooltip {
            opacity: 1;
            transform: translateY(0) translateX(-50%);
        }

        /* LIVE REAL-TIME CHAT BADGES */
        .dock-chat-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background-color: var(--color-revision);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 1.5px solid var(--bg-card);
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
            pointer-events: none;
            z-index: 10;
        }

        .dock-divider {
            width: 1px;
            height: 24px;
            background-color: var(--border-subtle);
            flex-shrink: 0;
        }

        .dock-brand-label {
            font-family: 'Cinzel', serif;
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 800;
            letter-spacing: 1px;
            flex-shrink: 0;
            user-select: none;
        }

        /* MAIN CONTENT OUTER CONTAINER */
        .main-workspace-content {
            flex: 1;
            display: grid;
            grid-template-rows: auto auto 1fr;
            gap: 24px;
            overflow-y: auto;
            position: relative;
            background-color: transparent;
            z-index: 10;
            padding-bottom: 100px;
            width: 100%;
        }

        @media (min-width: 1200px) {
            .main-workspace-content {
                gap: 28px;
                padding-bottom: 110px;
            }

            .brand-name {
                font-size: 27px;
            }

            .brand-mascot {
                height: 64px;
            }

            .stage-tabs-row {
                gap: 20px;
            }

            .stage-card-tab {
                padding: 22px 22px 24px;
            }

            .stage-card-tab h4 {
                font-size: 16px;
            }

            .cp-ring-wrap {
                width: 72px;
                height: 72px;
            }

            .cp-svg {
                width: 72px;
                height: 72px;
            }

            .cp-pct {
                font-size: 15px;
            }

            .workspace-core-grid {
                gap: 28px;
            }

            .section-card {
                padding: 28px;
            }
        }

        @media (min-width: 1440px) {
            body {
                padding: 32px 40px;
            }

            .stage-tabs-row {
                gap: 24px;
            }

            .stage-card-tab {
                padding: 26px 26px 28px;
                border-radius: 32px;
            }
        }

        /* NAVIGATION HEADER BAR */
        /* One row: the iSubmit brand lockup on the left, the action cluster on the right. */
        .workspace-header-block {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .header-left-cluster {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }

        /* ── Brand lockup: mascot tile + wordmark ── */
        .brand-lockup {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        /* No tile — the mascot floats on the page. drop-shadow (not box-shadow) follows the
           PNG's alpha silhouette, which is what reads as 3D rather than as a card. */
        .brand-mascot {
            height: 58px;
            width: auto;
            flex-shrink: 0;
            display: block;
            filter: drop-shadow(0 7px 10px rgba(15, 23, 42, 0.22)) drop-shadow(0 2px 3px rgba(15, 23, 42, 0.12));
            animation: brandMascotFloat 4.5s ease-in-out infinite;
            transition: transform 0.35s var(--spring), filter 0.35s var(--smooth);
        }

        /* Dark canvas + a near-black graduation cap would swallow the silhouette, so the
           lift shadow is joined by a soft accent halo that separates it from the surface. */
        body.theme-dark .brand-mascot {
            filter: drop-shadow(0 7px 12px rgba(0, 0, 0, 0.55)) drop-shadow(0 0 9px rgba(139, 92, 246, 0.45));
        }

        @keyframes brandMascotFloat {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-4px);
            }
        }

        .brand-lockup:hover .brand-mascot {
            transform: translateY(-5px) scale(1.06) rotate(-3deg);
            animation-play-state: paused;
            filter: drop-shadow(0 12px 16px rgba(15, 23, 42, 0.26)) drop-shadow(0 3px 4px rgba(15, 23, 42, 0.14));
        }

        body.theme-dark .brand-lockup:hover .brand-mascot {
            filter: drop-shadow(0 12px 18px rgba(0, 0, 0, 0.6)) drop-shadow(0 0 13px rgba(139, 92, 246, 0.6));
        }

        @media (prefers-reduced-motion: reduce) {
            .brand-mascot {
                animation: none;
            }
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .brand-name {
            font-family: 'Inter', sans-serif;
            font-size: 25px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.7px;
            line-height: 1.1;
        }

        .brand-tagline {
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            letter-spacing: -0.1px;
            line-height: 1.25;
            margin-top: 2px;
            white-space: nowrap;
        }

        .program-pomp-dot {
            width: 6px;
            height: 6px;
            background-color: var(--active-accent);
            border-radius: 50%;
            display: inline-block;
        }

        .header-right-cluster {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .head-action-btn {
            background-color: transparent;
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.25s var(--smooth);
            text-decoration: none;
            box-shadow: none;
            color: #64748b;
        }

        .head-action-btn:hover {
            color: #0f172a;
            background-color: rgba(15, 23, 42, 0.05);
            transform: translateY(-1px);
        }

        @media (min-width: 1025px) {
            .head-action-btn-notif svg {
                color: var(--active-accent) !important;
                stroke: var(--active-accent) !important;
            }
        }

        body.theme-dark .head-action-btn {
            color: #94a3b8;
        }

        body.theme-dark .head-action-btn:hover {
            color: #f8fafc;
            background-color: rgba(255, 255, 255, 0.06);
        }

        .head-action-btn svg {
            width: 20px;
            height: 20px;
            stroke-width: 2px;
        }

        /* Unread notification counter (replaces the old static ping dot) */
        .notif-count-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            min-width: 17px;
            height: 17px;
            padding: 0 4px;
            background-color: var(--color-revision);
            color: #ffffff;
            border-radius: 999px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            display: none;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--bg-card);
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.45);
            animation: notifBadgePop 0.3s var(--spring);
        }

        .notif-count-badge.show {
            display: flex;
        }

        @keyframes notifBadgePop {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }

        /* MILESTONE HORIZONTAL BENTO CARDS */
        .stage-tabs-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .stage-card-tab {
            background: color-mix(in srgb, var(--active-accent) 4%, white);
            border: 1px solid rgba(15, 23, 42, 0.07);
            border-radius: 26px;
            position: relative;
            cursor: pointer;
            text-align: left;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            align-items: stretch;
            padding: 18px 18px 16px;
            opacity: 0;
            transform: translateY(15px);
            margin-top: 0;
            overflow: hidden;
            transition: transform 0.4s var(--spring), opacity 0.6s var(--smooth), border-color 0.4s var(--smooth), box-shadow 0.4s var(--smooth), background-color 0.4s var(--smooth);
        }

        body.theme-dark .stage-card-tab {
            background: color-mix(in srgb, var(--active-accent) 10%, #151c24);
            border-color: rgba(255, 255, 255, 0.07);
        }

        /* The "not yet" pill. Deliberately not the corner toast: no dismiss ×, no slide-in from
           off-screen — it appears over the card that was tapped and leaves on its own. */
        .lock-pill {
            position: fixed; z-index: 1400; pointer-events: none;
            transform: translate(-50%, -50%) scale(.94);
            display: flex; align-items: center; gap: 9px;
            max-width: min(300px, 86vw);
            padding: 11px 15px; border-radius: 14px;
            background: rgba(15, 23, 42, .93);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            color: #f1f5f9; font-family: 'Inter', system-ui, sans-serif;
            font-size: 12.5px; font-weight: 650; line-height: 1.4; text-align: left;
            box-shadow: 0 16px 34px -14px rgba(15, 23, 42, .6);
            opacity: 0; transition: opacity .22s ease, transform .28s cubic-bezier(.2, .9, .3, 1.1);
        }
        .lock-pill.show { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        .lock-pill svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .8; }
        @media (prefers-reduced-motion: reduce) { .lock-pill { transition: opacity .15s linear; } }

        /* Locked stage. The card still shows its name and its hover-peek — a student should be
           able to see what is coming — but it cannot be opened, and tapping it says why. */
        .stage-card-tab.card-locked {
            cursor: not-allowed;
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        .stage-card-tab.card-locked .card-content-wrap,
        .stage-card-tab.card-locked .card-ghost-num { opacity: .45; filter: grayscale(.9); }
        .stage-card-tab.card-locked:hover { transform: none; box-shadow: none; border-color: #e2e8f0; }
        .stage-card-lock {
            position: absolute; top: 12px; right: 12px; z-index: 3;
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #e2e8f0; color: #64748b;
        }
        body.theme-dark .stage-card-tab.card-locked { background: rgba(148, 163, 184, .06); border-color: rgba(255, 255, 255, .07); }
        body.theme-dark .stage-card-lock { background: rgba(255, 255, 255, .1); color: #94a3b8; }

        /* Completed stage gets a subtle green tint; everything else gets a low-saturation purple */
        .stage-card-tab.card-completed {
            background: #f2fbf7;
        }

        body.theme-dark .stage-card-tab.card-completed {
            background: rgba(16, 185, 129, 0.08);
            border-color: rgba(16, 185, 129, 0.25);
        }

        /* Higher specificity than the generic :hover rule below so completed cards stay green on hover */
        .stage-card-tab.card-completed:hover {
            background-color: #e9f9f1;
        }

        body.theme-dark .stage-card-tab.card-completed:hover {
            background-color: rgba(16, 185, 129, 0.14);
        }

        .stage-card-tab.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .stage-card-tab:active {
            transform: scale(0.98);
        }

        .stage-card-tab:hover:active {
            transform: translateY(-4px) scale(0.99);
        }

        /* Shimmer sweep on card hover */
        .stage-card-tab::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 26px;
            background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.22) 50%, transparent 65%);
            background-size: 200% 100%;
            background-position: -100% 0;
            opacity: 0;
            transition: opacity 0.3s var(--smooth);
            pointer-events: none;
        }

        .stage-card-tab:hover::after {
            opacity: 1;
            animation: cardShimmer 0.7s var(--smooth) forwards;
        }

        @keyframes cardShimmer {
            from {
                background-position: -100% 0;
            }

            to {
                background-position: 200% 0;
            }
        }

        .stage-card-tab:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 20px 40px -12px rgba(15, 23, 42, 0.12);
            border-color: rgba(15, 23, 42, 0.08);
            background-color: color-mix(in srgb, var(--active-accent) 7%, white);
        }

        body.theme-dark .stage-card-tab:hover {
            border-color: rgba(255, 255, 255, 0.12);
            background: color-mix(in srgb, var(--active-accent) 15%, #151c24);
        }

        /* ── GHOST STAGE NUMBER (big background numeral, sits behind everything) ── */
        .card-ghost-num {
            position: absolute;
            top: -26px;
            right: -6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 110px;
            font-weight: 800;
            color: rgba(15, 23, 42, 0.045);
            user-select: none;
            pointer-events: none;
            line-height: 1;
            z-index: 0;
        }

        body.theme-dark .card-ghost-num {
            color: rgba(255, 255, 255, 0.04);
        }

        /* Wrapper that keeps real content above the ghost numeral */
        .card-content-wrap {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
            width: 100%;
            flex: 1;
        }

        /* ── TOP ROW: status chip, right-aligned so it clears the 3-dot button at top-left ── */
        .card-top-row {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            width: 100%;
            min-height: 20px;
        }

        /* ── CARD INFO (3-DOT) BUTTON ── */
        .card-dot-btn {
            position: absolute;
            top: 12px;
            left: 12px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--bg-canvas);
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 20;
            transition: background 0.2s, color 0.2s, transform 0.2s var(--spring);
        }

        .card-dot-btn:hover {
            background: var(--active-accent);
            color: #fff;
            border-color: var(--active-accent);
            transform: scale(1.1);
        }

        .card-dot-btn svg {
            width: 13px;
            height: 13px;
            stroke-width: 2.5px;
        }

        /* ── CIRCULAR PROGRESS RING (stopwatch style) ── */
        .cp-ring-wrap {
            position: relative;
            width: 64px;
            height: 64px;
            flex-shrink: 0;
        }

        .cp-svg {
            width: 64px;
            height: 64px;
            transform: rotate(-90deg);
        }

        .cp-track {
            fill: none;
            stroke: var(--border-subtle);
            stroke-width: 5;
        }

        .cp-fill {
            fill: none;
            stroke: var(--active-accent);
            stroke-width: 5;
            stroke-linecap: round;
            stroke-dasharray: 175.93;
            /* 2π × 28 */
            stroke-dashoffset: 175.93;
            /* starts empty */
            transition: stroke-dashoffset 1.4s var(--spring), stroke 0.4s var(--smooth);
        }

        .cp-fill.approved {
            stroke: var(--color-approved);
        }

        .cp-fill.review {
            stroke: var(--color-review);
        }

        .cp-center-label {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
        }

        .cp-pct {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        .cp-unit {
            font-family: 'Inter', sans-serif;
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── MIDDLE ROW: progress ring (left) + title/meta (right), horizontal ── */
        .card-mid-row {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: left;
        }

        .card-mid-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
            flex: 1;
        }

        .stage-card-tab h4 {
            font-size: 15px;
            color: var(--text-primary);
            font-weight: 800;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-meta-line {
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── FOOTER ACTION ROW ── */
        .card-footer-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            align-self: flex-end;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 14px;
            padding: 8px 12px;
        }

        body.theme-dark .card-footer-row {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.08);
        }

        .card-footer-text {
            font-size: 11.5px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
        }

        .card-footer-text.tone-approved {
            color: #047857;
        }

        body.theme-dark .card-footer-text.tone-approved {
            color: #34d399;
        }

        .card-footer-text.tone-review {
            color: #b45309;
        }

        body.theme-dark .card-footer-text.tone-review {
            color: #fbbf24;
        }

        .card-footer-text.tone-default {
            color: #475569;
        }

        body.theme-dark .card-footer-text.tone-default {
            color: #94a3b8;
        }

        .card-footer-arrow {
            flex-shrink: 0;
            display: flex;
            color: var(--text-muted);
        }

        .card-footer-arrow svg {
            width: 13px;
            height: 13px;
            stroke-width: 2.5px;
        }

        /* ── STATUS CHIP ── */
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            width: fit-content;
        }

        .status-chip svg {
            width: 10px;
            height: 10px;
        }

        .chip-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .chip-review {
            background: #fef3c7;
            color: #92400e;
        }

        .chip-revision {
            background: #fee2e2;
            color: #991b1b;
        }

        .chip-progress {
            background: #e0f2fe;
            color: #075985;
        }

        .chip-pending {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
        }

        /* CORE GRID */
        .workspace-core-grid {
            display: grid;
            grid-template-columns: 1.25fr 0.75fr;
            gap: 24px;
            align-items: start;
        }

        .section-card {
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: var(--radius-interactive);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s;
            position: relative;
        }

        body.theme-dark .section-card {
            background: #151c24;
            border-color: rgba(255, 255, 255, 0.06);
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
            border-color: rgba(124, 58, 237, 0.1);
        }

        .section-title-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1.5px solid var(--border-subtle);
            padding-bottom: 12px;
        }

        .section-title {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* PREMIUM PILL "SEE ALL" BUTTON */
        .btn-see-all {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 14px;
            background-color: var(--active-glow);
            color: var(--active-accent);
            border: 1px solid transparent;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 11px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-decoration: none;
            transition: all 0.25s var(--smooth);
        }

        .btn-see-all:hover {
            background-color: var(--active-accent);
            color: #ffffff;
            transform: translateY(-1.5px);
            box-shadow: 0 4px 12px -2px var(--active-glow);
        }

        /* Mobile: drop the violet pill fill — keep the label as plain violet text */
        @media (max-width: 768px) {
            .btn-see-all {
                background-color: transparent;
                border-color: transparent;
                padding-left: 2px;
                padding-right: 2px;
            }
            .btn-see-all:hover,
            .btn-see-all:active {
                background-color: transparent;
                color: var(--active-accent);
                transform: none;
                box-shadow: none;
            }
        }

        /* Caps the activity card's height so a group with many uploads doesn't push the whole
           page down — "See All" already exists for the full list, this just scrolls in-place. */
        .activity-scroll-container {
            max-height: 460px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .activity-scroll-container::-webkit-scrollbar {
            width: 5px;
        }

        .activity-scroll-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .activity-scroll-container::-webkit-scrollbar-thumb {
            background: var(--border-subtle);
            border-radius: 10px;
        }

        /* TIMELINE ACTIVITY STREAM */
        .timeline-stream {
            position: relative;
            padding-left: 28px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .timeline-stream::before {
            content: '';
            position: absolute;
            left: 12px;
            top: 4px;
            bottom: 4px;
            width: 2px;
            background-color: var(--border-subtle);
        }

        .timeline-item {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .timeline-badge-container {
            position: absolute;
            left: -26px;
            top: 14px;
            background-color: var(--bg-card);
            padding: 2px;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .timeline-badge {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .timeline-badge.success {
            background-color: var(--color-approved);
        }

        .timeline-badge.warning {
            background-color: var(--color-revision);
        }

        .timeline-badge.info {
            background-color: var(--active-accent);
        }

        .timeline-content {
            background-color: var(--bg-canvas);
            border: 1px solid var(--border-subtle);
            padding: 16px;
            border-radius: 12px;
            transition: transform 0.25s var(--smooth), background-color 0.25s var(--smooth), border-color 0.25s var(--smooth), box-shadow 0.25s var(--smooth);
        }

        .timeline-content:hover {
            transform: translateX(6px) translateY(-2px);
            background-color: var(--bg-card);
            border-color: var(--active-accent);
            box-shadow: var(--shadow-md);
        }

        .timeline-title {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--text-primary);
        }

        .timeline-description {
            font-size: 12.5px;
            color: var(--text-secondary);
            margin-top: 4px;
            line-height: 1.45;
        }

        .timeline-time {
            display: flex;
            align-items: center;
            gap: 4px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 8px;
        }

        /* CALENDAR */
        .mini-calendar-wrapper {
            background-color: transparent;
        }

        .cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .cal-month-title {
            font-weight: 800;
            color: var(--text-primary);
            font-size: 15px;
            letter-spacing: -0.2px;
        }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            text-align: center;
            justify-items: center;
            align-items: center;
            transition: transform 0.2s var(--smooth), opacity 0.2s var(--smooth);
        }

        .cal-day-label {
            font-size: 10px;
            font-weight: 750;
            text-transform: uppercase;
            color: var(--text-muted);
            padding-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .cal-date {
            aspect-ratio: 1;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.25s var(--smooth);
            background-color: transparent;
            color: var(--text-primary);
            position: relative;
        }

        .cal-date:hover {
            background-color: var(--border-subtle);
            transform: scale(1.1);
            z-index: 5;
        }

        .cal-date.today {
            background-color: var(--active-accent);
            color: #ffffff;
            box-shadow: var(--shadow-sm);
        }

        .cal-date.sunday-closed {
            background-color: var(--border-subtle);
            color: var(--text-muted);
            opacity: 0.7;
        }

        .cal-date.has-event::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background-color: var(--eagle-gold);
            border-radius: 50%;
        }

        .cal-date.busy::after {
            background-color: var(--color-revision);
        }

        .cal-date.available::after {
            background-color: var(--color-approved);
        }

        /* Full / short label pairs: one line at every width, never a wrapped title or pill (phones get the short one) */
        .lbl-short { display: none; }
        @media (max-width: 768px) { .lbl-full { display: none; } .lbl-short { display: inline; } }

        /* published defenses of your institution (violet dot); your own group's published defense (green ring) */
        .cal-date.defense-day::after { background-color: #5b50f5; width: 5px; height: 5px; }
        .cal-date.my-defense { box-shadow: inset 0 0 0 2px var(--color-approved); }
        .cal-date.my-defense::after { background-color: var(--color-approved); }

        .cal-event-preview {
            margin-top: 18px;
            padding: 16px;
            background: var(--bg-canvas);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            border-left: 3.5px solid var(--active-accent);
            min-height: 80px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.01);
            transition: opacity 0.3s ease;
        }

        .cal-event-preview h5 {
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .cal-event-preview p {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        /* ── RESEARCH FUN FACTS (desktop only, under calendar) ── */
        .research-fun-facts {
            margin-top: 0px;
            /* Removed margin to pull it up */
            padding: 18px 20px;

            /* The Grid Background */
            background-color: #1e1b4b;
            /* Deep brand purple */
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px),
                radial-gradient(circle at 80% 20%, #7c3aed 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, #6d28d9 0%, transparent 60%);
            background-size: 20px 20px, 20px 20px, 100% 100%, 100% 100%;

            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 16px;
            min-height: 96px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px -4px rgba(109, 40, 217, 0.2);
        }

        .fun-facts-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            /* Bigger label */
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #ffffff;
            /* Crisp white */
            z-index: 1;
        }

        .fun-facts-label svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.5px;
            color: #f59e0b;
            /* Yellow/amber twinkle icon */
        }

        .fun-facts-line {
            font-size: 14.5px;
            /* Bigger text */
            line-height: 1.6;
            color: #ffffff;
            /* Crisp white for readability */
            font-weight: 500;
            min-height: 46px;
            margin: 0;
            z-index: 1;
        }

        .fun-facts-cursor {
            display: inline-block;
            width: 2px;
            height: 1em;
            background: #f59e0b;
            /* Amber cursor */
            margin-left: 2px;
            vertical-align: text-bottom;
            animation: funFactBlink 0.85s step-end infinite;
            z-index: 1;
        }

        @keyframes funFactBlink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0;
            }
        }

        .fun-facts-progress {
            display: flex;
            gap: 6px;
            align-items: center;
            z-index: 1;
        }

        .fun-facts-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transition: all 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .fun-facts-dot.active {
            background: #f59e0b;
            /* Yellow active dash */
            width: 18px;
            /* Makes it look like a dash `_` */
            border-radius: 4px;
        }

        @media (max-width: 1024px) {
            .research-fun-facts {
                display: none !important;
            }
        }

        body.theme-dark .research-fun-facts {
            background-color: #0f0d22;
            border-color: rgba(139, 92, 246, 0.2);
        }

        .cal-controls {
            display: flex;
            gap: 6px;
        }

        .cal-btn {
            background: var(--bg-canvas);
            border: 1px solid var(--border-subtle);
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .cal-btn:hover {
            background-color: var(--active-accent);
            color: #ffffff;
            border-color: var(--active-accent);
        }

        /* Celebration Banner */
        .celebration-banner-card {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1.5px dashed var(--color-approved);
            padding: 24px;
            border-radius: var(--radius-interactive);
            text-align: center;
            margin-top: 6px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        /* the eagle celebrating every milestone cleared (2026-10-05) */
        .celebrate-eagle {
            display: block;
            width: 132px;
            height: 124px;
            margin: -6px auto 2px;
            object-fit: contain;
            transform-origin: 50% 90%;
            animation: celebrateHop 1.8s cubic-bezier(.34, 1.56, .64, 1) infinite;
        }
        @keyframes celebrateHop {
            0%, 60%, 100% { transform: translateY(0) rotate(0); }
            20% { transform: translateY(-10px) rotate(-3deg); }
            40% { transform: translateY(0) rotate(2deg); }
        }
        @media (prefers-reduced-motion: reduce) { .celebrate-eagle { animation: none; } }

        .celebration-banner-card h3 {
            font-family: 'Cinzel', serif;
            color: var(--color-approved);
            font-size: 17px;
            font-weight: 950;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .celebration-banner-card p {
            font-size: 13px;
            color: #065f46;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .btn-download-final-form {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background-color: var(--color-approved);
            color: #ffffff;
            padding: 12px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--smooth);
        }

        .btn-download-final-form:hover {
            background-color: #047857;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* OVERLAYS & MODALS */
        .fullscreen-zoom-overlay {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--bg-canvas);
            z-index: 99999 !important;
            padding: 24px;
            display: none;
            opacity: 0;
            transition: opacity 0.2s ease-out;
        }

        .fullscreen-zoom-overlay.active {
            display: flex !important;
            flex-direction: column;
            opacity: 1;
        }

        .overlay-iframe-container {
            width: 100%;
            flex: 1;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-interactive);
            background-color: #ffffff;
            box-shadow: var(--shadow-lg);
        }

        /* In dark mode the framed pages (settings, profile, members, activities, message) use a
           transparent <body>, so this container IS their background. Keep it dark so their light
           text stays readable instead of white-on-white. */
        body.theme-dark .overlay-iframe-container {
            background-color: var(--bg-card);
        }

        /* Desktop-only floating back control: no header bar, no divider line.
           Hidden entirely at ≤1024px (see mobile dock media query), so the
           mobile/tablet experience is unchanged. */
        .nav-back-wrapper {
            position: absolute;
            top: 38px;
            left: 38px;
            z-index: 60;
            padding: 0;
            background-color: transparent;
            border-bottom: none;
            display: flex;
            align-items: center;
        }

        .btn-vector-left-back {
            background-color: var(--bg-card);
            border: 1px solid var(--border-subtle);
            box-shadow: var(--shadow-md);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s var(--smooth);
            color: var(--text-primary);
        }

        .btn-vector-left-back:hover {
            background-color: var(--active-accent);
            border-color: var(--active-accent);
            color: #ffffff;
            transform: translateX(-3px);
            box-shadow: var(--shadow-md);
        }

        .btn-vector-left-back svg {
            width: 18px;
            height: 18px;
            stroke-width: 2.5px;
        }

        /* ── Desktop full-view framing (≥1025px, where the floating back button lives) ──
           Turns the "floating modal card" into an edge-to-edge full-view page: no frame
           padding, no card border/radius/shadow. A 60px transparent top strip (shows the
           canvas, no divider line) hosts the floating back button so it never overlaps the
           page's own title. Tablet (769–1024) and mobile (≤768) are untouched. */
        @media (min-width: 1025px) {
            .fullscreen-zoom-overlay {
                padding: 0;
            }

            .overlay-iframe-container {
                border: none;
                border-radius: 0;
                box-shadow: none;
                margin-top: 60px;
            }

            .nav-back-wrapper {
                top: 14px;
                left: 22px;
            }
        }

        /* NOTIFICATIONS DRAWER */
        .notif-drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.3);
            z-index: 250;
            display: none;
            backdrop-filter: blur(2.5px);
        }

        .notif-drawer {
            position: fixed;
            top: 16px;
            right: -380px;
            width: 340px;
            height: calc(100% - 32px);
            background-color: var(--bg-card);
            z-index: 260;
            transition: right 0.45s var(--smooth);
            padding: 24px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-app);
        }

        .notif-drawer.active {
            right: 16px;
        }

        .notif-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--border-subtle);
            padding-bottom: 15px;
        }

        .notif-header h3 {
            font-family: var(--ui-sans);
            font-size: 14.5px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: 0.5px;
        }

        .btn-close-drawer {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.2s, transform 0.2s;
            line-height: 1;
        }

        .btn-close-drawer:hover {
            color: var(--color-revision);
            transform: rotate(90deg);
        }

        .notif-list-scroll {
            overflow-y: auto;
            flex: 1;
        }

        .notif-list-scroll::-webkit-scrollbar {
            display: none;
        }

        .notif-compact-row {
            padding: 14px;
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            background: var(--bg-card);
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s var(--smooth);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .notif-compact-row.success {
            background: rgba(5, 150, 105, 0.03);
            border-color: rgba(5, 150, 105, 0.15);
        }

        .notif-compact-row.success::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--color-approved);
        }

        .notif-compact-row.warning {
            background: rgba(220, 38, 38, 0.03);
            border-color: rgba(220, 38, 38, 0.15);
        }

        .notif-compact-row.warning::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--color-revision);
        }

        .notif-compact-row.is-clickable {
            padding-right: 32px;
        }

        .notif-compact-row.is-clickable:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Non-clickable notifications (no resolvable module) stay static */
        .notif-compact-row:not(.is-clickable) {
            cursor: default;
        }

        .notif-go-arrow {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            opacity: 0.35;
            transition: opacity 0.2s var(--smooth), right 0.2s var(--smooth);
        }

        .notif-compact-row.is-clickable:hover .notif-go-arrow {
            opacity: 1;
            right: 10px;
            color: var(--active-accent);
        }

        .notif-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
            gap: 8px;
        }

        .notif-info {
            flex: 1;
        }

        .notif-info h5 {
            font-family: var(--ui-sans);
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 700;
            margin: 0;
            line-height: 1.3;
        }

        .notif-chip {
            font-family: var(--ui-sans);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 8px;
            border-radius: 6px;
            flex-shrink: 0;
            letter-spacing: 0.5px;
        }

        .notif-chip.success {
            background: rgba(5, 150, 105, 0.1);
            color: var(--color-approved);
        }

        .notif-chip.warning {
            background: rgba(220, 38, 38, 0.1);
            color: var(--color-revision);
        }

        .notif-chip.info {
            background: rgba(100, 116, 139, 0.1);
            color: var(--text-secondary);
        }

        .notif-info p {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.4;
            margin: 0 0 8px 0;
        }

        .notif-info .time-stamp {
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            font-weight: 600;
        }

        /* TOAST NOTIFICATION SYSTEM */
        /* A dark pill that rises from the bottom, matching .lock-pill so every transient message
           in the portal reads as one thing. The old version was a white box pinned top-right
           with a coloured left bar and a dismiss × — the shape of a 2010s alert. */
        .toast-notif {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 999px;
            background: rgba(15, 23, 42, .94);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            color: #f1f5f9;
            box-shadow: 0 16px 34px -14px rgba(15, 23, 42, .6);
            transform: translateY(14px) scale(.96);
            transition: transform .3s cubic-bezier(.2, .9, .3, 1.1), opacity .25s ease;
            opacity: 0;
        }

        .toast-notif.visible {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .toast-icon {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }

        /* the eagle in the toast: a little face for the mood, popping in with the pill */
        .toast-eagle {
            width: 38px;
            height: 38px;
            margin: -8px -4px -8px -10px;
            flex-shrink: 0;
            object-fit: contain;
            transform: scale(.6);
            transition: transform .45s cubic-bezier(.34, 1.56, .64, 1) .08s;
        }
        .toast-notif.visible .toast-eagle { transform: scale(1); }

        .toast-message {
            font-size: 13px;
            font-weight: 650;
            line-height: 1.4;
        }

        /* Only the icon carries the status colour — a whole coloured panel for "file received"
           is louder than the event deserves. */
        .toast-success .toast-icon { color: #34d399; }
        .toast-error   .toast-icon { color: #f87171; }
        .toast-warning .toast-icon { color: #fbbf24; }
        .toast-info    .toast-icon { color: #a78bfa; }

        @media (prefers-reduced-motion: reduce) {
            .toast-notif { transition: opacity .2s linear; transform: none; }
            .toast-notif.visible { transform: none; }
        }

        /* AVATAR DROPDOWN MENU */
        .avatar-dropdown-menu {
            display: none;
            position: absolute;
            top: 56px;
            right: 0;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            width: 200px;
            box-shadow: var(--shadow-lg);
            flex-direction: column;
            z-index: 1000;
            overflow: hidden;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.2s var(--smooth), transform 0.2s var(--smooth);
        }

        .avatar-dropdown-menu.active {
            display: flex;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-subtle);
            background: var(--bg-canvas);
        }

        .dropdown-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dropdown-role {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            font-size: 13px;
            color: var(--text-primary);
            text-decoration: none;
            transition: background-color 0.2s var(--smooth);
        }

        .dropdown-item:hover {
            background-color: var(--active-glow);
            color: var(--active-accent);
        }

        .dropdown-item.logout {
            color: var(--color-revision);
            border-top: 1px solid var(--border-subtle);
        }

        .dropdown-item.logout:hover {
            background-color: rgba(239, 68, 68, 0.08);
            color: var(--color-revision);
        }

        /* MOBILE FLOATING DOCK LAYOUT - iPadOS style bottom center dock */
        @media (max-width: 1024px) {
            body {
                padding: 16px;
                padding-bottom: calc(16px + 80px + env(safe-area-inset-bottom, 0px));
            }

            .app-dashboard-frame {
                flex-direction: column;
                gap: 0;
            }

            .nav-back-wrapper {
                display: none !important;
            }

            /* Floating macOS Dock becomes beautiful floating Bottom iPad-like Dock */
            .app-dock-navigation {
                position: fixed;
                bottom: calc(16px + env(safe-area-inset-bottom, 0px));
                left: 50%;
                transform: translateX(-50%);
                width: calc(100% - 24px);
                max-width: 480px;
                height: 72px;
                flex-direction: row;
                align-items: center;
                justify-content: space-around;
                gap: 6px;
                padding: 10px 18px;
                border-radius: 28px;
                box-shadow: 0 10px 30px -18px rgba(15, 23, 42, 0.16), 0 1px 2px rgba(15, 23, 42, 0.03), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            }

            .dock-traffic-lights {
                display: none;
            }

            .dock-user-profile {
                margin-bottom: 0;
                order: 6;
                /* Put profile avatar at the very end of the dock items */
            }

            .dock-avatar-wrapper {
                width: 36px;
                height: 36px;
            }

            .dock-rank-badge {
                display: none;
            }

            .dock-menu-list {
                flex-direction: row;
                gap: 8px;
                width: auto;
            }

            .dock-btn {
                width: 46px;
                height: 46px;
                border-radius: 14px;
            }

            .dock-btn svg {
                width: 26px;
                height: 26px;
            }

            .dock-btn.active::after {
                display: none;
            }

            .dock-tooltip {
                display: none !important;
            }

            .main-workspace-content {
                padding-bottom: 24px;
            }

            .stage-tabs-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .workspace-core-grid {
                grid-template-columns: 1fr;
            }

            .mobile-hide-activities {
                display: none !important;
            }
        }

        @media (min-width: 1025px) {
            .stage-tabs-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .dock-btn svg {
                width: 26px;
                height: 26px;
            }

            .dock-btn {
                width: 50px;
                height: 50px;
            }
        }

        @media (min-width: 768px) and (max-width: 1024px) {
            .stage-tabs-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }

            .brand-name {
                font-size: 21px;
            }

            .brand-mascot {
                height: 50px;
            }
        }

        @media (min-width: 641px) {
            .desktop-hidden {
                display: none !important;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 10px;
                padding-bottom: calc(10px + 84px + env(safe-area-inset-bottom, 0px));
            }

            .mobile-hidden {
                display: none !important;
            }

            /* ── 2×2 card grid on mobile ── */
            .stage-tabs-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-top: 0;
            }

            .stage-card-tab {
                height: auto !important;
                padding: 14px 12px !important;
                border-radius: 22px;
            }

            .card-content-wrap {
                gap: 10px;
            }

            .card-ghost-num {
                font-size: 56px;
                top: -12px;
                right: -2px;
            }

            .card-mid-row {
                gap: 8px;
            }

            .stage-card-tab h4 {
                font-size: 11.5px !important;
            }

            .card-meta-line {
                font-size: 9.5px;
            }

            .cp-ring-wrap {
                width: 52px;
                height: 52px;
            }

            .cp-svg {
                width: 52px;
                height: 52px;
            }

            .cp-pct {
                font-size: 11px !important;
            }

            .card-footer-row {
                padding: 8px 10px;
                border-radius: 12px;
            }

            .card-footer-text {
                font-size: 10px;
            }

            /* ── COMPACT MOBILE HEADER ── */
            /* Same two-row structure as desktop, just tuned down. Only sizes change here —
               the layout itself lives in the base rules. */

            /* Hide weather on mobile — it eats too much space */
            #weatherWidget {
                display: none !important;
            }

            .workspace-top-stack {
                gap: 10px;
            }

            .workspace-header-block {
                align-items: center;
                gap: 8px;
            }

            .brand-lockup {
                gap: 9px;
            }

            .brand-mascot {
                height: 46px;
            }

            .brand-name {
                font-size: 19px;
                letter-spacing: -0.5px;
            }

            .brand-tagline {
                font-size: 10px;
                margin-top: 1px;
            }

            /* Shrink action buttons */
            .head-action-btn {
                width: 36px !important;
                height: 36px !important;
            }

            .head-action-btn svg {
                width: 17px !important;
                height: 17px !important;
            }

            /* Avatar dropdown smaller */
            .avatar-dropdown-wrapper .head-action-btn {
                width: 36px !important;
                height: 36px !important;
            }

            /* Reduce right cluster gap */
            .header-right-cluster {
                gap: 8px !important;
            }

            /* Reduce main content top gap so cards are higher */
            .main-workspace-content {
                gap: 12px !important;
            }

            /* Slightly larger dock on mobile for easier tap */
            .app-dock-navigation {
                width: calc(100% - 20px);
                max-width: 380px;
                padding: 10px 14px;
                gap: 0;
                border-radius: 26px;
                height: 72px;
                justify-content: space-between;
            }

            .app-dock-navigation nav {
                width: 100%;
            }

            .dock-menu-list {
                gap: 0;
                width: 100%;
                justify-content: space-between;
            }

            .dock-btn {
                width: 50px;
                height: 50px;
                border-radius: 23px;
                color: #94a3b8;
                flex-shrink: 0;
                transition: width 0.45s var(--spring), background-color 0.3s var(--smooth), color 0.3s var(--smooth);
            }

            .dock-btn svg {
                width: 26px;
                height: 26px;
                stroke-width: 2.5px;
            }

            .dock-btn.active {
                width: 66px;
                background-color: var(--dock-tray);
                color: var(--active-accent);
            }

            .dock-btn.active svg {
                stroke-width: 2.7px;
            }

            .dock-avatar-wrapper {
                width: 34px;
                height: 34px;
            }

            .dock-center-avatar-ring {
                width: 60px;
                height: 60px;
                transform: translateY(-12px);
            }

            .dock-center-avatar-ring:hover {
                transform: scale(1.08) translateY(-14px);
            }
        }

        /* ── PILL THEME TOGGLE (lancejosh-inspired) ── */
        .pill-theme-toggle {
            position: relative;
            width: 56px;
            /* w-14 */
            height: 32px;
            /* h-8 */
            border-radius: 999px;
            background: #e4e4e7;
            /* zinc-200 light */
            border: 1.5px solid #d4d4d8;
            /* zinc-300 */
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 4px;
            transition: background 0.35s var(--smooth), border-color 0.35s var(--smooth);
            flex-shrink: 0;
            outline: none;
        }

        body.theme-dark .pill-theme-toggle {
            background: #27272a;
            /* zinc-800 */
            border-color: #3f3f46;
            /* zinc-700 */
        }

        .pill-thumb {
            width: 24px;
            /* w-6 */
            height: 24px;
            /* h-6 */
            border-radius: 50%;
            background: #ffffff;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateX(0);
            transition: transform 0.32s var(--spring), background 0.32s var(--smooth);
        }

        body.theme-dark .pill-thumb {
            transform: translateX(20px);
            background: #18181b;
            /* zinc-900 */
        }

        .pill-thumb svg {
            width: 14px;
            height: 14px;
            color: #52525b;
            /* zinc-600 */
            transition: color 0.3s var(--smooth);
        }

        body.theme-dark .pill-thumb svg {
            color: #facc15;
            /* yellow-400 — moon glows */
        }

        .pill-thumb .icon-sun {
            display: block;
        }

        .pill-thumb .icon-moon {
            display: none;
        }

        body.theme-dark .pill-thumb .icon-sun {
            display: none;
        }

        body.theme-dark .pill-thumb .icon-moon {
            display: block;
        }

        /* ── DOCK CENTER AVATAR ── */
        .dock-center-avatar-ring {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            padding: 2px;
            border: 2px solid var(--active-accent);
            transition: transform 0.3s var(--spring), box-shadow 0.3s var(--smooth);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            background: var(--bg-card);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateY(-16px);
        }

        .dock-center-avatar-ring:hover {
            transform: scale(1.06) translateY(-18px);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.14);
        }

        .dock-center-avatar-ring img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .dock-center-avatar .dock-rank-badge {
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 15;
        }

        /* ── DOCK PROFILE DROPDOWN OVERRIDES ── */
        .avatar-dropdown-wrapper {
            position: relative;
        }

        .avatar-dropdown-wrapper .avatar-dropdown-menu {
            bottom: 74px;
            top: auto;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            right: auto;
            box-shadow: var(--shadow-xl);
        }

        .avatar-dropdown-wrapper .avatar-dropdown-menu.active {
            transform: translateX(-50%) translateY(0);
        }

        /* ── VIEW TRANSITIONS API — circular ripple ── */
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
            mix-blend-mode: normal;
        }

        ::view-transition-old(root) {
            z-index: 1;
        }

        ::view-transition-new(root) {
            z-index: 9999;
        }

        /* Light-ward toggle: reveal by SHRINKING the old (dark) snapshot, so it must sit on top.
           The theme class lives on <body>, but view-transition pseudos live on the root (<html>),
           so we flip z-index via a temporary class on <html> set by the toggle handler. */
        html.vt-reverse::view-transition-old(root) {
            z-index: 9999;
        }

        html.vt-reverse::view-transition-new(root) {
            z-index: 1;
        }


        @media (max-width: 768px) {
            .fullscreen-zoom-overlay {
                padding: 0 !important;
            }

            .overlay-iframe-container {
                border: none !important;
                border-radius: 0 !important;
            }
        }

        /* BOTTOM SHEET FOR PROPOSAL DEFENSE (MOBILE) */
        @media (max-width: 768px) {
            #zoom-proposal, #zoom-stats, #zoom-plag, #zoom-final {
                top: auto !important;
                bottom: 0 !important;
                height: 85vh !important;
                transform: translateY(100%);
                border-radius: 24px 24px 0 0 !important;
                overflow: hidden !important;
                box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15) !important;
                transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
                padding-top: 15px !important;
            }

            #zoom-proposal.active, #zoom-stats.active, #zoom-plag.active, #zoom-final.active {
                transform: translateY(0) !important;
            }
        }
    </style>
    <meta name="theme-color" content="#7c3aed">
</head>

<body class="theme-purple">
<?php include __DIR__ . '/components/announcement.php'; /* 2026-10-01: the Admin's banner */ ?>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('rd-portal-theme');
            if (savedTheme) {
                document.body.className = savedTheme;
            }
        })();
    </script>

    <!-- Global Toast Container -->
    <div id="toastContainer" style="position: fixed; left: 50%; bottom: 96px; transform: translateX(-50%); z-index: 1000000; display: flex; flex-direction: column; align-items: center; gap: 10px; pointer-events: none; width: max-content; max-width: min(420px, 92vw);"></div>

    <div class="app-dashboard-frame">

        <!-- MODERN MACOS FLOATING DOCK NAVIGATION -->
        <aside class="app-dock-navigation" id="dockContainer">
            <!-- Dock Actions List -->
            <nav>
                <ul class="dock-menu-list">
                    <!-- Home -->
                    <li class="dock-item">
                        <button class="dock-btn active" data-view="home" onclick="collapseZoomModules(); selectDockItem(this)">
                            <i data-lucide="home"></i>
                        </button>
                        <span class="dock-tooltip">Home</span>
                    </li>

                    <!-- Activities (See All) -->
                    <li class="dock-item">
                        <button class="dock-btn" data-view="activities" onclick="pushView('zoom-activities', 'activities_all.php'); selectDockItem(this)">
                            <i data-lucide="history"></i>
                        </button>
                        <span class="dock-tooltip">Activities</span>
                    </li>

                    <!-- Members (Desktop only) -->
                    <li class="dock-item mobile-hidden">
                        <button class="dock-btn" data-view="members" onclick="pushView('zoom-members', 'members.php'); selectDockItem(this)">
                            <i data-lucide="users-round"></i>
                        </button>
                        <span class="dock-tooltip">Members</span>
                    </li>

                    <!-- CENTER: User Avatar -->
                    <li class="dock-item avatar-dropdown-wrapper" style="position: relative;">
                        <button class="dock-avatar-btn" onclick="toggleAvatarDropdown(event)" style="background: none; border: none; padding: 0; cursor: pointer; position: relative;">
                            <div class="dock-center-avatar-ring">
                                <img src="<?= htmlspecialchars($user_pfp) ?>" alt="Avatar" class="dock-avatar-img">
                            </div>
                            <span class="dock-rank-badge"><?= htmlspecialchars($rank_title) ?></span>
                        </button>

                        <!-- Profile Dropdown -->
                        <div class="avatar-dropdown-menu" id="avatarDropdownMenu">
                            <div class="dropdown-header">
                                <div class="dropdown-name"><?= htmlspecialchars($account_holder) ?></div>
                                <div class="dropdown-role"><?= $rank_title ?></div>
                            </div>
                            <a href="javascript:void(0)" onclick="pushView('zoom-profile', 'profile.php')" class="dropdown-item">
                                <i data-lucide="user" style="width: 16px; height: 16px;"></i> Profile
                            </a>
                            <a href="javascript:void(0)" onclick="pushView('zoom-members', 'members.php')" class="dropdown-item desktop-hidden">
                                <i data-lucide="users-round" style="width: 16px; height: 16px;"></i> Members
                            </a>
                            <a href="javascript:void(0)" onclick="pushView('zoom-settings', 'settings.php')" class="dropdown-item desktop-hidden">
                                <i data-lucide="settings" style="width: 16px; height: 16px;"></i> Settings
                            </a>
                        </div>
                    </li>

                    <!-- Message (Chat) -->
                    <li class="dock-item">
                        <button class="dock-btn" data-view="chat" onclick="openGroupChat(); selectDockItem(this)">
                            <div style="position: relative; display: flex; align-items: center;">
                                <i data-lucide="messages-square"></i>
                                <span id="chat-badge" class="dock-chat-badge" style="display:none;"></span>
                            </div>
                        </button>
                        <span class="dock-tooltip">Message</span>
                    </li>

                    <!-- Settings (Desktop only) -->
                    <li class="dock-item mobile-hidden">
                        <button class="dock-btn" data-view="settings" onclick="pushView('zoom-settings', 'settings.php'); selectDockItem(this)">
                            <i data-lucide="settings"></i>
                        </button>
                        <span class="dock-tooltip">Settings</span>
                    </li>

                    <div class="dock-divider mobile-hidden"></div>

                    <!-- Logout Button -->
                    <li class="dock-item">
                        <button class="dock-btn dock-btn-logout" data-view="logout" onclick="window.location.href='../auth/logout.php';">
                            <i data-lucide="arrow-right-from-line"></i>
                        </button>
                        <span class="dock-tooltip">Logout</span>
                    </li>
                </ul>
            </nav>

            <!-- macOS Traffic Lights (desktop only) -->
            <div class="dock-traffic-lights mobile-hidden" style="margin-left: 8px;"></div>
        </aside>

        <!-- CONTAINER MAIN WORKSPACE CONTENT -->
        <main class="main-workspace-content">

            <div class="workspace-header-block">
                <div class="header-left-cluster">
                    <!-- Brand lockup: the mascot + wordmark are the page's identity at every
                         breakpoint. The research group's own name and program live on
                         profile.php, not here. -->
                    <div class="brand-lockup">
                        <img src="../assets/images/mascot.png" alt="" aria-hidden="true" class="brand-mascot">
                        <div class="brand-text">
                            <span class="brand-name">iSubmit</span>
                            <span class="brand-tagline">Research Digitalization Platform</span>
                        </div>
                    </div>
                </div>

                <div class="header-right-cluster">
                    <div id="weatherWidget"></div>

                    <a href="javascript:void(0)" class="head-action-btn head-action-btn-notif" onclick="toggleNotifDrawer()" title="View Notifications">
                        <span class="notif-count-badge" id="notifCountBadge"></span>
                        <i data-lucide="bell-ring"></i>
                    </a>

                    <!-- Pill Theme Toggle (replaces avatar in header) -->
                    <button class="pill-theme-toggle" id="headerThemeToggle"
                        onclick="toggleThemeWithRipple(event)"
                        aria-label="Toggle dark mode" title="Toggle dark mode" style="margin-left: 8px;">
                        <div class="pill-thumb">
                            <!-- Sun icon (light mode) -->
                            <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="4" />
                                <path d="M12 2v2" />
                                <path d="M12 20v2" />
                                <path d="m4.93 4.93 1.41 1.41" />
                                <path d="m17.66 17.66 1.41 1.41" />
                                <path d="M2 12h2" />
                                <path d="M20 12h2" />
                                <path d="m6.34 17.66-1.41 1.41" />
                                <path d="m19.07 4.93-1.41 1.41" />
                            </svg>
                            <!-- Moon icon (dark mode) -->
                            <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
                            </svg>
                        </div>
                    </button>
                </div>
            </div>


            <!-- CARDS MILESTONE ROW -->
            <?php
            // Footer action-row text: green when complete, amber while in review, otherwise the
            // dynamic next-step text computed above (Revise:/Upload .../Awaiting review).
            function cardFooterHTML($progress, $completedFull, $completedShort, $nextFull, $nextShort)
            {
                if ($progress >= 100) {
                    $tone = 'tone-approved';
                    $full = $completedFull;
                    $short = $completedShort;
                } elseif ($progress >= 75) {
                    $tone = 'tone-review';
                    $full = $short = 'Under review';
                } else {
                    $tone = 'tone-default';
                    $full = $nextFull;
                    $short = $nextShort;
                }
                // Short (generic, always fits one line) on phone; full (specific) on tablet/desktop
                return '<span class="card-footer-text ' . $tone . ' desktop-hidden">' . htmlspecialchars($short) . '</span>'
                     . '<span class="card-footer-text ' . $tone . ' mobile-hidden">' . htmlspecialchars($full) . '</span>';
            }
            $footerArrowSvg = '<span class="card-footer-arrow"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></span>';
            ?>
            <section class="stage-tabs-row">
                <!-- Proposal Card — pink/rose accent -->
                <div class="stage-card-tab <?= $proposal_progress == 100 ? 'card-completed' : '' ?>"
                    style="--active-accent:#7c3aed; --active-glow:rgba(124,58,237,0.15);"
                    data-peek="proposal"
                    onclick="pushView('zoom-proposal','module_proposal.php',this)">
                    <div class="card-ghost-num">1</div>
                    <button class="card-dot-btn" onclick="openCardModal(event,'proposal')" title="What to do">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="5" r=".6" fill="currentColor" />
                            <circle cx="12" cy="12" r=".6" fill="currentColor" />
                            <circle cx="12" cy="19" r=".6" fill="currentColor" />
                        </svg>
                    </button>
                    <div class="card-content-wrap">
                        <div class="card-top-row">
                            <?php
                            if ($proposal_progress == 100)      echo '<span class="status-chip chip-approved"><i data-lucide="check-circle-2"></i> Completed</span>';
                            elseif ($proposal_progress >= 75)   echo '<span class="status-chip chip-review"><i data-lucide="beaker"></i> Reviewed</span>';
                            elseif ($proposal_progress >= 50)   echo '<span class="status-chip chip-revision"><i data-lucide="alert-circle"></i> Revision</span>';
                            elseif ($proposal_progress > 0)     echo '<span class="status-chip chip-pending"><i data-lucide="clock"></i> Pending</span>';
                            else                                 echo '<span></span>';
                            ?>
                        </div>
                        <div class="card-mid-row">
                            <div class="cp-ring-wrap">
                                <svg class="cp-svg" viewBox="0 0 72 72">
                                    <circle class="cp-track" cx="36" cy="36" r="28" />
                                    <circle class="cp-fill <?= $proposal_progress == 100 ? 'approved' : '' ?>" cx="36" cy="36" r="28"
                                        data-pct="<?= $proposal_progress ?>" />
                                </svg>
                                <div class="cp-center-label">
                                    <span class="cp-pct"><?= $proposal_progress ?>%</span>
                                </div>
                            </div>
                            <div class="card-mid-text">
                                <h4>Proposal Defense</h4>
                                <?php if ($proposal_meta['updated']): ?>
                                    <span class="card-meta-line"><?= $proposal_progress >= 100 ? 'Cleared' : 'Updated' ?> <?= htmlspecialchars($proposal_meta['updated']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($proposal_progress > 0): ?>
                            <div class="card-footer-row">
                                <?= cardFooterHTML($proposal_progress, 'Download clearing form', 'Download form', $proposal_meta['next_full'], $proposal_meta['next_short']) ?>
                                <?= $footerArrowSvg ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Card -->
                <div class="stage-card-tab <?= $stats_progress == 100 ? 'card-completed' : '' ?><?= $unlock_stats ? '' : ' card-locked' ?>"
                    style="--active-accent:#7c3aed; --active-glow:rgba(124,58,237,0.15);"
                    data-peek="stats"
                    <?php if ($unlock_stats): ?>onclick="pushView('zoom-stats','module_statistics.php',this)"<?php else: ?>onclick="lockedCardNote(<?= htmlspecialchars(json_encode($lock_reason_next), ENT_QUOTES) ?>, this)"<?php endif; ?>>
                    <div class="card-ghost-num">2</div>
                    <?php if (!$unlock_stats): ?><span class="stage-card-lock"><i data-lucide="lock" style="width:13px;height:13px;"></i></span><?php endif; ?>
                    <button class="card-dot-btn" onclick="openCardModal(event,'stats')" title="What to do">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="5" r=".6" fill="currentColor" />
                            <circle cx="12" cy="12" r=".6" fill="currentColor" />
                            <circle cx="12" cy="19" r=".6" fill="currentColor" />
                        </svg>
                    </button>
                    <div class="card-content-wrap">
                        <div class="card-top-row">
                            <?php
                            // Chip is phase-driven (see $stats_phase_meta) — NOT a threshold on the % ring.
                            if ($stats_chip_class) echo '<span class="status-chip ' . $stats_chip_class . '"><i data-lucide="' . $stats_chip_icon . '"></i> ' . $stats_chip_label . '</span>';
                            else                   echo '<span></span>';
                            ?>
                        </div>
                        <div class="card-mid-row">
                            <div class="cp-ring-wrap">
                                <svg class="cp-svg" viewBox="0 0 72 72">
                                    <circle class="cp-track" cx="36" cy="36" r="28" />
                                    <circle class="cp-fill <?= $stats_ring_class ?>" cx="36" cy="36" r="28"
                                        data-pct="<?= $stats_progress ?>" />
                                </svg>
                                <div class="cp-center-label">
                                    <span class="cp-pct"><?= $stats_progress ?>%</span>
                                </div>
                            </div>
                            <div class="card-mid-text">
                                <h4>Statistics Review</h4>
                                <?php if ($stats_updated): ?>
                                    <span class="card-meta-line"><?= $stats_progress >= 100 ? 'Cleared' : 'Updated' ?> <?= htmlspecialchars($stats_updated) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($stats_progress > 0): ?>
                            <div class="card-footer-row">
                                <?php // Footer tone + text are phase-driven (see $stats_phase_meta), not %-threshold-driven. ?>
                                <span class="card-footer-text <?= $stats_footer_tone ?> desktop-hidden"><?= htmlspecialchars($stats_footer_short) ?></span>
                                <span class="card-footer-text <?= $stats_footer_tone ?> mobile-hidden"><?= htmlspecialchars($stats_footer_full) ?></span>
                                <?= $footerArrowSvg ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Final Defense Card -->
                <div class="stage-card-tab <?= $final_progress == 100 ? 'card-completed' : '' ?><?= $unlock_final ? '' : ' card-locked' ?>"
                    style="--active-accent:#7c3aed; --active-glow:rgba(124,58,237,0.15);"
                    data-peek="final"
                    <?php if ($unlock_final): ?>onclick="pushView('zoom-final','module_final.php',this)"<?php else: ?>onclick="lockedCardNote(<?= htmlspecialchars(json_encode($lock_reason_final), ENT_QUOTES) ?>, this)"<?php endif; ?>>
                    <div class="card-ghost-num">3</div>
                    <?php if (!$unlock_final): ?><span class="stage-card-lock"><i data-lucide="lock" style="width:13px;height:13px;"></i></span><?php endif; ?>
                    <button class="card-dot-btn" onclick="openCardModal(event,'final')" title="What to do">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="5" r=".6" fill="currentColor" />
                            <circle cx="12" cy="12" r=".6" fill="currentColor" />
                            <circle cx="12" cy="19" r=".6" fill="currentColor" />
                        </svg>
                    </button>
                    <div class="card-content-wrap">
                        <div class="card-top-row">
                            <?php
                            if ($final_progress == 100)      echo '<span class="status-chip chip-approved"><i data-lucide="check-circle-2"></i> Completed</span>';
                            elseif ($final_progress >= 75)   echo '<span class="status-chip chip-review"><i data-lucide="beaker"></i> Reviewed</span>';
                            elseif ($final_progress >= 50)   echo '<span class="status-chip chip-revision"><i data-lucide="alert-circle"></i> Revision</span>';
                            elseif ($final_progress > 0)     echo '<span class="status-chip chip-pending"><i data-lucide="clock"></i> Pending</span>';
                            else                               echo '<span></span>';
                            ?>
                        </div>
                        <div class="card-mid-row">
                            <div class="cp-ring-wrap">
                                <svg class="cp-svg" viewBox="0 0 72 72">
                                    <circle class="cp-track" cx="36" cy="36" r="28" />
                                    <circle class="cp-fill <?= $final_progress == 100 ? 'approved' : '' ?>" cx="36" cy="36" r="28"
                                        data-pct="<?= $final_progress ?>" />
                                </svg>
                                <div class="cp-center-label">
                                    <span class="cp-pct"><?= $final_progress ?>%</span>
                                </div>
                            </div>
                            <div class="card-mid-text">
                                <h4>Final Defense</h4>
                                <?php if ($final_meta['updated']): ?>
                                    <span class="card-meta-line"><?= $final_progress >= 100 ? 'Cleared' : 'Updated' ?> <?= htmlspecialchars($final_meta['updated']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$df_unlocked_peek): ?>
                            <?php
                            // Zero-progress cards normally hide the footer because there is nothing
                            // specific to say. The front stage changes that: 0% now carries a real
                            // next action, so it earns a line. Short/full variants per breakpoint,
                            // never a truncated single string.
                            // Students never apply or pick a date any more, so the words come from
                            // df_stage_meta() — short = its label, full = its headline — rather
                            // than a second copy here that drifted ("Apply now", "Pick a date").
                            $df_foot = [$df_stage_peek['label'], $df_stage_peek['headline']];
                            // Only tone-default / tone-review / tone-approved exist; revision states
                            // fall back to tone-default, same as the stats phase ladder above.
                            $df_foot_tone = $df_stage_peek['tone'] === 'review' ? 'tone-review' : 'tone-default';
                            ?>
                            <div class="card-footer-row">
                                <span class="card-footer-text <?= $df_foot_tone ?> desktop-hidden"><?= htmlspecialchars($df_foot[0]) ?></span>
                                <span class="card-footer-text <?= $df_foot_tone ?> mobile-hidden"><?= htmlspecialchars($df_foot[1]) ?></span>
                                <?= $footerArrowSvg ?>
                            </div>
                        <?php elseif ($final_progress > 0): ?>
                            <div class="card-footer-row">
                                <?= cardFooterHTML($final_progress, 'Download approval form', 'Download form', $final_meta['next_full'], $final_meta['next_short']) ?>
                                <?= $footerArrowSvg ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Plagiarism Card -->
                <div class="stage-card-tab <?= $plag_progress == 100 ? 'card-completed' : '' ?><?= $unlock_plag ? '' : ' card-locked' ?>"
                    style="--active-accent:#7c3aed; --active-glow:rgba(124,58,237,0.15);"
                    data-peek="plag"
                    <?php if ($unlock_plag): ?>onclick="pushView('zoom-plag','module_plagiarism.php',this)"<?php else: ?>onclick="lockedCardNote(<?= htmlspecialchars(json_encode($lock_reason_plag), ENT_QUOTES) ?>, this)"<?php endif; ?>>
                    <div class="card-ghost-num">4</div>
                    <?php if (!$unlock_plag): ?><span class="stage-card-lock"><i data-lucide="lock" style="width:13px;height:13px;"></i></span><?php endif; ?>
                    <button class="card-dot-btn" onclick="openCardModal(event,'plag')" title="What to do">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="5" r=".6" fill="currentColor" />
                            <circle cx="12" cy="12" r=".6" fill="currentColor" />
                            <circle cx="12" cy="19" r=".6" fill="currentColor" />
                        </svg>
                    </button>
                    <div class="card-content-wrap">
                        <div class="card-top-row">
                            <?php
                            // Chip is phase-driven (see $plag_phase_meta) — NOT a threshold on the % ring.
                            if ($plag_chip_class) echo '<span class="status-chip ' . $plag_chip_class . '"><i data-lucide="' . $plag_chip_icon . '"></i> ' . $plag_chip_label . '</span>';
                            else                  echo '<span></span>';
                            ?>
                        </div>
                        <div class="card-mid-row">
                            <div class="cp-ring-wrap">
                                <svg class="cp-svg" viewBox="0 0 72 72">
                                    <circle class="cp-track" cx="36" cy="36" r="28" />
                                    <circle class="cp-fill <?= $plag_ring_class ?>" cx="36" cy="36" r="28"
                                        data-pct="<?= $plag_progress ?>" />
                                </svg>
                                <div class="cp-center-label">
                                    <span class="cp-pct"><?= $plag_progress ?>%</span>
                                </div>
                            </div>
                            <div class="card-mid-text">
                                <h4>Plagiarism Test</h4>
                                <?php if ($plag_updated): ?>
                                    <span class="card-meta-line"><?= $plag_progress >= 100 ? 'Cleared' : 'Updated' ?> <?= htmlspecialchars($plag_updated) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($plag_progress > 0): ?>
                            <div class="card-footer-row">
                                <?php // Footer tone + text are phase-driven (see $plag_phase_meta), not %-threshold-driven. ?>
                                <span class="card-footer-text <?= $plag_footer_tone ?> desktop-hidden"><?= htmlspecialchars($plag_footer_short) ?></span>
                                <span class="card-footer-text <?= $plag_footer_tone ?> mobile-hidden"><?= htmlspecialchars($plag_footer_full) ?></span>
                                <?= $footerArrowSvg ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php include 'components/dashboard_billboards.php'; ?>

            <!-- SIDE BY SIDE BENTO INFO GRID -->
            <div class="workspace-core-grid">

                <div class="section-card mobile-hide-activities">
                    <div class="section-title-wrapper">
                        <h3 class="section-title"><i data-lucide="history" style="width:16px;height:16px;color:#7c3aed;"></i>Recent Group Activities</h3>
                        <a href="javascript:void(0)" onclick="pushView('zoom-activities', 'activities_all.php')" class="btn-see-all">See All</a>
                    </div>

                    <div class="activity-scroll-container">
                        <div class="activity-stream-box timeline-stream">
                            <?php if (count($recent_activities) > 0): ?>
                                <?php foreach ($recent_activities as $act): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-badge-container">
                                            <div class="timeline-badge <?= $act['status_type'] === 'success' ? 'success' : ($act['status_type'] === 'warning' ? 'warning' : 'info') ?>">
                                                <?php if ($act['status_type'] === 'success'): ?>
                                                    <i data-lucide="check" style="width: 12px; height: 12px;"></i>
                                                <?php elseif ($act['status_type'] === 'warning'): ?>
                                                    <i data-lucide="alert-triangle" style="width: 12px; height: 12px;"></i>
                                                <?php else: ?>
                                                    <i data-lucide="info" style="width: 12px; height: 12px;"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="timeline-content">
                                            <h4 class="timeline-title"><?= htmlspecialchars($act['title']) ?></h4>
                                            <p class="timeline-description"><?= htmlspecialchars($act['description']) ?></p>
                                            <span class="timeline-time" data-timestamp="<?= strtotime($act['created_at']) ?>">
                                                <i data-lucide="clock" style="width: 10px; height: 10px;"></i>
                                                <span class="time-text"><?= date('M d, Y • h:i A', strtotime($act['created_at'])) ?></span>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="font-size: 13px; color: var(--text-muted); text-align: center; padding: 40px;"><i data-lucide="folder-open" style="width:28px;height:28px;display:block;margin:0 auto 10px auto;opacity:0.4;"></i>No recent verification milestones logged yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="right-column-wrapper" style="display: flex; flex-direction: column; gap: 12px;">
                    <div class="section-card">
                        <div class="section-title-wrapper">
                            <h3 class="section-title"><i data-lucide="calendar-days" style="width:16px;height:16px;color:#7c3aed;"></i><span class="lbl-full">Defense schedule & events</span><span class="lbl-short">Schedule</span></h3>
                            <a href="javascript:void(0)" onclick="pushView('zoom-calendar', 'calendar_all.php')" class="btn-see-all"><span class="lbl-full">Open calendar</span><span class="lbl-short">Open</span></a>
                        </div>

                        <div class="mini-calendar-wrapper">
                            <div class="cal-header">
                                <div class="cal-month-title" id="calMonthTitle">June 2026</div>
                                <div class="cal-controls">
                                    <button class="cal-btn" onclick="changeMonth(-1)">&lt;</button>
                                    <button class="cal-btn" onclick="changeMonth(1)">&gt;</button>
                                </div>
                            </div>
                            <div class="cal-grid" id="calendarGrid"></div>
                        </div>
                    </div>

                    <!-- Desktop-only fun facts (Duolingo-style tips) -->
                    <div class="research-fun-facts" id="researchFunFacts" aria-live="polite">
                        <div class="fun-facts-label">
                            <i data-lucide="sparkles"></i>
                            <span>Did you know?</span>
                        </div>
                        <p class="fun-facts-line">
                            <span id="funFactTyped"></span><span class="fun-facts-cursor" aria-hidden="true"></span>
                        </p>
                        <div class="fun-facts-progress" id="funFactsProgress"></div>
                    </div>
                </div>

                <?php if ($overall_complete): ?>
                    <div class="celebration-banner-card">
                        <img src="../assets/mascot/eagle/celebrating.svg" alt="" aria-hidden="true" class="celebrate-eagle" draggable="false">
                        <h3><i data-lucide="trophy" style="width: 24px; height: 24px; color: #fbbf24; vertical-align: middle; display: inline-block; margin-right: 8px;"></i>Congratulations! All Milestones Cleared</h3>
                        <p>All your research stages have been verified by coordinators and administrators. You may now generate your formal digital clearing credentials below.</p>
                        <a href="download_final_form.php" class="btn-download-final-form">
                            <i data-lucide="file-down"></i>Download Clearing Form
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </main>

        <!-- CINEMATIC ZOOM WINDOWS FOR ACTIVE MODULES -->
        <div class="fullscreen-zoom-overlay" id="zoom-stats">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-stats" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-plag">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-plag" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-proposal">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-proposal" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-final">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-final" class="overlay-iframe-container"></iframe>
        </div>

        <!-- FULL-SCREEN FRAME PREVIEWS -->
        <div class="fullscreen-zoom-overlay" id="zoom-activities">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-activities" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-chat">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-chat" class="overlay-iframe-container" scrolling="no"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-calendar">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-calendar" class="overlay-iframe-container"></iframe>
        </div>

        <!-- SIDEBAR FULLSCREEN ZOOM OVERLAYS -->
        <div class="fullscreen-zoom-overlay" id="zoom-profile">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-profile" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-members">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-members" class="overlay-iframe-container"></iframe>
        </div>

        <div class="fullscreen-zoom-overlay" id="zoom-settings">
            <div class="nav-back-wrapper">
                <button class="btn-vector-left-back" onclick="history.back()"><i data-lucide="arrow-left"></i></button>
            </div>
            <iframe id="frame-settings" class="overlay-iframe-container"></iframe>
        </div>
    </div>

    <!-- NOTIFICATION SYSTEM DRAWER -->
    <div class="notif-drawer-overlay" id="notifOverlay" onclick="toggleNotifDrawer()"></div>
    <div class="notif-drawer" id="notifDrawer">
        <div class="notif-header">
            <h3>Notifications</h3>
            <button class="btn-close-drawer" onclick="toggleNotifDrawer()">&times;</button>
        </div>
        <div class="notif-list-scroll">
            <?php
            // Drawer notifications = review-outcome logs, LEFT JOINed to uploads so each row can
            // deep-link to the module it belongs to.
            $all_act_stmt = $pdo->prepare(
                "SELECT a.title, a.description, a.status_type, a.created_at, u.item_id
                 FROM activity_logs a
                 LEFT JOIN uploads u ON a.upload_id = u.upload_id
                 WHERE a.user_id = ? AND a.status_type IN ('success', 'warning')
                 ORDER BY a.created_at DESC LIMIT 15"
            );
            $all_act_stmt->execute([$user_id]);
            $drawer_acts = $all_act_stmt->fetchAll();

            // Which module window a notification opens. item_id is authoritative (proposal 11–16,
            // final 21–27, stats 30–37, plag 4/40); fall back to title keywords; null = not clickable.
            $notif_module_targets = [
                'proposal' => ['zoom-proposal', 'module_proposal.php'],
                'final'    => ['zoom-final', 'module_final.php'],
                'stats'    => ['zoom-stats', 'module_statistics.php'],
                'plag'     => ['zoom-plag', 'module_plagiarism.php'],
            ];
            $notif_module_for = function ($item_id, $title) {
                if ($item_id !== null) {
                    $id = (int) $item_id;
                    if ($id === 4 || $id === 40) return 'plag';
                    if ($id >= 11 && $id <= 16) return 'proposal';
                    if ($id >= 21 && $id <= 27) return 'final';
                    if ($id >= 30 && $id <= 37) return 'stats';
                }
                $t = strtolower($title);
                if (strpos($t, 'plagiar') !== false || strpos($t, 'turnitin') !== false) return 'plag';
                if (strpos($t, 'statist') !== false || strpos($t, 'payment') !== false || strpos($t, 'registered') !== false
                    || strpos($t, 'rdc') !== false || strpos($t, 'coded data') !== false || strpos($t, 'treatment') !== false) return 'stats';
                if (strpos($t, 'form no. 008') !== false || strpos($t, 'form 008') !== false || strpos($t, 'capsule') !== false
                    || strpos($t, 'endorsement') !== false || strpos($t, 'adviser') !== false || strpos($t, 'proposal') !== false) return 'proposal';
                if (strpos($t, 'manuscript') !== false || strpos($t, 'final') !== false) return 'final';
                return null;
            };

            foreach ($drawer_acts as $act):
                $mod = $notif_module_for($act['item_id'] ?? null, $act['title']);
                $target = $mod ? $notif_module_targets[$mod] : null;
                $item_arg = ($target && isset($act['item_id']) && $act['item_id'] !== null) ? ', ' . (int) $act['item_id'] : '';
            ?>
                <div class="notif-compact-row <?= $act['status_type'] ?><?= $target ? ' is-clickable' : '' ?>"<?= $target ? ' onclick="goToNotif(\'' . $target[0] . '\', \'' . $target[1] . '\'' . $item_arg . ')"' : '' ?>>
                    <div class="notif-info">
                        <div class="notif-title-row">
                            <h5><?= htmlspecialchars($act['title']) ?></h5>
                            <?php if ($act['status_type'] === 'success'): ?>
                                <span class="notif-chip success">Approved</span>
                            <?php elseif ($act['status_type'] === 'warning'): ?>
                                <span class="notif-chip warning">Revision</span>
                            <?php else: ?>
                                <span class="notif-chip info">Notice</span>
                            <?php endif; ?>
                        </div>
                        <p><?= htmlspecialchars($act['description']) ?></p>
                        <span class="time-stamp"><i data-lucide="clock" style="width:10px; height:10px; margin-right: 4px;"></i><?= date('M d, Y • h:i A', strtotime($act['created_at'])) ?></span>
                    </div>
                    <?php if ($target): ?><i data-lucide="chevron-right" class="notif-go-arrow"></i><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Stage hover-peek (desktop only; wired up in the STAGE PEEK script block below) -->
    <div class="peek-scrim" id="peekScrim" aria-hidden="true">
        <div class="peek-panel" id="peekPanel" role="dialog" aria-label="Milestone preview"></div>
    </div>

    <!-- Card Info Modal -->
    <div class="card-modal-overlay" id="cardInfoModal" onclick="closeCardModal(event)">
        <div class="card-modal-box" id="cardModalBox">
            <div class="modal-header-band" id="modalHeaderBand">
                <div class="modal-header-icon" id="modalHeaderIcon"></div>
                <div style="flex:1;">
                    <div class="modal-title" id="modalTitle">Milestone Details</div>
                    <div class="modal-subtitle" id="modalSubtitle">Steps to complete</div>
                </div>
                <button class="modal-close-btn" onclick="document.getElementById('cardInfoModal').classList.remove('active')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <style>
        /* ── STAGE HOVER-PEEK (desktop only) ─────────────────────────────────────────────
           Hover-dwell on a milestone card grows it into a large preview over a blurred
           backdrop. Kept OFF for touch/coarse pointers and below 1025px — a hover-triggered
           overlay has no sane meaning there, and the mobile wallet-card stack owns that
           breakpoint. The JS guards on the same conditions; this is the belt-and-braces half. */
        .peek-scrim {
            position: fixed;
            inset: 0;
            z-index: 98000;
            /* below .card-modal-overlay (99000) so the ⋮ "what to do" modal still layers on top */
            background: rgba(9, 13, 18, 0.34);
            backdrop-filter: blur(16px) saturate(140%);
            -webkit-backdrop-filter: blur(16px) saturate(140%);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.32s var(--smooth), visibility 0.32s var(--smooth);
        }

        .peek-scrim.open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        /* left/top are written by JS each time so the panel grows centred on the card that
           spawned it (clamped to the viewport) — that also guarantees the pointer is inside
           the panel the moment it opens, so travelling card → panel never trips the close. */
        .peek-panel {
            position: fixed;
            left: 0;
            top: 0;
            width: 540px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 30px;
            box-shadow: var(--shadow-xl), 0 0 0 1px rgba(15, 23, 42, 0.03);
            padding: 26px 26px 22px;
            cursor: pointer;
            /* Never taller than the viewport — the 7-requirement Final Defense panel on a short
               laptop screen is the case that needs this; it scrolls itself instead of overflowing. */
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: none;
            will-change: transform;
            transition: transform 0.46s var(--spring);
        }

        .peek-panel::-webkit-scrollbar {
            display: none;
        }

        body.theme-dark .peek-panel {
            border-color: rgba(255, 255, 255, 0.09);
            box-shadow: 0 32px 80px -16px rgba(0, 0, 0, 0.6);
        }

        /* Content cross-fades in slightly behind the geometry so the scale-up reads as the
           card "becoming" the panel rather than a new box appearing. */
        .peek-inner {
            opacity: 0;
            transition: opacity 0.22s var(--smooth);
        }

        .peek-scrim.open .peek-inner {
            opacity: 1;
            transition-delay: 0.1s;
        }

        .peek-ghost-num {
            position: absolute;
            top: -34px;
            right: -8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 150px;
            font-weight: 800;
            color: rgba(15, 23, 42, 0.04);
            line-height: 1;
            user-select: none;
            pointer-events: none;
        }

        body.theme-dark .peek-ghost-num {
            color: rgba(255, 255, 255, 0.035);
        }

        .peek-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            position: relative;
            z-index: 1;
        }

        .peek-head h3 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            line-height: 1.15;
        }

        .peek-updated {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Big ring + the single "what's next" line, side by side */
        .peek-stat-row {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-top: 20px;
            padding: 16px 18px;
            border-radius: 20px;
            background: color-mix(in srgb, var(--active-accent) 5%, transparent);
            position: relative;
            z-index: 1;
        }

        body.theme-dark .peek-stat-row {
            background: rgba(255, 255, 255, 0.04);
        }

        .peek-ring-wrap {
            position: relative;
            width: 84px;
            height: 84px;
            flex-shrink: 0;
        }

        .peek-ring-wrap svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }

        .peek-ring-track {
            fill: none;
            stroke: var(--border-subtle);
            stroke-width: 6;
        }

        .peek-ring-fill {
            fill: none;
            stroke: var(--active-accent);
            stroke-width: 6;
            stroke-linecap: round;
            stroke-dasharray: 175.93;
            /* 2π × 28 — same geometry as the card's .cp-fill */
            stroke-dashoffset: 175.93;
            transition: stroke-dashoffset 0.9s var(--spring);
        }

        .peek-ring-fill.approved {
            stroke: var(--color-approved);
        }

        .peek-ring-fill.review {
            stroke: var(--color-review);
        }

        .peek-ring-pct {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            font-size: 20px;
            font-weight: 800;
            color: var(--text-primary);
        }

        .peek-next-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
        }

        .peek-next-text {
            margin-top: 4px;
            font-size: 15px;
            font-weight: 650;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .peek-list-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin: 20px 2px 8px;
            position: relative;
            z-index: 1;
        }

        .peek-list-head strong {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
        }

        .peek-list-count {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .peek-list {
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        .peek-row {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 2px;
            border-top: 1px solid var(--border-subtle);
        }

        .peek-row:first-child {
            border-top: none;
        }

        .peek-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--border-subtle);
            box-shadow: 0 0 0 3px transparent;
        }

        .peek-dot.ok {
            background: var(--color-approved);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14);
        }

        .peek-dot.review {
            background: var(--color-review);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.14);
        }

        .peek-dot.revision {
            background: var(--color-revision);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.14);
        }

        .peek-dot.pending {
            background: var(--color-pending);
            box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.14);
        }

        .peek-row-name {
            flex: 1;
            min-width: 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .peek-row-note {
            display: block;
            font-size: 10.5px;
            font-weight: 500;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .peek-row-status {
            font-size: 10.5px;
            font-weight: 700;
            white-space: nowrap;
            color: var(--text-muted);
        }

        .peek-row-status.ok {
            color: #059669;
        }

        .peek-row-status.review {
            color: #b45309;
        }

        .peek-row-status.revision {
            color: #dc2626;
        }

        .peek-row-status.pending {
            color: var(--text-secondary);
        }

        .peek-foot {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
            position: relative;
            z-index: 1;
        }

        .peek-cta {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 18px;
            border-radius: 15px;
            border: none;
            background: var(--active-accent);
            color: #fff;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: filter 0.2s var(--smooth), transform 0.2s var(--smooth);
        }

        .peek-cta:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        .peek-cta svg {
            width: 16px;
            height: 16px;
        }

        .peek-steps-btn {
            padding: 13px 16px;
            border-radius: 15px;
            border: 1px solid var(--border-subtle);
            background: transparent;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 13px;
            font-weight: 650;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s var(--smooth), color 0.2s var(--smooth);
        }

        .peek-steps-btn:hover {
            background: color-mix(in srgb, var(--active-accent) 8%, transparent);
            color: var(--text-primary);
        }

        .peek-hint {
            margin: 12px 0 0;
            text-align: center;
            font-size: 10.5px;
            color: var(--text-muted);
            position: relative;
            z-index: 1;
        }

        /* The card that spawned the peek stays put but drops its hover lift, so it doesn't
           fight the panel for attention through the blur. */
        .stage-card-tab.peek-source {
            transform: none !important;
            box-shadow: var(--shadow-sm) !important;
        }

        @media (prefers-reduced-motion: reduce) {

            .peek-panel,
            .peek-inner,
            .peek-ring-fill {
                transition: none !important;
            }
        }

        /* Touch, coarse pointers and every non-desktop width: the peek does not exist. */
        @media (max-width: 1024px),
        (hover: none),
        (pointer: coarse) {
            .peek-scrim {
                display: none !important;
            }
        }

        /* ── CARD INFO MODAL ── */
        /* Use visibility+opacity (NOT display:none) so CSS transitions fire correctly */
        .card-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(9, 13, 18, 0.45);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            z-index: 99000;
            display: flex;
            /* always flex — visibility controls show/hide */
            align-items: center;
            justify-content: center;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.28s var(--smooth), visibility 0.28s var(--smooth);
        }

        .card-modal-overlay.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .card-modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 28px;
            width: 100%;
            max-width: 400px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            transform: scale(0.88) translateY(20px);
            transition: transform 0.38s var(--spring);
        }

        .card-modal-overlay.active .card-modal-box {
            transform: scale(1) translateY(0);
        }

        .modal-header-band {
            padding: 22px 22px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .modal-header-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .modal-header-icon svg,
        .modal-header-icon i {
            width: 22px;
            height: 22px;
            color: #ffffff !important;
            stroke: #ffffff !important;
        }

        .modal-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .modal-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: 500;
        }

        .modal-close-btn {
            margin-left: auto;
            background: var(--bg-canvas);
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: all 0.2s var(--smooth);
            flex-shrink: 0;
        }

        .modal-close-btn:hover {
            color: var(--color-revision);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-close-btn svg {
            width: 15px;
            height: 15px;
        }

        .modal-body {
            padding: 0 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .modal-step-row {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 14px;
            background: var(--bg-canvas);
            border-radius: 14px;
            border: 1px solid var(--border-subtle);
            transition: background 0.2s;
        }

        .modal-step-row:hover {
            background: var(--bg-card);
        }

        .modal-step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .modal-step-text {
            font-size: 12.5px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .modal-step-text strong {
            color: var(--text-primary);
            font-weight: 700;
        }

        .modal-cta-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px;
            border: none;
            border-radius: 16px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            transition: filter 0.2s, transform 0.25s var(--spring);
        }

        .modal-cta-btn:hover {
            filter: brightness(1.12);
            transform: translateY(-2px);
        }

        .modal-cta-btn svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.5px;
        }


        /* BOTTOM SHEET FOR PROPOSAL DEFENSE (MOBILE) */
        @media (max-width: 768px) {
            #zoom-proposal, #zoom-stats, #zoom-plag, #zoom-final {
                top: auto !important;
                bottom: 0 !important;
                height: 85vh !important;
                transform: translateY(100%);
                border-radius: 24px 24px 0 0 !important;
                overflow: hidden !important;
                box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15) !important;
                transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
                padding-top: 15px !important;
            }

            #zoom-proposal.active, #zoom-stats.active, #zoom-plag.active, #zoom-final.active {
                transform: translateY(0) !important;
            }
        }
    </style>

    <script>
        // Server-to-Client time synchronization variables to bypass timezone discrepancies
        const SERVER_CURRENT_TIME = <?= time() ?> * 1000;
        const CLIENT_START_TIME = Date.now();

        // Custom Toast System
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast-notif toast-${type}`;
            toast.style.pointerEvents = 'auto';

            let iconName = 'check-circle';
            if (type === 'error') iconName = 'x-circle';
            if (type === 'warning') iconName = 'alert-triangle';
            if (type === 'info') iconName = 'info';

            // No dismiss × — it leaves on its own, and a close button on a 5-second
            // notice is exactly what made this read as an old-style alert box.
            // The eagle carries the mood (2026-10-05): happy when something went through, worried
            // when it did not, a wink for a tip. The status colour stays on a dot beside her.
            const pose = { success: 'happy', error: 'oops', warning: 'oops', info: 'wink' }[type] || 'happy';
            toast.innerHTML = `
                <img src="../assets/mascot/eagle/${pose}.svg" alt="" aria-hidden="true" class="toast-eagle">
                <i data-lucide="${iconName}" class="toast-icon"></i>
                <span class="toast-message">${message}</span>
            `;

            container.appendChild(toast);
            lucide.createIcons();

            setTimeout(() => {
                toast.classList.add('visible');
            }, 10);

            setTimeout(() => {
                toast.classList.remove('visible');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 5000);
        }

        // Replace native window.alert
        window.alert = function(msg) {
            showToast(msg, 'info');
        };

        // Theme Toggle Handler (keeps backwards compatibility)
        function toggleTheme() {
            toggleThemeWithRipple(null);
        }

        // ── THEME TOGGLE WITH VIEW TRANSITIONS CIRCULAR RIPPLE ──
        function toggleThemeWithRipple(event) {
            const isDark = document.body.classList.contains('theme-dark');
            const newTheme = isDark ? 'theme-default' : 'theme-dark';

            const applyTheme = () => {
                document.body.className = newTheme;
                localStorage.setItem('rd-portal-theme', newTheme);
                document.querySelectorAll('iframe').forEach(iframe => {
                    try {
                        if (iframe.contentWindow && iframe.contentWindow.document) {
                            iframe.contentWindow.document.body.className = newTheme;
                        }
                    } catch (e) {}
                });
            };

            // View Transitions API — circular reveal from click position
            if (!document.startViewTransition || !event) {
                applyTheme();
                return;
            }

            const x = event.clientX ?? window.innerWidth / 2;
            const y = event.clientY ?? window.innerHeight / 2;
            const endRadius = Math.hypot(
                Math.max(x, window.innerWidth - x),
                Math.max(y, window.innerHeight - y)
            );

            // Going light (isDark === true) reverses the ripple: the old dark snapshot shrinks away.
            // Put it on top via a temporary root class so the shrink is actually visible.
            if (isDark) document.documentElement.classList.add('vt-reverse');

            const transition = document.startViewTransition(applyTheme);

            transition.ready.then(() => {
                const clipPath = [
                    `circle(0px at ${x}px ${y}px)`,
                    `circle(${endRadius}px at ${x}px ${y}px)`
                ];
                document.documentElement.animate({
                    clipPath: isDark ? [...clipPath].reverse() : clipPath
                }, {
                    duration: 420,
                    easing: 'ease-in-out',
                    pseudoElement: isDark ?
                        '::view-transition-old(root)' : '::view-transition-new(root)'
                });
            });

            transition.finished.finally(() => {
                document.documentElement.classList.remove('vt-reverse');
            });
        }

        // Global theme setter for child iframe modules (e.g. settings.php)
        window.setThemeSafely = function(themeName) {
            document.body.className = themeName;
            localStorage.setItem('rd-portal-theme', themeName);
            document.querySelectorAll('iframe').forEach(iframe => {
                try {
                    if (iframe.contentWindow && iframe.contentWindow.document) {
                        iframe.contentWindow.document.body.className = themeName;
                    }
                } catch (e) {}
            });
        };

        // Instantly synchronize theme changes across frames/tabs via localStorage events
        window.addEventListener('storage', function(e) {
            if (e.key === 'rd-portal-theme' && e.newValue) {
                document.body.className = e.newValue;
            }
        });

        // ── CARD INFO MODAL DATA ──
        const CARD_MODAL_DATA = {
            proposal: {
                title: '1. Proposal Defense',
                subtitle: 'Steps to complete this stage',
                color: '#9333ea',
                icon: 'file-text',
                steps: [{
                        t: 'Upload required documents',
                        d: 'Submit your concept paper, ethics form, adviser endorsement, and panelist forms via the portal.'
                    },
                    {
                        t: 'Await coordinator review',
                        d: 'Your coordinator reviews the documents. You may get revision requests — update and resubmit promptly.'
                    },
                    {
                        t: 'Schedule your defense',
                        d: 'Once all documents are approved, coordinate with your adviser and department for a defense schedule.'
                    },
                    {
                        t: 'Clear the milestone',
                        d: 'After a successful defense, the coordinator marks this stage as Cleared and you advance.'
                    },
                ],
                open: 'zoom-proposal',
                url: 'module_proposal.php'
            },
            final: {
                title: '2. Final Defense',
                subtitle: 'Requirements for the final thesis defense',
                color: '#7c3aed',
                icon: 'award',
                steps: [{
                        t: 'Complete your full manuscript',
                        d: 'Finalize chapters I–V with all revisions from your proposal defense incorporated.'
                    },
                    {
                        t: 'Secure panel signatures',
                        d: 'Upload the signed endorsement and approval sheets from your panel members and adviser.'
                    },
                    {
                        t: 'Submit final documents',
                        d: 'Upload the complete manuscript package including abstract, title page, and bound copy receipt.'
                    },
                    {
                        t: 'Defend and get cleared',
                        d: 'Present your completed research to the panel. Upon approval, this milestone is marked Cleared.'
                    },
                ],
                open: 'zoom-final',
                url: 'module_final.php'
            },
            stats: {
                title: '3. Statistics Review',
                subtitle: 'Submission for statistical validation',
                color: '#6d28d9',
                icon: 'calculator',
                steps: [{
                        t: 'Prepare your data file',
                        d: 'Organize your raw data and statistical outputs (SPSS / Excel) into a clear, readable format.'
                    },
                    {
                        t: 'Fill the statistics form',
                        d: 'Access the Statistics Review module and complete the submission form with your encoded data.'
                    },
                    {
                        t: 'Await statistician validation',
                        d: 'The institutional statistician reviews your data for accuracy, completeness, and integrity.'
                    },
                    {
                        t: 'Receive clearance',
                        d: 'Once validated, the statistician approves and this milestone is marked Cleared.'
                    },
                ],
                open: 'zoom-stats',
                url: 'module_statistics.php'
            },
            plag: {
                title: '4. Plagiarism Test',
                subtitle: 'Originality and integrity verification',
                color: '#5b21b6',
                icon: 'shield-alert',
                steps: [{
                        t: 'Prepare your manuscript PDF',
                        d: 'Export a clean PDF of your complete manuscript — no tracked changes or comments.'
                    },
                    {
                        t: 'Upload for scanning',
                        d: 'Submit through the Plagiarism Test module. The file is run against academic databases.'
                    },
                    {
                        t: 'Review similarity report',
                        d: 'Your coordinator reviews the similarity index. A score below the threshold is needed for clearance.'
                    },
                    {
                        t: 'Get originality clearance',
                        d: 'Upon passing the threshold, your coordinator marks this stage as Cleared.'
                    },
                ],
                open: 'zoom-plag',
                url: 'module_plagiarism.php'
            }
        };

        // Inline SVG map — avoids calling lucide.createIcons() (full DOM scan = freeze)
        const MODAL_ICON_SVG = {
            'file-text': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`,
            'award': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>`,
            'calculator': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="16" y1="10" x2="8" y2="10"/><line x1="11" y1="14" x2="8" y2="14"/><line x1="11" y1="18" x2="8" y2="18"/><line x1="16" y1="14" x2="14" y2="14"/><line x1="16" y1="18" x2="14" y2="18"/></svg>`,
            'shield-alert': `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`
        };

        function openCardModal(e, cardKey) {
            e.stopPropagation();
            const d = CARD_MODAL_DATA[cardKey];
            if (!d) return;

            // Use pre-built SVGs — no lucide.createIcons() needed (avoids full DOM scan)
            document.getElementById('modalHeaderIcon').innerHTML = MODAL_ICON_SVG[d.icon] || '';
            document.getElementById('modalHeaderIcon').style.background = d.color;
            document.getElementById('modalHeaderBand').style.borderBottom = `3px solid ${d.color}25`;
            document.getElementById('modalTitle').textContent = d.title;
            document.getElementById('modalSubtitle').textContent = d.subtitle;

            const arrowSVG = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><line x1='5' y1='12' x2='19' y2='12'/><polyline points='12 5 19 12 12 19'/></svg>`;

            document.getElementById('modalBody').innerHTML =
                d.steps.map((s, i) => `
                    <div class="modal-step-row">
                        <div class="modal-step-num" style="background:${d.color}">${i + 1}</div>
                        <div class="modal-step-text"><strong>${s.t}</strong><br>${s.d}</div>
                    </div>`).join('') +
                `<button class="modal-cta-btn" style="background:${d.color}"
                    onclick="closeCardModalNow(); pushView('${d.open}','${d.url}')">
                    ${arrowSVG} Open ${d.title}
                </button>`;

            // Show with CSS transition (visibility-based, no display toggle = no freeze)
            document.getElementById('cardInfoModal').classList.add('active');
        }

        function closeCardModalNow() {
            document.getElementById('cardInfoModal').classList.remove('active');
        }

        function closeCardModal(e) {
            // Only close when clicking the dark overlay itself, not the box
            if (e.target.id === 'cardInfoModal') closeCardModalNow();
        }

        // ESC key closes the card modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCardModalNow();
            }
        });

        // ── STAGE HOVER-PEEK (desktop only) ────────────────────────────────────────────────
        // Hover-dwell on a milestone card grows it into a large live preview over a blurred
        // scrim. Purely presentational: it renders a read-only projection of data the page
        // already fetched, and the only actions it can take are the same pushView()/
        // openCardModal() calls the card itself offers. No POSTs, no workflow logic.
        const STAGE_PEEK_DATA = <?= json_encode($stage_peek_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

        (function() {
            const scrim = document.getElementById('peekScrim');
            const panel = document.getElementById('peekPanel');
            if (!scrim || !panel) return;

            const DWELL_MS = 380; // hover time before the peek commits — long enough that
            //                       sweeping the pointer across the row doesn't trigger it
            const LEAVE_MS = 160; // grace period after the pointer leaves the panel
            const MARGIN = 20; // viewport clamp
            const RING_C = 175.93; // 2π × 28, matches the card's .cp-fill

            let dwellTimer = null,
                leaveTimer = null;
            let openKey = null,
                sourceCard = null,
                fromTransform = '';

            // Same conditions as the CSS media guard — no peek on touch or below 1025px.
            const isDesktop = () => window.matchMedia('(min-width: 1025px) and (hover: hover) and (pointer: fine)').matches;

            const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ESC_MAP[c]);

            const ARROW = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>`;
            // Inline, like ARROW, so a locked panel needs no lucide rescan on hover.
            const LOCKGLYPH = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;

            function buildHTML(d) {
                const done = d.items.filter((i) => i.tone === 'ok').length;
                // Status chips here deliberately carry no lucide <i> icon: injecting one would
                // force a lucide.createIcons() full-DOM rescan on every hover (see the note on
                // MODAL_ICON_SVG above — that scan is what froze the card modal).
                const rows = d.items.map((i) => `
                    <div class="peek-row">
                        <span class="peek-dot ${esc(i.tone)}"></span>
                        <span class="peek-row-name">${esc(i.name)}${i.note ? `<span class="peek-row-note">${esc(i.note)}</span>` : ''}</span>
                        <span class="peek-row-status ${esc(i.tone)}">${esc(i.label)}</span>
                    </div>`).join('');

                return `
                    <div class="peek-ghost-num">${esc(d.num)}</div>
                    <div class="peek-inner">
                        <div class="peek-head">
                            <div style="flex:1;min-width:0;">
                                <h3>${esc(d.title)}</h3>
                                ${d.updated ? `<span class="peek-updated">${d.pct >= 100 ? 'Cleared' : 'Updated'} ${esc(d.updated)}</span>` : ''}
                            </div>
                            ${d.chip ? `<span class="status-chip ${esc(d.chip)}">${esc(d.chipLabel)}</span>` : ''}
                        </div>
                        <div class="peek-stat-row">
                            <div class="peek-ring-wrap">
                                <svg viewBox="0 0 72 72">
                                    <circle class="peek-ring-track" cx="36" cy="36" r="28"/>
                                    <circle class="peek-ring-fill ${esc(d.ring)}" cx="36" cy="36" r="28"/>
                                </svg>
                                <div class="peek-ring-pct">${esc(d.pct)}%</div>
                            </div>
                            <div style="min-width:0;">
                                <div class="peek-next-label">What's next</div>
                                <div class="peek-next-text">${esc(d.next)}</div>
                            </div>
                        </div>
                        <div class="peek-list-head">
                            <strong>${esc(d.heading)}</strong>
                            <span class="peek-list-count">${done} of ${d.items.length} done</span>
                        </div>
                        <div class="peek-list">${rows}</div>
                        <div class="peek-foot">
                            <button type="button" class="peek-cta">${d.locked ? LOCKGLYPH + ' Locked' : ARROW + ' Open ' + esc(d.title)}</button>
                            <button type="button" class="peek-steps-btn" data-peek-steps="1">What to do</button>
                        </div>
                        <p class="peek-hint">${d.locked ? esc(d.lockWhy) : 'Click to open · move away or press Esc to dismiss'}</p>
                    </div>`;
            }

            function openPeek(card, key) {
                const d = STAGE_PEEK_DATA[key];
                if (!d) return;
                openKey = key;
                sourceCard = card;
                card.classList.add('peek-source');
                panel.innerHTML = buildHTML(d);

                // Measure the panel at its resting size, then anchor it centred on the card that
                // spawned it (clamped to the viewport). Anchoring rather than centring on screen
                // is what makes the pointer land INSIDE the panel the instant it opens — so
                // travelling from card to panel can never trip the leave-to-close.
                panel.style.transition = 'none';
                panel.style.transform = 'none';
                panel.style.left = '0px';
                panel.style.top = '0px';
                const pw = panel.offsetWidth,
                    ph = panel.offsetHeight;
                const c = card.getBoundingClientRect();
                const left = Math.min(Math.max(c.left + c.width / 2 - pw / 2, MARGIN), Math.max(MARGIN, window.innerWidth - pw - MARGIN));
                const top = Math.min(Math.max(c.top + c.height / 2 - ph / 2, MARGIN), Math.max(MARGIN, window.innerHeight - ph - MARGIN));
                panel.style.left = left + 'px';
                panel.style.top = top + 'px';

                // FLIP: start life as the card's own footprint, then spring out to full size.
                const scale = Math.max(0.15, c.width / pw);
                const dx = (c.left + c.width / 2) - (left + pw / 2);
                const dy = (c.top + c.height / 2) - (top + ph / 2);
                fromTransform = `translate(${dx}px, ${dy}px) scale(${scale})`;
                panel.style.transform = fromTransform;
                void panel.offsetWidth; // flush, so the transition has a start value to run from
                panel.style.transition = '';
                panel.style.transform = 'none';

                scrim.classList.add('open');
                scrim.setAttribute('aria-hidden', 'false');

                const fill = panel.querySelector('.peek-ring-fill');
                if (fill) {
                    requestAnimationFrame(() => {
                        fill.style.strokeDashoffset = (RING_C * (1 - Math.min(100, Math.max(0, d.pct)) / 100)).toFixed(2);
                    });
                }
            }

            function closePeek() {
                clearTimeout(dwellTimer);
                clearTimeout(leaveTimer);
                dwellTimer = leaveTimer = null;
                if (!openKey) return;
                openKey = null;
                scrim.classList.remove('open');
                scrim.setAttribute('aria-hidden', 'true');
                if (fromTransform) panel.style.transform = fromTransform; // shrink back into the card
                if (sourceCard) sourceCard.classList.remove('peek-source');
                sourceCard = null;
            }

            document.querySelectorAll('.stage-card-tab[data-peek]').forEach((card) => {
                const key = card.getAttribute('data-peek');

                card.addEventListener('mouseenter', () => {
                    // Don't peek while a module is already open over the dashboard
                    if (!isDesktop() || document.body.classList.contains('zoom-active')) return;
                    if (openKey === key) return;
                    clearTimeout(dwellTimer);
                    dwellTimer = setTimeout(() => openPeek(card, key), DWELL_MS);
                });
                card.addEventListener('mouseleave', () => clearTimeout(dwellTimer));

                // Keep the ⋮ "what to do" button directly clickable — hold the peek off while
                // the pointer is on it (the peek also carries its own "What to do" button).
                const dot = card.querySelector('.card-dot-btn');
                if (dot) dot.addEventListener('mouseenter', () => clearTimeout(dwellTimer));
            });

            panel.addEventListener('mouseenter', () => clearTimeout(leaveTimer));
            panel.addEventListener('mouseleave', () => {
                clearTimeout(leaveTimer);
                leaveTimer = setTimeout(closePeek, LEAVE_MS);
            });

            scrim.addEventListener('click', (e) => {
                if (!openKey) return;
                const d = STAGE_PEEK_DATA[openKey];
                if (!panel.contains(e.target)) {
                    closePeek(); // clicked the backdrop → just dismiss
                    return;
                }
                if (e.target.closest('[data-peek-steps]')) {
                    const key = openKey;
                    closePeek();
                    openCardModal(e, key);
                    return;
                }
                closePeek();
                // A locked stage is locked here too: the panel is a preview of the card, so it
                // must obey the same gate rather than offering a way around it.
                if (d.locked) { lockedCardNote(d.lockWhy); return; }
                pushView(d.open, d.url);
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closePeek();
            });
            window.addEventListener('resize', closePeek);
            window.addEventListener('blur', closePeek);
            window.addEventListener('popstate', closePeek);
            window.addEventListener('wheel', (e) => {
                if (!openKey) return;
                // Let a tall panel scroll its own overflow; any other wheel dismisses.
                if (panel.scrollHeight > panel.clientHeight + 1 && panel.contains(e.target)) return;
                closePeek();
            }, { passive: true });
        })();

        // Initialize elements
        document.addEventListener("DOMContentLoaded", function() {
            lucide.createIcons();
            fetchWeatherWidget();
            animateCircularRings();
            staggerRevealCards();
            updateRelativeTimestamps();
            setInterval(updateRelativeTimestamps, 30000);
        });

        // ── Notification unread counter ──────────────────────────────────────
        // notifTimes = epoch timestamps of review-outcome logs (the drawer's content).
        // A per-browser "last read" marker decides how many are still unread.
        const notifTimes = <?= json_encode(array_values($notif_times)) ?> || [];
        const NOTIF_READ_KEY = 'rd-notif-lastread-<?= (int) $user_id ?>';

        function refreshNotifBadge() {
            const badge = document.getElementById('notifCountBadge');
            if (!badge) return;
            const lastRead = parseInt(localStorage.getItem(NOTIF_READ_KEY) || '0', 10);
            const unread = notifTimes.filter(t => t > lastRead).length;
            if (unread > 0) {
                badge.textContent = unread > 9 ? '9+' : String(unread);
                badge.classList.add('show');
            } else {
                badge.textContent = '';
                badge.classList.remove('show');
            }
        }

        function toggleNotifDrawer() {
            const drawer = document.getElementById('notifDrawer');
            const overlay = document.getElementById('notifOverlay');
            const isOpen = drawer.classList.contains('active');
            drawer.classList.toggle('active');
            overlay.style.display = isOpen ? 'none' : 'block';

            // Opening the drawer = the user has seen the notifications → clear the count.
            // Mark read up to the NEWEST notification we already know about (not the client clock):
            // notifTimes come from the server, so comparing them to Date.now() breaks under a
            // DB/PHP timezone offset. Using the max known timestamp is timezone-agnostic and always
            // drives the unread count to 0, while a genuinely newer notification later re-shows it.
            if (!isOpen) {
                const newest = notifTimes.length ? Math.max(...notifTimes) : Math.floor(Date.now() / 1000);
                localStorage.setItem(NOTIF_READ_KEY, String(newest));
                refreshNotifBadge();
            }
        }

        refreshNotifBadge();

        // Click a notification → close the drawer and open the module it belongs to.
        // When an item id is known, deep-link to that requirement card (module reads ?item=NN).
        function goToNotif(panelId, file, itemId) {
            const drawer = document.getElementById('notifDrawer');
            if (drawer && drawer.classList.contains('active')) toggleNotifDrawer();
            if (itemId) file += (file.indexOf('?') > -1 ? '&' : '?') + 'item=' + encodeURIComponent(itemId);
            pushView(panelId, file);
        }

        // Handle avatar dropdown
        function toggleAvatarDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('avatarDropdownMenu');
            if (!menu) return;
            menu.classList.toggle('active');
        }

        document.addEventListener('click', function(e) {
            const menu = document.getElementById('avatarDropdownMenu');
            if (menu && menu.classList.contains('active')) {
                const wrapper = document.querySelector('.avatar-dropdown-wrapper');
                const profile = document.querySelector('.dock-user-profile');
                if ((!wrapper || !wrapper.contains(e.target)) && (!profile || !profile.contains(e.target))) {
                    menu.classList.remove('active');
                }
            }
        });

        // Dock Item Selection Highlighter
        function selectDockItem(btn) {
            document.querySelectorAll('.dock-btn').forEach(b => {
                b.classList.remove('active');
            });
            btn.classList.add('active');
        }

        // Mobile bottom navigation router helper
        function handleBottomNav(view, btnEl) {
            selectDockItem(btnEl);
            if (view === 'dashboard') {
                collapseZoomModules();
            } else if (view === 'activities') {
                pushView('zoom-activities', 'activities_all.php');
            } else if (view === 'profile') {
                pushView('zoom-profile', 'profile.php');
            } else if (view === 'members') {
                pushView('zoom-members', 'members.php');
            } else if (view === 'settings') {
                pushView('zoom-settings', 'settings.php');
            } else if (view === 'chat') {
                openGroupChat();
            }
        }

        // Launch zoom dynamic modules
        // Tapping a locked stage card. Says why instead of doing nothing, which would read as a
        // broken card — but deliberately NOT through showToast(): that toast carries a dismiss ×
        // and slides in from the corner, which is far too heavy for "not yet". This is a small
        // lock pill that fades in over the card itself and leaves on its own.
        function lockedCardNote(reason, el, ms) {
            var card = el || (window.event && window.event.currentTarget) || null;
            var pill = document.getElementById('lockPill');
            if (!pill) {
                pill = document.createElement('div');
                pill.id = 'lockPill';
                pill.className = 'lock-pill';
                document.body.appendChild(pill);
            }
            pill.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ' +
                'stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/>' +
                '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span></span>';
            pill.querySelector('span').textContent = reason;

            // Sit it over the card that was tapped; fall back to centre-screen on phones where
            // the card may be under the thumb.
            pill.classList.remove('show');
            void pill.offsetWidth;
            if (card && card.getBoundingClientRect) {
                var r = card.getBoundingClientRect();
                pill.style.left = Math.round(r.left + r.width / 2) + 'px';
                pill.style.top = Math.round(r.top + r.height / 2) + 'px';
            } else {
                pill.style.left = '50%';
                pill.style.top = '42%';
            }
            pill.classList.add('show');
            clearTimeout(window.__lockPill);
            window.__lockPill = setTimeout(function () { pill.classList.remove('show'); }, ms || 2600);
        }

        function pushView(panelId, url = null, triggerEl = null) {
            const moduleName = panelId.replace("zoom-", "");
            history.pushState({
                panelId: panelId,
                url: url
            }, "", "#" + moduleName);

            collapseZoomModules();

            // Link matching dock button
            const mapPanelToNav = {
                'zoom-activities': 'activities',
                'zoom-profile': 'profile',
                'zoom-members': 'members',
                'zoom-settings': 'settings',
                'zoom-chat': 'chat'
            };

            const panel = document.getElementById(panelId);
            if (panel) {
                if (url) {
                    const iframe = panel.querySelector('iframe');
                    // Avoid pushing a duplicate history state when loading the iframe
                    if (!iframe.src || !iframe.src.includes(url)) {
                        if (iframe.contentWindow) {
                            iframe.contentWindow.location.replace(url);
                        } else {
                            iframe.src = url;
                        }
                    }
                }
                panel.style.display = 'block';
                document.body.classList.add('zoom-active');
                setTimeout(() => {
                    panel.classList.add('active');
                }, 30);
            }
        }

        function collapseZoomModules() {
            document.body.classList.remove('zoom-active');
            const openOverlays = document.querySelectorAll('.fullscreen-zoom-overlay');
            openOverlays.forEach(panel => {
                panel.classList.remove('active');
                setTimeout(() => {
                    if (!panel.classList.contains('active')) {
                        panel.style.display = 'none';
                    }
                }, 400);
            });
            // Automatically select Home button in the dock
            const homeBtn = document.querySelector('.dock-btn[data-view="home"]');
            if (homeBtn) {
                selectDockItem(homeBtn);
            }
        }

        // Live Chat badging
        function fetchChatBadge() {
            const chatOverlay = document.getElementById('zoom-chat');
            if (chatOverlay && chatOverlay.classList.contains('active')) {
                fetch('?ajax=mark_chat_read');
                const badge = document.getElementById('chat-badge');
                if (badge) badge.style.display = 'none';
                return;
            }

            fetch('?ajax=chat_badge')
                .then(r => r.text())
                .then(countStr => {
                    const count = parseInt(countStr);
                    const badge = document.getElementById('chat-badge');

                    if (badge) {
                        if (count > 0) {
                            badge.style.display = 'block';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                }).catch(e => console.error(e));
        }
        setInterval(fetchChatBadge, 5000);
        fetchChatBadge();

        function openGroupChat() {
            fetch('?ajax=mark_chat_read').then(() => {
                const badge = document.getElementById('chat-badge');
                if (badge) badge.style.display = 'none';
            });
            pushView('zoom-chat', 'message.php');
        }



        // Open-Meteo Weather integration with Philippine coordinates
        function fetchWeatherWidget() {
            const widgetEl = document.getElementById('weatherWidget');
            if (!widgetEl) return;

            const lat = 17.6132;
            const lon = 121.7270;

            fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current_weather=true`)
                .then(r => r.json())
                .then(data => {
                    const temp = Math.round(data.current_weather.temperature);
                    const code = data.current_weather.weathercode;

                    const weatherMapping = {
                        0: {
                            icon: 'sun',
                            text: 'Clear Sky',
                            color: '#8b5cf6'
                        },
                        1: {
                            icon: 'cloud-sun',
                            text: 'Mainly Clear',
                            color: '#8b5cf6'
                        },
                        2: {
                            icon: 'cloud-sun',
                            text: 'Partly Cloudy',
                            color: '#a78bfa'
                        },
                        3: {
                            icon: 'cloud',
                            text: 'Overcast',
                            color: '#64748b'
                        },
                        45: {
                            icon: 'cloud-fog',
                            text: 'Foggy',
                            color: '#a78bfa'
                        },
                        48: {
                            icon: 'cloud-fog',
                            text: 'Foggy',
                            color: '#a78bfa'
                        },
                        51: {
                            icon: 'cloud-drizzle',
                            text: 'Light Drizzle',
                            color: '#38bdf8'
                        },
                        53: {
                            icon: 'cloud-drizzle',
                            text: 'Drizzle',
                            color: '#0ea5e9'
                        },
                        55: {
                            icon: 'cloud-drizzle',
                            text: 'Heavy Drizzle',
                            color: '#0284c7'
                        },
                        61: {
                            icon: 'cloud-rain',
                            text: 'Slight Rain',
                            color: '#38bdf8'
                        },
                        63: {
                            icon: 'cloud-rain',
                            text: 'Moderate Rain',
                            color: '#0ea5e9'
                        },
                        65: {
                            icon: 'cloud-rain',
                            text: 'Heavy Rain',
                            color: '#0284c7'
                        },
                        80: {
                            icon: 'cloud-lightning-rain',
                            text: 'Rain Showers',
                            color: '#0ea5e9'
                        },
                        81: {
                            icon: 'cloud-lightning-rain',
                            text: 'Violent Showers',
                            color: '#0284c7'
                        },
                        95: {
                            icon: 'cloud-lightning',
                            text: 'Thunderstorm',
                            color: '#6366f1'
                        },
                    };

                    const weather = weatherMapping[code] || {
                        icon: 'cloud',
                        text: 'Cloudy',
                        color: '#a78bfa'
                    };

                    widgetEl.innerHTML = `
                        <div class="weather-widget-box" style="display: flex; align-items: center; gap: 10px; background: var(--bg-card); border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: 12px; box-shadow: var(--shadow-sm); transition: transform 0.2s;" onclick="fetchWeatherWidget()">
                            <div style="background: ${weather.color}15; padding: 6px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i data-lucide="${weather.icon}" style="width: 18px; height: 18px; color: ${weather.color};"></i>
                            </div>
                            <div style="text-align: left;">
                                <div style="font-size: 10px; font-weight: 750; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Tuguegarao, PH</div>
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <span style="font-size: 13px; font-weight: 800; color: var(--text-primary); font-family: 'JetBrains Mono', monospace;">${temp}°C</span>
                                    <span style="font-size: 11px; color: var(--text-secondary); font-weight: 500;">${weather.text}</span>
                                </div>
                            </div>
                        </div>
                    `;
                    lucide.createIcons();
                })
                .catch(err => {
                    widgetEl.innerHTML = `
                        <div class="weather-widget-box" style="display: flex; align-items: center; gap: 10px; background: var(--bg-card); border: 1px solid var(--border-subtle); padding: 8px 12px; border-radius: 12px;">
                            <i data-lucide="sun" style="width: 18px; height: 18px; color: #8b5cf6;"></i>
                            <div style="text-align: left;">
                                <div style="font-size: 10px; font-weight: 750; color: var(--text-muted); text-transform: uppercase;">Tuguegarao, PH</div>
                                <div style="font-size: 13px; font-weight: 800; color: var(--text-primary);">28°C • Sunny</div>
                            </div>
                        </div>
                    `;
                    lucide.createIcons();
                });
        }

        // Relative timestamp update
        function timeAgo(date, referenceDate = new Date()) {
            let seconds = Math.floor((referenceDate - date) / 1000);

            // Safe handling for slight browser clock variance or negative calculations
            if (seconds < 0) {
                seconds = 0;
            }

            let interval = Math.floor(seconds / 31536000);
            if (interval >= 1) return interval + (interval === 1 ? " year ago" : " years ago");

            interval = Math.floor(seconds / 2592000);
            if (interval >= 1) return interval + (interval === 1 ? " month ago" : " months ago");

            interval = Math.floor(seconds / 86400);
            if (interval >= 1) return interval + (interval === 1 ? " day ago" : " days ago");

            interval = Math.floor(seconds / 3600);
            if (interval >= 1) return interval + (interval === 1 ? " hour ago" : " hours ago");

            interval = Math.floor(seconds / 60);
            if (interval >= 1) return interval + (interval === 1 ? " minute ago" : " minutes ago");

            if (seconds < 10) return "just now";
            return Math.floor(seconds) + " seconds ago";
        }

        function updateRelativeTimestamps() {
            const elapsed = Date.now() - CLIENT_START_TIME;
            const currentServerTime = new Date(SERVER_CURRENT_TIME + elapsed);

            document.querySelectorAll('.timeline-time').forEach(el => {
                const ts = parseInt(el.getAttribute('data-timestamp')) * 1000;
                if (!ts) return;
                const timeStr = timeAgo(new Date(ts), currentServerTime);
                const textEl = el.querySelector('.time-text');
                if (textEl) textEl.textContent = timeStr;
            });
        }
        setInterval(updateRelativeTimestamps, 30000);

        // Circular ring animation (replaces linear bar)
        function animateCircularRings() {
            const circ = 2 * Math.PI * 28; // r=28 → 175.93
            document.querySelectorAll('.cp-fill').forEach(circle => {
                const pct = parseInt(circle.getAttribute('data-pct')) || 0;
                const offset = circ - (pct / 100) * circ;
                circle.style.strokeDashoffset = circ; // start empty
                // Double rAF to trigger CSS transition
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    circle.style.strokeDashoffset = offset;
                }));
            });
        }

        // Staggered Reveal Cards (macOS Launchpad style)
        function staggerRevealCards() {
            document.querySelectorAll('.stage-card-tab').forEach((card, idx) => {
                card.style.cssText += 'opacity:0;transform:translateY(20px) scale(0.94);transition:opacity 0.55s var(--spring),transform 0.55s var(--spring);';
                setTimeout(() => {
                    requestAnimationFrame(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0) scale(1)';
                    });
                }, 80 + idx * 90);
            });
        }

        // Calendar handler
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        const events = <?= json_encode($calendar_events) ?>;
        // Published defenses of the group's institution: date -> { rooms: [...], mine: bool }
        const defenseDays = <?= json_encode((object)$mini_defense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let holidaysData = [];

        async function fetchHolidays(year) {
            try {
                const res = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/PH`);
                if (res.ok) {
                    const data = await res.json();
                    holidaysData = data;
                    renderCalendar(); // re-render once we have holidays
                }
            } catch (e) {
                console.error("Failed to fetch holidays", e);
            }
        }

        fetchHolidays(currentYear);

        function renderCalendar() {
            const grid = document.getElementById('calendarGrid');
            const title = document.getElementById('calMonthTitle');
            if (!grid || !title) return;
            const date = new Date(currentYear, currentMonth, 1);

            title.innerText = date.toLocaleString('default', {
                month: 'long',
                year: 'numeric'
            });
            grid.innerHTML = '';

            ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(day => {
                grid.innerHTML += `<div class="cal-day-label">${day}</div>`;
            });

            const firstDay = date.getDay();
            const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

            for (let i = 0; i < firstDay; i++) grid.innerHTML += '<div></div>';

            for (let d = 1; d <= daysInMonth; d++) {
                const fullDate = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const curDateObj = new Date(currentYear, currentMonth, d);
                const dayOfWeek = curDateObj.getDay(); // 0 is Sunday, 6 is Saturday

                const dbEvents = events.filter(e => e.event_date === fullDate);
                const holiday = holidaysData.find(h => h.date === fullDate);

                let classes = 'cal-date';
                if (new Date().toISOString().split('T')[0] === fullDate) classes += ' today';

                let isBusy = false;
                let isAvail = false;

                if (dayOfWeek === 0) { // Sunday
                    classes += ' sunday-closed';
                } else if (holiday) {
                    classes += ' busy has-event';
                    isBusy = true;
                } else if (dbEvents.length > 0) {
                    classes += ' has-event';
                    isBusy = dbEvents.some(e => e.title.toLowerCase().includes('quota') || e.title.toLowerCase().includes('not available') || e.title.toLowerCase().includes('closed'));
                    isAvail = dbEvents.some(e => e.title.toLowerCase().includes('available'));
                } else if (dayOfWeek === 6) { // Saturday
                    // No event classes for Saturday, no dot.
                }

                if (isBusy) classes += ' busy';
                else if (isAvail) classes += ' available';
                const dd = defenseDays[fullDate];
                if (dd && dd.rooms && !isBusy) classes += ' has-event defense-day';
                if (dd && dd.mine) classes += ' my-defense';

                grid.innerHTML += `<div class="${classes}" onclick="showEvent('${fullDate}')">${d}</div>`;
            }
        }

        function changeMonth(dir) {
            const grid = document.getElementById('calendarGrid');
            if (grid) {
                grid.style.transform = dir > 0 ? 'translateX(-20px)' : 'translateX(20px)';
                grid.style.opacity = '0';
            }

            setTimeout(() => {
                let prevYear = currentYear;
                currentMonth += dir;
                if (currentMonth > 11) {
                    currentMonth = 0;
                    currentYear++;
                }
                if (currentMonth < 0) {
                    currentMonth = 11;
                    currentYear--;
                }

                if (prevYear !== currentYear) {
                    fetchHolidays(currentYear);
                } else {
                    renderCalendar();
                }

                if (grid) {
                    grid.style.transform = dir > 0 ? 'translateX(20px)' : 'translateX(-20px)';
                    void grid.offsetWidth;
                    grid.style.transform = 'translateX(0)';
                    grid.style.opacity = '1';
                }
            }, 200);
        }

        let mascotAutoOutTimer = null;

        function showEvent(dateStr) {
            const d = new Date(dateStr);
            const dayOfWeek = d.getDay();
            const dbEvents = events.filter(e => e.event_date === dateStr);
            const holiday = holidaysData.find(h => h.date === dateStr);

            const greeter = document.getElementById('mascotGreeter');
            const mascotSub = document.querySelector('.mascot-sub');
            if (!greeter || !mascotSub) return;

            let message = '';
            let pose = 'happy';                       // the eagle's face for this message

            if (dayOfWeek === 0) {
                message = "The research office is closed on Sundays! 🛋️"; pose = 'sleeping';
            } else if (holiday) {
                message = `It's ${holiday.name}! The office is closed for the holiday. 🎉`; pose = 'sleeping';
            }

            if (dbEvents.length > 0) {
                let texts = [];
                dbEvents.forEach(e => {
                    let st = e.title.toLowerCase();
                    if (st.includes('available')) {
                        texts.push(`Available: ${e.title}`);
                    } else if (st.includes('quota') || st.includes('not available') || st.includes('closed')) {
                        texts.push(`Closed: ${e.title}`); pose = 'sleeping';
                    } else {
                        texts.push(`Event: ${e.title}`);
                    }
                });
                message = texts.join(' • ');
            }

            if (dayOfWeek === 6 && dbEvents.length === 0 && !holiday) {
                message = "It's Saturday! Staff is only available until 1:00 PM. ⏰"; pose = 'wink';
            }

            // Published defenses that day (sections of the group's institution), the group's own first.
            const dd = defenseDays[dateStr];
            if (dd && (dd.mine || dd.rooms)) {
                const parts = [];
                if (dd.mine) { parts.push('Your defense is on this day! 🎓'); pose = 'celebrating'; }
                if (dd.rooms) parts.push('Defending: ' + dd.rooms.join(', '));
                message = parts.join(' • ') + (message && !dd.mine && dbEvents.length ? ' • ' + message : '');
                if (!dd.mine) pose = 'happy';
            }

            if (message === '') {
                message = `Research office is fully available today! ✨`;
            }

            mascotSub.innerText = message;
            const eagle = document.getElementById('mascotSVG');
            if (eagle && eagle.tagName === 'IMG') eagle.src = '../assets/mascot/eagle/' + pose + '.svg';

            // Pop the mascot in
            greeter.classList.remove('mascot-out');
            greeter.classList.add('mascot-in');

            // Clear any existing timer
            if (mascotAutoOutTimer) clearTimeout(mascotAutoOutTimer);

            // Auto hide after 3.5 seconds
            mascotAutoOutTimer = setTimeout(() => {
                greeter.classList.replace('mascot-in', 'mascot-out');
            }, 3500);
        }

        // Hide mascot when clicking outside
        document.addEventListener('click', function(e) {
            const greeter = document.getElementById('mascotGreeter');
            const calendarGrid = document.getElementById('calendarGrid');
            if (greeter && greeter.classList.contains('mascot-in')) {
                // If the click is not inside the greeter and not inside the calendar grid
                if (!greeter.contains(e.target) && (!calendarGrid || !calendarGrid.contains(e.target))) {
                    greeter.classList.replace('mascot-in', 'mascot-out');
                }
            }
        });

        renderCalendar();

        /* ── Desktop fun facts — typewriter rotation (Duolingo-style tips) ── */
        (function initResearchFunFacts() {
            if (!window.matchMedia('(min-width: 1025px)').matches) return;

            const typedEl = document.getElementById('funFactTyped');
            const progressEl = document.getElementById('funFactsProgress');
            if (!typedEl || !progressEl) return;

            const facts = [
                "Finish your Proposal Defense first — it opens the door to every stage after!",
                "Upload early and your coordinator gets extra time to help you shine.",
                "Your group chat is the best spot to sync with your research teammates.",
                "Sundays are rest days — the research office is closed, so plan ahead!",
                "Each approved file pushes you one step closer to clearing day. Keep going!",
                "Revision requests aren't setbacks — they're your roadmap to a stronger paper.",
                "Statistics review and plagiarism checks unlock after Final Defense. Almost there!",
                "Tap the three dots on any card to see exactly what you need to submit.",
                "Groups that chat often finish faster — don't ghost your teammates!",
                "Your rank goes up as you complete milestones. Legend status awaits!",
                "Check the calendar for office availability before you visit in person.",
                "Approved uploads turn green — that's your signal to celebrate and move on!"
            ];

            facts.forEach((_, i) => {
                const dot = document.createElement('span');
                dot.className = 'fun-facts-dot' + (i === 0 ? ' active' : '');
                dot.setAttribute('aria-hidden', 'true');
                progressEl.appendChild(dot);
            });

            let factIdx = 0;
            let charIdx = 0;
            let deleting = false;
            let pauseTimer = null;

            function setActiveDot() {
                progressEl.querySelectorAll('.fun-facts-dot').forEach((d, i) => {
                    d.classList.toggle('active', i === factIdx);
                });
            }

            function tick() {
                const fact = facts[factIdx];
                if (!deleting) {
                    typedEl.textContent = fact.slice(0, charIdx + 1);
                    charIdx++;
                    if (charIdx >= fact.length) {
                        pauseTimer = setTimeout(() => {
                            deleting = true;
                            tick();
                        }, 4200);
                        return;
                    }
                    pauseTimer = setTimeout(tick, 38 + Math.random() * 22);
                } else {
                    typedEl.textContent = fact.slice(0, charIdx - 1);
                    charIdx--;
                    if (charIdx <= 0) {
                        deleting = false;
                        factIdx = (factIdx + 1) % facts.length;
                        setActiveDot();
                        pauseTimer = setTimeout(tick, 500);
                        return;
                    }
                    pauseTimer = setTimeout(tick, 18);
                }
            }

            setTimeout(tick, 900);
        })();

        document.addEventListener("DOMContentLoaded", function() {
            const params = new URLSearchParams(window.location.search);

            // The handlers (upload_handler.php, reset_handler.php, ...) all redirect back
            // here with ?msg=&type=, but nothing was reading them, so every one of those
            // toasts was being dropped. Read it before the ?module= branch below, which
            // wipes the querystring with replaceState().
            if (params.get('msg')) {
                const toastType = params.get('type') || 'success';
                setTimeout(() => showToast(params.get('msg'), toastType), 400);
                window.history.replaceState({}, document.title,
                    window.location.pathname + (params.get('module') ? '?module=' + encodeURIComponent(params.get('module')) : ''));
            }

            const overallComplete = <?= json_encode($overall_complete) ?>;
            if (overallComplete && !sessionStorage.getItem('mcnp_clearing_confetti')) {
                sessionStorage.setItem('mcnp_clearing_confetti', 'done');

                function createConfetti() {
                    const colors = ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#ef4444'];
                    for (let i = 0; i < 90; i++) {
                        const confetti = document.createElement('div');
                        confetti.style.position = 'fixed';
                        confetti.style.width = '8px';
                        confetti.style.height = '8px';
                        confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                        confetti.style.borderRadius = i % 2 === 0 ? '50%' : '0%';
                        confetti.style.left = Math.random() * 100 + 'vw';
                        confetti.style.top = '-10px';
                        confetti.style.transform = `rotate(${Math.random() * 360}deg)`;
                        confetti.style.opacity = Math.random();
                        confetti.style.zIndex = '99999';
                        confetti.style.transition = `transform ${Math.random() * 2.5 + 1.5}s ease-out, top ${Math.random() * 2.5 + 1.5}s ease-in, opacity ${Math.random() * 1 + 0.5}s ease-out`;
                        document.body.appendChild(confetti);

                        setTimeout(() => {
                            confetti.style.top = '105vh';
                            confetti.style.transform = `translateY(0) rotate(${Math.random() * 1200}deg)`;
                            confetti.style.opacity = '0';
                        }, 50);

                        setTimeout(() => {
                            confetti.remove();
                        }, 3000);
                    }
                }
                setTimeout(createConfetti, 300);
                setTimeout(createConfetti, 850);
            }

            if (params.get('module')) {
                const moduleName = params.get('module');
                const moduleMap = {
                    'stats': 'module_statistics.php',
                    'plag': 'module_plagiarism.php',
                    'proposal': 'module_proposal.php',
                    'final': 'module_final.php',
                    'profile': 'profile.php',
                    'members': 'members.php',
                    'settings': 'settings.php',
                    'activities': 'activities_all.php',
                    'chat': 'message.php'
                };

                // 2026-10-01: the same locks as the cards. A printed phase-card QR (admin_qr.php)
                // lands here after sign-in; a stage the group has not reached says why, as a tap
                // on its locked card does, instead of opening. null = open.
                const moduleLocks = <?= json_encode([
                    'proposal' => $unlock_proposal ? null : 'Not open yet',
                    'stats'    => $unlock_stats ? null : $lock_reason_next,
                    'final'    => $unlock_final ? null : $lock_reason_final,
                    'plag'     => $unlock_plag ? null : $lock_reason_plag,
                ]) ?>;
                if (moduleLocks[moduleName]) {
                    lockedCardNote(moduleLocks[moduleName], null, 7000);
                    window.history.replaceState({}, document.title, window.location.pathname);
                } else if (moduleMap[moduleName]) {
                    // Carry ?view= through to the module. The defense handlers redirect with
                    // view=schedule so an upload or a date pick lands back INSIDE the schedule
                    // sheet instead of dropping the group on the requirement list every time.
                    let target = moduleMap[moduleName];
                    const view = params.get('view');
                    if (view) target += (target.indexOf('?') > -1 ? '&' : '?') + 'view=' + encodeURIComponent(view);
                    pushView('zoom-' + moduleName, target);
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            }
        });
    </script>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MASCOT — Slides in from right edge on first login       -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="mascotGreeter" aria-hidden="true">
        <!-- Speech bubble -->
        <div id="mascotBubble">
            <span id="mascotMsg">Hi, <?= htmlspecialchars(explode(' ', $account_holder)[0]) ?>! 👋</span>
            <span class="mascot-sub">Ready to crush your research?</span>
        </div>
        <!-- The iSubmit eagle (assets/mascot/eagle). Waving with a wink for the greeting; showEvent()
             changes the pose to match the calendar message (closed: sleeping, open: happy). -->
        <div id="mascotBody">
            <img src="../assets/mascot/eagle/wink.svg" alt="" aria-hidden="true" draggable="false" id="mascotSVG" data-pose="wink">
        </div>
    </div>

    <style>
        /* ══ MASCOT GREETER — original bubble, repositioned per breakpoint ══ */
        #mascotGreeter {
            position: fixed;
            bottom: 100px;
            right: 0;
            z-index: 999999;
            display: flex;
            flex-direction: row-reverse;
            align-items: flex-end;
            gap: 12px;
            transform: translateX(calc(100% + 20px));
            transition: transform 0.7s cubic-bezier(0.175, 0.885, 0.32, 1.3);
            cursor: pointer;
            user-select: none;
            pointer-events: none;
        }

        #mascotGreeter.mascot-in {
            transform: translateX(-24px);
            pointer-events: auto;
        }

        #mascotGreeter.mascot-out {
            transform: translateX(calc(100% + 40px));
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        /* Mobile — centered above dock */
        @media (max-width: 1024px) {
            #mascotGreeter {
                bottom: calc(96px + env(safe-area-inset-bottom, 0px));
                left: 50%;
                right: auto;
                flex-direction: column-reverse;
                align-items: center;
                text-align: center;
                transform: translateX(-50%) translateY(40px);
                opacity: 0;
                transition: transform 0.65s cubic-bezier(0.175, 0.885, 0.32, 1.25), opacity 0.5s var(--smooth);
            }

            #mascotGreeter.mascot-in {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }

            #mascotGreeter.mascot-out {
                transform: translateX(-50%) translateY(24px);
                opacity: 0;
            }
        }

        @media (max-width: 640px) {
            #mascotGreeter {
                bottom: calc(88px + env(safe-area-inset-bottom, 0px));
            }

            #mascotBody {
                width: 86px;
            }
        }

        #mascotBody {
            width: 108px;
            flex-shrink: 0;
            filter: drop-shadow(0 12px 24px rgba(109, 40, 217, 0.35));
            animation: mascotBob 2.2s ease-in-out infinite;
        }

        #mascotBody svg, #mascotBody img {
            width: 100%;
            height: auto;
            display: block;
            user-select: none;
            -webkit-user-drag: none;
        }

        /* Bob up/down */
        @keyframes mascotBob {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-8px);
            }
        }

        /* Wing wave */
        #mascotWing {
            animation: wingWave 0.6s ease-in-out infinite alternate;
        }

        @keyframes wingWave {
            from {
                transform: rotate(-12deg);
            }

            to {
                transform: rotate(18deg);
            }
        }

        /* Eye blink every ~3s */
        #blinkL,
        #blinkR {
            animation: eyeBlink 3s ease-in-out infinite;
        }

        @keyframes eyeBlink {

            0%,
            90%,
            100% {
                opacity: 0;
            }

            93%,
            97% {
                opacity: 1;
            }
        }

        /* Speech bubble — original glass design */
        #mascotBubble {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1.5px solid rgba(167, 139, 250, 0.4);
            border-radius: 18px 18px 4px 18px;
            padding: 12px 16px;
            box-shadow: 0 8px 24px -4px rgba(109, 40, 217, 0.18);
            display: flex;
            flex-direction: column;
            gap: 3px;
            max-width: 180px;
            animation: bubblePop 0.4s 0.5s var(--spring, cubic-bezier(0.175, 0.885, 0.32, 1.275)) both;
        }

        @media (max-width: 1024px) {
            #mascotBubble {
                border-radius: 18px 18px 18px 4px;
                max-width: 220px;
            }
        }

        @keyframes bubblePop {
            from {
                transform: scale(0.7);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        #mascotMsg {
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 800;
            color: #1e1b4b;
            white-space: nowrap;
        }

        .mascot-sub {
            font-family: 'Inter', sans-serif;
            font-size: 11px;
            font-weight: 500;
            color: #6d28d9;
            line-height: 1.3;
        }

        /* Dark-mode adjustment */
        body.theme-dark #mascotBubble {
            background: rgba(30, 27, 64, 0.92);
            border-color: rgba(139, 92, 246, 0.4);
        }

        body.theme-dark #mascotMsg {
            color: #ede9fe;
        }


        /* BOTTOM SHEET FOR PROPOSAL DEFENSE (MOBILE) */
        @media (max-width: 768px) {
            #zoom-proposal, #zoom-stats, #zoom-plag, #zoom-final {
                top: auto !important;
                bottom: 0 !important;
                height: 85vh !important;
                transform: translateY(100%);
                border-radius: 24px 24px 0 0 !important;
                overflow: hidden !important;
                box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15) !important;
                transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
                padding-top: 15px !important;
            }

            #zoom-proposal.active, #zoom-stats.active, #zoom-plag.active, #zoom-final.active {
                transform: translateY(0) !important;
            }
        }
    </style>

    <script>
        // Show mascot once per browser session
        (function() {
            if (sessionStorage.getItem('mascotShown')) return;
            sessionStorage.setItem('mascotShown', '1');

            const greeter = document.getElementById('mascotGreeter');
            if (!greeter) return;

            // Slide in after 800ms (page settled)
            setTimeout(() => greeter.classList.add('mascot-in'), 800);

            // Auto slide out after 5.5s
            let autoOut = setTimeout(() => greeter.classList.replace('mascot-in', 'mascot-out'), 5500);

            // Click to dismiss early
            greeter.addEventListener('click', () => {
                clearTimeout(autoOut);
                greeter.classList.replace('mascot-in', 'mascot-out');
            });
        })();

        // Handle native back button for slide-in modules
        window.addEventListener('popstate', function(event) {
            const hash = window.location.hash.replace('#', '');
            if (!hash || !document.getElementById('zoom-' + hash)) {
                collapseZoomModules();
            } else {
                const activeOverlays = document.querySelectorAll('.fullscreen-zoom-overlay.active');
                if (activeOverlays.length > 0) {
                    let shouldCollapse = true;
                    activeOverlays.forEach(overlay => {
                        if (overlay.id === 'zoom-' + hash) {
                            shouldCollapse = false;
                        }
                    });
                    if (shouldCollapse) {
                        collapseZoomModules();
                    }
                }
            }
        });
    </script>

    <script>
        // Bottom Sheet Drag Logic for Proposal Defense + Statistical Treatment
        document.addEventListener('DOMContentLoaded', () => {
          ['zoom-proposal', 'zoom-stats', 'zoom-plag', 'zoom-final'].forEach((sheetId) => {
            const proposalSheet = document.getElementById(sheetId);
            if (!proposalSheet) return;

            let startY = 0;
            let currentY = 0;
            let isDragging = false;
            const threshold = 150; // pixels to drag down before closing

            // Create drag handle
            const handle = document.createElement('div');
            handle.className = 'sheet-drag-handle';
            handle.style.cssText = 'width: 40px; height: 4px; background: #e2e8f0; border-radius: 2px; margin: 0 auto 15px auto; flex-shrink: 0;';
            proposalSheet.insertBefore(handle, proposalSheet.firstChild);

            proposalSheet.addEventListener('touchstart', (e) => {
                if (window.innerWidth > 768) return;
                // Only allow drag from the top 50px (header area) to avoid interfering with scrolling
                const touchY = e.touches[0].clientY;
                const rect = proposalSheet.getBoundingClientRect();
                if (touchY - rect.top > 60) return;

                startY = touchY;
                isDragging = true;
                proposalSheet.style.transition = 'none'; // Disable transition for direct manipulation
            }, {
                passive: true
            });

            proposalSheet.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                currentY = e.touches[0].clientY;
                const deltaY = currentY - startY;

                // Only allow dragging downwards
                if (deltaY > 0) {
                    proposalSheet.style.transform = `translateY(${deltaY}px)`;
                }
            }, {
                passive: true
            });

            proposalSheet.addEventListener('touchend', (e) => {
                if (!isDragging) return;
                isDragging = false;

                proposalSheet.style.transition = 'transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';

                const deltaY = currentY - startY;
                if (deltaY > threshold) {
                    // Trigger close
                    collapseZoomModules();
                    proposalSheet.style.transform = ''; // reset for next open
                } else {
                    // Snap back
                    proposalSheet.style.transform = 'translateY(0)';
                }
            });
          });
        });
    </script>
</body>

</html>