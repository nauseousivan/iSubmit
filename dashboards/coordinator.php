<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce Session Access Rule
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Research Coordinator') {
    header("Location: ../auth/login.php");
    exit();
}

$uid = $_SESSION['user_id'];
$message = "";
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

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
    // Redirect-after-POST: prevents a page refresh from resubmitting the form and re-showing this message.
    $_SESSION['flash_message'] = $message;
    header("Location: coordinator.php");
    exit();
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
    // Redirect-after-POST: prevents a page refresh from resubmitting the form and re-showing this message.
    $_SESSION['flash_message'] = $message;
    header("Location: coordinator.php");
    exit();
}

// Fetch active profile details
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = (!empty($currentUser['profile_pic'])) ? $currentUser['profile_pic'] : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

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

// Fetch coordinator alerts count.
// Coordinator acts on 'Pending' submissions. Count only the LATEST upload per group per item
// (mirrors the admin module) so old superseded versions don't inflate the nav badges.
// Coordinator is a sibling of Director and can review every phase, so we count all phases here.
// Stats range includes 36/37 (payment docs): Coordinator has one combined Statistics badge (no
// separate Payment badge like statistician.php has), so it must cover everything phase=stats
// shows in its default 'all' view, or pending payment docs would never surface anywhere.
// Scoped to her institution, like the checklist it counts (and nav_badge_counts.php).
require_once __DIR__ . '/admin_accounts_lib.php';
[$pc_sc_sql, $pc_sc_params] = aa_scope_sql($pdo, $_SESSION['role'], (int)$_SESSION['user_id'], 'u');
$pending_counts = $pdo->prepare("
    SELECT
        SUM(CASE WHEN up.item_id IN (11,12,13,14,15,16,17) THEN 1 ELSE 0 END) as proposal_pending,
        SUM(CASE WHEN up.item_id IN (20,22,23,24,25,26,27) THEN 1 ELSE 0 END) as final_pending,
        SUM(CASE WHEN up.item_id IN (30,31,32,34,35) THEN 1 ELSE 0 END) as stats_pending
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date
        FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    JOIN users u ON u.user_id = up.user_id
    WHERE up.verification_status = 'Pending' AND $pc_sc_sql
");
$pending_counts->execute($pc_sc_params);
$pending_counts = $pending_counts->fetch();

// Manuscript's four desks. Pulled out of the uploads query above because item 4's
// verification_status no longer says which desk a group is at — the phase ladder does.
// Schedule bundles waiting on THIS role — Coordinator holds step 2, Director also step 3.
require_once __DIR__ . '/focal_lib.php';
$nb_school = fp_school_scope($pdo, $_SESSION['role'], (int)$_SESSION['user_id']);
// Schedule badge — sections this viewer can LOG. The Coordinator signs on Approvals, not here,
// so this is 0 for her by design. Mirrors director.php; see fp_schedule_badge().
$pending_counts['schedule_pending']          = fp_schedule_badge($pdo, $_SESSION['role'], 'final', $nb_school);
$pending_counts['schedule_pending_proposal'] = fp_schedule_badge($pdo, $_SESSION['role'], 'proposal', $nb_school);
$pending_counts['approvals_proposal'] = fp_approvals_badge($pdo, $_SESSION['role'], 'proposal', $nb_school);
$pending_counts['approvals_final']    = fp_approvals_badge($pdo, $_SESSION['role'], 'final', $nb_school);

require_once __DIR__ . '/nav_badge_lib.php';
require_once __DIR__ . '/plag_pipeline_lib.php';   // pp_badge_counts() — the three Plagiarism step badges
$pp_step_n = pp_badge_counts($pdo, (string)$_SESSION['role'], (int)$_SESSION['user_id']);
$plag_counts = rd_plag_badge_counts($pdo);
$pending_counts['plag_pending'] = $plag_counts['review'];


// Backward-compatible alias (proposal-only) still referenced elsewhere in the dashboard.
$pending_uploads_count = (int) ($pending_counts['proposal_pending'] ?? 0);

// Fetch Calendar Events
$calendar_events = $pdo->query("SELECT * FROM calendar_events ORDER BY event_date ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
    <link rel="stylesheet" href="../assets/css/staff_home.css">
    <script src="../assets/js/portal.js"></script>
</head>

<body class="sh-shell">
    <div class="app-dashboard-frame">
        <?php
        // The staff home (2026-09-29), same as director.php and statistician.php: the rail, Home
        // and one hub per phase. Counts use nav_badge_counts.php's keys so the page and the 20s
        // refresh agree. Her Schedule cards read 0 by design — the Secretary logs, she confirms.
        $sh_counts = [
            'approvals_proposal' => (int)$pending_counts['approvals_proposal'],
            'approvals_final'    => (int)$pending_counts['approvals_final'],
            'schedule_proposal'  => (int)$pending_counts['schedule_pending_proposal'],
            'schedule'           => (int)$pending_counts['schedule_pending'],
            'proposal'           => (int)($pending_counts['proposal_pending'] ?? 0),
            'final'              => (int)($pending_counts['final_pending'] ?? 0),
            'stats'              => (int)($pending_counts['stats_pending'] ?? 0),
            'plag'               => (int)$plag_counts['review'],
            'plag_release'       => (int)$plag_counts['release'],
            'plag_pay'           => (int)$plag_counts['payment'],
            'plag_cleared'       => (int)$plag_counts['verdict'],
            'plag_manu'          => $pp_step_n['manuscript'],   // plag_pipeline_lib.php
            'plag_log'           => $pp_step_n['payment'],
            'plag_check'         => $pp_step_n['result'],
        ];
        // One coordinator per institution — say which one is signed in.
        require_once __DIR__ . '/admin_accounts_lib.php';
        $sh_role_label = 'Research Coordinator' . (!empty($currentUser['department']) ? ' · ' . aa_school_tag($currentUser['department']) : '');
        ?>
        <script>window.SH_SHELL_VIEWS = ['settingsDashboard'];</script>
        <?php include __DIR__ . '/_staff_home.php'; ?>

            <!-- PANEL SETTINGS DASHBOARD -->
            <?php // SETTINGS — shared by all three shells (2026-09-29): _staff_settings.php ?>
            <?php include __DIR__ . '/_staff_settings.php'; ?>

            <!-- CALENDAR DASHBOARD -->
            <?php // CALENDAR moved to office_calendar.php (a module window, 2026-09-29). ?>
        </main>

        <!-- macOS window layer (modules open here as windows) -->
        <div class="window-layer" id="windowLayer"></div>
    </div>

    <script>
        lucide.createIcons();

        // Standardized dynamic clock updater (without timezone text to give breath)
        function updateClock() {
            const clockEl = document.getElementById('coordClock');
            if (clockEl) {
                const now = new Date();
                const phtime = now.toLocaleString("en-US", {
                    timeZone: "Asia/Manila",
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                clockEl.textContent = phtime;
            }
            const settingsClock = document.getElementById('directorTimeClockSettings');
            if (settingsClock) {
                const now = new Date();
                const phtime = now.toLocaleString("en-US", {
                    timeZone: "Asia/Manila",
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                settingsClock.textContent = phtime;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Badges and view switching live in _staff_home.php now (one fetch paints the rail, the
        // cards and the hero). These names stay because other code still calls them.
        function hideAllDashboards() { if (window.PortalWindows) PortalWindows.collapseAll(); }
        function showMasterDashboard() { shShow('home'); }
        function showSettingsDashboard() { shShow('settingsDashboard'); }
        function showCalendarDashboard() { openOverlay('office_calendar.php', { dataset: { winTitle: 'Office calendar', winIcon: 'calendar-days' } }); }
        // Open a module as a macOS-style window (state preserved when minimized).
        function openOverlay(url, btn) {
            const title = (btn && btn.dataset.winTitle) || 'Module';
            const icon = (btn && btn.dataset.winIcon) || 'app-window';
            PortalWindows.open(url, url, title, icon, btn);
        }

        function selectAvatarPreset(url, el) {
            document.getElementById('pfp_selector').value = url;
            document.querySelectorAll('.avatar-preset-option').forEach(img => img.style.borderColor = 'var(--border-line)');
            el.style.borderColor = 'var(--mcnp-teal)';
        }

        // Theme is now applied + persisted by the shared controller (portal.js) under the
        // unified 'rd-portal-theme' key. It also seeds #user_theme_select's value on load.
        const themeSelectorEl = document.getElementById('user_theme_select');
        if (themeSelectorEl) {
            themeSelectorEl.addEventListener('change', (e) => setPortalTheme(e.target.value));
        }
    </script>
</body>

</html>
