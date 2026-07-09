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
    WHERE up.verification_status = 'Pending'
")->fetch();

// Backward-compatible alias (proposal-only) still referenced elsewhere in the dashboard.
$pending_uploads_count = (int) ($pending_counts['proposal_pending'] ?? 0);

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
                            <div class="dock-dropdown-role">Coordinator</div>
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
            } catch (e) {
                console.error("Error loading group", e);
            }
        }

        // Complete workspace-switcher layout handlers
        function hideAllDashboards() {
            document.getElementById('masterDashboard').style.display = 'none';
            document.getElementById('settingsDashboard').style.display = 'none';
            document.getElementById('calendarDashboard').style.display = 'none';
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

        function showCalendarDashboard(btn) {
            document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hideAllDashboards();
            document.getElementById('calendarDashboard').style.display = 'block';
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

        // Theme is now applied + persisted by the shared controller (portal.js) under the
        // unified 'rd-portal-theme' key. It also seeds #user_theme_select's value on load.
        const themeSelectorEl = document.getElementById('user_theme_select');
        if (themeSelectorEl) {
            themeSelectorEl.addEventListener('change', (e) => setPortalTheme(e.target.value));
        }
    </script>
</body>

</html>
