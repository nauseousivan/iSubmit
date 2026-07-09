<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$me_role = $_SESSION['role'];

// Determine effective user ID for student (leader's ID if current user is member)
$stmt_user = $pdo->prepare("SELECT leader_id, username, program FROM users WHERE user_id = ?");
$stmt_user->execute([$user_id]);
$user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
$leader_id_for_current_user = $user_data["leader_id"];
$effective_user_id = $leader_id_for_current_user ?? $user_id;

$safe_username = preg_replace("/[^a-zA-Z0-9]+$/", "", preg_replace("/[^a-zA-Z0-9]+/", "_", trim($user_data["username"] ?? "Student")));
$safe_program = preg_replace("/[^a-zA-Z0-9]+$/", "", preg_replace("/[^a-zA-Z0-9]+/", "_", trim($user_data["program"] ?? "")));

// CSRF validation (proposal + stats + plagiarism flows). Other module contexts are unaffected for now.
function csrf_ok_for_proposal($context)
{
    if (!in_array($context, ['proposal', 'stats', 'plagiarism'], true)) {
        return true; // scope: proposal, stats and plagiarism flows only
    }
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $item_id = intval($_POST['item_id'] ?? 0);
    $module_context = $_POST['module_context'] ?? 'proposal';

    if (!csrf_ok_for_proposal($module_context)) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=" . urlencode('Security token expired. Please refresh and try again') . "&type=error");
        exit();
    }

    if ($item_id === 0) {
        header("Location: student.php?msg=Invalid item selection&type=error");
        exit();
    }

    if (!isset($_FILES['research_file']) || $_FILES['research_file']['error'] !== UPLOAD_ERR_OK) {
        $error_code = $_FILES['research_file']['error'] ?? 'No File';
        // Check size error
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            header("Location: student.php?module=" . urlencode($module_context) . "&msg=File size exceeds our 30MB limit&type=error");
        } else {
            header("Location: student.php?module=" . urlencode($module_context) . "&msg=Error uploading file&type=error");
        }
        exit();
    }

    $file = $_FILES['research_file'];
    $original_name = basename($file['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    $allowed_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed_exts)) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Unsupported file format&type=error");
        exit();
    }

    $upload_dir = '../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

        $item_name_stmt = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
    $item_name_stmt->execute([$item_id]);
    $raw_item_name = $item_name_stmt->fetchColumn() ?: "Document";
    $safe_item_name = preg_replace("/[^a-zA-Z0-9]+$/", "", preg_replace("/[^a-zA-Z0-9]+/", "_", trim($raw_item_name)));

    $year = date("Y");
    $program_part = $safe_program ? $safe_program . "_" : "";
    // Format: YYYY_Program_Username_ItemName.ext
    // Note: truncate username to 40 chars to avoid overly long names
    $short_username = substr($safe_username, 0, 40);
    $new_filename = $year . "_" . $program_part . $short_username . "_" . $safe_item_name . "." . $ext;
    $file_path = $upload_dir . $new_filename;
    
    // Overwrite original name so it looks pretty in DB and UI
    $original_name = $new_filename;

    // Plagiarism control-number filename override: the group's control number is generated
    // once (on first upload) and lives on plagiarism_checks, keyed by the leader, so every
    // re-upload/revision reuses the same number instead of minting a new one.
    if ($module_context === 'plagiarism' && $item_id === 4) {
        $pc_stmt = $pdo->prepare("SELECT formatted_control_no FROM plagiarism_checks WHERE user_id = ?");
        $pc_stmt->execute([$effective_user_id]);
        $pc_row = $pc_stmt->fetch();

        if ($pc_row && !empty($pc_row['formatted_control_no'])) {
            $formatted_control_no = $pc_row['formatted_control_no'];
        } else {
            // Dept/course abbreviation: mirrors the REAL, live logic used for STAT control
            // numbers (admin_module_dynamic.php's acknowledge_payment action) — keyed off the
            // student's actual program/course, not the institution-level `department` field
            // (that field stores full names like "International School of Asia and the
            // Pacific", which never literally contains the substring "ISAP").
            $stmt_leader_info = $pdo->prepare("SELECT program FROM users WHERE user_id = ?");
            $stmt_leader_info->execute([$effective_user_id]);
            $leader_info = $stmt_leader_info->fetch();

            $prog = strtoupper($leader_info['program'] ?? 'GEN');
            $dept = 'ISAP';
            if (strpos($prog, 'NURSING') !== false || strpos($prog, 'MEDICAL') !== false || strpos($prog, 'RADIOLOGIC') !== false || strpos($prog, 'PHARMACY') !== false || strpos($prog, 'MIDWIFERY') !== false || strpos($prog, 'DENTAL') !== false || strpos($prog, 'CAREGIVING') !== false) {
                $dept = 'MCNP';
            }

            $course_stopwords = ['BS', 'BA', 'AB', 'BSED', 'OF', 'IN', 'AND', 'THE'];
            $course_words = preg_split('/\s+/', trim($leader_info['program'] ?? ''));
            $course_significant = [];
            foreach ($course_words as $w) {
                if ($w !== '' && !in_array(strtoupper($w), $course_stopwords, true)) {
                    $course_significant[] = strtoupper($w);
                }
            }
            if (count($course_significant) === 1) {
                $course_abbr = $course_significant[0];
            } elseif (count($course_significant) > 1) {
                $course_abbr = '';
                foreach ($course_significant as $w) { $course_abbr .= $w[0]; }
            } else {
                $course_abbr = 'GEN';
            }

            $seq_stmt = $pdo->query("SELECT MAX(control_number_seq) FROM plagiarism_checks");
            $next_seq = intval($seq_stmt->fetchColumn()) + 1;
            $candidate_control_no = "PLAG-$dept-$course_abbr-" . str_pad($next_seq, 3, '0', STR_PAD_LEFT);

            // Race-safe insert-if-not-exists: UNIQUE KEY(user_id) means a concurrent second
            // member's upload either wins or loses this INSERT IGNORE; the re-SELECT below
            // always returns whichever row actually won, so both requests end up naming their
            // file identically.
            $pdo->prepare("INSERT IGNORE INTO plagiarism_checks (user_id, control_number_seq, formatted_control_no) VALUES (?, ?, ?)")
                ->execute([$effective_user_id, $next_seq, $candidate_control_no]);

            $pc_stmt->execute([$effective_user_id]);
            $formatted_control_no = $pc_stmt->fetchColumn();
        }

        $safe_control_no = preg_replace("/[^a-zA-Z0-9\-]+$/", "", preg_replace("/[^a-zA-Z0-9\-]+/", "_", trim($formatted_control_no)));
        $new_filename = $safe_control_no . "_" . $short_username . "." . $ext;
        $file_path = $upload_dir . $new_filename;
        $original_name = $new_filename;
    }

    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Insert into uploads database as 'Pending'
        $stmt = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status) VALUES (?, ?, ?, ?, 'Pending')");
        if ($stmt->execute([$effective_user_id, $item_id, $file_path, $original_name])) {
            $new_upload_id = $pdo->lastInsertId();

            // Supersede-on-reupload: remove any OTHER un-reviewed 'Pending' drafts for this item so at most
            // one Pending version exists at a time. Reviewed versions (Under Review / Approved /
            // Revision Requested) are preserved as real submission history.
            $stale_stmt = $pdo->prepare("SELECT upload_id, file_path FROM uploads WHERE user_id = ? AND item_id = ? AND verification_status = 'Pending' AND upload_id != ?");
            $stale_stmt->execute([$effective_user_id, $item_id, $new_upload_id]);
            foreach ($stale_stmt->fetchAll() as $stale) {
                $del_stale = $pdo->prepare("DELETE FROM uploads WHERE upload_id = ?");
                $del_stale->execute([$stale['upload_id']]);
                // Only delete the physical file if no surviving row still references that path.
                // Filenames are deterministic (YYYY_Program_User_Item.ext), so a same-extension re-upload
                // overwrites the same file the new row now points to — we must not delete that.
                $ref_stmt = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE file_path = ?");
                $ref_stmt->execute([$stale['file_path']]);
                if ($ref_stmt->fetchColumn() == 0 && !empty($stale['file_path']) && file_exists($stale['file_path'])) {
                    @unlink($stale['file_path']);
                }
            }

            // Insert log action to group activities feed
            $item_name_stmt = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
            $item_name_stmt->execute([$item_id]);
            $item_name = $item_name_stmt->fetchColumn() ?: 'a file';

            // Link the log to the upload so the audit trail reconciles to a specific version
            // (FK is ON DELETE SET NULL, so deleting a pending draft simply nulls this reference)
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type) VALUES (?, ?, ?, ?, 'info')");
            $log_stmt->execute([$effective_user_id, $new_upload_id, 'File Submitted: ' . $item_name, 'You uploaded a new file: ' . htmlspecialchars($original_name)]);

            header("Location: student.php?module=" . urlencode($module_context) . "&msg=File successfully submitted for verification&type=success");
        } else {
            header("Location: student.php?module=" . urlencode($module_context) . "&msg=Database error while recording upload&type=error");
        }
    } else {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Failed to save uploaded file&type=error");
    }
    exit();
}

// Handle delete upload action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_upload') {
    $upload_id = intval($_POST['upload_id'] ?? 0);
    $module_context = $_POST['module_context'] ?? 'proposal';

    if (!csrf_ok_for_proposal($module_context)) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=" . urlencode('Security token expired. Please refresh and try again') . "&type=error");
        exit();
    }

    if ($upload_id === 0) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Invalid file deletion request&type=error");
        exit();
    }

    // Verify ownership and capture details BEFORE deletion (item_name lookup must happen while the row still exists)
    $stmt_find = $pdo->prepare("SELECT u.item_id, u.file_path, u.original_filename, u.verification_status, ci.item_name
                                FROM uploads u
                                LEFT JOIN checklist_items ci ON u.item_id = ci.item_id
                                WHERE u.upload_id = ? AND u.user_id = ?");
    $stmt_find->execute([$upload_id, $effective_user_id]);
    $upload_data = $stmt_find->fetch();

    if (!$upload_data) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Access Denied or File Not Found&type=error");
        exit();
    }

    $item_name = $upload_data['item_name'] ?: 'a file';

    // Guard: only a Pending (not yet reviewed) submission may be deleted.
    // Once a reviewer has acted (Under Review / Approved / Revision Requested) the record is locked into history.
    if ($upload_data['verification_status'] !== 'Pending') {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=" . urlencode('This submission is already under review and can no longer be deleted') . "&type=error");
        exit();
    }

    // Delete from database (status re-checked in SQL to avoid a race with a concurrent review)
    $stmt_del = $pdo->prepare("DELETE FROM uploads WHERE upload_id = ? AND user_id = ? AND verification_status = 'Pending'");
    $stmt_del->execute([$upload_id, $effective_user_id]);

    if ($stmt_del->rowCount() > 0) {
        // Delete file physically if it exists
        if (file_exists($upload_data['file_path'])) {
            unlink($upload_data['file_path']);
        }

        // Stats module: removing a pending draft must also rewind the group's phase so the
        // statistician queue does not keep a phantom "waiting for review" state.
        $deleted_item_id = (int)$upload_data['item_id'];
        if (in_array($deleted_item_id, [30, 31, 32, 33, 34, 35, 36, 37], true)) {
            if ($deleted_item_id === 30) {
                $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 1: Pending Coded Data', file_coded_data = NULL
                               WHERE user_id = ? AND status = 'Phase 1: Coded Data Review'")
                    ->execute([$effective_user_id]);
            } elseif (in_array($deleted_item_id, [36, 37], true)) {
                $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 2: Form Download'
                               WHERE user_id = ? AND status = 'Phase 4: Payment Verification'")
                    ->execute([$effective_user_id]);
            } else { // deliverables 31-35
                $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 5: Registered'
                               WHERE user_id = ? AND status = 'Phase 6: Under Review'")
                    ->execute([$effective_user_id]);
            }
        }

        // Insert log action to group activities feed (using activity_logs)
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, 'info')");
        $log_stmt->execute([$effective_user_id, 'File Removed: ' . $item_name, 'You removed a file: ' . htmlspecialchars($upload_data['original_filename'])]);

        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Upload successfully removed&type=success");
    } else {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=This submission is already under review and can no longer be deleted&type=error");
    }
    exit();
}

header("Location: student.php");
exit();
