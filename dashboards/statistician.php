<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce Session Access Rule
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Statistician') {
    header("Location: ../auth/login.php");
    exit();
}

$uid = $_SESSION['user_id'];
// Institution scope (2026-09-26): a Statistician covers one institution or both
// (AA_ALL_INSTITUTIONS). Her dock badges must agree with what her Checklist actually shows
// her, so they run through aa_scope_sql() like every other scoped listing.
require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/stats_pipeline_lib.php';   // sp_rows() — the one-ladder view
$role = $_SESSION['role'];
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
    header("Location: statistician.php");
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
    header("Location: statistician.php");
    exit();
}

// Fetch active profile details
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = (!empty($currentUser['profile_pic'])) ? $currentUser['profile_pic'] : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

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

// Badge/stat counts for the 3 Statistics nav tabs. Deduped to the latest upload per user/item
// (mirrors coordinator.php's $pending_counts pattern) so superseded re-uploads don't inflate counts.
$stats_checklist_pending = $pdo->query("
    SELECT COUNT(*) FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    WHERE up.verification_status = 'Pending' AND up.item_id IN (30,31,32,34,35)
")->fetchColumn();

// form_stat_treatment isn't versioned like uploads, so no dedup subquery is needed here.
[$sb_sql, $sb_params] = aa_scope_sql($pdo, $role, (int)$uid, 'u');

// Only what the desk can ACT on — the same definition the Ready lane uses in
// admin_module_dynamic.php. Phase 2 (form downloaded, nothing paid) is the student's move and
// must not inflate this: the badge used to say 4 while only 2 groups had submitted anything.
$sb_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM form_stat_treatment f
    JOIN users u ON u.user_id = f.user_id
    WHERE f.status = 'Phase 4: Payment Verification'
      AND EXISTS (SELECT 1 FROM uploads up WHERE up.user_id = f.user_id AND up.item_id = 37)
      AND $sb_sql
");
$sb_stmt->execute($sb_params);
$stats_payment_pending = $sb_stmt->fetchColumn();

$sr_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM form_stat_treatment f JOIN users u ON u.user_id = f.user_id
    WHERE f.status = 'Phase 7: Statistical Treatment' AND $sb_sql
");
$sr_stmt->execute($sb_params);
$stats_release_pending = $sr_stmt->fetchColumn();

// The Pipeline's badge is its own 'Your move' band — counted from the rows it renders, not
// a parallel query, so the number on the nav and the number on the screen are one thing.
$sp_you = sp_counts(sp_rows($pdo, $sb_sql, $sb_params, 'Statistician'))['you'];

// Manuscript's four desks. Statistician has full parity with Coordinator/Director on phase=plag,
// so it shows the same four badges. Counted off the phase ladder — see nav_badge_lib.php.
require_once __DIR__ . '/nav_badge_lib.php';
require_once __DIR__ . '/plag_pipeline_lib.php';   // pp_badge_counts() — the three Plagiarism step badges
$pp_step_n = pp_badge_counts($pdo, (string)$_SESSION['role'], (int)$_SESSION['user_id']);
$plag_counts = rd_plag_badge_counts($pdo);
$plag_pending_count = $plag_counts['review'];

// Backward-compat alias: the "Pending Data Validations" stat card (and the Master Dashboard
// partial's Statistician branch) reads this same corrected value.
$pending_uploads_count = (int) $stats_checklist_pending;

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
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistician Dashboard</title>
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
        // The staff home (2026-09-29), the Statistician's rail: Home · Statistics · Plagiarism ·
        // Workspace. Counts use nav_badge_counts.php's keys so the page and the 20s refresh agree.
        $sh_counts = [
            'pipeline'     => (int)$sp_you,
            'checklist'    => (int)$stats_checklist_pending,
            'plag'         => (int)$plag_counts['review'],
            'plag_release' => (int)$plag_counts['release'],
            'plag_pay'     => (int)$plag_counts['payment'],
            'plag_cleared' => (int)$plag_counts['verdict'],
            'plag_manu'          => $pp_step_n['manuscript'],   // plag_pipeline_lib.php
            'plag_log'           => $pp_step_n['payment'],
            'plag_check'         => $pp_step_n['result'],
        ];
        $sh_role_label   = 'Statistician';
        $sh_has_calendar = false;   // no Calendar view in this shell
        ?>
        <script>window.SH_SHELL_VIEWS = ['settingsDashboard'];</script>
        <?php include __DIR__ . '/_staff_home.php'; ?>


            <!-- PANEL SETTINGS DASHBOARD -->
            <?php // SETTINGS — shared by all three shells (2026-09-29): _staff_settings.php ?>
            <?php include __DIR__ . '/_staff_settings.php'; ?>
        </main>

        <!-- macOS window layer (modules open here as windows) -->
        <div class="window-layer" id="windowLayer"></div>
    </div>

    <script>
        lucide.createIcons();

        // Standardized dynamic clock updater (without timezone text to give breath)
        function updateClock() {
            const clockEl = document.getElementById('statsClock');
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

        // Badges and view switching live in _staff_home.php now; these names stay because other
        // code still calls them.
        function hideAllDashboards() { if (window.PortalWindows) PortalWindows.collapseAll(); }
        function showMasterDashboard() { shShow('home'); }
        function showSettingsDashboard() { shShow('settingsDashboard'); }

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

        // Theme is applied + persisted by the shared controller (portal.js) under the unified
        // 'rd-portal-theme' key, which also seeds the select and posts to the iframe overlay.
        const themeSelectorEl = document.getElementById('user_theme_select');
        if (themeSelectorEl) {
            themeSelectorEl.addEventListener('change', (e) => setQuickTheme(e.target.value));
        }

        function setQuickTheme(themeName) {
            setPortalTheme(themeName);
        }

    </script>
</body>

</html>