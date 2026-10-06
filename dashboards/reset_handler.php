<?php
// dashboards/reset_handler.php — "Start a New Research" (archive-and-reset).
//
// A group that finished its research comes back the next year with a NEW study. This
// handler MOVES the whole finished cycle into the `*_archive` tables, leaving the live
// tables empty for that group so the student portal reads 0% again, then stamps the new
// research details onto the leader and the members who carried over.
//
// Nothing is destroyed:
//   * every archived row stays fully readable by staff in research_history.php
//   * files under uploads/ are NEVER unlinked — archive rows keep file_path verbatim
//
// Leader-only, self-service, guarded by CSRF + a typed confirmation phrase.
// Follows defense_handler.php conventions: POST-only, CSRF gate at the top, redirects
// back through student.php with a querystring toast.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'defense_lib.php';   // df_effective_user_id() — same group-owner rule

const RESET_CONFIRM_PHRASE = 'START NEW RESEARCH';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';

// A dry run executes the entire transaction and then rolls it back, printing the row
// counts it would have moved. Nothing is written. Used to sanity-check a group before
// doing it for real — see docs/sql/2026-08-research-cycles.sql.
$dry_run = isset($_GET['dry']) || isset($_POST['dry']);

function rr_finish(string $msg, string $type): void
{
    header("Location: student.php?module=settings&msg=" . urlencode($msg) . "&type=" . urlencode($type));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student.php");
    exit();
}

// -------------------------------------------------------------------- guards
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    rr_finish("Security token expired. Please refresh and try again", "error");
}

if ($role !== 'Student') {
    rr_finish("Only students can start a new research", "error");
}

// The caller must be a LEADER. All of a group's progress is keyed to the leader's
// user_id (upload_handler.php's $effective_user_id), so a member resetting would either
// do nothing or wipe a group they don't own.
$stmt = $pdo->prepare("SELECT user_id, username, email, student_number, department, program,
                              research_group_name, research_title, year_level, leader_id
                       FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$leader = $stmt->fetch();

if (!$leader) {
    rr_finish("Account not found", "error");
}
if ($leader['leader_id'] !== null) {
    rr_finish("Only your group leader can start a new research", "error");
}

if (trim((string)($_POST['confirm_phrase'] ?? '')) !== RESET_CONFIRM_PHRASE) {
    rr_finish("You must type " . RESET_CONFIRM_PHRASE . " exactly to proceed", "error");
}

// ---------------------------------------------------------------- new details
// Registration's Step 4 labels this field "Research Project Title" but stores it in
// users.research_group_name (auth/register.php). users.research_title is a separate
// column written by the statistical treatment form. Both are set here so they can't
// drift apart for the new cycle.
$new_title = trim((string)($_POST['new_title'] ?? ''));
if ($new_title === '') {
    rr_finish("Enter the title of your new research", "error");
}
if (mb_strlen($new_title) > 255) {
    $new_title = mb_substr($new_title, 0, 255);
}

$allowed_years = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
$new_year      = trim((string)($_POST['new_year_level'] ?? ''));
if ($new_year !== '' && !in_array($new_year, $allowed_years, true)) {
    $new_year = '';
}

// Members the leader ticked to carry over into the new research.
$keep_members = array_map('intval', (array)($_POST['keep_members'] ?? []));

// ---------------------------------------------------------- the group as it stands
$effective_user_id = df_effective_user_id($pdo, $user_id);   // === $user_id for a leader

$mstmt = $pdo->prepare("SELECT user_id, username, email, student_number, year_level
                        FROM users WHERE leader_id = ? ORDER BY username");
$mstmt->execute([$effective_user_id]);
$members = $mstmt->fetchAll();

$member_ids = array_map(fn($m) => (int)$m['user_id'], $members);
$keep_ids   = array_values(array_intersect($keep_members, $member_ids));
$drop_ids   = array_values(array_diff($member_ids, $keep_ids));

// activity_logs are written against whichever member acted, not only the leader, so the
// archive has to sweep the whole group.
$group_ids = array_merge([$effective_user_id], $member_ids);

/** Build an "IN (?,?,?)" clause for a list of ints. */
function rr_in(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

$moved = [];

try {
    $pdo->beginTransaction();

    // ------------------------------------------------------- 1. the cycle record
    $seq_stmt = $pdo->prepare("SELECT COALESCE(MAX(cycle_no), 0) + 1 FROM research_cycles WHERE leader_user_id = ?");
    $seq_stmt->execute([$effective_user_id]);
    $cycle_no = (int)$seq_stmt->fetchColumn();

    // Control numbers are denormalised onto the cycle so the history list can show them
    // without joining three archive tables.
    $stat_no = $pdo->prepare("SELECT formatted_control_no FROM form_stat_treatment
                              WHERE user_id = ? AND formatted_control_no IS NOT NULL
                              ORDER BY form_id DESC LIMIT 1");
    $stat_no->execute([$effective_user_id]);
    $stats_control_no = $stat_no->fetchColumn() ?: null;

    $plag_no = $pdo->prepare("SELECT formatted_control_no FROM plagiarism_checks WHERE user_id = ?");
    $plag_no->execute([$effective_user_id]);
    $plag_control_no = $plag_no->fetchColumn() ?: null;

    // Snapshot of who was in the group, because users.research_group_name is about to be
    // overwritten and dropped members lose their link entirely.
    $roster = [[
        'user_id'        => (int)$leader['user_id'],
        'username'       => $leader['username'],
        'email'          => $leader['email'],
        'student_number' => $leader['student_number'],
        'year_level'     => $leader['year_level'],
        'role'           => 'Leader',
    ]];
    foreach ($members as $m) {
        $roster[] = [
            'user_id'        => (int)$m['user_id'],
            'username'       => $m['username'],
            'email'          => $m['email'],
            'student_number' => $m['student_number'],
            'year_level'     => $m['year_level'],
            'role'           => 'Member',
            'carried_over'   => in_array((int)$m['user_id'], $keep_ids, true),
        ];
    }

    $pdo->prepare("INSERT INTO research_cycles
            (leader_user_id, cycle_no, research_group_name, research_title, department, program,
             year_level, members_json, stats_control_no, plag_control_no, archived_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $effective_user_id, $cycle_no,
            $leader['research_group_name'], $leader['research_title'],
            $leader['department'], $leader['program'], $leader['year_level'],
            json_encode($roster, JSON_UNESCAPED_UNICODE),
            $stats_control_no, $plag_control_no, $user_id,
        ]);

    $cycle_id = (int)$pdo->lastInsertId();

    // ------------------------------------------------- 2. copy the live rows across
    // Column lists are written out in full and match the archive tables 1:1 — a bare
    // `SELECT *` would silently shift every value if a live table ever gains a column.
    $gid = rr_in($group_ids);

    $copies = [
        'uploads' => [
            "INSERT INTO uploads_archive
                (cycle_id, orig_upload_id, user_id, item_id, original_filename, file_path,
                 verification_status, remarks, uploaded_at, form_008_data, form_008_score, form_008_decision)
             SELECT ?, upload_id, user_id, item_id, original_filename, file_path,
                    verification_status, remarks, uploaded_at, form_008_data, form_008_score, form_008_decision
             FROM uploads WHERE user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'approvals' => [
            "INSERT INTO approvals_archive
                (cycle_id, orig_approval_id, user_id, form_id, coordinator_status,
                 statistician_status, director_status, payment_status, printing_enabled, updated_at)
             SELECT ?, approval_id, user_id, form_id, coordinator_status,
                    statistician_status, director_status, payment_status, printing_enabled, updated_at
             FROM approvals WHERE user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'form_stat_treatment' => [
            "INSERT INTO form_stat_treatment_archive
                (cycle_id, orig_form_id, control_number_seq, formatted_control_no, user_id,
                 research_title, proponents, course, main_or_number, date_submitted, date_released,
                 contact_email, contact_number, file_sop, file_questionnaire, file_coded_data,
                 file_comm_letter, file_mom, status, statistician_remarks, result_file)
             SELECT ?, form_id, control_number_seq, formatted_control_no, user_id,
                    research_title, proponents, course, main_or_number, date_submitted, date_released,
                    contact_email, contact_number, file_sop, file_questionnaire, file_coded_data,
                    file_comm_letter, file_mom, status, statistician_remarks, result_file
             FROM form_stat_treatment WHERE user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'plagiarism_checks' => [
            "INSERT INTO plagiarism_checks_archive
                (cycle_id, orig_id, user_id, control_number_seq, formatted_control_no)
             SELECT ?, id, user_id, control_number_seq, formatted_control_no
             FROM plagiarism_checks WHERE user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'defense_bookings' => [
            "INSERT INTO defense_bookings_archive
                (cycle_id, orig_booking_id, user_id, phase, defense_date, application_upload_id,
                 status, decided_by, decided_at, remarks, created_at, updated_at)
             SELECT ?, booking_id, user_id, phase, defense_date, application_upload_id,
                    status, decided_by, decided_at, remarks, created_at, updated_at
             FROM defense_bookings WHERE user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'form_008_reviews' => [
            "INSERT INTO form_008_reviews_archive
                (cycle_id, orig_review_id, upload_id, student_user_id, reviewer_id, item_id,
                 research_group_name, adviser_name, approved_title, proponents, department,
                 assessment_responses, yes_count, final_score, final_decision, comments,
                 created_at, updated_at)
             SELECT ?, review_id, upload_id, student_user_id, reviewer_id, item_id,
                    research_group_name, adviser_name, approved_title, proponents, department,
                    assessment_responses, yes_count, final_score, final_decision, comments,
                    created_at, updated_at
             FROM form_008_reviews WHERE student_user_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        'payment_logs' => [
            "INSERT INTO payment_logs_archive
                (cycle_id, orig_log_id, form_id, student_id, actor_id, action, control_no,
                 item_id, remarks, created_at)
             SELECT ?, log_id, form_id, student_id, actor_id, action, control_no,
                    item_id, remarks, created_at
             FROM payment_logs WHERE student_id = ?",
            [$cycle_id, $effective_user_id],
        ],
        // Every member's own timeline entries, not just the leader's.
        'activity_logs' => [
            "INSERT INTO activity_logs_archive
                (cycle_id, orig_activity_id, user_id, upload_id, title, description, status_type, created_at)
             SELECT ?, activity_id, user_id, upload_id, title, description, status_type, created_at
             FROM activity_logs WHERE user_id IN ($gid)",
            array_merge([$cycle_id], $group_ids),
        ],
    ];

    foreach ($copies as $table => [$sql, $params]) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $moved[$table] = $st->rowCount();
    }

    // ------------------------------------------------- 3. delete the live rows
    // FK-safe order. form_008_reviews.upload_id and uploads.item_id are plain RESTRICT
    // foreign keys, so `uploads` has to go LAST — see docs/sql/2026-08-research-cycles.sql.
    $deletes = [
        ["DELETE FROM form_008_reviews WHERE student_user_id = ?", [$effective_user_id]],
        ["DELETE FROM defense_bookings WHERE user_id = ?",         [$effective_user_id]],
        ["DELETE FROM activity_logs WHERE user_id IN ($gid)",      $group_ids],
        ["DELETE FROM payment_logs WHERE student_id = ?",          [$effective_user_id]],
        ["DELETE FROM form_stat_treatment WHERE user_id = ?",      [$effective_user_id]],
        ["DELETE FROM plagiarism_checks WHERE user_id = ?",        [$effective_user_id]],
        ["DELETE FROM approvals WHERE user_id = ?",                [$effective_user_id]],
        ["DELETE FROM uploads WHERE user_id = ?",                  [$effective_user_id]],
    ];
    foreach ($deletes as [$sql, $params]) {
        $pdo->prepare($sql)->execute($params);
    }

    // NOTE: the files themselves stay on disk. uploads_archive.file_path still points at
    // them so staff can open last year's documents from Research History. Deleting them
    // here would turn a reversible archive into permanent data loss.

    // ---------------------------------------------- 4. stamp the new research details
    // research_group_name is a shared group attribute: write it to the leader AND every
    // carried-over member so the two can't drift (config/group_helpers.php's
    // link_student_to_leader() sets it on both; profile.php:98 only does the leader).
    $carry_ids = array_merge([$effective_user_id], $keep_ids);
    $cin       = rr_in($carry_ids);
    $pdo->prepare("UPDATE users SET research_group_name = ?, research_title = ? WHERE user_id IN ($cin)")
        ->execute(array_merge([$new_title, $new_title], $carry_ids));

    // year_level is personal — like student_number it always goes to the caller's own row,
    // never inherited by the members.
    if ($new_year !== '') {
        $pdo->prepare("UPDATE users SET year_level = ? WHERE user_id = ?")->execute([$new_year, $user_id]);
    }

    // Members the leader dropped become their own leader again and can start fresh.
    if (!empty($drop_ids)) {
        $din = rr_in($drop_ids);
        $pdo->prepare("UPDATE users SET leader_id = NULL, research_group_name = NULL, research_title = NULL
                       WHERE user_id IN ($din)")->execute($drop_ids);
    }

    // Invitations issued for the old research would otherwise link a stranger into the new one.
    $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE leader_id = ? AND status = 'pending'")
        ->execute([$effective_user_id]);

    // ------------------------------------------------------------ 5. open the new log
    // status_type 'info' = the user's own action (the activity log). 'success'/'warning'
    // are reserved for staff review verdicts, which drive the notification bell.
    $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type)
                   VALUES (?, NULL, ?, ?, 'info')")
        ->execute([
            $effective_user_id,
            'New research started',
            'Your previous research was archived with the Research Office. You are now working on "' . $new_title . '".',
        ]);

    if ($dry_run) {
        $pdo->rollBack();
        header('Content-Type: text/plain; charset=utf-8');
        echo "DRY RUN — nothing was written.\n\n";
        echo "leader user_id : $effective_user_id\n";
        echo "cycle_no       : $cycle_no\n";
        echo "new title      : $new_title\n";
        echo "year level     : " . ($new_year ?: '(unchanged)') . "\n";
        echo "members kept   : " . (empty($keep_ids) ? '(none)' : implode(', ', $keep_ids)) . "\n";
        echo "members dropped: " . (empty($drop_ids) ? '(none)' : implode(', ', $drop_ids)) . "\n";
        echo "stat control # : " . ($stats_control_no ?: '(none)') . "\n";
        echo "plag control # : " . ($plag_control_no ?: '(none)') . "\n\n";
        echo "rows that would be archived:\n";
        foreach ($moved as $table => $n) {
            printf("  %-24s %d\n", $table, $n);
        }
        exit();
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('reset_handler: ' . $e->getMessage());
    rr_finish("Could not start your new research. Nothing was changed. Please try again.", "error");
}

$_SESSION['research_group_name'] = $new_title;

$total = array_sum($moved);
rr_finish(
    "Your new research is set up. " . $total . " record" . ($total === 1 ? '' : 's') .
    " from your previous research were archived with the Research Office.",
    "success"
);
