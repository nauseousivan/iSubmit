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
        // Deactivate account by setting is_verified = 0
        $stmt_deactivate = $pdo->prepare("UPDATE users SET is_verified = 0 WHERE user_id = ?");
        if ($stmt_deactivate->execute([$user_id])) {
            session_destroy();
            header("Location: ../auth/login.php?msg=" . urlencode("Your account has been deactivated successfully. Please contact support to restore access."));
            exit();
        } else {
            $message = "Unable to deactivate account. Please try again.";
            $message_type = "error";
        }
    } else {
        // Password change logic
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $db_pass = $stmt->fetchColumn();

        if (password_verify($current_pass, $db_pass)) {
            if ($new_pass === $confirm_pass) {
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt_update = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                if ($stmt_update->execute([$hashed, $user_id])) {
                    $message = "Your password has been successfully updated.";
                    $message_type = "success";
                }
            } else {
                $message = "The new passwords you entered do not match.";
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
    <title>Systems Settings</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

        /* THEME CLASSES - Consistent styling from student.php */
        body.theme-default,
        body.theme-blue {
            --bg-canvas: #f0f4f9;
            --bg-card: #ffffff;
            --text-primary: #1c2a44;
            --text-secondary: #5f6f8a;
            --text-muted: #9c9284;
            --border-subtle: #c6d4e9;
            --mcnp-teal: #1e40af;
        }

        body.theme-red {
            --bg-canvas: #fef2f2;
            --bg-card: #ffffff;
            --text-primary: #7f1d1d;
            --text-secondary: #b91c1c;
            --text-muted: #9c9284;
            --border-subtle: #fbd5d5;
            --mcnp-teal: #b91c1c;
        }

        body.theme-pink,
        body.theme-rose {
            --bg-canvas: #fdf2f8;
            --bg-card: #ffffff;
            --text-primary: #831843;
            --text-secondary: #be185d;
            --text-muted: #9c9284;
            --border-subtle: #fbcfe8;
            --mcnp-teal: #be185d;
        }

        body.theme-green {
            --bg-canvas: #f0fdf4;
            --bg-card: #ffffff;
            --text-primary: #14532d;
            --text-secondary: #15803d;
            --text-muted: #9c9284;
            --border-subtle: #bbf7d0;
            --mcnp-teal: #15803d;
        }

        body.theme-purple,
        body.theme-lavender {
            --bg-canvas: #f5f3ff;
            --bg-card: #ffffff;
            --text-primary: #4c1d95;
            --text-secondary: #6cb8d9;
            --text-muted: #9c9284;
            --border-subtle: #ddd6fe;
            --mcnp-teal: #6d28d9;
        }

        body.theme-orange,
        body.theme-amber {
            --bg-canvas: #fffbeb;
            --bg-card: #ffffff;
            --text-primary: #78350f;
            --text-secondary: #b45309;
            --text-muted: #9c9284;
            --border-subtle: #fde68a;
            --mcnp-teal: #b45309;
        }

        body.theme-dark {
            --bg-canvas: #1a1d21;
            --bg-card: #24282d;
            --text-primary: #e0e0e0;
            --text-secondary: #b0ada8;
            --text-muted: #b0ada8;
            --border-subtle: #3a3f45;
            --mcnp-teal: #38bdf8;
        }

        :root {
            --bg-beige: var(--bg-canvas, #f0f4f9);
            --bg-white: var(--bg-card, #ffffff);
            --text-dark: var(--text-primary, #1c2a44);
            --text-muted: var(--text-muted, #5f6f8a);
            --border-line: var(--border-subtle, #c6d4e9);
            --mcnp-teal: var(--mcnp-teal, #1e40af);
            --mcnp-hover: var(--text-primary, #1c2a44);
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Cambria', serif;
            background-color: var(--bg-beige);
            color: var(--text-dark);
            min-height: 100vh;
            padding: 24px;
            transition: 0.3s;
        }

        body::-webkit-scrollbar {
            display: none;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 25px;
        }

        .page-title h1 {
            font-family: var(--ui-sans);
            font-size: 32px;
            font-weight: 800;
            color: var(--mcnp-teal);
            margin-bottom: 4px;
            letter-spacing: -0.025em;
        }

        .page-title p {
            font-size: 15px;
            color: var(--text-muted);
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 30px;
            margin-top: 25px;
        }

        /* Left Sidebar Menu */
        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-item {
            font-family: var(--ui-sans);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            background: none;
            border: none;
            text-align: left;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 700;
            border-radius: 12px;
            transition: 0.25s;
            text-decoration: none;
        }

        .sidebar-item:hover,
        .sidebar-item.active {
            background: var(--bg-white);
            color: var(--mcnp-teal);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.03);
        }

        /* Right Content Panel */
        .panel {
            background: var(--bg-white);
            padding: 35px;
            border-radius: 24px;
            border: 1.5px solid var(--border-line);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .panel:hover {
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            border-color: var(--mcnp-teal);
        }

        .panel h2 {
            font-family: var(--ui-sans);
            font-size: 20px;
            font-weight: 800;
            color: var(--mcnp-teal);
            margin-bottom: 20px;
        }

        .alert {
            padding: 14px;
            border-radius: 12px;
            font-family: var(--ui-sans);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 25px;
            border-left: 4px solid;
        }

        .alert.success {
            background: #e6f4ea;
            color: #137333;
            border-left-color: #27ae60;
            border: 1px solid #c2e7c9;
            border-left-width: 4px;
        }

        .alert.error {
            background: #fef2f2;
            color: #b71c1c;
            border-left-color: #c0392b;
            border: 1px solid #f9d5d5;
            border-left-width: 4px;
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            font-family: var(--ui-sans);
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        input {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border-line);
            background: var(--bg-beige);
            color: var(--text-dark);
            font-family: var(--ui-sans);
            font-size: 14px;
            transition: 0.2s;
        }

        input:focus {
            outline: none;
            border-color: var(--mcnp-teal);
            background: var(--bg-white);
        }

        .btn-save {
            font-family: var(--ui-sans);
            background: linear-gradient(to bottom right, var(--mcnp-teal), var(--mcnp-hover));
            color: white;
            border: none;
            width: 100%;
            padding: 16px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            font-size: 15px;
            transition: 0.2s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        /* Themes Palette Selection Grid */
        .themes-selection-panel {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }

        .themes-selection-panel label {
            font-family: var(--ui-sans);
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .themes-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .theme-btn {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            cursor: pointer;
            border: 3px solid transparent;
            transition: 0.25s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        .theme-btn.active {
            border-color: var(--text-dark);
            transform: scale(1.05);
        }

        .theme-btn:hover {
            transform: translateY(-2px);
        }

        .theme-btn.default {
            background: linear-gradient(135deg, #e8f0ff 50%, #4a7c8c 50%);
        }

        .theme-btn.blue {
            background: linear-gradient(135deg, #e0f2fe 50%, #0284c7 50%);
        }

        .theme-btn.red {
            background: linear-gradient(135deg, #ffe8e8 50%, #d65a5a 50%);
        }

        .theme-btn.green {
            background: linear-gradient(135deg, #e8f6ea 50%, #4a9e7b 50%);
        }

        .theme-btn.pink {
            background: linear-gradient(135deg, #fde8f5 50%, #c56ba8 50%);
        }

        .theme-btn.purple {
            background: linear-gradient(135deg, #f5f3ff 50%, #6d28d9 50%);
        }

        .theme-btn.orange {
            background: linear-gradient(135deg, #fffbeb 50%, #b45309 50%);
        }

        .theme-btn.dark {
            background: linear-gradient(135deg, #1c1917 50%, #ffb547 50%);
        }

        /* Mobile adaptions */
        @media (max-width: 640px) {
            body {
                padding: 12px !important;
            }

            .header {
                display: none !important;
            }

            .settings-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .sidebar-menu {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--border-line);
                gap: 8px;
                -webkit-overflow-scrolling: touch;
            }

            .sidebar-menu::-webkit-scrollbar {
                display: none;
            }

            .sidebar-item {
                font-size: 13px;
                padding: 10px 14px;
                white-space: nowrap;
                flex-shrink: 0;
                min-height: 44px;
                display: inline-flex;
                align-items: center;
            }

            .panel {
                padding: 20px;
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
            }

            .panel h2 {
                font-size: 18px;
                margin-bottom: 15px;
            }

            .sidebar-item-logout {
                border-top: none !important;
                margin-top: 0 !important;
                padding-top: 10px !important;
                background: rgba(231, 76, 60, 0.1) !important;
                color: #e74c3c !important;
                border-radius: 12px !important;
                padding-left: 14px !important;
                padding-right: 14px !important;
            }

            input {
                padding: 12px !important;
                font-size: 13px !important;
                border-radius: 10px !important;
                background-color: var(--bg-card, #ffffff) !important;
            }

            .btn-save {
                padding: 14px !important;
                font-size: 14px !important;
                border-radius: 10px !important;
            }
        }

        .sidebar-item-logout {
            color: #e74c3c !important;
            border-top: 1px solid var(--border-line);
            border-radius: 0;
            margin-top: 15px;
            padding-top: 15px;
        }
    </style>
</head>

<body class="theme-default">
    <div class="container">
        <div class="header">
            <div class="page-title">
                <h1>Settings</h1>
                <p>Configure dashboard styling and update security passwords.</p>
            </div>
        </div>

        <div class="settings-grid">
            <div class="sidebar-menu">
                <a href="#appearance" class="sidebar-item active" onclick="switchTab('appearance', this)">Appearance</a>
                <a href="#security" class="sidebar-item" onclick="switchTab('security', this)">Password & Security</a>
                <a href="#deactivation" class="sidebar-item" onclick="switchTab('deactivation', this)" style="color: #be185d;">Deactivate Account</a>
                <a href="../auth/logout.php" class="sidebar-item sidebar-item-logout" target="_parent" onclick="return confirm('Sign out of your session?')">Sign Out</a>
            </div>

            <div class="panel" id="tab-appearance">
                <h2>Themes</h2>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.5;">Personalize your theme preference</p>
                <div class="themes-selection-panel">
                    <label>Select a theme</label>
                    <div class="themes-grid">
                        <button class="theme-btn default" onclick="setTheme('theme-default', this)" title="MCNP Blue-Teal Default"></button>
                        <button class="theme-btn red" onclick="setTheme('theme-red', this)" title="ISAP Red Maroon Palette"></button>
                        <button class="theme-btn green" onclick="setTheme('theme-green', this)" title="Science Green Palette"></button>
                        <button class="theme-btn pink" onclick="setTheme('theme-pink', this)" title="Rose Pastel Palette"></button>
                        <button class="theme-btn purple" onclick="setTheme('theme-purple', this)" title="Lavender Pastel Palette"></button>
                        <button class="theme-btn orange" onclick="setTheme('theme-orange', this)" title="Amber Sand Pastel Palette"></button>
                        <button class="theme-btn dark" onclick="setTheme('theme-dark', this)" title="Slate Dark Theme"></button>
                    </div>
                </div>
            </div>

            <div class="panel" id="tab-security" style="display: none;">
                <h2>Password & Security</h2>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.5;">Change your password credentials regularly for your account security.</p>
                <?php if ($message && !isset($_POST['action'])): ?>
                    <div class="alert <?= $message_type ?>"><?= $message ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="field">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="new_password" required minlength="6">
                    </div>
                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn-save">Update Password Credentials</button>
                </form>
            </div>

            <div class="panel" id="tab-deactivation" style="display: none; border-color: #fbcfe8;">
                <h2 style="color: #be185d;">Account Deactivation</h2>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.5;">Temporarily disable your institutional student account. This action cannot be undone without administrator assistance.</p>
                <?php if ($message && isset($_POST['action']) && $_POST['action'] === 'deactivate_account'): ?>
                    <div class="alert <?= $message_type ?>"><?= $message ?></div>
                <?php endif; ?>
                <form method="POST" id="deactivateForm" onsubmit="return confirmDeactivate(event)">
                    <input type="hidden" name="action" value="deactivate_account">
                    <div style="background: #fdf2f8; border: 1.5px solid #fbcfe8; padding: 20px; border-radius: 12px; margin-bottom: 25px;">
                        <h4 style="color: #be185d; font-family: var(--ui-sans); font-size: 14px; font-weight: bold; margin-bottom: 8px;">Warning</h4>
                        <p style="font-size: 13px; color: #831843; font-family: var(--ui-sans); line-height: 1.45;">Deactivating your account will immediately revoke your dashboard access, clear your active session, and set your status to inactive. You will need to contact the Research Coordinator or Director to restore your account.</p>
                    </div>
                    <button type="submit" class="btn-save" style="background: linear-gradient(to bottom right, #be185d, #831843); font-weight: 800;">Deactivate My Account Now</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        function switchTab(tabId, el) {
            document.querySelectorAll('.panel').forEach(p => p.style.display = 'none');
            document.getElementById('tab-' + tabId).style.display = 'block';
            document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
        }

        function setTheme(t, button) {
            localStorage.setItem('rd-portal-theme', t);
            document.body.className = t;

            // Fail-safe cross-frame theme propagation
            try {
                if (window.parent) {
                    if (typeof window.parent.setThemeSafely === 'function') {
                        window.parent.setThemeSafely(t);
                    } else if (window.parent.document && window.parent.document.body) {
                        window.parent.document.body.className = t;
                    }
                }
            } catch (err) {
                console.warn("Cross-frame constraint handled: relying on storage sync.", err);
            }

            // Manually dispatch a storage event to trigger immediate listeners in parent context
            try {
                const storageEvent = new StorageEvent('storage', {
                    key: 'rd-portal-theme',
                    newValue: t,
                    url: window.location.href
                });
                window.dispatchEvent(storageEvent);
                if (window.parent) {
                    window.parent.dispatchEvent(storageEvent);
                }
            } catch (e) {}

            document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
            if (button) button.classList.add('active');
        }

        function confirmDeactivate(e) {
            if (confirm("Are you absolutely sure you want to deactivate your student research portal account? This action will log you out immediately and disable login access.")) {
                return true;
            }
            e.preventDefault();
            return false;
        }

        // Initialize active theme buttons on load
        window.addEventListener('load', () => {
            const currentTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = currentTheme;
            let themeClass = currentTheme.replace('theme-', '');
            let activeBtn = document.querySelector(`.theme-btn.${themeClass}`);
            if (activeBtn) {
                document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
                activeBtn.classList.add('active');
            }
        });

        // Hash anchor routing inside sub panel
        if (window.location.hash) {
            const hash = window.location.hash.substring(1);
            const anchorBtn = document.querySelector(`a[href="#${hash}"]`);
            if (anchorBtn) switchTab(hash, anchorBtn);
        }
    </script>
</body>

</html>