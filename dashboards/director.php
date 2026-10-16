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
    header("Location: director.php");
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
    header("Location: director.php");
    exit();
}

// Fetch active profile details
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = (!empty($currentUser['profile_pic'])) ? $currentUser['profile_pic'] : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

// Fetch pending counts for the sidebar badges. Director outranks Coordinator and wants full
// pipeline visibility the moment a student submits — not just the subset waiting on Director's own
// action — so these badges count 'Pending' (with Coordinator) + 'Under Review' (with Director)
// combined per phase, in sync with what's actually happening system-wide. Count only the LATEST
// upload per group per item (mirrors the admin module's $uploads_by_item logic) so superseded
// versions don't inflate the badges.
// Stats range includes 36/37 (payment docs): Director has one combined Statistics badge (no
// separate Payment badge like statistician.php has), so it must cover everything phase=stats
// shows in its default 'all' view, or pending payment docs would never surface anywhere.
$pending_counts = $pdo->query("
    SELECT
        SUM(CASE WHEN up.item_id IN (11,12,13,14,15,16,17) THEN 1 ELSE 0 END) as proposal_pending,
        SUM(CASE WHEN up.item_id IN (20,22,23,24,25,26,27) THEN 1 ELSE 0 END) as final_pending,
        SUM(CASE WHEN up.item_id IN (30,31,32,34,35) THEN 1 ELSE 0 END) as stats_pending
    FROM uploads up
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) AS max_date
        FROM uploads GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    WHERE up.verification_status IN ('Pending', 'Under Review')
")->fetch();

// Manuscript has four desks (Release / Review / Payment / Cleared), each with its own queue and
// its own badge. Sourced from plagiarism_checks.status — see nav_badge_lib.php for why.
// Schedule bundles waiting on THIS role — Coordinator holds step 2, Director also step 3.
require_once __DIR__ . '/focal_lib.php';
$nb_school = fp_school_scope($pdo, $_SESSION['role'], (int)$_SESSION['user_id']);
// Schedule badge = sections the Coordinator has CONFIRMED that this viewer can log. It used to
// count 'Date Selected' bookings, which light up on the Focal Person's endorse — while the
// section is still the Coordinator's move. See fp_schedule_badge().
$pending_counts['schedule_pending']          = fp_schedule_badge($pdo, $_SESSION['role'], 'final', $nb_school);
$pending_counts['schedule_pending_proposal'] = fp_schedule_badge($pdo, $_SESSION['role'], 'proposal', $nb_school);
$pending_counts['approvals_proposal'] = fp_approvals_badge($pdo, $_SESSION['role'], 'proposal', $nb_school);
$pending_counts['approvals_final']    = fp_approvals_badge($pdo, $_SESSION['role'], 'final', $nb_school);

require_once __DIR__ . '/nav_badge_lib.php';
require_once __DIR__ . '/plag_pipeline_lib.php';   // pp_badge_counts() — the three Plagiarism step badges
$pp_step_n = pp_badge_counts($pdo, (string)$_SESSION['role'], (int)$_SESSION['user_id']);
$plag_counts = rd_plag_badge_counts($pdo);
$pending_counts['plag_pending'] = $plag_counts['review'];

// The Secretary's Statistics step (2026-10-16): numbers to issue + payments to confirm. Same rows
// as the Progress screen's 'Your move' band, so the rail and the page cannot disagree.
require_once __DIR__ . '/stats_pipeline_lib.php';
$pending_counts['pipeline'] = sp_counts(sp_rows($pdo, '1', [], 'Research Director'))['you'];


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard | MCNP-ISAP</title>
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
<body class="sh-shell sh-soft">   <?php /* sh-soft: the rail with a softened edge, ON TRIAL here only (2026-10-02, staff_home.css) */ ?>
    <div class="app-dashboard-frame">
        <?php
        // The staff home (2026-09-29): a seven-item rail, Home, and one hub per destination.
        // Counts use nav_badge_counts.php's keys so the page and the 20s refresh agree.
        $sh_counts = [
            'approvals_proposal' => (int)$pending_counts['approvals_proposal'],
            'approvals_final'    => (int)$pending_counts['approvals_final'],
            'schedule_proposal'  => (int)$pending_counts['schedule_pending_proposal'],
            'schedule'           => (int)$pending_counts['schedule_pending'],
            'proposal'           => (int)($pending_counts['proposal_pending'] ?? 0),
            'final'              => (int)($pending_counts['final_pending'] ?? 0),
            'stats'              => (int)($pending_counts['stats_pending'] ?? 0),
            'pipeline'           => (int)$pending_counts['pipeline'],
            'plag'               => (int)$plag_counts['review'],
            'plag_release'       => (int)$plag_counts['release'],
            'plag_pay'           => (int)$plag_counts['payment'],
            'plag_cleared'       => (int)$plag_counts['verdict'],
            'plag_manu'          => $pp_step_n['manuscript'],   // plag_pipeline_lib.php
            'plag_log'           => $pp_step_n['payment'],
            'plag_check'         => $pp_step_n['result'],
        ];
        // Lea (Secretary) and Ryan (Director) share the role; say which one is signed in.
        $sh_role_label = (stripos($currentUser['email'] ?? '', 'secretary') !== false) ? 'Secretary' : 'Research Director';
        ?>
        <script>window.SH_SHELL_VIEWS = ['settingsDashboard'];</script>
        <?php include __DIR__ . '/_staff_home.php'; ?>

            <!-- SETTINGS DASHBOARD -->
            <?php // SETTINGS — shared by all three shells (2026-09-29): _staff_settings.php ?>
            <?php include __DIR__ . '/_staff_settings.php'; ?>

            <!-- CALENDAR DASHBOARD -->
            <?php // CALENDAR moved to office_calendar.php (a module window, 2026-09-29). ?>
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

        // Badges and view switching live in _staff_home.php now (one fetch paints the rail, the
        // cards and the hero). These names stay because other code still calls them.
        function hideAllDashboards() { if (window.PortalWindows) PortalWindows.collapseAll(); }
        function showMasterDashboard() { shShow('home'); }
        function showSettingsDashboard() { shShow('settingsDashboard'); }
        function showCalendarDashboard() { openOverlay('office_calendar.php', { dataset: { winTitle: 'Office calendar', winIcon: 'calendar-days' } }); }
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
