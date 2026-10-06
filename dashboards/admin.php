<?php
// dashboards/admin.php — the ADMIN shell (2026-09-30).
//
// The system administrator the evaluators asked for. Same shell as the Director, Coordinator and
// Statistician (_staff_home.php + staff_home_lib.php, sh_model('Admin')): Home · Accounts ·
// Research · Insights · Workspace.
//
//   Accounts  — approve the staff accounts the Director or a Coordinator create (they start
//               `pending`), and maintain every account, staff and student.
//   Research  — the office's workflow screens, VIEW ONLY. config/db.php refuses any POST from
//               this role outside the account handlers and her own settings.
//
// This file handles no POST of its own: Settings saves go to staff_settings_handler.php.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . '/admin_accounts_lib.php';

$uid = (int)$_SESSION['user_id'];
// The view-only guard in config/db.php leaves its refusal here when a form was submitted.
$message = '';
foreach (['flash_message', 'message'] as $k) {
    if (!empty($_SESSION[$k])) { $message = (string)$_SESSION[$k]; }
    unset($_SESSION[$k]);
}
unset($_SESSION['message_type']);

$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = !empty($currentUser['profile_pic']) ? $currentUser['profile_pic']
             : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | MCNP-ISAP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
    <link rel="stylesheet" href="../assets/css/staff_home.css">
    <script src="../assets/js/portal.js"></script>
</head>
<body class="sh-shell">
    <div class="app-dashboard-frame">
        <?php
        // Her counts: accounts to approve, help requests, lockouts. Same keys nav_badge_counts.php returns.
        $sh_counts       = rd_admin_counts($pdo);   // acct_pending, help_open, locked_now (config/system.php)
        $sh_role_label   = 'Administrator';
        $sh_has_calendar = false;   // the office calendar is the office's to edit, not hers
        ?>
        <script>window.SH_SHELL_VIEWS = ['settingsDashboard'];</script>
        <?php include __DIR__ . '/_staff_home.php'; ?>

            <?php include __DIR__ . '/_staff_settings.php'; ?>
        </main>

        <div class="window-layer" id="windowLayer"></div>
    </div>

    <script>
        lucide.createIcons();

        // The same names the other three shells define; _staff_home.php and portal.js call them.
        function hideAllDashboards() { if (window.PortalWindows) PortalWindows.collapseAll(); }
        function showMasterDashboard() { shShow('home'); }
        function showSettingsDashboard() { shShow('settingsDashboard'); }
        function openOverlay(url, btn) {
            const title = (btn && btn.dataset.winTitle) || 'Module';
            const icon = (btn && btn.dataset.winIcon) || 'app-window';
            PortalWindows.open(url, url, title, icon, btn);
        }
        function setSettingTheme(t) { setPortalTheme(t); }
    </script>
</body>
</html>
