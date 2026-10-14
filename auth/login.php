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

$message = "";
$message_type = "error";
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

        /* Loading screen, "Eagle flight" (2026-10-07). The main eagle (ids "sp-", assets/mascot/eagle_svg.php)
           flaps in from the lower left, loops across a drifting doodle sky and lands on the "i" of iSubmit
           as its dot. Her dotted trail draws behind her — the trail IS the progress bar. On landing she
           bounces, tiptoes and waves (.splash-wave), then the curtain fades into the card. The path is
           built by the intro script from where the "i" really is, so she lands on it at any font size.
           Tap skips. Reduced motion: she is simply sitting on the "i". */
        .fl-stage { position: relative; width: 360px; height: 270px; }
        .fl-trail { position: absolute; inset: 0; overflow: visible; color: #a39dff; transition: opacity .45s ease; }
        .fl-trail-mask { stroke-dasharray: 1; stroke-dashoffset: 1; }
        .fl-bird { position: absolute; top: 0; left: 0; width: 86px; height: 89px; offset-anchor: 48% 94%; offset-rotate: 0deg;
                   offset-distance: 0%; opacity: 0; }
        .fl-tilt { width: 100%; height: 100%; transform-origin: 48% 94%; }
        .splash-eagle { width: 100%; height: 100%; display: block; overflow: visible; filter: drop-shadow(0 8px 12px rgba(76, 29, 149, .22)); }
        .fl-word { position: absolute; left: 0; right: 0; bottom: 18px; text-align: center; margin: 0; font-family: 'Poppins', sans-serif;
                   font-size: 64px; font-weight: 800; letter-spacing: -2px; line-height: 1; color: #5b50f5; white-space: nowrap; }
        .fl-word span { display: inline-block; opacity: 0; transform: translateY(18px) scale(.6); }
        .fl-bl { display: inline-block; width: 0; height: 0; vertical-align: baseline; }   /* marks the baseline for the script */
        .fl-skip { position: absolute; bottom: calc(26px + env(safe-area-inset-bottom)); left: 0; right: 0; text-align: center;
                   font-size: 12px; font-weight: 600; letter-spacing: .4px; color: #8b83c9; opacity: 0; transition: opacity .4s ease .8s; }
        @media (max-width: 400px) { .fl-stage { transform: scale(.88); } }

        /* in flight */
        .intro-fly .fl-bird  { opacity: 1; offset-distance: 100%; transition: offset-distance 1.5s cubic-bezier(.45, .05, .35, 1), opacity .2s ease; }
        .intro-fly .fl-trail-mask { stroke-dashoffset: 0; transition: stroke-dashoffset 1.5s cubic-bezier(.45, .05, .35, 1); }
        .intro-fly .fl-tilt  { animation: flTilt 1.5s cubic-bezier(.45, .05, .35, 1) forwards; }
        .intro-fly #sp-wingL { animation: flFlapL .18s ease-in-out infinite alternate; }
        .intro-fly #sp-wingR { animation: flFlapR .18s ease-in-out infinite alternate; }
        .intro-fly .fl-word span { animation: flPop .45s cubic-bezier(.34, 1.56, .64, 1) forwards; animation-delay: calc(.35s + var(--n) * .08s); }
        .intro-fly .fl-skip  { opacity: 1; }
        /* landed */
        .intro-landed .fl-trail { opacity: 0; }
        .intro-landed .fl-tilt  { animation: flLand .4s cubic-bezier(.34, 1.56, .64, 1); }
        .intro-landed #sp-wingL, .intro-landed #sp-wingR { animation: none; }

        #sp-wingL { transform-box: view-box; transform-origin: 70px 166px; }
        #sp-wingR { transform-box: view-box; transform-origin: 190px 166px; }
        #sp-body  { transform-box: view-box; transform-origin: 170px 256px; transition: transform .35s ease; }
        #sp-footL { transform-box: view-box; transform-origin: 90px 256px; transition: transform .35s ease; }
        #sp-cap   { transform-box: view-box; transform-origin: 128px 60px; }
        .splash-wave #sp-body  { transform: rotate(7deg); }
        .splash-wave #sp-footL { transform: translateY(-9px) rotate(16deg); }
        .intro-landed .splash-wave #sp-wingL { animation: spWaveHigh .4s ease-in-out infinite alternate; }
        .splash-wave #sp-wingR { transform: rotate(-8deg); }
        .splash-wave #sp-cap   { animation: spCapBob .4s ease-in-out infinite alternate; }
        @keyframes flFlapL { from { transform: rotate(-12deg); } to { transform: rotate(46deg); } }
        @keyframes flFlapR { from { transform: rotate(12deg); } to { transform: rotate(-46deg); } }
        @keyframes flTilt { 0% { transform: rotate(-10deg); } 35% { transform: rotate(-16deg); } 62% { transform: rotate(12deg); } 88% { transform: rotate(10deg); } 100% { transform: rotate(0); } }
        @keyframes flLand { 0% { transform: scale(1.12, .84); } 55% { transform: scale(.94, 1.08); } 100% { transform: scale(1); } }
        @keyframes flPop { to { opacity: 1; transform: none; } }
        @keyframes spWaveHigh { from { transform: rotate(118deg); } to { transform: rotate(150deg); } }
        @keyframes spCapBob { from { transform: rotate(-2deg); } to { transform: rotate(2deg); } }
        @media (prefers-reduced-motion: reduce) {
            .intro-cinematic-curtain::before { animation: none; }
            .fl-bird, .intro-fly .fl-bird { opacity: 1; offset-distance: 100%; transition: none; }
            .fl-trail { display: none; }
            .fl-word span, .intro-fly .fl-word span { opacity: 1; transform: none; animation: none; }
            .intro-fly .fl-tilt, .intro-landed .fl-tilt, .intro-fly #sp-wingL, .intro-fly #sp-wingR, .splash-wave #sp-cap,
            .intro-landed .splash-wave #sp-wingL { animation: none; }
        }
    </style>
</head>

<body class="no-intro-delay">
<?php include __DIR__ . '/../dashboards/components/announcement.php'; /* 2026-10-01: the Admin's banner */ ?>
    <script>
        window.isFirstLoad = !sessionStorage.getItem('mcnp_isap_intro_played');
        // Check if the user has already played the intro during this session
        if (window.isFirstLoad) {
            document.body.classList.remove('no-intro-delay');
            document.body.classList.add('intro-playing');   // holds the card back until she lands
            sessionStorage.setItem('mcnp_isap_intro_played', 'true');
        }
        // Ends the intro: fade the curtain, let the card in. Safe to call twice; the 6s timer is the
        // net if anything below fails, so the card can never stay hidden.
        window.endIntro = function () {
            if (!document.body.classList.contains('intro-playing')) return;
            document.body.classList.remove('intro-playing');
            var c = document.getElementById('introCurtain');
            if (c) { c.classList.add('is-out'); setTimeout(function () { c.style.display = 'none'; }, 600); }
        };
        if (window.isFirstLoad) setTimeout(window.endIntro, 6000);
    </script>

    <!-- iSubmit Loading Curtain: "Eagle flight" -->
    <div class="intro-cinematic-curtain" id="introCurtain" aria-hidden="true" onclick="window.endIntro()">
        <div class="fl-stage" id="flStage">
            <svg class="fl-trail" width="360" height="270" viewBox="0 0 360 270">
                <defs><mask id="flMask" maskUnits="userSpaceOnUse" x="-200" y="-200" width="760" height="670">
                    <path class="fl-trail-mask" id="flMaskPath" d="" fill="none" stroke="#fff" stroke-width="16" stroke-linecap="round" pathLength="1"/>
                </mask></defs>
                <path id="flTrailPath" d="" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-dasharray="0 13" mask="url(#flMask)"/>
            </svg>
            <div class="fl-word" id="flWord"><?php
                // "ı" (dotless) — she is the dot. Each letter pops in as she passes.
                foreach (['ı', 'S', 'u', 'b', 'm', 'i', 't'] as $n => $ch) {
                    echo '<span style="--n:' . $n . '"' . ($n === 0 ? ' id="flI"' : '') . '>' . $ch . ($n === 0 ? '<i class="fl-bl" id="flBase"></i>' : '') . '</span>';
                } ?></div>
            <div class="fl-bird" id="flBird"><div class="fl-tilt"><?php
                require_once __DIR__ . '/../assets/mascot/eagle_svg.php';
                echo eagle_plain_svg('sp', 'id="splash-mascot" class="splash-eagle"'); ?></div></div>
        </div>
        <div class="fl-skip">Tap to skip</div>
    </div>

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
                            <button type="button" class="mat-btn mat-btn-text" onclick="togglePassword('loginPass', this)" style="position: absolute; right: 4px; padding: 8px; min-width: auto; height: 36px;">
                                <i data-lucide="eye" style="width: 18px; height: 18px; margin: 0;"></i>
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
    <script>
        // "Eagle flight" intro (first visit of the session only; see the CSS above for the look).
        (function () {
            if (!document.body.classList.contains('intro-playing')) return;
            const curtain = document.getElementById('introCurtain');
            const word = document.getElementById('flWord'), dotless = document.getElementById('flI'), base = document.getElementById('flBase');
            const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let started = false;

            // Where the stem of the "ı" starts, in stage pixels. offset* ignore the letters' pop-in
            // transforms; the stem height comes from the font itself.
            function landingPoint() {
                const fs = parseFloat(getComputedStyle(word).fontSize);
                let ascent = fs * 0.56;
                try {
                    const ctx = document.createElement('canvas').getContext('2d');
                    ctx.font = '800 ' + fs + 'px Poppins';
                    const m = ctx.measureText('ı');
                    if (m.actualBoundingBoxAscent > 0) ascent = m.actualBoundingBoxAscent;
                } catch (e) {}
                return {
                    x: word.offsetLeft + dotless.offsetLeft + dotless.offsetWidth / 2 - 1,
                    y: word.offsetTop + base.offsetTop - ascent - 6
                };
            }

            function fly() {
                if (started) return;
                started = true;
                const e = landingPoint();
                // In low from the left, a loop high over the stage, then down onto the "ı".
                const d = 'M -70 230 C 40 40, 300 -40, 330 70 C 352 150, ' + (e.x + 90) + ' ' + (e.y - 120) + ', ' + e.x + ' ' + e.y;
                document.getElementById('flTrailPath').setAttribute('d', d);
                document.getElementById('flMaskPath').setAttribute('d', d);
                document.getElementById('flBird').style.offsetPath = "path('" + d + "')";
                const eagle = document.getElementById('splash-mascot');

                if (still) { curtain.classList.add('intro-fly', 'intro-landed'); setTimeout(window.endIntro, 900); return; }
                void curtain.offsetWidth;                         // commit the start position before moving
                curtain.classList.add('intro-fly');
                setTimeout(function () {                          // touchdown
                    curtain.classList.add('intro-landed');
                    setTimeout(function () { eagle.classList.add('splash-wave'); }, 250);
                }, 1500);
                setTimeout(window.endIntro, 2600);
            }

            // Wait for Poppins (the landing spot depends on it), but never more than 400ms.
            if (document.fonts && document.fonts.load) {
                Promise.race([document.fonts.load('800 64px Poppins'), new Promise(function (r) { setTimeout(r, 400); })]).then(fly, fly);
            } else {
                fly();
            }
        })();
    </script>
</body>

</html>