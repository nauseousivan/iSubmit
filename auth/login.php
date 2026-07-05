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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action']) && $_POST['auth_action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
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
            $_SESSION['research_group_name'] = $user['research_group_name'] ?? 'Research Group';

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
            }
        } else {
            $message = "Invalid credentials. Verify email and password.";
            $message_type = "error";
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
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
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

        /* Splash Screen Mascot Animations */
        #splash-body, #splash-footL, #splash-wingR, #splash-wingL {
            transition: transform 0.3s ease;
        }
        
        .splash-wave #splash-body {
            transform: rotate(8deg);
        }
        .splash-wave #splash-footL {
            transform: translateY(-8px) rotate(15deg);
        }
        .splash-wave #splash-wingR {
            transform: rotate(30deg) translate(-5px, 5px);
        }
        .splash-wave #splash-wingL {
            animation: quillWaveHigh 0.4s ease-in-out infinite alternate;
        }
    </style>
</head>

<body class="no-intro-delay">
    <script>
        window.isFirstLoad = !sessionStorage.getItem('mcnp_isap_intro_played');
        // Check if the user has already played the intro during this session
        if (window.isFirstLoad) {
            document.body.classList.remove('no-intro-delay');
            sessionStorage.setItem('mcnp_isap_intro_played', 'true');
        }
    </script>

    <!-- iSubmit Loading Curtain -->
    <div class="intro-cinematic-curtain" id="introCurtain">
        <div class="isubmit-loader-container" style="animation: fadeInScale 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards, heroTransition 0.6s cubic-bezier(0.85, 0, 0.15, 1) 4.2s forwards;">
            <!-- Splash Mascot and Title -->
            <svg viewBox="0 0 120 140" xmlns="http://www.w3.org/2000/svg" style="width: 140px; height: 140px; margin-bottom: 8px;" id="splash-mascot">
                <defs>
                    <filter id="splash-ground-shadow" x="-50%" y="-50%" width="200%" height="200%">
                        <feGaussianBlur stdDeviation="3" />
                    </filter>
                    <filter id="splash-core-shadow" x="-50%" y="-50%" width="200%" height="200%">
                        <feGaussianBlur stdDeviation="1.5" />
                    </filter>
                </defs>
                
                <ellipse cx="60" cy="134" rx="36" ry="4" fill="#B8A9FF" opacity="0.6" filter="url(#splash-ground-shadow)" />
                <ellipse cx="60" cy="134" rx="22" ry="2" fill="#5750d4" opacity="0.4" filter="url(#splash-core-shadow)" />
                
                <g>
                    <!-- Left foot raised and angled -->
                    <ellipse cx="46" cy="130" rx="10" ry="5" fill="#5750d4" style="transform-origin: 46px 130px;" id="splash-footL" />
                    <!-- Right foot planted -->
                    <ellipse cx="74" cy="130" rx="10" ry="5" fill="#5750d4" />
                </g>

                <!-- Body pivots on right foot (74px 130px) -->
                <g style="transform-origin: 74px 130px;" id="splash-body">
                    <g style="transform-origin: 60px 25px;">
                        <rect x="28" y="24" width="64" height="10" rx="3" fill="#1e1b4b" />
                        <polygon points="60,10 90,26 60,30 30,26" fill="#312e81" />
                        <line x1="90" y1="26" x2="96" y2="46" stroke="#312e81" stroke-width="2" />
                        <circle cx="96" cy="48" r="4" fill="#a78bfa" />
                    </g>

                    <ellipse cx="60" cy="90" rx="34" ry="42" fill="#6C63FF" />
                    <ellipse cx="60" cy="96" rx="20" ry="26" fill="#ede9fe" />

                    <g style="transform-origin: 60px 72px;">
                        <circle cx="44" cy="72" r="11" fill="white" />
                        <circle cx="76" cy="72" r="11" fill="white" />
                        <g>
                            <circle cx="46" cy="73" r="6" fill="#1e1b4b" />
                            <circle cx="78" cy="73" r="6" fill="#1e1b4b" />
                            <circle cx="48" cy="70" r="2.5" fill="white" />
                            <circle cx="80" cy="70" r="2.5" fill="white" />
                        </g>
                    </g>

                    <polygon points="60,78 53,86 67,86" fill="#f59e0b" />

                    <!-- Left wing waves continuously using the keyframes from mascot.css -->
                    <g style="transform-origin: 27px 90px;" id="splash-wingL">
                        <ellipse cx="27" cy="98" rx="12" ry="20" fill="#5750d4" transform="rotate(-15,27,98)" />
                    </g>

                    <!-- Right wing tucks in -->
                    <g style="transform-origin: 93px 90px;" id="splash-wingR">
                        <ellipse cx="93" cy="98" rx="12" ry="20" fill="#5750d4" transform="rotate(15,93,98)" />
                    </g>
                </g>
            </svg>
            <h1 class="isubmit-logo" style="font-family: 'Poppins', sans-serif; font-size: 48px; letter-spacing: -1.5px; margin: 0; color: var(--primary-color);">iSubmit</h1>
            <div class="isubmit-progress-bar">
                <div class="isubmit-progress-fill"></div>
            </div>
        </div>
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

                    <div style="text-align: right; margin-bottom: 24px;">
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
                    </div>
                </form>

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

        // Transition cleanup to free up rendering budget after animation
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                const curtain = document.getElementById('introCurtain');
                if (curtain) {
                    curtain.style.display = 'none';
                }
            }, 3000);
        });
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

        // Real-time Academic Domain Interactive Accent Morph
        const loginEmail = document.getElementById('loginEmail');
        const brandSubLead = document.getElementById('brandSubLead');

        function checkEmailBranding() {
            const emailVal = loginEmail.value.toLowerCase().trim();

            if (emailVal.includes('isap')) {
                document.body.setAttribute('data-theme', 'isap');
                brandSubLead.innerText = "International School of Asia & the Pacific";
            } else if (emailVal.includes('mcnp')) {
                document.body.setAttribute('data-theme', 'mcnp');
                brandSubLead.innerText = "Medical Colleges of Northern Philippines";
            } else {
                // Revert to original
                document.body.removeAttribute('data-theme');
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
        // Trigger splash screen mascot wave after a short delay
        window.addEventListener('load', () => {
            setTimeout(() => {
                const splashMascot = document.getElementById('splash-mascot');
                if (splashMascot) {
                    splashMascot.classList.add('splash-wave');
                }
            }, 1200); // Waits 1.2s before starting to tiptoe and wave
        });
    </script>
</body>

</html>