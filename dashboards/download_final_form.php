<?php
session_start();
require_once '../config/db.php';

// Ensure user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Helper function to get progress (copied from student.php for self-containment)
function getStageProgress($pdo, $userId, $itemIds) {
    if (empty($itemIds)) return 0;
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id IN ($placeholders) AND verification_status = 'Approved'");
    $stmt->execute(array_merge([$userId], $itemIds));
    $approvedCount = $stmt->fetchColumn();
    
    return round(($approvedCount / count($itemIds)) * 100);
}

function getSpecificItemProgress($pdo, $userId, $itemId) {
    $progress = 0;
    $stmt_any = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id = ?");
    $stmt_any->execute([$userId, $itemId]);
    $has_upload = $stmt_any->fetchColumn() > 0;

    if ($has_upload) {
        $progress = 25;
        $stmt_approved = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
        $stmt_approved->execute([$userId, $itemId]);
        $latest_status = $stmt_approved->fetchColumn();
        if ($latest_status === 'Approved') {
            $progress = 100;
        } elseif ($latest_status === 'Under Review') {
            $progress = 75; 
        }
    }
    return $progress;
}

// Calculate overall completion status
$proposal_progress = getStageProgress($pdo, $user_id, [11, 12, 13, 14, 15, 16]);
$final_progress    = getSpecificItemProgress($pdo, $user_id, 5);
$stats_progress    = getSpecificItemProgress($pdo, $user_id, 3);
$plag_progress     = getSpecificItemProgress($pdo, $user_id, 4);

// Define overall completion criteria
$overall_complete = ($proposal_progress === 100 && $final_progress === 100 && $stats_progress === 100 && $plag_progress === 100);

if ($overall_complete) {
    // Path to your actual final form file (e.g., a static PDF)
    $file_path = '../assets/final_research_form.pdf'; // Adjust this path as needed

    if (file_exists($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit();
    } else {
        // Log error or redirect to an error page if file not found
        header("Location: student.php?msg=" . urlencode("Final form file not found on server.") . "&type=error");
        exit();
    }
} else {
    // Not 100% complete, deny download
    header("Location: student.php?msg=" . urlencode("You must complete all research milestones to download the final form.") . "&type=error");
    exit();
}
?>