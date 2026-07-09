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
    WHERE up.verification_status = 'Pending' AND up.item_id IN (30,31,32,33,34,35)
")->fetchColumn();

// form_stat_treatment isn't versioned like uploads, so no dedup subquery is needed here.
$stats_payment_pending = $pdo->query("
    SELECT COUNT(*) FROM form_stat_treatment
    WHERE status IN ('Phase 2: Form Download', 'Phase 4: Payment Verification')
")->fetchColumn();

$stats_release_pending = $pdo->query("
    SELECT COUNT(*) FROM form_stat_treatment WHERE status = 'Phase 7: Statistical Treatment'
")->fetchColumn();

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistician Dashboard</title>
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
                    <button class="dock-btn nav-item-btn" data-win-title="Statistics Clearance" data-win-icon="sigma" onclick="openOverlay('admin_module_dynamic.php?phase=stats&view=checklist', this)">
                        <i data-lucide="sigma"></i>
                        <?= $stats_checklist_pending > 0 ? '<span class="dock-badge">' . $stats_checklist_pending . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Statistics Clearance</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Payment Verification" data-win-icon="receipt" onclick="openOverlay('admin_module_dynamic.php?phase=stats&view=payments', this)">
                        <i data-lucide="receipt"></i>
                        <?= $stats_payment_pending > 0 ? '<span class="dock-badge">' . $stats_payment_pending . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Payment Verification</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Release Results" data-win-icon="send" onclick="openOverlay('admin_module_dynamic.php?phase=stats&view=release', this)">
                        <i data-lucide="send"></i>
                        <?= $stats_release_pending > 0 ? '<span class="dock-badge">' . $stats_release_pending . '</span>' : '' ?>
                    </button>
                    <span class="dock-tooltip">Release Results</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Messages" data-win-icon="message-circle" onclick="openOverlay('message.php', this)"><i data-lucide="message-circle"></i></button>
                    <span class="dock-tooltip">Messages</span>
                </li>
                <li class="dock-item">
                    <button class="dock-btn nav-item-btn" data-win-title="Analytics" data-win-icon="bar-chart-3" onclick="openOverlay('analytics.php', this)"><i data-lucide="bar-chart-3"></i></button>
                    <span class="dock-tooltip">Analytics</span>
                </li>
                <li class="dock-divider" aria-hidden="true"></li>
                <li class="dock-item">
                    <button class="dock-avatar-btn" onclick="toggleDockMenu('dockAvatarMenu', event)" aria-label="Account menu">
                        <div class="dock-avatar-ring"><img src="<?= htmlspecialchars($current_pfp) ?>" class="dock-avatar-img" onerror="this.onerror=null; this.src='https://api.dicebear.com/9.x/avataaars/svg?seed=<?= urlencode($currentUser['username']) ?>';"></div>
                    </button>
                    <div class="dock-dropdown" id="dockAvatarMenu" onclick="event.stopPropagation()">
                        <div class="dock-dropdown-head">
                            <div class="dock-dropdown-name"><?= htmlspecialchars($currentUser['username']) ?></div>
                            <div class="dock-dropdown-role">Statistician</div>
                        </div>
                        <a onclick="showSettingsDashboard(document.getElementById('settings-nav-btn')); toggleDockMenu('dockAvatarMenu')"><i data-lucide="settings-2"></i> Panel Settings</a>
                        <a href="../auth/logout.php" class="danger"><i data-lucide="log-out"></i> Log out</a>
                    </div>
                </li>
                <li class="dock-item">
                    <button class="dock-btn dock-logout" onclick="window.location.href='../auth/logout.php'"><i data-lucide="log-out"></i></button>
                    <span class="dock-tooltip">Log out</span>
                </li>
            </ul>
        </nav>

        <!-- Hidden settings button retained so showSettingsDashboard() keeps its JS reference -->
        <button class="nav-item-btn" id="settings-nav-btn" onclick="showSettingsDashboard(this)" style="display: none;"></button>

        <main class="main-workspace-content">
            <?php include __DIR__ . "/_master_overview.php"; ?>


            <!-- PANEL SETTINGS DASHBOARD -->
            <div class="container" id="settingsDashboard" style="display: none;">
                <div class="header">
                    <div class="header-title">
                        <h1>Panel Settings</h1>
                        <p>Customize your administrative profile, credentials, and appearance.</p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; max-width: 900px; margin: 0 auto;">
                    <div class="section" style="margin-bottom: 0; background: var(--bg-white, #ffffff); padding: 24px; border-radius: var(--card-radius); border: 1.5px solid var(--border-line);">
                        <h3 style="margin-bottom: 18px; font-family:'Cinzel', serif; color: var(--mcnp-teal); border-bottom: 1.5px solid var(--border-line); padding-bottom: 10px; font-size: 15px;">Profile Information</h3>

                        <form method="POST" id="profileSettingsForm">
                            <input type="hidden" name="action_type" value="update_profile">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                            <div style="margin-bottom: 18px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:6px;">Username Signature</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($currentUser['username']) ?>" required style="width:100%; padding:9px 12px; border-radius:var(--control-radius); border:1.5px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                            </div>

                            <div style="margin-bottom: 18px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:6px;">Profile Avatar</label>
                                <?php
                                    $avatar_presets = ["Adonis", "Buster", "Luna", "Zoey", "Chloe", "Rocky"];
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
                                        <img src="<?= $purl ?>" onclick="selectAvatarPreset('<?= $purl ?>', this)" class="avatar-preset-option" style="width:34px; height:34px; border-radius:50%; cursor:pointer; background:#f3f4f6; border:2px solid <?= $active ? 'var(--mcnp-teal)' : 'var(--border-line)' ?>; transition:all 0.2s;">
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div style="margin-bottom: 22px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:8px;">Appearance</label>
                                <div class="seg-toggle">
                                    <button type="button" data-theme="theme-light" onclick="setPortalTheme('theme-light')"><i data-lucide="sun"></i> Light</button>
                                    <button type="button" data-theme="theme-dark" onclick="setPortalTheme('theme-dark')"><i data-lucide="moon"></i> Dark</button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="padding:10px 24px; border-radius:var(--control-radius); font-weight:700; font-size:13px;">
                                <i data-lucide="save"></i> Save
                            </button>
                        </form>
                    </div>

                    <div class="section" style="margin-bottom: 0; background: var(--bg-white, #ffffff); padding: 24px; border-radius: var(--card-radius); border: 1.5px solid var(--border-line);">
                        <h3 style="margin-bottom: 18px; font-family:'Cinzel', serif; color: var(--mcnp-teal); border-bottom: 1.5px solid var(--border-line); padding-bottom: 10px; font-size: 15px;">Change Password</h3>

                        <form method="POST" id="passwordSettingsForm">
                            <input type="hidden" name="action_type" value="update_password">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                            <div style="margin-bottom: 15px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:6px;">Current Password</label>
                                <input type="password" name="current_password" style="width:100%; padding:9px 12px; border-radius:var(--control-radius); border:1.5px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:6px;">New Password</label>
                                <input type="password" name="new_password" style="width:100%; padding:9px 12px; border-radius:var(--control-radius); border:1.5px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                            </div>

                            <div style="margin-bottom: 22px;">
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:6px;">Confirm New Password</label>
                                <input type="password" name="confirm_password" style="width:100%; padding:9px 12px; border-radius:var(--control-radius); border:1.5px solid var(--border-line); font-size:13px; background:transparent; color:inherit;">
                            </div>

                            <button type="submit" class="btn btn-primary" style="padding:10px 24px; border-radius:var(--control-radius); font-weight:700; font-size:13px;">
                                <i data-lucide="save"></i> Save
                            </button>
                        </form>
                    </div>
                </div>
            </div>
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

        // Complete workspace-switcher layout handlers
        function hideAllDashboards() {
            document.getElementById('masterDashboard').style.display = 'none';
            document.getElementById('settingsDashboard').style.display = 'none';
            PortalWindows.collapseAll();
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
    </script>
</body>

</html>