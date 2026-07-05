<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit(); 
}

$user_id = $_SESSION['user_id'];
$me_role = $_SESSION['role'];

// Helper to determine if user is leader
function is_student_leader($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $u_data = $stmt->fetch();
    return $u_data && empty($u_data['leader_id']);
}

// Fetch group leader ID for a student
function get_student_group_leader($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $u_data = $stmt->fetch();
    return !empty($u_data['leader_id']) ? $u_data['leader_id'] : $user_id;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_contacts') {
    // Return the list of contacts for the sidebar
    if ($me_role === 'Student') {
        if (!is_student_leader($pdo, $user_id)) {
            // Members only get the group chat contact (handled by frontend, but we can return it here)
            echo json_encode(['status' => 'success', 'contacts' => []]);
            exit();
        }
        
        // Leader: Can message the 3 roles
        $roles = ['Research Coordinator', 'Statistician', 'Research Director'];
        $contacts = [];
        
        foreach ($roles as $r) {
            // Check if there's an active conversation
            $stmt = $pdo->prepare("SELECT conversation_id, status, updated_at FROM staff_conversations WHERE group_leader_id = ? AND staff_role = ?");
            $stmt->execute([$user_id, $r]);
            $conv = $stmt->fetch();
            
            $unread = 0;
            if ($conv) {
                // Get unread count
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff_message_reads smr ON sm.conversation_id = smr.conversation_id AND smr.user_id = ? WHERE sm.conversation_id = ? AND (smr.last_read_at IS NULL OR sm.created_at > smr.last_read_at) AND sm.sender_id != ?");
                $stmt2->execute([$user_id, $conv['conversation_id'], $user_id]);
                $unread = $stmt2->fetchColumn();
                
                $contacts[] = [
                    'id' => 'role_' . str_replace(' ', '_', $r),
                    'name' => $r,
                    'type' => 'role_dm',
                    'role' => $r,
                    'conversation_id' => $conv['conversation_id'],
                    'status' => $conv['status'],
                    'updated_at' => $conv['updated_at'],
                    'unread' => $unread
                ];
            } else {
                // No conversation yet, just show the option
                $contacts[] = [
                    'id' => 'role_' . str_replace(' ', '_', $r),
                    'name' => $r,
                    'type' => 'role_dm',
                    'role' => $r,
                    'conversation_id' => null,
                    'status' => 'none',
                    'updated_at' => null,
                    'unread' => 0
                ];
            }
        }
        
        echo json_encode(['status' => 'success', 'contacts' => $contacts]);
        exit();
        
    } else if (in_array($me_role, ['Research Coordinator', 'Statistician', 'Research Director'])) {
        // Admins see a list of student groups they have conversations with
        $stmt = $pdo->prepare("
            SELECT sc.conversation_id, sc.group_leader_id, sc.status, sc.updated_at, 
                   u.research_group_name 
            FROM staff_conversations sc
            JOIN users u ON sc.group_leader_id = u.user_id
            WHERE sc.staff_role = ?
            ORDER BY sc.updated_at DESC
        ");
        $stmt->execute([$me_role]);
        $convs = $stmt->fetchAll();
        
        $contacts = [];
        foreach ($convs as $c) {
            // unread
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff_message_reads smr ON sm.conversation_id = smr.conversation_id AND smr.user_id = ? WHERE sm.conversation_id = ? AND (smr.last_read_at IS NULL OR sm.created_at > smr.last_read_at) AND sm.sender_id != ?");
            $stmt2->execute([$user_id, $c['conversation_id'], $user_id]);
            $unread = $stmt2->fetchColumn();
            
            $grp_name = $c['research_group_name'] ?: 'Group ' . $c['group_leader_id'];
            
            $contacts[] = [
                'id' => 'group_' . $c['group_leader_id'],
                'name' => $grp_name,
                'type' => 'group_dm',
                'group_leader_id' => $c['group_leader_id'],
                'conversation_id' => $c['conversation_id'],
                'status' => $c['status'],
                'updated_at' => $c['updated_at'],
                'unread' => $unread
            ];
        }
        
        echo json_encode(['status' => 'success', 'contacts' => $contacts]);
        exit();
    }
}

else if ($action === 'get_messages') {
    $target_role = $_POST['target_role'] ?? '';
    $target_group = $_POST['target_group'] ?? '';
    
    $conversation_id = null;
    $contact_name = '';
    
    if ($me_role === 'Student') {
        if (!is_student_leader($pdo, $user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Members cannot DM admins']);
            exit();
        }
        $stmt = $pdo->prepare("SELECT conversation_id, status FROM staff_conversations WHERE group_leader_id = ? AND staff_role = ?");
        $stmt->execute([$user_id, $target_role]);
        $conv = $stmt->fetch();
        if ($conv) {
            $conversation_id = $conv['conversation_id'];
        }
        $contact_name = $target_role;
    } else {
        $stmt = $pdo->prepare("SELECT conversation_id, status FROM staff_conversations WHERE group_leader_id = ? AND staff_role = ?");
        $stmt->execute([$target_group, $me_role]);
        $conv = $stmt->fetch();
        if ($conv) {
            $conversation_id = $conv['conversation_id'];
        }
        
        $stmt2 = $pdo->prepare("SELECT research_group_name FROM users WHERE user_id = ?");
        $stmt2->execute([$target_group]);
        $contact_name = $stmt2->fetchColumn() ?: 'Student Group';
    }
    
    if (!$conversation_id) {
        echo json_encode(['status' => 'success', 'messages' => [], 'contact_name' => $contact_name, 'conv_status' => 'none']);
        exit();
    }
    
    // Mark as read
    $stmt_read = $pdo->prepare("INSERT INTO staff_message_reads (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_read_at = NOW()");
    $stmt_read->execute([$conversation_id, $user_id]);
    
    // Fetch messages
    $stmt_msg = $pdo->prepare("
        SELECT sm.*, u.username, u.role, u.profile_pic 
        FROM staff_messages sm
        JOIN users u ON sm.sender_id = u.user_id
        WHERE sm.conversation_id = ?
        ORDER BY sm.created_at ASC
    ");
    $stmt_msg->execute([$conversation_id]);
    $messages = $stmt_msg->fetchAll(PDO::FETCH_ASSOC);
    
    // Sanitize message content for display, and use Role name for anonymity if staff
    foreach ($messages as &$msg) {
        if (in_array($msg['role'], ['Research Coordinator', 'Statistician', 'Research Director'])) {
            $msg['display_name'] = $msg['role']; // Anonymity
            $msg['profile_pic'] = ''; // Or some generic staff avatar
        } else {
            $msg['display_name'] = $msg['username'];
        }
    }
    
    echo json_encode([
        'status' => 'success', 
        'messages' => $messages, 
        'contact_name' => $contact_name, 
        'conv_status' => $conv['status'],
        'conversation_id' => $conversation_id
    ]);
    exit();
}

else if ($action === 'send_message') {
    $target_role = $_POST['target_role'] ?? '';
    $target_group = $_POST['target_group'] ?? '';
    $message_text = trim($_POST['message_text'] ?? '');
    
    if (empty($message_text)) {
        echo json_encode(['status' => 'error', 'message' => 'Empty message']);
        exit();
    }
    
    $group_leader_id = null;
    $staff_role = null;
    
    if ($me_role === 'Student') {
        if (!is_student_leader($pdo, $user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Members cannot DM admins']);
            exit();
        }
        $group_leader_id = $user_id;
        $staff_role = $target_role;
    } else {
        $group_leader_id = $target_group;
        $staff_role = $me_role;
    }
    
    if (!$staff_role || !$group_leader_id) {
         echo json_encode(['status' => 'error', 'message' => 'Invalid target']);
         exit();
    }
    
    // Get or create conversation
    $stmt = $pdo->prepare("SELECT conversation_id, status FROM staff_conversations WHERE group_leader_id = ? AND staff_role = ?");
    $stmt->execute([$group_leader_id, $staff_role]);
    $conv = $stmt->fetch();
    
    $conversation_id = null;
    if ($conv) {
        $conversation_id = $conv['conversation_id'];
        if ($conv['status'] === 'archived') {
            // Re-open conversation
            $pdo->prepare("UPDATE staff_conversations SET status = 'active' WHERE conversation_id = ?")->execute([$conversation_id]);
        }
    } else {
        $stmt_create = $pdo->prepare("INSERT INTO staff_conversations (group_leader_id, staff_role, status) VALUES (?, ?, 'active')");
        $stmt_create->execute([$group_leader_id, $staff_role]);
        $conversation_id = $pdo->lastInsertId();
    }
    
    // Insert message
    $stmt_ins = $pdo->prepare("INSERT INTO staff_messages (conversation_id, sender_id, message_text) VALUES (?, ?, ?)");
    $stmt_ins->execute([$conversation_id, $user_id, $message_text]);
    
    // Update conversation timestamp
    $pdo->prepare("UPDATE staff_conversations SET updated_at = NOW() WHERE conversation_id = ?")->execute([$conversation_id]);
    
    // Mark as read for sender
    $stmt_read = $pdo->prepare("INSERT INTO staff_message_reads (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_read_at = NOW()");
    $stmt_read->execute([$conversation_id, $user_id]);
    
    // Optional: add to activity logs if student initiated
    if ($me_role === 'Student' && !$conv) {
        $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type) VALUES (?, ?, ?, 'info')")
            ->execute([$user_id, 'Started Conversation', 'Started a conversation with ' . $staff_role, 'info']);
    }
    
    echo json_encode(['status' => 'success']);
    exit();
} else if ($action === 'react_message') {
    $msg_id = intval($_POST['message_id'] ?? 0);
    $reaction = $_POST['reaction'] ?? '';
    if ($msg_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE staff_messages SET reaction = ? WHERE message_id = ?");
            $stmt->execute([$reaction, $msg_id]);
            echo json_encode(['status' => 'success']);
        } catch(Exception $e) {
            echo json_encode(['status' => 'error']);
        }
    }
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
