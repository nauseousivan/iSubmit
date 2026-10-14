<?php

/**
 * Clearance (login.php)
 * Real-time Institutional Access Portal for MCNP-ISAP
 */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Setconsistent timezone
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once '../config/google_auth.php';   // "Continue with Google" (2026-10-15)

$message = "";
$message_type = "error";
// A note left by the Google sign-in (auth/google_callback.php): cancelled, not approved yet, closed, ...
if (!empty($_SESSION['login_notice'])) {
    [$message, $message_type] = $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}
// config/db.php signs out an account that was disabled mid-session and sends it here.
if (isset($_GET['blocked'])) {
    $message = "Your account is no longer active. Please contact the research office.";
}
if (isset($_GET['maintenance']) || (rd_setting('maintenance_mode') === '1' && $_SERVER['REQUEST_METHOD'] !== 'POST')) {
    $message = "The system is under maintenance. Please try again later.";
    $message_type = "error";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action']) && $_POST['auth_action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 2026-10-01: the ACCESS LOG + LOCKOUT (config/system.php). A locked email is refused
        // before its password is even checked, so guessing cannot continue during the lockout.
        $locked = rd_locked_minutes($pdo, $email);
        $pw_ok  = !$locked && $user && password_verify($password, $user['password']);
        $uid_or_null = $user ? (int)$user['user_id'] : null;

        if ($locked) {
            rd_log_login($pdo, $email, $uid_or_null, false, 'locked');
            $message = "Too many wrong passwords. Try again in $locked minute" . ($locked === 1 ? '' : 's')
                     . ", or ask the administrator for help below.";
            $message_type = "error";
        } elseif ($pw_ok && ($user['account_status'] ?? 'active') !== 'active') {
            rd_log_login($pdo, $email, $uid_or_null, false, 'inactive');
            // 2026-09-30: checked BEFORE the OTP branch. A disabled account used to be sent to
            // verify_otp.php, which set is_verified back to 1 — the person re-enabled themself.
            // Only after the password matched, so this never tells a stranger an email exists.
            $message = [
                'pending'  => 'Your account is waiting for the administrator\'s approval. You can sign in once it is approved.',
                'rejected' => 'This account was not approved. Please contact the research office.',
                'disabled' => 'This account has been disabled. Please contact the research office.',
            ][$user['account_status']] ?? 'This account cannot sign in. Please contact the research office.';
            $message_type = $user['account_status'] === 'pending' ? 'warning' : 'error';
        } elseif ($pw_ok && rd_setting('maintenance_mode') === '1' && $user['role'] !== 'Admin') {
            // MAINTENANCE MODE (System settings): only the Admin may sign in.
            rd_log_login($pdo, $email, $uid_or_null, false, 'maintenance');
            $message = "The system is under maintenance. Please try again later.";
            $message_type = "error";
        } elseif ($pw_ok) {
            rd_log_login($pdo, $email, $uid_or_null, true, 'ok');
            session_regenerate_id(true);
            if (!$user['is_verified']) {
                $_SESSION['verify_email'] = $user['email'];
                $message = "Your account is not verified yet. Verification code sent.";
                $message_type = "warning";
                header("Location: verify_otp.php");
                exit();
            }

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['department'] = $user['department'] ?? '';
            $_SESSION['program'] = $user['program'] ?? '';
            
            // Resolve effective group name from leader if applicable
            $eff_group_name = $user['research_group_name'];
            if (!empty($user['leader_id'])) {
                $l_stmt = $pdo->prepare("SELECT research_group_name FROM users WHERE user_id = ?");
                $l_stmt->execute([$user['leader_id']]);
                if ($l_name = $l_stmt->fetchColumn()) {
                    $eff_group_name = $l_name;
                }
            }
            $_SESSION['research_group_name'] = $eff_group_name ?: 'Research Group';

            // The Admin reset this password: the person chooses their own before anything else.
            if (!empty($user['must_change_password'])) {
                header("Location: change_password.php");
                exit();
            }

            // 2026-10-01: back to the page that sent them to sign in (a scanned QR, e.g. stat_desk.php).
            // Only a path inside this app is accepted, so this can never become an open redirect.
            if (!empty($_SESSION['login_next'])) {
                $next = (string)$_SESSION['login_next'];
                unset($_SESSION['login_next']);
                if (strpos($next, rd_app_url() . '/dashboards/') === 0 && preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $next) && strpos($next, '..') === false && strpos($next, '//') === false
                    && (strpos($next, '/dashboards/student.php') === false || $user['role'] === 'Student')) {
                    header("Location: " . $next);
                    exit();
                }
            }

            // Route dynamically depending on role permissions
            switch ($user['role']) {
                case 'Student':
                    header("Location: ../dashboards/student.php");
                    exit();
                case 'Research Coordinator':
                    header("Location: ../dashboards/coordinator.php");
                    exit();
                case 'Statistician':
                    header("Location: ../dashboards/statistician.php");
                    exit();
                case 'Research Director':
                    header("Location: ../dashboards/director.php");
                    exit();
                case 'Focal Person':
                    header("Location: ../dashboards/focal.php");
                    exit();
                case 'Admin':
                    header("Location: ../dashboards/admin.php");
                    exit();
            }
            // A role with no destination used to fall straight through this switch and
            // silently re-render the login form, which reads as "wrong password".
            $message = "Your account has no dashboard yet. Please contact the research office.";
            $message_type = "error";
        } else {
            rd_log_login($pdo, $email, $uid_or_null, false, $user ? 'wrong_password' : 'no_account');
            $message = "Invalid credentials. Verify email and password.";
            $message_type = "error";
            // The attempt that trips the lockout says so, instead of one more "invalid".
            if ($user && ($now_locked = rd_locked_minutes($pdo, $email))) {
                $message = "Too many wrong passwords. Try again in $now_locked minutes, or ask the administrator for help below.";
            }
        }
    } else {
        $message = "Please populate all security fields.";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="color-scheme" content="light only">
    <title>Login | iSubmit</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <!-- Premium Academic & Display Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Theme Settings -->
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/mascot/mascot.css?v=<?= time() ?>">
    <script src="../assets/js/ripple.js"></script>
    <style>
        /* FORCE WHITE TEXT NO MATTER WHAT CACHE SAYS */
        #brandTitle,
        #brandSubLead,
        .brand-title,
        .brand-sub-lead {
            color: #ffffff !important;
        }

    </style>
</head>

<body class="no-intro-delay">
<?php include __DIR__ . '/../dashboards/components/announcement.php'; /* 2026-10-01: the Admin's banner */ ?>
    <?php include __DIR__ . '/../dashboards/components/eagle_intro.php'; /* the "Eagle flight" loading screen */ ?>

    <div class="auth-wrapper">
        <div class="auth-card">
            <!-- Visual Pane -->
            <div class="auth-visual-pane" id="brandOverlay">
                <!-- Branding Mascot Badge -->
                <div class="brand-icon-box" id="brandBadge" style="margin-bottom: 8px; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; position: relative; z-index: 50;">
                    <?php include '../assets/mascot/mascot.php'; ?>
                </div>

                <div style="text-align: center; max-width: 320px; margin: 0 auto 0;">
                    <!-- Titles are now visible on mobile too -->
                    <h1 id="brandTitle" style="color: white; font-size: 38px; font-weight: 800; margin-bottom: 4px; margin-top: 0; letter-spacing: -0.5px; font-family: 'Poppins', sans-serif;">iSubmit</h1>
                    <p id="brandSubLead" style="color: white; font-size: 16px; opacity: 0.95; margin-bottom: 24px; font-weight: 500; margin-top: 0; font-family: 'Plus Jakarta Sans', sans-serif;">Research Digitalization Platform</p>
                    <p class="desktop-only" style="color: white; font-size: 14px; opacity: 0.8; line-height: 1.6;">Submit research documents, track consultation progress, and stay updated throughout every stage of your research journey.</p>
                </div>

                <div class="desktop-only" style="text-align: center; margin-top: 48px;">
                    <div style="font-size: 13px; opacity: 0.8; margin-bottom: 12px; font-weight: 500; color: white;">First time using the research portal?</div>
                    <a href="register.php" class="mat-btn mat-btn-outline" style="border-color: rgba(255,255,255,0.4); color: white; width: 100%; max-width: 250px;">
                        Create Account
                    </a>
                </div>
            </div>

            <!-- Form Pane -->
            <div class="auth-form-pane bottom-sheet">
                <div class="drag-handle"></div>
                <div class="auth-header">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); font-weight: 700; margin-bottom: 8px; display: block;">Digitalization of Research Process</span>
                    <h2 id="formHeader">Login Access</h2>
                    <p>Welcome back! Researcher, please sign in.</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="mat-alert <?php echo ($message_type === 'error') ? 'mat-alert-error' : 'mat-alert-success'; ?>">
                        <i data-lucide="info" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" autocomplete="on">
                    <input type="hidden" name="auth_action" value="login">

                    <div class="mat-input-group">
                        <label>Email</label>
                        <div class="mat-input-with-icon">
                            <i data-lucide="mail" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <input type="email" name="email" id="loginEmail" class="mat-input" placeholder="example@mcnp.edu.ph" required autocomplete="username">
                        </div>
                    </div>

                    <div class="mat-input-group">
                        <label>Password</label>
                        <div class="mat-input-with-icon">
                            <i data-lucide="lock" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <input type="password" name="password" id="loginPass" class="mat-input" placeholder="••••••••" required autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('loginPass', this)" aria-label="Show password" style="position: absolute; right: 12px; top: 0; background: transparent; border: none; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; height: 100%; padding: 0 4px;">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px;">
                        <a href="forgot_password.php" style="font-size: 13px; color: var(--primary-color); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                            <i data-lucide="key-round" style="width: 14px; height: 14px;"></i>
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px;">
                        LOGIN
                    </button>
                    <?php rd_google_button('Continue with Google'); ?>

                    <div style="text-align: center; margin-top: 24px; font-size: 14px; color: var(--text-secondary);" class="mobile-only-link">
                        First time? <a href="register.php" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Create Account</a>
                        <span style="margin: 0 6px; opacity: 0.4;">&middot;</span>
                        <a href="help.php" style="color: var(--text-secondary); font-weight: 600; text-decoration: none;">Get help</a>
                    </div>
                </form>

                <?php /* 2026-10-01: for anyone who cannot use the email reset (lost the inbox, wrong email).
                         Its own quiet line under the button, not a rival link beside "Forgot password?".
                         Desktop only: on a phone it rides the "First time?" line instead of stacking under it. */ ?>
                <div class="desktop-only" style="text-align: center; margin-top: 16px; font-size: 13px; color: var(--text-muted);">
                    Trouble signing in? <a href="help.php" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Ask the Help Desk</a>
                </div>

                <div class="auth-footer" style="text-align: center; margin-top: 48px; font-size: 11px; color: #a1a1aa; letter-spacing: 0.5px; font-weight: 400;">
                    Medical Colleges of Northern Philippines
                    <span style="margin: 0 4px; opacity: 0.5;">&bull;</span>
                    International School of Asia & the Pacific
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/bottom-sheet.js"></script>
    <script>
        // Initialize dynamic Lucide icons
        lucide.createIcons();

        // Reveal/Obscure Password Entry
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);

            if (input.type === 'password') {
                input.type = 'text';
                btn.innerHTML = '<i data-lucide="eye-off" style="width: 18px; height: 18px; margin: 0;"></i>';
            } else {
                input.type = 'password';
                btn.innerHTML = '<i data-lucide="eye" style="width: 18px; height: 18px; margin: 0;"></i>';
            }
            lucide.createIcons();
        }

        // The sub-line names the institution as the email is typed. The COLOUR no longer follows it
        // (2026-10-07, the user asked): the pane stays the one iSubmit violet for everyone.
        const loginEmail = document.getElementById('loginEmail');
        const brandSubLead = document.getElementById('brandSubLead');

        function checkEmailBranding() {
            const emailVal = loginEmail.value.toLowerCase().trim();

            if (emailVal.includes('isap')) {
                brandSubLead.innerText = "International School of Asia & the Pacific";
            } else if (emailVal.includes('mcnp')) {
                brandSubLead.innerText = "Medical Colleges of Northern Philippines";
            } else {
                // Revert to original
                document.getElementById('brandTitle').textContent = 'iSubmit';
                document.getElementById('brandSubLead').textContent = 'Research Digitalization Platform';
            }
        }

        if (loginEmail) {
            loginEmail.addEventListener('input', checkEmailBranding);
            // Timeout load check for browser cached credentials
            window.addEventListener('load', () => setTimeout(checkEmailBranding, 150));
        }

        // Mascot Password Interactions
        document.addEventListener('DOMContentLoaded', () => {
            const passwordInput = document.getElementById('loginPass');
            if (passwordInput) {
                passwordInput.addEventListener('focus', () => {
                    if (window.Quill) window.Quill.coverEyes();
                });
                passwordInput.addEventListener('blur', () => {
                    if (window.Quill) window.Quill.idle();
                });
            }
        });
    </script>
    <script src="../assets/js/constellation.js"></script>
    <script src="../assets/mascot/mascot.js"></script>
</body>

</html>