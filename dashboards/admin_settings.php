<?php
// dashboards/admin_settings.php — System settings (2026-10-01). ADMIN ONLY.
//
// The parameters the office used to need a programmer for: the announcement banner every user
// sees, maintenance mode, whether students may register, defense slots per institution per day,
// and the sign-in lockout. One form, saved by admin_system_handler.php into system_settings.
// (Not the Admin's own profile — that is Settings in the Workspace.)

require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$v = fn($k, $d = '') => (string)rd_setting($k, $d);
$last = $pdo->query("SELECT DATE_FORMAT(s.updated_at, '%b %e, %Y') AS on_day, u.username FROM system_settings s LEFT JOIN users u ON u.user_id = s.updated_by
                     WHERE s.updated_by IS NOT NULL ORDER BY s.updated_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$flash = $_SESSION['message'] ?? ''; $flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .st { --disp: "Outfit", var(--font-ui); padding: 22px 26px 110px; max-width: 980px; margin: 0 auto; }
        .st-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .st-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .st-sub { font-size: 13px; color: var(--muted); margin: 0 0 18px; line-height: 1.5; }
        .st-flash { padding: 12px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600; display: flex; gap: 8px; align-items: center;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .st-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .st-flash i, .st-flash svg { width: 16px; height: 16px; flex-shrink: 0; }
        .st-card { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 20px 22px; margin-bottom: 14px; }
        .st-card h2 { display: flex; align-items: center; gap: 9px; font-family: var(--disp); font-size: 18px; font-weight: 700; color: var(--ink); margin: 0 0 4px; }
        .st-card h2 i, .st-card h2 svg { width: 18px; height: 18px; color: var(--accent); }
        .st-card > p { font-size: 12.5px; color: var(--muted); margin: 0 0 14px; line-height: 1.5; }
        .st-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; border-top: 1px solid var(--line); }
        .st-row:first-of-type { border-top: 0; }
        .st-row b { display: block; font-size: 13.5px; color: var(--ink); }
        .st-row small { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; line-height: 1.4; }
        .st-sw { position: relative; width: 46px; height: 26px; flex-shrink: 0; }
        .st-sw input { opacity: 0; width: 0; height: 0; position: absolute; }
        .st-sw span { position: absolute; inset: 0; border-radius: 999px; background: var(--surface-3); border: 1px solid var(--line); cursor: pointer; transition: .15s; }
        .st-sw span::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: .15s; }
        .st-sw input:checked + span { background: var(--accent); border-color: var(--accent); }
        .st-sw input:checked + span::after { left: 23px; }
        .st-sw.danger input:checked + span { background: var(--danger); border-color: var(--danger); }
        .st-num { width: 92px; box-sizing: border-box; padding: 9px 12px; border-radius: 12px; border: 1.5px solid transparent; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 14px; font-weight: 700; text-align: center; outline: none; }
        .st-num:focus, .st-text:focus { border-color: var(--accent); background: var(--surface); }
        .st-text { width: 100%; box-sizing: border-box; padding: 11px 13px; border-radius: 14px; border: 1.5px solid transparent; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13.5px; min-height: 80px; resize: vertical; outline: none; line-height: 1.5; }
        .st-levels { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface-3); margin-top: 10px; }
        .st-levels label { cursor: pointer; padding: 6px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--ink); }
        .st-levels input { display: none; }
        .st-levels label:has(input:checked) { background: var(--surface); box-shadow: var(--shadow-1); }
        .st-preview { margin-top: 12px; }
        .st-bar { position: fixed; left: 0; right: 0; bottom: 0; padding: 14px 26px; background: color-mix(in srgb, var(--surface) 92%, transparent); backdrop-filter: blur(8px);
                  border-top: 1px solid var(--line); display: flex; justify-content: center; }
        .st-bar div { width: 100%; max-width: 980px; display: flex; align-items: center; gap: 12px; }
        .st-bar small { color: var(--muted); font-size: 12px; }
        .st-save { all: unset; cursor: pointer; margin-left: auto; padding: 11px 22px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 13.5px; font-weight: 750; }
        @media (max-width: 640px) { .st { padding: 16px 12px 110px; } .st-row { align-items: flex-start; } }
    </style>
</head>
<body class="admin-module">
<form class="st" method="POST" action="admin_system_handler.php">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="st-eyebrow">System</div>
    <h1 class="st-title">System settings</h1>
    <p class="st-sub">Parameters for the whole system. Changes apply the moment you save.</p>

    <?php if ($flash): ?>
        <div class="st-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= $h($flash) ?></div>
    <?php endif; ?>

    <section class="st-card">
        <h2><i data-lucide="megaphone"></i>Announcement</h2>
        <p>A banner shown at the top of every portal and on the login page. People can close it; a new message shows again.</p>
        <textarea class="st-text" name="announcement_text" maxlength="400" placeholder="e.g. Final defense requirements are due on Friday, October 10."><?= $h($v('announcement_text')) ?></textarea>
        <div class="st-row" style="border-top:0;padding-bottom:0">
            <div class="st-levels">
                <?php foreach (['info' => 'Info', 'warning' => 'Important', 'success' => 'Good news'] as $k => $lab): ?>
                    <label><input type="radio" name="announcement_level" value="<?= $k ?>" <?= $v('announcement_level', 'info') === $k ? 'checked' : '' ?>><?= $lab ?></label>
                <?php endforeach; ?>
            </div>
            <label class="st-sw" title="Show the announcement"><input type="checkbox" name="announcement_active" value="1" <?= $v('announcement_active') === '1' ? 'checked' : '' ?>><span></span></label>
        </div>
    </section>

    <section class="st-card">
        <h2><i data-lucide="door-open"></i>Access</h2>
        <p>Who can get in.</p>
        <div class="st-row">
            <div><b>Maintenance mode</b><small>Signs everyone out and lets only the Admin sign in. Use it while fixing something.</small></div>
            <label class="st-sw danger"><input type="checkbox" name="maintenance_mode" value="1" <?= $v('maintenance_mode') === '1' ? 'checked' : '' ?>
                   onchange="if (this.checked && !confirm('Sign everyone except the Admin out of the system?')) this.checked = false"><span></span></label>
        </div>
        <div class="st-row">
            <div><b>Student registration open</b><small>When off, the sign-up page tells students registration is closed.</small></div>
            <label class="st-sw"><input type="checkbox" name="registration_open" value="1" <?= $v('registration_open', '1') === '1' ? 'checked' : '' ?>><span></span></label>
        </div>
    </section>

    <section class="st-card">
        <h2><i data-lucide="calendar-days"></i>Defense scheduling</h2>
        <p>Each institution runs its own panels, so each gets its own slots on a day.</p>
        <div class="st-row">
            <div><b>Slots per institution per day</b><small>Shared by proposal and final defenses. Slots already given out keep their number.</small></div>
            <input class="st-num" type="number" name="slots_per_day" min="1" max="50" required value="<?= $h($v('slots_per_day', '10')) ?>">
        </div>
    </section>

    <section class="st-card">
        <h2><i data-lucide="shield-alert"></i>Sign-in security</h2>
        <p>Too many wrong passwords locks that email for a while. You can unlock anyone early from the Access log.</p>
        <div class="st-row">
            <div><b>Wrong passwords before a lockout</b><small>From 3 to 20.</small></div>
            <input class="st-num" type="number" name="lockout_attempts" min="3" max="20" required value="<?= $h($v('lockout_attempts', '5')) ?>">
        </div>
        <div class="st-row">
            <div><b>Lockout length, in minutes</b><small>From 1 to 1440 (a day).</small></div>
            <input class="st-num" type="number" name="lockout_minutes" min="1" max="1440" required value="<?= $h($v('lockout_minutes', '15')) ?>">
        </div>
    </section>

    <div class="st-bar"><div>
        <small><?= $last ? 'Last changed by ' . $h($last['username']) . ' on ' . $h($last['on_day']) : 'Never changed' ?></small>
        <button type="submit" class="st-save">Save settings</button>
    </div></div>
</form>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
