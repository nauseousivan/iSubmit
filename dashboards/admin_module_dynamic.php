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
    'stats' => ['title' => 'Statistics Clearances', 'items' => [30, 31, 32, 33, 34, 35, 36, 37]],
    'plag' => ['title' => 'Plagiarism Scan Clearances', 'items' => [4]],
];

$current_phase = $phase_map[$phase] ?? $phase_map['proposal'];
$item_list = implode(',', $current_phase['items']);

// Statistician nav split: statistician.php now links here with a `view` param so its
// Statistics Clearance / Payment Verification / Release Results tabs each show only their
// own section. Coordinator/Director keep linking with no `view` param (defaults to 'all'),
// which preserves today's combined single-page behavior for them.
$stats_view = 'all';
if ($phase === 'stats') {
    $stats_view = $_GET['view'] ?? 'all';
    if (!in_array($stats_view, ['all', 'checklist', 'payments', 'release'], true)) {
        $stats_view = 'all';
    }
}

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
    // CSRF protection (proposal + stats flows): reject forged reviews.
    if (in_array($phase, ['proposal', 'stats'], true) && (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']))) {
        exit('Invalid security token. Please refresh and try again.');
    }
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

    if (in_array($target_item_id, [30, 31, 32, 33, 34, 35, 36, 37])) {
        $f_stmt = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
        $f_stmt->execute([$student_user_id]);
        $f_data = $f_stmt->fetch();

        if ($f_data) {
            $form_id = $f_data['form_id'];

            if ($target_item_id === 30) {
                // Initial Data Approval -> Move to Phase 2 Form Download
                $new_state = ($upload_status === 'Approved') ? 'Phase 2: Form Download' : (($upload_status === 'Revision Requested' || $upload_status === 'revision') ? 'Phase 1: Coded Data Rejected' : 'Phase 1: Pending Coded Data');
                $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = ?, statistician_remarks = ? WHERE form_id = ?");
                $upd_stmt->execute([$new_state, $remarks, $form_id]);
            } elseif (in_array($target_item_id, [31, 32, 33, 34, 35])) {
                if ($upload_status === 'Revision Requested') {
                    // Flag the whole request so the student sees the revision alert
                    $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 6: Revision Requested', statistician_remarks = ? WHERE form_id = ?");
                    $upd_stmt->execute([$remarks, $form_id]);
                } else {
                    // All 5 approved (checking the LATEST version of each item, since older
                    // reviewed versions remain as history) -> move into processing, not Completed;
                    // the request completes when the statistician releases the result file.
                    $stmt_latest = $pdo->prepare("SELECT COUNT(*) FROM uploads u
                        INNER JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id IN (31,32,33,34,35) GROUP BY item_id) latest
                        ON latest.max_id = u.upload_id
                        WHERE u.verification_status = 'Approved'");
                    $stmt_latest->execute([$student_user_id]);
                    if ($stmt_latest->fetchColumn() == 5) {
                        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 7: Statistical Treatment', statistician_remarks = ? WHERE form_id = ?");
                        $upd_stmt->execute([$remarks, $form_id]);
                    } else {
                        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ? WHERE form_id = ?");
                        $upd_stmt->execute([$remarks, $form_id]);
                    }
                }
            } elseif (in_array($target_item_id, [36, 37])) {
                $pdo->prepare("UPDATE form_stat_treatment SET statistician_remarks = ? WHERE form_id = ?")->execute([$remarks, $form_id]);
                if ($upload_status === 'Revision Requested') {
                    // Rejected payment document: send the group back to Phase 2 so the
                    // upload cards unlock and they can submit a corrected file.
                    $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 2: Form Download' WHERE form_id = ? AND status = 'Phase 4: Payment Verification'")->execute([$form_id]);
                }
            }

            // Email student
            if (!empty($f_data['contact_email'])) {
                $subject = "Statistical Treatment Update: " . ($f_data['formatted_control_no'] ?: 'Pending Registration');
                $msg_body = "Your Statistical Treatment request has a new status update.\n\nStatus: " . $upload_status . "\nRemarks: " . $remarks . "\n\nPlease check your dashboard for more details.";
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
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, upload_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_user_id, $upload_id, $log_title, $log_desc, $log_status]);
}

// Handle Statistician Acknowledging Payment (Phase 5 Transition)
// Hybrid flow: works from Phase 4 (payment documents uploaded in-system) AND from
// Phase 2 (receipt presented physically at the Research Office, nothing uploaded).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'acknowledge_payment') {
    if ($role === 'Statistician') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            exit('Invalid security token. Please refresh and try again.');
        }
        $form_id = $_POST['form_id'];
        $sequence_no = trim($_POST['control_no']);
        $student_id = $_POST['student_id'];

        // Get student department and program to auto-generate prefix
        $stmt_s = $pdo->prepare("SELECT department, program FROM users WHERE user_id = ?");
        $stmt_s->execute([$student_id]);
        $s_info = $stmt_s->fetch();

        $prog = strtoupper($s_info['program'] ?? 'GEN');
        $school = 'ISAP';
        if (strpos($prog, 'NURSING') !== false || strpos($prog, 'MEDICAL') !== false || strpos($prog, 'RADIOLOGIC') !== false || strpos($prog, 'PHARMACY') !== false || strpos($prog, 'MIDWIFERY') !== false || strpos($prog, 'DENTAL') !== false || strpos($prog, 'CAREGIVING') !== false) {
            $school = 'MCNP';
        }
        
        // Course code comes from the student's actual course/program (e.g. "BS Nursing"),
        // never from the institution-level `department` field — that produced gibberish
        // initials like "ISOAATP" from "International School of Asia and the Pacific".
        $course_stopwords = ['BS', 'BA', 'AB', 'BSED', 'OF', 'IN', 'AND', 'THE'];
        $course_words = preg_split('/\s+/', trim($s_info['program'] ?? ''));
        $course_significant = [];
        foreach ($course_words as $w) {
            if ($w !== '' && !in_array(strtoupper($w), $course_stopwords, true)) {
                $course_significant[] = strtoupper($w);
            }
        }
        if (count($course_significant) === 1) {
            $course_code = $course_significant[0];
        } elseif (count($course_significant) > 1) {
            $course_code = '';
            foreach ($course_significant as $w) { $course_code .= $w[0]; }
        } else {
            $course_code = 'GEN';
        }

        $full_control_no = "STAT-" . date('Y') . "-" . $school . "-" . $course_code . "-" . str_pad($sequence_no, 3, "0", STR_PAD_LEFT);

        // Only registrable while awaiting/verifying payment (guards double registration)
        $upd_stmt = $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 5: Registered', formatted_control_no = ?
                                   WHERE form_id = ? AND status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')");
        $upd_stmt->execute([$full_control_no, $form_id]);

        if ($upd_stmt->rowCount() > 0) {
            // Payment documents uploaded in-system are implicitly verified by registering
            $pdo->prepare("UPDATE uploads SET verification_status = 'Approved', remarks = 'Verified during official registration.'
                           WHERE user_id = ? AND item_id IN (36, 37) AND verification_status = 'Pending'")
                ->execute([$student_id]);

            // Auto-rename the LATEST upload of each processed document to the official
            // control-number format. Older reviewed versions stay untouched as history
            // (renaming them too would collide on identical target filenames).
            $rename_labels = [30 => 'InitialData', 36 => 'ValidatedForm', 37 => 'Receipt'];
            $stmt_up = $pdo->prepare("SELECT u.upload_id, u.item_id, u.file_path FROM uploads u
                INNER JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id IN (30, 36, 37) GROUP BY item_id) latest
                ON latest.max_id = u.upload_id");
            $stmt_up->execute([$student_id]);

            foreach ($stmt_up->fetchAll() as $up) {
                $old_path = $up['file_path'];
                if (!file_exists($old_path)) continue;
                $ext = pathinfo($old_path, PATHINFO_EXTENSION);
                $new_filename = $full_control_no . "_" . $rename_labels[$up['item_id']] . "." . $ext;
                $new_path = dirname($old_path) . '/' . $new_filename;

                if ($old_path !== $new_path && rename($old_path, $new_path)) {
                    $pdo->prepare("UPDATE uploads SET file_path = ?, original_filename = ? WHERE upload_id = ?")
                        ->execute([$new_path, $new_filename, $up['upload_id']]);
                    if ((int)$up['item_id'] === 30) {
                        $pdo->prepare("UPDATE form_stat_treatment SET file_coded_data = ? WHERE form_id = ?")
                            ->execute([$new_path, $form_id]);
                    }
                }
            }

            // Notify student
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Payment Acknowledged (Registered)', ?, 'success', CURRENT_TIMESTAMP)");
            $log_stmt->execute([$student_id, "Your statistical treatment payment has been acknowledged. Your Control Number is: $full_control_no. You may now upload the remaining deliverables in Phase 6."]);

            $message = "Payment acknowledged and Registered successfully! Generated Control No: $full_control_no";
        } else {
            $message = "This request is not awaiting registration (it may already be registered).";
        }
    }
}

// Handle Statistician Releasing Final Results (Phase 7 Completion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'upload_stat_result') {
    if ($role === 'Statistician') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            exit('Invalid security token. Please refresh and try again.');
        }
        $form_id = $_POST['form_id'];
        $student_id = $_POST['student_id'];

        if (isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['result_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'])) {
                $f_stmt = $pdo->prepare("SELECT formatted_control_no, contact_email FROM form_stat_treatment WHERE form_id = ? AND status = 'Phase 7: Statistical Treatment'");
                $f_stmt->execute([$form_id]);
                $f_row = $f_stmt->fetch();

                if ($f_row) {
                    $result_dir = '../uploads/stats/results/';
                    if (!is_dir($result_dir)) mkdir($result_dir, 0777, true);
                    $prefix = $f_row['formatted_control_no'] ?: ('RESULT_' . $student_id);
                    $result_path = $result_dir . $prefix . '_FinalResults_' . time() . '.' . $ext;

                    if (move_uploaded_file($_FILES['result_file']['tmp_name'], $result_path)) {
                        $pdo->prepare("UPDATE form_stat_treatment SET status = 'Phase 7: Completed', result_file = ?, date_released = CURRENT_TIMESTAMP WHERE form_id = ?")
                            ->execute([$result_path, $form_id]);

                        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Statistical Treatment Completed', ?, 'success', CURRENT_TIMESTAMP)");
                        $log_stmt->execute([$student_id, "Your statistical treatment results are ready. Download them from the Statistics module, then proceed to the Research Office to claim your physical copies."]);

                        if (!empty($f_row['contact_email'])) {
                            $headers = "From: no-reply@mcnp-isap-research.edu\r\n";
                            @mail($f_row['contact_email'], "Statistical Treatment Completed: " . ($f_row['formatted_control_no'] ?: ''), "Your statistical treatment results have been released. Log in to download them, then claim your physical copies at the Research Office.", $headers);
                        }

                        $message = "Final results released. The request is now marked Completed.";
                    } else {
                        $message = "Failed to save the results file.";
                    }
                } else {
                    $message = "This request is not in the processing stage.";
                }
            } else {
                $message = "Invalid results file format.";
            }
        } else {
            $message = "No results file was selected.";
        }
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

// Sort by most recently active group first (activity_logs covers both student uploads and
// staff signoffs, so it's a more complete "last touched" signal than uploads alone). This list
// is system-wide, not phase-scoped, so the recency source is too.
$group_selector_query = "
    SELECT DISTINCT u.user_id, u.username, u.research_group_name, u.department, u.program, u.profile_pic, u.email,
           la.last_activity
    FROM users u
    LEFT JOIN (
        SELECT user_id, MAX(created_at) AS last_activity FROM activity_logs GROUP BY user_id
    ) la ON la.user_id = u.user_id
    $group_selector_where_sql
    ORDER BY (la.last_activity IS NULL) ASC, la.last_activity DESC, u.research_group_name ASC
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
$release_queue = [];
if ($phase === 'stats') {
    // Registration queue: groups with payment docs uploaded (Phase 4) plus groups still in
    // Phase 2 — the latter covers receipts presented physically at the Research Office.
    $stmt_pay = $pdo->query("SELECT f.*, u.username, u.research_group_name, u.department, u.program
                             FROM form_stat_treatment f
                             JOIN users u ON f.user_id = u.user_id
                             WHERE f.status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')
                             ORDER BY FIELD(f.status, 'Phase 4: Payment Verification', 'Phase 2: Form Download'), f.date_submitted DESC");
    $pending_payments = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);

    // Attach the latest payment-document uploads (36 = validated form, 37 = receipt) per group.
    // Pull the full row the evaluation modal needs so payment docs can be viewed and
    // approved/rejected in-place without leaving the Pending Payments section.
    $stmt_paydocs = $pdo->prepare("SELECT u.upload_id, u.item_id, u.file_path, u.original_filename, u.verification_status, u.remarks FROM uploads u
        INNER JOIN (SELECT item_id, MAX(upload_id) AS max_id FROM uploads WHERE user_id = ? AND item_id IN (36, 37) GROUP BY item_id) latest
        ON latest.max_id = u.upload_id");
    foreach ($pending_payments as &$pp) {
        $stmt_paydocs->execute([$pp['user_id']]);
        $pp['payment_docs'] = [];
        foreach ($stmt_paydocs->fetchAll(PDO::FETCH_ASSOC) as $doc_row) {
            $pp['payment_docs'][$doc_row['item_id']] = $doc_row;
        }
    }
    unset($pp);

    // Release queue: all deliverables approved, awaiting the final results upload
    $release_queue = $pdo->query("SELECT f.*, u.username, u.research_group_name, u.department, u.program
                                  FROM form_stat_treatment f
                                  JOIN users u ON f.user_id = u.user_id
                                  WHERE f.status = 'Phase 7: Statistical Treatment' ORDER BY f.date_submitted DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin - <?= htmlspecialchars($current_phase['title']) ?></title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <!-- Shared design system (portal.js also listens for the parent shell's theme postMessage) -->
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="admin-module">

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
                    <?php $sidebar_gi = 0; foreach ($all_student_groups as $g):
                        $pfp = $g['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($g['username']);
                        $sidebar_gi++;
                        $sidebar_extra = $sidebar_gi > 7;
                    ?>
                        <div class="group-select-item" <?= $sidebar_extra ? 'data-extra="1" style="display:none;"' : '' ?> onclick="selectStudentGroup(<?= htmlspecialchars(json_encode($g)) ?>)" id="sidebar-g-<?= $g['user_id'] ?>" data-group-name="<?= htmlspecialchars(strtolower($g['research_group_name'])) ?>" data-student-name="<?= htmlspecialchars(strtolower($g['username'])) ?>" data-program-name="<?= htmlspecialchars(strtolower($g['program'])) ?>">
                            <img src="<?= htmlspecialchars($pfp) ?>" class="mini-avatar">
                            <div style="overflow:hidden;">
                                <h4 class="mini-group-name"><?= htmlspecialchars($g['research_group_name']) ?></h4>
                                <p class="mini-group-lead">Leader: <?= htmlspecialchars($g['username']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($all_student_groups) > 7): ?>
                        <button type="button" id="sidebarShowMoreGroups" onclick="toggleSidebarShowMore()" style="width:100%; padding:8px; font-size:11px; font-weight:700; color:var(--mcnp-teal); background:#faf8f4; border:1.5px dashed var(--border-line); border-radius:8px; cursor:pointer;">
                            Show all <?= count($all_student_groups) ?> groups
                        </button>
                    <?php endif; ?>
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

            <!-- Status Filter Tabs (meaningless on the isolated Payment/Release tabs, which are
                 self-contained workflows the tabs don't filter) -->
            <?php if (!in_array($stats_view, ['payments', 'release'], true)): ?>
            <div class="status-tabs-container" style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--border-line); padding-bottom: 14px; flex-wrap: wrap;">
                <button type="button" class="status-filter-tab active" data-filter="action" onclick="filterByStatus('action')">
                    <i data-lucide="inbox" style="width: 14px; height: 14px;"></i> Action Needed
                </button>
                <button type="button" class="status-filter-tab" data-filter="revision" onclick="filterByStatus('revision')">
                    <i data-lucide="alert-circle" style="width: 14px; height: 14px;"></i> Revision History
                </button>
                <button type="button" class="status-filter-tab" data-filter="approved" onclick="filterByStatus('approved')">
                    <i data-lucide="archive" style="width: 14px; height: 14px;"></i> Approved Archive
                </button>
                <button type="button" class="status-filter-tab" data-filter="all" onclick="filterByStatus('all')">
                    <i data-lucide="layers" style="width: 14px; height: 14px;"></i> All Submissions
                </button>
            </div>
            <?php endif; ?>

            <?php if ($phase === 'stats' && in_array($stats_view, ['all', 'payments'], true) && count($pending_payments) > 0): ?>
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
                                    <th style="width: 22%;">Research Group</th>
                                    <th style="width: 22%;">Payment Documents</th>
                                    <th style="width: 18%;">Status</th>
                                    <th style="width: 38%;">Register (Official Control No.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_payments as $pay):
                                    $has_docs = ($pay['status'] === 'Phase 4: Payment Verification');
                                    $doc_labels = [36 => 'Validated Form', 37 => 'Official Receipt'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--mcnp-teal); font-size: 14px;"><?= htmlspecialchars($pay['research_group_name']) ?></strong><br>
                                            <span style="color: #6b7280; font-size: 11px;"><?= htmlspecialchars($pay['department']) ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($pay['payment_docs'])): ?>
                                                <?php foreach ($doc_labels as $doc_id => $doc_label): ?>
                                                    <?php if (isset($pay['payment_docs'][$doc_id])):
                                                        // Build the object the evaluation modal expects so the
                                                        // statistician can view the scan and Approve/Reject-with-remarks
                                                        // right here (rejecting sends the group back to Phase 2).
                                                        $pdoc = $pay['payment_docs'][$doc_id];
                                                        $modal_obj = [
                                                            'upload_id' => $pdoc['upload_id'],
                                                            'student_user_id' => $pay['user_id'],
                                                            'research_group_name' => $pay['research_group_name'],
                                                            'file_path' => $pdoc['file_path'],
                                                            'original_filename' => $pdoc['original_filename'],
                                                            'verification_status' => $pdoc['verification_status'],
                                                            'remarks' => $pdoc['remarks'],
                                                            'form_008_data' => null,
                                                        ];
                                                        $dpill = strtolower($pdoc['verification_status']) === 'revision requested' ? 'revision'
                                                               : (strtolower($pdoc['verification_status']) === 'approved' ? 'approved' : 'review');
                                                    ?>
                                                        <button type="button" onclick='openDocumentModal(<?= htmlspecialchars(json_encode($modal_obj), ENT_QUOTES) ?>, "<?= htmlspecialchars($doc_label, ENT_QUOTES) ?>", <?= $doc_id ?>)' class="file-link" style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                                                            <i data-lucide="eye" style="width:13px; height:13px;"></i> <?= $doc_label ?>
                                                            <span class="status-pill <?= $dpill ?>" style="font-size:8px; padding:1px 6px;"><?= htmlspecialchars($pdoc['verification_status']) ?></span>
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="display:block; font-size:11.5px; color:#9ca3af; margin-bottom:4px;"><?= $doc_label ?>: not uploaded</span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="font-size:11.5px; color:#9ca3af;">None uploaded (physical receipt)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($has_docs): ?>
                                                <span class="status-pill review">Receipt Uploaded — Verify</span>
                                            <?php else: ?>
                                                <span class="status-pill pending">Awaiting Docs / Physical Receipt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="action_type" value="acknowledge_payment">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                <input type="hidden" name="form_id" value="<?= $pay['form_id'] ?>">
                                                <input type="hidden" name="student_id" value="<?= $pay['user_id'] ?>">
                                                <input type="text" name="control_no" placeholder="Sequence Number (e.g. 015)" required style="flex:1;">
                                                <button type="submit" class="btn-update" style="background: var(--warning);">Register & Unlock</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($phase === 'stats' && in_array($stats_view, ['all', 'release'], true) && count($release_queue) > 0): ?>
                <div class="req-card" style="margin-bottom: 25px; border-left: 5px solid var(--success);">
                    <div class="req-header" onclick="toggleReq('release', this)">
                        <div class="req-title" style="color: var(--success);">
                            <i data-lucide="package-check" style="width: 20px; height: 20px; color: var(--success);"></i>
                            Ready for Release — Upload Final Results
                        </div>
                        <div class="req-meta">
                            <span class="badge animate-pulse" style="background: var(--success);"><?= count($release_queue) ?> Processing</span>
                            <i data-lucide="chevron-down" class="chevron" style="width: 20px; height: 20px; color: var(--success);"></i>
                        </div>
                    </div>
                    <div class="req-body" id="body-release">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 28%;">Research Group</th>
                                    <th style="width: 22%;">Control Number</th>
                                    <th style="width: 50%;">Release Final Results</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($release_queue as $rel): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--mcnp-teal); font-size: 14px;"><?= htmlspecialchars($rel['research_group_name']) ?></strong><br>
                                            <span style="color: #6b7280; font-size: 11px;"><?= htmlspecialchars($rel['department']) ?></span>
                                        </td>
                                        <td>
                                            <strong style="font-size: 13px; font-family: 'JetBrains Mono', monospace;"><?= htmlspecialchars($rel['formatted_control_no'] ?: 'N/A') ?></strong>
                                        </td>
                                        <td>
                                            <form method="POST" enctype="multipart/form-data" class="action-form">
                                                <input type="hidden" name="action_type" value="upload_stat_result">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                <input type="hidden" name="form_id" value="<?= $rel['form_id'] ?>">
                                                <input type="hidden" name="student_id" value="<?= $rel['user_id'] ?>">
                                                <input type="file" name="result_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip" required style="flex:1; font-size: 12px;">
                                                <button type="submit" class="btn-update" style="background: var(--success);">Release Results</button>
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
                // Payment documents (36 Validated Form / 37 Official Receipt) are reviewed entirely
                // inside the Pending Payments Acknowledgment section above — skip the generic card so
                // the payment workflow lives in exactly one place.
                if ($phase === 'stats' && in_array($item_id, [36, 37])) continue;
                // Statistician's isolated Payment Verification / Release Results tabs show only
                // their own section; skip the generic checklist cards there.
                if ($phase === 'stats' && in_array($stats_view, ['payments', 'release'], true)) continue;
                $submissions = $uploads_by_item[$item_id] ?? [];

                $pending_count = 0;
                foreach ($submissions as $sub) {
                    if ($role === 'Research Coordinator' && $sub['verification_status'] === 'Pending') $pending_count++;
                    if ($role === 'Research Director' && $sub['verification_status'] === 'Under Review') $pending_count++;
                    if ($role === 'Statistician' && $sub['verification_status'] === 'Pending') $pending_count++;
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
                            <span class="badge animate-pulse" id="action-badge-<?= $item_id ?>" style="background: var(--danger); <?= $pending_count > 0 ? '' : 'display:none;' ?>"><?= $pending_count ?> Action Needed</span>
                            <?php if (!$is_cascaded): ?><span class="badge" id="total-badge-<?= $item_id ?>" title="Count of submissions in the currently selected tab"><?= count($submissions) ?> Total Submitted</span><?php endif; ?>
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
                            <table class="submissions-table">
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
                                        <tr class="sub-row-g-<?= $sub['student_user_id'] ?>" data-status="<?= htmlspecialchars(strtolower($sub['verification_status'])) ?>">
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
                                                <button type="button" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($sub)) ?>, '<?= htmlspecialchars($item['item_name']) ?>', <?= $item_id ?>)" class="file-link file-name-btn" title="<?= htmlspecialchars($sub['original_filename']) ?>">
                                                    <i data-lucide="file-text" style="width: 14px; height: 14px; flex-shrink:0;"></i>
                                                    <span class="file-name-text"><?= htmlspecialchars($sub['original_filename'] ?: 'Document') ?></span>
                                                </button>
                                                <div style="font-size: 10px; color: #9ca3af; margin-top: 4px;"><?= date('M d, h:i A', strtotime($sub['uploaded_at'])) ?></div>
                                            </td>
                                            <td><span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($sub['verification_status']) ?></span></td>
                                            <td>
                                                <button type="button" class="btn-evaluate" onclick="openDocumentModal(<?= htmlspecialchars(json_encode($sub)) ?>, '<?= htmlspecialchars($item['item_name']) ?>', <?= $item_id ?>)">
                                                    <i data-lucide="eye" style="width: 15px; height: 15px;"></i> View &amp; Evaluate
                                                </button>
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
        let activeStatusFilter = 'action'; // 'action' (Pending+Under Review), 'revision', 'approved', 'all'

        // Sidebar search filtering function. The 7 most-recent groups show by default (server
        // sorts by recency); typing a query reveals matches from the full list regardless of that cutoff.
        let sidebarGroupsExpanded = false;
        const sidebarTotalGroups = <?= (int) count($all_student_groups) ?>;

        function toggleSidebarShowMore() {
            sidebarGroupsExpanded = !sidebarGroupsExpanded;
            document.querySelectorAll('#sidebarGroupsList [data-extra="1"]').forEach(el => {
                el.style.display = sidebarGroupsExpanded ? 'flex' : 'none';
            });
            const btn = document.getElementById('sidebarShowMoreGroups');
            if (btn) btn.textContent = sidebarGroupsExpanded ? 'Show fewer groups' : ('Show all ' + sidebarTotalGroups + ' groups');
        }

        function filterSidebarGroups() {
            const query = document.getElementById('sidebarGroupSearch').value.toLowerCase().trim();
            const searching = query.length > 0;
            const items = document.querySelectorAll('#sidebarGroupsList .group-select-item');

            items.forEach(item => {
                const groupName = item.getAttribute('data-group-name') || '';
                const studentName = item.getAttribute('data-student-name') || '';
                const programName = item.getAttribute('data-program-name') || '';
                const matches = groupName.includes(query) || studentName.includes(query) || programName.includes(query);
                const isExtra = item.getAttribute('data-extra') === '1';

                item.style.display = (matches && (searching || sidebarGroupsExpanded || !isExtra)) ? 'flex' : 'none';
            });

            const btn = document.getElementById('sidebarShowMoreGroups');
            if (btn) btn.style.display = searching ? 'none' : 'block';
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
            // Only the requirement submission tables are filtered. The Pending Payments and
            // Ready-for-Release tables are their own self-contained workflows and must never be
            // hidden by the status tabs (their pills don't map to Pending/Under Review/etc.).
            document.querySelectorAll('table.submissions-table').forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                let visibleRowsCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.no-records-row)');
                // Tallied regardless of the group filter — badges summarize the whole
                // requirement item, not just the currently-selected student group.
                const statusCounts = { pending: 0, revision: 0, approved: 0, total: 0 };

                rows.forEach(row => {
                    const statusText = (row.dataset.status || '').trim().toLowerCase();
                    statusCounts.total++;
                    if (statusText === 'pending' || statusText === 'under review') statusCounts.pending++;
                    else if (statusText === 'revision requested' || statusText === 'revision needed') statusCounts.revision++;
                    else if (statusText === 'approved') statusCounts.approved++;

                    // Check Group Filter
                    let groupMatches = true;
                    if (activeGroupId !== null) {
                        groupMatches = row.classList.contains('sub-row-g-' + activeGroupId);
                    }

                    // Check Status Filter — read the row's OWN current status from data-status.
                    // (Reading .status-pill was buggy: the "Show History" box in the first cell
                    // renders older versions' pills first, so a resubmitted-Pending row was being
                    // misfiled under Revision History based on its previous rejected version.)
                    let statusMatches = true;
                    if (activeStatusFilter !== 'all') {
                        if (statusText) {
                            if (activeStatusFilter === 'action' || activeStatusFilter === 'pending') {
                                // Active queue: only submissions that still need an admin decision
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

                // Sync this item's badges so they always reflect what's actually in the
                // currently active tab, instead of a stale lifetime total.
                const reqBody = table.closest('.req-body');
                const itemId = reqBody ? reqBody.id.replace('body-', '') : null;
                if (itemId) {
                    const actionBadge = document.getElementById('action-badge-' + itemId);
                    if (actionBadge) {
                        if (statusCounts.pending > 0) {
                            actionBadge.textContent = statusCounts.pending + ' Action Needed';
                            actionBadge.style.display = '';
                        } else {
                            actionBadge.style.display = 'none';
                        }
                    }
                    const totalBadge = document.getElementById('total-badge-' + itemId);
                    if (totalBadge) {
                        let label = 'Total Submitted';
                        let count = statusCounts.total;
                        if (activeStatusFilter === 'action') {
                            label = 'Action Needed'; count = statusCounts.pending;
                        } else if (activeStatusFilter === 'revision') {
                            label = 'In Revision History'; count = statusCounts.revision;
                        } else if (activeStatusFilter === 'approved') {
                            label = 'In Approved Archive'; count = statusCounts.approved;
                        }
                        totalBadge.textContent = count + ' ' + label;
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

            allRadioInputs.forEach(r => {
                r.checked = false;
                r.required = false;
            });
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
            } else if (parseInt(itemId) === 3) {
                statRubricContainer.style.display = 'flex';
                activeContainer = statRubricContainer;
                runTallyFunc = runForm011Tally;
            }
            // NOTE: rubric radios are intentionally NOT HTML-`required`. Hidden required radios inside
            // collapsed accordions caused native submit to fail silently ("control not focusable"),
            // which broke Approve when re-evaluating a submission with no stored rubric. We validate
            // completeness in the #modalForm submit handler instead (see below).

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
            document.querySelectorAll('.rubric-radio').forEach(r => r.required = false);
        }

        // Validate the rubric in JS (not via hidden HTML5 `required`) so submit never fails silently.
        document.getElementById('modalForm').addEventListener('submit', function (e) {
            const box008 = document.getElementById('form008RubricBox');
            const box011 = document.getElementById('form011RubricBox');
            const activeBox = (box008.style.display !== 'none') ? box008
                : ((box011.style.display !== 'none') ? box011 : null);

            if (activeBox) {
                // Group radios by name and ensure each criterion has a selected YES/NO
                const answered = {};
                activeBox.querySelectorAll('.rubric-radio').forEach(r => {
                    if (!(r.name in answered)) answered[r.name] = false;
                    if (r.checked) answered[r.name] = true;
                });
                const firstUnanswered = Object.keys(answered).find(name => !answered[name]);
                if (firstUnanswered) {
                    e.preventDefault();
                    const radio = activeBox.querySelector('.rubric-radio[name="' + firstUnanswered.replace(/"/g, '\\"') + '"]');
                    const section = radio ? radio.closest('.rubric-accordion-content') : null;
                    if (section && !section.classList.contains('open')) {
                        const btn = section.previousElementSibling;
                        if (btn && btn.classList.contains('rubric-accordion-btn')) toggleAccordion(section.id, btn);
                    }
                    if (radio) radio.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    alert('Please complete every rubric criterion (YES / NO) before submitting the evaluation.');
                    return false;
                }
            }
        });

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

        // Apply the default "Action Needed" filter on load so Approved submissions start in the archive
        filterTableRows();
    </script>
</body>

</html>