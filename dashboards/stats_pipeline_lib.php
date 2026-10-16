<?php
// dashboards/stats_pipeline_lib.php — the statistician's cohort as ONE ladder.
//
// Her work was split across three screens (Checklist, Payment, Release) that each showed a slice
// of the same journey, so "where is this group" could only be answered by opening all three. The
// student has seen a single 7-phase ring the whole time (student.php's $stats_phase_meta); this is
// the staff side of that same ladder.
//
// Reads, plus ONE write: sp_sync_reqs(), the shared rule both upload and review handlers call so
// they cannot disagree. The Secretary's two steps (issue the number, confirm the payment) are
// handlers in admin_module_dynamic.php.

/*
 * THE STATISTICS FLOW (rewritten 2026-10-16 with the RDC office, Form 11 in hand):
 *   1. Requirements — the group uploads the five (SP_REQ_ITEMS); the Statistician checks them.
 *   2. Control number — the SECRETARY issues it (sp_issue_control_no(): next number pre-filled,
 *      editable). That is her LAST step: the request goes straight to treatment. Payment is on
 *      paper — they pay at Finance and bring Form 11 back to the RDC; the system records no receipt
 *      (the user dropped the receipt upload and the confirm step, 2026-10-16).
 *   3. Treatment — the Statistician works from the files of step 1 and releases (or sends back to
 *      fix files; stats is paid ONCE, so a send-back never touches payment).
 * The phase STRINGS are kept (they are fixed values all over the code); what changed is what
 * each means. 'Phase 3: Control Number Issued' / 'Phase 4: Payment Verification' are no longer
 * written (kept mapped for any old row). 'Phase 5: Registered' is legacy only: groups who paid
 * under the old flow and still owe some of the five.
 */

/** The five requirements of step 1, as Form 11 lists them (+ the MOM the office still asks for). */
const SP_REQ_ITEMS = [30, 31, 32, 34, 35];   // coded data (Excel), SOP, questionnaire, communication letter, MOM
const SP_RECEIPT_ITEM = 37;                  // the Official Receipt — RETIRED 2026-10-16 (no upload, no confirm); kept for old rows

/** Phase-1 statuses (checking the five) and the post-payment loop (revise / legacy registered). */
const SP_PHASE1 = ['Phase 1: Pending Coded Data', 'Phase 1: Coded Data Review', 'Phase 1: Coded Data Rejected'];
const SP_LOOP   = ['Phase 5: Registered', 'Phase 6: Under Review', 'Phase 6: Revision Requested'];

/**
 * The five stages a request passes through, in order. 'Rejected' / 'Revision Requested' are not
 * places, they are the same place with a verdict attached — the difference belongs in whose move it
 * is, not in a column of its own.
 */
function sp_stages(): array
{
    return [
        ['key' => 'reqs',  'label' => 'Reqs',   'tip' => 'The five requirements checked'],
        ['key' => 'cn',    'label' => 'No.',    'tip' => 'Control number issued by the Secretary'],
        ['key' => 'treat', 'label' => 'Treat',  'tip' => 'Statistical treatment'],
        ['key' => 'done',  'label' => 'Done',   'tip' => 'Results released'],
    ];
}

/**
 * Where a status sits and WHOSE MOVE it is.
 *
 * `at` is the index into sp_stages() the group is currently standing on; everything before it is
 * done. `owner` is 'you' (the OFFICE), 'them' (the group) or 'done'; `actor` says which office
 * desk holds it — 'stat' (Statistician) or 'sec' (Secretary). sp_rows() turns that into the
 * viewer's own band, so the Statistician's "Your move" never counts the Secretary's work.
 *
 * `flag` marks the statuses that are the same stage with a bad verdict, so a bounced group reads
 * as bounced rather than as merely waiting.
 */
function sp_position(string $status): array
{
    return [
        'Phase 1: Pending Coded Data'    => ['at' => 0, 'owner' => 'them', 'actor' => '',     'do' => 'Upload the five requirements',        'flag' => ''],
        'Phase 1: Coded Data Review'     => ['at' => 0, 'owner' => 'you',  'actor' => 'stat', 'do' => 'Check the requirements',              'flag' => ''],
        'Phase 1: Coded Data Rejected'   => ['at' => 0, 'owner' => 'them', 'actor' => '',     'do' => 'Fix the requirements sent back',      'flag' => 'sent back'],
        'Phase 2: Form Download'         => ['at' => 1, 'owner' => 'you',  'actor' => 'sec',  'do' => 'Issue the control number',            'flag' => ''],
        // Phase 3 / 4 are no longer written (receipt step dropped); an old row there is the treatment's.
        'Phase 3: Control Number Issued' => ['at' => 2, 'owner' => 'you',  'actor' => 'stat', 'do' => 'Run the treatment and release',       'flag' => ''],
        'Phase 4: Payment Verification'  => ['at' => 2, 'owner' => 'you',  'actor' => 'stat', 'do' => 'Run the treatment and release',       'flag' => ''],
        'Phase 5: Registered'            => ['at' => 2, 'owner' => 'them', 'actor' => '',     'do' => 'Upload the remaining requirements',   'flag' => ''],
        'Phase 6: Under Review'          => ['at' => 2, 'owner' => 'you',  'actor' => 'stat', 'do' => 'Check the revised requirements',      'flag' => ''],
        'Phase 6: Revision Requested'    => ['at' => 2, 'owner' => 'them', 'actor' => '',     'do' => 'Fix a requirement',                   'flag' => 'sent back'],
        'Phase 7: Statistical Treatment' => ['at' => 2, 'owner' => 'you',  'actor' => 'stat', 'do' => 'Run the treatment and release',       'flag' => ''],
        'Phase 7: Completed'             => ['at' => 3, 'owner' => 'done', 'actor' => '',     'do' => 'Released',                            'flag' => ''],
    ][$status] ?? ['at' => 0, 'owner' => 'them', 'actor' => '', 'do' => 'Upload the five requirements', 'flag' => ''];
}

/** Which office desk a role sits at on this ladder: 'stat', 'sec' (the Secretary IS a Research Director), or ''. */
function sp_actor_of(?string $role): string
{
    return $role === 'Statistician' ? 'stat' : ($role === 'Research Director' ? 'sec' : '');
}

/** What to call a desk in copy a person reads. */
function sp_actor_name(string $actor): string
{
    return $actor === 'sec' ? 'the Secretary' : ($actor === 'stat' ? 'the Statistician' : 'the office');
}

/**
 * THE ONE RULE for where a request stands while its five requirements are being checked — called
 * after every upload, delete and review decision on one of them, so the student handler and the
 * staff handler can never disagree. Only moves a request that is in Phase 1 or the post-payment
 * loop; anything else (waiting on the Secretary, paying, treating, released) is left alone.
 * Returns the status it ended on.
 */
function sp_sync_reqs(PDO $pdo, int $uid): ?string
{
    $f = $pdo->prepare("SELECT form_id, status FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $f->execute([$uid]);
    $f = $f->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;

    $ph = implode(',', SP_REQ_ITEMS);
    $l = $pdo->prepare("SELECT u.item_id, u.verification_status FROM uploads u
                        JOIN (SELECT item_id, MAX(upload_id) m FROM uploads WHERE user_id = ? AND item_id IN ($ph) GROUP BY item_id) x
                          ON x.m = u.upload_id");
    $l->execute([$uid]);
    $present = 0; $approved = 0; $back = false;
    foreach ($l->fetchAll(PDO::FETCH_KEY_PAIR) as $st) {
        $present++;
        if ($st === 'Approved') $approved++;
        if ($st === 'Revision Requested') $back = true;
    }
    $all = count(SP_REQ_ITEMS);

    if (in_array($f['status'], SP_PHASE1, true)) {
        $new = $approved === $all ? 'Phase 2: Form Download'
             : ($back ? 'Phase 1: Coded Data Rejected'
             : ($present === $all ? 'Phase 1: Coded Data Review' : 'Phase 1: Pending Coded Data'));
    } elseif (in_array($f['status'], SP_LOOP, true)) {
        $new = $approved === $all ? 'Phase 7: Statistical Treatment'
             : ($back ? 'Phase 6: Revision Requested'
             : ($present === $all ? 'Phase 6: Under Review' : 'Phase 5: Registered'));
    } else {
        return $f['status'];
    }
    if ($new !== $f['status']) {
        $pdo->prepare("UPDATE form_stat_treatment SET status = ? WHERE form_id = ?")->execute([$new, $f['form_id']]);
        if ($new === 'Phase 2: Form Download') {
            $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)")
                ->execute([$uid, 'Statistics requirements approved',
                    'All five of your statistics requirements were approved. Go to the RDC office: the Secretary will issue your control number and give you Form 11.']);
        }
    }
    return $new;
}

/**
 * The next control number for a group, previewed and minted from ONE place. The prefix is
 * sp_control_prefix() (year, institution, course); the sequence counts up per prefix across the
 * live AND archived requests (a live-only MAX would reissue a number an archived cycle holds).
 * Numbers typed by hand before 2026-10-16 carry no control_number_seq and are simply not counted.
 */
function sp_next_control_no(PDO $pdo, ?string $program, bool $lock = false): array
{
    $prefix = sp_control_prefix($program);
    // $lock (inside the minting transaction): a locking read over the prefix's rows, so two
    // Research Director accounts issuing at the same moment cannot both take the same number.
    $live = $pdo->prepare("SELECT COALESCE(MAX(control_number_seq), 0) FROM form_stat_treatment WHERE formatted_control_no LIKE ?" . ($lock ? " FOR UPDATE" : ""));
    $live->execute([$prefix . '%']);
    $arch = $pdo->prepare("SELECT COALESCE(MAX(control_number_seq), 0) FROM form_stat_treatment_archive WHERE formatted_control_no LIKE ?");
    $arch->execute([$prefix . '%']);
    $seq = max((int)$live->fetchColumn(), (int)$arch->fetchColumn()) + 1;
    return [$seq, $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT)];
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
function sp_rows(PDO $pdo, string $scopeSql, array $scopeParams, ?string $viewerRole = null): array
{
    $req = implode(',', SP_REQ_ITEMS);
    $stmt = $pdo->prepare("
        SELECT f.form_id, f.user_id, f.status, f.formatted_control_no, f.main_or_number, f.date_submitted, f.date_released,
               f.result_file, f.statistician_remarks,
               u.username, u.student_number, u.research_group_name, u.department, u.program,
               u.email, u.profile_pic, u.research_title,
               (SELECT MAX(up.uploaded_at) FROM uploads up
                 WHERE up.user_id = f.user_id AND up.item_id IN ($req, " . SP_RECEIPT_ITEM . ")) AS last_touch,
               (SELECT MAX(pl.created_at) FROM payment_logs pl WHERE pl.form_id = f.form_id) AS last_logged
        FROM form_stat_treatment f
        JOIN users u ON u.user_id = f.user_id
        WHERE $scopeSql");
    $stmt->execute($scopeParams);

    $me = sp_actor_of($viewerRole);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos = sp_position($r['status']);
        $r   = array_merge($r, $pos);
        // The viewer's own band: an office move belongs to "you" only when it is YOUR desk's.
        // A viewer at neither desk (Coordinator) sees every office move together.
        if ($r['owner'] === 'you' && $me !== '' && $r['actor'] !== $me) $r['owner'] = 'office';
        // The clock runs from the last thing that happened, whichever side did it — an upload,
        // or an office step like issuing the number (payment_logs).
        $r['since']    = max((string)$r['last_touch'], (string)$r['last_logged']) ?: $r['date_submitted'];
        $r['days']     = max(0, (int)floor((time() - strtotime($r['since'])) / 86400));
        $rows[] = $r;
    }

    // The Official Receipt, latest version. Fetched for the whole set in one pass rather than per
    // row: it is the evidence the Secretary confirms the payment against, so the screen that
    // asks her to confirm should show it.
    if ($rows) {
        $ids = array_column($rows, 'user_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // Same pass: the latest version of each of the five, for the "n/5 approved" fact.
        $dstmt = $pdo->prepare("SELECT up.upload_id, up.user_id, up.item_id, up.file_path,
                                       up.original_filename, up.verification_status, up.remarks
                                FROM uploads up
                                INNER JOIN (SELECT user_id, item_id, MAX(upload_id) AS max_id
                                            FROM uploads WHERE user_id IN ($ph) AND item_id IN ($req, " . SP_RECEIPT_ITEM . ")
                                            GROUP BY user_id, item_id) l ON l.max_id = up.upload_id");
        $dstmt->execute($ids);
        $byUser = []; $okBy = [];
        foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if ((int)$d['item_id'] === SP_RECEIPT_ITEM) $byUser[(int)$d['user_id']][SP_RECEIPT_ITEM] = $d;
            elseif ($d['verification_status'] === 'Approved') $okBy[(int)$d['user_id']] = ($okBy[(int)$d['user_id']] ?? 0) + 1;
        }
        foreach ($rows as &$r) {
            $r['pay_docs'] = $byUser[(int)$r['user_id']] ?? [];
            $r['reqs_ok']  = $okBy[(int)$r['user_id']] ?? 0;
        }
        unset($r);
    }

    // Yours first, then the other desk's, then theirs, then finished — and oldest first inside
    // each, because within a band the only thing that distinguishes two rows is who waited longer.
    $rank = ['you' => 0, 'office' => 1, 'them' => 2, 'done' => 3];
    usort($rows, function ($a, $b) use ($rank) {
        if ($rank[$a['owner']] !== $rank[$b['owner']]) return $rank[$a['owner']] <=> $rank[$b['owner']];
        return strcmp($a['since'], $b['since']);
    });
    return $rows;
}

/** How many rows fall in each band, for the filter pills. */
function sp_counts(array $rows): array
{
    $c = ['you' => 0, 'office' => 0, 'them' => 0, 'done' => 0];
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
