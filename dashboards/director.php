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
    <style>
        :root {
            --card-radius: 20px;
            --control-radius: 12px;
            --ui-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
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
            font-family: var(--ui-sans);
            background-color: var(--bg-canvas) !important;
            min-height: 100vh; 
            color: var(--text-dark); 
            display: flex; 
            font-size: 14px;
        }

        /* Container styling */
        .app-dashboard-frame { 
            background-color: var(--bg-canvas); 
            width: 100vw; 
            height: 100vh; 
            display: flex; 
            position: relative; 
            overflow: hidden; 
        }

        /* Ambient subtle grid */
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

        /* Current Time clock */
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

        .clock-widget i {
            color: var(--mcnp-teal);
        }

        .clock-widget span {
            font-weight: 700;
            color: var(--mcnp-teal);
            font-size: 14px;
        }

        /* Stats Cards */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
            gap: 18px; 
            margin-bottom: 28px; 
        }
        
        .stat-card { 
            background: var(--bg-white); 
            border-radius: var(--card-radius); 
            padding: 24px; 
            box-shadow: 0 8px 25px rgba(12,52,61,0.03); 
            border-left: 5px solid var(--mcnp-teal); 
            border: 1.5px solid var(--border-line);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, transparent 40%, rgba(204, 153, 0, 0.1) 100%);
        }
        
        .stat-value { 
            font-size: 32px; 
            font-weight: 800; 
            color: var(--mcnp-teal); 
            margin-bottom: 4px; 
            font-family: 'Cinzel', serif;
        }
        
        .stat-label { 
            font-size: 12.5px; 
            color: var(--text-muted); 
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: bold;
        }

        /* Interactive student selector panel */
        .selector-section {
            background: white;
            border: 1.5px solid var(--border-line);
            border-radius: 20px;
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

        .selector-header h3 {
            font-family: 'Cinzel', serif;
            font-size: 14.5px;
            color: var(--mcnp-teal);
        }

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

        /* General sections */
        .section { 
            background: var(--bg-white); 
            border-radius: var(--card-radius); 
            box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
            border: 1.5px solid var(--border-line);
            padding: 28px; 
            margin-bottom: 24px; 
        }
        
        .section h3 { 
            font-family: 'Cinzel', serif;
            color: var(--mcnp-teal); 
            margin-bottom: 16px; 
            font-size: 16px;
            letter-spacing: 0.3px;
        }
        
        .table-wrapper { overflow-x: auto; border-radius: 12px; border: 1.5px solid var(--border-line); }
        
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        
        th, td { padding: 14px 16px; text-align: left; vertical-align: middle; border-bottom: 1px solid var(--border-line); }
        
        th { 
            background: #faf8f4; 
            color: var(--text-dark); 
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        select, textarea { 
            width: 100%; 
            padding: 11px 14px; 
            border-radius: var(--control-radius); 
            border: 1.5px solid var(--border-line); 
            background: #fdfbf7; 
            color: var(--text-dark); 
            font-family: inherit; 
            font-size: 13.5px; 
            margin-top: 6px; 
            outline: none;
            transition: all 0.2s;
        }
        
        select:focus, textarea:focus { 
            border-color: var(--mcnp-teal); 
            background: var(--bg-white); 
            box-shadow: 0 0 0 3px rgba(12,52,61,0.08); 
        }
        
        textarea { resize: vertical; min-height: 80px; }
        
        .btn { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            border: none; 
            border-radius: 10px; 
            padding: 10px 18px; 
            font-weight: 700; 
            cursor: pointer; 
            text-decoration: none; 
            font-size: 12.5px;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-primary { 
            background: var(--mcnp-teal); 
            color: #fff; 
        }
        
        .btn-primary:hover { 
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(12, 52, 61, 0.15);
        }
        
        .btn-secondary { 
            background: var(--bg-white); 
            color: var(--text-dark); 
            border: 1.5px solid var(--border-line); 
        }
        
        .btn-secondary:hover { 
            background: #faf8f4; 
        }
        
        .btn-dispatch { 
            background: #4d0026; 
            color: #fff; 
        }
        
        .btn-dispatch:hover { 
            opacity: .95; 
        }

        .badge-status { 
            padding: 4px 10px; 
            border-radius: 20px; 
            font-size: 10px; 
            font-weight: 800; 
            text-transform: uppercase; 
            display: inline-block; 
            letter-spacing: 0.5px;
        }
        
        .badge-paid { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .badge-pending { background: #fff3cd; color: #856404; border: 1px solid #fcd34d; }

        .form-actions { margin-top: 18px; }

        .alert-success { background: #ecfdf5; color: #136643; padding: 16px 20px; border-radius: 14px; margin-bottom: 24px; font-weight: 700; border-left: 5px solid var(--success); }

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

        .sidebar-footer { 
            margin-top: auto; 
            padding-top: 20px; 
            border-top: 2px solid rgba(255,255,255,0.08); 
            text-align: center; 
        }
        
        .sidebar-footer p { font-size: 11.5px; opacity: 0.7; margin-bottom: 12px; color: white; }
        
        .logout-btn { 
            width: 100%; 
            padding: 11px 14px; 
            background: rgba(220, 38, 38, 0.85); 
            border: none; 
            color: white; 
            border-radius: 10px; 
            cursor: pointer; 
            font-weight: bold; 
            font-size: 12.5px; 
            transition: 0.2s; 
            text-decoration: none; 
            display: block; 
        }
        
        .logout-btn:hover { background: #b91c1c; }

        @media (max-width: 900px) {
            .app-sidebar { display: none; }
            table { min-width: 100%; }
        }

        /* IFRAME OVERLAY SYSTEM FOR EMBEDDED CONSOLE */
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
            border-radius: 0; 
            background: transparent; 
        }
        
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(10px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
    </style>
</head>
<body>
    <div class="app-dashboard-frame">
        <aside class="app-sidebar">
            <div class="sidebar-header">
                <img src="../mcnp-isap.jpg" class="sidebar-logo">
                <h2>Staff Portal</h2>
                <p>Research Director</p>
            </div>
            
            <ul class="nav-menu-list">
                <li><button class="nav-item-btn active" onclick="showMasterDashboard(this)">
                    <i data-lucide="layout-dashboard"></i> Master Dashboard
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=proposal', this)">
                    <i data-lucide="file-check"></i> Proposal Defense
                    <?= $pending_counts['proposal_pending'] > 0 ? '<span class="nav-badge">' . $pending_counts['proposal_pending'] . '</span>' : '' ?>
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=final', this)">
                    <i data-lucide="award"></i> Final Manuscript
                    <?= $pending_counts['final_pending'] > 0 ? '<span class="nav-badge">' . $pending_counts['final_pending'] . '</span>' : '' ?>
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=stats', this)">
                    <i data-lucide="calculator"></i> Statistics Clearance
                    <?= $pending_counts['stats_pending'] > 0 ? '<span class="nav-badge">' . $pending_counts['stats_pending'] . '</span>' : '' ?>
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('admin_module_dynamic.php?phase=plag', this)">
                    <i data-lucide="file-warning"></i> Plagiarism Verify
                    <?= $pending_counts['plag_pending'] > 0 ? '<span class="nav-badge">' . $pending_counts['plag_pending'] . '</span>' : '' ?>
                </button></li>
                <li><button class="nav-item-btn" onclick="openOverlay('message.php', this)">
                    <i data-lucide="message-square"></i> Messages
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
                        <span style="font-size: 10px; color: #cc9900; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Director</span>
                    </div>
                </div>
                <a href="../auth/logout.php" style="width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); color: #f87171; border-radius: 8px; transition: all 0.25s; flex-shrink: 0; border: 1px solid rgba(239, 68, 68, 0.2); text-decoration: none;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.color='#f87171';" title="Logout">
                    <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
                </a>
            </div>
        </aside>

        <main class="main-workspace-content">
            <?php include __DIR__ . "/_master_overview.php"; ?>

            <!-- STATISTICS & APPROVALS DASHBOARD -->
            <div class="container" id="statisticsDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Statistics & Approvals</h1>
                        <p>Review workflow approvals and authorize outgoing SMTP email notifications.</p>
                    </div>
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="directorTimeClockStats">loading...</span>
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
                        <p>Modify your name, edit profile elements, choose color palettes, and change secure credentials.</p>
                    </div>
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="directorTimeClockSettings">loading...</span>
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

                            <!-- Theme Selection Palette grid -->
                            <div style="margin-bottom: 22px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Dashboard Theme</label>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button type="button" class="theme-select-btn default" onclick="setSettingTheme('theme-default')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #f7f5ef 50%, #0c343d 50%); cursor:pointer;" title="MCNP Blue-Teal Default"></button>
                                    <button type="button" class="theme-select-btn dark" onclick="setSettingTheme('theme-dark')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #171c1f 50%, #22d3ee 50%); cursor:pointer;" title="Midnight Dark"></button>
                                    <button type="button" class="theme-select-btn green" onclick="setSettingTheme('theme-green')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #1e3f20 50%); cursor:pointer;" title="Science Green"></button>
                                    <button type="button" class="theme-select-btn red" onclick="setSettingTheme('theme-red')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #571616 50%); cursor:pointer;" title="ISAP Red Maroon"></button>
                                    <button type="button" class="theme-select-btn pink" onclick="setSettingTheme('theme-pink')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #5e1c3e 50%); cursor:pointer;" title="Rose Pastel"></button>
                                    <button type="button" class="theme-select-btn purple" onclick="setSettingTheme('theme-purple')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #3b1e5a 50%); cursor:pointer;" title="Lavender Pastel"></button>
                                    <button type="button" class="theme-select-btn orange" onclick="setSettingTheme('theme-orange')" style="width: 32px; height: 32px; border-radius: 9px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #5d2b0e 50%); cursor:pointer;" title="Amber Sand"></button>
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

        <!-- MODULAR OVERLAY CONTAINER -->
        <div class="fullscreen-overlay" id="moduleOverlay">
            <iframe id="moduleFrame" class="overlay-iframe"></iframe>
        </div>
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
            document.getElementById('moduleOverlay').classList.remove('active');
            document.getElementById('moduleFrame').src = 'about:blank';
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

        function openOverlay(url, btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('moduleFrame').src = url;
            document.getElementById('moduleOverlay').classList.add('active');
        }

        // Settings Avatar and Theme update helpers
        function selectAvatarPreset(url, el) {
            document.getElementById('pfp_selector').value = url;
            document.querySelectorAll('.avatar-preset-option').forEach(img => img.style.borderColor = 'var(--border-line)');
            el.style.borderColor = 'var(--mcnp-teal)';
        }

        function setSettingTheme(t) {
            localStorage.setItem('rd-portal-theme', t);
            document.body.className = t;
            
            // Sync active theme indicator button
            document.querySelectorAll('.theme-select-btn').forEach(btn => btn.style.borderColor = 'transparent');
            const trimmed = t.replace('theme-', '');
            const targetBtn = document.querySelector(`.theme-select-btn.${trimmed}`);
            if (targetBtn) targetBtn.style.borderColor = 'var(--accent-teal)';
        }

        // Initialize active theme buttons on load
        window.addEventListener('load', () => {
            const currentTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            setSettingTheme(currentTheme);
        });
    </script>
</body>
</html>
