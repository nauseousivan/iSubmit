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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_stat_requirement') {
    $item_id = intval($_POST['item_id']);
    
    if (!isset($_FILES['research_file']) || $_FILES['research_file']['error'] !== UPLOAD_ERR_OK) {
        header("Location: module_statistics.php?msg=" . urlencode("Failed to upload file. Please try again.") . "&type=error");
        exit();
    }
    
    $file = $_FILES['research_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
    
    if (!in_array($ext, $allowed)) {
        header("Location: module_statistics.php?msg=" . urlencode("Invalid file format. Only PDF, DOC, and Excel files are allowed.") . "&type=error");
        exit();
    }
    
    // Check current state in form_stat_treatment
    $stmt_form = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $stmt_form->execute([$effective_user_id]);
    $stats_data = $stmt_form->fetch();
    
    $control_no = $stats_data ? $stats_data['formatted_control_no'] : '';
    $dir = '../uploads/stats/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    // Determine filename
    if ($item_id == 30) {
        $filename = "InitialData_{$effective_user_id}_" . time() . ".$ext";
    } else {
        // Step 3 deliverables: auto-rename using control number if available
        $prefix = $control_no ? $control_no : "REQ_{$effective_user_id}";
        
        $item_names = [
            31 => 'SOP',
            32 => 'Questionnaire',
            33 => 'CodedData',
            34 => 'CommLetter',
            35 => 'MOM'
        ];
        $doc_name = $item_names[$item_id] ?? "Item$item_id";
        $filename = "{$prefix}_{$doc_name}_" . time() . ".$ext";
    }
    
    $filepath = $dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Save to uploads table standardly
        $stmt_check = $pdo->prepare("SELECT upload_id FROM uploads WHERE user_id = ? AND item_id = ?");
        $stmt_check->execute([$effective_user_id, $item_id]);
        
        if ($stmt_check->rowCount() > 0) {
            $stmt_update = $pdo->prepare("UPDATE uploads SET file_path = ?, verification_status = 'Pending', remarks = NULL, uploaded_at = CURRENT_TIMESTAMP WHERE user_id = ? AND item_id = ?");
            $stmt_update->execute([$filepath, $effective_user_id, $item_id]);
        } else {
            $stmt_insert = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, verification_status) VALUES (?, ?, ?, 'Pending')");
            $stmt_insert->execute([$effective_user_id, $item_id, $filepath]);
        }
        
        // Custom State Logic for form_stat_treatment
        if ($item_id == 30) {
            if (!$stats_data) {
                // Fetch group data for new row
                $stmt_group = $pdo->prepare("SELECT research_title, program, email FROM users WHERE user_id = ?");
                $stmt_group->execute([$effective_user_id]);
                $g = $stmt_group->fetch();
                
                $stmt_new = $pdo->prepare("INSERT INTO form_stat_treatment (user_id, research_title, course, contact_email, file_coded_data, status) VALUES (?, ?, ?, ?, ?, 'Initial Data Uploaded')");
                $stmt_new->execute([$effective_user_id, $g['research_title'], $g['program'], $g['email'], $filepath]);
            } else {
                $stmt_upd_form = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Initial Data Uploaded', file_coded_data = ?, statistician_remarks = NULL WHERE user_id = ?");
                $stmt_upd_form->execute([$filepath, $effective_user_id]);
            }
        } elseif (in_array($item_id, [31, 32, 33, 34, 35])) {
            // Check if all 5 are uploaded
            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id IN (31,32,33,34,35)");
            $stmt_count->execute([$effective_user_id]);
            $count = $stmt_count->fetchColumn();
            
            if ($count == 5) {
                // Update overall state to Requirements Uploaded if not already beyond that
                if ($stats_data && $stats_data['status'] == 'Payment Acknowledged') {
                    $pdo->prepare("UPDATE form_stat_treatment SET status = 'Requirements Uploaded' WHERE user_id = ?")->execute([$effective_user_id]);
                }
            }
        }
        
        header("Location: module_statistics.php?msg=" . urlencode("File uploaded successfully!") . "&type=success");
        exit();
    } else {
        header("Location: module_statistics.php?msg=" . urlencode("Failed to move uploaded file.") . "&type=error");
        exit();
    }
} else {
    header("Location: module_statistics.php");
    exit();
}
