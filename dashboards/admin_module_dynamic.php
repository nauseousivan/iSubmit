<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director', 'Statistician'])) {
    exit("Access Denied");
}

$role = $_SESSION['role'];
$message = "";

$phase = $_GET['phase'] ?? 'proposal';

if ($_SESSION['role'] === 'Statistician' && $phase !== 'stats') {
    exit("Access Denied");
}
$phase_map = [
    'proposal' => ['title' => 'Proposal Defense Validations', 'items' => [11, 12, 13, 14, 15, 16]],
    'final' => ['title' => 'Final Defense Validations', 'items' => [21, 22, 23, 24, 25, 26, 27]],
    'stats' => ['title' => 'Statistics Clearances', 'items' => [30, 31, 32, 33, 34, 35]],
    'plag' => ['title' => 'Plagiarism Scan Clearances', 'items' => [4]],
];

$current_phase = $phase_map[$phase] ?? $phase_map['proposal'];
$item_list = implode(',', $current_phase['items']);

// Define Form No. 008 Assessment Rubric Structure Natively
$rubric_sections = [
    "Clarity of Research Objectives" => [
        "q1" => "Are the research questions or objectives clearly articulated and well-defined?",
        "q2" => "Is there a clear and logical rationale for the study?"
    ],
    "Literature Review" => [
        "q3" => "Does the literature review demonstrate a thorough understanding of existing research in the field?",
        "q4" => "Is the literature review up-to-date and relevant to the proposed research?"
    ],
    "Theoretical Framework" => [
        "q5" => "Is there a well-developed theoretical framework guiding the research?",
        "q6" => "Does the theoretical framework align with the research questions and objectives?"
    ],
    "Research Design and Methodology" => [
        "q7" => "Is the research design appropriate for addressing the research questions or objectives?",
        "q8" => "Are the methods described in sufficient detail to allow for replication?",
        "q9" => "Is the sample size justified, and is the sampling method appropriate?"
    ],
    "Data Collection" => [
        "q10" => "Are the data collection methods clearly described and appropriate for the study?",
        "q11" => "Is there a plan for ensuring data validity and reliability?"
    ],
    "Data Analysis" => [
        "q12" => "Is the proposed data analysis approach suitable for answering the research questions?",
        "q13" => "Are statistical methods, if applicable, appropriate and correctly applied?"
    ],
    "Significance of the Study" => [
        "q14" => "Does the proposal clearly articulate the potential contributions of the research to the field?",
        "q15" => "Is there a discussion of the practical implications of the study?"
    ],
    "Feasibility" => [
        "q16" => "Are the resources (time, personnel, funding, etc.) required for the research realistically addressed?",
        "q17" => "Does the researcher have access to necessary data sources, equipment, or facilities?"
    ],
    "Ethical Considerations" => [
        "q18" => "Are ethical considerations in research adequately addressed?",
        "q19" => "Are there plans for obtaining informed consent and ensuring participant confidentiality?"
    ],
    "Presentation and Communication" => [
        "q20" => "Is the proposal well-organized and clearly written?",
        "q21" => "Are the ideas presented in a logical and coherent manner?",
        "q22" => "Is the language appropriate and accessible to a diverse audience?"
    ]
];

// Define Form No. 011 Assessment Rubric Structure for Statistician
$rubric_stats_sections = [
    "Deliverables Quality" => [
        "s1" => "Is the Statement of the Problem clear and well-defined?",
        "s2" => "Is the sample questionnaire correctly formatted and appropriate for the objectives?",
        "s3" => "Is the coded data in Excel complete and properly labeled?",
        "s4" => "Is the Communication Letter signed and approved by necessary authorities?",
        "s5" => "Are the Minutes of the Meeting coherent and accurately reflect the group's progress?"
    ],
    "Statistical Compliance" => [
        "s6" => "Are the statistical methods aligned with the proposed research design?",
        "s7" => "Are payment records and OR numbers validated?"
    ]
];

// Process Document Verifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'verify_upload') {
    $upload_id = $_POST['upload_id'];
    $status = $_POST['verification_status'];
    $remarks = trim($_POST['remarks']);
    $student_user_id = $_POST['student_user_id'];
    $req_name = $_POST['req_name'] ?? 'Document';
    $target_item_id = (int)($_POST['target_item_id'] ?? 0);

    // Form No. 008 / Form No. 011 Rubric Payload Elements Process Handling
    $rubric_payload = $_POST['rubric'] ?? [];
    $yes_count = 0;

    foreach ($rubric_payload as $q) {
        if (($q['val'] ?? '') === 'YES') {
            $yes_count++;
        }
    }

    // Mapping Institutional Guidelines Rules Set Criteria
    $decision_string = "Deferred";
    
    if ($target_item_id === 3) {
        // Stats Form 011 has 7 items
        if ($yes_count === 7) {
            $decision_string = "Accepted with no revision";
        } elseif ($yes_count >= 5) {
            $decision_string = "Accepted with minor revision";
        } elseif ($yes_count >= 2) {
            $decision_string = "Accepted with major revision";
        }
    } else {
        // Capsule Form 008 has 22 items
        if ($yes_count === 22) {
            $decision_string = "Accepted with no revision";
        } elseif ($yes_count >= 15) {
            $decision_string = "Accepted with minor revision";
        } elseif ($yes_count >= 8) {
            $decision_string = "Accepted with major revision";
        }
    }

    $json_rubric_data = !empty($rubric_payload) ? json_encode($rubric_payload) : null;

    // Pass to director queue if Coordinator approves
    if ($role === 'Research Coordinator') {
        $upload_status = ($status === 'Approved') ? 'Under Review' : $status;
    } else {
        $upload_status = $status;
    }

    // Persist Document Status and Matriculated Review Forms Elements Data Log
    $stmt = $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ?, form_008_data = ?, form_008_score = ?, form_008_decision = ? WHERE upload_id = ?");
    $stmt->execute([$upload_status, $remarks, $json_rubric_data, $yes_count, $decision_string, $upload_id]);

    $message = "Student compliance verification metrics logged successfully against Form No. 008.";

    if (in_array($target_item_id, [30, 31, 32, 33, 34, 35])) {
        $f_stmt = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
        $f_stmt->execute([$student_user_id]);
        $f_data = $f_stmt->fetch();
        
        if ($f_data) {
            $form_id = $f_data['form_id'];
            
            if ($target_item_id === 30) {
                // Initial Data Approval -> Move to Payment
                $new_state = ($upload_status === 'Approved') ? 'Waiting for Payment' : (($upload_status === 'Revision Requested' || $upload_status === 'revision') ? 'Initial Data Rejected' : 'Initial Data Uploaded');
                $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = ?, statistician_remarks = ? WHERE form_id = ?");
                $upd_stmt->execute([$new_state, $remarks, $form_id]);
            } else {
                // If any requirement 31-35 is approved, check if all 5 are approved
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id IN (31,32,33,34,35) AND verification_status = 'Approved'");
                $stmt_count->execute([$student_user_id]);
                if ($stmt_count->fetchColumn() == 5) {
                    $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Completed', statistician_remarks = ? WHERE form_id = ?");
                    $upd_stmt->execute([$remarks, $form_id]);
                } else {
                    $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ? WHERE form_id = ?");
                    $upd_stmt->execute([$remarks, $form_id]);
                }
            }
            
            // Email student
            if (!empty($f_data['contact_email'])) {
                $subject = "Statistical Treatment Update: " . $f_data['formatted_control_no'];
                $msg_body = "Your Statistical Treatment form (Control No: " . $f_data['formatted_control_no'] . ") has a new status update.\n\nStatus: " . $upload_status . "\nRemarks: " . $remarks . "\n\nPlease check your dashboard for more details.";
                $headers = "From: no-reply@mcnp-isap-research.edu\r\n";
                @mail($f_data['contact_email'], $subject, $msg_body, $headers);
            }
        }
    }

    // MILESTONE AUTO-LINKING CASCADE OPERATION: If Item 14 (Capsule Proposal) gets Approved by Director (or Coordinator in general)
    if ($target_item_id === 14 && $upload_status === 'Approved') {
        $cascade_items = [13, 15, 16]; // Auto-clear Form 008 (13), Data Gathering (15), and Lit Matrix (16)
        foreach ($cascade_items as $c_item) {
            $check_stmt = $pdo->prepare("SELECT upload_id FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
            $check_stmt->execute([$student_user_id, $c_item]);
            $existing_upload_id = $check_stmt->fetchColumn();

            if ($existing_upload_id) {
                $up_stmt = $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Automatically verified via Capsule Proposal Approval Matrix (Form No. 008).' WHERE upload_id = ?");
                $up_stmt->execute([$existing_upload_id]);
            } else {
                $ins_stmt = $pdo->prepare("INSERT INTO uploads (user_id, item_id, file_path, original_filename, verification_status, remarks, uploaded_at) VALUES (?, ?, '../uploads/cascaded_clearance.pdf', 'Cascaded_Institutional_Verification.pdf', 'Approved', 'Bundled milestone validation completed natively inside Capsule Proposal Form No. 008 approval.', CURRENT_TIMESTAMP)");
                $ins_stmt->execute([$student_user_id, $c_item]);
            }
        }
        $message .= " Milestone items 13, 15, and 16 have been auto-cleared and locked.";
    }

    // Log activity to notify the student
    $log_title = ($target_item_id === 14) ? "Form No. 008 Review - " . $status : "Document Review - " . $status;
    $log_desc = ($target_item_id === 14)
        ? "Your Capsule Proposal scored " . $yes_count . "/22. Review Outcome: " . $decision_string . ". Remarks: " . $remarks
        : "Your " . htmlspecialchars($req_name) . " has been reviewed by the " . $role . ". Status: " . $status . ".";

    $log_status = ($status === 'Approved') ? 'success' : 'warning';
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_user_id, $log_title, $log_desc, $log_status]);
}

// Handle Statistician Acknowledging Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'acknowledge_payment') {
    if ($role === 'Statistician') {
        $form_id = $_POST['form_id'];
        $control_no = trim($_POST['control_no']);
        $student_id = $_POST['student_id'];
        
        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Payment Acknowledged', formatted_control_no = ? WHERE form_id = ?");
        $upd_stmt->execute([$control_no, $form_id]);
        
        // Notify student
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Payment Acknowledged', 'Your statistical treatment payment has been acknowledged. Your Control Number is: $control_no. You may now upload the remaining deliverables.', 'success', CURRENT_TIMESTAMP)");
        $log_stmt->execute([$student_id]);
        
        $message = "Payment acknowledged successfully for Control No: $control_no";
    }
}

// Get filter values
$selected_department = $_GET['department'] ?? '';
$selected_program = $_GET['program'] ?? '';
$search_query = trim($_GET['search'] ?? '');
$sort_date = $_GET['sort_date'] ?? 'DESC';
$order_dir = ($sort_date === 'ASC') ? 'ASC' : 'DESC';

// Fetch distinct departments and programs for filters
$depts_stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $depts_stmt->fetchAll(PDO::FETCH_COLUMN);

$program_options = [
    "BS Radiologic Technology",
    "BS Nursing",
    "BS Medical Technology",
    "BS Physical Therapy",
    "BS Pharmacy",
    "BS Midwifery",
    "BS 2-year Dental Technology",
    "BS 2-year Pharmacy Aide",
    "BS Caregiving and TVET Course",
    "BS Information Technology",
    "BS Computer Engineering",
    "BS Business Administration",
    "BS Custom Administration",
    "BS Hospitality Management",
    "BS Tourism Management",
    "BS Accountancy",
    "BS Education",
    "BS Science Criminology",
    "BS Science in Social Work",
    "BS Secondary Education",
    "BS Science in Psychology",
    "BS Physical Education"
];
sort($program_options);

// Build WHERE clause for filtering
$where_clauses = [];
$params = [];
if ($selected_department) {
    $where_clauses[] = "u.department = ?";
    $params[] = $selected_department;
}
if ($selected_program) {
    $where_clauses[] = "u.program = ?";
    $params[] = $selected_program;
}
if ($search_query) {
    $where_clauses[] = "(u.username LIKE ? OR u.research_group_name LIKE ? OR u.department LIKE ? OR u.program LIKE ?)";
    $wildcard = "%" . $search_query . "%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}
$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : "";

// Fetch checklist items
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE item_id IN ($item_list) ORDER BY CASE WHEN item_id = 12 THEN 10.5 WHEN item_id = 13 THEN 14.1 WHEN item_id = 15 THEN 14.2 WHEN item_id = 16 THEN 14.3 ELSE item_id END ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Fetch ALL uploads (including historical ones) for this phase
$where_clauses_uploads = $where_clauses;
$where_clauses_uploads[] = "up.item_id IN ($item_list)";
$where_uploads_sql = "WHERE " . implode(' AND ', $where_clauses_uploads);

$uploads_query = "
    SELECT up.upload_id, up.item_id, up.file_path, up.original_filename, up.verification_status, up.remarks, up.uploaded_at,
           up.form_008_data, up.form_008_score, up.form_008_decision, u.department, u.program,
           u.email, u.username, u.research_group_name, u.user_id as student_user_id, u.profile_pic
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    $where_uploads_sql
    ORDER BY up.uploaded_at DESC
";
$uploads_stmt = $pdo->prepare($uploads_query);
$uploads_stmt->execute($params);
$all_uploads = $uploads_stmt->fetchAll(PDO::FETCH_ASSOC);

// Map uploads into array groups mapped by the requirement item ID
$uploads_by_item = [];
$uploads_history = [];

foreach ($all_uploads as $up) {
    $itemId = $up['item_id'];
    $studId = $up['student_user_id'];

    // Group ALL uploads by item and student for history tracking
    if (!isset($uploads_history[$itemId])) {
        $uploads_history[$itemId] = [];
    }
    if (!isset($uploads_history[$itemId][$studId])) {
        $uploads_history[$itemId][$studId] = [];
    }
    $uploads_history[$itemId][$studId][] = $up; // Ordered by uploaded_at DESC
}

// Populate $uploads_by_item with ONLY the latest upload per student per item
foreach ($uploads_history as $itemId => $students) {
    foreach ($students as $studId => $studentSubs) {
        $uploads_by_item[$itemId][] = $studentSubs[0];
    }
}

// Fetch all student groups for the Interactive SELECTOR feature (leaders only, where leader_id is NULL)
// We also apply the same department, program, and search filters so that the sidebar is in sync with the search filters!
$group_selector_clauses = $where_clauses;
$group_selector_clauses[] = "u.role = 'Student'";
$group_selector_clauses[] = "u.research_group_name IS NOT NULL";
$group_selector_clauses[] = "u.research_group_name != ''";
$group_selector_clauses[] = "u.leader_id IS NULL";

$group_selector_where_sql = "WHERE " . implode(' AND ', $group_selector_clauses);

$group_selector_query = "
    SELECT DISTINCT u.user_id, u.username, u.research_group_name, u.department, u.program, u.profile_pic, u.email
    FROM users u
    $group_selector_where_sql
    ORDER BY u.research_group_name ASC
";
$group_selector_stmt = $pdo->prepare($group_selector_query);
$group_selector_stmt->execute($params);
$all_student_groups = $group_selector_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate actual progress for each group in the current phase
$group_progress = [];
foreach ($all_student_groups as $g) {
    $g_id = $g['user_id'];
    $approved_count = 0;
    $total_items = count($checklist_items);

    foreach ($checklist_items as $item) {
        $itemId = $item['item_id'];
        if (isset($uploads_history[$itemId][$g_id])) {
            $latest_sub = $uploads_history[$itemId][$g_id][0];
            if ($latest_sub['verification_status'] === 'Approved') {
                $approved_count++;
            }
        }
    }

    $pct = $total_items > 0 ? round(($approved_count / $total_items) * 100) : 0;
    $group_progress[$g_id] = [
        'pct' => $pct,
        'approved' => $approved_count,
        'total' => $total_items
    ];
}

// Helper function to securely highlight search matches
function highlightSearchTerm($text, $term)
{
    $text = htmlspecialchars($text ?? '');
    if (!empty($term)) {
        $term_escaped = preg_quote(htmlspecialchars($term), '/');
        $text = preg_replace("/($term_escaped)/i", "<mark class='search-highlight'>$1</mark>", $text);
    }
    return $text;
}

$pending_payments = [];
if ($phase === 'stats') {
    $stmt_pay = $pdo->query("SELECT f.*, u.username, u.research_group_name, u.department, u.program 
                             FROM form_stat_treatment f 
                             JOIN users u ON f.user_id = u.user_id 
                             WHERE f.status = 'Waiting for Payment' ORDER BY f.date_submitted DESC");
    $pending_payments = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin - <?= htmlspecialchars($current_phase['title']) ?></title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Inline Styling representing High-End Glassmorphism and Elegant Accent Colors -->
    <style>
        :root {
            --mcnp-teal: #0c343d;
            --bg-beige: #f9f7f2;
            --border-line: #e3dec9;
            --ui-sans: 'Inter', system-ui, sans-serif;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #2563eb;
            --active-accent: #0c343d;
            --bg-card: #ffffff;
            --eagle-gold: #cc9900;
        }

        body {
            font-family: var(--ui-sans);
            padding: 30px;
            background: #fbf9f5;
            color: #1f2937;
            margin: 0;
            min-height: 100vh;
        }

        /* Watermark & Grid Background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(#e0dbc8 1.2px, transparent 1.2px),
                linear-gradient(to right, rgba(0, 0, 0, 0.01) 1px, transparent 1px);
            background-size: 24px 24px, 128px 128px;
            opacity: 0.6;
            pointer-events: none;
            z-index: -1;
        }

        .page-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2.5px solid var(--border-line);
            padding-bottom: 20px;
        }

        .page-title {
            font-family: 'Cinzel', serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--mcnp-teal);
            margin: 0 0 5px 0;
            letter-spacing: 0.5px;
        }

        .page-subtitle {
            font-size: 13.5px;
            color: #6b7280;
            margin: 0;
        }

        /* Time & Date Display Widget for Administrators */
        .admin-time-widget {
            background: var(--bg-card);
            border: 1.5px solid var(--border-line);
            padding: 12px 18px;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(12, 52, 61, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
            font-family: 'JetBrains Mono', monospace;
        }

        .time-badge {
            background: var(--mcnp-teal);
            color: white;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: bold;
        }

        .time-digits {
            font-size: 15px;
            font-weight: 700;
            color: var(--mcnp-teal);
        }

        /* Workspace Grid Layout */
        .workspace-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Student Group Directory Selector Box */
        .group-sidebar {
            background: var(--bg-card);
            border: 1.5px solid var(--border-line);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(12, 52, 61, 0.03);
            display: flex;
            flex-direction: column;
            gap: 14px;
            max-height: calc(100vh - 180px);
            overflow-y: auto;
        }

        .sidebar-title {
            font-family: 'Cinzel', serif;
            font-size: 13.5px;
            font-weight: 800;
            color: var(--mcnp-teal);
            margin: 0;
            padding-bottom: 8px;
            border-bottom: 1.5px solid var(--border-line);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .group-select-item {
            background: #faf8f3;
            border: 1px solid var(--border-line);
            border-radius: 12px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-select-item:hover,
        .group-select-item.active {
            background: #f1ebd9;
            border-color: var(--mcnp-teal);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(12, 52, 61, 0.06);
        }

        .group-select-item.active {
            border-left: 4px solid var(--mcnp-teal);
        }

        .mini-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #ffffff;
            border: 1px solid var(--border-line);
            object-fit: cover;
        }

        .mini-group-name {
            font-size: 12px;
            font-weight: 700;
            color: var(--mcnp-teal);
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 190px;
        }

        .mini-group-lead {
            font-size: 11px;
            color: #6b7280;
            margin: 0;
        }

        /* Filter Bar */
        .filter-bar {
            background: #faf8f4;
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1.5px solid var(--border-line);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.01);
        }

        .filter-bar form {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            min-width: 250px;
            display: flex;
            align-items: center;
            background: white;
            border: 1.5px solid var(--border-line);
            border-radius: 10px;
            padding: 0 14px;
            transition: 0.25s;
        }

        .search-input-wrapper:focus-within {
            border-color: var(--mcnp-teal);
            box-shadow: 0 0 0 3px rgba(12, 52, 61, 0.08);
        }

        .search-input-wrapper input[type="text"] {
            border: none;
            outline: none;
            padding: 11px 5px;
            width: 100%;
            font-size: 13.5px;
            font-family: inherit;
            background: transparent;
        }

        .filter-bar select {
            padding: 11px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border-line);
            font-size: 13.5px;
            font-family: inherit;
            background: white;
            max-width: 190px;
            outline: none;
            transition: 0.25s;
            cursor: pointer;
        }

        .filter-bar select:focus {
            border-color: var(--mcnp-teal);
        }

        .btn-clear {
            font-size: 12px;
            color: var(--danger);
            text-decoration: none;
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 10px;
            background: #fee2e2;
            transition: 0.25s;
            white-space: nowrap;
        }

        .btn-clear:hover {
            background: #fca5a5;
        }

        .search-highlight {
            background-color: #fef08a;
            color: #854d0e;
            padding: 0 3px;
            border-radius: 3px;
            font-weight: bold;
        }

        .alert {
            background: #ecfdf5;
            color: #065f46;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 600;
            border-left: 5px solid var(--success);
            box-shadow: 0 4px 10px rgba(5, 150, 105, 0.05);
        }

        .req-card {
            background: white;
            border: 1.5px solid var(--border-line);
            border-radius: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.02);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .req-card:hover {
            transform: translateY(-2px);
        }

        .req-header {
            background: #faf8f4;
            padding: 20px 24px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.25s;
            border-bottom: 1.5px solid transparent;
        }

        .req-header:hover {
            background: #efebdc;
        }

        .req-header.active {
            border-bottom-color: var(--border-line);
        }

        .req-title {
            font-family: 'Cinzel', Georgia, serif;
            font-size: 15.5px;
            font-weight: 800;
            color: var(--mcnp-teal);
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: 0.3px;
        }

        .req-meta {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .badge {
            background: var(--mcnp-teal);
            color: white;
            padding: 5px 12px;
            border-radius: var(--radius-pill);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .chevron {
            transition: transform 0.3s ease;
        }

        .req-header.active .chevron {
            transform: rotate(180deg);
        }

        .req-body {
            display: none;
            padding: 0;
            background: white;
        }

        .req-body.active {
            display: block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px 24px;
            border-bottom: 1.5px solid var(--border-line);
            text-align: left;
            font-size: 13.5px;
            vertical-align: middle;
        }

        th {
            background: #faf8f5;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.05em;
        }

        .status-pill {
            padding: 5px 12px;
            border-radius: var(--radius-pill);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-pill.pending {
            background: #f3f4f6;
            color: #4b5563;
            border: 1.5px solid #e5e7eb;
        }

        .status-pill.review {
            background: #fef3c7;
            color: #92400e;
            border: 1.5px solid #fcd34d;
        }

        .status-pill.approved {
            background: #d1fae5;
            color: #065f46;
            border: 1.5px solid #6ee7b7;
        }

        .status-pill.revision {
            background: #fee2e2;
            color: #991b1b;
            border: 1.5px solid #fca5a5;
        }

        .status-filter-tab {
            background: #faf8f4;
            color: var(--text-muted);
            border: 1px solid var(--border-line) !important;
            padding: 8px 16px;
            border-radius: 8px;
            font-family: var(--ui-sans);
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-filter-tab:hover {
            background: #f3eee3 !important;
            color: var(--mcnp-teal) !important;
            border-color: var(--mcnp-teal) !important;
        }

        .status-filter-tab.active {
            background: var(--mcnp-teal) !important;
            color: white !important;
            border-color: var(--mcnp-teal) !important;
            box-shadow: 0 4px 12px rgba(12, 52, 61, 0.15) !important;
        }

        select,
        input[type="text"] {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border-line);
            font-size: 13.5px;
            font-family: inherit;
            width: 100%;
            outline: none;
            background: #fafafa;
            transition: border-color 0.2s;
        }

        select:focus,
        input[type="text"]:focus {
            border-color: var(--mcnp-teal);
            background: white;
        }

        .action-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-update {
            background: var(--mcnp-teal);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12.5px;
            font-weight: bold;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-update:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(12, 52, 61, 0.15);
        }

        .empty-state {
            padding: 50px 30px;
            text-align: center;
            color: #6b7280;
            font-size: 14.5px;
        }

        .file-link {
            color: var(--info);
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-family: inherit;
            font-size: inherit;
        }

        .file-link:hover {
            text-decoration: underline;
        }

        /* Modal Layout Structures Enhancements for Split Window Console Panels */
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(12, 52, 61, 0.4);
            backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .preview-content {
            background: white;
            width: 96vw;
            height: 96vh;
            border-radius: 24px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 65px -10px rgba(12, 52, 61, 0.35);
            border: 1.5px solid var(--border-line);
        }

        .preview-header {
            padding: 16px 28px;
            background: var(--mcnp-teal);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close-preview {
            cursor: pointer;
            font-size: 28px;
            font-weight: bold;
            color: #f3efe4;
            transition: color 0.2s;
        }

        .close-preview:hover {
            color: var(--eagle-gold);
        }

        .preview-body {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        .preview-frame {
            flex: 1.3;
            border: none;
            background: #cbd5e1;
            height: 100%;
        }

        .preview-sidebar {
            flex: 0.7;
            padding: 28px;
            background: var(--bg-beige);
            overflow-y: auto;
            border-left: 1.5px solid var(--border-line);
            height: 100%;
        }

        .action-form-modal {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .action-form-modal select,
        .action-form-modal input {
            width: 100%;
            padding: 12px;
            font-size: 13.5px;
            border-radius: 10px;
            border: 1.5px solid var(--border-line);
            background: white;
        }

        .action-form-modal button,
        .action-form-modal a {
            padding: 14px;
            font-size: 13.5px;
            border-radius: 10px;
            font-weight: bold;
            text-decoration: none;
            border: none;
            text-align: center;
        }

        /* Accordion Style Criteria Rows for Form No. 008 Rubrics */
        .rubric-accordion-btn {
            width: 100%;
            background: white;
            border: 1.5px solid var(--border-line);
            padding: 12px 18px;
            border-radius: 10px;
            text-align: left;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--mcnp-teal);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            transition: all 0.2s;
        }

        .rubric-accordion-btn:hover {
            background: #faf8f4;
        }

        .rubric-accordion-content {
            display: none;
            padding: 18px;
            background: white;
            border: 1.5px solid var(--border-line);
            border-top: none;
            border-radius: 0 0 12px 12px;
            margin-top: -6px;
            margin-bottom: 12px;
            flex-direction: column;
            gap: 14px;
        }

        .rubric-accordion-content.open {
            display: flex;
        }

        .criteria-item-block {
            border-bottom: 1px dashed var(--border-line);
            padding-bottom: 12px;
        }

        .criteria-item-block:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .criteria-text {
            font-size: 12.5px;
            line-height: 1.5;
            color: #374151;
            font-weight: 500;
            margin: 0 0 10px 0;
        }

        .radio-options-row {
            display: flex;
            gap: 22px;
            align-items: center;
            margin-bottom: 8px;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
        }

        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }

        /* Glassmorphic Active Profile Card */
        .group-profile-card {
            background: linear-gradient(135deg, #faf8f3 0%, #f6f1e5 100%);
            border: 1.5px solid var(--border-line);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 8px 30px rgba(12, 52, 61, 0.04);
            display: none;
            animation: slideDownIn 0.4s ease;
        }

        @keyframes slideDownIn {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pfp-large {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 2px solid var(--mcnp-teal);
            object-fit: cover;
            background: white;
        }

        .shimmer-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            width: 100%;
            margin-top: 8px;
        }

        .shimmer-progress {
            height: 100%;
            background: var(--mcnp-teal);
            border-radius: 3px;
            width: 0%;
            transition: width 0.6s ease;
        }
    </style>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>

    <div class="page-header">
        <div>
            <h1 class="page-title"><?= htmlspecialchars($current_phase['title']) ?></h1>
            <p class="page-subtitle">Manage, review, and approve individual prerequisite documents uploaded by research groups.</p>
        </div>

        <!-- Live Real-Time clock widget for Admins -->
        <div class="admin-time-widget">
            <i data-lucide="clock" style="width: 18px; height: 18px; color: var(--mcnp-teal);"></i>
            <div class="time-digits" id="dashboardClock">00:00:00</div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert font-bold">
            <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 8px;"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="">
            <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">

            <div class="search-input-wrapper">
                <i data-lucide="search" style="color: #9ca3af; margin-right: 8px; width: 18px; height: 18px;"></i>
                <input type="text" name="search" placeholder="Search groups, students, programs..." value="<?= htmlspecialchars($search_query) ?>" onchange="this.form.submit()">
            </div>

            <select name="department" onchange="this.form.submit()">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>" <?= ($selected_department === $dept) ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="program" onchange="this.form.submit()">
                <option value="">All Programs</option>
                <?php foreach ($program_options as $prog): ?>
                    <option value="<?= htmlspecialchars($prog) ?>" <?= ($selected_program === $prog) ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort_date" onchange="this.form.submit()">
                <option value="DESC" <?= ($sort_date === 'DESC') ? 'selected' : '' ?>>Sort: Newest First</option>
                <option value="ASC" <?= ($sort_date === 'ASC') ? 'selected' : '' ?>>Sort: Oldest First</option>
            </select>
            <a href="admin_module_dynamic.php?phase=<?= htmlspecialchars($phase) ?>" class="btn-clear flex items-center justify-center gap-1">Reset Query</a>
        </form>
    </div>

    <!-- MAIN EXQUISITE GRID -->
    <div class="workspace-grid">

        <!-- SIDEBAR: Interactive Selector Directory -->
        <aside class="group-sidebar">
            <h3 class="sidebar-title">Select Student Group</h3>

            <!-- Sidebar Quick Search -->
            <div class="sidebar-search-container" style="position: relative; display: flex; align-items: center; background: #faf8f4; border: 1.5px solid var(--border-line); border-radius: 10px; padding: 8px 12px; margin-bottom: 2px;">
                <i data-lucide="search" style="color: #9ca3af; width: 14px; height: 14px; margin-right: 8px; flex-shrink:0;"></i>
                <input type="text" id="sidebarGroupSearch" placeholder="Filter groups/students..." onkeyup="filterSidebarGroups()" style="background: transparent; border: none; outline: none; font-size: 11.5px; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark); font-family: var(--ui-sans);">
            </div>

            <div class="group-select-item active" onclick="selectStudentGroup(null)" id="sidebar-g-all" style="margin-bottom: 2px;">
                <div class="mini-avatar" style="display:flex; align-items:center; justify-content:center; background:var(--mcnp-teal); color:white; font-size:11px; font-weight:bold;">ALL</div>
                <div style="overflow:hidden;">
                    <h4 class="mini-group-name">All Student Groups</h4>
                    <p class="mini-group-lead">Display all uploaded files</p>
                </div>
            </div>

            <div id="sidebarGroupsList" style="display: flex; flex-direction: column; gap: 10px;">
                <?php if (empty($all_student_groups)): ?>
                    <div style="font-size: 11px; color:#9ca3af; text-align:center; padding: 10px 0;">No students mapped.</div>
                <?php else: ?>
                    <?php foreach ($all_student_groups as $g):
                        $pfp = $g['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($g['username']);
                    ?>
                        <div class="group-select-item" onclick="selectStudentGroup(<?= htmlspecialchars(json_encode($g)) ?>)" id="sidebar-g-<?= $g['user_id'] ?>" data-group-name="<?= htmlspecialchars(strtolower($g['research_group_name'])) ?>" data-student-name="<?= htmlspecialchars(strtolower($g['username'])) ?>" data-program-name="<?= htmlspecialchars(strtolower($g['program'])) ?>">
                            <img src="<?= htmlspecialchars($pfp) ?>" class="mini-avatar">
                            <div style="overflow:hidden;">
                                <h4 class="mini-group-name"><?= htmlspecialchars($g['research_group_name']) ?></h4>
                                <p class="mini-group-lead">Leader: <?= htmlspecialchars($g['username']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- RIGHT PANEL: Action items & Active workspace -->
        <section class="main-content-panel">

            <!-- Dynamic Group Detail profile card (when selected) -->
            <div class="group-profile-card" id="activeProfileCard" style="display: none;">
                <div style="display:flex; gap:16px; align-items:start;">
                    <img id="cardPfp" src="" class="pfp-large">
                    <div style="flex:1;">
                        <h3 id="cardGroupName" style="color:var(--mcnp-teal); font-family:'Cinzel', serif; font-size:16px; margin:0 0 4px 0;">Group Research</h3>
                        <p id="cardLeader" style="font-size:12.5px; color:#4b5563; margin:0 0 2px 0; font-weight:600;">Leader:</p>
                        <p id="cardEmail" style="font-size:11.5px; color:#6b7280; margin:0 0 2px 0; font-family:'JetBrains Mono', monospace;"></p>
                        <p id="cardDetails" style="font-size:11px; color:#9ca3af; margin:0;"></p>
                    </div>

                    <div style="text-align:right; min-width: 140px;">
                        <span style="font-size:10px; font-weight:800; color:#7d7569; letter-spacing:0.5px; text-transform:uppercase;">Overall Checklist Complete</span>
                        <div style="font-size:15px; font-weight:800; color:var(--mcnp-teal); margin-top:2px;" id="cardProgressText">0%</div>
                        <div class="shimmer-bar">
                            <div class="shimmer-progress" id="cardProgressBar"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <div class="status-tabs-container" style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--border-line); padding-bottom: 14px; flex-wrap: wrap;">
                <button type="button" class="status-filter-tab active" data-filter="all" onclick="filterByStatus('all')">
                    <i data-lucide="layers" style="width: 14px; height: 14px;"></i> All Submissions
                </button>
                <button type="button" class="status-filter-tab" data-filter="pending" onclick="filterByStatus('pending')">
                    <i data-lucide="clock" style="width: 14px; height: 14px;"></i> Pending / Under Review
                </button>
                <button type="button" class="status-filter-tab" data-filter="approved" onclick="filterByStatus('approved')">
                    <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i> Approved
                </button>
                <button type="button" class="status-filter-tab" data-filter="revision" onclick="filterByStatus('revision')">
                    <i data-lucide="alert-circle" style="width: 14px; height: 14px;"></i> Revision Requested
                </button>
            </div>

            <?php if ($phase === 'stats' && count($pending_payments) > 0): ?>
                <div class="req-card" style="margin-bottom: 25px; border-left: 5px solid var(--warning);">
                    <div class="req-header" onclick="toggleReq('payment', this)">
                        <div class="req-title" style="color: var(--warning);">
                            <i data-lucide="banknote" style="width: 20px; height: 20px; color: var(--warning);"></i>
                            Pending Payments Acknowledgment
                        </div>
                        <div class="req-meta">
                            <span class="badge animate-pulse" style="background: var(--warning);"><?= count($pending_payments) ?> Action Needed</span>
                            <i data-lucide="chevron-down" class="chevron" style="width: 20px; height: 20px; color: var(--warning);"></i>
                        </div>
                    </div>
                    <div class="req-body" id="body-payment">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Research Group</th>
                                    <th style="width: 20%;">Student OR Number</th>
                                    <th style="width: 15%;">Status</th>
                                    <th style="width: 40%;">Review Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_payments as $pay): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--mcnp-teal); font-size: 14px;"><?= htmlspecialchars($pay['research_group_name']) ?></strong><br>
                                            <span style="color: #6b7280; font-size: 11px;"><?= htmlspecialchars($pay['department']) ?></span>
                                        </td>
                                        <td>
                                            <strong style="font-size: 13px;"><?= htmlspecialchars($pay['main_or_number'] ?: 'N/A') ?></strong>
                                        </td>
                                        <td><span class="status-pill review">Awaiting Acknowledgment</span></td>
                                        <td>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="action_type" value="acknowledge_payment">
                                                <input type="hidden" name="form_id" value="<?= $pay['form_id'] ?>">
                                                <input type="hidden" name="student_id" value="<?= $pay['user_id'] ?>">
                                                <input type="text" name="control_no" placeholder="Assign Control No (e.g. STAT-2026-MCNP-001)" required style="flex:1;">
                                                <button type="submit" class="btn-update" style="background: var(--warning);">Acknowledge & Unlock</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($checklist_items as $item):
                $item_id = $item['item_id'];
                $submissions = $uploads_by_item[$item_id] ?? [];

                $pending_count = 0;
                foreach ($submissions as $sub) {
                    if ($role === 'Research Coordinator' && $sub['verification_status'] === 'Pending') $pending_count++;
                    if ($role === 'Research Director' && $sub['verification_status'] === 'Under Review') $pending_count++;
                }

                $is_cascaded = in_array($item_id, [13, 15, 16]);
                $card_style = $is_cascaded ? 'width: 100%; border-left: 5px solid var(--info);' : '';
            ?>
                <div class="req-card" style="<?= $card_style ?>">
                    <div class="req-header" onclick="toggleReq(<?= $item_id ?>, this)">
                        <div class="req-title">
                            <i data-lucide="file-text" style="width: 20px; height: 20px; color: var(--mcnp-teal);"></i>
                            <?= htmlspecialchars($item['item_name']) ?>
                        </div>
                        <div class="req-meta">
                            <?php if ($pending_count > 0): ?>
                                <span class="badge animate-pulse" style="background: var(--danger);"><?= $pending_count ?> Action Needed</span>
                            <?php endif; ?>
                            <?php if (!$is_cascaded): ?><span class="badge"><?= count($submissions) ?> Uploads</span><?php endif; ?>
                            <i data-lucide="chevron-down" class="chevron" style="width: 20px; height: 20px; color: var(--mcnp-teal);"></i>
                        </div>
                    </div>

                    <div class="req-body" id="body-<?= $item_id ?>">
                        <?php if ($is_cascaded): ?>
                            <div class="empty-state" style="padding: 25px; color: var(--info); background: #f8fafc;">
                                <i data-lucide="info" style="width: 24px; height: 24px; margin-bottom: 8px; color: var(--info);"></i><br>
                                This requirement is evaluated natively inside the <b>Complete Capsule Proposal</b>.<br>Approving the Capsule Proposal automatically clears and locks this requirement.
                            </div>
                        <?php elseif (empty($submissions)): ?>
                            <div class="empty-state">
                                <i data-lucide="folder-open" style="width: 32px; height: 32px; color: #9ca3af; margin-bottom:6px;"></i><br>
                                No student groups have uploaded this requirement yet.
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Research Group</th>
                                        <th style="width: 20%;">Document</th>
                                        <th style="width: 15%;">Status</th>
                                        <th style="width: 40%;">Review Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $sub):
                                        $pill_class = 'pending';
                                        if ($sub['verification_status'] === 'Approved') $pill_class = 'approved';
                                        if ($sub['verification_status'] === 'Under Review') $pill_class = 'review';
                                        if ($sub['verification_status'] === 'Revision Requested') $pill_class = 'revision';
                                    ?>
                                        <tr class="sub-row-g-<?= $sub['student_user_id'] ?>">
                                            <td>
                                                <strong style="color: var(--mcnp-teal); font-size: 14px;"><?= highlightSearchTerm($sub['research_group_name'], $search_query) ?></strong><br>
                                                <span style="color: #6b7280; font-size: 11px;"><?= highlightSearchTerm($sub['department'], $search_query) ?></span><br>
                                                <span style="color: #6b7280; font-size: 11px;">Uploaded by <?= highlightSearchTerm($sub['username'], $search_query) ?></span>
                                                <?php if ($item_id == 14 && !empty($sub['form_008_decision'])): ?>
                                                    <div style="font-size:11px; margin-top:5px; color:var(--warning); font-weight:700;">Score: <?= $sub['form_008_score'] ?>/22 (<?= $sub['form_008_decision'] ?>)</div>
                                                <?php endif; ?>

                                                <?php
                                                $studId = $sub['student_user_id'];
                                                $group_subs = $uploads_history[$item_id][$studId] ?? [];
                                                if (count($group_subs) > 1):
                                                ?>
                                                    <div style="margin-top: 8px;">
                                                        <button type="button" class="history-toggle-btn" onclick="toggleHistory('hist-<?= $item_id ?>-<?= $studId ?>', this)" style="background: #f1ebd9; border: none; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; color: var(--mcnp-teal); cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i data-lucide="history" style="width: 12px; height: 12px;"></i> Show History (<?= count($group_subs) - 1 ?>)
                                                        </button>
                                                        <div id="hist-<?= $item_id ?>-<?= $studId ?>" class="history-content-box" style="display: none; margin-top: 8px; padding: 10px; background: #faf8f5; border: 1px solid var(--border-line); border-radius: 8px; font-size: 12px; max-height: 150px; overflow-y: auto; width: 100%;">
                                                            <div style="font-weight: 700; font-size: 9px; color: #6b7280; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.02em;">Previous Versions:</div>
                                                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px;">
                                                                <?php for ($i = 1; $i < count($group_subs); $i++):
                                                                    $prev = $group_subs[$i];
                                                                    $p_pill = 'pending';
                                                                    if ($prev['verification_status'] === 'Approved') $p_pill = 'approved';
                                                                    if ($prev['verification_status'] === 'Under Review') $p_pill = 'review';
                                                                    if ($prev['verification_status'] === 'Revision Requested') $p_pill = 'revision';
                                                                ?>
                                                                    <li style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 6px; border-bottom: 1px dashed #e3dec9; gap: 10px;">
                                                                        <div style="flex: 1;">
                                                                            <button type="button" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($prev)) ?>, '<?= htmlspecialchars($item['item_name']) ?>', <?= $item_id ?>)" style="background:none; border:none; cursor:pointer; font-weight:700; font-size:11.5px; padding:0; text-align:left; color: var(--info);" class="file-link">
                                                                                <i data-lucide="file" style="width: 11px; height: 11px; display:inline-block; vertical-align:middle; margin-right:3px;"></i>
                                                                                <?= htmlspecialchars($prev['original_filename']) ?>
                                                                            </button>
                                                                            <div style="font-size: 9.5px; color: #9ca3af; margin-top: 2px;"><?= date('M d, Y h:i A', strtotime($prev['uploaded_at'])) ?></div>
                                                                            <?php if (!empty($prev['remarks'])): ?>
                                                                                <div style="font-size: 10.5px; color: #64748b; font-style: italic; margin-top: 3px; background: #fffbeb; padding: 4px 8px; border-radius: 4px; border-left: 2px solid #f59e0b;">"<?= htmlspecialchars($prev['remarks']) ?>"</div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <span class="status-pill <?= $p_pill ?>" style="font-size: 8.5px; padding: 2px 6px; white-space: nowrap;"><?= htmlspecialchars($prev['verification_status']) ?></span>
                                                                    </li>
                                                                <?php endfor; ?>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($sub)) ?>, '<?= htmlspecialchars($item['item_name']) ?>', <?= $item_id ?>)" style="background:none; border:none; cursor:pointer;" class="file-link">
                                                    <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                                    View & Evaluate
                                                </button>
                                                <div style="font-size: 10px; color: #9ca3af; margin-top: 4px;"><?= date('M d, h:i A', strtotime($sub['uploaded_at'])) ?></div>
                                            </td>
                                            <td><span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($sub['verification_status']) ?></span></td>
                                            <td>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="action_type" value="verify_upload">
                                                    <input type="hidden" name="upload_id" value="<?= $sub['upload_id'] ?>">
                                                    <input type="hidden" name="student_user_id" value="<?= $sub['student_user_id'] ?>">
                                                    <input type="hidden" name="req_name" value="<?= htmlspecialchars($item['item_name']) ?>">
                                                    <input type="hidden" name="target_item_id" value="<?= $item_id ?>">

                                                    <select name="verification_status" style="width: 130px;" required>
                                                        <option value="" disabled selected>Action...</option>
                                                        <option value="Approved">Approve</option>
                                                        <option value="Revision Requested">Reject / Revise</option>
                                                    </select>
                                                    <input type="text" name="remarks" placeholder="Add feedback notes (optional)..." value="<?= htmlspecialchars($sub['remarks'] ?? '') ?>">
                                                    <button type="submit" class="btn-update">Update</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="no-records-row" style="display:none;">
                                        <td colspan="4" style="text-align:center; padding:30px; color:#6b7280; font-size:13.5px;">
                                            <i data-lucide="info" style="width: 20px; height: 20px; color:#9ca3af; display:inline-block; vertical-align:middle; margin-right:6px;"></i>
                                            No submission matches the selected group or status filter.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </section>
    </div>

    <!-- Premium Split Window Evaluation Console Modal -->
    <div class="preview-modal" id="docModal">
        <div class="preview-content">
            <div class="preview-header">
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="font-size:10px; text-transform:uppercase; font-weight:bold; opacity:0.85; letter-spacing:0.02em;">ISAP-QMS-DCO-RDC Form No: 008 (Research Proposal Review)</span>
                    <h4 id="modalTitle" style="margin:0; font-family:'Cinzel', serif; font-size:16px; font-weight:800;">Document Viewer Console</h4>
                </div>
                <span class="close-preview" onclick="closeDocumentModal()">&times;</span>
            </div>
            <div class="preview-body">
                <iframe class="preview-frame" id="previewFrame" src="about:blank"></iframe>
                <div class="preview-sidebar">
                    <h3 style="color:var(--mcnp-teal); font-family:'Cinzel', serif; font-size:16px; margin-bottom:15px; font-weight:800; border-bottom:2.5px solid var(--mcnp-teal); padding-bottom:6px;" id="modalReqName">Evaluate Document</h3>

                    <form method="POST" class="action-form-modal" id="modalForm">
                        <input type="hidden" name="action_type" value="verify_upload">
                        <input type="hidden" name="upload_id" id="modal_upload_id">
                        <input type="hidden" name="student_user_id" id="modal_student_id">
                        <input type="hidden" name="req_name" id="modal_req_name_input">
                        <input type="hidden" name="target_item_id" id="modal_target_item_id">

                        <div id="form008RubricBox" style="display:none; flex-direction:column; margin-bottom:15px; width:100%;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label style="font-size:11px; font-weight:800; color:#6b7280; text-transform:uppercase; margin:0; letter-spacing:0.02em;">Rubric Criteria Rubrics Checklist</label>
                                <button type="button" id="btnAIPrescore" class="btn-update" style="background:#8b5cf6; display:flex; align-items:center; gap:5px; padding:6px 10px; font-size:10px;" onclick="runAIPrescore()">
                                    <i data-lucide="sparkles" style="width:12; height:12;"></i> AI Pre-Score
                                </button>
                            </div>

                            <?php
                            $section_idx = 1;
                            foreach ($rubric_sections as $title => $questions): ?>
                                <button type="button" class="rubric-accordion-btn" onclick="toggleAccordion('sec-<?= $section_idx ?>', this)">
                                    <span><?= $title ?></span>
                                    <i data-lucide="chevron-down" style="width:14px; height:14px; color:var(--mcnp-teal);"></i>
                                </button>
                                <div class="rubric-accordion-content" id="sec-<?= $section_idx ?>">
                                    <?php foreach ($questions as $key => $label): ?>
                                        <div class="criteria-item-block">
                                            <p class="criteria-text"><?= $label ?></p>
                                            <div class="radio-options-row">
                                                <label class="radio-label" style="color:var(--success);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="YES" class="rubric-radio" onchange="runForm008Tally()" required> YES
                                                </label>
                                                <label class="radio-label" style="color:var(--danger);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="NO" class="rubric-radio" onchange="runForm008Tally()" required> NO
                                                </label>
                                            </div>
                                            <input type="text" name="rubric[<?= $key ?>][comment]" placeholder="Feedback notes for this row criteria..." style="padding:6px 10px; font-size:11px; margin-top:4px;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php
                                $section_idx++;
                            endforeach; ?>

                            <div style="background:#0c343d; color:white; padding:15px; border-radius:10px; display:flex; flex-direction:column; gap:6px; margin:10px 0; box-shadow:0 4px 10px rgba(12,52,61,0.15);">
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; border-bottom:1px solid rgba(255,255,255,0.15); padding-bottom:4px; justify-content:space-between;">
                                    <span>Calculated Tally:</span>
                                    <span id="form008LiveScore">0 / 22 Points (0%)</span>
                                </div>
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; justify-content:space-between;">
                                    <span>Decision Rule:</span>
                                    <span id="form008LiveDecision" style="color:#f59e0b; text-transform:uppercase;">Awaiting Form Input</span>
                                </div>
                            </div>
                        </div>

                        <div id="form011RubricBox" style="display:none; flex-direction:column; margin-bottom:15px; width:100%;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label style="font-size:11px; font-weight:800; color:#6b7280; text-transform:uppercase; margin:0; letter-spacing:0.02em;">Statistical Clearance Checklist (011)</label>
                            </div>

                            <?php
                            $section_idx_stat = 100;
                            foreach ($rubric_stats_sections as $title => $questions): ?>
                                <button type="button" class="rubric-accordion-btn" onclick="toggleAccordion('sec-<?= $section_idx_stat ?>', this)">
                                    <span><?= $title ?></span>
                                    <i data-lucide="chevron-down" style="width:14px; height:14px; color:var(--mcnp-teal);"></i>
                                </button>
                                <div class="rubric-accordion-content" id="sec-<?= $section_idx_stat ?>">
                                    <?php foreach ($questions as $key => $label): ?>
                                        <div class="criteria-item-block">
                                            <p class="criteria-text"><?= $label ?></p>
                                            <div class="radio-options-row">
                                                <label class="radio-label" style="color:var(--success);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="YES" class="rubric-radio stat-radio" onchange="runForm011Tally()" required> YES
                                                </label>
                                                <label class="radio-label" style="color:var(--danger);">
                                                    <input type="radio" name="rubric[<?= $key ?>][val]" value="NO" class="rubric-radio stat-radio" onchange="runForm011Tally()" required> NO
                                                </label>
                                            </div>
                                            <input type="text" name="rubric[<?= $key ?>][comment]" placeholder="Feedback notes for this row criteria..." style="padding:6px 10px; font-size:11px; margin-top:4px;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php
                                $section_idx_stat++;
                            endforeach; ?>

                            <div style="background:#155563; color:white; padding:15px; border-radius:10px; display:flex; flex-direction:column; gap:6px; margin:10px 0; box-shadow:0 4px 10px rgba(12,52,61,0.15);">
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; border-bottom:1px solid rgba(255,255,255,0.15); padding-bottom:4px; justify-content:space-between;">
                                    <span>Calculated Tally:</span>
                                    <span id="form011LiveScore">0 / 7 Points (0%)</span>
                                </div>
                                <div style="display:flex; justify-content:between; font-size:12px; font-weight:bold; justify-content:space-between;">
                                    <span>Decision Rule:</span>
                                    <span id="form011LiveDecision" style="color:#f59e0b; text-transform:uppercase;">Awaiting Form Input</span>
                                </div>
                            </div>
                        </div>

                        <label style="font-size:11px; font-weight:bold; color:#6b7280; text-transform:uppercase;">Final Form Action Status</label>
                        <select name="verification_status" id="modal_status" required>
                            <option value="" disabled selected>Select Evaluation...</option>
                            <option value="Approved">Approve Document</option>
                            <option value="Revision Requested">Reject / Request Revision</option>
                        </select>

                        <label style="font-size:11px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin-top:4px;">Feedback General Remarks</label>
                        <input type="text" name="remarks" id="modal_remarks" placeholder="Add feedback notes (optional)...">

                        <div style="margin-top:15px; display:flex; gap:10px; flex-direction:column;">
                            <button type="submit" class="btn-update" style="width:100%; padding:14px; font-size:13px;">Submit Evaluation</button>
                            <a id="modalDownloadBtn" href="#" download class="btn-update" style="background:#ffffff; color:var(--mcnp-teal); border:1.5px solid var(--border-line); shadow: none;">Download Original File</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Script Setup and Live UTC Real-time Sync Widgets -->
    <script>
        lucide.createIcons();

        // 1. Real-Time clock showing Time Metric for Admins
        function updateAdminClock() {
            const clockEl = document.getElementById('dashboardClock');
            if (clockEl) {
                const now = new Date();
                const phtime = now.toLocaleString("en-US", {
                    timeZone: "Asia/Manila",
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                clockEl.innerHTML = phtime;
            }
        }
        setInterval(updateAdminClock, 1000);
        updateAdminClock();

        const groupProgressData = <?= json_encode($group_progress) ?>;
        let activeGroupId = null; // null means "all"
        let activeStatusFilter = 'all'; // 'all', 'pending', 'approved', 'revision'

        // Sidebar search filtering function
        function filterSidebarGroups() {
            const query = document.getElementById('sidebarGroupSearch').value.toLowerCase().trim();
            const items = document.querySelectorAll('#sidebarGroupsList .group-select-item');

            items.forEach(item => {
                const groupName = item.getAttribute('data-group-name') || '';
                const studentName = item.getAttribute('data-student-name') || '';
                const programName = item.getAttribute('data-program-name') || '';

                if (groupName.includes(query) || studentName.includes(query) || programName.includes(query)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // 2. Select Student Group Profile function
        function selectStudentGroup(data) {
            // Remove active style from all sidebar items
            document.querySelectorAll('.group-select-item').forEach(el => el.classList.remove('active'));

            const activeProfileCard = document.getElementById('activeProfileCard');

            if (data === null) {
                activeGroupId = null;
                document.getElementById('sidebar-g-all').classList.add('active');
                activeProfileCard.style.display = 'none';
            } else {
                activeGroupId = data.user_id;
                document.getElementById('sidebar-g-' + data.user_id).classList.add('active');

                // Show dynamic detail profile card at top of content area
                activeProfileCard.style.display = 'block';

                // Update details
                const pfpUrl = data.profile_pic || 'https://api.dicebear.com/9.x/bottts/svg?seed=' + encodeURIComponent(data.username);
                document.getElementById('cardPfp').src = pfpUrl;
                document.getElementById('cardGroupName').textContent = data.research_group_name;
                document.getElementById('cardLeader').innerHTML = `<i data-lucide="user" style="width:14px; height:14px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> Primary Contact: ${data.username}`;
                document.getElementById('cardEmail').innerHTML = `<i data-lucide="mail" style="width:13px; height:13px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> ${data.email}`;
                document.getElementById('cardDetails').textContent = `${data.program} • ${data.department}`;

                // Update actual progress metrics
                const progressInfo = groupProgressData[data.user_id] || {
                    pct: 0,
                    approved: 0,
                    total: 0
                };
                document.getElementById('cardProgressText').textContent = progressInfo.pct + '% (' + progressInfo.approved + '/' + progressInfo.total + ' Approved)';
                document.getElementById('cardProgressBar').style.width = progressInfo.pct + '%';
            }

            filterTableRows();
            lucide.createIcons();
        }

        function filterByStatus(status) {
            activeStatusFilter = status;
            document.querySelectorAll('.status-filter-tab').forEach(btn => {
                if (btn.getAttribute('data-filter') === status) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            filterTableRows();
        }

        function filterTableRows() {
            // Loop through all tables and apply activeGroupId and activeStatusFilter
            document.querySelectorAll('table').forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                let visibleRowsCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.no-records-row)');

                rows.forEach(row => {
                    // Check Group Filter
                    let groupMatches = true;
                    if (activeGroupId !== null) {
                        groupMatches = row.classList.contains('sub-row-g-' + activeGroupId);
                    }

                    // Check Status Filter
                    let statusMatches = true;
                    if (activeStatusFilter !== 'all') {
                        const statusPill = row.querySelector('.status-pill');
                        if (statusPill) {
                            const statusText = statusPill.textContent.trim().toLowerCase();
                            if (activeStatusFilter === 'pending') {
                                statusMatches = statusText === 'pending' || statusText === 'under review';
                            } else if (activeStatusFilter === 'approved') {
                                statusMatches = statusText === 'approved';
                            } else if (activeStatusFilter === 'revision') {
                                statusMatches = statusText === 'revision requested' || statusText === 'revision needed';
                            }
                        }
                    }

                    if (groupMatches && statusMatches) {
                        row.style.display = '';
                        visibleRowsCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Handle no records row
                const noRecordsRow = tbody.querySelector('.no-records-row');
                if (noRecordsRow) {
                    if (visibleRowsCount === 0) {
                        noRecordsRow.style.display = '';
                    } else {
                        noRecordsRow.style.display = 'none';
                    }
                }
            });
        }

        function toggleHistory(id, btnEl) {
            const box = document.getElementById(id);
            if (box.style.display === 'none') {
                box.style.display = 'block';
                btnEl.innerHTML = `<i data-lucide="history" style="width: 12px; height: 12px;"></i> Hide History`;
            } else {
                box.style.display = 'none';
                btnEl.innerHTML = `<i data-lucide="history" style="width: 12px; height: 12px;"></i> Show History`;
            }
            lucide.createIcons();
        }

        function toggleReq(id, headerEl) {
            const body = document.getElementById('body-' + id);
            const icon = headerEl.querySelector('.chevron');
            if (!body.classList.contains('active')) {
                body.classList.add('active');
                headerEl.classList.add('active');
                if (icon) icon.style.transform = 'rotate(180deg)';
            } else {
                body.classList.remove('active');
                headerEl.classList.remove('active');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }

        function toggleAccordion(secId, btnEl) {
            const content = document.getElementById(secId);
            const icon = btnEl.querySelector('[data-lucide="chevron-down"]');
            if (content.classList.contains('open')) {
                content.classList.remove('open');
                if (icon) icon.style.transform = 'rotate(0deg)';
            } else {
                content.classList.add('open');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        }

        function runForm008Tally() {
            const selectedRadios = document.querySelectorAll('.rubric-radio:checked');
            let yesCounter = 0;

            selectedRadios.forEach(radio => {
                if (radio.value === 'YES') yesCounter++;
            });

            const totalQuestions = 22;
            const percentage = Math.round((yesCounter / totalQuestions) * 100);
            document.getElementById('form008LiveScore').textContent = `${yesCounter} / ${totalQuestions} Points (${percentage}%)`;

            let ruleLabel = "Deferred";
            let systemStatusSuggestion = "Revision Requested";

            if (yesCounter === 22) {
                ruleLabel = "Accepted with no revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 15) {
                ruleLabel = "Accepted with minor revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 8) {
                ruleLabel = "Accepted with major revision";
                systemStatusSuggestion = "Revision Requested";
            } else if (yesCounter >= 1) {
                ruleLabel = "Deferred";
                systemStatusSuggestion = "Revision Requested";
            }

            const txtElement = document.getElementById('form008LiveDecision');
            txtElement.textContent = ruleLabel;
            txtElement.style.color = (yesCounter >= 15) ? '#10b981' : ((yesCounter >= 8) ? '#f59e0b' : '#ef4444');

            document.getElementById('modal_status').value = systemStatusSuggestion;
        }

        function runForm011Tally() {
            const selectedRadios = document.querySelectorAll('.stat-radio:checked');
            let yesCounter = 0;

            selectedRadios.forEach(radio => {
                if (radio.value === 'YES') yesCounter++;
            });

            const totalQuestions = 7;
            const percentage = Math.round((yesCounter / totalQuestions) * 100);
            document.getElementById('form011LiveScore').textContent = `${yesCounter} / ${totalQuestions} Points (${percentage}%)`;

            let ruleLabel = "Deferred";
            let systemStatusSuggestion = "Revision Requested";

            if (yesCounter === 7) {
                ruleLabel = "Accepted with no revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 5) {
                ruleLabel = "Accepted with minor revision";
                systemStatusSuggestion = "Approved";
            } else if (yesCounter >= 2) {
                ruleLabel = "Accepted with major revision";
                systemStatusSuggestion = "Revision Requested";
            } else if (yesCounter >= 1) {
                ruleLabel = "Deferred";
                systemStatusSuggestion = "Revision Requested";
            }

            const txtElement = document.getElementById('form011LiveDecision');
            txtElement.textContent = ruleLabel;
            txtElement.style.color = (yesCounter >= 5) ? '#10b981' : ((yesCounter >= 2) ? '#f59e0b' : '#ef4444');

            document.getElementById('modal_status').value = systemStatusSuggestion;
        }

        function openDocumentModal(data, reqName, itemId) {
            document.getElementById('docModal').style.display = 'flex';
            document.getElementById('modalTitle').textContent = data.research_group_name + ' - ' + (data.original_filename || 'Document');
            document.getElementById('modalReqName').textContent = reqName;

            document.getElementById('modal_upload_id').value = data.upload_id;
            document.getElementById('modal_student_id').value = data.student_user_id;
            document.getElementById('modal_req_name_input').value = reqName;
            document.getElementById('modal_target_item_id').value = itemId;
            document.getElementById('modal_remarks').value = data.remarks || '';
            document.getElementById('modal_status').value = (data.verification_status === 'Approved' || data.verification_status === 'Revision Requested') ? data.verification_status : '';

            // Clean state parameters inside Form No. 008 checklist workspace blocks
            const rubricContainer = document.getElementById('form008RubricBox');
            const statRubricContainer = document.getElementById('form011RubricBox');
            const allRadioInputs = document.querySelectorAll('.rubric-radio');
            const allCommentInputs = document.querySelectorAll('input[type="text"][name^="rubric"]');

            allRadioInputs.forEach(r => { r.checked = false; r.required = false; });
            allCommentInputs.forEach(c => c.value = '');
            document.getElementById('form008LiveScore').textContent = "0 / 22 Points (0%)";
            document.getElementById('form008LiveDecision').textContent = "Awaiting Form Input";
            document.getElementById('form008LiveDecision').style.color = "#f59e0b";
            
            document.getElementById('form011LiveScore').textContent = "0 / 7 Points (0%)";
            document.getElementById('form011LiveDecision').textContent = "Awaiting Form Input";
            document.getElementById('form011LiveDecision').style.color = "#f59e0b";

            rubricContainer.style.display = 'none';
            statRubricContainer.style.display = 'none';

            let activeContainer = null;
            let runTallyFunc = null;

            if (parseInt(itemId) === 14) {
                rubricContainer.style.display = 'flex';
                activeContainer = rubricContainer;
                runTallyFunc = runForm008Tally;
                rubricContainer.querySelectorAll('.rubric-radio').forEach(r => r.required = true);
            } else if (parseInt(itemId) === 3) {
                statRubricContainer.style.display = 'flex';
                activeContainer = statRubricContainer;
                runTallyFunc = runForm011Tally;
                statRubricContainer.querySelectorAll('.rubric-radio').forEach(r => r.required = true);
            }

            if (activeContainer && data.form_008_data) {
                try {
                    const parsingRubric = JSON.parse(data.form_008_data);
                    Object.keys(parsingRubric).forEach(key => {
                        const valState = parsingRubric[key]['val'];
                        const commentText = parsingRubric[key]['comment'];

                        const radioMatch = activeContainer.querySelector(`input[name="rubric[${key}][val]"][value="${valState}"]`);
                        if (radioMatch) radioMatch.checked = true;

                        const textMatch = activeContainer.querySelector(`input[name="rubric[${key}][comment]"]`);
                        if (textMatch) textMatch.value = commentText || '';
                    });
                    if (runTallyFunc) runTallyFunc();
                } catch (e) {
                    console.error("Data tracking initialization failed", e);
                }
            }

            // Check if it's the custom Statistical Form
            let targetSrc = data.file_path;
            if (targetSrc && targetSrc.startsWith('form_stat:')) {
                const formId = targetSrc.split(':')[1];
                targetSrc = 'view_stat_form.php?id=' + formId;
                document.getElementById('modalDownloadBtn').style.display = 'none';
            } else {
                document.getElementById('modalDownloadBtn').style.display = 'inline-block';
                document.getElementById('modalDownloadBtn').href = targetSrc;
            }

            const iframe = document.getElementById('previewFrame');
            const fileExt = (data.file_path || '').split('.').pop().toLowerCase();
            
            if (!targetSrc.startsWith('view_stat_form.php') && ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(fileExt)) {
                iframe.removeAttribute('srcdoc');
                const pathname = window.location.pathname;
                const dashIndex = pathname.indexOf('/dashboards/');
                const base = dashIndex !== -1 ? pathname.substring(0, dashIndex) : '';
                const cleanPath = targetSrc.replace('../', '');
                const publicUrl = window.location.origin + base + '/' + cleanPath;
                iframe.src = 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(publicUrl);
            } else {
                iframe.removeAttribute('srcdoc');
                iframe.src = targetSrc;
            }
        }

        function closeDocumentModal() {
            document.getElementById('docModal').style.display = 'none';
            document.getElementById('previewFrame').src = 'about:blank';

            // Restore required validation properties back to fallback defaults
            document.querySelectorAll('.rubric-radio').forEach(r => r.required = true);
        }

        async function runAIPrescore() {
            const uploadId = document.getElementById('modal_upload_id').value;
            const btn = document.getElementById('btnAIPrescore');
            const originalText = btn.innerHTML;

            if (!uploadId) return alert("No document selected.");
            if (!confirm("This will analyze the document using AI and overwrite your current rubric selections. Proceed?")) return;

            btn.innerHTML = `<i data-lucide="loader" style="animation: spin 1s linear infinite; width:12; height:12;"></i> Analyzing...`;
            btn.disabled = true;
            lucide.createIcons();

            try {
                const response = await fetch('ai_prescore_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'upload_id=' + encodeURIComponent(uploadId)
                });
                const result = await response.json();

                if (result.success && result.data) {
                    Object.keys(result.data).forEach(key => {
                        const radioMatch = document.querySelector(`input[name="rubric[${key}][val]"][value="${result.data[key].val}"]`);
                        if (radioMatch) radioMatch.checked = true;
                        const textMatch = document.querySelector(`input[name="rubric[${key}][comment]"]`);
                        if (textMatch) textMatch.value = result.data[key].comment || '';
                    });
                    runForm008Tally(); // Auto-tally the new scores!
                    alert("AI Evaluation Complete! Please review and modify the suggestions.");
                } else {
                    alert("AI Error: " + (result.error || "Unknown error"));
                }
            } catch (err) {
                console.error(err);
                alert("Failed to connect to the AI Service.");
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                lucide.createIcons();
            }
        }
    </script>
</body>

</html>