<?php
require_once '../config/db.php';
require_once '../config/mail.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Enforce Session Access Rule
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Research Director') {
    header("Location: ../auth/login.php");
    exit();
}

$uid = $_SESSION['user_id'];
$message = "";

// Profile Information save (username + avatar preset; theme is client-only via localStorage)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'update_profile') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $message = "Invalid security token. Please refresh and try again.";
    } else {
        $new_username = trim($_POST['username'] ?? '');
        $new_pfp = trim($_POST['profile_pic'] ?? '');
        if (!empty($new_username)) {
            $stmt_up = $pdo->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE user_id = ?");
            $stmt_up->execute([$new_username, $new_pfp, $uid]);
            $_SESSION['username'] = $new_username;
        }
        $message = "Profile information updated successfully.";
    }
}

// Change Password save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'update_password') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $message = "Invalid security token. Please refresh and try again.";
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass)) {
            $message = "Error: All password fields are required.";
        } else {
            $stmt_pw = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmt_pw->execute([$uid]);
            $db_pass = $stmt_pw->fetchColumn();

            if (!password_verify($current_pass, $db_pass)) {
                $message = "Error: Current password is incorrect.";
            } elseif ($new_pass !== $confirm_pass) {
                $message = "Error: New passwords do not match.";
            } else {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt_up_pw = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt_up_pw->execute([$hashed, $uid]);
                $message = "Password updated successfully.";
            }
        }
    }
}

// Fetch active profile details
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = (!empty($currentUser['profile_pic'])) ? $currentUser['profile_pic'] : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

// 0. Process Payment Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'verify_payment') {
    $approval_id = $_POST['approval_id'];
    $new_status = $_POST['payment_status'];

    $stmt = $pdo->prepare("UPDATE approvals SET payment_status = ? WHERE approval_id = ?");
    $stmt->execute([$new_status, $approval_id]);
    $message = "Group payment status updated to " . strtoupper($new_status) . ".";
}

// 1. Process System Level Structural Final Signoffs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'final_signoff') {
    $approval_id = $_POST['approval_id'];
    $status = $_POST['director_status'];

    $message_warning = "";

    // Update the approval workflow table
    $stmt = $pdo->prepare("SELECT user_id, form_id FROM approvals WHERE approval_id = ?");
    $stmt->execute([$approval_id]);
    $app_data = $stmt->fetch();

    if ($app_data) {
        $user_id = $app_data['user_id'];
        $form_id = $app_data['form_id'];

        // Check payment status for informative warning
        if ($status === 'Approved') {
            $pay_check = $pdo->prepare("SELECT payment_status FROM approvals WHERE approval_id = ?");
            $pay_check->execute([$approval_id]);
            if ($pay_check->fetchColumn() === 'Unpaid') {
                $message_warning = " (⚠️ Caution: Payment was not yet verified)";
            }
        }

        $stmt = $pdo->prepare("UPDATE approvals SET director_status = ? WHERE approval_id = ?");
        $stmt->execute([$status, $approval_id]);

        // If Director approves, flip all related student uploads to 'Approved'
        if ($status === 'Approved') {
            $update_uploads = $pdo->prepare("
                UPDATE uploads 
                SET verification_status = 'Approved' 
                WHERE user_id = ? AND item_id IN (SELECT item_id FROM checklist_items WHERE form_id = ?)
            ");
            $update_uploads->execute([$user_id, $form_id]);

            // Log activity for the student
            $log_title = "Research Stage Final Approval";
            $log_desc = "Your study stage milestone files have been officially APPROVED by the Research Director.";
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $log_stmt->execute([$user_id, $log_title, $log_desc, 'success']);
        }
    }

    $message = "Workflow final signature state successfully modified." . $message_warning;
}

// 1.5 Process Individual Document Verifications by Director
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'verify_upload') {
    $upload_id = $_POST['upload_id'];
    $status = $_POST['verification_status'];
    $remarks = trim($_POST['remarks']);
    $student_user_id = $_POST['student_user_id'];

    $stmt = $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ? WHERE upload_id = ?");
    $stmt->execute([$status, $remarks, $upload_id]);
    
    $message = "Document verification updated successfully.";

    // Log activity
    $log_title = "Director Document Review - " . $status;
    $log_desc = "Your document has been reviewed by the Director. Status: " . $status . ". Remarks: " . $remarks;
    $log_status = ($status === 'Approved') ? 'success' : 'warning';
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_user_id, $log_title, $log_desc, $log_status]);
}

// 2. Process Email Outbox Queue Authorization & SMTP Delivery Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'dispatch_email') {
    $notification_id = $_POST['notification_id'];
    
    // Fetch target unsent mail properties
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE notification_id = ? AND sent_status != 'Sent'");
    $stmt->execute([$notification_id]);
    $email_job = $stmt->fetch();

    if ($email_job) {
        $delivery_status = sendSystemEmail($email_job['recipient_email'], $email_job['subject'], $email_job['body']);
        
        if ($delivery_status) {
            $update_stmt = $pdo->prepare("UPDATE notifications SET director_approval = 1, sent_status = 'Sent', sent_at = NOW() WHERE notification_id = ?");
            $update_stmt->execute([$notification_id]);
            $message = "SMTP Dispatch Engine successfully authorized and delivered email to " . htmlspecialchars($email_job['recipient_email']);
        } else {
            $update_stmt = $pdo->prepare("UPDATE notifications SET sent_status = 'Failed' WHERE notification_id = ?");
            $update_stmt->execute([$notification_id]);
            $message = "Fatal error encountered during SMTP handshake execution.";
        }
    }
}

// Fetch all tracks under active progress evaluation
$workflow_query = "
    SELECT ap.approval_id, ap.coordinator_status, ap.statistician_status, ap.director_status, ap.payment_status, ap.printing_enabled,
           u.research_group_name, f.form_name, u.user_id, u.username, u.email, u.program, u.department, u.profile_pic
    FROM approvals ap
    JOIN users u ON ap.user_id = u.user_id
    JOIN forms f ON ap.form_id = f.form_id
";
$workflow_tracks = $pdo->query($workflow_query)->fetchAll();

// Fetch pending email notification queue entries
$mail_queue = $pdo->query("SELECT * FROM notifications WHERE sent_status = 'Pending Approval'")->fetchAll();

// Fetch Under Review uploads for the Director (Pre-checked by Coordinator)
$director_uploads_stmt = $pdo->query("
    SELECT up.upload_id, up.file_path, up.original_filename, up.verification_status, up.remarks, up.uploaded_at,
           u.username, u.research_group_name, u.user_id as student_user_id, ci.requirement_name
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    JOIN checklist_items ci ON up.item_id = ci.item_id
    WHERE up.verification_status = 'Under Review'
    ORDER BY up.uploaded_at ASC
");
$director_uploads = $director_uploads_stmt->fetchAll();

// Fetch recent activities (for Master Dashboard)
$recent_activities = $pdo->query("
    SELECT al.title, al.description, al.status_type, al.created_at,
           u.username, u.research_group_name
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC
    LIMIT 60
")->fetchAll();

// Fetch pending counts for the sidebar badges (Director handles 'Under Review' status)
// Director acts on 'Under Review' submissions. Count only the LATEST upload per group per item
// (mirrors the admin module's $uploads_by_item logic) so superseded versions don't inflate the badges.
$pending_counts = $pdo->query("
    SELECT
        SUM(CASE WHEN up.item_id IN (11,12,13,14,15,16) THEN 1 ELSE 0 END) as proposal_pending,
        SUM(CASE WHEN up.item_id IN (21,22,23,24,25,26,27) THEN 1 ELSE 0 END) as final_pending,
        SUM(CASE WHEN up.item_id IN (30,31,32,33,34,35) THEN 1 ELSE 0 END) as stats_pending,
        SUM(CASE WHEN up.item_id = 4 THEN 1 ELSE 0 END) as plag_pending
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date
        FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    WHERE up.verification_status = 'Under Review'
")->fetch();

// Fetch Calendar Events
$calendar_events = $pdo->query("SELECT * FROM calendar_events ORDER BY event_date ASC")->fetchAll();


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard | MCNP-ISAP</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
</head>
<body>
    <div class="app-dashboard-frame">
        <nav class="app-dock-navigation" aria-label="Primary navigation">
            <ul class="dock-menu-list">
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn active" data-view="home" onclick="showMasterDashboard(this)"><i data-lucide="house"></i></button>
                    <span class="dock-tooltip">Dashboard</span>
                </li>
                <li class="dock-divider" aria-hidden="true"></li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Proposal Defense" data-win-icon="file-check" onclick="openOverlay('admin_module_dynamic.php?phase=proposal', this)">
                        <i data-lucide="file-check"></i>
                        <?= $pending_counts['proposal_pending'] > 0 ? '<span class="dock-badge">' . $pending_counts['proposal_pending'] . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Proposal Defense</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Final Manuscript" data-win-icon="graduation-cap" onclick="openOverlay('admin_module_dynamic.php?phase=final', this)">
                        <i data-lucide="graduation-cap"></i>
                        <?= $pending_counts['final_pending'] > 0 ? '<span class="dock-badge">' . $pending_counts['final_pending'] . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Final Manuscript</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Statistics Clearance" data-win-icon="sigma" onclick="openOverlay('admin_module_dynamic.php?phase=stats', this)">
                        <i data-lucide="sigma"></i>
                        <?= $pending_counts['stats_pending'] > 0 ? '<span class="dock-badge">' . $pending_counts['stats_pending'] . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Statistics Clearance</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Plagiarism Verify" data-win-icon="shield-check" onclick="openOverlay('admin_module_dynamic.php?phase=plag', this)">
                        <i data-lucide="shield-check"></i>
                        <?= $pending_counts['plag_pending'] > 0 ? '<span class="dock-badge">' . $pending_counts['plag_pending'] . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Plagiarism Verify</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Messages" data-win-icon="message-circle" onclick="openOverlay('message.php', this)"><i data-lucide="message-circle"></i></button>
                    <span class="dock-tooltip">Messages</span>
                </li>
                <li class="dock-divider" aria-hidden="true"></li>
                <li class="dock-item">
                    <button class="dock-avatar-btn" onclick="toggleDockMenu('dockAvatarMenu', event)" aria-label="Account menu">
                        <div class="dock-avatar-ring"><img src="<?= htmlspecialchars($current_pfp) ?>" class="dock-avatar-img" onerror="this.onerror=null; this.src='https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($currentUser['username']) ?>';"></div>
                    </button>
                    <div class="dock-dropdown" id="dockAvatarMenu" onclick="event.stopPropagation()">
                        <div class="dock-dropdown-head">
                            <div class="dock-dropdown-name"><?= htmlspecialchars($currentUser['username']) ?></div>
                            <div class="dock-dropdown-role">Director</div>
                        </div>
                        <a onclick="showSettingsDashboard(document.getElementById('settings-nav-btn')); toggleDockMenu('dockAvatarMenu')"><i data-lucide="settings-2"></i> Panel Settings</a>
                        <a href="../auth/logout.php" class="danger"><i data-lucide="log-out"></i> Log out</a>
                    </div>
                </li>
                <li class="dock-divider" aria-hidden="true"></li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-view="calendar" onclick="showCalendarDashboard(this)"><i data-lucide="calendar-days"></i></button>
                    <span class="dock-tooltip">Calendar</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" id="settings-nav-btn" data-view="settings" onclick="showSettingsDashboard(this)"><i data-lucide="settings-2"></i></button>
                    <span class="dock-tooltip">Settings</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn dock-logout" onclick="window.location.href='../auth/logout.php'"><i data-lucide="log-out"></i></button>
                    <span class="dock-tooltip">Log out</span>
                </li>
            </ul>
        </nav>

        <main class="main-workspace-content">
            <?php include __DIR__ . "/_master_overview.php"; ?>

            <!-- STATISTICS & APPROVALS DASHBOARD -->
            <div class="container" id="statisticsDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Statistics & Approvals</h1>
                        <p>Review workflow approvals and authorize outgoing SMTP email notifications.</p>
                    </div>
                </div>

                <div class="section">
                    <h3>Institutional Workflow Tracking Pipeline</h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Research Group</th>
                                    <th>Stage</th>
                                    <th>Coordinator</th>
                                    <th>Statistician</th>
                                    <th>Payment</th>
                                    <th>Your Signoff</th>
                                    <th>Printing Lock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workflow_tracks as $wt): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($wt['research_group_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($wt['form_name']) ?></td>
                                    <td><?= htmlspecialchars($wt['coordinator_status']) ?></td>
                                    <td><?= htmlspecialchars($wt['statistician_status']) ?></td>
                                    <td>
                                        <form method="POST" style="display: flex; flex-direction: column; gap: 5px; align-items: center;">
                                            <input type="hidden" name="action_type" value="verify_payment">
                                            <input type="hidden" name="approval_id" value="<?= $wt['approval_id'] ?>">
                                            <span class="badge-status <?= $wt['payment_status'] === 'Paid' ? 'badge-paid' : 'badge-unpaid' ?>">
                                                <?= htmlspecialchars($wt['payment_status']) ?>
                                            </span>
                                            <input type="hidden" name="payment_status" value="<?= $wt['payment_status'] === 'Paid' ? 'Unpaid' : 'Paid' ?>">
                                            <button type="submit" class="btn btn-secondary" style="font-size: 10px; padding: 4px; border-radius: 8px;">
                                                <?= $wt['payment_status'] === 'Paid' ? 'Mark Unpaid' : 'Verify Receipt' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline-block; width:100%;">
                                            <input type="hidden" name="action_type" value="final_signoff">
                                            <input type="hidden" name="approval_id" value="<?= $wt['approval_id'] ?>">
                                            <select name="director_status" style="<?= ($wt['director_status'] === 'Pending' && $wt['payment_status'] === 'Unpaid') ? 'border-color: #c5221f;' : '' ?>">
                                                <option value="Pending" <?= $wt['director_status'] === 'Pending' ? 'selected' : '' ?>>Pending Review</option>
                                                <?php if ($wt['payment_status'] === 'Paid'): ?>
                                                    <option value="Approved" <?= $wt['director_status'] === 'Approved' ? 'selected' : '' ?>>Authorize Final Form</option>
                                                <?php endif; ?>
                                                <option value="Rejected" <?= $wt['director_status'] === 'Rejected' ? 'selected' : '' ?>>Reject / Hold Requirements</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary" style="margin-top: 8px; width: 100%; font-size: 11px; padding: 8px;"><i data-lucide="check-square" style="width:12; height:12;"></i> Update Signoff</button>
                                        </form>
                                    </td>
                                    <td><strong><?= $wt['printing_enabled'] == 1 ? '🔓 UNLOCKED' : '🔒 LOCKED' ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section">
                    <h3>Document Verification Queue (From Coordinator)</h3>
                    <p style="color: var(--text-muted); margin-bottom: 16px;">These documents have been pre-checked by the Coordinator and are awaiting your final approval.</p>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Research Group</th>
                                    <th>Requirement</th>
                                    <th>Uploaded File</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($director_uploads) === 0): ?>
                                    <tr><td colspan="4" style="padding: 18px; text-align: center;">No documents pending your review.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($director_uploads as $up): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($up['research_group_name']) ?></strong><br><small><?= htmlspecialchars($up['username']) ?></small></td>
                                    <td><?= htmlspecialchars($up['requirement_name']) ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($up['file_path']) ?>" target="_blank" class="btn btn-secondary" style="font-size:11px; padding: 6px 10px;">
                                            <i data-lucide="external-link" style="width:12px; height:12px;"></i> View Document
                                        </a>
                                        <br><small style="color: var(--text-muted); display:inline-block; margin-top:4px;"><?= date('M d, Y', strtotime($up['uploaded_at'])) ?></small>
                                    </td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="action_type" value="verify_upload">
                                            <input type="hidden" name="upload_id" value="<?= $up['upload_id'] ?>">
                                            <input type="hidden" name="student_user_id" value="<?= $up['student_user_id'] ?>">
                                            <select name="verification_status" required>
                                                <option value="Under Review" selected>Pending Your Review</option>
                                                <option value="Approved">Approve Document</option>
                                                <option value="Revision Requested">Request Revisions</option>
                                            </select>
                                            <textarea name="remarks" placeholder="Enter feedback (optional)..." rows="2" style="min-height: 60px;"><?= htmlspecialchars($up['remarks'] ?? '') ?></textarea>
                                            <button type="submit" class="btn btn-primary" style="margin-top: 8px; width: 100%; font-size: 11px; padding: 8px;">Submit Evaluation</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section">
                    <h3>SMTP Email Outbox Verification Center</h3>
                    <p style="color: var(--text-muted); margin-bottom: 16px;">Authorize outgoing academic notifications and release SMTP email handshakes immediately.</p>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Message Body</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($mail_queue) === 0): ?>
                                    <tr><td colspan="4" style="padding: 18px; text-align: center;">No notifications currently queued.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($mail_queue as $mq): ?>
                                <tr>
                                    <td><?= htmlspecialchars($mq['recipient_email']) ?></td>
                                    <td><?= htmlspecialchars($mq['subject']) ?></td>
                                    <td><?= htmlspecialchars($mq['body']) ?></td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="action_type" value="dispatch_email">
                                            <input type="hidden" name="notification_id" value="<?= $mq['notification_id'] ?>">
                                            <button type="submit" class="btn btn-dispatch"><i data-lucide="send" style="width:12; height:12;"></i> Authorize SMTP Delivery</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SETTINGS DASHBOARD -->
            <div class="container" id="settingsDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Panel Settings & Environment Customize</h1>
                        <p>Modify your name, edit profile elements, and change secure credentials.</p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; max-width: 900px; margin: 0 auto;">
                    <div class="section" style="margin-bottom: 0;">
                        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:18px; border-bottom:1.5px solid var(--border-line); padding-bottom:10px;"><i data-lucide="user-cog" style="vertical-align: middle; margin-right: 6px;"></i> Profile Information</h3>
                        <form method="POST">
                            <input type="hidden" name="action_type" value="update_profile">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                            <div style="margin-bottom: 18px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Username</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($currentUser['username']) ?>" required style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); font-family: inherit; font-size: 13px; outline: none;">
                            </div>

                            <div style="margin-bottom: 18px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Avatar</label>
                                <?php
                                    $avatar_presets = ['Director', 'Coordinator', 'Stats', 'Research', 'Tech', 'Grace'];
                                    $preset_urls = array_map(fn($p) => "https://api.dicebear.com/9.x/avataaars/svg?seed=" . $p, $avatar_presets);
                                    $current_pic = $currentUser['profile_pic'] ?? '';
                                    $is_preset_pic = in_array($current_pic, $preset_urls, true);
                                ?>
                                <input type="hidden" name="profile_pic" id="pfp_selector" value="<?= htmlspecialchars($current_pic ?: $preset_urls[0]) ?>">
                                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                    <?php if ($current_pic && !$is_preset_pic): ?>
                                        <div style="text-align:center;">
                                            <img src="<?= htmlspecialchars($current_pic) ?>" style="width:34px; height:34px; border-radius:50%; border:2px solid var(--eagle-gold); object-fit:cover;">
                                            <div style="font-size:8px; color:var(--text-muted); margin-top:2px;">Current</div>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($preset_urls as $purl): $active = ($purl === $current_pic) || (!$current_pic && $purl === $preset_urls[0]); ?>
                                        <img src="<?= $purl ?>" onclick="selectAvatarPreset('<?= $purl ?>', this)" class="avatar-preset-option" style="width:34px; height:34px; border-radius:50%; cursor:pointer; background:white; border:2px solid <?= $active ? 'var(--mcnp-teal)' : 'var(--border-line)' ?>; transition:all 0.2s;">
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Appearance: Light / Dark -->
                            <div style="margin-bottom: 22px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Appearance</label>
                                <div class="seg-toggle">
                                    <button type="button" data-theme="theme-light" onclick="setPortalTheme('theme-light')"><i data-lucide="sun"></i> Light</button>
                                    <button type="button" data-theme="theme-dark" onclick="setPortalTheme('theme-dark')"><i data-lucide="moon"></i> Dark</button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:bold; font-size:13px;"><i data-lucide="save"></i> Save</button>
                        </form>
                    </div>

                    <div class="section" style="margin-bottom: 0;">
                        <h3 style="font-family:'Cinzel', serif; font-size:15px; color:var(--mcnp-teal); margin-bottom:18px; border-bottom:1.5px solid var(--border-line); padding-bottom:10px;"><i data-lucide="shield-check" style="vertical-align: middle; margin-right: 6px;"></i> Change Password</h3>
                        <form method="POST">
                            <input type="hidden" name="action_type" value="update_password">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                            <div style="margin-bottom: 15px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Current Password</label>
                                <input type="password" name="current_password" style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none;">
                            </div>

                            <div style="margin-bottom: 22px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">New Password</label>
                                <input type="password" name="new_password" style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none; margin-bottom: 10px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Confirm New Password</label>
                                <input type="password" name="confirm_password" style="width: 100%; padding: 9px 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none;">
                            </div>

                            <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:bold; font-size:13px;"><i data-lucide="save"></i> Save</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- CALENDAR DASHBOARD -->
            <div class="container" id="calendarDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Institutional Calendar & Availability</h1>
                        <p>Manage unavailability dates like holidays or school events so students know when the office is closed.</p>
                    </div>
                </div>

                <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="section" style="margin-bottom:0;">
                        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:15px;"><i data-lucide="calendar-plus"></i> Add Event</h3>
                        <form method="POST" action="calendar_handler.php" style="display:flex; flex-direction:column; gap:12px;">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <div>
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Event Title</label>
                                <input type="text" name="title" required placeholder="e.g. Foundation Day" style="width: 100%; padding: 10px; border-radius: 8px; border: 1.5px solid var(--border-line); outline: none;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Date</label>
                                <input type="date" name="event_date" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1.5px solid var(--border-line); outline: none;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Description (Optional)</label>
                                <textarea name="description" rows="2" placeholder="e.g. Staff will not be available..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary" style="align-self:flex-start;"><i data-lucide="plus-circle" style="width:14px;height:14px;"></i> Save Event</button>
                        </form>
                    </div>

                    <div class="section" style="margin-bottom:0;">
                        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:15px;"><i data-lucide="list"></i> Upcoming Custom Events</h3>
                        <?php if (empty($calendar_events)): ?>
                            <div style="text-align:center; color:var(--text-muted); padding: 30px;">
                                <i data-lucide="calendar-x" style="width:26px; height:26px; display:block; margin: 0 auto 10px; opacity:0.5;"></i>
                                No upcoming events scheduled.
                            </div>
                        <?php else: ?>
                            <div>
                                <?php foreach ($calendar_events as $ce):
                                    $is_past = strtotime($ce['event_date']) < strtotime(date('Y-m-d'));
                                ?>
                                    <div class="activity-item" style="cursor:default; <?= $is_past ? 'opacity:0.55;' : '' ?>">
                                        <div class="activity-icon info" style="flex-direction:column; line-height:1.2; gap:0;">
                                            <span style="font-size:8px; font-weight:800; text-transform:uppercase;"><?= date('M', strtotime($ce['event_date'])) ?></span>
                                            <span style="font-size:14px; font-weight:800;"><?= date('d', strtotime($ce['event_date'])) ?></span>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?= htmlspecialchars($ce['title']) ?>
                                                <?php if ($is_past): ?><span style="font-size:9px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-left:6px;">Past</span><?php endif; ?>
                                            </div>
                                            <?php if (!empty($ce['description'])): ?>
                                                <div class="activity-desc"><?= htmlspecialchars($ce['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" action="calendar_handler.php" onsubmit="return confirm('Are you sure you want to delete this event?');" style="align-self:center;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="event_id" value="<?= $ce['event_id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding:6px 10px; color:#dc2626;" title="Delete event"><i data-lucide="trash" style="width:14px;height:14px;"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- macOS window layer (modules open here as draggable-feel windows) -->
        <div class="window-layer" id="windowLayer"></div>
    </div>

    <script>
        lucide.createIcons();

        // 1. Live PH Standard Time clock display
        function updateDirectorClock() {
            const now = new Date();
            const phtime = now.toLocaleString("en-US", { timeZone: "Asia/Manila", hour: '2-digit', minute: '2-digit', second: '2-digit' });

            const clockEl = document.getElementById('directorTimeClock');
            if (clockEl) clockEl.innerHTML = phtime;

            const clockStatsEl = document.getElementById('directorTimeClockStats'); // This element does not exist, but leaving for safety
            if (clockStatsEl) clockStatsEl.innerHTML = phtime; // This element does not exist

            const clockSettingsEl = document.getElementById('directorTimeClockSettings');
            if (clockSettingsEl) clockSettingsEl.innerHTML = phtime;
        }
        setInterval(updateDirectorClock, 1000);
        updateDirectorClock();

        function hideAllDashboards() {
            document.getElementById('masterDashboard').style.display = 'none';
            document.getElementById('statisticsDashboard').style.display = 'none';
            document.getElementById('settingsDashboard').style.display = 'none';
            document.getElementById('calendarDashboard').style.display = 'none';
            PortalWindows.collapseAll();   // tuck any open module windows back to the dock
        }

        function showMasterDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('masterDashboard').style.display = 'block';
        }
        
        function showStatisticsDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('statisticsDashboard').style.display = 'block';
        }

        function showSettingsDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('settingsDashboard').style.display = 'block';
        }

        function showCalendarDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('calendarDashboard').style.display = 'block';
        }

        // Open a module as a macOS-style window (state preserved when minimized).
        // Signature kept as (url, btn) so existing callers — including the cross-file
        // activity-log links in _master_overview.php — keep working unchanged.
        function openOverlay(url, btn) {
            const title = (btn && btn.dataset.winTitle) || 'Module';
            const icon = (btn && btn.dataset.winIcon) || 'app-window';
            PortalWindows.open(url, url, title, icon, btn);
        }

        // Settings Avatar and Theme update helpers
        function selectAvatarPreset(url, el) {
            document.getElementById('pfp_selector').value = url;
            document.querySelectorAll('.avatar-preset-option').forEach(img => img.style.borderColor = 'var(--border-line)');
            el.style.borderColor = 'var(--mcnp-teal)';
        }

        // Theme now flows through the shared controller (portal.js): one localStorage
        // key for every role, body markers preserved, swatch + iframe sync handled there.
        function setSettingTheme(t) {
            setPortalTheme(t);
        }
    </script>
</body>
</html>
