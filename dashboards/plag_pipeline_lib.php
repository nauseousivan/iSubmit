<?php
// dashboards/plag_pipeline_lib.php — the plagiarism journey as ONE ladder, the twin of
// stats_pipeline_lib.php (2026-09-29).
//
// Plagiarism used to be three desks (Manuscript, Payment, Cleared) that each showed a slice of the
// same journey, the way Statistics was before its Progress screen. The journey is the same shape
// as Statistics': a document first, then the finance office, a control number, the office's own
// work, a verdict. So it gets the same screen.
//
// THE RE-PAYMENT LAP (office rule, 2026-09-28): every Turnitin revision costs a NEW payment, with
// no limit on rounds — unlike Statistics, which is paid once. A revision sends the group back to
// the start: re-send the manuscript, the office receives it, they pay again, the office registers
// the new receipt, Turnitin runs again. The control number is the group's for good; each new
// receipt is logged against it.
//
// A ROUND is derived, never stored: it starts at the group's latest `revision` row in
// plagiarism_payment_logs (round 1 has none). Payment documents (41 form, 42 receipt) only count
// for the round they were uploaded in — without that, round 1's Approved receipt would read as
// "already paid" and the group could never upload a new one. No table, no ALTER.
//
// Read-only, except nothing: every write still belongs to the handlers in admin_module_dynamic.php
// and upload_handler.php, which call the helpers below.

/** The five stages, in order. The two send-back statuses are verdicts ON a stage, not stages. */
function pp_stages(): array
{
    return [
        ['key' => 'manu', 'label' => 'Manuscript', 'tip' => 'The right manuscript received'],
        ['key' => 'pay',  'label' => 'Pay',        'tip' => 'RDC Form 13 paid and the receipt in'],
        ['key' => 'reg',  'label' => 'Reg',        'tip' => 'Payment registered against the control number'],
        ['key' => 'scan', 'label' => 'Turnitin',   'tip' => 'Turnitin scan and verdict'],
        ['key' => 'done', 'label' => 'Cleared',    'tip' => 'Cleared to bookbind'],
    ];
}

/**
 * Where a status sits and WHOSE MOVE it is — the same contract as sp_position(). Every staff
 * role that can open Plagiarism can act on it (the handlers allow Coordinator, Director and
 * Statistician alike), so 'you' means the office, whoever is looking.
 */
function pp_position(string $status): array
{
    return [
        'Phase 1: Pending Manuscript'   => ['at' => 0, 'owner' => 'them', 'do' => 'Send the final manuscript',      'flag' => ''],
        'Phase 1: Manuscript Review'    => ['at' => 0, 'owner' => 'you',  'do' => 'Receive the manuscript',         'flag' => ''],
        'Phase 1: Manuscript Returned'  => ['at' => 0, 'owner' => 'them', 'do' => 'Re-send the manuscript',         'flag' => 'sent back'],
        'Phase 2: Form Download'        => ['at' => 1, 'owner' => 'them', 'do' => 'Pay and upload the receipt',     'flag' => ''],
        'Phase 3: Payment Verification' => ['at' => 2, 'owner' => 'you',  'do' => 'Register the payment',           'flag' => ''],
        'Phase 4: Registered'           => ['at' => 3, 'owner' => 'you',  'do' => 'Run Turnitin and give the verdict', 'flag' => ''],
        'Phase 5: Revision Requested'   => ['at' => 3, 'owner' => 'them', 'do' => 'Revise and re-send the manuscript', 'flag' => 'revision'],
        'Phase 5: Cleared'              => ['at' => 4, 'owner' => 'done', 'do' => 'Cleared',                        'flag' => ''],
    ][$status] ?? ['at' => 0, 'owner' => 'them', 'do' => 'Send the final manuscript', 'flag' => ''];
}

/** When the group's current round began: its latest revision, or null in round 1. */
function pp_round_start(PDO $pdo, int $userId): ?string
{
    $s = $pdo->prepare("SELECT MAX(created_at) FROM plagiarism_payment_logs WHERE student_id = ? AND action = 'revision'");
    $s->execute([$userId]);
    $v = $s->fetchColumn();
    return $v ?: null;
}

/**
 * The latest form (41) and receipt (42) uploaded IN THE CURRENT ROUND, keyed by item_id.
 * Round 1's documents stay in `uploads` as history; they just stop answering "has this round
 * been paid". Compared in SQL, so both timestamps are MySQL's (PHP runs Europe/Berlin).
 */
function pp_current_pay_docs(PDO $pdo, int $userId): array
{
    $start = pp_round_start($pdo, $userId);
    $s = $pdo->prepare("SELECT up.item_id, up.upload_id, up.file_path, up.original_filename,
                               up.verification_status, up.remarks, up.uploaded_at
                        FROM uploads up
                        JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads
                              WHERE user_id = ? AND item_id IN (41,42) AND uploaded_at > ?
                              GROUP BY item_id) l ON l.max_id = up.upload_id");
    $s->execute([$userId, $start ?? '1970-01-01 00:00:00']);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) $out[(int)$d['item_id']] = $d;
    return $out;
}

/**
 * The part of a plagiarism control number the office does NOT type: `PLAG-ISAP-CE-`. School and
 * course come from the group's PROGRAM, exactly as the STAT number's do (sp_control_prefix).
 * The ONE source for it — plag_acknowledge_payment mints with it and the Progress composer
 * previews with it, so the preview cannot drift from the mint.
 */
function pp_control_prefix(?string $program): string
{
    $prog = strtoupper($program ?: 'GEN');
    $school = 'ISAP';
    foreach (['NURSING', 'MEDICAL', 'RADIOLOGIC', 'PHARMACY', 'MIDWIFERY', 'DENTAL', 'CAREGIVING'] as $mk) {
        if (strpos($prog, $mk) !== false) { $school = 'MCNP'; break; }
    }
    $stop = ['BS', 'BA', 'AB', 'BSED', 'OF', 'IN', 'AND', 'THE'];
    $words = [];
    foreach (preg_split('/\s+/', trim($prog)) as $w) {
        if ($w !== '' && !in_array($w, $stop, true)) $words[] = $w;
    }
    if (count($words) === 1)   $course = $words[0];
    elseif (count($words) > 1) { $course = ''; foreach ($words as $w) $course .= $w[0]; }
    else                       $course = 'GEN';
    return 'PLAG-' . $school . '-' . $course . '-';
}

/**
 * Every released group the viewer may see, positioned on the ladder.
 *
 * Only groups the office has NOTIFIED (released_at set) — before that there is no plagiarism
 * journey, and those groups live on the Notify screen. Scoped with aa_scope_sql() on `u`, like
 * sp_rows(); never query it unscoped.
 *
 * `days` runs from the last thing that happened to the request (an upload, a decision, the
 * release), computed in MySQL — never PHP, which runs Europe/Berlin.
 */
function pp_rows(PDO $pdo, string $scopeSql, array $scopeParams): array
{
    $stmt = $pdo->prepare("
        SELECT t.*, TIMESTAMPDIFF(DAY, t.since, NOW()) AS days
        FROM (
            SELECT pc.user_id, pc.status, pc.formatted_control_no, pc.released_at, pc.registered_at,
                   pc.remarks AS plag_remarks,
                   u.username, u.student_number, u.research_group_name, u.department, u.program,
                   u.email, u.research_title,
                   GREATEST(pc.released_at,
                            COALESCE((SELECT MAX(up.uploaded_at) FROM uploads up
                                       WHERE up.user_id = pc.user_id AND up.item_id IN (4,40,41,42)), pc.released_at),
                            COALESCE((SELECT MAX(l.created_at) FROM plagiarism_payment_logs l
                                       WHERE l.student_id = pc.user_id), pc.released_at)) AS since,
                   (SELECT COUNT(*) FROM plagiarism_payment_logs l
                     WHERE l.student_id = pc.user_id AND l.action = 'revision')   AS revisions,
                   (SELECT MAX(l.created_at) FROM plagiarism_payment_logs l
                     WHERE l.student_id = pc.user_id AND l.action = 'revision')   AS round_start
            FROM plagiarism_checks pc
            JOIN users u ON u.user_id = pc.user_id
            WHERE pc.released_at IS NOT NULL AND $scopeSql
        ) t");
    $stmt->execute($scopeParams);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_merge($r, pp_position((string)$r['status']));
        $r['days']  = max(0, (int)$r['days']);
        $r['round'] = (int)$r['revisions'] + 1;
        $r['docs']  = [];
        $rows[] = $r;
    }
    if (!$rows) return [];

    // The documents behind each stage, for the whole set in one pass: the latest manuscript (4),
    // the latest Turnitin report (40), and this round's form + receipt (41, 42).
    $ids = array_column($rows, 'user_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $d = $pdo->prepare("SELECT upload_id, user_id, item_id, file_path, original_filename,
                               verification_status, remarks, uploaded_at
                        FROM uploads WHERE user_id IN ($ph) AND item_id IN (4,40,41,42)
                        ORDER BY upload_id ASC");
    $d->execute($ids);
    $all = [];
    foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $doc) $all[(int)$doc['user_id']][] = $doc;
    foreach ($rows as &$r) {
        foreach ($all[(int)$r['user_id']] ?? [] as $doc) {
            $item = (int)$doc['item_id'];
            // Payment documents only count for the round they belong to (see the header).
            if (($item === 41 || $item === 42) && $r['round_start'] !== null && $doc['uploaded_at'] <= $r['round_start']) continue;
            $r['docs'][$item] = $doc;   // ASC by id, so the latest wins
        }
    }
    unset($r);

    // The office's move first, then the group's, then cleared. Inside a working band the one that
    // has waited longest leads; Cleared is a record, so it reads newest first.
    $rank = ['you' => 0, 'them' => 1, 'done' => 2];
    usort($rows, function ($a, $b) use ($rank) {
        if ($rank[$a['owner']] !== $rank[$b['owner']]) return $rank[$a['owner']] <=> $rank[$b['owner']];
        return $a['owner'] === 'done' ? $a['days'] <=> $b['days'] : $b['days'] <=> $a['days'];
    });
    return $rows;
}

/**
 * THE THREE OFFICE STEPS (2026-09-29, from the Claude Design concept): Confirm manuscripts ·
 * Log payments · Release results — one screen each (`?phase=plag&view=manuscript|payment|result`).
 * Which step a status belongs to, and whether it is the office's move there. The board on the
 * Plagiarism hub adds a fourth column, Released, for the verdicts already given.
 *
 *   step     'manuscript' | 'payment' | 'result'
 *   board    0 Manuscript · 1 Payment · 2 Checking · 3 Released
 *   tone     you (solid) · them (outline) · back (sent back / revision) · done (cleared)
 */
function pp_step(string $status): array
{
    return [
        'Phase 1: Pending Manuscript'   => ['step' => 'manuscript', 'board' => 0, 'tone' => 'them'],
        'Phase 1: Manuscript Review'    => ['step' => 'manuscript', 'board' => 0, 'tone' => 'you'],
        'Phase 1: Manuscript Returned'  => ['step' => 'manuscript', 'board' => 0, 'tone' => 'back'],
        'Phase 2: Form Download'        => ['step' => 'payment',    'board' => 1, 'tone' => 'them'],
        'Phase 3: Payment Verification' => ['step' => 'payment',    'board' => 1, 'tone' => 'you'],
        'Phase 4: Registered'           => ['step' => 'result',     'board' => 2, 'tone' => 'you'],
        'Phase 5: Revision Requested'   => ['step' => 'result',     'board' => 3, 'tone' => 'back'],
        'Phase 5: Cleared'              => ['step' => 'result',     'board' => 3, 'tone' => 'done'],
    ][$status] ?? ['step' => 'manuscript', 'board' => 0, 'tone' => 'them'];
}

/** The board's four columns. */
function pp_board_cols(): array
{
    return ['Manuscript', 'Payment', 'Checking', 'Released'];
}

/**
 * The office's move per step, scoped like the screens: manuscripts to confirm, payments to log,
 * scans to check. One definition for the rail badge, the hub cards, the 20s refresh and the
 * step pills on the screens themselves — so none of them can disagree.
 */
function pp_step_counts(array $rows): array
{
    $c = ['manuscript' => 0, 'payment' => 0, 'result' => 0];
    foreach ($rows as $r) {
        $s = pp_step((string)$r['status']);
        if ($s['tone'] === 'you') $c[$s['step']]++;
    }
    return $c;
}

function pp_badge_counts(PDO $pdo, string $role, int $userId): array
{
    if (!function_exists('aa_scope_sql')) require_once __DIR__ . '/admin_accounts_lib.php';
    [$sql, $params] = aa_scope_sql($pdo, $role, $userId, 'u');
    return pp_step_counts(pp_rows($pdo, $sql, $params));
}

/** How many rows fall in each band, for the lane pills. */
function pp_counts(array $rows): array
{
    $c = ['you' => 0, 'them' => 0, 'done' => 0];
    foreach ($rows as $r) $c[$r['owner']]++;
    return $c;
}
