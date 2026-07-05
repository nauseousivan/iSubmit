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

// Fetch submission counts per college (for Master Dashboard)
$college_counts_query = "
    SELECT
        CASE
            WHEN u.department LIKE '%Medical Colleges%' THEN 'MCNP'
            WHEN u.department LIKE '%International School%' THEN 'ISAP'
            ELSE 'Other'
        END as college,
        'Proposal' as item_type,
        COUNT(DISTINCT up.upload_id) as pending_count
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    WHERE up.verification_status IN ('Pending', 'Under Review')
    AND up.item_id = 14
    GROUP BY college
    
    UNION ALL
    
    SELECT
        CASE
            WHEN u.department LIKE '%Medical Colleges%' THEN 'MCNP'
            WHEN u.department LIKE '%International School%' THEN 'ISAP'
            ELSE 'Other'
        END as college,
        'Data/Literature' as item_type,
        COUNT(DISTINCT up.upload_id) as pending_count
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    WHERE up.verification_status IN ('Pending', 'Under Review')
    AND up.item_id IN (3, 4)
    GROUP BY college
";

$college_counts = $pdo->query($college_counts_query)->fetchAll();

$counts_by_college = ['ISAP' => ['Proposal' => 0, 'Data/Literature' => 0], 'MCNP' => ['Proposal' => 0, 'Data/Literature' => 0]];
foreach ($college_counts as $cc) {
    $college = $cc['college'] ?? 'ISAP';
    if (!isset($counts_by_college[$college])) {
        $counts_by_college[$college] = ['Proposal' => 0, 'Data/Literature' => 0];
    }
    $counts_by_college[$college][$cc['item_type']] = $cc['pending_count'];
}

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
$pending_counts = $pdo->query("
    SELECT 
        SUM(CASE WHEN up.item_id = 14 THEN 1 ELSE 0 END) as proposal_pending,
        SUM(CASE WHEN up.item_id IN (25,27) THEN 1 ELSE 0 END) as final_pending,
        SUM(CASE WHEN up.item_id = 4 THEN 1 ELSE 0 END) as plag_pending
    FROM uploads up
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
                <img src="https://isap.edu.ph/wp-content/uploads/2022/07/ISAP-LOGO-2022.png" class="sidebar-logo">
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
            <!-- MASTER DASHBOARD -->
            <div class="container" id="masterDashboard">
                <div class="header">
                    <div class="header-title">
                        <h1>Research Director Terminal</h1>
                        <p>Overview of all research stages, payment validations, and recent achievements.</p>
                    </div>
                    
                    <!-- Calendar Realtime Clock countdown widget -->
                    <div class="clock-widget">
                        <i data-lucide="clock"></i>
                        <span id="directorTimeClock">loading...</span>
                    </div>
                </div>

                <?php if($message): ?>
                    <div class="alert-success">
                        <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 6px;"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid with cohesive look -->
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= count($workflow_tracks) ?></div>
                        <div class="stat-label">Research Groups</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= array_sum($counts_by_college['ISAP']) ?></div>
                        <div class="stat-label">ISAP Pending Tasks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= array_sum($counts_by_college['MCNP']) ?></div>
                        <div class="stat-label">MCNP Pending Tasks</div>
                    </div>
                </div>

                <!-- NEW: Interactive Student/Group Selector & Quick Profile View -->
                <div class="selector-section">
                    <div class="selector-header" style="display: flex; gap: 12px; align-items: center; justify-content: space-between; border-bottom: 1.5px solid var(--border-line); padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap;">
                        <h3 style="margin-bottom: 0;">Interactive Student/Group Explorer</h3>
                        
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
 
                     <!-- Selected Group Profile Display -->
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
                                     <div style="font-size:12px;"><span style="color:#6b7280;">Coordinator:</span> <strong id="progCoord">Pending</strong></div>
                                     <div style="font-size:12px;"><span style="color:#6b7280;">Statistician:</span> <strong id="progStats">Pending</strong></div>
                                     <div style="font-size:12px;"><span style="color:#6b7280;">Payment Status:</span> <strong id="progPay">Unpaid</strong></div>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
 
                 <!-- Recent Activities with custom link to proposal -->
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
                             <div class="activity-item" onclick="openOverlay('admin_module_dynamic.php?phase=proposal', document.querySelectorAll('.nav-item-btn')[2])">
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
 
             <!-- ACTIVITY LOGS MODAL -->
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
 
                         <!-- Status Filter -->
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
             </div>
 
             <script>
                 function toggleActivityLogs() {
                     // deprecated inline toggle, handled by modal logs now
                 }
             </script>

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

                <div class="section" style="max-width: 680px; margin-bottom: 30px;">
                    <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:16px; margin-bottom:20px; border-bottom:1.5px solid var(--border-line); padding-bottom:10px;"><i data-lucide="user-cog" style="vertical-align: middle; margin-right: 6px;"></i> Update Identity & Custom Settings</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action_type" value="update_admin_settings">
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Username</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($currentUser['username']) ?>" required style="width: 100%; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); font-family: inherit; font-size: 13.5px; outline: none;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Avatar Picker</label>
                            
                            <!-- Custom Link/Avatar select -->
                            <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 12px;">
                                <img id="settingsAvatarPreview" src="<?= htmlspecialchars($current_pfp) ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--mcnp-teal); background: white;">
                                <input type="text" id="pfpUrlInput" name="profile_pic" value="<?= htmlspecialchars($currentUser['profile_pic'] ?? '') ?>" placeholder="Paste image URL or pick avatar below..." style="flex:1; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); font-family: inherit; font-size: 13.5px; outline: none;" oninput="document.getElementById('settingsAvatarPreview').src = this.value || 'https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($currentUser['username']) ?>';">
                            </div>

                            <!-- Beautiful Avatar Grid selectors -->
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 5px;">
                                <?php 
                                $temp_seeds = ['Director', 'Coordinator', 'Stats', 'Research', 'Tech', 'Grace'];
                                foreach ($temp_seeds as $s): 
                                    $av_url = "https://api.dicebear.com/9.x/avataaars/svg?seed=" . $s;
                                ?>
                                <button type="button" onclick="setSettingAvatar('<?= $av_url ?>')" style="padding:4px; border-radius: 8px; border: 1.5px solid var(--border-line); background: white; cursor: pointer; transition: 0.2s;"><img src="<?= $av_url ?>" style="width:32px; height:32px; border-radius:50%; pointer-events: none;"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Theme Selection Palette grid -->
                        <div style="margin-bottom: 25px;">
                            <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:10px;">Select Dashboard Theme Color</label>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <button type="button" class="theme-select-btn default" onclick="setSettingTheme('theme-default')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #f7f5ef 50%, #0c343d 50%); cursor:pointer;" title="MCNP Blue-Teal Default"></button>
                                <button type="button" class="theme-select-btn dark" onclick="setSettingTheme('theme-dark')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #171c1f 50%, #22d3ee 50%); cursor:pointer;" title="Midnight Dark"></button>
                                <button type="button" class="theme-select-btn green" onclick="setSettingTheme('theme-green')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #1e3f20 50%); cursor:pointer;" title="Science Green"></button>
                                <button type="button" class="theme-select-btn red" onclick="setSettingTheme('theme-red')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #571616 50%); cursor:pointer;" title="ISAP Red Maroon"></button>
                                <button type="button" class="theme-select-btn pink" onclick="setSettingTheme('theme-pink')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #5e1c3e 50%); cursor:pointer;" title="Rose Pastel"></button>
                                <button type="button" class="theme-select-btn purple" onclick="setSettingTheme('theme-purple')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #3b1e5a 50%); cursor:pointer;" title="Lavender Pastel"></button>
                                <button type="button" class="theme-select-btn orange" onclick="setSettingTheme('theme-orange')" style="width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid transparent; background: linear-gradient(135deg, #ffffff 50%, #5d2b0e 50%); cursor:pointer;" title="Amber Sand"></button>
                            </div>
                        </div>

                        <div style="border-top:1.5px solid var(--border-line); padding-top:15px; margin-bottom:20px;">
                            <h4 style="font-family:'Cinzel', serif; font-size:13px; color:var(--mcnp-teal); margin-bottom:12px;"><i data-lucide="shield-check" style="vertical-align: middle; margin-right: 4px;"></i> Change Password (Optional)</h4>
                            
                            <div style="margin-bottom: 12px;">
                                <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Current Password</label>
                                <input type="password" name="current_password" style="width: 100%; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">New Password</label>
                                    <input type="password" name="new_password" style="width: 100%; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Confirm Password</label>
                                    <input type="password" name="confirm_password" style="width: 100%; padding: 12px; border-radius: 10px; border: 1.5px solid var(--border-line); background: #faf9f6; color: var(--text-dark); outline: none;">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; padding:14px; font-weight:bold; font-size:13px; margin-top:10px;"><i data-lucide="save"></i> Save Configuration Updates</button>
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

                <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
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
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($calendar_events)): ?>
                                        <tr><td colspan="3" style="text-align:center; color:var(--text-muted);">No custom events configured.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach($calendar_events as $ce): ?>
                                    <tr>
                                        <td><strong><?= date('M d, Y', strtotime($ce['event_date'])) ?></strong></td>
                                        <td><?= htmlspecialchars($ce['title']) ?><br><small style="color:var(--text-muted);"><?= htmlspecialchars($ce['description']) ?></small></td>
                                        <td>
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

        // 2. Select Student Group Profile Display & Custom Dropdown Setup
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

                // Populate workflow tracking metrics indicators in profile text
                document.getElementById('progCoord').textContent = data.coordinator_status;
                document.getElementById('progCoord').className = 'badge-status ' + (data.coordinator_status === 'Approved' ? 'badge-paid' : 'badge-pending');
                
                document.getElementById('progStats').textContent = data.statistician_status;
                document.getElementById('progStats').className = 'badge-status ' + (data.statistician_status === 'Approved' ? 'badge-paid' : 'badge-pending');

                document.getElementById('progPay').textContent = data.payment_status;
                document.getElementById('progPay').className = 'badge-status ' + (data.payment_status === 'Paid' ? 'badge-paid' : 'badge-unpaid');

                lucide.createIcons();
            }
        }

        // 3. Comprehensive Activity Logs Modal Logic
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
                const statusType = activity.status_type || '';
                const groupName = activity.research_group_name || '';
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
                        <div class="activity-item" style="cursor: default; margin-bottom: 0;">
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
        function setSettingAvatar(url) {
            document.getElementById('pfpUrlInput').value = url;
            document.getElementById('settingsAvatarPreview').src = url;
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
