<?php
/**
 * The Manuscript (plagiarism) desk counts, in one place.
 *
 * There are four staff shells that show these numbers — director.php, coordinator.php,
 * statistician.php (server-rendered on load) and nav_badge_counts.php (polled every 20s) — and
 * before this file each carried its own copy of the SQL. They had already drifted: Director counted
 * 'Pending' only, Statistician counted 'Pending' + 'Under Review', and Coordinator folded item 4
 * into the big uploads query with no LATEST-per-group filter at all, so a group that re-uploaded
 * three times counted three times. Same badge, three different numbers depending who logged in.
 *
 * Counting off plagiarism_checks.status also fixes the deeper problem: since plag_receive exists,
 * an item-4 upload marked 'Approved' means "received, now go pay" — three desks away from being
 * anyone's queue. The phase ladder is the only thing that knows which desk a group is actually
 * standing at.
 *
 * @return array{review:int, payment:int, verdict:int, release:int}
 */
function rd_plag_badge_counts(PDO $pdo): array
{
    $row = $pdo->query("
        SELECT
            SUM(status = 'Phase 1: Manuscript Review')                                AS review,
            SUM(status IN ('Phase 2: Form Download', 'Phase 3: Payment Verification')) AS payment,
            SUM(status = 'Phase 4: Registered')                                        AS verdict
        FROM plagiarism_checks
    ")->fetch();

    // Release is the one desk with no plagiarism_checks row yet — that row is created BY the
    // release, so the queue has to be sourced from the bookings that qualify for one.
    $release = (int) $pdo->query("
        SELECT COUNT(*) FROM defense_bookings b
        LEFT JOIN plagiarism_checks pc ON pc.user_id = b.user_id
        WHERE b.phase = 'final' AND b.status = 'Scheduled' AND pc.released_at IS NULL
    ")->fetchColumn();

    return [
        'review'  => (int) ($row['review'] ?? 0),
        'payment' => (int) ($row['payment'] ?? 0),
        'verdict' => (int) ($row['verdict'] ?? 0),
        'release' => $release,
    ];
}
