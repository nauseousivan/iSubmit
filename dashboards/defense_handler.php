<?php
// dashboards/defense_handler.php — all writes for the defense Apply -> Schedule stage.
//
// Student action:  select_date
// Office actions:  block_date, unblock_date, import_holidays, cancel
//                  (schedule + reject are retired — see the guard below)
//
// Follows calendar_handler.php conventions: POST-only, CSRF-checked, sets
// $_SESSION['message'] / ['message_type'] and redirects back to the referer.

require_once '../config/db.php';
require_once '../config/mail.php';   // milestone mail — sendGroupEmail() / rd_mail_html()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'defense_lib.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';
// Director + Coordinator both act as the RDC Secretary (they already co-own the calendar).
$is_office = in_array($role, ['Research Coordinator', 'Research Director'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student.php");
    exit();
}

$action  = $_POST['action'] ?? '';
$phase   = ($_POST['phase'] ?? 'final') === 'proposal' ? 'proposal' : 'final';
$referer = $_SERVER['HTTP_REFERER'] ?? ($is_office ? 'director.php' : 'student.php');

function df_finish(string $msg, string $type, string $referer): void
{
    $_SESSION['message'] = $msg;
    $_SESSION['message_type'] = $type;
    header("Location: " . $referer);
    exit();
}

// Student surfaces are iframed modules that redirect through student.php with a querystring
// toast, matching upload_handler.php.
function df_student_finish(string $msg, string $type): void
{
    // Land back INSIDE the schedule view of the card the request came from. Dropping the
    // group on the requirement list after every date pick meant clicking back in each time.
    global $phase;
    $module = ($phase === 'proposal') ? 'proposal' : 'final';
    header("Location: student.php?module=" . $module . "&view=schedule&msg=" . urlencode($msg)
        . "&type=" . urlencode($type));
    exit();
}

function df_valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    if ($is_office) {
        df_finish("Invalid security token. Please refresh and try again.", "error", $referer);
    }
    df_student_finish("Security token expired. Please refresh and try again", "error");
}

// ---------------------------------------------------------------- student: pick a date
// RETIRED 2026-09-19. Students no longer choose their own defense date — the Focal Person of
// the course consolidates a whole section onto one day (focal_handler.php, action
// endorse_batch) and the Coordinator and Secretary sign it off. The UI that posted this is
// gone from components/defense_scheduler.php; the action is refused here too, because removing
// a form is not the same as closing an endpoint.
if ($action === 'select_date') {
    df_student_finish("Defense dates are now set by your focal person for your whole section.", "error");
}

// The three office actions below never checked WHO was asking — only the CSRF token, which
// every signed-in student also holds. Closing, reopening and importing dates is office work.
if (in_array($action, ['block_date', 'unblock_date', 'import_holidays'], true) && !$is_office) {
    df_student_finish("That is an office action.", "error");
}

if ($action === 'block_date') {
    $date = trim((string)($_POST['block_date'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));

    if (!df_valid_date($date)) {
        df_finish("Please choose a valid date.", "error", $referer);
    }
    if ($date < df_today()) {
        df_finish("That date has already passed.", "error", $referer);
    }

    // A date a group is actively holding must not be closed under them — the office has to
    // deal with the booking first, so the group always learns what happened.
    $held = $pdo->prepare("SELECT b.booking_id, u.research_group_name FROM defense_bookings b
                           JOIN users u ON u.user_id = b.user_id
                           WHERE b.phase = ? AND b.defense_date = ?
                             AND b.status IN ('Date Selected','Scheduled')");
    $held->execute([$phase, $date]);
    if ($row = $held->fetch(PDO::FETCH_ASSOC)) {
        df_finish("Cannot close " . date('M j', strtotime($date)) . " — "
            . ($row['research_group_name'] ?: 'a group') . " is booked. Cancel or reject that booking first.",
            "error", $referer);
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO defense_blackouts (phase, block_date, reason, source, created_by)
                           VALUES (?, ?, ?, 'manual', ?)");
    $stmt->execute([$phase, $date, $reason !== '' ? $reason : null, $user_id]);

    if ($stmt->rowCount() === 0) {
        df_finish("That date is already closed.", "error", $referer);
    }
    df_finish("Closed " . date('F j, Y', strtotime($date)) . ".", "success", $referer);
}

if ($action === 'unblock_date') {
    $id = (int)($_POST['blackout_id'] ?? 0);
    $date = trim((string)($_POST['block_date'] ?? ''));

    if ($id) {
        $pdo->prepare("DELETE FROM defense_blackouts WHERE blackout_id = ?")->execute([$id]);
    } elseif (df_valid_date($date)) {
        $pdo->prepare("DELETE FROM defense_blackouts WHERE phase = ? AND block_date = ?")->execute([$phase, $date]);
    } else {
        df_finish("Invalid date.", "error", $referer);
    }
    df_finish("Date reopened.", "success", $referer);
}

// Bulk-close Philippine public holidays. The student calendar has always drawn holidays from
// date.nager.at client-side; importing them makes the server agree, so a holiday is genuinely
// unbookable rather than merely greyed out in one view.
if ($action === 'import_holidays') {
    $added = 0;
    // phase=both (the Office calendar): the office being shut shuts it for both defenses.
    $imp_phases = (($_POST['phase'] ?? '') === 'both') ? ['proposal', 'final'] : [$phase];
    $years = [(int)date('Y'), (int)date('Y', strtotime('+' . DEFENSE_HORIZON_MONTHS . ' months'))];
    foreach (array_unique($years) as $year) {
        $json = @file_get_contents("https://date.nager.at/api/v3/PublicHolidays/$year/PH");
        if ($json === false) {
            df_finish("Could not reach the holiday service. Check the internet connection and try again.", "error", $referer);
        }
        $holidays = json_decode($json, true);
        if (!is_array($holidays)) {
            continue;
        }
        foreach ($holidays as $h) {
            if (empty($h['date']) || !df_valid_date($h['date']) || $h['date'] < df_today()) {
                continue;
            }
            foreach ($imp_phases as $imp_phase) {
                // Never close a date a group already holds — the office must handle those by hand.
                $busy = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings
                                       WHERE phase = ? AND defense_date = ? AND status IN ('Date Selected','Scheduled')");
                $busy->execute([$imp_phase, $h['date']]);
                if ((int)$busy->fetchColumn() > 0) {
                    continue;
                }
                $stmt = $pdo->prepare("INSERT IGNORE INTO defense_blackouts (phase, block_date, reason, source, created_by)
                                       VALUES (?, ?, ?, 'holiday', ?)");
                $stmt->execute([$imp_phase, $h['date'], $h['localName'] ?? $h['name'] ?? 'Public holiday', $user_id]);
                $added += $stmt->rowCount();
            }
        }
    }
    df_finish($added > 0 ? "Imported $added public holiday" . ($added === 1 ? '' : 's') . "."
                         : "No new holidays to import.", "success", $referer);
}

// ------------------------------- office: log & schedule / reject / cancel (application flow steps 2-3)
if (in_array($action, ['schedule', 'reject', 'cancel'], true)) {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    if ($booking_id === 0) {
        df_finish("Invalid booking.", "error", $referer);
    }

    $b_stmt = $pdo->prepare("SELECT * FROM defense_bookings WHERE booking_id = ?");
    $b_stmt->execute([$booking_id]);
    $booking = $b_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        df_finish("Booking not found.", "error", $referer);
    }

    // A booking that belongs to a BATCH is governed by the focal -> coordinator -> secretary
    // chain and must not be scheduled, rejected or cancelled one group at a time from this
    // screen — that would publish or kill a date without the signatures the chain exists to
    // collect. Those rows are already filtered out of the queue; this refuses the posted id
    // too, because hiding a row is not the same as closing the endpoint.
    if (!empty($booking['batch_id'])) {
        df_finish("That group is part of a section schedule. Handle it in Schedule Approvals, "
            . "where the whole section is signed off together.", "error", $referer);
    }

    // RETIRED 2026-09-24, for the same reason as select_date above. 'schedule' and 'reject'
    // belong to the flow where a student picked a day and the office said yes or no. That flow
    // is gone: a date is now endorsed by a Focal Person, confirmed by the Coordinator and logged
    // by the Secretary, and the queue that posted these was removed from admin_defense_schedule.php
    // with them. They are refused here rather than merely left unreachable, because 'schedule'
    // mints a slot and publishes a date to a group carrying none of the three signatures the
    // chain exists to collect. 'cancel' stays: bookings logged before the chain existed have no
    // batch, and undoing one is still the office's job.
    if ($action === 'schedule' || $action === 'reject') {
        df_finish("Defense dates are set a section at a time now — the Focal Person endorses one, "
            . "the Coordinator confirms it, and it is logged from Schedule Approvals.", "error", $referer);
    }

    $pretty = $booking['defense_date'] ? date('F j, Y', strtotime($booking['defense_date'])) : 'the requested date';

    // ---- confirm: covers application flow steps 2 and 3 in one action ----
    if ($action === 'schedule') {
        if ($booking['status'] !== 'Date Selected') {
            df_finish("That booking is not waiting for a decision.", "error", $referer);
        }
        // The date may have been closed while the request sat in the queue.
        $blackouts = df_blackouts($pdo, $phase, $booking['defense_date'], $booking['defense_date']);
        if (isset($blackouts[$booking['defense_date']])) {
            df_finish("That date has since been closed (" . htmlspecialchars($blackouts[$booking['defense_date']]['reason'] ?: 'office closed')
                . "). Reject the booking so the group can pick another.", "error", $referer);
        }

        // Confirming is the last act of the phase: it mints the slot, issues the ticket and
        // releases the group's clearance forms. Documents that the office reviews in the Checklist
        // must therefore be Approved first — the screen disables the button, but a disabled button
        // is not a rule, so this is the rule.
        //
        // Only items with a review surface can be required here. Proposal's endorsement is NOT a
        // checklist item — it is accepted BY the act of confirming (see the UPDATE further down) —
        // so gating on it would deadlock the phase with nowhere to clear it.
        $p2_items = df_phase2_reviewed_items($phase);
        if ($p2_items) {
            $ph = implode(',', array_fill(0, count($p2_items), '?'));
            $clear = $pdo->prepare("SELECT COUNT(*) FROM uploads up
                JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads
                      WHERE user_id = ? AND item_id IN ($ph) GROUP BY item_id) latest
                  ON latest.max_id = up.upload_id
                WHERE up.verification_status = 'Approved'");
            $clear->execute(array_merge([(int)$booking['user_id']], $p2_items));
            $n_clear = (int)$clear->fetchColumn();
            if ($n_clear < count($p2_items)) {
                df_finish("Approve all " . count($p2_items) . " documents in the Checklist first — "
                    . $n_clear . " of " . count($p2_items) . " are cleared.", "error", $referer);
            }
        }

        // Assign the slot and confirm in one transaction: the number is 1 + however many groups
        // OF THE SAME SCHOOL are already confirmed on that date (both phases), so two staff
        // confirming at the same moment cannot hand out the same slot. Slots run 1..10 per
        // school, so MCNP and ISAP each have their own slot 1 on a given day. Written once and
        // never recomputed — a student may already have downloaded the ticket.
        $slot = null;
        $bkSchool = df_user_school($pdo, (int)$booking['user_id']);
        try {
            $pdo->beginTransaction();

            // Lock the date across both schools — the day is the contended resource and the
            // range lock also blocks an INSERT into the gap — then count only this school's.
            $pdo->prepare("SELECT booking_id FROM defense_bookings
                           WHERE defense_date = ? AND status = 'Scheduled' FOR UPDATE")
                ->execute([$booking['defense_date']]);

            $count = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings b
                                    JOIN users u ON u.user_id = b.user_id
                                    WHERE b.defense_date = ? AND u.department = ? AND b.status = 'Scheduled'");
            $count->execute([$booking['defense_date'], $bkSchool]);
            $slot = (int)$count->fetchColumn() + 1;

            if ($slot > DEFENSE_SLOTS_PER_SCHOOL_PER_DAY) {
                $pdo->rollBack();
                df_finish("That institution has no slots left on that date (" . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY
                    . " of " . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY
                    . "). Reject the booking so the group can be given another day.", "error", $referer);
            }

            $pdo->prepare("UPDATE defense_bookings
                           SET status = 'Scheduled', slot_no = ?, decided_by = ?, decided_at = NOW(), remarks = NULL
                           WHERE booking_id = ?")
                ->execute([$slot, $user_id, $booking_id]);

            if (!empty($booking['application_upload_id'])) {
                $pdo->prepare("UPDATE uploads SET verification_status = 'Approved' WHERE upload_id = ?")
                    ->execute([$booking['application_upload_id']]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            df_finish("Could not log that schedule. Please try again.", "error", $referer);
        }

        $slotText = 'Slot ' . $slot . ' of ' . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY;
        $whatNext = ($phase === 'proposal')
            ? ' Your Final Defense card is now open.'
            : ' Your requirements are now open.';

        df_log($pdo, (int)$booking['user_id'], ucwords(df_phase_label($phase)) . ' Scheduled',
               'Your ' . df_phase_label($phase) . ' is set for ' . $pretty . ' — ' . $slotText . '.'
               . ' Groups are heard in slot order, so be ready before yours is called.' . $whatNext, 'success');

        // A confirmed date is the one milestone a group must not miss — they have to physically
        // turn up. Mailed to every member, not just the leader, and never allowed to break the
        // booking if the mail server is down.
        sendGroupEmail(
            $pdo,
            (int)$booking['user_id'],
            'Your ' . df_phase_label($phase) . ' is scheduled — ' . $pretty,
            rd_mail_html(
                'Your ' . df_phase_label($phase) . ' is scheduled',
                [
                    'The research office has logged your defense date. Bring your printed forms on the day.',
                    'Groups are heard in <strong>slot order</strong>, so be ready well before yours is called.',
                ],
                $pretty . ' &middot; ' . $slotText,
                trim($whatNext) . ' Open iSubmit to download your ticket.'
            )
        );

        df_finish("Scheduled and logged for " . $pretty . " — " . $slotText . ".", "success", $referer);
    }

    // ---- cancel: undo a confirmed schedule ----
    if ($action === 'cancel') {
        if ($booking['status'] !== 'Scheduled') {
            df_finish("Only a confirmed schedule can be cancelled.", "error", $referer);
        }
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks === '') {
            df_finish("Please give a reason so the group knows what to do next.", "error", $referer);
        }

        // Releasing the date frees it immediately. Uploaded requirement files are deliberately
        // left untouched — cancelling a date must never destroy a group's work; the gate simply
        // closes again until they are re-scheduled.
        $pdo->prepare("UPDATE defense_bookings
                       SET status = 'Cancelled', defense_date = NULL, remarks = ?,
                           decided_by = ?, decided_at = NOW()
                       WHERE booking_id = ?")
            ->execute([$remarks, $user_id, $booking_id]);

        df_log($pdo, (int)$booking['user_id'], 'Defense Schedule Cancelled',
               'Your ' . $pretty . ' defense was cancelled by the research office. ' . $remarks, 'warning');

        df_finish("Cancelled. The group has been notified; their Focal Person can put them on a new date.", "success", $referer);
    }

    // ---- reject: turn down a requested date ----
    if ($booking['status'] !== 'Date Selected') {
        df_finish("That booking is not waiting for a decision.", "error", $referer);
    }
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    if ($remarks === '') {
        df_finish("Please give a reason so the group knows what to do next.", "error", $referer);
    }

    $pdo->prepare("UPDATE defense_bookings
                   SET status = 'Rejected', defense_date = NULL, remarks = ?, decided_by = ?, decided_at = NOW()
                   WHERE booking_id = ?")
        ->execute([$remarks, $user_id, $booking_id]);

    df_log($pdo, (int)$booking['user_id'], 'Defense Date Not Confirmed', $remarks, 'warning');

    df_finish("Booking returned to the group.", "success", $referer);
}

df_finish("Unknown action.", "error", $referer);
