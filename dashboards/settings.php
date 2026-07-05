<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'deactivate_account') {
        $confirmation = trim($_POST['deactivate_confirm'] ?? '');
        if ($confirmation === 'DEACTIVATE') {
            $stmt_deactivate = $pdo->prepare("UPDATE users SET is_verified = 0 WHERE user_id = ?");
            if ($stmt_deactivate->execute([$user_id])) {
                session_destroy();
                header("Location: ../auth/login.php?msg=" . urlencode("Your account has been safely suspended. Please contact support if you wish to return."));
                exit();
            } else {
                $message = "Something went wrong. Please try again.";
                $message_type = "error";
            }
        } else {
            $message = "You must type 'DEACTIVATE' exactly to proceed.";
            $message_type = "error";
        }
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $db_pass = $stmt->fetchColumn();

        if (password_verify($current_pass, $db_pass)) {
            if ($new_pass === $confirm_pass) {
                if (strlen($new_pass) >= 6) {
                    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                    $stmt_update = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    if ($stmt_update->execute([$hashed, $user_id])) {
                        $message = "Your password has been successfully secured.";
                        $message_type = "success";
                    }
                } else {
                    $message = "Your new password must be at least 6 characters long.";
                    $message_type = "error";
                }
            } else {
                $message = "The new passwords you typed do not match.";
                $message_type = "error";
            }
        } else {
            $message = "The current password you entered is incorrect.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Full Width Minimal</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --bg-white: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-line: #e2e8f0;
            --border-light: #f1f5f9;
            --bg-canvas: #f9fbfc;
            --bg-card: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-subtle: #e2e8f0;
            --mcnp-teal: #0f172a; 
        }

        body.theme-default, body.theme-blue { --bg-canvas: #f0f4f9; --bg-white: #ffffff; --text-dark: #0f172a; --mcnp-teal: #1e40af; }
        body.theme-red { --bg-canvas: #fef2f2; --bg-white: #ffffff; --text-dark: #b91c1c; --mcnp-teal: #b91c1c; }
        body.theme-green { --bg-canvas: #f0fdf4; --bg-white: #ffffff; --text-dark: #15803d; --mcnp-teal: #15803d; }
        body.theme-pink, body.theme-rose { --bg-canvas: #fdf2f8; --bg-white: #ffffff; --text-dark: #be185d; --mcnp-teal: #be185d; }
        body.theme-purple, body.theme-lavender { --bg-canvas: #f5f3ff; --bg-white: #ffffff; --text-dark: #6d28d9; --mcnp-teal: #6d28d9; }
        body.theme-orange, body.theme-amber { --bg-canvas: #fffbeb; --bg-white: #ffffff; --text-dark: #b45309; --mcnp-teal: #b45309; }
        body.theme-dark { --bg-canvas: #0f172a; --bg-card: #1e293b; --bg-white: #1e293b; --text-dark: #f8fafc; --text-primary: #f8fafc; --text-secondary: #94a3b8; --text-muted: #94a3b8; --border-line: #334155; --border-light: #0f172a; --border-subtle: #334155; --mcnp-teal: #38bdf8; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--ui-sans);
            background: transparent;
            color: var(--text-dark);
            height: 100vh;
            overflow-y: auto;
            padding: 32px 40px;
        }

        .container { 
            max-width: 1200px; /* Full width Native feeling */
            margin: 0 auto; 
        }

        /* Minimal Header */
        .page-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 30px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 20px;
        }
        .header-content h1 { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 6px; }
        .header-content p { font-size: 14px; color: var(--text-secondary); }
        
        .btn-signout {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 8px;
            font-size: 14px; font-weight: 600; color: #ef4444;
            text-decoration: none; border: 1px solid #fecaca;
            background: #fef2f2; transition: 0.2s;
        }
        .btn-signout:hover { background: #fee2e2; border-color: #fca5a5; }

        /* Horizontal Tab Menu */
        .tab-menu {
            display: flex; gap: 40px; margin-bottom: 40px;
        }
        .tab-btn {
            background: none; border: none; padding-bottom: 12px;
            font-size: 15px; font-weight: 600; color: var(--text-secondary);
            cursor: pointer; position: relative; font-family: var(--ui-sans);
        }
        .tab-btn:hover { color: var(--text-primary); }
        .tab-btn.active { color: var(--text-primary); }
        .tab-btn.active::after {
            content: ''; position: absolute; bottom: 0; left: 0; width: 100%;
            height: 3px; background: var(--mcnp-teal); border-radius: 3px 3px 0 0;
        }

        /* Panels */
        .panel { display: none; animation: fadeIn 0.3s ease-out; }
        .panel.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* Compact Layouts */
        .panel-section {
            background: var(--bg-white);
            border: 1px solid var(--border-light);
            border-radius: 16px; 
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .panel-section:hover {
            border-color: var(--border-line);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }

        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 24px;
        }
        .section-header i { color: var(--text-secondary); }
        .section-header h2 { font-size: 18px; font-weight: 700; }
        .section-desc { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }

        /* Compact Form Grid for Passwords */
        .password-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .field-group { display: flex; flex-direction: column; gap: 8px; }
        .field-group.full { grid-column: 1 / -1; }
        .field-group label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        
        .minimal-input {
            width: 100%; padding: 14px 18px;
            border: 1px solid var(--border-light);
            border-radius: 16px; background: var(--bg-white);
            font-family: var(--ui-sans); font-size: 15px;
            color: var(--text-dark); transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .minimal-input:focus {
            outline: none; border-color: var(--border-line);
            box-shadow: 0 4px 12px -4px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }

        .btn-action {
            font-family: var(--ui-sans); background: var(--text-dark); color: white;
            border: none; padding: 12px 28px; border-radius: 999px; font-weight: 600;
            cursor: pointer; font-size: 14px; transition: 0.2s; margin-top: 24px; float: right;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-action:hover { opacity: 0.9; transform: scale(1.02); }

        /* Minimal Themes Row */
        .themes-row { display: flex; gap: 16px; flex-wrap: wrap; }
        .theme-dot {
            width: 40px; height: 40px; border-radius: 50%; cursor: pointer;
            border: 2px solid transparent; transition: 0.2s;
        }
        .theme-dot:hover { transform: scale(1.1); }
        .theme-dot.active { border-color: var(--text-primary); box-shadow: 0 0 0 4px var(--bg-card); transform: scale(1.1); }
        
        .theme-dot.default { background: #4a7c8c; }
        .theme-dot.red { background: #d65a5a; }
        .theme-dot.green { background: #4a9e7b; }
        .theme-dot.pink { background: #c56ba8; }
        .theme-dot.purple { background: #8b5cf6; }
        .theme-dot.orange { background: #d97706; }
        .theme-dot.dark { background: #1e293b; }

        /* Minimal Danger Zone */
        .danger-zone {
            background: var(--bg-white);
            border: 1px solid #fecaca; 
            border-radius: 16px; padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .danger-title { color: #b91c1c; font-weight: 700; font-size: 18px; margin-bottom: 8px; display:flex; align-items:center; gap:8px;}
        .danger-text { color: var(--text-secondary); font-size: 14px; margin-bottom: 24px; line-height: 1.5; max-width: 600px;}
        
        .type-to-confirm { max-width: 400px; }
        .type-to-confirm input {
            width: 100%; padding: 14px 18px; border-radius: 16px; border: 1px solid #fca5a5;
            background: #fff5f5; font-family: monospace; font-size: 14px;
            margin-bottom: 16px; color: #b91c1c; transition: 0.2s;
        }
        .type-to-confirm input:focus { outline: none; border-color: #ef4444; box-shadow: 0 4px 12px -4px rgba(239, 68, 68, 0.2); transform: translateY(-1px); }
        
        .btn-danger {
            background: #ef4444; color: white; border: none; padding: 12px 28px;
            border-radius: 999px; font-weight: 600; cursor: not-allowed; opacity: 0.5; transition: 0.2s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08); font-family: var(--ui-sans);
        }
        .btn-danger.unlocked { opacity: 1; cursor: pointer; }
        .btn-danger.unlocked:hover { background: #dc2626; transform: scale(1.02); }

        /* Toast Alert */
        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(100px);
            background: #1e293b; color: white; padding: 12px 24px; border-radius: 30px;
            font-size: 14px; font-weight: 500; opacity: 0; transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 1000; display: flex; align-items: center; gap: 8px;
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        @media (max-width: 768px) {
        }

        @media (max-width: 640px) {
            body { padding: 16px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; }
            .header-content h1 { font-size: 26px; }
            .btn-signout { align-self: stretch; justify-content: center; }
            .tab-menu { overflow-x: auto; padding-bottom: 4px; margin-bottom: 24px; gap: 24px; }
            
            .panel-section { padding: 20px; }
            .password-grid { grid-template-columns: 1fr; gap: 16px; }
            .section-header { margin-bottom: 16px; }
            .section-header h2 { font-size: 16px; }
            
            .btn-action { float: none; margin-top: 16px; justify-content: center; }
            
            .danger-zone { padding: 20px; }
            .danger-title { font-size: 16px; }
            .danger-text { font-size: 13px; margin-bottom: 16px; }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <div class="header-content">
                <h1>Settings</h1>
                <p>Manage your preferences and security credentials.</p>
            </div>
            <a href="../auth/logout.php" class="btn-signout" target="_parent" onclick="return confirm('Sign out of your session?')">
                <i data-lucide="log-out" style="width:16px;height:16px;"></i> Sign Out
            </a>
        </div>

        <div class="tab-menu">
            <button class="tab-btn active" onclick="switchTab('appearance', this)">Appearance</button>
            <button class="tab-btn" onclick="switchTab('security', this)">Security</button>
            <button class="tab-btn" onclick="switchTab('danger', this)" style="color: #ef4444;">Danger Zone</button>
        </div>

        <!-- Appearance Panel -->
        <div class="panel active" id="tab-appearance">
            <div class="panel-section">
                <div class="section-header">
                    <i data-lucide="palette"></i>
                    <h2>Theme Personalization</h2>
                </div>
                <p class="section-desc">Choose a color palette that matches your brand. Syncs instantly across all devices.</p>
                
                <div class="themes-row">
                    <div class="theme-dot default" onclick="setTheme('theme-default', this)" title="Default Teal"></div>
                    <div class="theme-dot red" onclick="setTheme('theme-red', this)" title="Maroon"></div>
                    <div class="theme-dot green" onclick="setTheme('theme-green', this)" title="Green"></div>
                    <div class="theme-dot pink" onclick="setTheme('theme-pink', this)" title="Pink"></div>
                    <div class="theme-dot purple" onclick="setTheme('theme-purple', this)" title="Purple"></div>
                    <div class="theme-dot orange" onclick="setTheme('theme-orange', this)" title="Orange"></div>
                    <div class="theme-dot dark" onclick="setTheme('theme-dark', this)" title="Dark Mode"></div>
                </div>
            </div>
        </div>

        <!-- Security Panel -->
        <div class="panel" id="tab-security">
            <form method="POST">
                <div class="panel-section">
                    <div class="section-header">
                        <i data-lucide="shield-check"></i>
                        <h2>Password Reset</h2>
                    </div>
                    
                    <div class="password-grid">
                        <div class="field-group full">
                            <label>Current Password</label>
                            <input type="password" class="minimal-input" name="current_password" required>
                        </div>
                        <div class="field-group">
                            <label>New Password</label>
                            <input type="password" class="minimal-input" name="new_password" required minlength="6">
                        </div>
                        <div class="field-group">
                            <label>Confirm Password</label>
                            <input type="password" class="minimal-input" name="confirm_password" required minlength="6">
                        </div>
                    </div>
                    <div style="clear:both;">
                        <button type="submit" class="btn-action">Update Password</button>
                        <div style="clear:both;"></div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Danger Zone Panel -->
        <div class="panel" id="tab-danger">
            <div class="danger-zone">
                <div class="danger-title"><i data-lucide="alert-triangle"></i> Suspend Account</div>
                <div class="danger-text">
                    Suspending your account will instantly hide your dashboard access. 
                    You cannot log back in without an administrator's approval.
                </div>

                <form method="POST" class="type-to-confirm">
                    <input type="hidden" name="action" value="deactivate_account">
                    <label style="font-size:13px; font-weight:600; color:#b91c1c; margin-bottom:8px; display:block;">Type DEACTIVATE to confirm:</label>
                    <input type="text" name="deactivate_confirm" id="dInput" autocomplete="off">
                    <br>
                    <button type="submit" class="btn-danger" id="dBtn" disabled>Suspend My Account</button>
                </form>
            </div>
        </div>
    </div>

    <div class="toast" id="toastMsg"><span></span></div>

    <script>
        lucide.createIcons();

        // Toast
        <?php if ($message && !isset($_POST['action'])): ?>
            showToast(<?= json_encode($message) ?>, <?= json_encode($message_type) ?>);
        <?php endif; ?>
        <?php if ($message && isset($_POST['action']) && $_POST['action'] === 'deactivate_account'): ?>
            showToast(<?= json_encode($message) ?>, <?= json_encode($message_type) ?>);
        <?php endif; ?>

        function showToast(msg, type) {
            const t = document.getElementById('toastMsg');
            t.querySelector('span').textContent = msg;
            t.style.background = (type === 'error') ? '#ef4444' : '#10b981';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        // Theme Sync
        const syncTheme = () => {
            const savedTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = savedTheme;
            let themeClass = savedTheme.replace('theme-', '');
            document.querySelectorAll('.theme-dot').forEach(b => b.classList.remove('active'));
            let activeBtn = document.querySelector(`.theme-dot.${themeClass}`);
            if (activeBtn) activeBtn.classList.add('active');
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);

        function setTheme(t, button) {
            localStorage.setItem('rd-portal-theme', t);
            document.body.className = t;

            try {
                const storageEvent = new StorageEvent('storage', { key: 'rd-portal-theme', newValue: t });
                window.dispatchEvent(storageEvent);
                if (window.parent) window.parent.dispatchEvent(storageEvent);
            } catch (e) {}

            document.querySelectorAll('.theme-dot').forEach(b => b.classList.remove('active'));
            button.classList.add('active');
        }

        // Tabs
        function switchTab(tabId, btn) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }

        // Deactivation
        const dInput = document.getElementById('dInput');
        const dBtn = document.getElementById('dBtn');
        if(dInput && dBtn) {
            dInput.addEventListener('input', (e) => {
                if (e.target.value === 'DEACTIVATE') {
                    dBtn.classList.add('unlocked');
                    dBtn.disabled = false;
                } else {
                    dBtn.classList.remove('unlocked');
                    dBtn.disabled = true;
                }
            });
        }
    </script>
</body>
</html>
