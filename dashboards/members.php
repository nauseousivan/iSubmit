<?php
require_once '../config/db.php';
require_once '../config/group_helpers.php';
require_once '../config/mail.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

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

        if ($me['leader_id'] !== null) {
            $message = "Only the assigned research group leader is authorized to invite new members.";
            $message_type = "error";
        } elseif (strcasecmp($search_email, $me['email']) === 0) {
            $message = "You cannot add yourself as a member.";
            $message_type = "error";
        } elseif ($found_user) {
            // Check if they are already in a group or have members
            $stmt_has_members = $pdo->prepare("SELECT COUNT(*) FROM users WHERE leader_id = ?");
            $stmt_has_members->execute([$found_user['user_id']]);
            $has_members = $stmt_has_members->fetchColumn() > 0;

            if ($found_user['leader_id'] !== null || $has_members) {
                $message = "Student is already associated with another research group.";
                $message_type = "error";
            } else {
                // Ensure departments match
                $found_user_dept_code = get_dept_code($found_user['department'] ?? '');
                $me_dept_code = get_dept_code($me['department'] ?? '');
                if ($found_user_dept_code !== $me_dept_code) {
                    $message = "You can only invite students from your same department code (" . htmlspecialchars($me_dept_code) . ").";
                    $message_type = "error";
                } else {
                    link_student_to_leader($pdo, $user_id, $me, $found_user['user_id']);
                    $message = htmlspecialchars($found_user['username']) . " added to research group!";
                    $message_type = "success";
                }
            }
        } else {
            // No account yet: leave a pending invite that auto-links when they register.
            create_or_reactivate_invite($pdo, $user_id, $search_email);
            send_invite_email($search_email, $me['username'], $me['research_group_name'] ?? '');
            $message = "No account found yet for " . htmlspecialchars($search_email) . " \xE2\x80\x94 an invite has been sent and they'll be added automatically once they register.";
            $message_type = "success";
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

    if (isset($_POST['action']) && $_POST['action'] === 'cancel_invite') {
        $invite_id = $_POST['invite_id'];
        $stmt_cancel = $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE id = ? AND leader_id = ?");
        if ($stmt_cancel->execute([$invite_id, $user_id])) {
            $message = "Invite cancelled.";
            $message_type = "success";
        }
    }
}

// Fetch members of my group
$effective_leader_id = $me['leader_id'] ?? $user_id;

$stmt_leader = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_leader->execute([$effective_leader_id]);
$leader_user = $stmt_leader->fetch();

$stmt_members = $pdo->prepare("SELECT * FROM users WHERE leader_id = ?");
$stmt_members->execute([$effective_leader_id]);
$members = $stmt_members->fetchAll();

$pending_invites = [];
if ($me['leader_id'] === null) {
    $stmt_invites = $pdo->prepare("SELECT * FROM group_invites WHERE leader_id = ? AND status = 'pending' ORDER BY created_at DESC");
    $stmt_invites->execute([$user_id]);
    $pending_invites = $stmt_invites->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Group Members</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root { 
            --bg-beige: #f9f7f2; --bg-white: #ffffff; --mcnp-teal: #0f172a; --accent: #7c3aed;
            --border-line: #e2e8f0; --border-light: #f1f5f9; --text-muted: #64748b; --text-lighter: #94a3b8; --text-dark: #0f172a;
            --color-approved: #10b981; --color-revision: #ef4444; --color-pending: #3b82f6;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: var(--ui-sans);
            background: transparent;
            color: var(--text-dark);
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 32px 40px;
            margin: 0;
            overflow: hidden;
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .container { 
            max-width: 760px; 
            margin: 0 auto; 
        }
        
        /* HEADER */
        .page-header {
            margin-bottom: 32px;
            animation: fadeDown 0.4s ease forwards;
        }
        
        .page-title h1 { 
            font-size: 28px; 
            font-weight: 800; 
            color: var(--text-dark); 
            margin-bottom: 8px; 
            letter-spacing: -0.03em; 
        }
        
        .page-title p { 
            font-size: 15px; 
            color: var(--text-muted); 
            line-height: 1.5;
        }

        /* ALERTS */
        .alert { 
            padding: 14px 18px; 
            border-radius: 12px; 
            font-size: 14px; 
            font-weight: 600; 
            margin-bottom: 24px; 
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeDown 0.3s ease;
        }
        .alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #d1fae5; }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }

        /* SECTIONS */
        .section-block {
            margin-bottom: 40px;
            animation: fadeUp 0.5s ease forwards;
        }

        .section-header {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ADD MEMBER - PILL INPUT */
        .invite-container {
            background: var(--bg-white);
            border: 1px solid var(--border-line);
            border-radius: 999px;
            padding: 6px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
        }

        .invite-container:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .invite-icon {
            color: var(--text-lighter);
            margin-left: 14px;
            margin-right: 8px;
        }

        .invite-input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 10px 4px;
            font-family: var(--ui-sans);
            font-size: 15px;
            color: var(--text-dark);
            outline: none;
        }

        .invite-input::placeholder {
            color: var(--text-lighter);
        }

        .btn-add {
            background: var(--text-dark);
            color: white;
            border: none;
            border-radius: 999px;
            padding: 10px 20px;
            font-family: var(--ui-sans);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-add:hover {
            background: var(--accent);
            transform: scale(1.02);
        }

        /* MEMBER LIST */
        .member-list { 
            display: flex; 
            flex-direction: column; 
            gap: 12px; 
        }

        .member-row { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 16px 20px; 
            background: var(--bg-white); 
            border-radius: 16px; 
            border: 1px solid var(--border-light); 
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .member-row:hover {
            border-color: var(--border-line);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }

        .member-info { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
        }

        .member-pfp { 
            width: 48px; 
            height: 48px; 
            border-radius: 50%; 
            object-fit: cover; 
            background: var(--border-light); 
        }

        .member-details h4 { 
            font-size: 15px; 
            font-weight: 700; 
            color: var(--text-dark); 
            margin-bottom: 2px;
            display: flex;
            align-items: center;
        }

        .member-details p { 
            font-size: 13.5px; 
            color: var(--text-muted); 
        }
        
        /* ROLE BADGES */
        .role-badge { 
            font-size: 10px; 
            font-weight: 700; 
            background: #f1f5f9; 
            color: #475569; 
            padding: 4px 8px; 
            border-radius: 6px; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            margin-left: 10px; 
            border: 1px solid #e2e8f0;
        }

        .role-badge.leader {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }
        
        /* SLEEK REMOVE BUTTON */
        .btn-remove { 
            background: transparent; 
            color: var(--text-lighter); 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            padding: 8px;
            transition: all 0.2s; 
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-remove:hover { 
            background: #fef2f2; 
            color: var(--color-revision); 
        }

        .empty-members {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            border: 1px dashed var(--border-line);
            border-radius: 16px;
            background: var(--border-light);
        }

        /* ANIMATIONS */
        @keyframes fadeDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* THEMES */
        body.theme-default, body.theme-blue { --bg-white: #ffffff; --text-dark: #0f172a; --text-muted: #64748b; --border-line: #e2e8f0; --border-light: #f8fafc; --accent: #3b82f6; }
        body.theme-dark { --bg-white: #1e293b; --text-dark: #f8fafc; --text-muted: #94a3b8; --text-lighter: #64748b; --border-line: #334155; --border-light: #0f172a; --accent: #8b5cf6; }

        /* MOBILE RESPONSE */
        @media (max-width: 768px) {
            body { padding: 20px 16px; }
            .invite-container { flex-direction: column; padding: 12px; border-radius: 16px; background: var(--border-light); border: none; }
            .invite-icon { display: none; }
            .invite-input { width: 100%; text-align: center; margin-bottom: 8px; }
            .btn-add { width: 100%; justify-content: center; border-radius: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="page-header">
            <div class="page-title">
                <h1>Research Group</h1>
                <p>Manage and collaborate with your research partners.</p>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert <?= $message_type ?>">
                <?php if ($message_type === 'success'): ?>
                    <i data-lucide="check-circle" style="width: 20px; height: 20px;"></i>
                <?php else: ?>
                    <i data-lucide="alert-circle" style="width: 20px; height: 20px;"></i>
                <?php endif; ?>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($me['leader_id'] === null): ?>
        <div class="section-block" style="animation-delay: 0.1s;">
            <div class="section-header">
                <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i> Invite Member
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="search_user">
                <div class="invite-container">
                    <i data-lucide="mail" class="invite-icon"></i>
                    <input type="email" name="email" class="invite-input" placeholder="studentname@mcnp.edu.ph" required>
                    <button type="submit" class="btn-add">
                        Invite <i data-lucide="arrow-right" style="width: 16px; height: 16px;"></i>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!empty($pending_invites)): ?>
        <div class="section-block" style="animation-delay: 0.15s;">
            <div class="section-header">
                <i data-lucide="mail-question" style="width: 16px; height: 16px;"></i> Pending Invites
            </div>
            <div class="member-list">
                <?php foreach ($pending_invites as $invite): ?>
                    <div class="member-row">
                        <div class="member-info">
                            <img src="https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($invite['invitee_email']) ?>" class="member-pfp" style="opacity: 0.5;">
                            <div class="member-details">
                                <h4><?= htmlspecialchars($invite['invitee_email']) ?><span class="role-badge">Pending</span></h4>
                                <p>Invited <?= htmlspecialchars(date('M j, Y', strtotime($invite['created_at']))) ?> &mdash; will join automatically once they register.</p>
                            </div>
                        </div>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="cancel_invite">
                            <input type="hidden" name="invite_id" value="<?= $invite['id'] ?>">
                            <button type="submit" class="btn-remove" title="Cancel Invite" onclick="return confirm('Cancel the invite to <?= htmlspecialchars($invite['invitee_email']) ?>?');">
                                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-block" style="animation-delay: 0.2s;">
            <div class="section-header">
                <i data-lucide="users" style="width: 16px; height: 16px;"></i> Current Roster
            </div>
            <div class="member-list">
                
                <!-- Effective Leader / Head Row -->
                <div class="member-row">
                    <div class="member-info">
                        <?php $l_pfp = $leader_user['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($leader_user['username']); ?>
                        <img src="<?= htmlspecialchars($l_pfp) ?>" class="member-pfp">
                        <div class="member-details">
                            <h4>
                                <?= htmlspecialchars($leader_user['username']) ?>
                                <span class="role-badge leader">Group Leader</span>
                            </h4>
                            <p><?= htmlspecialchars($leader_user['email']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Members Loop -->
                <?php if (count($members) > 0): ?>
                    <?php foreach ($members as $member): ?>
                        <div class="member-row">
                            <div class="member-info">
                                <?php $m_pfp = $member['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($member['username']); ?>
                                <img src="<?= htmlspecialchars($m_pfp) ?>" class="member-pfp">
                                <div class="member-details">
                                    <h4>
                                        <?= htmlspecialchars($member['username']) ?>
                                        <span class="role-badge">Member</span>
                                    </h4>
                                    <p><?= htmlspecialchars($member['email']) ?></p>
                                </div>
                            </div>
                            <?php if ($me['leader_id'] === null): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="member_id" value="<?= $member['user_id'] ?>">
                                    <button type="submit" class="btn-remove" title="Remove Member" onclick="return confirm('Remove <?= htmlspecialchars($member['username']) ?> from your research group?');">
                                        <i data-lucide="user-minus" style="width: 18px; height: 18px;"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if ($me['leader_id'] === null): ?>
                        <div class="empty-members">
                            <i data-lucide="user-x" style="width: 32px; height: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p>You haven't added any members yet.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>
    </div>
    
    <script>
        lucide.createIcons();
        
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
