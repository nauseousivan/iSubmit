<?php
// dashboards/defense_lib.php — shared reads for the defense Apply -> Schedule stage.
//
// Implements RDC's "Application for Research Defense" steps 1-3, for BOTH phases: a group
// uploads its application document(s), picks a date from the office calendar, and the office
// logs and confirms it. See df_application_items() for what each phase asks for.
//   proposal -> no application STAGE: all seven requirements, the Form 002 application (17) and
//               the endorsement included, are checklist items, and the Focal Person schedules
//               the section.
//   final    -> no application stage either (2026-09-25): all seven requirements, Form 002
//               included, are checklist items and the Focal Person schedules the section.
//
// AVAILABILITY MODEL: the office is open by DEFAULT. There is no list of published slots —
// staff close the exceptions instead (holidays, closures) in `defense_blackouts`. A date is
// bookable when all four hold:
//   1. it sits inside the booking window (see the constants below),
//   2. it is not a Sunday (the portal has always treated Sunday as closed),
//   3. no blackout row covers it,
//   4. its SCHOOL still has a free slot that day (see DEFENSE_SLOTS_PER_SCHOOL_PER_DAY).
//
// Included read-only by module_final.php / module_proposal.php / student.php /
// admin_defense_schedule.php. All writes live in defense_handler.php.

// PLUMBING: the upload that CREATES the booking row and owns
// `defense_bookings.application_upload_id`.
// PROPOSAL HAS NO APPLICATION DOCUMENT ANY MORE (2026-09-19).
// Item 12 (Endorsement From the Adviser) used to sit outside the requirement grid: clearing the
// five EARNED it, the group had it signed, and re-uploading it was how they applied for a date.
// Students no longer apply — the Focal Person schedules the section — so that separate stage had
// nothing left to do but add a step. Item 12 is now the SIXTH proposal requirement, uploaded and
// approved in the same grid as the other five, which is why the progress reads 6/6.
// Final lost its one too (2026-09-25): the office asked for the same flow as Proposal, so Form
// 002 is now simply the first of its seven requirements. Nothing has an application stage.
function df_application_items(string $phase): array
{
    return [];
}

// WHAT THE SCHEDULE PANEL STILL COLLECTS: nothing, in either phase. Kept as a function rather
// than deleted because four callers ask it what to render, and an empty list is the answer that
// turns each of them off.
function df_phase2_items(string $phase): array
{
    // NOTHING, for either phase. Both grids now collect every requirement up front and the
    // panel is status-only. Final's 22-27 used to be uploaded in here beside a date picker.
    return [];
}

/**
 * Of those, the ones the office actually reviews in the Checklist — and therefore the ones a date
 * confirmation may be gated on.
 *
 * Both return nothing because neither panel collects anything: every requirement, each phase's
 * application form included, is reviewed in the checklist grid.
 */
function df_phase2_reviewed_items(string $phase): array
{
    return [];
}

/**
 * The requirements that must be approved before a group can be put on a defense date.
 *   proposal — all SEVEN: the application 17, 11, 13, 14, 15, 16 and the signed
 *              endorsement 12.
 *   final    — all SEVEN: Form 002 (20) and 22-27.
 * Reads the latest upload per item, matching every other progress rule in the project.
 */
function df_phase1_items(string $phase): array
{
    // Seven for proposal: the five, plus 12 (the signed adviser endorsement) and 17 (the
    // Application for Research Defense, Form 002), both ordinary requirements in the grid.
    // 17 is the proposal's OWN copy of that form — item 20 is Final's and must stay separate.
    return $phase === 'proposal' ? [17, 11, 13, 14, 15, 16, 12] : [20, 22, 23, 24, 25, 26, 27];
}

function df_phase1_cleared(PDO $pdo, int $effectiveUserId, string $phase): bool
{
    $items = df_phase1_items($phase);
    $ph = implode(',', array_fill(0, count($items), '?'));
    $stmt = $pdo->prepare("
        SELECT u.item_id, u.verification_status
        FROM uploads u
        INNER JOIN (SELECT item_id, MAX(uploaded_at) AS m FROM uploads
                    WHERE user_id = ? AND item_id IN ($ph) GROUP BY item_id) l
                ON l.item_id = u.item_id AND l.m = u.uploaded_at
        WHERE u.user_id = ? AND u.item_id IN ($ph)");
    $stmt->execute(array_merge([$effectiveUserId], $items, [$effectiveUserId], $items));
    $seen = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($items as $item) {
        if (($seen[$item] ?? null) !== 'Approved') return false;
    }
    return true;
}

/** The one that owns `defense_bookings.application_upload_id`. */
function df_primary_application_item(string $phase): ?int
{
    // null for proposal, which no longer has an application document at all.
    return df_application_items($phase)[0] ?? null;
}

/** Which phase an item id applies to, or null when it is an ordinary requirement. */
function df_phase_for_application_item(int $itemId): ?string
{
    foreach (['proposal', 'final'] as $phase) {
        if (in_array($itemId, df_application_items($phase), true)) return $phase;
    }
    return null;
}

// Booking window. Both are tunable in one place: the office needs a little lead time to
// prepare, and an open-ended calendar invites bookings nobody can plan for.
const DEFENSE_LEAD_DAYS = 1;        // earliest bookable date = tomorrow
const DEFENSE_HORIZON_MONTHS = 4;   // latest bookable date

// How many defenses ONE SCHOOL can hear in a day. MCNP and ISAP run their own panels, so the
// two pools are independent: a day holds 10 MCNP + 10 ISAP = 20 in total, and MCNP filling up
// must never close that day to ISAP. Shared across proposal and final within a school — a
// proposal and a final may sit on the same date and come out of the same 10.
//
// Groups are heard in sequence and a defense takes as long as it takes, so a group gets a slot
// NUMBER, never a clock time — and those run 1..10 PER SCHOOL, so one date legitimately has two
// "slot 3"s, one in each school. Always pair a slot number with its school when displaying it.
// 2026-10-01: the Admin sets this in System settings (system_settings.slots_per_day, 1..50);
// 10 when unset. Read once, at load — config/db.php is always required before this file.
if (!defined('DEFENSE_SLOTS_PER_SCHOOL_PER_DAY')) {
    define('DEFENSE_SLOTS_PER_SCHOOL_PER_DAY', function_exists('rd_setting_int') ? rd_setting_int('slots_per_day', 10, 1, 50) : 10);
}

/**
 * Every school that actually has student groups, in a stable order.
 *
 * Capacity is per school, so any whole-office view (the Secretary's planner) has to loop these
 * rather than count one pool. Read from data, not hardcoded: a third institution would
 * otherwise silently get no slots at all.
 */
function df_schools(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT department FROM users
                        WHERE role = 'Student' AND department IS NOT NULL AND department <> ''
                        ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * The school a user belongs to. `users.department` holds the SCHOOL (MCNP vs ISAP), not a
 * department — see CLAUDE.md. Capacity is per school, so nearly every availability question
 * needs this first.
 */
function df_user_school(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: '');
}

/**
 * BLANK FORMS PER INSTITUTION (2026-10-05). Kept as thin names over config/forms.php, which holds the
 * catalogue and the Admin's uploads (Admin → System → Forms). Every blank goes through rd_form_url().
 */
function df_is_mcnp(PDO $pdo, int $userId): bool
{
    return rd_user_institution($pdo, $userId) === 'MCNP';
}

/** Form 002, the Application for Research Defense, for this student's school. */
function df_application_form(PDO $pdo, int $userId): string
{
    return rd_form_url($pdo, 'application', $userId);
}

/** Any blank in the catalogue (endorsement, assigned_adviser, mom, ...) for this student's school. */
function df_institution_form(PDO $pdo, int $userId, string $name): string
{
    return rd_form_url($pdo, $name, $userId);
}

/**
 * Group-level owner id. Uploads and bookings are keyed to the LEADER so every member of a
 * research group sees the same booking — mirrors upload_handler.php's $effective_user_id.
 */
function df_effective_user_id(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $leader = $stmt->fetchColumn();
    return $leader ? (int)$leader : $userId;
}

/**
 * Today in Asia/Manila. PHP on this box runs Europe/Berlin, so a bare date('Y-m-d') is a day
 * behind for the whole Manila evening — long enough to offer "tomorrow" as a date that has
 * already started, or to mark a live defense as past. Read via an explicit DateTimeZone rather
 * than date_default_timezone_set() so the rest of the project's timestamp handling is
 * untouched (same approach student.php uses for its greeting).
 */
function df_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function df_shift(string $date, string $modifier): string
{
    return (new DateTime($date, new DateTimeZone('Asia/Manila')))->modify($modifier)->format('Y-m-d');
}

function df_window_start(): string
{
    return df_shift(df_today(), '+' . DEFENSE_LEAD_DAYS . ' days');
}

function df_window_end(): string
{
    return df_shift(df_today(), '+' . DEFENSE_HORIZON_MONTHS . ' months');
}

/**
 * The group's booking for a phase, joined to its Form 002 upload.
 * Returns null when the group has not applied yet.
 */
function df_get_booking(PDO $pdo, int $effectiveUserId, string $phase = 'final'): ?array
{
    $stmt = $pdo->prepare("
        SELECT b.*,
               u.file_path AS application_path, u.original_filename AS application_filename,
               u.verification_status AS application_status, u.uploaded_at AS application_uploaded_at
        FROM defense_bookings b
        LEFT JOIN uploads u ON u.upload_id = b.application_upload_id
        WHERE b.user_id = ? AND b.phase = ?");
    $stmt->execute([$effectiveUserId, $phase]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Blacked-out dates in a range, as date => row. */
function df_blackouts(PDO $pdo, string $phase, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT * FROM defense_blackouts
                           WHERE phase = ? AND block_date BETWEEN ? AND ?
                           ORDER BY block_date ASC");
    $stmt->execute([$phase, $from, $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['block_date']] = $r;
    }
    return $out;
}

/**
 * How full each date is, as date => ['count','left','mine','groups'].
 *
 * Deliberately NOT scoped to a phase: a school hears proposal and final defenses on the same
 * day out of one pool of DEFENSE_SLOTS_PER_SCHOOL_PER_DAY, so capacity has to be counted across
 * both or a date could be sold twice over. `$phase` is still taken so callers read consistently
 * and so the per-phase breakdown is available to staff.
 *
 * IS scoped to a SCHOOL, and $school is required for any capacity decision: the two schools hold
 * independent pools of 10. Pass null only to view every booking on a date (the Secretary's
 * combined list) — 'left' is then null, because "how many are left" has no answer across two
 * pools and a caller that treats null as a number is a bug worth crashing on.
 *
 * A held date covers both a pending request and a confirmed schedule — either way the slot
 * is spoken for.
 */
function df_taken_dates(PDO $pdo, string $phase, ?string $from = null, ?string $to = null, ?string $school = null): array
{
    $sql = "SELECT b.booking_id, b.user_id, b.defense_date, b.status, b.phase, b.slot_no,
                   u.username, u.research_group_name, u.program, u.department
            FROM defense_bookings b
            JOIN users u ON u.user_id = b.user_id
            WHERE b.defense_date IS NOT NULL
              AND b.status IN ('Date Selected','Scheduled')";
    $params = [];
    if ($school !== null) {
        $sql .= " AND u.department = ?";
        $params[] = $school;
    }
    if ($from && $to) {
        $sql .= " AND b.defense_date BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
    }
    $sql .= " ORDER BY b.defense_date ASC, b.slot_no ASC, b.booking_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['defense_date'];
        if (!isset($out[$d])) {
            $out[$d] = ['count' => 0,
                         'left'  => $school !== null ? DEFENSE_SLOTS_PER_SCHOOL_PER_DAY : null,
                         'mine'  => false, 'groups' => []];
        }
        $out[$d]['count']++;
        if ($school !== null) {
            $out[$d]['left'] = max(0, DEFENSE_SLOTS_PER_SCHOOL_PER_DAY - $out[$d]['count']);
        }
        $out[$d]['groups'][] = $r;
    }
    return $out;
}

/**
 * Marks which dates the given group holds IN THIS PHASE, so the calendar can colour them "mine".
 *
 * Phase-scoped on purpose, unlike the capacity count: a group's final defense date must not
 * show up as "your pick" while they are booking their proposal defense — that date is spoken
 * for, but it is not this booking.
 */
function df_mark_mine(array $taken, int $effectiveUserId, string $phase): array
{
    foreach ($taken as $date => $info) {
        foreach ($info['groups'] as $g) {
            if ((int)$g['user_id'] === $effectiveUserId && $g['phase'] === $phase) {
                $taken[$date]['mine'] = true;
                break;
            }
        }
    }
    return $taken;
}

/**
 * Why a date cannot be booked, or null when it can be.
 * The single source of truth — the calendar renders from it and the handler enforces it, so
 * the two can never disagree about what is clickable.
 */
function df_unavailable_reason(string $date, array $blackouts, array $taken, int $effectiveUserId): ?string
{
    if ($date < df_window_start()) {
        return 'Too soon — the office needs a little lead time';
    }
    if ($date > df_window_end()) {
        return 'Too far ahead to book yet';
    }
    if ((int)date('w', strtotime($date)) === 0) {
        return 'The research office is closed on Sundays';
    }
    if (isset($blackouts[$date])) {
        $reason = trim((string)$blackouts[$date]['reason']);
        return $reason !== '' ? $reason : 'The office is closed on this date';
    }
    // Full is full — but a group already holding THIS phase's slot here can always keep it.
    // ('mine' is set by df_mark_mine and is phase-scoped; holding the other phase's slot on
    // this date does not entitle the group to a second one when the day is full.)
    // $taken must be a SCHOOL-scoped count (df_taken_dates with a $school), or this reads one
    // school's day as full because the other school filled it.
    if (isset($taken[$date])) {
        if (empty($taken[$date]['mine']) && $taken[$date]['count'] >= DEFENSE_SLOTS_PER_SCHOOL_PER_DAY) {
            return 'Your institution has no slots left on this date ('
                . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY . ' of ' . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY . ' taken)';
        }
    }
    return null;
}

/**
 * The slot a group would be given on a date today — 1-based, first come first served WITHIN ITS
 * SCHOOL. MCNP and ISAP number their own days, so both can hold a slot 3 on one date.
 * Only ever read for display; the number that sticks is written once when the office confirms.
 */
function df_next_slot(PDO $pdo, string $date, string $school): int
{
    // Counts HELD slots as well as confirmed ones. A batch sits at 'Date Selected' for as long
    // as it is travelling the focal -> coordinator -> secretary chain; if those were not counted,
    // two batches endorsed onto the same day would both be handed slot 1.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings b
                           JOIN users u ON u.user_id = b.user_id
                           WHERE b.defense_date = ? AND u.department = ?
                             AND b.status IN ('Date Selected','Scheduled')");
    $stmt->execute([$date, $school]);
    return (int)$stmt->fetchColumn() + 1;
}

/**
 * A month of day cells for the picker/planner, so student and staff calendars are built from
 * exactly the same availability call.
 */
function df_month_cells(PDO $pdo, string $phase, int $year, int $month, int $effectiveUserId = 0, ?string $school = null): array
{
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));
    $blackouts = df_blackouts($pdo, $phase, $first, $last);
    $taken     = df_mark_mine(df_taken_dates($pdo, $phase, $first, $last, $school), $effectiveUserId, $phase);
    $today     = df_today();

    $cells = [];
    $days = (int)date('t', strtotime($first));
    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $reason = df_unavailable_reason($date, $blackouts, $taken, $effectiveUserId);
        $info   = $taken[$date] ?? null;
        $isMine = $info !== null && $info['mine'];
        $isFull = $info !== null && $info['count'] >= DEFENSE_SLOTS_PER_SCHOOL_PER_DAY;

        // 'taken' now means FULL, not "somebody is on it" — a date with free slots stays open.
        if ($isMine)                       $state = 'mine';
        elseif ($isFull)                   $state = 'taken';
        elseif (isset($blackouts[$date]))  $state = 'blocked';
        elseif ((int)date('w', strtotime($date)) === 0) $state = 'closed';
        elseif ($date < $today)            $state = 'past';
        elseif ($reason !== null)          $state = 'out';
        else                               $state = 'open';

        $cells[$d] = [
            'date'      => $date,
            'day'       => $d,
            'state'     => $state,
            'bookable'  => ($reason === null),
            'reason'    => $reason,
            'is_today'  => ($date === $today),
            'blackout'  => $blackouts[$date] ?? null,
            'booking'   => $info,
            'slots_used' => $info['count'] ?? 0,
            'slots_left' => $info['left'] ?? DEFENSE_SLOTS_PER_SCHOOL_PER_DAY,
        ];
    }
    return $cells;
}

/** The next bookable date — powers the "soonest available" shortcut on the picker. */
function df_soonest_open(PDO $pdo, string $phase, int $effectiveUserId = 0, ?string $school = null): ?string
{
    $from = df_window_start();
    $to   = df_window_end();
    $blackouts = df_blackouts($pdo, $phase, $from, $to);
    $taken     = df_mark_mine(df_taken_dates($pdo, $phase, $from, $to, $school), $effectiveUserId, $phase);
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $date = date('Y-m-d', $t);
        if (df_unavailable_reason($date, $blackouts, $taken, $effectiveUserId) === null) {
            return $date;
        }
    }
    return null;
}

// df_upcoming() lived here and had one caller: the office's "Upcoming" card. Its rows carried no
// booking detail, so nothing on that card could be opened. That card now takes the forward half of
// the record it already loads, which is what makes every row clickable — leaving this behind would
// have left a second, subtly different "what is coming up" answer for someone to pick up by
// mistake.

/**
 * The gate. A phase's requirements stay locked until the office has actually logged the date
 * (application flow step 3) — applying alone is not enough, and a cancellation re-locks them.
 * Also what unlocks the NEXT stage card on the dashboard: clearing proposal opens Final and
 * Statistics, because a scheduled proposal defense is what produces the Minutes that Final
 * asks for.
 */
function df_requirements_unlocked(?array $booking): bool
{
    return $booking !== null && $booking['status'] === 'Scheduled';
}

/** True once the defense day itself is behind the group. */
function df_defense_done(?array $booking): bool
{
    return df_requirements_unlocked($booking)
        && !empty($booking['defense_date'])
        && $booking['defense_date'] < df_today();
}

/**
 * Has the research office released this group to send its final manuscript?
 *
 * The face-to-face final defense happens outside the system, so nothing here can tell that it
 * actually went ahead — only the office knows. They flip this from the Manuscript screen once the
 * panel is done with a group. It is what opens the Plagiarism card, swaps the dashboard billboard
 * and lights the CTA on the defense ticket.
 *
 * Returns the released_at timestamp, or null. df_defense_done() still decides who may be released;
 * this decides what the student sees.
 */
function df_manuscript_released(PDO $pdo, int $effectiveUserId): ?string
{
    $stmt = $pdo->prepare("SELECT released_at FROM plagiarism_checks WHERE user_id = ?");
    $stmt->execute([$effectiveUserId]);
    return $stmt->fetchColumn() ?: null;
}

/** Human label for a phase, used throughout the stage copy. */
function df_phase_label(string $phase): string
{
    return $phase === 'proposal' ? 'proposal defense' : 'final defense';
}

/**
 * Where the group is in the front stage, as a UI-ready descriptor.
 * Mirrors the tone vocabulary the student cards already use.
 *
 * STUDENTS DO NOT PICK DATES (2026-09-19): the Focal Person endorses a whole section onto one
 * day, the Coordinator confirms, the Secretary finalises. The live chain only ever writes three
 * statuses here — 'Date Selected' (proposed, still in the chain), 'Scheduled', and 'Cancelled'
 * (the section was withdrawn, or the group was unticked when it was logged). 'Applied' and
 * 'Rejected' come from the retired student-picks-a-date flow and survive only on old rows; they
 * keep their keys so nothing that branches on them breaks, but their copy no longer asks the
 * group to apply or to pick. Only the WORDS below may change — keys and tones are read elsewhere.
 */
function df_stage_meta(?array $booking, string $phase = 'final', bool $phase1Cleared = true): array
{
    $what = df_phase_label($phase);
    $next = $phase === 'proposal'
        ? 'Your Statistics card is now open.'
        : 'Groups are heard in slot order, so come early.';

    if ($booking === null) {
        return $phase1Cleared
            ? ['key' => 'apply', 'label' => 'Awaiting date', 'tone' => 'empty',
               'headline' => 'Waiting for your ' . $what . ' date',
               'blurb' => 'Your requirements are cleared. Your focal person schedules your whole section together — nothing more for you to do.']
            : ['key' => 'apply', 'label' => 'Not scheduled', 'tone' => 'empty',
               'headline' => 'Finish your requirements first',
               'blurb' => 'Once every requirement is approved, your focal person schedules your section\'s ' . $what . '.'];
    }
    switch ($booking['status']) {
        case 'Applied':   // legacy rows only
            if (!$phase1Cleared) {
                return ['key' => 'appreview', 'label' => 'In review', 'tone' => 'review',
                        'headline' => 'Your documents are with the office',
                        'blurb' => 'Once they are approved, your focal person schedules your section.'];
            }
            return ['key' => 'pick', 'label' => 'Awaiting date', 'tone' => 'pending',
                    'headline' => 'Waiting for your ' . $what . ' date',
                    'blurb' => 'Your focal person schedules your whole section together — nothing more for you to do.'];
        case 'Date Selected':
            return ['key' => 'waiting', 'label' => 'Date proposed', 'tone' => 'review',
                    'headline' => 'Your ' . $what . ' date is proposed',
                    'blurb' => 'Your focal person put your section forward for a date and the research office is confirming it. It is not final yet — please do not plan around it until it is approved.'];
        case 'Scheduled':
            $past = !empty($booking['defense_date']) && $booking['defense_date'] < df_today();
            return ['key' => 'scheduled', 'label' => $past ? 'Defense day passed' : 'Scheduled', 'tone' => 'ok',
                    'headline' => $past ? 'Your defense date has passed' : 'Your ' . $what . ' is scheduled',
                    'blurb' => $past
                        ? ($phase === 'proposal'
                            ? 'Keep going in your Statistics card. Ask your adviser for the Minutes of the Proposal Defense — you will upload it in Final Defense.'
                            : 'The research office opens your Plagiarism card once it records the outcome of your defense.')
                        : 'Bring your printed forms on the day. ' . $next];
        case 'Rejected':  // legacy rows only
            return ['key' => 'rejected', 'label' => 'Awaiting new date', 'tone' => 'revision',
                    'headline' => 'That date did not push through',
                    'blurb' => 'The research office could not confirm it. Your focal person will propose another date for your section.'];
        case 'Cancelled':
            return ['key' => 'cancelled', 'label' => 'Awaiting new date', 'tone' => 'revision',
                    'headline' => 'Your defense date was withdrawn',
                    'blurb' => 'Your focal person will propose a new date for your section. Your uploaded files are safe — nothing for you to do.'];
    }
    return ['key' => 'apply', 'label' => 'Not scheduled', 'tone' => 'empty',
            'headline' => 'Waiting for your ' . $what . ' date', 'blurb' => ''];
}

/**
 * Everything components/defense_scheduler.php needs for one phase, in one call.
 *
 * Both module_proposal.php and module_final.php render the same panel, so the booking, the
 * stage descriptor, the whole-window calendar payload and the per-phase application documents
 * are assembled here rather than copied into each module and drifting apart.
 *
 * Returns the $df_* variables the component documents as its contract.
 */
function df_stage_bundle(PDO $pdo, int $effectiveUserId, string $phase): array
{
    $booking = df_get_booking($pdo, $effectiveUserId, $phase);
    $phase1  = df_phase1_cleared($pdo, $effectiveUserId, $phase);
    // Capacity is per school, and a group only ever competes with its own.
    $school  = df_user_school($pdo, $effectiveUserId);

    // Availability for every month the booking window touches, flattened to date => cell. The
    // picker renders straight from this, and defense_handler.php re-derives the same answer
    // from df_unavailable_reason(), so the calendar can never offer a date the server refuses.
    $cal = [];
    $cur = strtotime(df_today() . ' first day of this month');
    $endMonth = strtotime(date('Y-m-01', strtotime(df_window_end())));
    while ($cur <= $endMonth) {
        foreach (df_month_cells($pdo, $phase, (int)date('Y', $cur), (int)date('n', $cur), $effectiveUserId, $school) as $cell) {
            $cal[$cell['date']] = [
                'state'      => $cell['state'],
                'reason'     => $cell['reason'],
                'is_today'   => $cell['is_today'],
                'slots_left' => $cell['slots_left'],
            ];
        }
        $cur = strtotime('+1 month', $cur);
    }

    // The blank each document is filled in from, and whether it is already in. Only documents
    // the OFFICE issues a blank for appear here — items 23-27 are produced by the group itself,
    // so they get no download chip. (They used to point at ../placeholder.pdf, which is a
    // byte-for-byte copy of endorsement.pdf: students downloading a "blank communication letter"
    // were handed an adviser endorsement form.)
    $forms = [
        12 => df_institution_form($pdo, $effectiveUserId, 'endorsement'),   // proposal: adviser endorsement, the group's institution's
        20 => df_application_form($pdo, $effectiveUserId),  // final: Form 002, the group's own institution's
        22 => rd_form_url($pdo, 'mom', $effectiveUserId),               // final: Minutes of the Proposal Defense
    ];
    // Numbered how-to per document — same shape as the requirement cards' $item_steps lists.
    // A one-line prose blurb reads faster but is much harder to follow while actually doing it.
    $steps = [
        12 => ['Download the endorsement form below', 'Have your research adviser sign it',
               'Capture a photo or scan it', 'Upload it using the button below'],
        20 => ['Download the blank Form 002 below', 'Fill it out completely',
               'Have it signed by your research teacher and adviser', 'Capture a photo or scan it',
               'Upload it using the button below'],
        22 => ['Present and defend your proposal first', 'Your teacher writes the minutes during it',
               'Ask your adviser for the copy — they keep it', 'Upload it using the button below'],
        23 => ['Prepare your letter of communication', 'Have it approved and signed',
               'Capture a photo or scan it', 'Upload it using the button below'],
        24 => ['Go through the checklist item by item', 'Fill it out honestly — it is your own check',
               'Capture a photo or scan it', 'Upload it using the button below'],
        25 => ['Assemble Title Page, Preliminary Pages, Chapters 1-5 and References',
               'Save the whole thing as one PDF', 'Upload it using the button below'],
        26 => ['Take the tool you actually used to gather data',
               'Save a clean sample copy as PDF', 'Upload it using the button below'],
        27 => ['Collect the general statistical tables from your data',
               'Save them as one PDF', 'Upload it using the button below'],
    ];
    // One-line answer to "what even is this?", shown above the steps. The Minutes in particular
    // is a document students routinely have not heard of.
    $notes = [
        22 => 'The notes your teacher took during your proposal defense.',
        24 => 'Your own read of whether the paper is ready — not the office\'s.',
        25 => 'The full paper as it stands going into the defense.',
    ];
    // What the PANEL shows. Deliberately df_phase2_items(), not df_application_items(): for Final
    // those differ — Form 002 is reviewed before this panel ever opens.
    $items = df_phase2_items($phase);
    // Proposal collects nothing here any more, and `IN ()` is a syntax error in MySQL — so the
    // whole document-card block is skipped rather than queried for an empty set.
    if (empty($items)) {
        $docs = [];
    } else {
    $placeholders = implode(',', array_fill(0, count($items), '?'));
    // Latest version per item, WITH its verdict. A document the office sent back is not "in" —
    // the group has to replace it — so the status has to travel with the card, not just the fact
    // that something was once uploaded.
    $stmt = $pdo->prepare("SELECT up.upload_id, up.item_id, up.verification_status, up.remarks,
                                  up.file_path, up.original_filename, up.uploaded_at
        FROM uploads up
        JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads
              WHERE user_id = ? AND item_id IN ($placeholders) GROUP BY item_id) latest
          ON latest.max_id = up.upload_id");
    $stmt->execute(array_merge([$effectiveUserId], $items));
    $states = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $states[(int)$r['item_id']] = $r;
    }

    $nameStmt = $pdo->prepare("SELECT item_id, item_name, description FROM checklist_items WHERE item_id IN ($placeholders)");
    $nameStmt->execute($items);
    $names = $descs = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $names[(int)$r['item_id']] = $r['item_name'];
        $descs[(int)$r['item_id']] = $r['description'];
    }

    // Each document is rendered as the same Apple-Wallet .item-card the requirement grid uses, so
    // the card needs everything that markup reads: description, verdict, and the latest file.
    $docs = [];
    foreach ($items as $item) {
        $blank  = $forms[$item] ?? null;
        $st     = $states[$item] ?? null;
        $status = $st['verification_status'] ?? null;
        $docs[] = [
            'item'        => $item,
            'label'       => $names[$item] ?? ('Requirement ' . $item),
            'description' => $descs[$item] ?? '',
            'form'        => ($blank && file_exists($blank)) ? $blank : null,
            'note'        => $notes[$item] ?? null,
            'steps'       => $steps[$item] ?? [],
            // A document the office sent back does NOT count as in: the card has to offer the
            // upload button again, and the date picker has to wait for the replacement.
            'uploaded'    => ($status !== null && $status !== 'Revision Requested'),
            'status'      => $status,
            'remarks'     => $st['remarks'] ?? null,
            'upload_id'   => (int)($st['upload_id'] ?? 0),
            'file_path'   => $st['file_path'] ?? '',
            'filename'    => $st['original_filename'] ?? '',
            'uploaded_at' => $st['uploaded_at'] ?? null,
        ];
    }
    } // end: this phase collects documents in the panel

    $gname = $pdo->prepare("SELECT research_group_name FROM users WHERE user_id = ?");
    $gname->execute([$effectiveUserId]);

    return [
        'booking'    => $booking,
        'stage'      => df_stage_meta($booking, $phase, $phase1),
        'unlocked'   => df_requirements_unlocked($booking),
        // Whether the panel is live or a greyed-out preview. Proposal: five requirements
        // approved. Final: Form 002 approved.
        'phase1'     => $phase1,
        'phase'      => $phase,
        'csrf'       => $_SESSION['csrf_token'] ?? '',
        'cal'        => $cal,
        'soonest'    => df_soonest_open($pdo, $phase, $effectiveUserId, $school),
        'app_docs'   => $docs,
        'group_name' => (string)$gname->fetchColumn(),
        // The group's id, so the panel can read its own schedule without the includer having to
        // hand over a variable the component's contract never documented.
        'uid'        => $effectiveUserId,
    ];
}

/**
 * Writes to the live notification surface. status_type 'info' = the student's own action
 * log; 'success'/'warning' = things that alert them (bell / Action Required).
 * NOTE: the `notifications` table is an unrelated email-draft table — see CLAUDE.md.
 */
function df_log(PDO $pdo, int $effectiveUserId, string $title, string $description, string $type = 'info'): void
{
    $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, ?)")
        ->execute([$effectiveUserId, $title, $description, $type]);
}
