<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$me_role = $_SESSION['role'];

// Determine effective user ID for student (leader's ID if current user is member)
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $item_id = intval($_POST['item_id'] ?? 0);
    $module_context = $_POST['module_context'] ?? 'proposal';

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

    $new_filename = 'item_' . $item_id . '_' . $effective_user_id . '_' . time() . '.' . $ext;
    $file_path = $upload_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Insert into uploads database as 'Pending'
        $stmt = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status) VALUES (?, ?, ?, ?, 'Pending')");
        if ($stmt->execute([$effective_user_id, $item_id, $file_path, $original_name])) {            
            // Insert log action to group activities feed
            $item_name_stmt = $pdo->prepare("SELECT item_name FROM checklist_items WHERE item_id = ?");
            $item_name_stmt->execute([$item_id]);
            $item_name = $item_name_stmt->fetchColumn() ?: 'a file';
            
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, 'info')");
            $log_stmt->execute([$effective_user_id, 'File Submitted: ' . $item_name, 'You uploaded a new file: ' . htmlspecialchars($original_name)]);

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

    if ($upload_id === 0) {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Invalid file deletion request&type=error");
        exit();
    }

    // Verify ownership
    $stmt_find = $pdo->prepare("SELECT file_path, original_filename FROM uploads WHERE upload_id = ? AND user_id = ?");
    $stmt_find->execute([$upload_id, $effective_user_id]);
    $upload_data = $stmt_find->fetch();

    if ($upload_data) {
        // Delete from database
        $stmt_del = $pdo->prepare("DELETE FROM uploads WHERE upload_id = ?");
        if ($stmt_del->execute([$upload_id])) {
            // Delete file physically if it exists
            if (file_exists($upload_data['file_path'])) {
                unlink($upload_data['file_path']);
            }
            
            // Insert log action to group activities feed (using activity_logs)
            $item_name_stmt = $pdo->prepare("SELECT ci.item_name FROM uploads u JOIN checklist_items ci ON u.item_id = ci.item_id WHERE u.upload_id = ?");
            $item_name_stmt->execute([$upload_id]);
            $item_name = $item_name_stmt->fetchColumn() ?: 'a file';
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, 'info')");
            $log_stmt->execute([$effective_user_id, 'File Removed: ' . $item_name, 'You removed a file: ' . htmlspecialchars($upload_data['original_filename'])]);

            header("Location: student.php?module=" . urlencode($module_context) . "&msg=Upload successfully removed&type=success");
        } else {
            header("Location: student.php?module=" . urlencode($module_context) . "&msg=Error deleting record from database&type=error");
        }
    } else {
        header("Location: student.php?module=" . urlencode($module_context) . "&msg=Access Denied or File Not Found&type=error");
    }
    exit();
}

header("Location: student.php");
exit();
