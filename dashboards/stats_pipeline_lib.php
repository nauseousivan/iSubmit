<?php
// dashboards/stats_pipeline_lib.php — the statistician's cohort as ONE ladder.
//
// Her work was split across three screens (Checklist, Payment, Release) that each showed a slice
// of the same journey, so "where is this group" could only be answered by opening all three. The
// student has seen a single 7-phase ring the whole time (student.php's $stats_phase_meta); this is
// the staff side of that same ladder.
//
// Read-only. Every write still belongs to the handlers in admin_module_dynamic.php.

/**
 * The six stages a request passes through, in order. Deliberately SIX, not the seven phase
 * strings: 'Phase 1: Coded Data Rejected' and 'Phase 6: Revision Requested' are not places, they
 * are the same place with a verdict attached — the difference belongs in whose move it is, not in
 * a column of its own.
 */
function sp_stages(): array
{
    return [
        ['key' => 'coded', 'label' => 'Coded',  'tip' => 'Initial coded data verified'],
        ['key' => 'pay',   'label' => 'Pay',    'tip' => 'Form 011 paid and the receipt in'],
        ['key' => 'reg',   'label' => 'Reg',    'tip' => 'Control number issued'],
        ['key' => 'reqs',  'label' => 'Reqs',   'tip' => 'The five deliverables approved'],
        ['key' => 'treat', 'label' => 'Treat',  'tip' => 'Statistical treatment'],
        ['key' => 'done',  'label' => 'Done',   'tip' => 'Results released'],
    ];
}

/**
 * Where a status sits and WHOSE MOVE it is.
 *
 * `at` is the index into sp_stages() the group is currently standing on; everything before it is
 * done. `owner` is 'you' (the statistician), 'them' (the group) or 'done'. That split is the whole
 * point of the screen: a queue that mixes the two is how the payment desk ended up counting four
 * things as "action needed" when only two were hers.
 *
 * `flag` marks the statuses that are the same stage with a bad verdict, so a bounced group reads
 * as bounced rather than as merely waiting.
 */
function sp_position(string $status): array
{
    return [
        'Phase 1: Pending Coded Data'    => ['at' => 0, 'owner' => 'them', 'do' => 'Upload the coded data',        'flag' => ''],
        'Phase 1: Coded Data Review'     => ['at' => 0, 'owner' => 'you',  'do' => 'Check their coded data',       'flag' => ''],
        'Phase 1: Coded Data Rejected'   => ['at' => 0, 'owner' => 'them', 'do' => 'Re-upload the coded data',     'flag' => 'sent back'],
        'Phase 2: Form Download'         => ['at' => 1, 'owner' => 'them', 'do' => 'Pay and upload the receipt',   'flag' => ''],
        'Phase 4: Payment Verification'  => ['at' => 2, 'owner' => 'you',  'do' => 'Issue a control number',       'flag' => ''],
        'Phase 5: Registered'            => ['at' => 3, 'owner' => 'them', 'do' => 'Upload the five deliverables', 'flag' => ''],
        'Phase 6: Under Review'          => ['at' => 3, 'owner' => 'you',  'do' => 'Check the deliverables',       'flag' => ''],
        'Phase 6: Revision Requested'    => ['at' => 3, 'owner' => 'them', 'do' => 'Fix a deliverable',            'flag' => 'sent back'],
        'Phase 7: Statistical Treatment' => ['at' => 4, 'owner' => 'you',  'do' => 'Run the treatment and release','flag' => ''],
        'Phase 7: Completed'             => ['at' => 5, 'owner' => 'done', 'do' => 'Released',                     'flag' => ''],
    ][$status] ?? ['at' => 0, 'owner' => 'them', 'do' => 'Upload the coded data', 'flag' => ''];
}

/**
 * Every request the viewer may see, positioned on the ladder.
 *
 * $scopeSql/$scopeParams come from aa_scope_sql() — a Statistician covers one institution or both,
 * so this must never be queried unscoped.
 *
 * Ordered so the screen opens on the work: her move first, then whoever has been waiting longest.
 * `since` is the last thing that actually happened to the request, not the day it was created — a
 * request opened in July and paid yesterday has been waiting one day, not two months.
 */
function sp_rows(PDO $pdo, string $scopeSql, array $scopeParams): array
{
    $stmt = $pdo->prepare("
        SELECT f.form_id, f.user_id, f.status, f.formatted_control_no, f.date_submitted, f.date_released,
               f.result_file, f.statistician_remarks,
               u.username, u.student_number, u.research_group_name, u.department, u.program,
               u.email, u.profile_pic, u.research_title,
               (SELECT MAX(up.uploaded_at) FROM uploads up
                 WHERE up.user_id = f.user_id AND up.item_id IN (30,31,32,33,34,35,36,37)) AS last_touch,
               (SELECT MAX(up.verified_at) FROM uploads up
                 WHERE up.user_id = f.user_id AND up.item_id IN (31,32,33,34,35)
                   AND up.verification_status = 'Approved')                                AS last_cleared,
               (SELECT COUNT(DISTINCT up.item_id) FROM uploads up
                 WHERE up.user_id = f.user_id AND up.item_id IN (31,32,33,34,35)
                   AND up.verification_status = 'Approved')                                AS reqs_ok
        FROM form_stat_treatment f
        JOIN users u ON u.user_id = f.user_id
        WHERE $scopeSql");
    $stmt->execute($scopeParams);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos = sp_position($r['status']);
        $r   = array_merge($r, $pos);
        // The clock runs from the last thing that happened, whichever side did it.
        $r['since']    = $r['last_touch'] ?: $r['date_submitted'];
        $r['days']     = max(0, (int)floor((time() - strtotime($r['since'])) / 86400));
        $r['reqs_ok']  = (int)$r['reqs_ok'];
        $rows[] = $r;
    }

    // The validated Form 011 and the Official Receipt, latest version of each. Fetched for the
    // whole set in one pass rather than per row: this is the evidence behind the Reg stage, and
    // a screen that asks her to mint a permanent number should show her what she is minting it
    // against.
    if ($rows) {
        $ids = array_column($rows, 'user_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $dstmt = $pdo->prepare("SELECT up.upload_id, up.user_id, up.item_id, up.file_path,
                                       up.original_filename, up.verification_status, up.remarks
                                FROM uploads up
                                INNER JOIN (SELECT user_id, item_id, MAX(upload_id) AS max_id
                                            FROM uploads WHERE user_id IN ($ph) AND item_id IN (36,37)
                                            GROUP BY user_id, item_id) l ON l.max_id = up.upload_id");
        $dstmt->execute($ids);
        $byUser = [];
        foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $d) $byUser[(int)$d['user_id']][(int)$d['item_id']] = $d;
        foreach ($rows as &$r) $r['pay_docs'] = $byUser[(int)$r['user_id']] ?? [];
        unset($r);
    }

    // Hers first, then theirs, then finished — and oldest first inside each, because within a
    // band the only thing that distinguishes two rows is who has waited longer.
    $rank = ['you' => 0, 'them' => 1, 'done' => 2];
    usort($rows, function ($a, $b) use ($rank) {
        if ($rank[$a['owner']] !== $rank[$b['owner']]) return $rank[$a['owner']] <=> $rank[$b['owner']];
        return strcmp($a['since'], $b['since']);
    });
    return $rows;
}

/** How many rows fall in each band, for the filter pills. */
function sp_counts(array $rows): array
{
    $c = ['you' => 0, 'them' => 0, 'done' => 0];
    foreach ($rows as $r) $c[$r['owner']]++;
    return $c;
}

/**
 * The part of a control number the statistician does NOT choose.
 *
 * `STAT-2026-ISAP-CE-` — year, institution, course code. Derived entirely from the group's
 * PROGRAM (never `users.department`, the institution-level field: its initials came out as
 * gibberish like "ISOAATP"). She supplies only the sequence.
 *
 * Lives here rather than inline in the handler so the composer can SHOW the number before it is
 * minted. A preview built from a second copy of this logic would drift, and the one thing worse
 * than not previewing a permanent identifier is previewing it wrongly.
 */
function sp_control_prefix(?string $program): string
{
    $prog = strtoupper($program ?? 'GEN');

    $school = 'ISAP';
    foreach (['NURSING', 'MEDICAL', 'RADIOLOGIC', 'PHARMACY', 'MIDWIFERY', 'DENTAL', 'CAREGIVING'] as $mark) {
        if (strpos($prog, $mark) !== false) { $school = 'MCNP'; break; }
    }

    $stopwords = ['BS', 'BA', 'AB', 'BSED', 'OF', 'IN', 'AND', 'THE'];
    $significant = [];
    foreach (preg_split('/\s+/', trim($program ?? '')) as $w) {
        if ($w !== '' && !in_array(strtoupper($w), $stopwords, true)) $significant[] = strtoupper($w);
    }
    if (count($significant) === 1) {
        $course = $significant[0];
    } elseif (count($significant) > 1) {
        $course = '';
        foreach ($significant as $w) $course .= $w[0];
    } else {
        $course = 'GEN';
    }

    return 'STAT-' . date('Y') . '-' . $school . '-' . $course . '-';
}
