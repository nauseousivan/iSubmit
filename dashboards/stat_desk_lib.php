<?php
// dashboards/stat_desk_lib.php — the statistician's DESK QR (2026-10-01).
//
// Students still walk into the statistician's office to go through their coded data, and again
// with their requirements after paying. The QR on their Statistics card is how that face-to-face
// check gets recorded: if everything is right, the statistician scans it and confirms, and the
// files are approved through the SAME bulk approval the Requirements screen uses
// (admin_module_dynamic.php `bulk_verify` -> applyReviewDecision), so the Phase 1 -> Phase 2 move
// (off to the Finance Office) and the Phase 7 move happen exactly as they do today. If something
// needs fixing, she simply does not scan: the files stay pending until the student re-uploads,
// and a re-upload changes the file ids, so the old QR stops working on its own.
//
// Read by module_statistics.php (to show the QR) and stat_desk.php (the page the QR opens).

/**
 * What is waiting for the statistician's in-person check, or null when nothing is.
 *   stage 'coded'        — the initial coded data (item 30), while the request is in Coded Data Review
 *   stage 'deliverables' — the five requirements after payment (31-35), once ALL five are uploaded,
 *                          none is still flagged for revision, and at least one is pending
 * ids are the latest upload ids that are still Pending — exactly what a confirm will approve.
 */
function sd_pending(PDO $pdo, int $owner): ?array
{
    $st = $pdo->prepare("SELECT status FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $st->execute([$owner]);
    $status = (string)$st->fetchColumn();

    $latest = $pdo->prepare("
        SELECT u.item_id, u.upload_id, u.verification_status
        FROM uploads u
        JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id BETWEEN 30 AND 35 GROUP BY item_id) l
          ON l.max_id = u.upload_id");
    $latest->execute([$owner]);
    $by = [];
    foreach ($latest->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['item_id']] = $r;

    if ($status === 'Phase 1: Coded Data Review' && isset($by[30]) && $by[30]['verification_status'] === 'Pending') {
        return ['stage' => 'coded', 'ids' => [(int)$by[30]['upload_id']]];
    }
    if (in_array($status, ['Phase 5: Registered', 'Phase 6: Under Review', 'Phase 6: Revision Requested'], true)) {
        $ids = [];
        foreach ([31, 32, 33, 34, 35] as $i) {
            if (!isset($by[$i]) || $by[$i]['verification_status'] === 'Revision Requested') return null;   // not ready yet
            if ($by[$i]['verification_status'] === 'Pending') $ids[] = (int)$by[$i]['upload_id'];
        }
        sort($ids);   // the QR and the scan page compare SORTED id lists
        return $ids ? ['stage' => 'deliverables', 'ids' => $ids] : null;
    }
    return null;
}

/** The signature binding a QR to one group AND to those exact files. */
function sd_token(int $owner, array $ids): string
{
    sort($ids);
    $secret = (string)rd_setting('qr_secret', 'isubmit-desk');
    return substr(hash_hmac('sha256', 'statdesk|' . $owner . '|' . implode('-', $ids), $secret), 0, 24);
}

/** The URL the student's QR carries. */
function sd_url(int $owner, array $ids): string
{
    sort($ids);
    return rd_site_url() . '/dashboards/stat_desk.php?' . http_build_query([
        'g' => $owner, 'u' => implode('-', $ids), 't' => sd_token($owner, $ids),
    ]);
}
