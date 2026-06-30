<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Enforce Session Access Rule
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Statistician') {
    header("Location: ../auth/login.php");
    exit();
}

$uid = $_SESSION['user_id'];

// Settings update handler for administrative console
$message = "";
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

if (!isset($message)) {
    $message = "";
}

// Process statistician signoff updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'statistician_signoff') {
    $approval_id = $_POST['approval_id'];
    $status = $_POST['statistician_status'];
    $remarks = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("UPDATE approvals SET statistician_status = ? WHERE approval_id = ?");
    $stmt->execute([$status, $approval_id]);

    // Fetch user details for log updates
    $group_stmt = $pdo->prepare("
        SELECT ap.user_id, u.research_group_name 
        FROM approvals ap 
        JOIN users u ON ap.user_id = u.user_id 
        WHERE ap.approval_id = ?
    ");
    $group_stmt->execute([$approval_id]);
    $group_data = $group_stmt->fetch();

    if ($group_data) {
        $student_user_id = $group_data['user_id'];
        
        // Log local student activity
        $log_title = "Statistician Study Signoff - " . $status;
        $log_desc = "Your data analysis / methodology files clearance status updated to " . $status . " by the Research Statistician. Remarks: " . $remarks;
        $log_status = ($status === 'Approved') ? 'success' : 'warning';

        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, title, description, status_type, created_at) 
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $log_stmt->execute([$student_user_id, $log_title, $log_desc, $log_status]);
    }

    $message = "Statistical clearance status updated successfully.";
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

// Fetch pending stats upload validation count
$pending_uploads_count = $pdo->query("
    SELECT COUNT(*) 
    FROM uploads 
    WHERE verification_status = 'Pending' 
    AND item_id = 3
")->fetchColumn();

// Fetch approved clearances count
$approved_clearances_count = $pdo->query("
    SELECT COUNT(*) 
    FROM approvals 
    WHERE statistician_status = 'Approved'
")->fetchColumn();

// Fetch recent activities (Filtered specifically for Statistician-related reviews and files)
$recent_activities = $pdo->query("
    SELECT al.title, al.description, al.status_type, al.created_at,
           u.username, u.research_group_name
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    WHERE al.title LIKE '%Statistician%'
       OR al.title LIKE '%Statistical%'
       OR al.title LIKE '%Treatment%'
       OR al.title LIKE '%Methodology%'
       OR al.title LIKE '%Data Analysis%'
       OR al.title LIKE '%011%'
       OR al.title LIKE '%012%'
       OR al.title LIKE '%Payment Acknowledged%'
       OR al.description LIKE '%Statistician%'
       OR al.description LIKE '%statistical%'
       OR al.description LIKE '%methodology%'
       OR al.description LIKE '%data analysis%'
       OR al.description LIKE '%011%'
       OR al.description LIKE '%012%'
    ORDER BY al.created_at DESC
    LIMIT 60
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistician Dashboard</title>
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
        
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-canvas) !important; 
            min-height: 100vh; 
            color: var(--text-dark); 
            display: flex;
            font-size: 14px;
        }

        .app-dashboard-frame { 
            background-color: var(--bg-canvas); 
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

        /* SIDEBAR PANEL */
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
            color: var(--eagle-gold);
            font-weight: 700;
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
            border-left: 4px solid var(--eagle-gold);
        }
        
        .nav-badge { 
            background: #ef4444; 
            color: white; 
            padding: 2px 7px; 
            border-radius: var(--control-radius); 
            font-size: 10px; 
            font-weight: bold; 
            margin-left: auto; 
        }

        /* MAIN WORKSPACE WRAPPER */
        .main-workspace-content { 
            flex: 1; 
            padding: 30px; 
            overflow-y: auto; 
            position: relative; 
            z-index: 20;
            background: #faf9f6;
        }

        .top-navbar-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 30px;
            background: var(--bg-white);
            border-bottom: 1.5px solid var(--border-line);
            z-index: 90;
            gap: 20px;
        }

        .workspace-scrollable-container {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
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

        /* Recent Activity feed */
        .activity-feed { 
            background: var(--bg-white); 
            border-radius: var(--card-radius); 
            padding: 28px; 
            border: 1.5px solid var(--border-line);
            box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
            margin-bottom: 28px; 
        }
        
        .activity-item { 
            display: flex; 
            gap: 14px; 
            padding: 14px; 
            border-bottom: 1.5px solid var(--border-line); 
            cursor: pointer; 
            transition: 0.2s; 
        }
        
        .activity-item:hover { 
            background: #faf8f4; 
            border-radius: var(--control-radius); 
        }
        
        .activity-item:last-child { border-bottom: none; }
        
        .activity-icon { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
            flex-shrink: 0; 
        }
        
        .activity-icon.success { background: #d1fae5; color: #059669; }
        .activity-icon.warning { background: #fef3c7; color: #d97706; }
        .activity-icon.info { background: #dbeafe; color: #2563eb; }
        
        .activity-content { flex: 1; }
        .activity-title { font-weight: bold; color: var(--text-dark); font-size: 13px; }
        .activity-desc { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
        .activity-time { color: var(--text-muted); font-size: 11px; margin-top: 4px; }

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
                <p>Statistician</p>
            </div>
            
            <ul class="nav-menu-list">
                <li><button class="nav-item-btn active" onclick="showMasterDashboard(this)">
                    <i data-lucide="layout-dashboard"></i> Dashboard
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=stats', this)">
                    <i data-lucide="calculator"></i> Statistics
                    <?= $pending_uploads_count > 0 ? '<span class="nav-badge">' . $pending_uploads_count . '</span>' : '' ?>
                </button></li>
            </ul>

            <!-- Hidden settings button to maintain JS reference capability without cluttering UI -->
            <button class="nav-item-btn" id="settings-nav-btn" onclick="showSettingsDashboard(this)" style="display: none;"></button>
            
            <div class="sidebar-footer" style="padding-top: 15px; display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; border-top: 2px solid rgba(255,255,255,0.08); margin-top: auto;">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden; text-align: left;">
                    <img src="<?= htmlspecialchars($current_pfp) ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--eagle-gold); background: white; flex-shrink: 0;" onerror="this.onerror=null; this.src='https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($currentUser['username']) ?>';">
                    <div style="display: flex; flex-direction: column; overflow: hidden; cursor: pointer;" onclick="showSettingsDashboard(document.getElementById('settings-nav-btn'))">
                        <span style="font-weight: 600; font-size: 13px; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($currentUser['username']) ?></span>
                        <span style="font-size: 10px; color: var(--eagle-gold); font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Statistician</span>
                    </div>
                </div>
                <a href="../auth/logout.php" style="width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); color: #f87171; border-radius: 8px; transition: all 0.25s; flex-shrink: 0; border: 1px solid rgba(239, 68, 68, 0.2); text-decoration: none;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.color='#f87171';" title="Logout">
                    <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
                </a>
            </div>
        </aside>

        <main class="main-workspace-content">
            <!-- MASTER DASHBOARD -->
            <div class="container" id="masterDashboard">
                <div class="header">
                    <div class="header-title">
                        <h1>Research Statistician Terminal</h1>
                        <p>Overview of statistical review workflows, data analysis clearance metrics, and recent activities.</p>
                    </div>
                    
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="statsClock">loading...</span>
                    </div>
                </div>

                    <?php if($message): ?>
                        <div class="alert-success">
                            <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 6px;"></i>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Grid with cohesive look -->
                    <div class="dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px;">
                        <div class="stat-card" style="background: var(--bg-white); border: 1.5px solid var(--border-line); border-radius: var(--card-radius); padding: 24px; text-align: left; position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.02); transition: all 0.25s;">
                            <div class="stat-value" style="font-size: 32px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;"><?= count($workflow_tracks) ?></div>
                            <div class="stat-label" style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Research Groups</div>
                            <div style="position: absolute; right: 20px; top: 20px; background: rgba(16, 185, 129, 0.1); color: var(--mcnp-teal); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                <i data-lucide="users" style="width: 20px; height: 20px;"></i>
                            </div>
                        </div>
                        <div class="stat-card" style="background: var(--bg-white); border: 1.5px solid var(--border-line); border-radius: var(--card-radius); padding: 24px; text-align: left; position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.02); transition: all 0.25s;">
                            <div class="stat-value" style="font-size: 32px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;"><?= $pending_uploads_count ?></div>
                            <div class="stat-label" style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Pending Data Validations</div>
                            <div style="position: absolute; right: 20px; top: 20px; background: rgba(245, 158, 11, 0.1); color: var(--eagle-gold); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                <i data-lucide="alert-circle" style="width: 20px; height: 20px;"></i>
                            </div>
                        </div>
                        <div class="stat-card" style="background: var(--bg-white); border: 1.5px solid var(--border-line); border-radius: var(--card-radius); padding: 24px; text-align: left; position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.02); transition: all 0.25s;">
                            <div class="stat-value" style="font-size: 32px; font-weight: 800; color: var(--text-dark); margin-bottom: 4px;"><?= $approved_clearances_count ?></div>
                            <div class="stat-label" style="font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Approved Clearances</div>
                            <div style="position: absolute; right: 20px; top: 20px; background: rgba(16, 185, 129, 0.1); color: var(--mcnp-teal); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                <i data-lucide="check-circle" style="width: 20px; height: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive Dropdown Group Selector -->
                    <div class="selector-section" style="margin-bottom: 24px;">
                        <div class="selector-header" style="display: flex; gap: 12px; align-items: center; justify-content: space-between; border-bottom: 1.5px solid var(--border-line); padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap;">
                            <h3 style="margin-bottom: 0;">Research Group Quick Profile Finder</h3>
                            
                            <!-- Beautiful Custom Dropdown Container -->
                            <div class="custom-dropdown-container" style="position: relative; width: 100%; max-width: 320px; z-index: 120;">
                                <button type="button" id="customDropdownTrigger" class="custom-dropdown-trigger" style="display: flex; justify-content: space-between; align-items: center; width: 100%; padding: 10px 14px; border-radius: 10px; border: 1.5px solid var(--border-line); font-size: 13px; font-family: inherit; background: #faf9f6; color: var(--text-dark); cursor: pointer; text-align: left; transition: all 0.2s; outline: none; font-weight: 600; box-sizing: border-box;">
                                    <span id="customDropdownSelectedText">-- Select Student Group --</span>
                                    <i data-lucide="chevron-down" style="width: 15px; height: 15px; color: var(--text-muted); margin-left: 8px;"></i>
                                </button>
                                
                                <!-- Dropdown Menu List (hidden by default) -->
                                <div id="customDropdownMenu" class="custom-dropdown-menu" style="display: none; position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: var(--bg-white, #ffffff); border: 1.5px solid var(--border-line); border-radius: var(--control-radius); box-shadow: 0 10px 25px rgba(0,0,0,0.08); overflow: hidden; animation: slideIn 0.2s ease;">
                                    <!-- Mini Compact Search bar inside -->
                                    <div style="padding: 8px; border-bottom: 1px solid var(--border-line); background: #faf8f4; display: flex; align-items: center; gap: 8px;">
                                        <i data-lucide="search" style="color: #9ca3af; width: 13px; height: 13px; flex-shrink: 0;"></i>
                                        <input type="text" id="customDropdownSearch" placeholder="Type to filter..." style="border: none; background: transparent; outline: none; font-size: 11.5px; font-family: inherit; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark);" oninput="filterCustomDropdownOptions()">
                                    </div>
                                    
                                    <!-- Options wrapper -->
                                    <div id="customDropdownOptionsList" style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column;">
                                        <?php foreach ($workflow_tracks as $group): ?>
                                            <div class="custom-dropdown-item" 
                                                 onclick='selectCustomDropdownOption(<?= htmlspecialchars(json_encode($group), ENT_QUOTES, "UTF-8") ?>)' 
                                                 data-search-term="<?= htmlspecialchars(strtolower($group['research_group_name'] . ' ' . $group['username'] . ' ' . $group['program'] . ' ' . $group['department'])) ?>"
                                                 style="padding: 10px 14px; font-size: 12.5px; font-weight: 600; color: var(--text-dark); cursor: pointer; transition: background 0.2s; border-bottom: 1px solid rgba(0,0,0,0.03);"
                                                 onmouseover="this.style.backgroundColor='#faf8f4'"
                                                 onmouseout="this.style.backgroundColor='transparent'">
                                                <?= htmlspecialchars($group['research_group_name']) ?> <span style="font-weight: normal; color: var(--text-muted); font-size: 11px;">(<?= htmlspecialchars($group['username']) ?>)</span>
                                            </div>
                                        <?php endforeach; ?>
                                        <div id="customDropdownNoResults" style="display: none; padding: 12px; font-size: 11.5px; color: var(--text-muted); text-align: center;">No groups found</div>
                                    </div>
                                </div>
                            </div>
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

                    <!-- Recent Activities with custom link to stats module -->
                    <div class="activity-feed">
                        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:12px;"><i data-lucide="activity"></i> Recent Activity Logs</h3>
                        <?php if (count($recent_activities) === 0): ?>
                            <p style="text-align: center; color: var(--text-muted); padding: 30px;">No recent submissions yet.</p>
                        <?php else: ?>
                            <div id="activityLogsList">
                                <?php 
                                $index = 0;
                                foreach ($recent_activities as $activity): 
                                    if ($index >= 5) break;
                                    $icon_class = $activity['status_type'] === 'Approved' ? 'success' : ($activity['status_type'] === 'Revision Requested' ? 'warning' : 'info');
                                    $index++;
                                ?>
                                <div class="activity-item" onclick="openOverlay('admin_module_dynamic.php?phase=stats', document.querySelectorAll('.nav-item-btn')[1])" style="cursor: pointer;">
                                    <div class="activity-icon <?= $icon_class ?>">
                                        <?= $icon_class === 'success' ? '✓' : ($icon_class === 'warning' ? '!' : '📄') ?>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">New Submission: <?= htmlspecialchars($activity['title']) ?></div>
                                        <div class="activity-desc">Status: <?= htmlspecialchars($activity['status_type']) ?></div>
                                        <div class="activity-time">📦 <?= htmlspecialchars($activity['research_group_name']) ?> • <?= date('M d, Y @ h:i A', strtotime($activity['created_at'])) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($recent_activities) > 5): ?>
                                <div style="text-align: center; margin-top: 16px;">
                                    <button type="button" onclick="openActivityLogsModal()" style="background: #faf8f4; border: 1.5px solid var(--border-line); padding: 8px 18px; border-radius: 8px; font-family: var(--ui-sans); font-size: 12.5px; font-weight: 700; color: var(--mcnp-teal); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                                        <i data-lucide="history" style="width: 15px; height: 15px;"></i> See All Activity Logs (<?= count($recent_activities) ?>)
                                    </button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- COMPREHENSIVE ACTIVITY LOGS MODAL -->
                <div id="activityLogsModal" class="fullscreen-modal" style="display: none; position: fixed; inset: 0; background: rgba(12, 52, 61, 0.45); backdrop-filter: blur(8px); z-index: 200; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
                    <div style="background: var(--bg-white, #ffffff); width: 100%; max-width: 750px; border-radius: var(--card-radius); border: 2px solid var(--border-line); box-shadow: 0 20px 50px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; animation: slideIn 0.3s ease;">
                        
                        <!-- Modal Header -->
                        <div style="padding: 20px 24px; border-bottom: 2.5px solid var(--border-line); display: flex; justify-content: space-between; align-items: center; background: #faf8f4; flex-shrink: 0;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: var(--mcnp-teal); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i data-lucide="history" style="width: 18px; height: 18px;"></i>
                                </div>
                                <div>
                                    <h3 style="font-family: 'Cinzel', serif; color: var(--mcnp-teal); font-size: 18px; margin: 0; font-weight: 800;">Comprehensive Activity Logs</h3>
                                    <p style="color: var(--text-muted); font-size: 11.5px; margin: 0;">Institutional Submission & Review Logs Pipeline</p>
                                </div>
                            </div>
                            <button type="button" onclick="closeActivityLogsModal()" style="background: transparent; border: none; cursor: pointer; color: var(--text-muted); padding: 6px; transition: scale 0.2s;" onmouseover="this.style.scale=1.1" onmouseout="this.style.scale=1">
                                <i data-lucide="x" style="width: 22px; height: 22px;"></i>
                            </button>
                        </div>

                        <!-- Filter Controls Area inside Modal -->
                        <div style="padding: 16px 24px; background: #fdfdfd; border-bottom: 1.5px solid var(--border-line); display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; box-sizing: border-box; flex-shrink: 0;">
                            <!-- Text Search Filter -->
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Search Logs</label>
                                <div style="position: relative; display: flex; align-items: center; background: #faf8f4; border: 1.5px solid var(--border-line); border-radius: 8px; padding: 6px 10px;">
                                    <i data-lucide="search" style="color: #9ca3af; width: 12px; height: 12px; margin-right: 6px;"></i>
                                    <input type="text" id="modalLogSearch" placeholder="Filter groups / titles..." onkeyup="filterModalLogs()" style="background: transparent; border: none; outline: none; font-size: 11.5px; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark); font-family: var(--ui-sans);">
                                </div>
                            </div>

                            <!-- Filter Status -->
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Filter Status</label>
                                <select id="modalLogStatusFilter" onchange="filterModalLogs()" style="padding: 6px 10px; border-radius: 8px; border: 1.5px solid var(--border-line); font-size: 11.5px; font-weight: 600; outline: none; margin-top: 0; background: #faf9f6;">
                                    <option value="all">All Statuses</option>
                                    <option value="Approved">Approved / Success</option>
                                    <option value="Revision Requested">Revision Requested / Warnings</option>
                                    <option value="other">Pending / Info / Reviews</option>
                                </select>
                            </div>

                            <!-- Form/Document Filter -->
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Filter File / Form Stage</label>
                                <select id="modalLogFormFilter" onchange="filterModalLogs()" style="padding: 6px 10px; border-radius: 8px; border: 1.5px solid var(--border-line); font-size: 11.5px; font-weight: 600; outline: none; margin-top: 0; background: #faf9f6;">
                                    <option value="all">All Stages</option>
                                    <option value="capsule">Capsule Proposal (Form No. 008)</option>
                                    <option value="final">Final Defense / Form 5</option>
                                    <option value="plagiarism">Plagiarism Verification</option>
                                    <option value="endorsement">Institutional Endorsement</option>
                                    <option value="general">Other General Milestones</option>
                                </select>
                            </div>
                        </div>

                        <!-- Scrollable Logs Content inside Modal -->
                        <div id="modalLogsContainer" style="padding: 16px 24px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 12px; background: #faf9f6;">
                            <!-- Dynamic elements will be injected here via JavaScript -->
                        </div>

                        <!-- Modal Footer -->
                        <div style="padding: 14px 24px; border-top: 1.5px solid var(--border-line); display: flex; justify-content: space-between; align-items: center; background: #faf8f4; flex-shrink: 0;">
                            <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 600;" id="modalLogCount">Showing 0 of 0 logs</span>
                            <button type="button" class="btn btn-secondary" onclick="closeActivityLogsModal()" style="padding: 8px 16px; font-size: 11.5px;">Close Portal</button>
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
        </main>

        <div class="fullscreen-overlay" id="moduleOverlay" style="left: 280px; width: calc(100% - 280px); background: var(--bg-canvas);">
            <iframe id="moduleFrame" class="overlay-iframe"></iframe>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Standardized dynamic clock updater (without timezone text to give breath)
        function updateClock() {
            const clockEl = document.getElementById('statsClock');
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

        // Custom Dropdown State Handlers
        const dropdownTrigger = document.getElementById('customDropdownTrigger');
        const dropdownMenu = document.getElementById('customDropdownMenu');
        const dropdownSearch = document.getElementById('customDropdownSearch');

        if (dropdownTrigger && dropdownMenu) {
            dropdownTrigger.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = dropdownMenu.style.display === 'block';
                dropdownMenu.style.display = isOpen ? 'none' : 'block';
                if (!isOpen && dropdownSearch) {
                    dropdownSearch.value = '';
                    filterCustomDropdownOptions();
                    setTimeout(() => dropdownSearch.focus(), 50);
                }
            });

            document.addEventListener('click', (e) => {
                if (!dropdownMenu.contains(e.target) && !dropdownTrigger.contains(e.target)) {
                    dropdownMenu.style.display = 'none';
                }
            });
        }

        function filterCustomDropdownOptions() {
            const q = document.getElementById('customDropdownSearch').value.toLowerCase().trim();
            const items = document.querySelectorAll('#customDropdownOptionsList .custom-dropdown-item');
            let hasResults = false;
            items.forEach(item => {
                const text = item.getAttribute('data-search-term') || '';
                if (text.includes(q)) {
                    item.style.display = 'block';
                    hasResults = true;
                } else {
                    item.style.display = 'none';
                }
            });
            const noResults = document.getElementById('customDropdownNoResults');
            if (noResults) {
                noResults.style.display = hasResults ? 'none' : 'block';
            }
        }

        function selectCustomDropdownOption(data) {
            const textEl = document.getElementById('customDropdownSelectedText');
            if (textEl) {
                textEl.textContent = data.research_group_name;
            }
            if (dropdownMenu) {
                dropdownMenu.style.display = 'none';
            }
            
            const card = document.getElementById('selectedGroupProfile');
            if (card) {
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
            }
        }

        // Complete workspace-switcher layout handlers
        function hideAllDashboards() {
            document.getElementById('masterDashboard').style.display = 'none';
            document.getElementById('settingsDashboard').style.display = 'none';
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

        function openOverlay(url, btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('moduleFrame').src = url;
            document.getElementById('moduleOverlay').classList.add('active');
        }

        // Live persistence initialization for premium administrative customizable themes
        const localThemeKey = "rd-portal-theme";
        const currentTheme = localStorage.getItem(localThemeKey) || "theme-default";
        document.body.className = currentTheme;
        
        const themeSelectorEl = document.getElementById('user_theme_select');
        if (themeSelectorEl) {
            themeSelectorEl.value = currentTheme;
            themeSelectorEl.addEventListener('change', (e) => {
                const updatedTheme = e.target.value;
                setQuickTheme(updatedTheme);
            });
        }

        function setQuickTheme(themeName) {
            document.body.className = themeName;
            localStorage.setItem(localThemeKey, themeName);
            if (themeSelectorEl) {
                themeSelectorEl.value = themeName;
            }
            // Broaden notification message theme switch inside iframe overlay if visible
            const frame = document.getElementById('moduleFrame');
            if (frame && frame.contentWindow) {
                try {
                    frame.contentWindow.postMessage({ action: 'themeChanged', theme: themeName }, '*');
                } catch(e) {}
            }
        }

        function updateNavClock() {
            const timeEl = document.getElementById('navClockTime');
            if (timeEl) {
                const now = new Date();
                const timeString = now.toLocaleString("en-US", { 
                    timeZone: "Asia/Manila", 
                    weekday: 'short', 
                    month: 'short', 
                    day: 'numeric', 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
                timeEl.textContent = timeString;
            }
        }
        setInterval(updateNavClock, 1000);
        updateNavClock();

        // Perform search logic across rows in the workflow track table
        function performGlobalTableSearch() {
            const q = document.getElementById('workspaceGlobalSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('table tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Hotkey trigger: Cmd+Space / Ctrl+Space focuses global search
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === ' ') {
                e.preventDefault();
                const searchInput = document.getElementById('workspaceGlobalSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });

        // Modal & Log Filtering Support (cloned precisely from director.php to keep consistency)
        const allRecentActivities = <?= json_encode($recent_activities) ?>;

        function getFormCategory(title, description) {
            const text = (title + ' ' + description).toLowerCase();
            if (text.includes('capsule') || text.includes('proposal') || text.includes('008')) {
                return 'capsule';
            } else if (text.includes('final') || text.includes('defense') || text.includes('award') || text.includes('milestone')) {
                return 'final';
            } else if (text.includes('plagiarism') || text.includes('plag')) {
                return 'plagiarism';
            } else if (text.includes('endorse') || text.includes('endorsement')) {
                return 'endorsement';
            }
            return 'general';
        }

        function openActivityLogsModal() {
            const modal = document.getElementById('activityLogsModal');
            if (modal) {
                modal.style.display = 'flex';
                // Reset inputs
                document.getElementById('modalLogSearch').value = '';
                document.getElementById('modalLogStatusFilter').value = 'all';
                document.getElementById('modalLogFormFilter').value = 'all';
                filterModalLogs();
            }
        }

        function closeActivityLogsModal() {
            const modal = document.getElementById('activityLogsModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function filterModalLogs() {
            const searchVal = document.getElementById('modalLogSearch').value.toLowerCase().trim();
            const statusVal = document.getElementById('modalLogStatusFilter').value;
            const formVal = document.getElementById('modalLogFormFilter').value;
            const container = document.getElementById('modalLogsContainer');

            if (!container) return;
            container.innerHTML = '';

            let matchedCount = 0;

            allRecentActivities.forEach(activity => {
                const title = activity.title || '';
                const desc = activity.description || '';
                const groupName = activity.research_group_name || '';
                const statusType = activity.status_type || '';
                const username = activity.username || '';
                
                // Form type classification
                const category = getFormCategory(title, desc);

                // Check Text Search
                const matchesSearch = title.toLowerCase().includes(searchVal) || 
                                      desc.toLowerCase().includes(searchVal) || 
                                      groupName.toLowerCase().includes(searchVal) ||
                                      username.toLowerCase().includes(searchVal);

                // Check Status Filter
                let matchesStatus = true;
                if (statusVal === 'Approved') {
                    matchesStatus = (statusType === 'Approved');
                } else if (statusVal === 'Revision Requested') {
                    matchesStatus = (statusType === 'Revision Requested');
                } else if (statusVal === 'other') {
                    matchesStatus = (statusType !== 'Approved' && statusType !== 'Revision Requested');
                }

                // Check Form Filter
                const matchesForm = (formVal === 'all' || category === formVal);

                if (matchesSearch && matchesStatus && matchesForm) {
                    matchedCount++;
                    const iconClass = statusType === 'Approved' ? 'success' : (statusType === 'Revision Requested' ? 'warning' : 'info');
                    
                    const itemHtml = `
                        <div class="activity-item" onclick="openOverlay('admin_module_dynamic.php?phase=stats', document.querySelectorAll('.nav-item-btn')[1])" style="cursor: pointer; margin-bottom: 0;">
                            <div class="activity-icon ${iconClass}">
                                ${iconClass === 'success' ? '✓' : (iconClass === 'warning' ? '!' : '📄')}
                            </div>
                            <div class="activity-content" style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                    <div class="activity-title" style="font-weight: 700; color: var(--text-dark);">New Submission: ${escapeHtml(title)}</div>
                                    <span style="font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 6px; background: ${iconClass==='success' ? '#e6f4ea; color:#137333;' : (iconClass==='warning' ? '#fef7e0; color:#b06000;' : '#e8f0fe; color:#1a73e8;')}; text-transform: uppercase;">
                                        ${escapeHtml(statusType)}
                                    </span>
                                </div>
                                <div class="activity-desc" style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">${escapeHtml(desc)}</div>
                                <div class="activity-time" style="margin-top: 6px; font-size: 10.5px; font-weight: 600; color: var(--mcnp-teal);">
                                    📦 ${escapeHtml(groupName)} (${escapeHtml(username)}) • ${formatLogDate(activity.created_at)}
                                </div>
                            </div>
                        </div>
                    `;
                    container.insertAdjacentHTML('beforeend', itemHtml);
                }
            });

            document.getElementById('modalLogCount').textContent = `Showing ${matchedCount} of ${allRecentActivities.length} logs`;
            lucide.createIcons();
            
            if (matchedCount === 0) {
                container.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 40px; font-weight: 600;">No matching activity logs found.</div>`;
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function formatLogDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' @ ' + 
                   d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }
    </script>
</body>
</html>
