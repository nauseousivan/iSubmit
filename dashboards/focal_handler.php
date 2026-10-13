<?php
// dashboards/focal_handler.php — all writes for sections and the schedule chain.
//
// Focal Person : create_section, delete_section, assign_section, endorse_batch, cancel_batch
// Coordinator  : confirm_batch, return_batch
// Secretary    : finalize_batch, return_batch        (Director acts as Secretary — see CLAUDE.md)
//
// POST-only, CSRF-checked, sets $_SESSION['message'] / ['message_type'] and redirects back,
// following defense_handler.php.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'focal_lib.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../auth/login.php");
    exit();
}

$me      = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? ($role === 'Focal Person' ? 'focal.php' : 'director.php');

function fp_finish(string $msg, string $type): void
{
    global $referer;
    $_SESSION['message']      = $msg;
    $_SESSION['message_type'] = $type;
    header("Location: " . $referer);
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    fp_finish("Invalid security token. Please refresh and try again.", "error");
}

$action = $_POST['action'] ?? '';
$phase  = ($_POST['phase'] ?? 'proposal') === 'final' ? 'final' : 'proposal';

/** Is this section inside a course the current user may act on? Directors/Coordinators: any. */
function fp_owns_section(PDO $pdo, string $role, int $userId, int $sectionId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE section_id = ?");
    $stmt->execute([$sectionId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;

    if (!in_array($role, AA_SCOPED_ROLES, true)) return $s;

    foreach (aa_focal_scope($pdo, $userId) as $c) {
        if ($c['department'] === $s['department'] && $c['program'] === $s['program']) return $s;
    }
    return null;
}

// ============================================================ Focal Person: sections
if ($action === 'create_section') {
    if ($role !== 'Focal Person') fp_finish("Only a Focal Person manages her own sections.", "error");

    $raw     = trim($_POST['course'] ?? '');
    $label   = trim($_POST['label'] ?? '');
    $year    = trim($_POST['year_level'] ?? '');
    $allowed = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];

    if ($label === '') fp_finish("Give the section a name, for example BSIT 4-A.", "error");
    if ($year !== '' && !in_array($year, $allowed, true)) fp_finish("That year level is not valid.", "error");

    // The course must be one of hers — never trust the posted value.
    $match = null;
    foreach (aa_focal_scope($pdo, $me) as $c) {
        if ($c['department'] . '::' . $c['program'] === $raw) $match = $c;
    }
    if (!$match) fp_finish("That course is not one of yours.", "error");

    // A label repeats across year levels — offices call the rooms "A" and "B" and let the year
    // tell them apart — so the year is part of what makes a section unique, matching the
    // uniq_section_label key. Only the same year AND the same label is a real duplicate.
    $dupe = $pdo->prepare("SELECT section_id FROM sections WHERE department = ? AND program = ? AND year_level = ? AND label = ?");
    $dupe->execute([$match['department'], $match['program'], $year, $label]);
    if ($dupe->fetch()) {
        fp_finish(trim(($year ?: '') . ' ' . $label) . " already exists in " . htmlspecialchars($match['program']) . ".", "error");
    }

    $pdo->prepare("INSERT INTO sections (department, program, year_level, label, created_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$match['department'], $match['program'], $year, $label, $me]);

    fp_finish("Section " . htmlspecialchars($label) . " created.", "success");
}

if ($action === 'delete_section') {
    $sid = (int)($_POST['section_id'] ?? 0);
    $s   = fp_owns_section($pdo, $role, $me, $sid);
    if (!$s) fp_finish("That section is not yours.", "error");

    // Refuse while anything still points at it. Deleting would not destroy the groups (the FK
    // is ON DELETE SET NULL, so they would drop into the unassigned tray) but silently emptying
    // a room out from under a live batch is not something to do without saying so.
    $n = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.section_id = ? AND " . FP_GROUP_PREDICATE);
    $n->execute([$sid]);
    if ((int)$n->fetchColumn() > 0) fp_finish("Move its groups out before deleting this section.", "error");

    $pdo->prepare("DELETE FROM sections WHERE section_id = ?")->execute([$sid]);
    fp_finish("Section " . htmlspecialchars($s['label']) . " deleted.", "success");
}

if ($action === 'assign_section') {
    $leader = (int)($_POST['user_id'] ?? 0);
    $sid    = (int)($_POST['section_id'] ?? 0);
    $s      = fp_owns_section($pdo, $role, $me, $sid);
    if (!$s) fp_finish("That section is not yours.", "error");

    // The group must sit in one of her courses, and must actually be a group leader.
    [$scopeSql, $params] = aa_scope_sql($pdo, $role, $me, 'u');
    $chk = $pdo->prepare("SELECT u.user_id, u.research_group_name FROM users u
                          WHERE u.user_id = ? AND " . FP_GROUP_PREDICATE . " AND $scopeSql");
    $chk->execute(array_merge([$leader], $params));
    $g = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$g) fp_finish("That group is not in your courses.", "error");

    // Section and members move together: the whole group sits in one room, so the leader's
    // section is pushed to the members exactly the way program/department already are.
    $pdo->prepare("UPDATE users SET section_id = ? WHERE user_id = ? OR leader_id = ?")
        ->execute([$sid, $leader, $leader]);

    // A section carries its year level; adopting it fills the blanks left by older signups.
    if (!empty($s['year_level'])) {
        $pdo->prepare("UPDATE users SET year_level = ? WHERE (user_id = ? OR leader_id = ?) AND (year_level IS NULL OR year_level = '')")
            ->execute([$s['year_level'], $leader, $leader]);
    }

    fp_finish(htmlspecialchars($g['research_group_name']) . " moved into " . htmlspecialchars($s['label']) . ".", "success");
}

// ============================================================ Focal Person: review a document
//
// Her own review, on her own screen. Approving writes 'Approved' straight away and the student
// goes green immediately — requirement review is single-stage and always has been. Only the
// DEFENSE DATE travels the focal -> coordinator -> secretary chain.
//
// The write deliberately mirrors applyReviewDecision() in admin_module_dynamic.php: same
// verification_status strings, the same item-14 cascade, and the same activity_logs row, so a
// requirement cleared here is indistinguishable from one the office cleared.
/** Load one upload with its requirement name, or null. */
function fp_load_doc(PDO $pdo, int $uploadId): ?array
{
    $stmt = $pdo->prepare("SELECT u.upload_id, u.user_id, u.item_id,
                                  COALESCE(NULLIF(c.item_name,''), c.requirement_name) AS label
                           FROM uploads u
                           LEFT JOIN checklist_items c ON c.item_id = u.item_id
                           WHERE u.upload_id = ?");
    $stmt->execute([$uploadId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * May this focal person decide this document? Returns an error string, or null when fine.
 * Hiding a group from her lists is not the same as protecting it from a posted upload_id.
 */
function fp_review_block(PDO $pdo, string $role, int $me, array $doc): ?string
{
    if (!aa_may_touch_student($pdo, $role, $me, (int)$doc['user_id'])) {
        return "That group is not in your courses.";
    }
    // Item 4 (plagiarism manuscript) has its own dedicated actions — the same guard the generic
    // review modal carries in admin_module_dynamic.php.
    if ((int)$doc['item_id'] === 4) {
        return "Plagiarism manuscripts are reviewed elsewhere.";
    }
    return null;
}

/**
 * The single write path for a review decision, used by both the one-document action and the
 * batch one. Mirrors applyReviewDecision() in admin_module_dynamic.php: same status strings,
 * the same item-14 cascade, the same activity_logs verdict row.
 *
 * Caller owns the transaction so a batch commits or fails as one.
 */
function fp_write_review(PDO $pdo, array $doc, string $decision, string $remarks, int $actorId): void
{
    // Stamp the author. "Approved" alone stopped being enough once three roles could write it.
    $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ?, verified_by = ?, verified_at = NOW()
                   WHERE upload_id = ?")
        ->execute([$decision, $remarks, $actorId, (int)$doc['upload_id']]);

    // Item 14 (Capsule Proposal / Form 008) clears 13, 15 and 16 with it.
    if ((int)$doc['item_id'] === 14 && $decision === 'Approved') {
        foreach ([13, 15, 16] as $cascade) {
            $find = $pdo->prepare("SELECT upload_id FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
            $find->execute([(int)$doc['user_id'], $cascade]);
            if ($existing = $find->fetchColumn()) {
                $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Automatically verified via Capsule Proposal Approval Matrix (Form No. 008).', verified_by = ?, verified_at = NOW() WHERE upload_id = ?")
                    ->execute([$actorId, $existing]);
            } else {
                $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status, verified_by, verified_at, remarks, uploaded_at) VALUES (?, ?, '../uploads/cascaded_clearance.pdf', 'Cascaded_Institutional_Verification.pdf', 'Approved', ?, NOW(), 'Bundled milestone validation completed natively inside Capsule Proposal Form No. 008 approval.', CURRENT_TIMESTAMP)")
                    ->execute([(int)$doc['user_id'], $cascade, $actorId]);
            }
        }
    }

    // status_type success/warning = a verdict the student is alerted about (see the
    // activity-log semantics note); 'info' is reserved for their own actions.
    $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type, created_at)
                   VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
        ->execute([
            (int)$doc['user_id'], (int)$doc['upload_id'],
            "Document Review - " . $decision,
            "Your " . $doc['label'] . " has been reviewed. Status: " . $decision
                . ($remarks !== '' ? ". Remarks: " . $remarks : "."),
            $decision === 'Approved' ? 'success' : 'warning',
        ]);
}

if ($action === 'review_doc') {
    if ($role !== 'Focal Person') fp_finish("Access Denied", "error");

    $uploadId = (int)($_POST['upload_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $remarks  = trim($_POST['remarks'] ?? '');

    if (!in_array($decision, ['Approved', 'Revision Requested'], true)) {
        fp_finish("Pick approve or request a revision.", "error");
    }
    $doc = fp_load_doc($pdo, $uploadId);
    if (!$doc) fp_finish("That document no longer exists.", "error");
    if ($why = fp_review_block($pdo, $role, $me, $doc)) fp_finish($why, "error");
    if ($decision === 'Revision Requested' && $remarks === '') {
        fp_finish("Say what needs fixing so the group knows what to change.", "error");
    }

    $pdo->beginTransaction();
    try {
        fp_write_review($pdo, $doc, $decision, $remarks, $me);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fp_finish("Could not save that review.", "error");
    }

    fp_finish($doc['label'] . " — " . ($decision === 'Approved' ? "approved." : "sent back for revision."), "success");
}

// ---- approve several at once -------------------------------------------------------------
// The office screen can clear a stack of documents in one pass; she reviews far more groups
// than it does, so she needs the same. Approve only: sending back needs its own reason per
// document, and one shared remark across a batch would be worse than useless to the student.
if ($action === 'review_bulk') {
    if ($role !== 'Focal Person') fp_finish("Access Denied", "error");

    $ids = array_values(array_unique(array_map('intval', (array)($_POST['upload_ids'] ?? []))));
    if (empty($ids)) fp_finish("Tick the requirements you want to approve.", "error");

    $docs = [];
    foreach ($ids as $id) {
        $doc = fp_load_doc($pdo, $id);
        if (!$doc) fp_finish("One of those documents no longer exists.", "error");
        if ($why = fp_review_block($pdo, $role, $me, $doc)) fp_finish($why, "error");
        $docs[] = $doc;
    }

    // All or nothing: a half-applied batch would leave her guessing which ones landed.
    $pdo->beginTransaction();
    try {
        foreach ($docs as $doc) {
            fp_write_review($pdo, $doc, 'Approved', '', $me);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fp_finish("Could not save those reviews. Nothing was changed.", "error");
    }

    fp_finish(count($docs) . " requirement" . (count($docs) === 1 ? "" : "s") . " approved.", "success");
}

// ---- remind groups to upload (2026-10-01) ------------------------------------------------
// The Requirements screen's "Remind all N" (groups that have sent nothing) and a requirement's
// "Remind" (one missing file). The same reminder the office's Checklist sends (remind_upload in
// admin_module_dynamic.php): EMAIL ONLY, it changes no status, and once per target per 6 hours
// per person, so a double tap is not two emails. Scoped per group like every write here.
if ($action === 'remind_groups') {
    if ($role !== 'Focal Person') fp_finish("Access Denied", "error");
    require_once '../config/mail.php';

    $ids    = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));
    $itemId = (int)($_POST['item_id'] ?? 0);
    if (!$ids) fp_finish("Nobody to remind.", "error");

    $item = '';
    if ($itemId) {
        if (!in_array($itemId, fp_review_items($phase), true)) fp_finish("That is not one of this phase's requirements.", "error");
        $item = (string)(fp_item_names($pdo, [$itemId])[$itemId] ?? 'a requirement');
    }
    $html = $item !== ''
        ? rd_mail_html('A requirement is still missing', [
            'Your ' . htmlspecialchars($item) . ' has not been uploaded yet.',
            'Upload it in iSubmit so your focal person can check it.'])
        : rd_mail_html('Your requirements are waiting', [
            'Your group has not sent any of its ' . df_phase_label($phase) . ' requirements yet.',
            'Upload them in iSubmit so your focal person can check them and put you on a defense date.']);
    $subject = $item !== '' ? 'Reminder: please upload ' . $item : 'Reminder: please upload your requirements';

    $sent = 0; $wait = 0;
    foreach ($ids as $gid) {
        $g = $pdo->prepare("SELECT u.user_id FROM users u WHERE u.user_id = ? AND " . FP_GROUP_PREDICATE);
        $g->execute([$gid]);
        if (!$g->fetch() || !aa_may_touch_student($pdo, $role, $me, $gid)) fp_finish("One of those groups is not in your courses.", "error");
        $key = $phase . ':' . $gid . ':' . $itemId;
        if (time() - ($_SESSION['fp_reminded'][$key] ?? 0) < 6 * 3600) { $wait++; continue; }
        if (sendGroupEmail($pdo, $gid, $subject, $html)) { $_SESSION['fp_reminded'][$key] = time(); $sent++; }
    }
    if (!$sent && $wait) fp_finish("Already reminded — give them a few hours.", "error");
    if (!$sent) fp_finish("The reminder could not be sent. Please try again.", "error");
    fp_finish("Reminder sent to " . $sent . " group" . ($sent === 1 ? "" : "s") . ($wait ? " ($wait reminded recently, skipped)" : "") . ".", "success");
}

// ============================================================ Focal Person: endorse a date
if ($action === 'endorse_batch') {
    if ($role !== 'Focal Person') fp_finish("Only a Focal Person endorses a section's schedule.", "error");

    $sid    = (int)($_POST['section_id'] ?? 0);
    $date   = trim($_POST['defense_date'] ?? '');
    $picked = array_map('intval', (array)($_POST['groups'] ?? []));
    $s      = fp_owns_section($pdo, $role, $me, $sid);

    if (!$s) fp_finish("That section is not yours.", "error");
    if (empty($picked)) fp_finish("Tick at least one group to schedule.", "error");

    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) fp_finish("That date is not valid.", "error");

    // A PUBLISHED batch does not block: a section is scheduled in rounds, and the groups that
    // were not ready the first time still need a day. Only a request already in the chain does.
    if (fp_pending_batch($pdo, $sid, $phase)) {
        fp_finish("This section already has a date waiting for approval. Withdraw it first.", "error");
    }

    // Same four availability rules the student calendar renders from — window, not Sunday,
    // not blacked out, not full. Enforced here as well as drawn there, never split.
    // Availability is per SCHOOL: each has its own pool of DEFENSE_SLOTS_PER_SCHOOL_PER_DAY,
    // so a day MCNP has filled is still open to ISAP. The section decides which pool applies.
    $school    = (string)$s['department'];
    $blackouts = df_blackouts($pdo, $phase, df_window_start(), df_window_end());
    $taken     = df_taken_dates($pdo, $phase, df_window_start(), df_window_end(), $school);
    if ($why = df_unavailable_reason($date, $blackouts, $taken, 0)) {
        fp_finish($why, "error");
    }

    // Only groups of this section that have actually cleared the gate may be placed, and only
    // if they are not already holding a booking for this phase.
    $inSection = [];
    foreach (fp_groups_in_section($pdo, $sid) as $g) $inSection[(int)$g['user_id']] = $g;
    $ready = fp_readiness($pdo, array_keys($inSection), $phase);

    $toPlace = [];
    foreach ($picked as $uid) {
        if (!isset($inSection[$uid])) fp_finish("One of those groups is not in this section.", "error");
        if (empty($ready[$uid]['ready'])) {
            fp_finish(htmlspecialchars($inSection[$uid]['research_group_name']) . " has not cleared its requirements yet.", "error");
        }
        $has = $pdo->prepare("SELECT booking_id FROM defense_bookings
                              WHERE user_id = ? AND phase = ? AND status IN ('Applied','Date Selected','Scheduled')");
        $has->execute([$uid, $phase]);
        if ($has->fetch()) {
            fp_finish(htmlspecialchars($inSection[$uid]['research_group_name']) . " already holds a booking for this phase.", "error");
        }
        $toPlace[] = $uid;
    }

    // ---- capacity, under a lock ----------------------------------------------------------
    // Two focal persons can endorse onto the same day at the same moment. Counting inside the
    // transaction with FOR UPDATE means the second one reads the first one's rows and is turned
    // away, instead of both seeing "5 free" and writing 2 x 8 into a 10-slot day.
    $pdo->beginTransaction();
    try {
        // Lock the whole DATE, not just this school's rows: the contended resource is the day,
        // and a range lock over (defense_date, status) also stops a concurrent INSERT landing in
        // the gap. Deliberately no join — locking users rows as well would widen this for no gain.
        $pdo->prepare("SELECT booking_id FROM defense_bookings
                       WHERE defense_date = ? AND status IN ('Date Selected','Scheduled') FOR UPDATE")
            ->execute([$date]);

        // Then count only this school's, safely, behind that lock.
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings b
                              JOIN users u ON u.user_id = b.user_id
                              WHERE b.defense_date = ? AND u.department = ?
                                AND b.status IN ('Date Selected','Scheduled')");
        $cnt->execute([$date, $school]);
        $used = (int)$cnt->fetchColumn();
        $free = DEFENSE_SLOTS_PER_SCHOOL_PER_DAY - $used;

        if (count($toPlace) > $free) {
            $pdo->rollBack();
            fp_finish("Only $free slot" . ($free === 1 ? "" : "s") . " left on " . htmlspecialchars($date)
                . ". Pick another date or reduce the batch.", "error");
        }

        $pdo->prepare("INSERT INTO defense_batches (section_id, phase, defense_date, status, focal_by, focal_at)
                       VALUES (?, ?, ?, 'Endorsed', ?, NOW())")
            ->execute([$sid, $phase, $date, $me]);
        $batchId = (int)$pdo->lastInsertId();

        // UPSERT, not INSERT. `uniq_group_phase` allows exactly ONE defense_bookings row per
        // (group, phase) for all time, so a group that was endorsed and then cancelled still
        // owns its row — a plain insert would collide and the whole batch would fail. Live
        // rows were already refused above, so anything found here is spent and safe to reuse.
        $slot = $used + 1;
        $ins  = $pdo->prepare("INSERT INTO defense_bookings (user_id, phase, batch_id, defense_date, slot_no, status)
                               VALUES (?, ?, ?, ?, ?, 'Date Selected')");
        $upd  = $pdo->prepare("UPDATE defense_bookings
                               SET batch_id = ?, defense_date = ?, slot_no = ?, status = 'Date Selected',
                                   decided_by = NULL, decided_at = NULL, remarks = NULL
                               WHERE user_id = ? AND phase = ?");
        $find = $pdo->prepare("SELECT booking_id FROM defense_bookings WHERE user_id = ? AND phase = ?");
        foreach ($toPlace as $uid) {
            $find->execute([$uid, $phase]);
            if ($find->fetchColumn()) {
                $upd->execute([$batchId, $date, $slot, $uid, $phase]);
            } else {
                $ins->execute([$uid, $phase, $batchId, $date, $slot]);
            }
            $slot++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fp_finish("Could not endorse that date. Please try again.", "error");
    }

    foreach ($toPlace as $uid) {
        df_log($pdo, $uid, 'Defense date proposed',
            'Your ' . df_phase_label($phase) . ' was proposed for ' . $date . ' and is awaiting final confirmation.', 'info');
    }

    fp_finish(count($toPlace) . " group" . (count($toPlace) === 1 ? "" : "s") . " endorsed for " . htmlspecialchars($date)
        . ". Waiting for the Coordinator.", "success");
}

if ($action === 'cancel_batch') {
    $bid  = (int)($_POST['batch_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM defense_batches WHERE batch_id = ?");
    $stmt->execute([$bid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) fp_finish("That batch no longer exists.", "error");
    if (!fp_owns_section($pdo, $role, $me, (int)$b['section_id'])) fp_finish("That section is not yours.", "error");
    if ($b['status'] === 'Scheduled') fp_finish("That date is already confirmed. Ask the office to cancel it.", "error");

    $groups = fp_batch_groups($pdo, $bid);
    $pdo->beginTransaction();
    try {
        // Frees the slots. Uploaded files are never touched — same rule as defense_handler.php.
        $pdo->prepare("UPDATE defense_bookings SET status = 'Cancelled', batch_id = NULL WHERE batch_id = ?")->execute([$bid]);
        $pdo->prepare("UPDATE defense_batches SET status = 'Cancelled' WHERE batch_id = ?")->execute([$bid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fp_finish("Could not cancel that batch.", "error");
    }

    foreach ($groups as $g) {
        df_log($pdo, (int)$g['user_id'], 'Proposed defense date withdrawn',
            'The proposed date for your ' . df_phase_label($b['phase']) . ' was withdrawn. A new one will follow.', 'warning');
    }
    fp_finish("Batch cancelled and the slots released.", "success");
}

// ============================================================ Coordinator: confirm
if ($action === 'confirm_batch') {
    $bid  = (int)($_POST['batch_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM defense_batches WHERE batch_id = ?");
    $stmt->execute([$bid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) fp_finish("That batch no longer exists.", "error");
    if ($b['status'] !== 'Endorsed') fp_finish("That batch is not waiting for a Coordinator.", "error");
    if (!fp_can_act($role, 'Endorsed')) fp_finish("Access Denied", "error");
    if ($b['defense_date'] < df_today()) fp_finish("That date has already passed. Send it back so a new one can be set.", "error");

    $pdo->prepare("UPDATE defense_batches SET status = 'Confirmed', coord_by = ?, coord_at = NOW() WHERE batch_id = ?")
        ->execute([$me, $bid]);
    fp_finish("Confirmed. Waiting for the Secretary's final approval.", "success");
}

// ============================================================ Secretary: finalise
if ($action === 'finalize_batch') {
    $bid  = (int)($_POST['batch_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM defense_batches WHERE batch_id = ?");
    $stmt->execute([$bid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) fp_finish("That batch no longer exists.", "error");
    if ($b['status'] !== 'Confirmed') fp_finish("That batch is not waiting for the Secretary.", "error");
    if (!fp_can_act($role, 'Confirmed')) fp_finish("Access Denied", "error");
    if ($b['defense_date'] < df_today()) fp_finish("That date has already passed. Send it back so a new one can be set.", "error");

    $groups = fp_batch_groups($pdo, $bid);

    // The Secretary confirms group by group, not blind. `keep` is the set she ticked; anything
    // she unticked is released — its booking is cancelled and its slot handed back, so the group
    // waits for a later round instead of being published onto a day she did not sign for.
    //
    // `keep_mode` exists because an unchecked checkbox posts NOTHING: "she unticked every group"
    // and "this caller sent no selection at all" arrive as the same empty request. Without the
    // marker, unticking everything silently published everything — the exact opposite.
    $onBatch  = array_map(fn($g) => (int)$g['user_id'], $groups);
    $perGroup = !empty($_POST['keep_mode']);

    if ($perGroup) {
        $posted = array_map('intval', (array)($_POST['keep'] ?? []));
        $keep   = array_values(array_intersect($onBatch, $posted));
        if (empty($keep)) {
            fp_finish("Tick at least one group, or send the whole request back instead.", "error");
        }
    } else {
        $keep = $onBatch;
    }
    $drop = array_values(array_diff($onBatch, $keep));

    $pdo->beginTransaction();
    try {
        if ($drop) {
            $ph = implode(',', array_fill(0, count($drop), '?'));
            $pdo->prepare("UPDATE defense_bookings SET status = 'Cancelled', batch_id = NULL, slot_no = NULL
                           WHERE batch_id = ? AND user_id IN ($ph)")
                ->execute(array_merge([$bid], $drop));
        }
        $pdo->prepare("UPDATE defense_bookings SET status = 'Scheduled', decided_by = ?, decided_at = NOW() WHERE batch_id = ?")
            ->execute([$me, $bid]);
        $pdo->prepare("UPDATE defense_batches SET status = 'Scheduled', sec_by = ?, sec_at = NOW() WHERE batch_id = ?")
            ->execute([$me, $bid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fp_finish("Could not finalise that batch.", "error");
    }

    // Tell the released groups plainly — silence here reads as "my date vanished".
    foreach ($groups as $g) {
        if (!in_array((int)$g['user_id'], $drop, true)) continue;
        df_log($pdo, (int)$g['user_id'], 'Not on this defense date',
            'Your group was taken off the ' . $b['defense_date'] . ' schedule by the research office. '
            . 'Your focal person will put you on a later date.', 'warning');
    }
    $groups = array_values(array_filter($groups, fn($g) => in_array((int)$g['user_id'], $keep, true)));

    // Only now does the student see a real date — this is the green light under option B.
    foreach ($groups as $g) {
        df_log($pdo, (int)$g['user_id'], 'Defense date confirmed',
            'Your ' . df_phase_label($b['phase']) . ' is confirmed for ' . $b['defense_date']
            . ' (slot ' . (int)$g['slot_no'] . ').', 'success');
    }
    fp_finish("Scheduled. " . count($groups) . " group" . (count($groups) === 1 ? "" : "s") . " notified.", "success");
}

// ============================================================ send it back
// ============================================================ Office: nudge the next desk
// "Further up the chain" is visible to everyone in the office, so anyone watching a section sit
// can remind whoever holds it. Email is the only channel staff have. Refused for your OWN step
// (that is a to-do, not a nudge), scoped like every other chain action, and rate-limited per
// section per person so a double tap does not become two emails.
if ($action === 'nudge_batch') {
    if (!in_array($role, ['Research Coordinator', 'Research Director'], true)) fp_finish("Access Denied", "error");
    $bid  = (int)($_POST['batch_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT b.*, s.department, s.program, s.label, s.year_level FROM defense_batches b
                           JOIN sections s ON s.section_id = b.section_id WHERE b.batch_id = ?");
    $stmt->execute([$bid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b || !in_array($b['status'], ['Endorsed', 'Confirmed'], true)) fp_finish("That section is not waiting on anyone.", "error");
    $scope = fp_school_scope($pdo, $role, $me);
    if ($scope !== null && $b['department'] !== $scope) fp_finish("Access Denied", "error");
    if (fp_can_act($role, $b['status'])) fp_finish("That one is on your own desk.", "error");

    $last = $_SESSION['br_nudged'][$bid] ?? 0;
    if (time() - $last < 6 * 3600) fp_finish("Already nudged — give them a few hours.", "error");

    // Whoever holds the step: the Coordinator(s) of that institution, or the Secretary/Director.
    if ($b['status'] === 'Endorsed') {
        $to = $pdo->prepare("SELECT username, email FROM users WHERE role = 'Research Coordinator' AND department = ? AND is_verified = 1");
        $to->execute([$b['department']]);
    } else {
        $to = $pdo->query("SELECT username, email FROM users WHERE role = 'Research Director' AND is_verified = 1");
    }
    $people = array_filter($to->fetchAll(PDO::FETCH_ASSOC), fn($p) => !empty($p['email']));
    if (!$people) fp_finish("Nobody holds that step yet, so there is no one to nudge.", "error");

    require_once '../config/mail.php';
    $what = $b['program'] . ' ' . fp_section_name($b) . ' (' . df_phase_label($b['phase']) . ')';
    $html = rd_mail_html(
        'A defense date is waiting for you',
        [
            html_entity_decode(strip_tags($what)) . ' is on your desk for '
                . date('l, F j', strtotime($b['defense_date'])) . '.',
            'Open Approvals in iSubmit to ' . ($b['status'] === 'Endorsed' ? 'confirm it.' : 'log the slots and publish it.'),
        ]
    );
    $sent = 0;
    foreach ($people as $p) {
        if (sendSystemEmail($p['email'], 'Reminder: a defense date is waiting for you', $html)) $sent++;
    }
    if (!$sent) fp_finish("The reminder could not be sent. Please try again.", "error");
    $_SESSION['br_nudged'][$bid] = time();
    fp_finish("Nudged " . implode(', ', array_column($people, 'username')) . ".", "success");
}

if ($action === 'return_batch') {
    $bid     = (int)($_POST['batch_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $stmt    = $pdo->prepare("SELECT * FROM defense_batches WHERE batch_id = ?");
    $stmt->execute([$bid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) fp_finish("That batch no longer exists.", "error");
    if (!in_array($b['status'], ['Endorsed', 'Confirmed'], true)) fp_finish("That batch cannot be sent back.", "error");
    if (!fp_can_act($role, $b['status'])) fp_finish("Access Denied", "error");
    if ($remarks === '') fp_finish("Say what needs fixing before sending it back.", "error");

    // The date stays held while she fixes it — a return is usually a small correction, and
    // releasing the slots would let another course take the day in the meantime.
    $pdo->prepare("UPDATE defense_batches SET status = 'Returned', returned_by = ?, returned_at = NOW(), remarks = ? WHERE batch_id = ?")
        ->execute([$me, $remarks, $bid]);
    fp_finish("Sent back to the Focal Person.", "success");
}

fp_finish("Unknown action.", "error");
