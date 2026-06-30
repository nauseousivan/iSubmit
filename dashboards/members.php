<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

function get_dept_code($department_raw) {
    if (!$department_raw) return '';
    if (strpos($department_raw, 'Medical Colleges') !== false) { return 'MCNP'; }
    if (strpos($department_raw, 'International School') !== false) { return 'ISAP'; }
    return $department_raw;
}

// Checks of role
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch();

if ($me['role'] !== 'Student') { exit("Access Denied"); }

// Handle adding members or updating leader/membership rules
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'search_user') {
        $search_email = trim($_POST['email']);
        $stmt_find = $pdo->prepare("SELECT user_id, username, email, leader_id, role, department FROM users WHERE email = ? AND role = 'Student'");
        $stmt_find->execute([$search_email]);
        $found_user = $stmt_find->fetch();

        if ($found_user) {
            // Check if they are already in a group or have members
            $stmt_has_members = $pdo->prepare("SELECT COUNT(*) FROM users WHERE leader_id = ?");
            $stmt_has_members->execute([$found_user['user_id']]);
            $has_members = $stmt_has_members->fetchColumn() > 0;

            if ($found_user['leader_id'] !== null || $has_members) {
                $message = "Student is already associated with another research group.";
                $message_type = "error";
            } elseif ($found_user['user_id'] == $user_id) {
                $message = "You cannot add yourself as a member.";
                $message_type = "error";
            } else {
                // Ensure departments match
                $found_user_dept_code = get_dept_code($found_user['department'] ?? '');
                $me_dept_code = get_dept_code($me['department'] ?? '');
                if ($found_user_dept_code !== $me_dept_code) {
                    $message = "You can only invite students from your same department code (" . htmlspecialchars($me_dept_code) . ").";
                    $message_type = "error";
                } else {
                    // Update leader_id
                    // Effective leader is me, or if I have a leader, that leader is the supervisor/head
                    // In our model: the user creating the group becomes the effective leader (and leader_id = null for them)
                    // If the current user has a leader, then they cannot add members (only leader can)
                    if ($me['leader_id'] !== null) {
                        $message = "Only the assigned research group leader is authorized to invite new members.";
                        $message_type = "error";
                    } else {
                        // Associate research group title
                        $grp = $me['research_group_name'] ?: "My Group";
                        $stmt_add = $pdo->prepare("UPDATE users SET leader_id = ?, research_group_name = ? WHERE user_id = ?");
                        if ($stmt_add->execute([$user_id, $grp, $found_user['user_id']])) {
                            $message = htmlspecialchars($found_user['username']) . " added to research group!";
                            $message_type = "success";
                        }
                    }
                }
            }
        } else {
            $message = "A Student account with that institutional email address was not found.";
            $message_type = "error";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'remove_member') {
        $member_id_to_remove = $_POST['member_id'];
        if ($me['leader_id'] !== null) {
            $message = "Only the research group leader can remove members.";
            $message_type = "error";
        } else {
            $stmt_pk = $pdo->prepare("UPDATE users SET leader_id = NULL, research_group_name = NULL WHERE user_id = ? AND leader_id = ?");
            if ($stmt_pk->execute([$member_id_to_remove, $user_id])) {
                $message = "Member successfully removed from the group.";
                $message_type = "success";
            }
        }
    }
}

// Fetch members of my group (if I am leader, those who have leader_id = my id)
// If I am member, fetch those who have leader_id = my leader's id, plus the leader themselves
$effective_leader_id = $me['leader_id'] ?? $user_id;

$stmt_leader = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_leader->execute([$effective_leader_id]);
$leader_user = $stmt_leader->fetch();

$stmt_members = $pdo->prepare("SELECT * FROM users WHERE leader_id = ?");
$stmt_members->execute([$effective_leader_id]);
$members = $stmt_members->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Group Members</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

        :root { 
            --bg-beige: #f9f7f2; --bg-white: #ffffff; --mcnp-teal: #0c343d; --mcnp-hover: #144652;
            --border-line: #e5e7eb; --text-muted: #6b7280; --text-dark: #1f2937;
            --color-approved: #27ae60; --color-revision: #c0392b;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Cambria', serif; background-color: var(--bg-white); color: var(--text-dark); min-height: 100vh; padding: 24px; transition: 0.3s; }
        body::-webkit-scrollbar { display: none; }
        
        .container { max-width: 800px; margin: 0 auto; }
        
        .header { margin-bottom: 25px; }
        .page-title h1 { font-family: var(--ui-sans); font-size: 32px; font-weight: 800; color: var(--mcnp-teal); margin-bottom: 4px; letter-spacing: -0.025em; }
        .page-title p { font-size: 15px; color: var(--text-muted); }

        .card { background: var(--bg-white); padding: 30px; border-radius: 20px; border: 1px solid var(--border-line); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
        .card h2 { font-family: var(--ui-sans); font-size: 20px; font-weight: 800; color: var(--mcnp-teal); margin-bottom: 20px; }
        
        .alert { padding: 14px; border-radius: 12px; font-family: var(--ui-sans); font-size: 13px; font-weight: 600; margin-bottom: 20px; border-left: 4px solid; }
        .alert.success { background: #e6f4ea; color: #137333; border-left-color: #27ae60; border: 1px solid #c2e7c9; border-left-width: 4px; }
        .alert.error { background: #fef2f2; color: #b71c1c; border-left-color: #c0392b; border: 1px solid #f9d5d5; border-left-width: 4px; }

        .form-row { display: flex; gap: 10px; margin-bottom: 12px; }
        input[type="email"] { flex: 1; padding: 14px; border-radius: 12px; border: 1.5px solid var(--border-line); background: var(--bg-beige); font-family: var(--ui-sans); font-size: 14px; }
        input[type="email"]:focus { outline: none; border-color: var(--mcnp-teal); background: var(--bg-white); }
        
        .btn-action { font-family: var(--ui-sans); padding: 14px 24px; border-radius: 12px; border: none; font-weight: 800; cursor: pointer; font-size: 14px; transition: 0.2s; white-space: nowrap; }
        .btn-add { background: linear-gradient(to bottom right, var(--mcnp-teal), var(--mcnp-hover)); color: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        /* Member List Styling */
        .member-list { display: flex; flex-direction: column; gap: 12px; }
        .member-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: var(--bg-beige); border-radius: 12px; border: 1px solid var(--border-line); }
        .member-info { display: flex; align-items: center; gap: 12px; }
        .member-pfp { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--mcnp-teal); background: white; }
        .member-details h4 { font-family: var(--ui-sans); font-size: 14px; font-weight: 700; color: var(--text-dark); }
        .member-details p { font-family: var(--ui-sans); font-size: 11px; color: var(--text-muted); }
        
        .role-badge { font-family: var(--ui-sans); font-size: 9px; font-weight: bold; background: var(--mcnp-teal); color: white; padding: 3px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.05em; margin-left: 8px; }
        
        .btn-remove { font-family: var(--ui-sans); padding: 8px 12px; font-size: 11px; font-weight: bold; background: #e74c3c; color: white; border: none; border-radius: 8px; cursor: pointer; transition: 0.2s; }
        .btn-remove:hover { background: #c0392b; transform: translateY(-1px); }

        /* Mobile adaptions */
        @media (max-width: 640px) {
            body { padding: 12px !important; }
            .header { display: none !important; }
            .card { 
                padding: 0 !important; 
                background: transparent !important; 
                border: none !important; 
                box-shadow: none !important; 
                margin-bottom: 20px !important;
            }
            .card h2 {
                font-size: 16px !important;
                margin-bottom: 12px !important;
                padding-left: 4px;
            }
            .form-row { flex-direction: column; gap: 8px; }
            .btn-action { width: 100%; border-radius: 10px !important; padding: 12px !important; }
            input[type="email"] { border-radius: 10px !important; padding: 12px !important; background-color: var(--bg-card, #ffffff) !important; }
            .member-row { 
                flex-direction: row !important; 
                align-items: center !important; 
                justify-content: space-between !important; 
                padding: 12px 14px !important; 
                background: var(--bg-card, #ffffff) !important;
                border-radius: 12px !important;
                box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
            }
            .member-pfp {
                width: 36px !important;
                height: 36px !important;
            }
            .member-details h4 {
                font-size: 13px !important;
            }
            .member-details p {
                font-size: 10px !important;
            }
            .role-badge {
                font-size: 8px !important;
                padding: 2px 6px !important;
            }
            .btn-remove { align-self: center; width: auto; font-size: 10px; padding: 6px 10px; border-radius: 6px; }
        }

        body.theme-default, body.theme-blue { --bg-beige: #e8f0ff; --bg-white: #ffffff; --text-dark: #1c2a44; --text-muted: #5f6f8a; --border-line: #c6d4e9; --mcnp-teal: #4a7c8c; --mcnp-hover: #3b6370; }
        body.theme-red { --bg-beige: #ffe8e8; --bg-white: #ffffff; --text-dark: #4c1f20; --text-muted: #9d5b5c; --border-line: #f2c7c7; --mcnp-teal: #d65a5a; --mcnp-hover: #c04f4f; }
        body.theme-pink, body.theme-rose { --bg-beige: #fde8f5; --bg-white: #ffffff; --text-dark: #4c2346; --text-muted: #9f628d; --border-line: #f3c7dc; --mcnp-teal: #c56ba8; --mcnp-hover: #ac5e94; }
        body.theme-green { --bg-beige: #e8f6ea; --bg-white: #ffffff; --text-dark: #2f4a33; --text-muted: #6d8b75; --border-line: #c9dec9; --mcnp-teal: #4a9e7b; --mcnp-hover: #3a8565; }
        body.theme-purple, body.theme-lavender { --bg-beige: #f5f3ff; --bg-white: #ffffff; --text-dark: #4c1d95; --text-muted: #9c9284; --border-line: #ddd6fe; --mcnp-teal: #6d28d9; --mcnp-hover: #4c1d95; }
        body.theme-orange, body.theme-amber { --bg-beige: #fffbeb; --bg-white: #ffffff; --text-dark: #78350f; --text-muted: #9c9284; --border-line: #fde68a; --mcnp-teal: #b45309; --mcnp-hover: #78350f; }
        body.theme-dark { --bg-beige: #1a1d21; --bg-white: #24282d; --text-dark: #e0e0e0; --text-muted: #b0ada8; --border-line: #3a3f45; --mcnp-teal: #4e9cae; --mcnp-hover: #5fb3c8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="page-title">
                <h1>Members</h1>
                <p>Manage and view your research partners.</p>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($me['leader_id'] === null): ?>
        <div class="card">
            <h2>Add Group Member</h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px; font-family: var(--ui-sans);">Members must have a registered student account to be linked to your files.</p>
            <form method="POST">
                <input type="hidden" name="action" value="search_user">
                <div class="form-row">
                    <input type="email" name="email" placeholder="studentname@mcnp.edu.ph" required>
                    <button type="submit" class="btn-action btn-add">Add Member</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Current Group</h2>
            <div class="member-list">
                
                <!-- Effective Leader / Head Row -->
                <div class="member-row">
                    <div class="member-info">
                        <?php 
                            $l_pfp = $leader_user['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($leader_user['username']);
                        ?>
                        <img src="<?= htmlspecialchars($l_pfp) ?>" class="member-pfp">
                        <div class="member-details">
                            <span style="display:flex; align-items:center;">
                                <h4><?= htmlspecialchars($leader_user['username']) ?></h4>
                                <span class="role-badge">Group Leader</span>
                            </span>
                            <p><?= htmlspecialchars($leader_user['email']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Members Roster Loop -->
                <?php if (count($members) > 0): ?>
                    <?php foreach ($members as $member): ?>
                        <div class="member-row">
                            <div class="member-info">
                                <?php 
                                    $m_pfp = $member['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($member['username']);
                                ?>
                                <img src="<?= htmlspecialchars($m_pfp) ?>" class="member-pfp">
                                <div class="member-details">
                                    <h4><?= htmlspecialchars($member['username']) ?></h4>
                                    <p><?= htmlspecialchars($member['email']) ?></p>
                                </div>
                            </div>
                            <?php if ($me['leader_id'] === null): // Show only to leader ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= $member['user_id'] ?>">
                                    <button type="submit" class="btn-remove" onclick="return confirm('Remove <?= htmlspecialchars($member['username']) ?> from your research group?');">Remove Member</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="font-size: 13px; color: var(--text-muted); text-align: center; margin-top: 15px; font-family: var(--ui-sans);">No linked members registered under this group yet.</p>
                <?php endif; ?>

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
    </script>
</body>
</html>
