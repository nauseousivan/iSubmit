<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];

// Determine effective user ID
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_stat_form') {
    $course = trim($_POST['course'] ?? '');
    $research_title = trim($_POST['research_title'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $main_or_number = trim($_POST['main_or_number'] ?? '');
    $proponents_raw = $_POST['proponents'] ?? [];
    
    // Process proponents
    $proponents = [];
    foreach ($proponents_raw as $p) {
        if (!empty(trim($p['name']))) {
            $proponents[] = [
                'name' => trim($p['name']),
                'or_number' => trim($p['or_number'] ?? '')
            ];
        }
    }
    
    // File upload handler
    function upload_req_file($file_input_name, $eff_user_id) {
        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$file_input_name];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
            if (in_array($ext, $allowed)) {
                $dir = '../uploads/stats/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = $file_input_name . '_' . $eff_user_id . '_' . time() . '.' . $ext;
                $filepath = $dir . $filename;
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    return $filepath;
                }
            }
        }
        return null;
    }

    $file_sop = upload_req_file('file_sop', $effective_user_id);
    $file_questionnaire = upload_req_file('file_questionnaire', $effective_user_id);
    $file_coded_data = upload_req_file('file_coded_data', $effective_user_id);
    $file_comm_letter = upload_req_file('file_comm_letter', $effective_user_id);
    $file_mom = upload_req_file('file_mom', $effective_user_id);

    if (!$file_sop || !$file_questionnaire || !$file_coded_data || !$file_comm_letter || !$file_mom) {
        header("Location: student.php?module=statistics&msg=Failed to upload one or more required files. Please ensure they are valid documents.&type=error");
        exit();
    }

    // Generate Control Number
    $dept = 'MCNP'; // default
    $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
    $stmt_dept->execute([$effective_user_id]);
    $u_dept = $stmt_dept->fetchColumn();
    if ($u_dept && strpos(strtoupper($u_dept), 'ISAP') !== false) {
        $dept = 'ISAP';
    }

    $course_abbr = 'PROG';
    if (!empty($course)) {
        $words = explode(' ', $course);
        $course_abbr = '';
        foreach($words as $w) {
            if (strlen($w) > 0 && strtoupper($w) !== 'BS' && strtoupper($w) !== 'OF' && strtoupper($w) !== 'IN') {
                $course_abbr .= strtoupper($w[0]);
            }
        }
        if (strlen($course_abbr) == 0) $course_abbr = 'PROG';
    }

    $year = date('Y');

    // Get next seq
    $stmt_seq = $pdo->query("SELECT MAX(control_number_seq) FROM form_stat_treatment");
    $next_seq = intval($stmt_seq->fetchColumn()) + 1;
    $seq_padded = str_pad($next_seq, 3, '0', STR_PAD_LEFT);

    $formatted_control_no = "STAT-$year-$dept-$course_abbr-$seq_padded";

    // Insert into form_stat_treatment
    $stmt_insert = $pdo->prepare("
        INSERT INTO form_stat_treatment 
        (control_number_seq, formatted_control_no, user_id, research_title, proponents, course, main_or_number, date_released, contact_email, contact_number, file_sop, file_questionnaire, file_coded_data, file_comm_letter, file_mom, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY), ?, ?, ?, ?, ?, ?, ?, 'Under Review')
    ");

    $stmt_insert->execute([
        $next_seq, $formatted_control_no, $effective_user_id, $research_title, json_encode($proponents), $course, $main_or_number, $contact_email, $contact_number, $file_sop, $file_questionnaire, $file_coded_data, $file_comm_letter, $file_mom
    ]);

    $new_form_id = $pdo->lastInsertId();

    // Also update users.research_title if not empty
    if (!empty($research_title)) {
        $stmt_u = $pdo->prepare("UPDATE users SET research_title = ? WHERE user_id = ?");
        $stmt_u->execute([$research_title, $effective_user_id]);
    }

    // Insert to uploads to maintain progress bar compatibility (item_id = 3)
    $stmt_up = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status) VALUES (?, 3, ?, ?, 'Under Review')");
    $stmt_up->execute([$effective_user_id, 'form_stat:' . $new_form_id, $formatted_control_no . ' Submission']);

    // Log action
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, 'info')");
    $log_stmt->execute([$effective_user_id, 'Statistical Treatment Form Submitted', 'You submitted the Statistical Treatment form with control number ' . $formatted_control_no]);

    header("Location: student.php?module=statistics&msg=Statistical Treatment Form submitted successfully! Expected release date is in 7 days.&type=success");
    exit();
}

header("Location: student.php");
exit();
