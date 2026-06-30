<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Enforce Session Access Rule
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Research Coordinator') {
    header("Location: ../auth/login.php");
    exit();
}

$uid = $_SESSION['user_id'];

// Settings update handler for administrative console
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'update_admin_settings') {
    $new_username = trim($_POST['username'] ?? '');
    $new_pfp = trim($_POST['profile_pic'] ?? '');
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    $settings_success = true;
    $settings_err = "";

    // 1. Update username & pfp
    if (!empty($new_username)) {
        $stmt_up = $pdo->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE user_id = ?");
        $stmt_up->execute([$new_username, $new_pfp, $uid]);
        $_SESSION['username'] = $new_username;
    }

    // 2. Optional Password Update
    if (!empty($current_pass) && !empty($new_pass)) {
        $stmt_pw = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt_pw->execute([$uid]);
        $db_pass = $stmt_pw->fetchColumn();

        if (password_verify($current_pass, $db_pass)) {
            if ($new_pass === $confirm_pass) {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt_up_pw = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt_up_pw->execute([$hashed, $uid]);
            } else {
                $settings_success = false;
                $settings_err = "New passwords do not match.";
            }
        } else {
            $settings_success = false;
            $settings_err = "Current password is incorrect.";
        }
    }

    if ($settings_success) {
        $message = "Your settings and credentials have been updated successfully.";
    } else {
        $message = "Error: " . $settings_err;
    }
}

// Fetch active profile details
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = (!empty($currentUser['profile_pic'])) ? $currentUser['profile_pic'] : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

$message = "";

// Process Stage-level Coordinator Signoff updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'coordinator_signoff') {
    $approval_id = $_POST['approval_id'];
    $status = $_POST['coordinator_status'];
    $remarks = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("UPDATE approvals SET coordinator_status = ? WHERE approval_id = ?");
    $stmt->execute([$status, $approval_id]);

    // Fetch user details for notification queue
    $group_stmt = $pdo->prepare("
        SELECT ap.user_id, u.email, u.research_group_name 
        FROM approvals ap 
        JOIN users u ON ap.user_id = u.user_id 
        WHERE ap.approval_id = ?
    ");
    $group_stmt->execute([$approval_id]);
    $group_data = $group_stmt->fetch();

    if ($group_data) {
        $student_user_id = $group_data['user_id'];
        $recipient_email = $group_data['email'];
        $group_name = $group_data['research_group_name'];

        // Queue notification job for Director authorized release via SMTP
        $subject = "Research Stage update: Coordinator Review Completed";
        $body = "Dear $group_name,\n\nYour recent study stage submissions have been reviewed by the Research Coordinator.\nStatus: $status.\nRemarks: $remarks\n\nBest Regards,\nInstitutional Portal";
        
        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications (recipient_email, subject, body, sent_status, director_approval) 
            VALUES (?, ?, ?, 'Pending Approval', 0)
        ");
        $notif_stmt->execute([$recipient_email, $subject, $body]);

        // Insert local student activity log entry
        $log_title = "Coordinator Stage Review - " . $status;
        $log_desc = "Coordinator stage checklist review marked as " . $status . ". Feedback notes: " . $remarks;
        $log_status = ($status === 'Approved') ? 'success' : 'warning';
        
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, title, description, status_type, created_at) 
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $log_stmt->execute([$student_user_id, $log_title, $log_desc, $log_status]);
    }

    $message = "Coordinator milestone validation state successfully persisted.";
}

// Fetch all pipeline tracking approvals
$pipeline_query = "
    SELECT ap.approval_id, ap.coordinator_status, ap.statistician_status, ap.payment_status,
           u.research_group_name, f.form_name, u.user_id, u.username, u.email, u.program, u.department, u.profile_pic
    FROM approvals ap
    JOIN users u ON ap.user_id = u.user_id
    JOIN forms f ON ap.form_id = f.form_id
";
$workflow_tracks = $pdo->query($pipeline_query)->fetchAll(PDO::FETCH_ASSOC);

// Fetch coordinator alerts count
$pending_uploads_count = $pdo->query("
    SELECT COUNT(*) 
    FROM uploads 
    WHERE verification_status = 'Pending' 
    AND item_id IN (11, 12, 13, 14, 15, 16, 5, 4)
")->fetchColumn();

// Fetch Calendar Events
$calendar_events = $pdo->query("SELECT * FROM calendar_events ORDER BY event_date ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --card-radius: 20px;
            --control-radius: 12px;
        }

        body.theme-default, body {
            --bg-canvas: #f7f5ef;
            --bg-white: #ffffff;
            --mcnp-teal: #0c343d;
            --accent-teal: #1a5f6d;
            --border-line: #dcd8cc;
            --text-muted: #7d7569;
            --text-dark: #2c2416;
            --eagle-gold: #cc9900;
        }
        body.theme-dark {
            --bg-canvas: #0f1214;
            --bg-white: #171c1f;
            --mcnp-teal: #22d3ee;
            --accent-teal: #0891b2;
            --border-line: #2d363d;
            --text-muted: #94a3b8;
            --text-dark: #f1f5f9;
            --eagle-gold: #fbbf24;
        }
        body.theme-green {
            --bg-canvas: #f2f8f2;
            --bg-white: #ffffff;
            --mcnp-teal: #1e3f20;
            --accent-teal: #2d5f30;
            --border-line: #cbdacd;
            --text-muted: #6b7c6c;
            --text-dark: #202c20;
            --eagle-gold: #c39a24;
        }
        body.theme-red {
            --bg-canvas: #f9f5f5;
            --bg-white: #ffffff;
            --mcnp-teal: #571616;
            --accent-teal: #731e1e;
            --border-line: #dbc8c8;
            --text-muted: #8c7373;
            --text-dark: #3b2020;
            --eagle-gold: #cca500;
        }
        body.theme-pink {
            --bg-canvas: #fcf4f7;
            --bg-white: #ffffff;
            --mcnp-teal: #5e1c3e;
            --accent-teal: #7a2652;
            --border-line: #e3cbd7;
            --text-muted: #917183;
            --text-dark: #3a1c2d;
            --eagle-gold: #cb9300;
        }
        body.theme-purple {
            --bg-canvas: #f6f4fa;
            --bg-white: #ffffff;
            --mcnp-teal: #3b1e5a;
            --accent-teal: #50287a;
            --border-line: #d5cbdc;
            --text-muted: #827290;
            --text-dark: #2c1a3e;
            --eagle-gold: #cb9200;
        }
        body.theme-orange {
            --bg-canvas: #fcf6f0;
            --bg-white: #ffffff;
            --mcnp-teal: #5d2b0e;
            --accent-teal: #7b3d16;
            --border-line: #e4ccd2;
            --text-muted: #90766a;
            --text-dark: #3a2216;
            --eagle-gold: #cb9400;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-canvas) !important; 
            min-height: 100vh; 
            color: var(--text-dark); 
            display: flex;
        }

        /* Container Styling */
        .app-dashboard-frame { 
            background: var(--bg-canvas); 
            width: 100vw; 
            height: 100vh; 
            display: flex; 
            position: relative; 
            overflow: hidden; 
        }

        .app-dashboard-frame::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(#e0dbc8 1px, transparent 1px),
                linear-gradient(to right, rgba(0,0,0,0.01) 1px, transparent 1px);
            background-size: 24px 24px, 128px 128px;
            opacity: 0.5;
            pointer-events: none;
            z-index: 10;
        }

        /* Sidebar Styling */
        .app-sidebar { 
            background-color: var(--mcnp-teal); 
            color: white; 
            width: 280px; 
            padding: 35px 20px; 
            display: flex; 
            flex-direction: column; 
            z-index: 110; 
            flex-shrink: 0; 
            box-shadow: 10px 0 35px rgba(12, 52, 61, 0.12);
            border-right: 2px solid rgba(255,255,255,0.08);
        }

        .sidebar-header {
            text-align: center;
            border-bottom: 2px solid rgba(255,255,255,0.08);
            padding-bottom: 25px;
            margin-bottom: 25px;
        }

        .sidebar-logo {
            width: 50px;
            height: 50px;
            background: white;
            padding: 6px;
            border-radius: 14px;
            margin-bottom: 12px;
            object-fit: contain;
        }

        .sidebar-header h2 {
            font-family: 'Cinzel', serif;
            font-size: 18px;
            color: white;
            letter-spacing: 0.8px;
        }

        .sidebar-header p {
            font-size: 11.5px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-top: 4px;
            color: #cc9900;
            font-weight: bold;
        }

        .nav-menu-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
        
        .nav-item-btn { 
            width: 100%; 
            padding: 13px 16px; 
            background: transparent; 
            border: none; 
            color: #d1d5db; 
            font-family: inherit; 
            font-size: 13.5px; 
            text-align: left; 
            border-radius: var(--control-radius); 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            transition: all 0.25s; 
            font-weight: 550;
        }
        
        .nav-item-btn:hover { 
            background: rgba(255, 255, 255, 0.08); 
            color: white;
            transform: translateX(3px);
        }
        
        .nav-item-btn.active { 
            background: rgba(255, 255, 255, 0.15); 
            color: white; 
            font-weight: 700; 
            border-left: 4px solid #cc9900;
        }
        
        .nav-badge { 
            background: #ef4444; 
            color: white; 
            padding: 2px 7px; 
            border-radius: var(--control-radius); 
            font-size: 10px; 
            font-style: bold; 
            margin-left: auto; 
        }

        .main-workspace-content { 
            flex: 1; 
            padding: 30px; 
            overflow-y: auto; 
            position: relative; 
            z-index: 20;
            background: #faf9f6;
        }

        .container { max-width: 1080px; margin: 0 auto; }

        .header { 
            display: flex; 
            flex-wrap: wrap; 
            justify-content: space-between; 
            align-items: center;
            gap: 20px; 
            margin-bottom: 28px; 
            border-bottom: 2px solid var(--border-line);
            padding-bottom: 20px;
        }

        .header-title h1 { 
            font-family: 'Cinzel', serif;
            font-size: 26px; 
            color: var(--mcnp-teal); 
            margin-bottom: 6px; 
            font-weight: 800;
        }
        
        .header-title p { color: var(--text-muted); font-size: 13.5px; }

        .clock-widget {
            background: white;
            border: 1.5px solid var(--border-line);
            padding: 12px 18px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'JetBrains Mono', monospace;
            box-shadow: 0 5px 15px rgba(0,0,0,0.02);
        }

        .clock-widget span {
            font-weight: 700;
            color: var(--mcnp-teal);
        }

        .section {
            background: white;
            border-radius: var(--card-radius);
            padding: 28px;
            border: 1.5px solid var(--border-line);
            box-shadow: 0 10px 30px rgba(0,0,0,0.02);
            margin-bottom: 24px;
        }

        .section h3 {
            font-family: 'Cinzel', serif;
            color: var(--mcnp-teal);
            margin-bottom: 16px;
            font-size: 16px;
        }

        .selector-section {
            background: white;
            border: 1.5px solid var(--border-line);
            border-radius: var(--card-radius);
            padding: 22px;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.02);
        }

        .selector-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1.5px solid var(--border-line);
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .selector-header h3 { font-family: 'Cinzel', serif; font-size: 14.5px; color: var(--mcnp-teal); }

        .select-group-dropdown {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border-line);
            font-size: 13.5px;
            font-family: inherit;
            background: #faf9f6;
            width: 100%;
            max-width: 320px;
            outline: none;
            cursor: pointer;
        }

        .selected-group-profile-card {
            display: none;
            background: #faf8f3;
            border: 1px solid var(--border-line);
            border-radius: 16px;
            padding: 18px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .profile-pfp {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 1.5px solid var(--mcnp-teal);
            object-fit: cover;
            background: white;
        }

        .table-wrapper { overflow-x: auto; border-radius: 12px; border: 1.5px solid var(--border-line); }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-line); vertical-align: middle; }
        th { background: #faf8f4; color: var(--text-dark); font-size: 11px; text-transform: uppercase; font-weight: 800; }
        
        select, textarea {
            width: 100%;
            padding: 11px 14px;
            border-radius: var(--control-radius);
            border: 1.5px solid var(--border-line);
            background: #fdfbf7;
            font-family: inherit;
            outline: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-weight: bold;
            cursor: pointer;
            font-size: 12.5px;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--mcnp-teal); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }

        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            display: inline-block;
        }
        .badge-paid { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-pending { background: #fff3cd; color: #856404; border: 1px solid #fcd34d; }

        .alert-success { background: #ecfdf5; color: #136643; padding: 16px 20px; border-radius: 14px; margin-bottom: 24px; font-weight: 700; border-left: 5px solid #059669; }

        /* Fullscreen Overlay System */
        .fullscreen-overlay { 
            position: absolute; 
            top: 0; 
            left: 280px; 
            width: calc(100% - 280px); 
            height: 100%; 
            background-color: #faf9f6; 
            z-index: 150; 
            padding: 0; 
            display: none; 
        }
        
        .fullscreen-overlay.active { 
            display: block; 
            animation: fadeIn 0.3s ease; 
        }
        
        .overlay-iframe { 
            width: 100%; 
            height: 100%; 
            border: none; 
            background: transparent; 
        }
        
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(10px); } 
            to { opacity: 1; transform: translateY(0); } 
        }

        .sidebar-footer { margin-top: auto; padding-top: 20px; border-top: 2px solid rgba(255,255,255,0.08); text-align: center; }
        .sidebar-footer p { font-size: 11.5px; opacity: 0.7; margin-bottom: 12px; color: white; }
        .logout-btn { width: 100%; padding: 11px 14px; background: rgba(220, 38, 38, 0.85); border: none; color: white; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 12.5px; text-decoration: none; display: block; }
        .logout-btn:hover { background: #b91c1c; }
    </style>
</head>
<body>
    <div class="app-dashboard-frame">
        <aside class="app-sidebar">
            <div class="sidebar-header">
                <img src="https://isap.edu.ph/wp-content/uploads/2022/07/ISAP-LOGO-2022.png" class="sidebar-logo">
                <h2>Staff Portal</h2>
                <p>Coordinator</p>
            </div>
            
            <ul class="nav-menu-list">
                <li><button class="nav-item-btn active" onclick="showMasterDashboard(this)">
                    <i data-lucide="layout-dashboard"></i> Master Dashboard
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=proposal', this)">
                    <i data-lucide="file-check"></i> Evaluate Proposals
                    <?= $pending_uploads_count > 0 ? '<span class="nav-badge">' . $pending_uploads_count . '</span>' : '' ?>
                </button></li>
                <li><button class="nav-item-btn" onclick="showCalendarDashboard(this)">
                    <i data-lucide="calendar-days"></i> Institutional Calendar
                </button></li>
                <li><button class="nav-item-btn" id="settings-nav-btn" onclick="showSettingsDashboard(this)">
                    <i data-lucide="settings"></i> Panel Settings
                </button></li>
            </ul>
            
            <div class="sidebar-footer" style="padding-top: 15px; display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; border-top: 2px solid rgba(255,255,255,0.08); margin-top: auto;">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden; text-align: left;">
                    <img src="<?= htmlspecialchars($current_pfp) ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1.5px solid #cc9900; background: white; flex-shrink: 0;" onerror="this.onerror=null; this.src='https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($currentUser['username']) ?>';">
                    <div style="display: flex; flex-direction: column; overflow: hidden;">
                        <span style="font-weight: 600; font-size: 13px; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($currentUser['username']) ?></span>
                        <span style="font-size: 10px; color: #cc9900; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Coordinator</span>
                    </div>
                </div>
                <a href="../auth/logout.php" style="width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); color: #f87171; border-radius: 8px; transition: all 0.25s; flex-shrink: 0; border: 1px solid rgba(239, 68, 68, 0.2); text-decoration: none;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.color='#f87171';" title="Logout">
                    <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
                </a>
            </div>
        </aside>

        <main class="main-workspace-content">
            <div class="container" id="masterDashboard">
                <div class="header">
                    <div class="header-title">
                        <h1>Research Coordinator Console</h1>
                        <p>Process capsule proposals, stage reviews, and track student research tracks.</p>
                    </div>
                    <!-- Calendar Dynamic clock -->
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="coordClock">loading...</span>
                    </div>
                </div>

                <?php if($message): ?>
                    <div class="alert-success">
                        <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 6px;"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Interactive Dropdown Group Selector -->
                <div class="selector-section">
                    <div class="selector-header">
                        <h3>Research Group Quick Profile Finder</h3>
                        <select class="select-group-dropdown" id="groupSelector" onchange="loadSelectedGroupProfile()">
                            <option value="" disabled selected>-- Select Student Group --</option>
                            <?php foreach ($workflow_tracks as $group): ?>
                                <option value="<?= htmlspecialchars(json_encode($group)) ?>">
                                    <?= htmlspecialchars($group['research_group_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="selected-group-profile-card" id="selectedGroupProfile">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <div style="display:flex; gap:16px;">
                                <img id="groupPfp" src="" class="profile-pfp">
                                <div>
                                    <h4 id="groupName" style="color:var(--mcnp-teal); font-family:'Cinzel', serif; font-size:16px;">Group Name</h4>
                                    <p id="groupLeader" style="font-weight:600; color:#4b5563; font-size:12.5px; margin-top:2px;">Leader: </p>
                                    <p id="groupMail" style="font-family:'JetBrains Mono', monospace; font-size:11.5px; color:#6b7280;"></p>
                                    <p id="groupDetails" style="font-size:11px; color:#9ca3af; margin-top:2px;"></p>
                                </div>
                            </div>
                            
                            <div style="text-align:right;">
                                <span style="font-size:10px; font-weight:800; color:#4a453e; text-transform:uppercase;">Administrative Milestones</span>
                                <div style="display:flex; flex-direction:column; gap:6px; margin-top:6px; align-items:flex-end;">
                                    <div style="font-size:12px;"><span style="color:#6b7280;">Coordinator Flag:</span> <strong id="progCoord">Pending</strong></div>
                                    <div style="font-size:12px;"><span style="color:#6b7280;">Statistician Status:</span> <strong id="progStats">Pending</strong></div>
                                    <div style="font-size:12px;"><span style="color:#6b7280;">Payment Verified:</span> <strong id="progPay">Unpaid</strong></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h3>Administrative Milestones Clearance Pipeline</h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Research Group</th>
                                    <th>Stage Form Type</th>
                                    <th>Statistician Status</th>
                                    <th>Your Evaluation Signoff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workflow_tracks as $wt): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($wt['research_group_name']) ?></strong><br>
                                        <small style="color:var(--text-muted);"><?= htmlspecialchars($wt['program']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($wt['form_name']) ?></td>
                                    <td>
                                        <span class="badge-status <?= $wt['statistician_status'] === 'Approved' ? 'badge-paid' : 'badge-pending' ?>">
                                            <?= htmlspecialchars($wt['statistician_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="action_type" value="coordinator_signoff">
                                            <input type="hidden" name="approval_id" value="<?= $wt['approval_id'] ?>">
                                            <select name="coordinator_status" required>
                                                <option value="Pending" <?= $wt['coordinator_status'] === 'Pending' ? 'selected' : '' ?>>Pending Review</option>
                                                <option value="Approved" <?= $wt['coordinator_status'] === 'Approved' ? 'selected' : '' ?>>Approve & Forward to Director</option>
                                                <option value="Rejected" <?= $wt['coordinator_status'] === 'Rejected' ? 'selected' : '' ?>>Reject / Hold Milestones</option>
                                            </select>
                                            <textarea name="remarks" placeholder="Enter remarks/instructions for notifications..." style="margin-top:8px; font-size:11px;" required></textarea>
                                            <button type="submit" class="btn btn-primary" style="margin-top:8px; width:100%; font-size:11px; padding:8px;"><i data-lucide="check-square" style="width:12px; height:12px;"></i> Process Signoff</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PANEL SETTINGS DASHBOARD -->
            <div class="container" id="settingsDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Panel Settings</h1>
                        <p>Customize administrative profile, credential values, and dashboard visual skins.</p>
                    </div>
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="directorTimeClockSettings">loading...</span>
                    </div>
                </div>

                <div class="section" style="max-width: 650px; margin: 0 auto; background: var(--bg-white, #ffffff); padding: 30px; border-radius: var(--card-radius); border: 2px solid var(--border-line);">
                    <h3 style="margin-bottom: 20px; font-family:'Cinzel', serif; color: var(--mcnp-teal); border-bottom: 1.5px solid var(--border-line); padding-bottom: 10px;">Update Authorized Profile</h3>
                    
                    <form method="POST" id="adminSettingsForm">
                        <input type="hidden" name="action_type" value="update_admin_settings">
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:8px;">Username Signature</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($currentUser['username']) ?>" required style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:8px;">Profile Avatar URL</label>
                            <input type="text" name="profile_pic" id="pfp_selector" value="<?= htmlspecialchars($currentUser['profile_pic'] ?? '') ?>" placeholder="Paste image link or leave empty for DiceBear dynamic avatar" style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                            <p style="font-size:11px; color:var(--text-muted); margin-top:5px;">Choose Avatar Quickpreset:</p>
                            <div style="display:flex; gap:10px; margin-top:10px; overflow-x:auto;">
                                <?php foreach (["Adonis", "Buster", "Luna", "Zoey", "Chloe", "Rocky"] as $preset): 
                                    $purl = "https://api.dicebear.com/9.x/avataaars/svg?seed=" . $preset; ?>
                                    <img src="<?= $purl ?>" onclick="document.getElementById('pfp_selector').value='<?= $purl ?>';" style="width:36px; height:36px; border-radius:50%; border:1.5px solid var(--border-line); cursor:pointer; background:#f3f4f6; transition:scale 0.2s;" onmouseover="this.style.scale=1.13;" onmouseout="this.style.scale=1;">
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:8px;">Administrative Visual Canvas Theme</label>
                            <select id="user_theme_select" style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                                <option value="theme-default">Institutional Ivory Beige (Default)</option>
                                <option value="theme-dark">Cosmic Obsidian Slate (Dark Mode)</option>
                                <option value="theme-green">Botanical Forest Emerald</option>
                                <option value="theme-red">Academic Crimson Burgundy</option>
                                <option value="theme-pink">Cherry Blossom Magenta</option>
                                <option value="theme-purple">Monarch Royal Orchid</option>
                                <option value="theme-orange">Autumn Sun Amber</option>
                            </select>
                        </div>

                        <div style="margin-top: 30px; margin-bottom: 10px; border-top: 1.5px dashed var(--border-line); padding-top: 20px;">
                            <h4 style="font-size:14.5px; margin-bottom:15px; font-weight: 700; color:var(--text-dark);">Change Authentication Password Key</h4>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:6px;">Current Password Key</label>
                            <input type="password" name="current_password" style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:6px;">New Password Key</label>
                            <input type="password" name="new_password" style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                        </div>

                        <div style="margin-bottom: 25px;">
                            <label style="display:block; font-weight:600; font-size:12.5px; margin-bottom:6px;">Confirm New Password Key</label>
                            <input type="password" name="confirm_password" style="width:100%; padding:11px 14px; border-radius:var(--control-radius); border:2px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%; padding:12px 18px; border-radius:var(--control-radius); font-weight:700; font-size:13.5px;">
                            <i data-lucide="save"></i> Save Settings & Visual Customization
                        </button>
                    </form>
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

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                    <div class="section" style="margin-bottom:0;">
                        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:15px;"><i data-lucide="calendar-plus"></i> Add Event</h3>
                        <form method="POST" action="calendar_handler.php" style="display:flex; flex-direction:column; gap:12px;">
                            <input type="hidden" name="action" value="add">
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
                        <div class="table-wrapper">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th style="padding: 10px; text-align: left; background: #faf8f4; color: var(--text-dark); font-size: 11px; text-transform: uppercase;">Date</th>
                                        <th style="padding: 10px; text-align: left; background: #faf8f4; color: var(--text-dark); font-size: 11px; text-transform: uppercase;">Event</th>
                                        <th style="padding: 10px; text-align: left; background: #faf8f4; color: var(--text-dark); font-size: 11px; text-transform: uppercase;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($calendar_events)): ?>
                                        <tr><td colspan="3" style="text-align:center; padding: 14px; color:var(--text-muted);">No custom events configured.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach($calendar_events as $ce): ?>
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid var(--border-line);"><strong><?= date('M d, Y', strtotime($ce['event_date'])) ?></strong></td>
                                        <td style="padding: 10px; border-bottom: 1px solid var(--border-line);"><?= htmlspecialchars($ce['title']) ?><br><small style="color:var(--text-muted);"><?= htmlspecialchars($ce['description']) ?></small></td>
                                        <td style="padding: 10px; border-bottom: 1px solid var(--border-line);">
                                            <form method="POST" action="calendar_handler.php" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="event_id" value="<?= $ce['event_id'] ?>">
                                                <button type="submit" class="btn btn-secondary" style="padding:6px 10px; color:#dc2626;"><i data-lucide="trash" style="width:14px;height:14px;"></i> Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <div class="fullscreen-overlay" id="moduleOverlay">
            <iframe id="moduleFrame" class="overlay-iframe"></iframe>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Standardized dynamic clock updater (without timezone text to give breath)
        function updateClock() {
            const clockEl = document.getElementById('coordClock');
            if(clockEl) {
                const now = new Date();
                const phtime = now.toLocaleString("en-US", { timeZone: "Asia/Manila", hour: '2-digit', minute: '2-digit', second: '2-digit' });
                clockEl.textContent = phtime;
            }
            const settingsClock = document.getElementById('directorTimeClockSettings');
            if (settingsClock) {
                const now = new Date();
                const phtime = now.toLocaleString("en-US", { timeZone: "Asia/Manila", hour: '2-digit', minute: '2-digit', second: '2-digit' });
                settingsClock.textContent = phtime;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        function loadSelectedGroupProfile() {
            const selector = document.getElementById('groupSelector');
            const dataStr = selector.value;
            if (!dataStr) return;

            try {
                const data = JSON.parse(dataStr);
                const card = document.getElementById('selectedGroupProfile');
                card.style.display = 'block';

                const pfpUrl = data.profile_pic || 'https://api.dicebear.com/9.x/bottts/svg?seed=' + encodeURIComponent(data.username);
                document.getElementById('groupPfp').src = pfpUrl;
                document.getElementById('groupName').textContent = data.research_group_name;
                document.getElementById('groupLeader').innerHTML = `Leader: <strong style="color:var(--mcnp-teal);">${data.username}</strong>`;
                document.getElementById('groupMail').innerHTML = `<i data-lucide="mail" style="width:12px; height:12px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> ${data.email}`;
                document.getElementById('groupDetails').textContent = `${data.program} • ${data.department}`;

                // Populate tracking labels
                document.getElementById('progCoord').textContent = data.coordinator_status;
                document.getElementById('progCoord').className = 'badge-status ' + (data.coordinator_status === 'Approved' ? 'badge-paid' : 'badge-pending');
                
                document.getElementById('progStats').textContent = data.statistician_status;
                document.getElementById('progStats').className = 'badge-status ' + (data.statistician_status === 'Approved' ? 'badge-paid' : 'badge-pending');

                document.getElementById('progPay').textContent = data.payment_status;
                document.getElementById('progPay').className = 'badge-status ' + (data.payment_status === 'Paid' ? 'badge-paid' : 'badge-unpaid');

                lucide.createIcons();
            } catch(e) { console.error("Error loading group", e); }
        }

        // Complete workspace-switcher layout handlers
        function hideAllDashboards() {
            document.getElementById('masterDashboard').style.display = 'none';
            document.getElementById('settingsDashboard').style.display = 'none';
            document.getElementById('calendarDashboard').style.display = 'none';
            document.getElementById('moduleOverlay').classList.remove('active');
            document.getElementById('moduleFrame').src = 'about:blank';
        }

        function showMasterDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('masterDashboard').style.display = 'block';
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

        function openOverlay(url, btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('moduleFrame').src = url;
            document.getElementById('moduleOverlay').classList.add('active');
        }

        // Live persistence initialization for premium administrative customizable themes
        const localThemeKey = "staff-portal-coordinator-theme";
        const currentTheme = localStorage.getItem(localThemeKey) || "theme-default";
        document.body.className = currentTheme;
        
        const themeSelectorEl = document.getElementById('user_theme_select');
        if (themeSelectorEl) {
            themeSelectorEl.value = currentTheme;
            themeSelectorEl.addEventListener('change', (e) => {
                const updatedTheme = e.target.value;
                document.body.className = updatedTheme;
                localStorage.setItem(localThemeKey, updatedTheme);
            });
        }
    </script>
</body>
</html>
