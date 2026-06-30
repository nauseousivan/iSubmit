<?php

/**
 * Recovery Services (forgot_password.php)
 * Real-time Password Reset Gateway for MCNP-ISAP
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db.php';
require_once '../config/mail.php';

// Ensure consistent timezone for OTP expiration checks
date_default_timezone_set('Asia/Manila');

$message = "";
$message_type = "";
$step = $_SESSION['reset_step'] ?? 'request';
$reset_email = $_SESSION['reset_email'] ?? '';

// Determine email under check and query their registered department from database
$check_branding_email = !empty($_POST['email']) ? trim($_POST['email']) : $reset_email;
$is_isap = false;

if (!empty($check_branding_email)) {
    $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE email = ? LIMIT 1");
    $stmt_dept->execute([$check_branding_email]);
    $user_row = $stmt_dept->fetch();
    if ($user_row) {
        $user_dept = $user_row['department'];
        $is_isap = (strpos(strtolower($user_dept), 'international school') !== false || strpos(strtolower($user_dept), 'isap') !== false);
    } else {
        $is_isap = (strpos(strtolower($check_branding_email), 'isap') !== false);
    }
}

$active_accent = $is_isap ? '#b91c1c' : '#1e40af';
$active_glow = $is_isap ? 'rgba(185, 28, 28, 0.12)' : 'rgba(30, 64, 175, 0.12)';
$school_name = $is_isap ? 'International School of Asia and the Pacific' : 'Medical Colleges of Northern Philippines';
$school_abbrev = $is_isap ? 'ISAP' : 'MCNP';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        $email = trim($_POST['email'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $otp_code = strval(rand(100000, 999999));

            // Wipe out existing OTP items for this account
            $stmt = $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?");
            $stmt->execute([$email]);

            $expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));
            $stmt = $pdo->prepare("INSERT INTO otp_verifications (email, otp_code, expires_at) VALUES (?, ?, ?)");

            if ($stmt->execute([$email, $otp_code, $expires_at])) {
                $user_dept = $user['department'] ?? '';
                $user_is_isap = (strpos(strtolower($user_dept), 'international school') !== false || strpos(strtolower($user_dept), 'isap') !== false);
                $brand_color = $user_is_isap ? '#b91c1c' : '#1e40af';
                $brand_abbrev = $user_is_isap ? 'ISAP' : 'MCNP';

                $email_body = '
                    <div style="font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f7f4eb; padding: 40px 10px; text-align: center; color: #2b261f;">
                        <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(12,52,61,0.06); border-top: 6px solid ' . $brand_color . '; padding: 40px; text-align: left;">
                            <div style="text-align: center; margin-bottom: 30px;">
                                <img src="mcnp-isap.png" alt="MCNP-ISAP" style="width: 75px; height: 75px; object-fit: contain;">
                                <h2 style="color: #0c343d; font-size: 22px; font-weight: bold; margin-top: 15px; margin-bottom: 5px; font-family: \'Georgia\', serif;">MCNP-ISAP Research Portal</h2>
                                <p style="color: #7d7569; font-size: 13px; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Security Recovery Service</p>
                            </div>
                            
                            <div style="border-bottom: 1.5px solid #eae5d9; padding-bottom: 20px; margin-bottom: 25px;">
                                <p style="font-size: 16px; line-height: 1.6; margin: 0; color: #2b261f;">
                                    Hello Researcher,
                                </p>
                                <p style="font-size: 15px; line-height: 1.6; margin-top: 10px; color: #4a453e;">
                                    We received an authorized request to reset your access code or security password for the <strong>' . $brand_abbrev . '</strong> Research Portal. Enter the verification code below:
                                </p>
                            </div>

                            <div style="text-align: center; margin: 30px 0;">
                                <div style="background-color: #f0fdf4; border: 1px dashed #6ee7b7; color: #047857; font-size: 34px; font-weight: bold; padding: 20px 30px; border-radius: 12px; display: inline-block; letter-spacing: 6px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                                    ' . $otp_code . '
                                </div>
                                <p style="color: #7d7569; font-size:12px; margin-top:12px; font-style:italic;">This unique session reset token will expire in exactly 15 minutes.</p>
                            </div>

                            <p style="color: #7d7569; font-size: 13px; line-height: 1.5; margin-bottom: 0;">
                                If you did not make this request, you can safely ignore this email. Your portal credentials remain secure.
                            </p>
                        </div>
                        <div style="text-align: center; margin-top: 25px;">
                            <p style="font-size: 11px; color: #7d7569; margin: 0; text-transform: uppercase; letter-spacing: 1px;">
                                Medical Colleges of Northern Philippines<br>
                                International School of Asia and the Pacific
                            </p>
                            <p style="font-size: 10px; color: #9d968b; margin-top: 6px;">© ' . date('Y') . ' MCNP-ISAP Research Office.</p>
                        </div>
                    </div>';

                if (sendSystemEmail($email, "Your Password Reset Code", $email_body)) {
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_step'] = 'verify';
                    header("Location: forgot_password.php");
                    exit();
                } else {
                    $message = "MTA failure occurred. Reset email could not be safely transmitted.";
                    $message_type = "error";
                }
            }
        } else {
            $message = "The email does not exist in our system.";
            $message_type = "error";
        }
    } elseif ($action === 'verify_code') {
        $entered_otp = trim($_POST['otp_code'] ?? '');
        $email = $_SESSION['reset_email'] ?? '';

        // Query the latest record timezone independenly on PHP side
        $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $entered_otp]);
        $row = $stmt->fetch();

        if ($row) {
            $expires_at = $row['expires_at'];
            if (strtotime($expires_at) < time()) {
                $message = "This verification token has expired. Request a new one.";
                $message_type = "error";
            } else {
                $_SESSION['reset_step'] = 'reset';
                header("Location: forgot_password.php");
                exit();
            }
        } else {
            $message = "The verification code you entered is incorrect.";
            $message_type = "error";
        }
    } elseif ($action === 'update_password') {
        $new_pass = $_POST['new_password'] ?? '';
        $conf_pass = $_POST['confirm_password'] ?? '';
        $email = $_SESSION['reset_email'] ?? '';

        if (strlen($new_pass) < 6) {
            $message = "For safety, passwords must be at least 6 characters.";
            $message_type = "error";
        } elseif ($new_pass === $conf_pass) {
            $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            if ($stmt->execute([$hashed_pass, $email])) {
                $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?")->execute([$email]);
                unset($_SESSION['reset_email']);
                $_SESSION['reset_step'] = 'success';
                header("Location: forgot_password.php");
                exit();
            }
        } else {
            $message = "Passwords do not match.";
            $message_type = "error";
        }
    } elseif ($action === 'restart') {
        unset($_SESSION['reset_step'], $_SESSION['reset_email']);
        header("Location: forgot_password.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Recovery | MCNP-ISAP Research Portal</title>
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

            /* Institutional Accents */
            --mcnp-blue: #1e40af;
            --isap-red: #b91c1c;
            --eagle-gold: #d97706;

            /* Workspace attributes inherited dynamically from corporate domain context */
            --active-accent: <?php echo $active_accent; ?>;
            --active-glow: <?php echo $active_glow; ?>;

            /* Sizing standards */
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

        /* Ambient glowing canvas node blurs */
        .ambient-sphere {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            pointer-events: none;
            z-index: 1;
            opacity: 0.12;
            animation: pulseGlow 10s infinite alternate cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ambient-sphere-1 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--active-accent) 0%, rgba(255, 255, 255, 0) 70%);
            top: -10%;
            left: -10%;
        }

        .ambient-sphere-2 {
            width: 600px;
            height: 600px;
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
                transform: scale(1.15) translate(20px, -20px);
                opacity: 0.15;
            }
        }

        /* Recovery Frame Wrapper */
        .forgot-frame {
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

        /* Branding Headings and Badges */
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
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.45;
            font-weight: 550;
        }

        /* Exquisite form design */
        .field-box {
            margin-bottom: 18px;
            text-align: left;
            position: relative;
            width: 100%;
        }

        .field-box label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
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
            padding: 15px 16px 15px 52px;
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            background-color: #f7f5ef;
            border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-interactive);
            outline: none;
            color: var(--text-primary);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-weight: 500;
            box-shadow: inset 0 2px 4px rgba(43, 38, 31, 0.02);
        }

        .field-box input::placeholder {
            color: var(--text-muted);
            opacity: 0.85;
        }

        .field-box input:focus {
            border-color: var(--active-accent);
            background-color: var(--bg-card);
            box-shadow:
                0 0 0 4px var(--active-glow),
                0 6px 16px -4px rgba(43, 38, 31, 0.04);
        }

        .field-box input:focus+.prefix-icon {
            color: var(--active-accent);
            transform: scale(1.05);
        }

        /* Password Show-Obscure toggle btn */
        .password-toggle-btn {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            padding: 6px;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .password-toggle-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--text-primary);
        }

        /* Solid styling for buttons */
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
            cursor: pointer;
            background: none;
            border: none;
            font-family: inherit;
        }

        .back-link:hover {
            color: var(--active-accent);
            transform: translateX(-2px);
        }

        /* Dynamic feedback toast alert */
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

        /* Phone adaptive styling */
        @media (max-width: 540px) {
            body {
                padding: 16px;
            }

            .forgot-frame {
                padding: 40px 24px;
                border-radius: var(--radius-interactive);
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

    <!-- Drifting organic blurs -->
    <div class="ambient-sphere ambient-sphere-1"></div>
    <div class="ambient-sphere ambient-sphere-2"></div>

    <div class="forgot-frame">

        <?php if (!empty($message)): ?>
            <div class="toast <?php echo $message_type; ?>">
                <i data-lucide="<?php echo ($message_type === 'error') ? 'alert-octagon' : 'check-circle'; ?>" style="width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px;"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- REQUEST CODE STATE -->
        <?php if ($step === 'request'): ?>
            <div class="badge-header-box">
                <div class="brand-icon-box">
                    <i data-lucide="key-round" style="width: 40px; height: 40px;"></i>
                </div>
            </div>
            <h2>Forgot Password</h2>
            <p>Enter your valid email address. A verification OTP will be sent to recover your password.</p>

            <form action="" method="POST">
                <input type="hidden" name="action" value="send_code">
                <div class="field-box">
                    <label>Account Email</label>
                    <div class="input-group-with-icon">
                        <i data-lucide="mail" class="prefix-icon" style="width: 18.5px; height: 18.5px;"></i>
                        <input type="email" name="email" id="recoveryEmail" placeholder="example@mcnp.edu.ph" required autocomplete="email">
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <span>Send Reset Code</span>
                    <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            <a href="login.php" class="back-link">
                <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                <span>Return to sign-in</span>
            </a>

            <!-- VERIFY OTP STATE -->
        <?php elseif ($step === 'verify'): ?>
            <div class="badge-header-box">
                <div class="brand-icon-box">
                    <i data-lucide="shield-question" style="width: 40px; height: 40px;"></i>
                </div>
            </div>
            <h2>Verify Code</h2>
            <p>A verification code was sent to<br><strong style="color: var(--active-accent);"><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong>.</p>

            <form action="" method="POST" autocomplete="off">
                <input type="hidden" name="action" value="verify_code">
                <div class="field-box">
                    <label>6-Digit Verification CODE</label>
                    <div class="input-group-with-icon">
                        <i data-lucide="key" class="prefix-icon" style="width: 18.5px; height: 18.5px;"></i>
                        <input type="text" name="otp_code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus style="text-align: center; font-size: 20px; letter-spacing: 4px;">
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <span>VERIFY</span>
                </button>
            </form>

            <form action="" method="POST">
                <input type="hidden" name="action" value="restart">
                <button type="submit" class="back-link">
                    <i data-lucide="refresh-cw" style="width: 15px; height: 15px;"></i>
                    <span>Use a different email</span>
                </button>
            </form>

            <!-- PASSWORD RESET STATE -->
        <?php elseif ($step === 'reset'): ?>
            <div class="badge-header-box">
                <div class="brand-icon-box">
                    <i data-lucide="lock" style="width: 40px; height: 40px;"></i>
                </div>
            </div>
            <h2>Create New Password</h2>
            <p>Verification successful! Create a brand new security password/PIN for your account.</p>

            <form action="" method="POST">
                <input type="hidden" name="action" value="update_password">
                <div class="field-box">
                    <label>New Security Passcode</label>
                    <div class="input-group-with-icon">
                        <i data-lucide="lock" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                        <input type="password" name="new_password" id="newPass" placeholder="••••••••" required autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('newPass', this)">
                            <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                        </button>
                    </div>
                </div>
                <div class="field-box" style="margin-bottom: 24px;">
                    <label>Confirm Passcode</label>
                    <div class="input-group-with-icon">
                        <i data-lucide="repeat" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                        <input type="password" name="confirm_password" id="confirmPass" placeholder="••••••••" required autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('confirmPass', this)">
                            <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-submit">
                    <span>CONFIRM</span>
                </button>
            </form>

            <!-- SUCCESS VIEW -->
        <?php elseif ($step === 'success'): ?>
            <div class="badge-header-box">
                <div class="brand-icon-box success-view">
                    <i data-lucide="shield-check" style="width: 44px; height: 44px;"></i>
                </div>
            </div>
            <h2 class="activated">Password Updated!</h2>
            <p>Your PIN has been successfully updated. You may now securely sign into your account.</p>
            <a href="login.php" class="btn-submit btn-success">
                <span>LOGIN</span>
            </a>
            <?php unset($_SESSION['reset_step']); ?>
        <?php endif; ?>
    </div>

    <div class="portal-institutional-footer">
        Medical Colleges of Northern Philippines
        <span class="footer-divider">|</span>
        <br>
        International School of Asia and the Pacific
    </div>

    <script>
        // Initialize dynamic Lucide icons
        lucide.createIcons();

        // Reveal/Obscure Password Inputs
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerHTML = '<i data-lucide="eye-off" style="width: 18px; height: 18px;"></i>';
            } else {
                input.type = 'password';
                btn.innerHTML = '<i data-lucide="eye" style="width: 18px; height: 18px;"></i>';
            }
            lucide.createIcons();
        }

        // Live Corporate Email Domain Color Theme Morph
        const recoveryEmail = document.getElementById('recoveryEmail');
        const root = document.documentElement;

        function refreshThemeBranding() {
            if (!recoveryEmail) return;
            const val = recoveryEmail.value.toLowerCase().trim();
            if (val.includes('isap')) {
                root.style.setProperty('--active-accent', '#b91c1c');
                root.style.setProperty('--active-glow', 'rgba(185, 28, 28, 0.12)');
                const badge = document.querySelector('.brand-icon-box');
                if (badge && !badge.classList.contains('success-view')) {
                    badge.style.color = '#b91c1c';
                }
            } else {
                root.style.setProperty('--active-accent', '#1e40af');
                root.style.setProperty('--active-glow', 'rgba(30, 64, 175, 0.12)');
                const badge = document.querySelector('.brand-icon-box');
                if (badge && !badge.classList.contains('success-view')) {
                    badge.style.color = '#1e40af';
                }
            }
        }

        if (recoveryEmail) {
            recoveryEmail.addEventListener('input', refreshThemeBranding);
            window.addEventListener('load', () => setTimeout(refreshThemeBranding, 150));
        }
    </script>
</body>

</html>