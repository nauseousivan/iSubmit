<?php
$PHP_CODE = <<<'EOD'
<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$me_role = $_SESSION['role'];

function timeAgo($datetime) {
    if (!$datetime) return '';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 172800) return 'Yesterday';
    return date('M j', $time);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        group_identifier INT NOT NULL,
        sender_id INT NOT NULL,
        message_text TEXT NOT NULL,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        reaction VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$u_data = $stmt_leader_check->fetch();
$is_leader = ($me_role === 'Student' && empty($u_data['leader_id']));
$leader_id = ($me_role === 'Student') ? (!empty($u_data['leader_id']) ? $u_data['leader_id'] : $user_id) : 0;
$group_identifier = $leader_id;

if ($me_role !== 'Student') {
    $chat_context = $_GET['chat_context'] ?? '0';
    if (strpos($chat_context, 'group_') === 0) {
        $group_identifier = intval(str_replace('group_', '', $chat_context));
    } else {
        $group_identifier = intval($chat_context);
    }
}
if (empty($group_identifier) && $me_role === 'Student') { $group_identifier = $user_id; }

$me_username = $_SESSION['username'] ?? 'User';
$selected_avatar = $_SESSION['profile_pic'] ?? "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($me_username);

// Handle AJAX group message reaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'react_group_message') {
    $msg_id = intval($_POST['message_id'] ?? 0);
    $reaction = $_POST['reaction'] ?? '';
    if ($msg_id > 0) {
        $stmt = $pdo->prepare("UPDATE group_messages SET reaction = ? WHERE message_id = ?");
        $stmt->execute([$reaction, $msg_id]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $msg_text = trim($_POST['message_text'] ?? '');
    
    if (!empty($msg_text)) {
        $stmt_send = $pdo->prepare("INSERT INTO group_messages (group_identifier, sender_id, message_text) VALUES (?, ?, ?)");
        $stmt_send->execute([$group_identifier, $user_id, $msg_text]);
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: message.php?chat_context=group_" . urlencode($group_identifier) . "&active_contact=group_chat");
    exit;
}

$group_messages = [];
try {
    $stmt_msg = $pdo->prepare("SELECT gm.*, u.username as sender_name, u.profile_pic as sender_avatar FROM group_messages gm LEFT JOIN users u ON gm.sender_id = u.user_id WHERE gm.group_identifier = ? ORDER BY gm.created_at ASC LIMIT 100");
    $stmt_msg->execute([$group_identifier]);
    $group_messages = $stmt_msg->fetchAll();
} catch (Exception $e) {}

$contacts = [];
if ($me_role === 'Student') {
    $stmt_last_grp = $pdo->prepare("SELECT message_text, attachment_path, created_at FROM group_messages WHERE group_identifier = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_last_grp->execute([$leader_id]);
    $last_grp = $stmt_last_grp->fetch();
    
    $grp_preview = $last_grp ? ($last_grp['message_text'] ?: 'Sent an attachment') : 'Start a conversation';
    $grp_time = $last_grp ? timeAgo($last_grp['created_at']) : '';
    
    $contacts[] = ['id' => 'group_chat', 'name' => 'My Research Group', 'type' => 'group', 'icon' => 'users', 'unread' => 0, 'preview' => $grp_preview, 'time' => $grp_time, 'category' => 'groups'];
    
    if ($is_leader) {
        $roles = ['Research Coordinator', 'Statistician', 'Research Director'];
        foreach ($roles as $r) {
            $stmt = $pdo->prepare("SELECT conversation_id, status FROM staff_conversations WHERE group_leader_id = ? AND staff_role = ?");
            $stmt->execute([$user_id, $r]);
            $conv = $stmt->fetch();
            $unread = 0;
            $preview = 'No messages yet';
            $time = '';
            
            if ($conv) {
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff_message_reads smr ON sm.conversation_id = smr.conversation_id AND smr.user_id = ? WHERE sm.conversation_id = ? AND (smr.last_read_at IS NULL OR sm.created_at > smr.last_read_at) AND sm.sender_id != ?");
                $stmt2->execute([$user_id, $conv['conversation_id'], $user_id]);
                $unread = $stmt2->fetchColumn();
                
                $stmt_last_staff = $pdo->prepare("SELECT message_text, created_at FROM staff_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt_last_staff->execute([$conv['conversation_id']]);
                $last_staff = $stmt_last_staff->fetch();
                if ($last_staff) {
                    $preview = $last_staff['message_text'];
                    $time = timeAgo($last_staff['created_at']);
                }
            }
            $icon = ($r === 'Research Coordinator') ? 'landmark' : (($r === 'Statistician') ? 'bar-chart-2' : 'graduation-cap');
            $contacts[] = [
                'id' => 'role_' . str_replace(' ', '_', $r), 'name' => $r, 'type' => 'role', 'role' => $r,
                'icon' => $icon, 'status' => $conv ? $conv['status'] : 'none', 'unread' => $unread, 'preview' => $preview, 'time' => $time, 'category' => 'staff'
            ];
        }
    }
} else {
    $stmt = $pdo->prepare("
        SELECT sc.conversation_id, sc.group_leader_id, sc.status, sc.updated_at, u.research_group_name 
        FROM staff_conversations sc JOIN users u ON sc.group_leader_id = u.user_id WHERE sc.staff_role = ? ORDER BY sc.updated_at DESC
    ");
    $stmt->execute([$me_role]);
    $convs = $stmt->fetchAll();
    foreach ($convs as $c) {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff_message_reads smr ON sm.conversation_id = smr.conversation_id AND smr.user_id = ? WHERE sm.conversation_id = ? AND (smr.last_read_at IS NULL OR sm.created_at > smr.last_read_at) AND sm.sender_id != ?");
        $stmt2->execute([$user_id, $c['conversation_id'], $user_id]);
        $unread = $stmt2->fetchColumn();
        
        $preview = 'No messages yet';
        $time = '';
        $stmt_last_staff = $pdo->prepare("SELECT message_text, created_at FROM staff_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt_last_staff->execute([$c['conversation_id']]);
        $last_staff = $stmt_last_staff->fetch();
        if ($last_staff) {
            $preview = $last_staff['message_text'];
            $time = timeAgo($last_staff['created_at']);
        }
        
        $grp_name = $c['research_group_name'] ?: 'Group ' . $c['group_leader_id'];
        $contacts[] = [
            'id' => 'group_' . $c['group_leader_id'], 'name' => $grp_name, 'type' => 'role', 'group_id' => $c['group_leader_id'],
            'icon' => 'users', 'status' => $c['status'], 'unread' => $unread, 'preview' => $preview, 'time' => $time, 'category' => 'groups'
        ];
    }
}

$is_single_contact = (count($contacts) === 1);
$active_contact_id = $_GET['active_contact'] ?? '';

if (empty($active_contact_id) && $is_single_contact) {
    $active_contact_id = $contacts[0]['id'];
    $show_chat_mobile = true;
} else if (empty($active_contact_id)) {
    $active_contact_id = $contacts[0]['id'] ?? '';
    $show_chat_mobile = false;
} else {
    $show_chat_mobile = true;
}

$active_contact_info = null;
foreach ($contacts as $c) {
    if ($c['id'] === $active_contact_id) {
        $active_contact_info = $c;
        break;
    }
}

$pinned_contacts = array_filter($contacts, fn($c) => $c['id'] === 'group_chat');
$normal_contacts = array_filter($contacts, fn($c) => $c['id'] !== 'group_chat');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Research Messenger</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        :root { 
            --bg-white: #ffffff; 
            --bg-beige: #f9f7f2;
            --bg-hover: #f1f5f9;
            --bg-chat-stream: #faf9f6; 
            --mcnp-teal: #0f172a; 
            --accent: #7c3aed;
            --accent-transparent: rgba(124, 58, 237, 0.08);
            --border-line: #e2e8f0; 
            --text-muted: #64748b; 
            --text-lighter: #94a3b8; 
            --text-dark: #0f172a;
            
            --bubble-self: var(--accent); 
            --text-self: #ffffff; 
            --bubble-other: #ffffff; 
            --text-other: var(--text-dark); 
            
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --sidebar-width: 340px;
        }
        
        body.theme-dark { 
            --bg-beige: #0f1214; --bg-white: #1e293b; --bg-hover: #334155; --bg-chat-stream: #0f172a; --mcnp-teal: #38bdf8; --accent: #38bdf8; 
            --accent-transparent: rgba(56, 189, 248, 0.1);
            --border-line: #334155; --text-muted: #94a3b8; --text-lighter: #475569; --text-dark: #f8fafc; 
            --bubble-other: #334155; --text-other: #f8fafc;
        }
        body.theme-green { --bg-beige: #f2f8f2; --bg-chat-stream: #f8fbf8; --mcnp-teal: #1e3f20; --accent: #2d5f30; --border-line: #cbdacd; }
        body.theme-red { --bg-beige: #f9f5f5; --bg-chat-stream: #fcfaf8; --mcnp-teal: #571616; --accent: #731e1e; --border-line: #dbc8c8; }
        body.theme-purple { --bg-beige: #f6f4fa; --bg-chat-stream: #f9f8fc; --mcnp-teal: #3b1e5a; --accent: #7c3aed; --border-line: #ddd6fe; }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; width: 100%; overflow: hidden; }
        
        body { 
            font-family: var(--ui-sans); 
            background: transparent; 
            color: var(--text-dark); 
            display: flex; 
            padding: 24px;
        }
        
        .chat-app-container { 
            display: flex; 
            height: 100%; 
            width: 100%; 
            background: var(--bg-white);
            border-radius: 16px;
            box-shadow: 0 4px 24px -8px rgba(0,0,0,0.06);
            border: 1px solid var(--border-line);
            overflow: hidden;
        }
        
        /* Sidebar */
        .chat-sidebar { 
            width: var(--sidebar-width); 
            background: var(--bg-white); 
            border-right: 1px solid var(--border-line); 
            display: flex; 
            flex-direction: column; 
            flex-shrink: 0; 
            z-index: 10;
        }
        .sidebar-header { 
            padding: 24px 20px 16px 20px; 
            border-bottom: 1px solid var(--border-line); 
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg-white);
        }
        .sidebar-header h2 { 
            font-size: 20px; 
            font-weight: 800; 
            color: var(--text-dark); 
            letter-spacing: -0.02em;
        }
        
        /* Search */
        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-hover);
            border-radius: 8px;
            padding: 8px 12px;
            gap: 8px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .search-box:focus-within {
            background: var(--bg-white);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            font-family: var(--ui-sans);
            font-size: 14px;
            color: var(--text-dark);
            width: 100%;
        }
        .search-box i { color: var(--text-muted); width: 16px; height: 16px; }

        /* Filter Chips */
        .filter-chips {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .filter-chips::-webkit-scrollbar { display: none; }
        .filter-chip {
            padding: 4px 12px;
            background: var(--bg-hover);
            color: var(--text-muted);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .filter-chip.active {
            background: var(--text-dark);
            color: var(--bg-white);
        }
        .filter-chip:hover:not(.active) {
            background: var(--border-line);
        }
        
        .contact-list { 
            flex: 1; 
            overflow-y: auto; 
            padding: 12px; 
            display: flex; 
            flex-direction: column; 
            gap: 4px; 
            background: var(--bg-white);
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-line); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-lighter); }

        .list-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-lighter);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 12px 12px 4px 12px;
        }

        /* Contact Items */
        .contact-item { 
            display: flex; 
            align-items: center; 
            gap: 14px; 
            padding: 12px; 
            border-radius: 12px; 
            cursor: pointer; 
            transition: all 0.15s ease; 
            text-decoration: none; 
            color: inherit; 
            background: transparent;
            position: relative;
        }
        .contact-item:hover { background: var(--bg-hover); }
        .contact-item.active { 
            background: var(--bg-hover); 
        }
        
        /* Pinned Group overrides */
        .contact-item.pinned {
            background: var(--accent-transparent);
            border: 1px solid rgba(124, 58, 237, 0.1);
        }
        .contact-item.pinned:hover {
            background: rgba(124, 58, 237, 0.12);
        }
        .contact-item.pinned.active {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
        }
        
        /* Avatar & Icon */
        .contact-avatar { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            background: var(--bg-white); 
            border: 1px solid var(--border-line); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-shrink: 0; 
            color: var(--text-dark); 
            position: relative;
        }
        .contact-item.active:not(.pinned) .contact-avatar {
            border-color: var(--accent);
            color: var(--accent);
        }
        .contact-item.pinned:not(.active) .contact-avatar {
            border-color: var(--accent);
            color: var(--accent);
        }
        .contact-item.pinned.active .contact-avatar {
            background: rgba(255,255,255,0.2);
            border-color: transparent;
            color: white;
        }
        
        .contact-avatar svg { width: 20px; height: 20px; }
        
        /* Online Status Dot */
        .status-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 12px;
            height: 12px;
            background: #10b981;
            border: 2px solid var(--bg-white);
            border-radius: 50%;
        }
        .contact-item.active .status-dot { border-color: var(--bg-hover); }
        .contact-item.pinned.active .status-dot { border-color: var(--accent); }

        .contact-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .contact-header { display: flex; justify-content: space-between; align-items: center; }
        .contact-name { font-weight: 600; font-size: 14px; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .contact-time { font-size: 11px; color: var(--text-lighter); font-weight: 500; flex-shrink: 0; }
        
        .contact-footer { display: flex; justify-content: space-between; align-items: center; }
        .contact-preview { font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        
        /* Active text colors */
        .contact-item.pinned.active .contact-name,
        .contact-item.pinned.active .contact-time,
        .contact-item.pinned.active .contact-preview { color: white; }
        
        .badge-unread { background: var(--accent); color: white; font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 12px; display: inline-block; flex-shrink: 0; line-height: 1.2; }
        .contact-item.pinned.active .badge-unread { background: white; color: var(--accent); }

        /* Main Panel */
        .chat-main-panel { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            min-width: 0; 
            background: var(--bg-chat-stream); 
            position: relative;
        }

        /* Chat Header */
        .chat-main-header {
            padding: 16px 28px;
            border-bottom: 1px solid var(--border-line);
            background: var(--bg-white);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 5;
            box-shadow: 0 4px 20px -10px rgba(0,0,0,0.02);
        }
        .chat-main-header-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-avatar {
            width: 40px; height: 40px;
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-transparent);
        }
        .header-avatar svg { width: 18px; height: 18px; }
        .header-avatar .status-dot { border-color: var(--bg-white); }
        .header-text-container { display: flex; flex-direction: column; }
        .header-name { font-weight: 700; font-size: 15.5px; color: var(--text-dark); letter-spacing: -0.01em; }
        .header-status { font-size: 12px; font-weight: 500; color: #10b981; }

        .chat-main-header-actions {
            display: flex;
            gap: 8px;
        }
        /* Call buttons removed as requested */
        .icon-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: 1px solid transparent;
            background: transparent;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }
        .icon-btn:hover {
            background: var(--bg-hover);
            color: var(--text-dark);
            border-color: var(--border-line);
        }
        .icon-btn svg { width: 18px; height: 18px; }

        .messages-stream-box { 
            flex: 1; 
            min-height: 0; 
            overflow-y: auto; 
            padding: 24px 32px; 
            display: flex; 
            flex-direction: column; 
            gap: 20px; 
            background: transparent; 
        }

        .date-separator {
            text-align: center;
            margin: 10px 0;
            position: relative;
        }
        .date-separator::before {
            content: '';
            position: absolute;
            left: 0; top: 50%; width: 100%; height: 1px;
            background: var(--border-line);
            z-index: 0;
        }
        .date-separator-text {
            background: var(--bg-chat-stream);
            padding: 0 12px;
            color: var(--text-lighter);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
            z-index: 1;
        }

        .message-row { display: flex; gap: 12px; max-width: 75%; width: fit-content; align-items: flex-end; position: relative; }
        .message-row.self { margin-left: auto; flex-direction: row-reverse; }
        
        .sender-avatar { 
            width: 32px; 
            height: 32px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid var(--bg-white); 
            background: white; 
            flex-shrink: 0; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .message-bubble-data { display: flex; flex-direction: column; gap: 6px; }
        .message-bubble-data .sender-title { font-size: 11.5px; font-weight: 700; color: var(--text-muted); padding-left: 6px; }
        .message-row.self .message-bubble-data .sender-title { text-align: right; padding-right: 6px; }
        
        .chat-text-balloon { 
            padding: 12px 18px; 
            border-radius: 20px; 
            font-size: 14.5px; 
            line-height: 1.5; 
            word-break: break-word; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            position: relative;
            cursor: pointer; /* indicates interactability */
            user-select: none;
        }
        .message-row.self .chat-text-balloon { 
            background: var(--bubble-self); 
            color: var(--text-self); 
            border-bottom-right-radius: 4px; 
        }
        .message-row.other .chat-text-balloon { 
            background: var(--bubble-other); 
            color: var(--text-other); 
            border-bottom-left-radius: 4px; 
            border: 1px solid rgba(0,0,0,0.02);
        }

        /* REACTION UI */
        .reaction-trigger {
            opacity: 0;
            transition: opacity 0.2s;
            background: var(--bg-white);
            border: 1px solid var(--border-line);
            border-radius: 50%;
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            position: absolute;
            bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        .message-row.self .reaction-trigger { left: -36px; }
        .message-row.other .reaction-trigger { right: -36px; }
        
        @media (hover: hover) {
            .message-row:hover .reaction-trigger { opacity: 1; }
        }

        .reaction-trigger:hover { color: var(--text-dark); background: var(--bg-hover); }

        .reaction-picker {
            position: absolute;
            bottom: 100%;
            background: var(--bg-white);
            border: 1px solid var(--border-line);
            border-radius: 24px;
            padding: 6px 12px;
            display: flex;
            gap: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            z-index: 50;
            opacity: 0; pointer-events: none;
            transform: translateY(10px);
            transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .message-row.self .reaction-picker { right: 0; }
        .message-row.other .reaction-picker { left: 0; }
        .reaction-picker.show { opacity: 1; pointer-events: auto; transform: translateY(-5px); }

        .reaction-emoji {
            font-size: 22px;
            cursor: pointer;
            transition: transform 0.1s;
            user-select: none;
        }
        .reaction-emoji:hover { transform: scale(1.3); }

        .reaction-badge {
            position: absolute;
            bottom: -10px;
            background: var(--bg-white);
            border: 1px solid var(--border-line);
            border-radius: 12px;
            padding: 2px 6px;
            font-size: 13px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            z-index: 10;
        }
        .message-row.self .reaction-badge { right: 10px; }
        .message-row.other .reaction-badge { left: 10px; }


        .time-label { 
            font-size: 10px; 
            color: var(--text-lighter); 
            padding: 0 6px; 
            margin-top: 0px; 
            font-weight: 600; 
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .message-row.self .time-label { justify-content: flex-end; }

        /* Empty State */
        .empty-state {
            text-align: center; color: var(--text-muted); margin: auto; display: flex; flex-direction: column; align-items: center; gap: 16px;
        }
        .empty-icon {
            width: 64px; height: 64px; border-radius: 50%; background: var(--bg-white); border: 1px solid var(--border-line); display: flex; align-items: center; justify-content: center; color: var(--text-lighter); box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .empty-text { font-size: 14px; font-weight: 500; line-height: 1.5; }

        /* Input Area */
        .chat-keyboard-bar { 
            padding: 18px 28px; 
            border-top: 1px solid var(--border-line); 
            background: var(--bg-white); 
            flex-shrink: 0; 
            z-index: 5;
        }
        .chat-input-form { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
        }
        
        .chat-msg-textarea { 
            flex: 1; 
            border: 1px solid var(--border-line); 
            background: var(--bg-chat-stream); 
            border-radius: 24px;
            font-family: var(--ui-sans); 
            font-size: 14.5px; 
            resize: none; 
            outline: none; 
            height: 48px; 
            padding: 13px 20px; 
            line-height: 20px; 
            color: var(--text-dark);
            transition: all 0.2s ease;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
            overflow: hidden; 
        }
        .chat-msg-textarea:focus {
            border-color: var(--accent);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .chat-msg-textarea::placeholder {
            color: var(--text-lighter);
        }
        
        .btn-post-msg { 
            width: 48px; 
            height: 48px; 
            border-radius: 50%; 
            border: none; 
            background: var(--text-dark); 
            color: white; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            cursor: pointer; 
            transition: all 0.2s; 
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .btn-post-msg:hover { background: var(--accent); transform: scale(1.05); }
        .btn-post-msg svg { width: 20px; height: 20px; margin-left: 2px; }
        
        #staffChatPanel, #groupChatPanel { flex: 1; flex-direction: column; overflow: hidden; display: flex; }

        .chat-app-container.single-contact-mode .chat-sidebar { display: none !important; }
        
        @media (max-width: 768px) {
            body { padding: 0; }
            .chat-app-container { border-radius: 0; border: none; box-shadow: none; }
            .chat-app-container.mobile-show-chat .chat-sidebar { display: none !important; }
            .chat-app-container.mobile-show-list .chat-main-panel { display: none !important; }
            .chat-sidebar { width: 100%; border-right: none; }
            .chat-main-header { padding: 12px 16px; }
            .messages-stream-box { padding: 16px; }
            .chat-keyboard-bar { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <div class="chat-app-container <?= $show_chat_mobile ? 'mobile-show-chat' : 'mobile-show-list' ?> <?= $is_single_contact ? 'single-contact-mode' : '' ?>" id="appContainer">
        
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="sidebar-header">
                <h2>Messages</h2>
                
                <!-- Search & Filters -->
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="contactSearch" placeholder="Search conversations...">
                </div>
                <div class="filter-chips" id="filterChips">
                    <div class="filter-chip active" data-filter="all">All</div>
                    <div class="filter-chip" data-filter="unread">Unread</div>
                    <div class="filter-chip" data-filter="groups">Groups</div>
                    <div class="filter-chip" data-filter="staff">Staff</div>
                </div>
            </div>
            
            <div class="contact-list" id="contactList">
                
                <?php if (!empty($pinned_contacts)): ?>
                    <div class="list-label">Pinned</div>
                    <?php foreach($pinned_contacts as $c): ?>
                        <a href="?active_contact=<?= urlencode($c['id']) ?>" class="contact-item pinned <?= ($active_contact_id === $c['id']) ? 'active' : '' ?>" data-category="<?= $c['category'] ?>" data-unread="<?= $c['unread'] ?>">
                            <div class="contact-avatar">
                                <i data-lucide="<?= $c['icon'] ?>"></i>
                                <div class="status-dot"></div>
                            </div>
                            <div class="contact-info">
                                <div class="contact-header">
                                    <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="contact-time"><?= htmlspecialchars($c['time']) ?></div>
                                </div>
                                <div class="contact-footer">
                                    <div class="contact-preview"><?= htmlspecialchars($c['preview']) ?></div>
                                    <?php if($c['unread'] > 0): ?>
                                        <span class="badge-unread"><?= $c['unread'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($normal_contacts)): ?>
                    <div class="list-label" style="margin-top: 8px;">Recent</div>
                    <?php foreach($normal_contacts as $c): ?>
                        <a href="?active_contact=<?= urlencode($c['id']) ?>" class="contact-item <?= ($active_contact_id === $c['id']) ? 'active' : '' ?>" data-category="<?= $c['category'] ?>" data-unread="<?= $c['unread'] ?>">
                            <div class="contact-avatar">
                                <i data-lucide="<?= $c['icon'] ?>"></i>
                            </div>
                            <div class="contact-info">
                                <div class="contact-header">
                                    <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="contact-time"><?= htmlspecialchars($c['time']) ?></div>
                                </div>
                                <div class="contact-footer">
                                    <div class="contact-preview"><?= htmlspecialchars($c['preview']) ?></div>
                                    <?php if($c['unread'] > 0): ?>
                                        <span class="badge-unread"><?= $c['unread'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                
            </div>
        </div>

        <!-- Main Panel -->
        <div class="chat-main-panel">
            
            <?php if ($active_contact_info): ?>
            <!-- Dynamic Chat Header -->
            <div class="chat-main-header">
                <div class="chat-main-header-info">
                    <div class="contact-avatar header-avatar">
                        <i data-lucide="<?= $active_contact_info['icon'] ?>"></i>
                        <div class="status-dot"></div>
                    </div>
                    <div class="header-text-container">
                        <div class="header-name"><?= htmlspecialchars($active_contact_info['name']) ?></div>
                        <div class="header-status">Online</div>
                    </div>
                </div>
                <div class="chat-main-header-actions">
                    <button class="icon-btn" title="More Options"><i data-lucide="more-vertical"></i></button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($active_contact_id === 'group_chat'): ?>
            
                <div id="groupChatPanel">
                    <div class="messages-stream-box" id="groupMessageStream">
                        
                        <?php if (count($group_messages) > 0): ?>
                            <div class="date-separator"><span class="date-separator-text">Today</span></div>
                        <?php endif; ?>

                        <?php if (count($group_messages) == 0): ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i data-lucide="messages-square" style="width: 32px; height: 32px;"></i></div>
                                <p class="empty-text">No messages sent yet.<br>Start the conversation with your team!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($group_messages as $msg): 
                                $is_self = ($msg['sender_id'] == $user_id);
                                $formatted_time = date('h:i A', strtotime($msg['created_at']));
                                $mid = $msg['message_id'];
                            ?>
                                <div class="message-row <?= $is_self ? 'self' : 'other' ?>" data-msgid="<?= $mid ?>">
                                    <?php if (!$is_self): ?>
                                        <img src="<?= htmlspecialchars($msg['sender_avatar'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($msg['sender_name'])) ?>" class="sender-avatar">
                                    <?php endif; ?>
                                    
                                    <div class="message-bubble-data">
                                        <?php if (!$is_self): ?><span class="sender-title"><?= htmlspecialchars($msg['sender_name']) ?></span><?php endif; ?>
                                        <div class="chat-text-balloon" oncontextmenu="toggleReactionPicker(this); return false;">
                                            <?php if (!empty($msg['message_text'])): ?>
                                                <div style="white-space: pre-wrap;"><?= htmlspecialchars($msg['message_text']) ?></div>
                                            <?php endif; ?>
                                            
                                            <!-- Reaction Badge if exists -->
                                            <?php if(!empty($msg['reaction'])): ?>
                                                <div class="reaction-badge" id="badge-grp-<?= $mid ?>"><?= htmlspecialchars($msg['reaction']) ?></div>
                                            <?php else: ?>
                                                <div class="reaction-badge" id="badge-grp-<?= $mid ?>" style="display:none;"></div>
                                            <?php endif; ?>
                                            
                                        </div>
                                        <span class="time-label">
                                            <?= $formatted_time ?> 
                                            <?php if($is_self): ?>
                                                <i data-lucide="check-check" style="width:14px;height:14px;margin-left:2px;color:var(--accent);"></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Reaction Trigger for Desktop Hover -->
                                    <div class="reaction-trigger" onclick="toggleReactionPicker(this)">
                                        <i data-lucide="smile" style="width:14px;height:14px;"></i>
                                    </div>
                                    
                                    <!-- Emoji Picker Box -->
                                    <div class="reaction-picker">
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '❤️', 'group')">❤️</span>
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '👍', 'group')">👍</span>
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '😂', 'group')">😂</span>
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '😮', 'group')">😮</span>
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '😢', 'group')">😢</span>
                                        <span class="reaction-emoji" onclick="sendReaction(<?= $mid ?>, '🙏', 'group')">🙏</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="chat-keyboard-bar">
                        <form method="POST" class="chat-input-form">
                            <input type="hidden" name="action" value="send_message">
                            <!-- Attachment removed -->
                            <textarea class="chat-msg-textarea" name="message_text" placeholder="Write a message..."></textarea>
                            <button type="submit" class="btn-post-msg"><i data-lucide="send"></i></button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                
                <div id="staffChatPanel">
                    <div class="messages-stream-box" id="staffMessageStream">
                        <!-- AJAX content -->
                    </div>
                    <div class="chat-keyboard-bar">
                        <form id="staffChatForm" class="chat-input-form" onsubmit="sendStaffMessage(event)">
                            <textarea class="chat-msg-textarea" id="staffMsgInput" placeholder="Write a message..." required></textarea>
                            <button type="submit" class="btn-post-msg"><i data-lucide="send"></i></button>
                        </form>
                    </div>
                </div>
                
                <script>
                    const activeContact = "<?= htmlspecialchars($active_contact_id) ?>";
                    let targetRole = "";
                    let targetGroup = "";
                    if (activeContact.startsWith('role_')) {
                        targetRole = activeContact.replace('role_', '').replace(/_/g, ' ');
                    } else if (activeContact.startsWith('group_')) {
                        targetGroup = activeContact.replace('group_', '');
                    }

                    function loadStaffMessages() {
                        const fd = new FormData();
                        fd.append('action', 'get_messages');
                        fd.append('target_role', targetRole);
                        fd.append('target_group', targetGroup);
                        
                        fetch('staff_message_handler.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if (data.status === 'success') {
                                const stream = document.getElementById('staffMessageStream');
                                stream.innerHTML = '';
                                if (data.messages.length === 0) {
                                    stream.innerHTML = `
                                        <div class="empty-state">
                                            <div class="empty-icon"><i data-lucide="messages-square" style="width: 32px; height: 32px;"></i></div>
                                            <p class="empty-text">No messages yet.<br>Send a message to start.</p>
                                        </div>
                                    `;
                                    lucide.createIcons();
                                } else {
                                    stream.insertAdjacentHTML('beforeend', '<div class="date-separator"><span class="date-separator-text">Today</span></div>');

                                    data.messages.forEach(msg => {
                                        const isSelf = (msg.sender_id == <?= $user_id ?>);
                                        const dateStr = msg.created_at.replace(' ', 'T');
                                        const time = new Date(dateStr).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                                        const checkIcon = isSelf ? `<i data-lucide="check-check" style="width:14px;height:14px;margin-left:2px;color:var(--accent);"></i>` : '';
                                        const mid = msg.message_id;

                                        let badgeHtml = msg.reaction ? 
                                            `<div class="reaction-badge" id="badge-staff-${mid}">${msg.reaction}</div>` : 
                                            `<div class="reaction-badge" id="badge-staff-${mid}" style="display:none;"></div>`;

                                        const html = `
                                            <div class="message-row ${isSelf ? 'self' : 'other'}" data-msgid="${mid}">
                                                <div class="message-bubble-data">
                                                    ${!isSelf ? `<span class="sender-title">${msg.display_name}</span>` : ''}
                                                    <div class="chat-text-balloon" oncontextmenu="toggleReactionPicker(this); return false;">
                                                        <div style="white-space: pre-wrap;">${msg.message_text}</div>
                                                        ${badgeHtml}
                                                    </div>
                                                    <span class="time-label">${time} ${checkIcon}</span>
                                                </div>
                                                <div class="reaction-trigger" onclick="toggleReactionPicker(this)">
                                                    <i data-lucide="smile" style="width:14px;height:14px;"></i>
                                                </div>
                                                <div class="reaction-picker">
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '❤️', 'staff')">❤️</span>
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '👍', 'staff')">👍</span>
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '😂', 'staff')">😂</span>
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '😮', 'staff')">😮</span>
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '😢', 'staff')">😢</span>
                                                    <span class="reaction-emoji" onclick="sendReaction(${mid}, '🙏', 'staff')">🙏</span>
                                                </div>
                                            </div>
                                        `;
                                        stream.insertAdjacentHTML('beforeend', html);
                                    });
                                    lucide.createIcons();
                                    stream.scrollTop = stream.scrollHeight;
                                }
                            }
                        });
                    }

                    function sendStaffMessage(e) {
                        e.preventDefault();
                        const text = document.getElementById('staffMsgInput').value.trim();
                        if (!text) return;
                        
                        const fd = new FormData();
                        fd.append('action', 'send_message');
                        fd.append('target_role', targetRole);
                        fd.append('target_group', targetGroup);
                        fd.append('message_text', text);
                        
                        document.getElementById('staffMsgInput').value = '';
                        
                        fetch('staff_message_handler.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if(data.status === 'success') loadStaffMessages();
                        });
                    }
                    
                    if (targetRole || targetGroup) {
                        loadStaffMessages();
                        setInterval(loadStaffMessages, 5000); 
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        lucide.createIcons();

        // Reactions logic
        function toggleReactionPicker(el) {
            // Close all others first
            document.querySelectorAll('.reaction-picker.show').forEach(p => p.classList.remove('show'));
            // Find picker in the same row
            const row = el.closest('.message-row');
            if (row) {
                const picker = row.querySelector('.reaction-picker');
                if (picker) picker.classList.toggle('show');
            }
        }
        
        // Hide pickers when clicking outside
        document.addEventListener('click', e => {
            if (!e.target.closest('.reaction-trigger') && !e.target.closest('.reaction-picker') && !e.target.closest('.chat-text-balloon')) {
                document.querySelectorAll('.reaction-picker').forEach(p => p.classList.remove('show'));
            }
        });

        // Double tap for mobile
        let lastTap = 0;
        document.addEventListener('touchstart', function(e) {
            const balloon = e.target.closest('.chat-text-balloon');
            if (balloon) {
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                if (tapLength < 500 && tapLength > 0) {
                    toggleReactionPicker(balloon);
                    e.preventDefault();
                }
                lastTap = currentTime;
            }
        }, {passive: false});

        function sendReaction(msgId, emoji, type) {
            const badge = document.getElementById(`badge-${type === 'group' ? 'grp' : 'staff'}-${msgId}`);
            if (badge) {
                badge.innerText = emoji;
                badge.style.display = 'block';
            }
            // Close pickers
            document.querySelectorAll('.reaction-picker.show').forEach(p => p.classList.remove('show'));
            
            // Save to DB
            const fd = new FormData();
            fd.append('message_id', msgId);
            fd.append('reaction', emoji);
            
            if (type === 'group') {
                fd.append('action', 'react_group_message');
                fetch('message.php', { method: 'POST', body: fd });
            } else {
                fd.append('action', 'react_message');
                fetch('staff_message_handler.php', { method: 'POST', body: fd });
            }
        }

        // -------------------------

        const syncTheme = () => {
            const savedTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = savedTheme;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className;
                    if (pTheme && pTheme !== document.body.className) { document.body.className = pTheme; }
                }
            } catch(e) {}
        }, 500);

        const gstream = document.getElementById('groupMessageStream');
        if (gstream) gstream.scrollTop = gstream.scrollHeight;
        
        const gInput = document.querySelector('#groupChatPanel .chat-msg-textarea');
        if (gInput) {
            gInput.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.closest('form').submit(); }
            });
        }
        const sInput = document.getElementById('staffMsgInput');
        if (sInput) {
            sInput.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendStaffMessage(e); }
            });
        }

        // Filtering Logic
        const searchInput = document.getElementById('contactSearch');
        const filterChips = document.querySelectorAll('.filter-chip');
        const contactItems = document.querySelectorAll('.contact-item');
        let currentFilter = 'all';

        function applyFilters() {
            const query = searchInput.value.toLowerCase();
            
            contactItems.forEach(item => {
                const name = item.querySelector('.contact-name').innerText.toLowerCase();
                const category = item.dataset.category;
                const unread = parseInt(item.dataset.unread || "0");
                
                let matchesSearch = name.includes(query);
                let matchesFilter = false;
                
                if (currentFilter === 'all') matchesFilter = true;
                else if (currentFilter === 'unread' && unread > 0) matchesFilter = true;
                else if (currentFilter === 'groups' && category === 'groups') matchesFilter = true;
                else if (currentFilter === 'staff' && category === 'staff') matchesFilter = true;

                if (matchesSearch && matchesFilter) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Hide section labels if all children are hidden
            document.querySelectorAll('.list-label').forEach(label => {
                let next = label.nextElementSibling;
                let hasVisible = false;
                while (next && next.classList.contains('contact-item')) {
                    if (next.style.display !== 'none') {
                        hasVisible = true;
                        break;
                    }
                    next = next.nextElementSibling;
                }
                label.style.display = hasVisible ? 'block' : 'none';
            });
        }

        if (searchInput) searchInput.addEventListener('input', applyFilters);

        if (filterChips) {
            filterChips.forEach(chip => {
                chip.addEventListener('click', () => {
                    filterChips.forEach(c => c.classList.remove('active'));
                    chip.classList.add('active');
                    currentFilter = chip.dataset.filter;
                    applyFilters();
                });
            });
        }

    </script>
</body>
</html>
EOD;

file_put_contents('c:/xampp/htdocs/Research_Digital/dashboards/message.php', $PHP_CODE);
?>
