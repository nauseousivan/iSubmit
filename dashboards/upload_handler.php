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

// CSRF validation (proposal flow only). Other module contexts are unaffected for now.
function csrf_ok_for_proposal($context)
{
    if ($context !== 'proposal') {
        return true; // scope: proposal flow only
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
    $stmt_find = $pdo->prepare("SELECT u.file_path, u.original_filename, u.verification_status, ci.item_name
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
