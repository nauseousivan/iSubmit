<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director'])) { 
    exit(json_encode(['success' => false, 'message' => 'Access Denied'])); 
}

// Handle Form 008 Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'save_form_008') {
    $upload_id = $_POST['upload_id'];
    $student_user_id = $_POST['student_user_id'];
    $reviewer_id = $_SESSION['user_id'];
    $research_group_name = trim($_POST['research_group_name']);
    $adviser_name = trim($_POST['adviser_name']);
    $approved_title = trim($_POST['approved_title']);
    $proponents = trim($_POST['proponents']);
    $department = trim($_POST['department']);
    $final_decision = trim($_POST['final_decision']);

    // Parse assessment responses (22 criteria)
    $responses = [];
    $yes_count = 0;
    for ($i = 1; $i <= 22; $i++) {
        $criteria_key = "criteria_$i";
        $comment_key = "comment_$i";
        
        $response = isset($_POST[$criteria_key]) ? sanitize_input($_POST[$criteria_key]) : null;
        $comment = isset($_POST[$comment_key]) ? sanitize_input($_POST[$comment_key]) : '';
        
        $responses[$i] = [
            'criteria_num' => $i,
            'response' => $response,
            'comment' => $comment
        ];
        
        if ($response === 'yes') $yes_count++;
    }

    // Calculate final score
    $score_mapping = [
        22 => 100,
        '15-21' => 90,
        '8-14' => 80,
        '1-7' => 70
    ];
    
    $final_score = ($yes_count >= 22) ? 100 : (($yes_count >= 15) ? 90 : (($yes_count >= 8) ? 80 : 70));

    try {
        // Check if review already exists for this upload
        $check_stmt = $pdo->prepare("SELECT review_id FROM form_008_reviews WHERE upload_id = ?");
        $check_stmt->execute([$upload_id]);
        $existing_review = $check_stmt->fetchColumn();

        if ($existing_review) {
            // Update existing review
            $update_stmt = $pdo->prepare("
                UPDATE form_008_reviews 
                SET research_group_name = ?, adviser_name = ?, approved_title = ?, proponents = ?, 
                    department = ?, assessment_responses = ?, yes_count = ?, final_score = ?, 
                    final_decision = ?, reviewer_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE upload_id = ?
            ");
            $update_stmt->execute([
                $research_group_name, $adviser_name, $approved_title, $proponents, $department,
                json_encode($responses), $yes_count, $final_score, $final_decision, $reviewer_id, $upload_id
            ]);
        } else {
            // Insert new review
            $insert_stmt = $pdo->prepare("
                INSERT INTO form_008_reviews 
                (upload_id, student_user_id, reviewer_id, research_group_name, adviser_name, approved_title, 
                 proponents, department, assessment_responses, yes_count, final_score, final_decision)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $upload_id, $student_user_id, $reviewer_id, $research_group_name, $adviser_name, $approved_title,
                $proponents, $department, json_encode($responses), $yes_count, $final_score, $final_decision
            ]);
        }

        // If Form 008 is approved and decision is ACCEPT, auto-approve interconnected items (3, 4)
        if ($final_decision === 'ACCEPT') {
            $interconnected_items = [15, 16]; // Data Gathering (15), Literature Matrix (16)
            
            foreach ($interconnected_items as $item_id) {
                // Get all uploads for this student for the interconnected item
                $inter_stmt = $pdo->prepare("
                    UPDATE uploads 
                    SET verification_status = 'Approved', remarks = 'Auto-approved via Capsule Proposal (Form 008) evaluation.'
                    WHERE user_id = ? AND item_id = ? AND verification_status != 'Approved'
                ");
                $inter_stmt->execute([$student_user_id, $item_id]);

                // Log the auto-approval
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, title, description, status_type, created_at)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $item_name = ($item_id == 15) ? 'Data Gathering Tool' : 'Literature Matrix';
                $log_stmt->execute([
                    $student_user_id, 
                    "Auto-Approval: $item_name", 
                    "$item_name was automatically approved because the Complete Capsule Proposal was accepted.",
                    'success'
                ]);
            }

            // Now approve the main item 14
            $item_14_stmt = $pdo->prepare("
                UPDATE uploads 
                SET verification_status = 'Approved'
                WHERE upload_id = ?
            ");
            $item_14_stmt->execute([$upload_id]);
        }

        echo json_encode(['success' => true, 'message' => 'Form 008 review saved successfully!', 'final_score' => $final_score]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error saving review: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch existing Form 008 review
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_form_008') {
    $upload_id = $_GET['upload_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM form_008_reviews WHERE upload_id = ?");
        $stmt->execute([$upload_id]);
        $review = $stmt->fetch();
        
        if ($review) {
            $review['assessment_responses'] = json_decode($review['assessment_responses'], true);
            echo json_encode(['success' => true, 'review' => $review]);
        } else {
            echo json_encode(['success' => true, 'review' => null]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching review: ' . $e->getMessage()]);
    }
    exit;
}

function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>
