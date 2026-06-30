<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$me_role = $_SESSION['role'];

// Resilient self-bootstrapping table check/creation for group_messages
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        group_identifier INT NOT NULL,
        sender_id INT NOT NULL,
        message_text TEXT NOT NULL,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
            message_id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_identifier INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            message_text TEXT NOT NULL,
            attachment_path TEXT DEFAULT NULL,
            attachment_name TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e2) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
                message_id SERIAL PRIMARY KEY,
                group_identifier INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                message_text TEXT NOT NULL,
                attachment_path VARCHAR(255) DEFAULT NULL,
                attachment_name VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e3) { }
    }
}

// Resilient column checks/additions for existing deployments
try {
    $pdo->exec("ALTER TABLE group_messages ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) { }
try {
    $pdo->exec("ALTER TABLE group_messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) { }

// Determine effective leader_id if student (students share same chat feed inside their own research group)
if ($me_role === 'Student') {
    $stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
    $stmt_leader_check->execute([$user_id]);
    $u_data = $stmt_leader_check->fetch();
    $leader_id = $u_data['leader_id'] ?? $user_id; // If they have no leader, they are the leader themselves
    $group_identifier = $leader_id;
} else {
    // Coordinator sees all messages directed to departments or general
    $chat_context = $_GET['chat_context'] ?? '0';
    if (strpos($chat_context, 'group_') === 0) {
        $group_identifier = intval(str_replace('group_', '', $chat_context));
    } else {
        $group_identifier = intval($chat_context);
    }
}

if (empty($group_identifier)) {
    $group_identifier = $user_id;
}

$me_username = $_SESSION['username'];
$selected_avatar = $_SESSION['profile_pic'] ?? "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($me_username);

// API/Form action to post message with potential file attachment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $msg_text = trim($_POST['message_text'] ?? '');
    
    $attachment_path = null;
    $attachment_name = null;
    
    // File upload logic
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $original_name = basename($file['name']);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'zip'];
        if (in_array($ext, $allowed_exts)) {
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $new_filename = 'msg_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $file_path = $upload_dir . $new_filename;
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                $attachment_path = '../uploads/' . $new_filename;
                $attachment_name = $original_name;
            }
        }
    }
    
    if (!empty($msg_text) || !empty($attachment_path)) {
        $stmt_send = $pdo->prepare("INSERT INTO group_messages (group_identifier, sender_id, message_text, attachment_path, attachment_name) VALUES (?, ?, ?, ?, ?)");
        $stmt_send->execute([$group_identifier, $user_id, $msg_text, $attachment_path, $attachment_name]);
    }
    
    // Return simple JSON if AJAX, otherwise regular redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: message.php?chat_context=group_" . urlencode($group_identifier));
    exit;
}

// Fetch messages joined with users to get sender details
try {
    $stmt_msg = $pdo->prepare("SELECT gm.*, u.username as sender_name, u.profile_pic as sender_avatar FROM group_messages gm LEFT JOIN users u ON gm.sender_id = u.user_id WHERE gm.group_identifier = ? ORDER BY gm.created_at ASC LIMIT 100");
    $stmt_msg->execute([$group_identifier]);
    $messages = $stmt_msg->fetchAll();
} catch (Exception $e) {
    $messages = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Messenger Desk</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root { 
            --bg-beige: #f9f7f2; --bg-white: #ffffff; --mcnp-teal: #0c343d; --mcnp-hover: #144652;
            --border-line: #e5e7eb; --text-muted: #6b7280; --text-dark: #1f2937;
            --bubble-self: #0c343d; --text-self: #ffffff;
            --bubble-other: #f3f4f6; --text-other: #1f2937;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; width: 100%; overflow: hidden; }
        body { font-family: 'Cambria', serif; background-color: var(--bg-white); color: var(--text-dark); display: flex; flex-direction: column; transition: 0.3s; }
        
        .chat-app-container {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg-white);
        }
        
        /* Coordinator Context Panel selection layout elements optionally compiled */
        
        .chat-main-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
            overflow: hidden;
            background: var(--bg-white);
        }
        
        /* Chat Area Header */
        .chat-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid var(--border-line); background: var(--bg-white); flex-shrink: 0; }
        .chat-header-info h3 { font-family: var(--ui-sans); font-size: 16px; font-weight: 800; color: var(--mcnp-teal); }
        .chat-header-info p { font-size: 12px; color: var(--text-muted); }
 
        /* Messages Stream Area SCROLL */
        .messages-stream-box {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: var(--bg-beige);
        }
        .messages-stream-box::-webkit-scrollbar { width: 6px; }
        .messages-stream-box::-webkit-scrollbar-thumb { background-color: rgba(0,0,0,0.15); border-radius: 10px; }
        
        .message-row { display: flex; gap: 12px; max-width: 70%; width: fit-content; align-items: flex-end; }
        .message-row.self { margin-left: auto; flex-direction: row-reverse; max-width: 70%; }
        
        .sender-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--mcnp-teal); background: white; flex-shrink: 0; }
        
        .message-bubble-data { display: flex; flex-direction: column; gap: 4px; }
        .message-bubble-data .sender-title { font-family: var(--ui-sans); font-size: 11px; font-weight: bold; color: var(--text-muted); padding-left: 6px; }
        .message-row.self .message-bubble-data .sender-title { text-align: right; padding-right: 6px; }
        
        .chat-text-balloon { padding: 12px 18px; border-radius: 18px; font-size: 14px; font-family: var(--ui-sans); line-height: 1.45; word-break: break-word; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .message-row.self .chat-text-balloon { background: var(--bubble-self); color: var(--text-self); border-bottom-right-radius: 4px; }
        .message-row.other .chat-text-balloon { background: var(--bubble-other); color: var(--text-other); border-bottom-left-radius: 4px; }
        
        .time-label { font-size: 9px; color: var(--text-muted); padding: 0 6px; font-family: var(--ui-sans); margin-top: 2px; }
        .message-row.self .time-label { text-align: right; }

        /* Input control desk footer */
        .chat-keyboard-bar { padding: 16px 24px; border-top: 1px solid var(--border-line); background: var(--bg-white); flex-shrink: 0; }
        .chat-input-form { display: flex; align-items: center; gap: 12px; }
        .chat-msg-textarea { flex: 1; padding: 14px 18px; border-radius: 24px; border: 1.5px solid var(--border-line); background: var(--bg-beige); font-family: var(--ui-sans); font-size: 14px; resize: none; outline: none; height: 48px; line-height: 1.2; }
        .chat-msg-textarea:focus { border-color: var(--mcnp-teal); background: var(--bg-white); }
        
        .btn-attach { width: 48px; height: 48px; border-radius: 50%; border: 1.5px solid var(--border-line); background: var(--bg-white); color: var(--text-muted); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; flex-shrink: 0; }
        .btn-attach:hover { color: var(--mcnp-teal); border-color: var(--mcnp-teal); background: var(--bg-beige); }
        .btn-attach svg { width: 20px; height: 20px; stroke-width: 2.5px; fill: none; stroke: currentColor; }

        .btn-post-msg { font-family: var(--ui-sans); width: 48px; height: 48px; border-radius: 50%; border: none; background: linear-gradient(to bottom right, var(--mcnp-teal), var(--mcnp-hover)); color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.2s; flex-shrink: 0; }
        .btn-post-msg:hover { transform: scale(1.05); }
        .btn-post-msg svg { width: 18px; height: 18px; stroke-width: 2.5px; fill: none; stroke: currentColor; }

        /* Attachment preview styling */
        .attachment-preview-bar { display: none; align-items: center; justify-content: space-between; padding: 8px 16px; background: var(--bg-beige); border-radius: 12px; margin-bottom: 8px; font-size: 13px; font-family: var(--ui-sans); color: var(--text-dark); border: 1px solid var(--border-line); }
        .attachment-preview-name { display: flex; align-items: center; gap: 8px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .btn-remove-attachment { background: none; border: none; color: #ef4444; font-size: 18px; font-weight: bold; cursor: pointer; padding: 0 4px; display: flex; align-items: center; justify-content: center; }

        /* Mobile specific styling */
        @media (max-width: 640px) {
            .chat-header { display: none !important; }
            .messages-stream-box { padding: 16px; gap: 12px; }
            .message-row { max-width: 85%; }
            .message-row.self { max-width: 85%; }
            .chat-keyboard-bar { padding: 10px 16px !important; padding-bottom: max(10px, env(safe-area-inset-bottom)) !important; }
            .chat-msg-textarea { padding: 12px 14px; height: 42px; font-size: 16px !important; border-radius: 18px; }
            .btn-post-msg { width: 42px; height: 42px; }
            .btn-attach { width: 42px; height: 42px; }
            .chat-text-balloon { padding: 10px 14px; font-size: 13.5px; }
        }

        body.theme-default, body.theme-blue { --bg-beige: #e8f0ff; --bg-white: #ffffff; --text-dark: #1c2a44; --text-muted: #5f6f8a; --border-line: #c6d4e9; --mcnp-teal: #4a7c8c; --mcnp-hover: #3b6370; --bubble-self: #4a7c8c; --bubble-other: #f1f5f9; }
        body.theme-red { --bg-beige: #ffe8e8; --bg-white: #ffffff; --text-dark: #4c1f20; --text-muted: #9d5b5c; --border-line: #f2c7c7; --mcnp-teal: #d65a5a; --mcnp-hover: #c04f4f; --bubble-self: #d65a5a; --bubble-other: #f5ecec; }
        body.theme-pink, body.theme-rose { --bg-beige: #fde8f5; --bg-white: #ffffff; --text-dark: #4c2346; --text-muted: #9f628d; --border-line: #f3c7dc; --mcnp-teal: #c56ba8; --mcnp-hover: #ac5e94; --bubble-self: #c56ba8; --bubble-other: #ece3ea; }
        body.theme-green { --bg-beige: #e8f6ea; --bg-white: #ffffff; --text-dark: #2f4a33; --text-muted: #6d8b75; --border-line: #c9dec9; --mcnp-teal: #4a9e7b; --mcnp-hover: #3a8565; --bubble-self: #4a9e7b; --bubble-other: #e3ece8; }
        body.theme-dark { --bg-beige: #1a1d21; --bg-white: #24282d; --text-dark: #e0e0e0; --text-muted: #b0ada8; --border-line: #3a3f45; --mcnp-teal: #4e9cae; --mcnp-hover: #5fb3c8; --bubble-self: #4e9cae; --bubble-other: #3a3f45; --text-other: #e0e0e0; }
    </style>
</head>
<body>
    <div class="chat-app-container">
        <div class="chat-main-panel">
            <div class="chat-header">
                <div class="chat-header-info">
                    <h3>Group Message Thread</h3>
                    <p>Submitting research topics and coordination feedback within your group circle.</p>
                </div>
            </div>

            <!-- Messages Stream -->
            <div class="messages-stream-box" id="messageStream">
                <?php if (count($messages) == 0): ?>
                    <p style="text-align: center; color: var(--text-muted); margin: auto; font-family: var(--ui-sans); font-size: 13px;">No messages sent yet. Start the conversation!</p>
                <?php else: ?>
                    <?php foreach ($messages as $msg): 
                        $is_self = ($msg['sender_id'] == $user_id);
                        $formatted_time = date('h:i A', strtotime($msg['created_at']));
                    ?>
                        <div class="message-row <?= $is_self ? 'self' : 'other' ?>">
                            <img src="<?= htmlspecialchars($msg['sender_avatar'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($msg['sender_name'])) ?>" class="sender-avatar">
                            <div class="message-bubble-data">
                                <?php if (!$is_self): ?>
                                    <span class="sender-title"><?= htmlspecialchars($msg['sender_name']) ?></span>
                                <?php endif; ?>
                                <div class="chat-text-balloon">
                                    <?php if (!empty($msg['message_text'])): ?>
                                        <div class="msg-body-text" style="white-space: pre-wrap;"><?= htmlspecialchars($msg['message_text']) ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($msg['attachment_path'])): 
                                        $file_path = $msg['attachment_path'];
                                        $file_name = $msg['attachment_name'];
                                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                    ?>
                                        <div class="msg-attachment-box" style="margin-top: <?= !empty($msg['message_text']) ? '10px' : '0px' ?>;">
                                            <?php if ($is_image): ?>
                                                <a href="<?= htmlspecialchars($file_path) ?>" target="_blank">
                                                    <img src="<?= htmlspecialchars($file_path) ?>" alt="<?= htmlspecialchars($file_name) ?>" style="max-width: 100%; max-height: 200px; border-radius: 10px; display: block; border: 1px solid rgba(0,0,0,0.1);" referrerPolicy="no-referrer">
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($file_path) ?>" target="_blank" download="<?= htmlspecialchars($file_name) ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; background: rgba(0,0,0,0.06); border-radius: 10px; color: inherit; text-decoration: none; font-size: 13px; font-family: var(--ui-sans); font-weight: 500;">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                                                    </svg>
                                                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;"><?= htmlspecialchars($file_name) ?></span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="time-label"><?= $formatted_time ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Text Area keyboard input desk footer -->
            <div class="chat-keyboard-bar">
                <!-- File Attachment Preview Bar -->
                <div id="attachmentPreviewBar" class="attachment-preview-bar">
                    <div id="attachmentNameSpan" class="attachment-preview-name"></div>
                    <button type="button" class="btn-remove-attachment" onclick="clearAttachment()">&times;</button>
                </div>

                <form id="chatForm" method="POST" class="chat-input-form" onsubmit="sendMessage(event)">
                    <input type="hidden" name="action" value="send_message">
                    
                    <button type="button" class="btn-attach" onclick="triggerFileSelect()">
                        <svg viewBox="0 0 24 24">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                        </svg>
                    </button>
                    <input type="file" id="chatFileInput" name="attachment" style="display: none;" onchange="handleFileSelected()">

                    <textarea class="chat-msg-textarea" name="message_text" id="chatMsgInput" placeholder="Write group message..." required onkeydown="checkSubmit(event)"></textarea>
                    
                    <button type="submit" class="btn-post-msg">
                        <svg viewBox="0 0 24 24">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script>
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
                    if (pTheme && pTheme !== document.body.className) {
                        document.body.className = pTheme;
                    }
                }
            } catch(e) {}
        }, 500);

        function scrollToBottom() {
            const m = document.getElementById('messageStream');
            m.scrollTop = m.scrollHeight;
        }
        window.addEventListener('load', scrollToBottom);

        function checkSubmit(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage(e);
            }
        }

        function triggerFileSelect() {
            document.getElementById('chatFileInput').click();
        }

        function handleFileSelected() {
            const fileInput = document.getElementById('chatFileInput');
            const previewBar = document.getElementById('attachmentPreviewBar');
            const nameSpan = document.getElementById('attachmentNameSpan');
            
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                nameSpan.innerHTML = `
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                    </svg>
                    <span>${file.name} (${formatBytes(file.size)})</span>
                `;
                previewBar.style.display = 'flex';
                document.getElementById('chatMsgInput').removeAttribute('required');
            } else {
                clearAttachment();
            }
        }

        function clearAttachment() {
            const fileInput = document.getElementById('chatFileInput');
            const previewBar = document.getElementById('attachmentPreviewBar');
            if (fileInput) fileInput.value = '';
            if (previewBar) previewBar.style.display = 'none';
            
            const input = document.getElementById('chatMsgInput');
            if (input && input.value.trim() === '') {
                input.setAttribute('required', 'required');
            }
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        // Handle dynamic required toggle
        const inputEl = document.getElementById('chatMsgInput');
        if (inputEl) {
            inputEl.addEventListener('input', () => {
                const fileInput = document.getElementById('chatFileInput');
                if (inputEl.value.trim() !== '' || (fileInput && fileInput.files.length > 0)) {
                    inputEl.removeAttribute('required');
                } else {
                    inputEl.setAttribute('required', 'required');
                }
            });
        }

        function sendMessage(event) {
            if (event && event.preventDefault) event.preventDefault();
            const input = document.getElementById('chatMsgInput');
            const text = input.value.trim();
            const fileInput = document.getElementById('chatFileInput');
            
            if (text === '' && (!fileInput || fileInput.files.length === 0)) return;

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('message_text', text);
            if (fileInput && fileInput.files.length > 0) {
                formData.append('attachment', fileInput.files[0]);
            }
            
            // Clear input and attachment immediately for snappy feel
            input.value = '';
            if (fileInput) fileInput.value = '';
            const previewBar = document.getElementById('attachmentPreviewBar');
            if (previewBar) previewBar.style.display = 'none';

            const xhr = new XMLHttpRequest();
            xhr.open("POST", "message.php", true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.onload = function () {
                if (xhr.status === 200) {
                    window.location.reload();
                }
            };
            xhr.send(formData);
        }
    </script>
</body>
</html>
