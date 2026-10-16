<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt_leader = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader->execute([$user_id]);
$leader_id = $stmt_leader->fetchColumn();
$effective_user_id = $leader_id ?? $user_id;

function stat_redirect($msg, $type = 'error') {
    header("Location: module_statistics.php?msg=" . urlencode($msg) . "&type=" . $type);
    exit();
}

// Returns the latest upload row per item for this user, keyed by item_id.
function latest_uploads_by_item($pdo, $user_id, array $item_ids) {
    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $sql = "SELECT u.item_id, u.upload_id, u.verification_status
            FROM uploads u
            INNER JOIN (
                SELECT item_id, MAX(upload_id) AS max_id
                FROM uploads
                WHERE user_id = ? AND item_id IN ($placeholders)
                GROUP BY item_id
            ) latest ON latest.max_id = u.upload_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$user_id], $item_ids));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['item_id']] = $row;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_stat_requirement') {
    // CSRF: reject forged submissions (token issued in config/db.php)
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        stat_redirect("Security token expired. Please refresh and try again.");
    }

    require_once __DIR__ . '/stats_pipeline_lib.php';   // SP_REQ_ITEMS, sp_sync_reqs() — the one status rule
    $item_id = intval($_POST['item_id']);
    // (2026-10-16) The five requirements only. Retired: 33 (Final Coded Data — Form 11 lists one
    // coded data), 36 (validated Form 011 — the paper stays at the RDC) and 37 (the receipt —
    // payment is handled on paper; issuing the control number moves the request to treatment).
    $valid_items = SP_REQ_ITEMS;
    if (!in_array($item_id, $valid_items)) {
        stat_redirect("Invalid requirement selection.");
    }

    if (!isset($_FILES['research_file']) || $_FILES['research_file']['error'] !== UPLOAD_ERR_OK) {
        stat_redirect("Failed to upload file. Please try again.");
    }

    $file = $_FILES['research_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Images allowed because payment documents (items 36/37) are photos/scans.
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    if (!in_array($ext, $allowed)) {
        stat_redirect("Invalid file format. Only PDF, DOC, Excel, and image files are allowed.");
    }

    // Check current state in form_stat_treatment
    $stmt_form = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $stmt_form->execute([$effective_user_id]);
    $stats_data = $stmt_form->fetch();
    $current_status = $stats_data['status'] ?? 'Phase 1: Pending Coded Data';

    // Phase gate: each requirement is only submittable during its own phase,
    // mirroring the locked/unlocked cards in the student wizard.
    // The five are open in step 1 and in the fix-it loop after treatment (and for legacy groups
    // that paid under the old flow).
    $phase_gate = array_merge(SP_PHASE1, SP_LOOP);
    if (!in_array($current_status, $phase_gate)) {
        stat_redirect("This requirement is not available at your current phase.");
    }
    // An APPROVED requirement is not re-uploaded (nothing would review it again); in the loop only
    // the ones sent back, or still missing, are open.
    $latest_now = latest_uploads_by_item($pdo, $effective_user_id, [$item_id]);
    if (($latest_now[$item_id]['verification_status'] ?? '') === 'Approved') {
        stat_redirect("That requirement is already approved.");
    }

    $control_no = $stats_data ? $stats_data['formatted_control_no'] : '';
    $dir = '../uploads/stats/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    // Determine filename (official control number prefix once registered)
    $item_names = [
        30 => 'InitialData',
        31 => 'SOP',
        32 => 'Questionnaire',
        33 => 'CodedData',
        34 => 'CommLetter',
        35 => 'MOM',
        36 => 'ValidatedForm',
        37 => 'Receipt'
    ];
    $doc_name = $item_names[$item_id];
    if ($control_no) {
        $filename = "{$control_no}_{$doc_name}_" . time() . ".$ext";
    } elseif ($item_id == 30) {
        $filename = "InitialData_{$effective_user_id}_" . time() . ".$ext";
    } else {
        $filename = "REQ_{$effective_user_id}_{$doc_name}_" . time() . ".$ext";
    }

    $filepath = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        stat_redirect("Failed to move uploaded file.");
    }

    // Insert as a NEW Pending version (history-preserving, same as the proposal module)
    $stmt_insert = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt_insert->execute([$effective_user_id, $item_id, $filepath, $filename]);
    $new_upload_id = $pdo->lastInsertId();

    // Supersede-on-reupload: prune any OTHER un-reviewed Pending drafts for this item so at most
    // one Pending version exists at a time. Reviewed versions stay as submission history.
    $stale_stmt = $pdo->prepare("SELECT upload_id, file_path FROM uploads WHERE user_id = ? AND item_id = ? AND verification_status = 'Pending' AND upload_id != ?");
    $stale_stmt->execute([$effective_user_id, $item_id, $new_upload_id]);
    foreach ($stale_stmt->fetchAll() as $stale) {
        $pdo->prepare("DELETE FROM uploads WHERE upload_id = ?")->execute([$stale['upload_id']]);
        // Only delete the physical file if no surviving row still references that path.
        $ref_stmt = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE file_path = ?");
        $ref_stmt->execute([$stale['file_path']]);
        if ($ref_stmt->fetchColumn() == 0 && !empty($stale['file_path']) && file_exists($stale['file_path'])) {
            @unlink($stale['file_path']);
        }
    }

    // Phase transitions on form_stat_treatment. The request is opened by the FIRST of the five,
    // whichever it is (it used to be the coded data only).
    if (!$stats_data) {
        $stmt_group = $pdo->prepare("SELECT research_title, program, email FROM users WHERE user_id = ?");
        $stmt_group->execute([$effective_user_id]);
        $g = $stmt_group->fetch();

        $stmt_new = $pdo->prepare("INSERT INTO form_stat_treatment (user_id, research_title, course, contact_email, file_coded_data, status) VALUES (?, ?, ?, ?, ?, 'Phase 1: Pending Coded Data')");
        $stmt_new->execute([$effective_user_id, $g['research_title'], $g['program'], $g['email'], $item_id === 30 ? $filepath : null]);
    } elseif ($item_id === 30) {
        $pdo->prepare("UPDATE form_stat_treatment SET file_coded_data = ? WHERE user_id = ?")->execute([$filepath, $effective_user_id]);
    }
    if (in_array($current_status, SP_PHASE1, true)) {
        $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = NULL WHERE user_id = ? AND status = 'Phase 1: Coded Data Rejected'")
            ->execute([$effective_user_id]);
    }
    // Pending -> Review once all five are in and none is waiting on a fix (step 1 and the loop).
    sp_sync_reqs($pdo, (int)$effective_user_id);

    // Log to the group activity feed, linked to this upload version
    $item_name_stmt = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
    $item_name_stmt->execute([$item_id]);
    $item_label = $item_name_stmt->fetchColumn() ?: 'a statistics requirement';
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type) VALUES (?, ?, ?, ?, 'info')");
    $log_stmt->execute([$effective_user_id, $new_upload_id, 'Statistical Treatment Submission: ' . $item_label, 'You uploaded a new file: ' . htmlspecialchars($filename)]);

    stat_redirect("File uploaded successfully!", "success");
} else {
    header("Location: module_statistics.php");
    exit();
}
