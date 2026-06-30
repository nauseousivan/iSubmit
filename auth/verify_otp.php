<?php

/**
 * OTP Verification Portal (verify_otp.php)
 * High-Fidelity Verification Workspace for MCNP-ISAP
 */

require_once '../config/db.php';
require_once '../config/mail.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure timezone consistency for the 15-minute expiration check
date_default_timezone_set('Asia/Manila');

$message = "";
$message_type = "";
$verify_success = $_SESSION['verify_success'] ?? false;

// Handle Success message from resend redirect
if (isset($_GET['resend_success'])) {
    $message = "New code has been sent to your email.";
    $message_type = "success";
}

if (!isset($_SESSION['verify_email']) && !$verify_success) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['verify_email'] ?? '';
$user_department = '';

if ($email) {
    $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE email = ? LIMIT 1");
    $stmt_dept->execute([$email]);
    $user_row = $stmt_dept->fetch();
    if ($user_row) {
        $user_department = $user_row['department'];
    }
}

// Fallback to success department if verification was just completed
if (empty($user_department) && isset($_SESSION['verified_department'])) {
    $user_department = $_SESSION['verified_department'];
}

// Determine is_isap based on user department first, with email domain as fallback
$is_isap = false;
if (!empty($user_department)) {
    $is_isap = (strpos(strtolower($user_department), 'international school') !== false || strpos(strtolower($user_department), 'isap') !== false);
} else {
    $is_isap = (strpos(strtolower($email), 'isap') !== false);
}

$active_accent = $is_isap ? '#b91c1c' : '#1e40af';
$active_glow = $is_isap ? 'rgba(185, 28, 28, 0.12)' : 'rgba(30, 64, 175, 0.12)';
$active_theme_class = $is_isap ? 'theme-isap' : 'theme-mcnp';
$school_name = $is_isap ? 'International School of Asia and the Pacific' : 'Medical Colleges of Northern Philippines';
$school_abbrev = $is_isap ? 'ISAP' : 'MCNP';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Resend code handler Action
    if (isset($_POST['resend_code'])) {
        if ($email) {
            $otp_code = strval(rand(100000, 999999));

            // Delete previous OTP codes to prevent cluttering
            $stmt_del = $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?");
            $stmt_del->execute([$email]);

            $stmt = $pdo->prepare("INSERT INTO otp_verifications (email, otp_code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
            $stmt->execute([$email, $otp_code]);

            $email_body = '
                <div style="font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f7f4eb; padding: 40px 10px; text-align: center; color: #2b261f;">
                    <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(12,52,61,0.06); border-top: 6px solid ' . $active_accent . '; padding: 40px; text-align: left;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <img src="https://isap.edu.ph/wp-content/uploads/2022/07/ISAP-LOGO-2022.png" alt="MCNP-ISAP Logo" style="width: 75px; height: 75px; object-fit: contain;">
                            <h2 style="color: #0c343d; font-size: 22px; font-weight: bold; margin-top: 15px; margin-bottom: 5px; font-family: \'Georgia\', serif;">MCNP-ISAP Research Portal</h2>
                            <p style="color: #7d7569; font-size: 13px; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Institutional Verification Service</p>
                        </div>
                        
                        <div style="border-bottom: 1.5px solid #eae5d9; padding-bottom: 20px; margin-bottom: 25px;">
                            <p style="font-size: 16px; line-height: 1.6; margin: 0; color: #2b261f;">
                                Hello Academic Researcher,
                            </p>
                            <p style="font-size: 15px; line-height: 1.6; margin-top: 10px; color: #4a453e;">
                                A new 6-digit confirmation security code was requested for your <strong>' . $school_abbrev . '</strong> portal registration. Keep this credential strictly confidential. Use the code below:
                            </p>
                        </div>

                        <div style="text-align: center; margin: 30px 0;">
                            <div style="background-color: #f0fdf4; border: 1px dashed #6ee7b7; color: #047857; font-size: 34px; font-weight: bold; padding: 20px 30px; border-radius: 12px; display: inline-block; letter-spacing: 6px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                                ' . $otp_code . '
                            </div>
                            <p style="color: #7d7569; font-size:12px; margin-top:12px; font-style:italic;">This code will expire in exactly 15 minutes.</p>
                        </div>

                        <p style="color: #7d7569; font-size: 13px; line-height: 1.5; margin-bottom: 0;">
                            If you did not initiate this activation request, please secure your administrative records immediately.
                        </p>
                    </div>
                    <div style="text-align: center; margin-top: 25px;">
                        <p style="font-size: 11px; color: #7d7569; margin: 0; text-transform: uppercase; letter-spacing: 1px;">
                            Medical Colleges of Northern Philippines<br>
                            International School of Asia and the Pacific
                        </p>
                        <p style="font-size: 10px; color: #9d968b; margin-top: 6px;">© ' . date('Y') . ' MCNP-ISAP Institutional Research Commission.</p>
                    </div>
                </div>';

            if (sendSystemEmail($email, "Your Portal Verification Code", $email_body)) {
                header("Location: verify_otp.php?resend_success=1");
                exit();
            } else {
                $message = "Institutional MTA failed to transmit OTP packet. Review configuration.";
                $message_type = "error";
            }
        }
    }
    // Activation handler (Standard POST)
    elseif (isset($_POST['otp_code'])) {
        $entered_otp = trim($_POST['otp_code']);

        // Verify code against latest record that isn\'t expired
        $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $entered_otp]);
        $row = $stmt->fetch();

        if (!$row) {
            $message = "The confirmation credentials entered are invalid. Verify and try again.";
            $message_type = "error";
        } else {
            $expires_at = $row['expires_at'];
            if (strtotime($expires_at) < time()) {
                $message = "The activation key has expired. Request a temporary token replacement.";
                $message_type = "error";
            } else {
                // Get user department first to persist styling on success screen
                $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE email = ? LIMIT 1");
                $stmt_dept->execute([$email]);
                $user_row = $stmt_dept->fetch();
                $dept_val = $user_row ? $user_row['department'] : '';

                // Activate user
                $up = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE email = ?");
                $up->execute([$email]);

                // Clean up verification tokens
                $del = $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?");
                $del->execute([$email]);

                $_SESSION['verify_success'] = true;
                $_SESSION['verified_department'] = $dept_val;
                unset($_SESSION['verify_email']);
                header("Location: verify_otp.php");
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Registration | MCNP-ISAP Research Portal</title>
    <!-- Display & Sans Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
    <!-- Lucide Dynamic Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            /* Palette Setup */
            --bg-canvas: #fbfaf7;
            --bg-card: #ffffff;
            --text-primary: #1a1715;
            --text-secondary: #5c544d;
            --text-muted: #9c9284;
            --border-subtle: #eaddd0;

            /* Institutional Colors */
            --mcnp-blue: #1e40af;
            --isap-red: #b91c1c;
            --eagle-gold: #d97706;

            /* Workspace settings inherited dynamically from PHP domain context */
            --active-accent: <?php echo $active_accent; ?>;
            --active-glow: <?php echo $active_glow; ?>;

            /* Radii standard shapes */
            --radius-viewport: 24px;
            --radius-interactive: 14px;
            --radius-pill: 50px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        /* Custom premium research pencil/pen cursor */
        body,
        input,
        select,
        button,
        textarea,
        a,
        span,
        div,
        label,
        p,
        h1,
        h2,
        h3,
        i {
            cursor: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%231c1917' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M12 20h9'/><path d='M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z'/></svg>") 3 19, auto;
            transition: background-color 0.3s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        input:focus,
        select:focus,
        textarea:focus {
            cursor: text;
        }

        button,
        a,
        select,
        option,
        .btn-wizard,
        .password-toggle-btn,
        .back-link,
        .btn-to-login,
        .btn-to-register,
        .btn-portal-submit,
        [role='button'],
        .add-member-trigger,
        .member-pill i,
        .prog-step {
            cursor: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23d97706' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M12 20h9'/><path d='M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z'/></svg>") 3 19, pointer !important;
        }

        /* Eagle watermark silhouette definition */
        .eagle-watermark-bg {
            position: absolute;
            top: 55%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 250px;
            height: 250px;
            pointer-events: none;
            z-index: 1;
            opacity: 0.05;
            color: #ffffff;
            transition: color 0.6s ease, transform 0.6s ease;
            animation: eagleDrift 30s infinite alternate ease-in-out;
        }

        @keyframes eagleDrift {
            0% {
                transform: translate(-50%, -50%) rotate(0deg) scale(0.95);
            }

            100% {
                transform: translate(-47%, -53%) rotate(5deg) scale(1.05);
            }
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(#e0dbc8 1.5px, transparent 1.5px),
                linear-gradient(to right, rgba(0, 0, 0, 0.02) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(0, 0, 0, 0.02) 1px, transparent 1px);
            background-size: 32px 32px, 128px 128px, 128px 128px;
            background-position: center;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient flowing canvas background filters */
        .ambient-sphere {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            pointer-events: none;
            z-index: 1;
            opacity: 0.12;
            animation: pulseGlow 8s infinite alternate cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ambient-sphere-1 {
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, var(--active-accent) 0%, rgba(255, 255, 255, 0) 70%);
            top: -10%;
            left: -10%;
        }

        .ambient-sphere-2 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--eagle-gold) 0%, rgba(255, 255, 255, 0) 70%);
            bottom: -15%;
            right: -10%;
            animation-delay: 3s;
        }

        @keyframes pulseGlow {
            0% {
                transform: scale(1) translate(0px, 0px);
                opacity: 0.08;
            }

            100% {
                transform: scale(1.15) translate(15px, -15px);
                opacity: 0.14;
            }
        }

        /* Verification Card Frame wrapping */
        .verify-frame {
            background-color: var(--bg-card);
            width: 100%;
            max-width: 480px;
            border-radius: var(--radius-viewport);
            box-shadow:
                0 4px 6px -1px rgba(0, 0, 0, 0.01),
                0 25px 65px -15px rgba(43, 38, 31, 0.16),
                0 15px 30px -10px rgba(43, 38, 31, 0.08),
                inset 0 0 0 1px rgba(255, 255, 255, 0.6);
            border: 1px solid var(--border-subtle);
            padding: 50px 40px;
            position: relative;
            z-index: 10;
            backdrop-filter: blur(8px);
            text-align: center;
            animation: cardEntrance 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Branding Badges and Logos */
        .badge-header-box {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 24px;
            position: relative;
        }

        .brand-icon-box {
            background: rgba(247, 245, 239, 0.7);
            padding: 16px;
            border-radius: 20px;
            border: 1.5px solid var(--border-subtle);
            color: var(--active-accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(43, 38, 31, 0.04);
            animation: bounceFloat 4s ease-in-out infinite alternate;
        }

        .brand-icon-box.success-view {
            color: #16a34a;
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        @keyframes bounceFloat {
            0% {
                transform: translateY(0);
            }

            100% {
                transform: translateY(-5px);
            }
        }

        h2 {
            font-family: 'Cambria', Georgia, serif;
            font-size: 24px;
            color: var(--text-primary);
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        h2.activated {
            color: #15803d;
        }

        p {
            font-size: 14.5px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.5;
            font-weight: 500;
        }

        p.success-desc {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Premium Form fields input design */
        .field-box {
            margin-bottom: 20px;
            width: 100%;
        }

        .input-group-with-icon {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .input-group-with-icon .prefix-icon {
            position: absolute;
            left: 18px;
            color: var(--text-muted);
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 5;
        }

        .field-box input {
            width: 100%;
            padding: 16px 16px 16px 52px;
            font-family: 'Inter', sans-serif;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 4px;
            text-align: center;
            background-color: #f7f5ef;
            border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-interactive);
            outline: none;
            color: var(--text-primary);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: inset 0 2px 4px rgba(43, 38, 31, 0.02);
        }

        .field-box input::placeholder {
            letter-spacing: normal;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-muted);
            opacity: 0.7;
        }

        .field-box input:focus {
            border-color: var(--active-accent);
            background-color: var(--bg-card);
            box-shadow:
                0 0 0 4px var(--active-glow),
                0 6px 16px rgba(43, 38, 31, 0.04);
        }

        .field-box input:focus+.prefix-icon {
            color: var(--active-accent);
            transform: scale(1.05);
        }

        /* Solid buttons design */
        .btn-submit {
            width: 100%;
            padding: 16px 24px;
            background-color: var(--active-accent);
            color: #ffffff;
            border: none;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 700;
            border-radius: var(--radius-interactive);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 8px 24px var(--active-glow);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            opacity: 0.98;
            box-shadow: 0 10px 30px var(--active-glow);
        }

        .btn-submit.btn-success {
            background-color: #16a34a;
            box-shadow: 0 8px 24px rgba(22, 163, 74, 0.2);
        }

        .btn-submit.btn-success:hover {
            box-shadow: 0 10px 30px rgba(22, 163, 74, 0.3);
        }

        /* Subtle gray buttons for secondary actions like resend code */
        .btn-resend {
            width: 100%;
            padding: 14px 20px;
            background-color: transparent;
            color: var(--text-secondary);
            border: 1.5px solid var(--border-subtle);
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: var(--radius-interactive);
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-resend:hover {
            background-color: rgba(43, 38, 31, 0.03);
            border-color: var(--text-muted);
            color: var(--text-primary);
        }

        .btn-resend i {
            transition: transform 0.4s ease;
        }

        .btn-resend:hover i {
            transform: rotate(180deg);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 700;
            transition: all 0.2s;
        }

        .back-link:hover {
            color: var(--active-accent);
            transform: translateX(-2px);
        }

        /* Dynamic Visual Toast Alert */
        .toast {
            padding: 12px 16px;
            border-radius: var(--radius-interactive);
            font-size: 13.5px;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.45;
            text-align: left;
            animation: slideDown 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            border-left: 4px solid transparent;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-12px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .toast.error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-left-color: #dc2626;
        }

        .toast.success {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-left-color: #16a34a;
        }

        .portal-institutional-footer {
            margin-top: 35px;
            text-align: center;
            font-size: 10px;
            color: var(--text-secondary);
            letter-spacing: 1.2px;
            line-height: 1.7;
            text-transform: uppercase;
            font-weight: 700;
            position: relative;
            z-index: 10;
        }

        .portal-institutional-footer br {
            display: none;
        }

        .portal-institutional-footer .footer-divider {
            content: "•";
            display: inline-block;
            margin: 0 8px;
            color: var(--text-muted);
        }

        /* Ultimate Phone & Tablet Adaptive Constraints */
        @media (max-width: 540px) {
            body {
                padding: 16px;
            }

            .verify-frame {
                padding: 40px 24px;
                border-radius: var(--radius-interactive);
            }

            .field-box input {
                font-size: 20px;
                letter-spacing: 3px;
                padding: 14px 14px 14px 44px;
            }

            .portal-institutional-footer br {
                display: block;
            }

            .portal-institutional-footer .footer-divider {
                display: none;
            }
        }
    </style>
</head>

<body>

    <!-- Majestic Eagle Watermark Background Silhouette -->
    <div class="eagle-watermark-bg" style="top: 50%; opacity: 0.03; z-index: 1;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round" style="width:100%; height:100%;">
            <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" stroke-dasharray="2 2" stroke-opacity="0.3" />
            <path d="M12 6c-1.5 1-3.5 1.5-6 1.5 2.5 2.5 4.5 4 6 5.5 1.5-1.5 3.5-3 6-5.5-2.5 0-4.5-.5-6-1.5z" fill="currentColor" fill-opacity="0.08" stroke-width="0.5" />
            <path d="M12 11.5c-.8.8-1.8 1.2-3 1.2.8.8 1.5 1.8 1.8 2.8.3-1 .8-2 1.8-2.8z" fill="currentColor" fill-opacity="0.12" />
            <path d="M4.5 9.5c.8 1 2 2 3.5 2.5C7 11.5 6 10.5 4.5 9.5z" fill="currentColor" fill-opacity="0.06" />
            <path d="M19.5 9.5c-.8 1-2 2-3.5 2.5 1-.5 2-1.5 3.5-2.5z" fill="currentColor" fill-opacity="0.06" />
        </svg>
    </div>

    <!-- Ambient organic drift filters -->
    <div class="ambient-sphere ambient-sphere-1"></div>
    <div class="ambient-sphere ambient-sphere-2"></div>

    <div class="verify-frame">

        <?php if ($verify_success): ?>
            <!-- Verification Accomplished success view -->
            <div class="badge-header-box">
                <div class="brand-icon-box success-view">
                    <i data-lucide="shield-check" style="width: 44px; height: 44px;"></i>
                </div>
            </div>
            <h2 class="activated">Account Verified!</h2>
            <p class="success-desc">Success! Your account have been activated under <strong><?php echo htmlspecialchars($school_name); ?></strong>. The Research Office is ready to monitor your paper submissions.</p>
            <a href="login.php" class="btn-submit btn-success">
                <span>LOG IN</span>
            </a>
            <?php unset($_SESSION['verify_success']); ?>

        <?php else: ?>
            <!-- Verification pending dynamic form view -->
            <div class="badge-header-box">
                <div class="brand-icon-box">
                    <i data-lucide="mail-search" style="width: 40px; height: 40px;"></i>
                </div>
            </div>
            <h2>Verify Your Account</h2>
            <p>A 6-digit verification code has been sent to <br><b style="color: var(--active-accent); font-weight: 700;"><?= htmlspecialchars($email) ?></b>.<br> Enter your credentials to register.</p>

            <?php if (!empty($message)): ?>
                <div class="toast <?php echo $message_type; ?>">
                    <i data-lucide="<?php echo ($message_type === 'error') ? 'alert-octagon' : 'check-circle'; ?>" style="width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px;"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="field-box">
                    <div class="input-group-with-icon">
                        <i data-lucide="key-round" class="prefix-icon" style="width: 20px; height: 20px;"></i>
                        <input type="text" name="otp_code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="one-time-code">
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <span>CONFIRM</span>
                </button>
            </form>

            <form method="POST">
                <button type="submit" name="resend_code" class="btn-resend">
                    <i data-lucide="refresh-cw" style="width: 16px; height: 16px;"></i>
                    <span>Resend Code</span>
                </button>
            </form>

            <a href="login.php" class="back-link">
                <i data-lucide="chevron-left" style="width: 16px; height: 16px;"></i>
                <span>Return to sign-in</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="portal-institutional-footer">
        Medical Colleges of Northern Philippines
        <span class="footer-divider">|</span>
        <br>
        International School of Asia and the Pacific
    </div>

    <script>
        // Initialize dynamic beautiful Lucide icons
        lucide.createIcons();
    </script>
</body>

</html>