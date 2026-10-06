<?php
// dashboards/focal_lib.php — reads for the Focal Person dashboard and the schedule chain.
//
// Shape of her world, and of the screen:
//     course  ->  section  ->  group
// She handles one or more COURSES (focal_scope). Each course holds SECTIONS (rooms). Each
// section holds research GROUPS. She consolidates the ready groups of one section onto one
// date and endorses that as a batch.
//
// All writes live in focal_handler.php. Nothing here modifies anything.

require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/defense_lib.php';

/** The canonical leader-group predicate, written once. `u` is the users alias. */
const FP_GROUP_PREDICATE = "u.role = 'Student' AND u.leader_id IS NULL
                            AND u.research_group_name IS NOT NULL AND u.research_group_name <> ''";

/**
 * Sections that belong to the courses this focal person handles, each with a live group count.
 * Returned grouped by course so the screen can render one card per course with its sections
 * inside — a focal person who handles a single course sees one card and no extra nesting.
 */
function fp_sections_by_course(PDO $pdo, string $role, int $userId): array
{
    [$scopeSql, $params] = aa_scope_sql($pdo, $role, $userId, 's');

    $rows = $pdo->prepare("
        SELECT s.section_id, s.department, s.program, s.year_level, s.label,
               (SELECT COUNT(*) FROM users u WHERE u.section_id = s.section_id AND " . FP_GROUP_PREDICATE . ") AS group_count
        FROM sections s
        WHERE $scopeSql
        ORDER BY s.program ASC, s.year_level ASC, s.label ASC
    ");
    $rows->execute($params);

    $out = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['department'] . '::' . $r['program']]['department'] = $r['department'];
        $out[$r['department'] . '::' . $r['program']]['program']    = $r['program'];
        $out[$r['department'] . '::' . $r['program']]['sections'][] = $r;
    }

    // A course with no sections yet must still appear, or she has no way to make the first one.
    foreach (aa_focal_scope($pdo, $userId) as $c) {
        $k = $c['department'] . '::' . $c['program'];
        if (!isset($out[$k])) {
            $out[$k] = ['department' => $c['department'], 'program' => $c['program'], 'sections' => []];
        }
    }
    ksort($out);
    return $out;
}

/** Group leaders sitting in one section. */
function fp_groups_in_section(PDO $pdo, int $sectionId): array
{
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.research_group_name, u.research_title,
               u.department, u.program, u.year_level, u.student_number
        FROM users u
        WHERE u.section_id = ? AND " . FP_GROUP_PREDICATE . "
        ORDER BY u.research_group_name ASC
    ");
    $stmt->execute([$sectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Groups in her courses that have no section yet. This is the backfill tray: every group that
 * existed before sections were introduced lands here, and so does every transferee and late
 * registrant afterwards. One tray, and it never needs a migration script.
 */
function fp_unassigned_groups(PDO $pdo, string $role, int $userId): array
{
    [$scopeSql, $params] = aa_scope_sql($pdo, $role, $userId, 'u');
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.research_group_name, u.department, u.program, u.year_level
        FROM users u
        WHERE u.section_id IS NULL AND " . FP_GROUP_PREDICATE . " AND $scopeSql
        ORDER BY u.program ASC, u.research_group_name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What a group must have cleared before it can be put on a defense date — six for proposal
 * (including the signed endorsement, item 12), Form 002 for final.
 *
 * This is just df_phase1_items() now. It was briefly a separate list, back when item 12 was an
 * "application" document uploaded in the schedule panel: adding it to df_phase1_items() would
 * have gated the very panel that collected it. Item 12 is an ordinary requirement in the grid
 * today, so there is nothing left to keep apart.
 */
function fp_ready_items(string $phase): array
{
    return df_phase1_items($phase);
}

/** How far each group has got towards being schedulable, keyed by leader id. */
function fp_readiness(PDO $pdo, array $leaderIds, string $phase): array
{
    $items = fp_ready_items($phase);
    $total = count($items);
    $out   = [];
    foreach ($leaderIds as $id) {
        $out[(int)$id] = ['done' => 0, 'total' => $total, 'ready' => false];
    }
    if (empty($leaderIds)) return $out;

    $idPh   = implode(',', array_fill(0, count($leaderIds), '?'));
    $itemPh = implode(',', array_fill(0, $total, '?'));

    // Latest upload per (user, item) — the same "newest row wins" rule every progress
    // calculation in this project uses.
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.item_id, u.verification_status
        FROM uploads u
        INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads
                    WHERE user_id IN ($idPh) AND item_id IN ($itemPh)
                    GROUP BY user_id, item_id) l
                ON l.user_id = u.user_id AND l.item_id = u.item_id AND l.m = u.uploaded_at
    ");
    $stmt->execute(array_merge($leaderIds, $items));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['verification_status'] === 'Approved') {
            $out[(int)$r['user_id']]['done']++;
        }
    }
    foreach ($out as $id => $v) {
        $out[$id]['ready'] = $v['done'] >= $total;
    }
    return $out;
}

/** Batches are live until dropped; Returned still counts (its date is still held). */
const FP_LIVE_BATCH_STATUSES = ['Endorsed', 'Confirmed', 'Scheduled', 'Returned'];

/**
 * Statuses that stop a section being endorsed again. Deliberately EXCLUDES 'Scheduled': a
 * section is scheduled in rounds — whoever is ready goes on the next available day, and the
 * groups still finishing their requirements follow later. Blocking on a published batch would
 * strand every group that was not ready the first time. What must never happen is two requests
 * for the same section in the chain at once, which is what these three cover.
 */
const FP_PENDING_BATCH_STATUSES = ['Endorsed', 'Confirmed', 'Returned'];

/**
 * The live batch for one section+phase, or null. A section holds at most one at a time.
 *
 * Batches with NO bookings left are ignored. A batch is only meaningful as a set of groups on
 * a day; an empty one is a leftover, and because a live batch blocks the section from being
 * endorsed again, treating it as live would wedge that section permanently.
 */
function fp_section_batch(PDO $pdo, int $sectionId, string $phase): ?array
{
    $ph = implode(',', array_fill(0, count(FP_LIVE_BATCH_STATUSES), '?'));
    $stmt = $pdo->prepare("
        SELECT b.*, s.label, s.program, s.department, s.year_level
        FROM defense_batches b
        JOIN sections s ON s.section_id = b.section_id
        WHERE b.section_id = ? AND b.phase = ? AND b.status IN ($ph)
          AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
        ORDER BY b.batch_id DESC LIMIT 1
    ");
    $stmt->execute(array_merge([$sectionId, $phase], FP_LIVE_BATCH_STATUSES));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The batch that stops this section being endorsed again, or null. Only one request may be in
 * the chain at a time; a published one does not block the next round (see
 * FP_PENDING_BATCH_STATUSES).
 */
function fp_pending_batch(PDO $pdo, int $sectionId, string $phase): ?array
{
    $ph = implode(',', array_fill(0, count(FP_PENDING_BATCH_STATUSES), '?'));
    $stmt = $pdo->prepare("
        SELECT b.*, s.label, s.program, s.department, s.year_level
        FROM defense_batches b
        JOIN sections s ON s.section_id = b.section_id
        WHERE b.section_id = ? AND b.phase = ? AND b.status IN ($ph)
          AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
        ORDER BY b.batch_id DESC LIMIT 1
    ");
    $stmt->execute(array_merge([$sectionId, $phase], FP_PENDING_BATCH_STATUSES));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The groups placed in a batch, with their slot numbers, in slot order. */
function fp_batch_groups(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare("
        SELECT b.booking_id, b.user_id, b.slot_no, b.status,
               u.username, u.research_group_name, u.research_title
        FROM defense_bookings b
        JOIN users u ON u.user_id = b.user_id
        WHERE b.batch_id = ?
        ORDER BY b.slot_no ASC, b.booking_id ASC
    ");
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Batches waiting on a given step of the chain — the Coordinator's and Secretary's queues.
 *   Coordinator sees status 'Endorsed'  (focal has signed)
 *   Secretary   sees status 'Confirmed' (coordinator has signed)
 */
function fp_chain_queue(PDO $pdo, string $status, ?string $phase = null, ?string $school = null): array
{
    // Empty batches are left out for the same reason fp_section_batch() ignores them: a bundle
    // with no groups in it is a leftover, and showing "0 groups" in a queue is only noise.
    $stmt = $pdo->prepare("
        SELECT b.*, s.label, s.program, s.department, s.year_level,
               COALESCE(f.username, IF(b.focal_by IS NULL, NULL, 'Removed account')) AS focal_name,
               COALESCE(c.username, IF(b.coord_by IS NULL, NULL, 'Removed account')) AS coord_name,
               COALESCE(sc.username, IF(b.sec_by IS NULL, NULL, 'Removed account')) AS sec_name,
               (SELECT COUNT(*) FROM defense_bookings db WHERE db.batch_id = b.batch_id) AS group_count,
               UNIX_TIMESTAMP(b.sec_at)      AS sec_ts,
               (DATE(b.sec_at) = CURDATE())  AS sec_today,
               -- True epochs, computed by the DATABASE. PHP runs Europe/Berlin here while the
               -- MySQL clock is six hours ahead, so strtotime() on a DATETIME from this table
               -- lands in the future and every waiting time reads as nothing at all.
               UNIX_TIMESTAMP(b.focal_at)    AS focal_ts,
               UNIX_TIMESTAMP(b.coord_at)    AS coord_ts,
               UNIX_TIMESTAMP(b.returned_at) AS returned_ts
        FROM defense_batches b
        JOIN sections s      ON s.section_id = b.section_id
        LEFT JOIN users f    ON f.user_id = b.focal_by
        LEFT JOIN users c    ON c.user_id = b.coord_by
        LEFT JOIN users sc   ON sc.user_id = b.sec_by
        WHERE b.status = ?
          AND (? IS NULL OR b.phase = ?)
          AND (? IS NULL OR s.department = ?)
          AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
        ORDER BY b.defense_date ASC, b.batch_id ASC
    ");
    $stmt->execute([$status, $phase, $phase, $school, $school]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What one date already costs, broken down by COURSE.
 *
 * The 15 slots of a day are one pool shared by the whole school, but a Focal Person must not
 * see another course's students. So she gets course names and counts and nothing below that —
 * enough to plan around a busy day, nothing that leaks a roster.
 */
function fp_date_load(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare("
        SELECT u.program, u.department, COUNT(*) AS n
        FROM defense_bookings b
        JOIN users u ON u.user_id = b.user_id
        WHERE b.defense_date = ? AND b.status IN ('Date Selected','Scheduled')
        GROUP BY u.department, u.program
        ORDER BY n DESC, u.program ASC
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Slots already spoken for on a date, across every course and both phases. */
function fp_slots_taken(PDO $pdo, string $date): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings
                           WHERE defense_date = ? AND status IN ('Date Selected','Scheduled')");
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn();
}

/**
 * Where a student's own schedule stands, for the student panel.
 *
 * Students no longer pick dates — their Focal Person does — so all they need here is an honest
 * status. A date that is still travelling the chain is shown as PROPOSED, never as a booking:
 * it is not real until the Secretary signs, and letting a group plan around an unsigned date is
 * exactly the mistake worth avoiding.
 *
 * Returns null when nothing has been proposed yet.
 */
function fp_student_schedule(PDO $pdo, int $effectiveUserId, string $phase): ?array
{
    $stmt = $pdo->prepare("
        SELECT b.defense_date, b.slot_no, b.status AS booking_status,
               ba.status AS chain_status, ba.remarks, s.label AS section_label
        FROM defense_bookings b
        LEFT JOIN defense_batches ba ON ba.batch_id = b.batch_id
        LEFT JOIN sections s         ON s.section_id = ba.section_id
        WHERE b.user_id = ? AND b.phase = ? AND b.status IN ('Date Selected','Scheduled')
        ORDER BY b.booking_id DESC LIMIT 1
    ");
    $stmt->execute([$effectiveUserId, $phase]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $r['confirmed'] = $r['booking_status'] === 'Scheduled';
    return $r;
}

/**
 * The requirements a Focal Person reviews for a phase, in the order the office reads them.
 *   proposal — the seven that must clear before a defense date can be set.
 *   final    — Form 002 (which gates the date) and the six that follow it.
 * Deliberately excludes statistics, plagiarism and payment items: other desks own those.
 */
function fp_review_items(string $phase): array
{
    // Same order the student's grid uses: the defense application (17) leads, then the two
    // adviser documents (11, 12), then the capsule with its three bundled items folded
    // underneath it. 17 is the proposal's OWN Form 002 row — item 20 is Final's.
    return $phase === 'proposal' ? [17, 11, 12, 14, 13, 15, 16] : [20, 22, 23, 24, 25, 26, 27];
}

/**
 * Requirements that are cleared BY another one rather than on their own.
 *
 * Approving item 14 (Complete Capsule Proposal) auto-approves 13, 15 and 16 — the review sheet
 * inside Form 008 covers them. They are shown nested under 14 with no buttons of their own,
 * because offering an Approve that the cascade will press anyway is just a way to be confusing.
 */
const FP_BUNDLED_UNDER = [14 => [13, 15, 16]];

/** The parent requirement a bundled item hangs off, or null when it stands alone. */
function fp_bundle_parent(int $itemId): ?int
{
    foreach (FP_BUNDLED_UNDER as $parent => $kids) {
        if (in_array($itemId, $kids, true)) return $parent;
    }
    return null;
}

/** Display names for checklist items, keyed by id. */
function fp_item_names(PDO $pdo, array $itemIds): array
{
    if (empty($itemIds)) return [];
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT item_id, COALESCE(NULLIF(item_name,''), requirement_name) AS label
                           FROM checklist_items WHERE item_id IN ($ph)");
    $stmt->execute($itemIds);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * One group's requirement documents for a phase — the newest upload per item, which is the
 * one under review (older versions stay as history, same rule the rest of the project uses).
 * Items with nothing submitted still come back, so the reviewer sees the gap.
 */
function fp_group_documents(PDO $pdo, int $leaderId, string $phase): array
{
    $items = fp_review_items($phase);
    $names = fp_item_names($pdo, $items);
    $ph    = implode(',', array_fill(0, count($items), '?'));

    $stmt = $pdo->prepare("
        SELECT u.upload_id, u.item_id, u.original_filename, u.file_path,
               u.verification_status, u.remarks, u.uploaded_at,
               u.verified_at, v.username AS verified_name, v.role AS verified_role
        FROM uploads u
        LEFT JOIN users v ON v.user_id = u.verified_by
        INNER JOIN (SELECT item_id, MAX(uploaded_at) AS m FROM uploads
                    WHERE user_id = ? AND item_id IN ($ph) GROUP BY item_id) l
                ON l.item_id = u.item_id AND l.m = u.uploaded_at
        WHERE u.user_id = ? AND u.item_id IN ($ph)
    ");
    $stmt->execute(array_merge([$leaderId], $items, [$leaderId], $items));

    $byItem = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byItem[(int)$r['item_id']] = $r;
    }

    // Build flat, then fold the bundled ones in under their parent so the screen can show
    // "approving this also clears these three" as an actual nesting rather than a sentence.
    $flat = [];
    foreach ($items as $id) {
        $flat[$id] = [
            'item_id'  => $id,
            'label'    => $names[$id] ?? ('Requirement ' . $id),
            'upload'   => $byItem[$id] ?? null,
            'status'   => $byItem[$id]['verification_status'] ?? null,
            'bundled'  => [],
        ];
    }

    $out = [];
    foreach ($flat as $id => $row) {
        if (fp_bundle_parent($id) !== null) continue;          // placed under its parent below
        foreach (FP_BUNDLED_UNDER[$id] ?? [] as $kid) {
            if (isset($flat[$kid])) $row['bundled'][] = $flat[$kid];
        }
        $out[] = $row;
    }
    return $out;
}

/** How many of a group's documents are sitting unreviewed — the "needs you" count. */
function fp_pending_count(PDO $pdo, array $leaderIds, string $phase): array
{
    $out = array_fill_keys(array_map('intval', $leaderIds), 0);
    if (empty($leaderIds)) return $out;

    $items = fp_review_items($phase);
    $idPh   = implode(',', array_fill(0, count($leaderIds), '?'));
    $itemPh = implode(',', array_fill(0, count($items), '?'));

    // 'Under Review' is legacy but still actionable (see CLAUDE.md), so it counts as pending.
    $stmt = $pdo->prepare("
        SELECT u.user_id, COUNT(*) AS n
        FROM uploads u
        INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads
                    WHERE user_id IN ($idPh) AND item_id IN ($itemPh)
                    GROUP BY user_id, item_id) l
                ON l.user_id = u.user_id AND l.item_id = u.item_id AND l.m = u.uploaded_at
        WHERE u.verification_status IN ('Pending','Under Review')
        GROUP BY u.user_id
    ");
    $stmt->execute(array_merge($leaderIds, $items));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['user_id']] = (int)$r['n'];
    }
    return $out;
}

/**
 * One batch with its section, course and the people who have signed it so far.
 *
 * focal_by / coord_by / sec_by / returned_by carry no foreign key, so a signer whose staff
 * account is later deleted leaves the id behind and the LEFT JOIN comes back empty. The
 * COALESCE keeps that honest: the step still reads as signed, by 'Removed account', rather
 * than rendering a blank name that looks like the signature never happened.
 */
function fp_get_batch(PDO $pdo, int $batchId): ?array
{
    $stmt = $pdo->prepare("
        SELECT b.*, s.section_id, s.label, s.program, s.department, s.year_level,
               COALESCE(f.username, IF(b.focal_by IS NULL, NULL, 'Removed account')) AS focal_name,
               COALESCE(c.username, IF(b.coord_by IS NULL, NULL, 'Removed account')) AS coord_name,
               COALESCE(x.username, IF(b.sec_by IS NULL, NULL, 'Removed account')) AS sec_name,
               COALESCE(r.username, IF(b.returned_by IS NULL, NULL, 'Removed account')) AS returned_name,
               -- Display dates formatted by MySQL: PHP runs Europe/Berlin, six hours behind, and
               -- would put a 1 AM Manila signature on the previous day.
               DATE_FORMAT(b.focal_at, '%b %e')    AS focal_md,
               DATE_FORMAT(b.coord_at, '%b %e')    AS coord_md,
               DATE_FORMAT(b.sec_at, '%b %e')      AS sec_md,
               DATE_FORMAT(b.returned_at, '%b %e') AS returned_md
        FROM defense_batches b
        JOIN sections s        ON s.section_id = b.section_id
        LEFT JOIN users f      ON f.user_id = b.focal_by
        LEFT JOIN users c      ON c.user_id = b.coord_by
        LEFT JOIN users x      ON x.user_id = b.sec_by
        LEFT JOIN users r      ON r.user_id = b.returned_by
        WHERE b.batch_id = ?
    ");
    $stmt->execute([$batchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Display name for a section. Offices name the rooms just "A" / "B" and let the year tell them
 * apart, so "A" alone is ambiguous on any screen that shows more than one year at a time.
 */
function fp_section_name(array $s): string
{
    $year = trim((string)($s['year_level'] ?? ''));
    return $year !== '' ? $year . ' &middot; ' . $s['label'] : (string)$s['label'];
}

/** Human label for a chain status, from the point of view of whoever is reading it. */
function fp_status_label(string $status): string
{
    return [
        'Endorsed'  => 'Waiting for the Coordinator',
        'Confirmed' => 'Waiting for the Secretary',
        'Scheduled' => 'Confirmed',
        'Returned'  => 'Sent back to the Focal Person',
        'Cancelled' => 'Cancelled',
    ][$status] ?? $status;
}

/**
 * How many schedule bundles are waiting on THIS person right now — the Approvals nav badge.
 *
 * Counts only the steps this role actually owns, so a Coordinator is not nagged about batches
 * sitting with the Secretary. A Director holds both steps (she acts as Secretary), so she sees
 * the sum. Empty batches are excluded for the same reason fp_chain_queue() skips them.
 */
function fp_approvals_badge(PDO $pdo, string $role, ?string $phase = null, ?string $school = null): int
{
    $statuses = array_values(array_filter(
        ['Endorsed', 'Confirmed'],
        fn($s) => fp_can_act($role, $s)
    ));
    if (empty($statuses)) return 0;

    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM defense_batches b
                           JOIN sections s ON s.section_id = b.section_id
                           WHERE b.status IN ($ph)
                             AND (? IS NULL OR b.phase = ?)
                             AND (? IS NULL OR s.department = ?)
                             AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)");
    $stmt->execute(array_merge($statuses, [$phase, $phase, $school, $school]));
    return (int)$stmt->fetchColumn();
}

/**
 * The Schedule nav badge: sections waiting to be LOGGED by this viewer.
 *
 * It used to count defense_bookings in 'Date Selected' — but a booking takes that status the
 * moment the Focal Person endorses, so the Director and Secretary were badged while the section
 * still sat on the Coordinator's desk. What the Schedule screen actually asks of anyone is the
 * 'Confirmed' step (admin_defense_schedule.php, "To log"), so that is the count, and only for
 * a role that can sign it. The Coordinator cannot, so hers is 0 — the waiting cards on the
 * queue screens still show her (and everyone) where each section is.
 */
function fp_schedule_badge(PDO $pdo, string $role, string $phase, ?string $school = null): int
{
    if (!fp_can_act($role, 'Confirmed')) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM defense_batches b
                           JOIN sections s ON s.section_id = b.section_id
                           WHERE b.status = 'Confirmed' AND b.phase = ?
                             AND (? IS NULL OR s.department = ?)
                             AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)");
    $stmt->execute([$phase, $school, $school]);
    return (int)$stmt->fetchColumn();
}

/**
 * Does this staff member own the current step of a batch?
 *
 * ONE STEP EACH. The Director used to hold both, being the stand-in Secretary — which produced
 * a button reading "Confirm — send to the Secretary" on her own screen, i.e. send it to herself.
 * Coordinator confirms; Director, as Secretary, publishes. This is the one place Coordinator and
 * Director are NOT interchangeable, and deliberately so: a two-signature chain with one person
 * holding both signatures is a one-signature chain wearing a hat.
 */
function fp_can_act(string $role, string $status): bool
{
    if ($status === 'Endorsed')  return $role === 'Research Coordinator';
    // The Secretary's account does not exist yet; the Director acts as her (see CLAUDE.md).
    if ($status === 'Confirmed') return $role === 'Research Director';
    return false;
}

/**
 * The school a viewer's chain queues are limited to, or null for no limit.
 *
 * MCNP and ISAP have a coordinator each, so a Coordinator only ever sees her own institution's
 * sections. The Director and the Secretary sit above both and see everything. Kept here so no
 * caller has to remember which roles are school-bound.
 */
function fp_school_scope(PDO $pdo, string $role, int $userId): ?string
{
    if ($role !== 'Research Coordinator') {
        return null;
    }
    // '' would mean "no school set", and passing null there would silently widen her to every
    // school. A sentinel nobody matches keeps it failing closed until someone sets one.
    $school = aa_user_school($pdo, $userId);
    return $school !== '' ? $school : "\0none";
}

/** Whose move a batch is on right now, for the "locked, not yours" cards. */
function fp_step_owner(string $status): string
{
    return [
        'Endorsed'  => 'Coordinator',
        'Confirmed' => 'Secretary',
        'Returned'  => 'Focal Person',
        'Scheduled' => 'nobody — it is published',
        'Cancelled' => 'nobody — it was withdrawn',
    ][$status] ?? 'nobody';
}

/**
 * Every batch this Focal Person has put into the chain, newest first.
 *
 * The answer to "what happened to the dates I sent?" — which previously could only be found by
 * opening each section in turn and reading the panel inside. One row per request, carrying the
 * three chain timestamps so the caller can draw the whole journey without a second query.
 *
 * Cancelled batches are left out: she withdrew them herself, so they are not news. Empty ones
 * are skipped for the same reason everywhere else does — a bundle with no groups is a leftover.
 */
function fp_my_batches(PDO $pdo, int $focalId, ?string $phase = null): array
{
    $stmt = $pdo->prepare("
        SELECT b.*, s.label, s.program, s.department, s.year_level,
               (SELECT COUNT(*) FROM defense_bookings db WHERE db.batch_id = b.batch_id) AS group_count,
               COALESCE(b.sec_at, b.returned_at, b.coord_at, b.focal_at) AS last_at,
               -- Epochs from MySQL: PHP runs Europe/Berlin, and strtotime() on a stored DATETIME
               -- put every event six hours in the future, so the feed read just now for a day.
               UNIX_TIMESTAMP(b.returned_at) AS returned_ts, UNIX_TIMESTAMP(b.sec_at) AS sec_ts,
               UNIX_TIMESTAMP(b.coord_at) AS coord_ts
        FROM defense_batches b
        JOIN sections s ON s.section_id = b.section_id
        WHERE b.focal_by = ?
          AND b.status <> 'Cancelled'
          AND (? IS NULL OR b.phase = ?)
          AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
        ORDER BY last_at DESC, b.batch_id DESC
    ");
    $stmt->execute([$focalId, $phase, $phase]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What she would want to be told about, newest first — the notification drawer.
 *
 * DERIVED, not stored. Every event here is a timestamp that already exists on defense_batches
 * or uploads, so the feed cannot drift from the truth it reports and no write path has to
 * remember to log. The cost is that it cannot record anything those tables do not already know.
 *
 * Each row: ['at' => unix, 'kind' => success|warning|info, 'title', 'body', 'href'].
 * 'title' and 'body' are HTML — fp_section_name() emits an entity, so anything drawn from
 * user input (group names, a coordinator's send-back remark) is escaped HERE, at the point
 * it is mixed in, and the drawer prints them raw.
 */
function fp_feed(PDO $pdo, int $focalId, string $role, int $limit = 20): array
{
    $out = [];

    // ---- the chain answering back on dates she endorsed
    foreach (fp_my_batches($pdo, $focalId) as $b) {
        $where = fp_section_name($b) . ' &middot; ' . htmlspecialchars((string)$b['program']);
        $place = htmlspecialchars(fp_short_section($b)) . ' &middot; ' . ucfirst((string)$b['phase']) . ' defense';
        $when  = date('M j', strtotime((string)$b['defense_date']));
        $href  = 'focal_desk.php?view=schedule&phase=' . urlencode((string)$b['phase'])
               . '&course=' . urlencode($b['department'] . '::' . $b['program'])
               . '&section=' . (int)$b['section_id'];

        if (!empty($b['returned_at'])) {
            $out[] = ['at' => (int)$b['returned_ts'], 'kind' => 'warning',
                      'title' => 'Sent back — ' . $where,
                      'body'  => trim((string)$b['remarks']) !== ''
                                 ? htmlspecialchars((string)$b['remarks'])
                                 : 'The ' . $when . ' date needs another look.',
                      'href'  => $href, 'type' => 'back', 'head' => 'Date sent back', 'where' => $place];
        }
        if (!empty($b['sec_at'])) {
            $out[] = ['at' => (int)$b['sec_ts'], 'kind' => 'success',
                      'title' => 'Date published — ' . $where,
                      'body'  => $when . ' is final. The ' . (int)$b['group_count']
                                 . ' group' . ((int)$b['group_count'] === 1 ? '' : 's') . ' can see it now.',
                      'href'  => $href, 'type' => 'published', 'head' => 'Date published', 'where' => $place];
        } elseif (!empty($b['coord_at'])) {
            // Only while it is still the newest thing to happen — once the Secretary signs,
            // "the Coordinator confirmed it" is history, not news.
            $out[] = ['at' => (int)$b['coord_ts'], 'kind' => 'info',
                      'title' => 'Coordinator confirmed — ' . $where,
                      'body'  => $when . ' now sits with the Secretary to be published.',
                      'href'  => $href, 'type' => 'confirmed', 'head' => 'Coordinator confirmed', 'where' => $place];
        }
    }

    // ---- files that have arrived in her courses and nobody has decided yet
    //
    // Only the NEWEST upload per (group, requirement) counts, the same rule fp_pending_count()
    // uses: a group that re-sent a rejected file has one thing waiting, not two. Uploads are
    // stored against the group LEADER, so the leader-group predicate is the right join.
    $items  = array_values(array_unique(array_merge(fp_review_items('proposal'), fp_review_items('final'))));
    $itemPh = implode(',', array_fill(0, count($items), '?'));
    [$scopeSql, $scopeParams] = aa_scope_sql($pdo, $role, $focalId, 'u');

    $stmt = $pdo->prepare("
        SELECT up.upload_id, up.uploaded_at, UNIX_TIMESTAMP(up.uploaded_at) AS up_ts, up.item_id, ci.item_name,
               u.research_group_name, u.program, u.department, u.section_id
        FROM uploads up
        INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS m FROM uploads
                    WHERE item_id IN ($itemPh) GROUP BY user_id, item_id) l
                ON l.user_id = up.user_id AND l.item_id = up.item_id AND l.m = up.uploaded_at
        JOIN users u                 ON u.user_id  = up.user_id
        LEFT JOIN checklist_items ci ON ci.item_id = up.item_id
        WHERE up.verification_status IN ('Pending','Under Review')
          AND " . FP_GROUP_PREDICATE . "
          AND $scopeSql
        ORDER BY up.uploaded_at DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute(array_merge($items, $scopeParams));

    $finalItems = fp_review_items('final');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $href = 'focal_desk.php?view=requirements'
              . '&phase=' . (in_array((int)$r['item_id'], $finalItems, true) ? 'final' : 'proposal')
              . '&course=' . urlencode($r['department'] . '::' . $r['program'])
              . ($r['section_id'] ? '&section=' . (int)$r['section_id'] : '');
        $out[] = ['at' => (int)$r['up_ts'], 'kind' => 'info',
                  'title' => 'New file — ' . htmlspecialchars((string)$r['research_group_name']),
                  'body'  => htmlspecialchars((string)($r['item_name'] ?: 'A requirement')) . ' is waiting for your decision.',
                  'href'  => $href, 'type' => 'file', 'head' => 'New file',
                  'where' => htmlspecialchars((string)$r['research_group_name']) . ' &middot; ' . htmlspecialchars((string)$r['program'])];
    }

    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return array_slice($out, 0, $limit);
}

/**
 * When each group last sent anything in for a phase, keyed by leader id.
 *
 * "When did they last submit?" was only answerable by opening a group and reading the dates off
 * its documents one at a time. One query for the whole section instead of one per card.
 */
function fp_last_submitted(PDO $pdo, array $leaderIds, string $phase): array
{
    $leaderIds = array_values(array_unique(array_filter(array_map('intval', $leaderIds))));
    if (empty($leaderIds)) return [];

    $items  = fp_review_items($phase);
    $idPh   = implode(',', array_fill(0, count($leaderIds), '?'));
    $itemPh = implode(',', array_fill(0, count($items), '?'));

    $stmt = $pdo->prepare("SELECT user_id, MAX(uploaded_at) AS last_at
                           FROM uploads
                           WHERE user_id IN ($idPh) AND item_id IN ($itemPh)
                           GROUP BY user_id");
    $stmt->execute(array_merge($leaderIds, $items));

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['user_id']] = $r['last_at'];
    }
    return $out;
}

/**
 * A timestamp as something readable at a glance: "2h ago", "3d ago", then a plain date.
 * Recent submissions are the ones she is chasing, so those get the relative form.
 */
function fp_when(?string $ts): string
{
    if (!$ts) return '';
    $t = strtotime($ts);
    $d = time() - $t;
    if ($d < 3600)   return max(1, intdiv($d, 60)) . 'm ago';
    if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('M j, Y', $t);
}

/* ============================================================================================
 * THE FOCAL PERSON'S SHELL (2026-10-01) — her home, hubs and badges read these.
 * ========================================================================================== */

/**
 * Which of these groups already hold a booking for the phase, as a set keyed by leader id.
 *
 * The same rule endorse_batch() refuses on (Applied / Date Selected / Scheduled), so no screen
 * offers a group the handler would turn away. Looking only at the section's NEWEST batch missed
 * a group published in an earlier round and counted it as still needing a day.
 */
function fp_booked(PDO $pdo, array $leaderIds, string $phase): array
{
    $leaderIds = array_values(array_unique(array_filter(array_map('intval', $leaderIds))));
    if (empty($leaderIds)) return [];
    $ph = implode(',', array_fill(0, count($leaderIds), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM defense_bookings
                           WHERE user_id IN ($ph) AND phase = ? AND status IN ('Applied','Date Selected','Scheduled')");
    $stmt->execute(array_merge($leaderIds, [$phase]));
    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** "BS Information Technology" + "4th Year" + "A" -> "BSIT 4-A": a section, short enough for a pill. */
function fp_short_section(array $s): string
{
    $words = preg_split('/\s+/', trim((string)($s['program'] ?? ''))) ?: [];
    $abbr  = '';
    foreach ($words as $i => $w) {
        if ($i === 0 && preg_match('/^[A-Z]{2,4}$/', $w)) { $abbr .= $w; continue; }   // "BS", "AB", "BSED"
        if (in_array(strtolower($w), ['of', 'and', 'in', 'the', '&'], true)) continue;
        $abbr .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    $yr = preg_match('/^(\d)/', (string)($s['year_level'] ?? ''), $m) ? $m[1] . '-' : '';
    return trim($abbr . ' ' . $yr . $s['label']);
}

/** The board's columns, in the order a section travels. */
function fp_board_cols(): array
{
    return ['Requirements', 'Needs a date', 'Coordinator', 'Secretary', 'Published'];
}

/**
 * Every section she handles that holds groups, for ONE phase, placed on the board.
 *
 * One row per section: where it stands (col), whose move it is (tone: you / them / back / done),
 * a sentence saying so (do), and the desk URL that opens it. Built in a fixed handful of queries
 * for all her sections at once — the old home ran four queries per section, per page load.
 *
 * Priority mirrors the screens: a date in the chain (sent back / with the Coordinator / with the
 * Secretary) wins; then groups ready and unbooked ("needs a date"); then a published date; and
 * otherwise the section is still collecting requirements — her move if files are waiting.
 */
function fp_board(PDO $pdo, string $role, int $me, string $phase): array
{
    $secs = [];
    foreach (fp_sections_by_course($pdo, $role, $me) as $key => $c) {
        foreach ($c['sections'] as $s) {
            if ((int)$s['group_count'] < 1) continue;          // an empty room is registration plumbing
            $s['course_key'] = $key;
            $secs[(int)$s['section_id']] = $s;
        }
    }
    if (!$secs) return [];

    $sph = implode(',', array_fill(0, count($secs), '?'));
    $g = $pdo->prepare("SELECT u.user_id, u.section_id FROM users u WHERE u.section_id IN ($sph) AND " . FP_GROUP_PREDICATE);
    $g->execute(array_keys($secs));
    $bySec = [];
    $all   = [];
    foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bySec[(int)$r['section_id']][] = (int)$r['user_id'];
        $all[] = (int)$r['user_id'];
    }
    $ready  = fp_readiness($pdo, $all, $phase);
    $pend   = fp_pending_count($pdo, $all, $phase);
    $booked = fp_booked($pdo, $all, $phase);

    // Newest live batch per section, and newest one still in the chain — the same two answers
    // fp_section_batch() and fp_pending_batch() give, for every section in one query.
    $lph = implode(',', array_fill(0, count(FP_LIVE_BATCH_STATUSES), '?'));
    $b = $pdo->prepare("SELECT b.batch_id, b.section_id, b.status, b.remarks, DATE_FORMAT(b.defense_date, '%b %e') AS md,
                               (b.defense_date < ?) AS past
                        FROM defense_batches b
                        WHERE b.section_id IN ($sph) AND b.phase = ? AND b.status IN ($lph)
                          AND EXISTS (SELECT 1 FROM defense_bookings db WHERE db.batch_id = b.batch_id)
                        ORDER BY b.batch_id DESC");
    $b->execute(array_merge([df_today()], array_keys($secs), [$phase], FP_LIVE_BATCH_STATUSES));
    $latest  = [];
    $inChain = [];
    foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (int)$r['section_id'];
        if (!isset($latest[$k])) $latest[$k] = $r;
        if (!isset($inChain[$k]) && in_array($r['status'], FP_PENDING_BATCH_STATUSES, true)) $inChain[$k] = $r;
    }

    $grp = fn(int $n) => $n . ' group' . ($n === 1 ? '' : 's');
    $out = [];
    foreach ($secs as $sid => $s) {
        $ids    = $bySec[$sid] ?? [];
        $nReady = 0; $nPlace = 0; $nPend = 0; $done = 0; $total = 0;
        foreach ($ids as $u) {
            $nPend += (int)($pend[$u] ?? 0);
            $done  += (int)($ready[$u]['done'] ?? 0);
            $total += (int)($ready[$u]['total'] ?? 0);
            if (!empty($ready[$u]['ready'])) { $nReady++; if (!isset($booked[$u])) $nPlace++; }
        }
        $ch = $inChain[$sid] ?? null;
        $lb = $latest[$sid] ?? null;
        if ($ch && $ch['status'] === 'Returned') {
            $why = trim((string)$ch['remarks']);
            [$col, $tone, $do] = [1, 'back', 'Sent back' . ($why !== '' ? ': ' . $why : ' — the ' . $ch['md'] . ' date needs another look')];
        } elseif ($ch && $ch['status'] === 'Endorsed') {
            [$col, $tone, $do] = [2, 'them', $ch['md'] . ' — with the Coordinator'];
        } elseif ($ch && $ch['status'] === 'Confirmed') {
            [$col, $tone, $do] = [3, 'them', $ch['md'] . ' — with the Secretary'];
        } elseif ($nPlace > 0) {
            [$col, $tone, $do] = [1, 'you', $grp($nPlace) . ' ready for a date'];
        } elseif ($lb && $lb['status'] === 'Scheduled') {
            // Rooms are scheduled in rounds: say who is still to come, or green reads as finished.
            $left = count(array_filter($ids, fn($u) => !isset($booked[$u])));
            [$col, $tone, $do] = [4, 'done', ($lb['past'] ? 'Held on ' : 'Published for ') . $lb['md'] . ($left ? ' · ' . $grp($left) . ' still to go' : '')];
        } else {
            [$col, $tone, $do] = [0, $nPend > 0 ? 'you' : 'them',
                                  $nPend > 0 ? $nPend . ' file' . ($nPend === 1 ? '' : 's') . ' to review' : 'Waiting on the groups'];
        }
        $out[] = $s + [
            'short'   => fp_short_section($s),
            'groups'  => count($ids),
            'ready'   => $nReady,
            'place'   => $nPlace,
            'pending' => $nPend,
            'done'    => $done, 'total' => $total,
            'pct'     => $total > 0 ? (int)round($done / $total * 100) : 0,
            'col'     => $col, 'tone' => $tone, 'do' => $do,
            'href'    => 'focal_desk.php?' . http_build_query([
                'view' => $col === 0 ? 'requirements' : 'schedule', 'phase' => $phase,
                'course' => $s['course_key'], 'section' => $sid]),
        ];
    }
    // Inside a column: her move first, then sent back, then everyone else's, then finished.
    $rank = ['you' => 0, 'back' => 1, 'them' => 2, 'done' => 3];
    usort($out, fn($a, $b) => [$a['col'], $rank[$a['tone']], $a['short']] <=> [$b['col'], $rank[$b['tone']], $b['short']]);
    return $out;
}

/**
 * Her badge counts, keyed the way nav_badge_counts.php returns them, so the shell's first paint
 * and its 20-second refresh can never disagree:
 *   fp_req_{phase}   documents waiting for her decision
 *   fp_date_{phase}  sections waiting on her for a date (ready groups, or a date sent back)
 * Pass $boards (phase => fp_board rows) when the caller already built them.
 */
function fp_counts(PDO $pdo, string $role, int $me, ?array $boards = null): array
{
    $out = [];
    foreach (['proposal', 'final'] as $ph) {
        $rows = $boards[$ph] ?? fp_board($pdo, $role, $me, $ph);
        $out['fp_req_' . $ph]  = array_sum(array_column($rows, 'pending'));
        $out['fp_date_' . $ph] = count(array_filter($rows, fn($r) => $r['col'] === 1));
    }
    // Groups with no room yet cannot be scheduled at all: the Sections card says how many.
    $out['fp_unassigned'] = count(fp_unassigned_groups($pdo, $role, $me));
    return $out;
}
