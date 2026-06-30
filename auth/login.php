<?php

/**
 * Clearance (login.php)
 * Real-time Institutional Access Portal for MCNP-ISAP
 */

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
    <title>MCNP-ISAP Portal Login</title>
    <!-- Premium Academic & Display Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            /* Color Palette */
            --bg-canvas: #fbfaf7;
            --bg-card: #ffffff;
            --text-primary: #1a1715;
            --text-secondary: #5c544d;
            --text-muted: #9c9284;
            --border-subtle: #eaddd0;

            /* School Brand Colors */
            --mcnp-blue: #1e40af;
            --mcnp-dark: #172554;
            --isap-red: #b91c1c;
            --isap-dark: #7f1d1d;
            --eagle-gold: #d97706;

            /* Current active accent */
            --active-accent: #1e40af;
            --active-glow: rgba(30, 64, 175, 0.12);

            /* Theme Radii */
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

        html,
        body {
            overflow: hidden; /* Lock both X and Y */
            width: 100%;
            height: 100vh;
            height: 100dvh;
            max-width: 100vw;
            overscroll-behavior-y: none; /* Prevent pull-to-refresh / bouncing */
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
            height: 100vh;
            height: 100dvh;
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
            overflow: hidden; /* Prevent scrolling completely */
        }

        /* Ambient glowing background nodes */
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
            background: radial-gradient(circle, var(--mcnp-blue) 0%, rgba(255, 255, 255, 0) 70%);
            top: -10%;
            left: -10%;
        }

        .ambient-sphere-2 {
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, var(--isap-red) 0%, rgba(255, 255, 255, 0) 70%);
            bottom: -15%;
            right: -10%;
            animation-delay: 3s;
        }

        @keyframes pulseGlow {
            0% {
                transform: scale(1) translate(0px, 0px);
                opacity: 0.1;
            }

            100% {
                transform: scale(1.15) translate(30px, -20px);
                opacity: 0.16;
            }
        }

        /* Framework Wrapper Frame */
        .portal-frame {
            background-color: var(--bg-card);
            width: 100%;
            max-width: 980px;
            min-height: 580px;
            border-radius: var(--radius-viewport);
            box-shadow:
                0 4px 6px -1px rgba(0, 0, 0, 0.01),
                0 25px 65px -15px rgba(43, 38, 31, 0.16),
                0 15px 30px -10px rgba(43, 38, 31, 0.08),
                inset 0 0 0 1px rgba(255, 255, 255, 0.6);
            overflow: hidden;
            display: flex;
            border: 1px solid var(--border-subtle);
            position: relative;
            z-index: 10;
            backdrop-filter: blur(8px);
            animation: frameEntrance 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
            animation-delay: 1s;
        }

        @keyframes frameEntrance {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Diagonal Hybrid Split Left Panel */
        .left-visual-pane {
            width: 44%;
            background: linear-gradient(135deg, var(--mcnp-blue) 0%, var(--mcnp-dark) 48%, var(--isap-red) 52%, var(--isap-dark) 100%);
            color: #ffffff;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Dynamic Class Overrides */
        .left-visual-pane.theme-mcnp {
            background: linear-gradient(135deg, var(--mcnp-blue) 0%, var(--mcnp-dark) 100%) !important;
        }

        .left-visual-pane.theme-isap {
            background: linear-gradient(135deg, var(--isap-red) 0%, var(--isap-dark) 100%) !important;
        }

        /* Fine mesh background detail */
        .left-visual-pane::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(rgba(255, 255, 255, 0.15) 1.2px, transparent 1.2px),
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 24px 24px, 12px 12px;
            opacity: 0.4;
            pointer-events: none;
            z-index: 1;
        }

        /* Diagonal slash divider to represent division in logo */
        .brand-lightning-slash {
            position: absolute;
            width: 150%;
            height: 10px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), var(--eagle-gold), rgba(255, 255, 255, 0.4), transparent);
            top: 50%;
            left: -25%;
            transform: rotate(-15deg);
            z-index: 2;
            pointer-events: none;
            opacity: 0.65;
            animation: sweepSlash 6s infinite ease-in-out;
        }

        @keyframes sweepSlash {
            0% {
                transform: translate(-30%, -30%) rotate(-15deg);
                opacity: 0.4;
            }

            50% {
                transform: translate(30%, 30%) rotate(-15deg);
                opacity: 0.8;
            }

            100% {
                transform: translate(-30%, -30%) rotate(-15deg);
                opacity: 0.4;
            }
        }

        .brand-badge-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 25px;
            position: relative;
            z-index: 3;
        }

        /* Core high-fidelity icon badge */
        .brand-icon-box {
            background: rgba(255, 255, 255, 0.11);
            backdrop-filter: blur(12px);
            padding: 20px;
            border-radius: 24px;
            border: 1.5px solid rgba(255, 255, 255, 0.25);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                0 15px 35px -10px rgba(0, 0, 0, 0.25),
                inset 0 1px 1px rgba(255, 255, 255, 0.3);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            animation: floatBadge 4s ease-in-out infinite alternate;
        }

        .brand-icon-box:hover {
            transform: scale(1.08) rotate(3deg);
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 20px 45px -5px rgba(0, 0, 0, 0.3);
        }

        @keyframes floatBadge {
            0% {
                transform: translateY(0px) rotate(0deg);
            }

            100% {
                transform: translateY(-8px) rotate(1deg);
            }
        }

        /* Glowing inner emblem status point */
        .brand-icon-box::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            background-color: var(--eagle-gold);
            border-radius: 50%;
            top: 10px;
            right: 10px;
            box-shadow: 0 0 10px var(--eagle-gold);
            animation: blinkGold 2s infinite;
        }

        @keyframes blinkGold {

            0%,
            100% {
                opacity: 0.5;
            }

            50% {
                opacity: 1;
            }
        }

        /* Academic Branding Text */
        .brand-text-container {
            position: relative;
            z-index: 3;
            margin: auto 0;
            padding: 10px 0;
        }

        .left-visual-pane h1 {
            font-family: 'Cambria', Georgia, serif;
            font-size: 26px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 16px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
            background: linear-gradient(to bottom, #ffffff, #f0eae1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .left-visual-pane .school-sub-lead {
            font-family: 'Cambria', Georgia, serif;
            font-style: italic;
            font-size: 14.5px;
            color: #fce8db;
            margin-bottom: 20px;
            opacity: 0.95;
            letter-spacing: 0.2px;
        }

        .left-visual-pane p {
            font-size: 13.5px;
            opacity: 0.85;
            line-height: 1.6;
            font-weight: 400;
            max-width: 320px;
            margin: 0 auto 30px auto;
            color: #faf6f0;
        }

        /* Home of the Mighty Eagles emblem label */
        .brand-eagle-banner {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.25);
            padding: 8px 16px;
            border-radius: var(--radius-pill);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fcedcf;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2);
            margin-bottom: 10px;
        }

        /* Action Nav Elements */
        .pane-action-footer {
            position: relative;
            z-index: 3;
            width: 100%;
            margin-top: auto;
        }

        .btn-to-register {
            align-self: center;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border: 1.5px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            padding: 13px 36px;
            font-size: 14px;
            font-weight: 600;
            border-radius: var(--radius-pill);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            max-width: 250px;
        }

        .btn-to-register:hover {
            background: #ffffff;
            color: var(--text-primary);
            border-color: #ffffff;
            transform: translateY(-2.5px);
            box-shadow:
                0 12px 30px rgba(0, 0, 0, 0.18),
                0 4px 10px rgba(0, 0, 0, 0.08);
        }

        /* Right Form Pane Design */
        .right-form-pane {
            width: 56%;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.7) 0%, rgba(254, 253, 251, 0.9) 100%);
        }

        .app-identity-wrapper {
            margin-bottom: 35px;
            animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes slideUpFade {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .app-identity-wrapper h2 {
            font-family: 'Cambria', Georgia, serif;
            font-size: 26px;
            color: var(--active-accent);
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            transition: color 0.4s ease;
        }

        .app-identity-wrapper .form-lead-desc {
            font-size: 14.5px;
            color: var(--text-secondary);
            font-weight: 550;
            line-height: 1.4;
        }

        .app-identity-wrapper .system-lead-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
            margin-bottom: 15px;
            display: block;
        }

        /* Exquisite Fields Framework */
        .field-box {
            margin-bottom: 22px;
            position: relative;
            width: 100%;
            animation: slideUpFade 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .field-box:nth-child(2) {
            animation-delay: 0.1s;
        }

        .field-box:nth-child(3) {
            animation-delay: 0.15s;
        }

        .field-box label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            transition: color 0.2s ease;
        }

        .input-group-with-icon {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        /* Modernized Elegant Sizing with Prefix Icons */
        .input-group-with-icon .prefix-icon {
            position: absolute;
            left: 18px;
            color: var(--text-muted);
            pointer-events: none;
            transition: all 0.3s ease;
            z-index: 5;
        }

        .field-box input {
            width: 100%;
            padding: 15px 16px 15px 52px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
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

        /* Highlight icon color on focus */
        .field-box input:focus+.prefix-icon {
            color: var(--active-accent);
            transform: scale(1.05);
        }

        /* Custom Hover Styles */
        .field-box input:hover:not(:focus) {
            border-color: #cbd5e1;
            background-color: #fcfbfa;
        }

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

        /* Account Recovery Elements */
        .recovery-line-box {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin-bottom: 28px;
            width: 100%;
            animation: slideUpFade 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
            animation-delay: 0.2s;
        }

        .forgot-password-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 600;
        }

        .forgot-password-link:hover {
            color: var(--active-accent);
            transform: translateX(-1px);
        }

        .forgot-password-link span:hover {
            text-decoration: underline;
        }

        /* Submit Button Elements */
        .btn-portal-submit {
            width: 100%;
            padding: 16px 24px;
            background-color: var(--active-accent);
            color: var(--bg-card);
            border: none;
            font-family: 'Inter', sans-serif;
            font-size: 15.5px;
            font-weight: 700;
            border-radius: var(--radius-interactive);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 10px 30px var(--active-glow);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            clear: both;
            position: relative;
            overflow: hidden;
            animation: slideUpFade 0.85s cubic-bezier(0.16, 1, 0.3, 1) both;
            animation-delay: 0.25s;
        }

        .btn-portal-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg,
                    transparent,
                    rgba(255, 255, 255, 0.15),
                    transparent);
            transition: 0.5s;
        }

        .btn-portal-submit:hover::before {
            left: 100%;
            transition: 0.8s ease-in-out;
        }

        .btn-portal-submit:hover {
            transform: translateY(-2px);
            opacity: 0.98;
            box-shadow: 0 12px 35px var(--active-glow);
        }

        .btn-portal-submit:active {
            transform: translateY(0);
        }

        /* Dynamic Visual Toast Notification */
        .toast-msg {
            padding: 14px 18px;
            border-radius: var(--radius-interactive);
            font-size: 13.5px;
            margin-bottom: 25px;
            font-weight: 600;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.45;
            animation: slideDownIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            z-index: 12;
            border-left: 4px solid transparent;
        }

        @keyframes slideDownIn {
            from {
                transform: translateY(-15px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .msg-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-left-color: #dc2626;
        }

        .msg-success {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-left-color: #16a34a;
        }

        /* High contrast footer information */
        .footer-line {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            line-height: 1.7;
            font-weight: 600;
            position: relative;
            z-index: 10;
        }

        .footer-line br {
            display: none;
        }

        .footer-line .institutional-divider {
            content: "•";
            display: inline-block;
            margin: 0 8px;
            color: var(--text-muted);
        }

        .mobile-navigator-link {
            display: none;
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 600;
            font-family: 'Inter', sans-serif;
        }

        .mobile-navigator-link a {
            color: var(--active-accent);
            text-decoration: none;
            font-weight: 700;
            margin-left: 5px;
            transition: opacity 0.2s;
        }

        .mobile-navigator-link a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        /* Responsive Breakpoints Rules */
        @media (max-width: 900px) {
            body {
                padding: 16px;
                /* overflow-y removed to keep body locked */
            }

            .portal-frame {
                flex-direction: column;
                max-width: 500px;
                height: 100%;
                max-height: 100%;
                overflow-y: auto;
                border-radius: var(--radius-interactive);
            }

            .left-visual-pane {
                width: 100%;
                padding: 45px 30px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }

            .right-form-pane {
                width: 100%;
                padding: 40px 30px;
            }

            .footer-line br {
                display: block;
            }

            .footer-line .institutional-divider {
                display: none;
            }

            .mobile-navigator-link {
                display: block;
            }
        }

        @media (max-width: 580px) {
            body {
                padding: 0;
                background-color: var(--bg-canvas);
            }

            .portal-frame {
                border-radius: 0;
                box-shadow: none;
                width: 100%;
                min-height: 100vh;
                margin: 0;
                border: none;
            }

            .left-visual-pane {
                display: none !important;
            }

            .right-form-pane {
                width: 100%;
                padding: 40px 24px;
                min-height: 100vh;
                justify-content: center;
                border-radius: 0;
                background: var(--bg-white);
            }

            .footer-line {
                margin: 20px 0;
                padding: 0 16px;
            }
        }

        /* Skip transition animations and delay on repeat session loads */
        body.no-intro-delay .portal-frame {
            animation-delay: 0s !important;
        }

        body.no-intro-delay .intro-cinematic-curtain {
            display: none !important;
        }

        /* Cinematic Eagle Fly-By & Page Turn Transition CSS */
        .intro-cinematic-curtain {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100vh;
            background-color: var(--bg-canvas);
            /* Beige background */
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeOutCurtain 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 2.2s;
            /* Wait for progress bar to finish */
        }

        .isubmit-loader-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 24px;
        }

        .isubmit-logo {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 64px;
            font-weight: 800;
            color: #1d1d1f;
            letter-spacing: -2.5px;
            margin: 0;
            text-align: center;
        }

        @media (max-width: 580px) {
            .isubmit-logo {
                font-size: 48px;
                letter-spacing: -1.5px;
            }
        }

        @media (min-width: 581px) {
            .intro-cinematic-curtain {
                background-image:
                    radial-gradient(#e0dbc8 1.5px, transparent 1.5px),
                    linear-gradient(to right, rgba(0, 0, 0, 0.02) 1px, transparent 1px),
                    linear-gradient(to bottom, rgba(0, 0, 0, 0.02) 1px, transparent 1px);
                background-size: 32px 32px, 128px 128px, 128px 128px;
                background-position: center;
            }
        }

        .isubmit-progress-bar {
            width: 200px;
            height: 5px;
            background-color: rgba(0, 0, 0, 0.08);
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }

        .isubmit-progress-fill {
            height: 100%;
            background-color: #1d1d1f;
            width: 0%;
            border-radius: 8px;
            animation: loadProgress 1.8s cubic-bezier(0.65, 0, 0.35, 1) forwards;
        }

        @keyframes loadProgress {
            0% {
                width: 0%;
            }

            40% {
                width: 45%;
            }

            70% {
                width: 75%;
            }

            100% {
                width: 100%;
            }
        }

        @keyframes fadeOutCurtain {
            0% {
                opacity: 1;
                visibility: visible;
            }

            100% {
                opacity: 0;
                visibility: hidden;
            }
        }
    </style>
</head>

<body class="no-intro-delay">
    <script>
        // Check if the user has already played the intro during this session
        if (!sessionStorage.getItem('mcnp_isap_intro_played')) {
            document.body.classList.remove('no-intro-delay');
            sessionStorage.setItem('mcnp_isap_intro_played', 'true');
        }
    </script>

    <!-- Apple/macOS-style iSubmit Loading Curtain -->
    <div class="intro-cinematic-curtain" id="introCurtain">
        <div class="isubmit-loader-container">
            <h1 class="isubmit-logo">iSubmit</h1>
            <div class="isubmit-progress-bar">
                <div class="isubmit-progress-fill"></div>
            </div>
        </div>
    </div>

    <!-- Ambient glowing backgrounds -->
    <div class="ambient-sphere ambient-sphere-1"></div>
    <div class="ambient-sphere ambient-sphere-2"></div>

    <div class="portal-frame">

        <!-- Interactive Brand Diagonal Screen -->
        <div class="left-visual-pane" id="brandOverlay">
            <!-- Majestic Eagle Watermark Background Silhouette -->
            <div class="eagle-watermark-bg">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round" style="width:100%; height:100%;">
                    <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" stroke-dasharray="2 2" stroke-opacity="0.3" />
                    <path d="M12 6c-1.5 1-3.5 1.5-6 1.5 2.5 2.5 4.5 4 6 5.5 1.5-1.5 3.5-3 6-5.5-2.5 0-4.5-.5-6-1.5z" fill="currentColor" fill-opacity="0.08" stroke-width="0.5" />
                    <path d="M12 11.5c-.8.8-1.8 1.2-3 1.2.8.8 1.5 1.8 1.8 2.8.3-1 .8-2 1.8-2.8z" fill="currentColor" fill-opacity="0.12" />
                    <path d="M4.5 9.5c.8 1 2 2 3.5 2.5C7 11.5 6 10.5 4.5 9.5z" fill="currentColor" fill-opacity="0.06" />
                    <path d="M19.5 9.5c-.8 1-2 2-3.5 2.5 1-.5 2-1.5 3.5-2.5z" fill="currentColor" fill-opacity="0.06" />
                </svg>
            </div>

            <div class="brand-lightning-slash"></div>

            <div class="brand-badge-container">
                <div class="brand-icon-box">
                    <i data-lucide="graduation-cap" style="width: 40px; height: 40px;"></i>
                </div>
            </div>

            <div class="brand-text-container">
                <div class="brand-eagle-banner">
                    <i data-lucide="feather" style="width: 12px; height: 12px; stroke-width: 3;"></i>
                    <span>Home of Mighty Eagles</span>
                </div>
                <h1 id="brandTitle">MCNP-ISAP Portal</h1>
                <div class="school-sub-lead" id="brandSubLead">Dual Campus Research Gateway</div>
                <p>Submit research documents, track consultation progress, and stay updated throughout every stage of your research journey.</p>
            </div>

            <div class="pane-action-footer">
                <div style="font-size: 13.5px; opacity: 0.8; margin-bottom: 14px; font-weight: 600; color: #fdfaf6;">First time using the research portal?</div>
                <a href="register.php" class="btn-to-register">
                    <span>Create Account</span>
                    <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i>
                </a>
            </div>
        </div>

        <!--  Login Form Section -->
        <div class="right-form-pane">
            <div class="app-identity-wrapper">
                <span class="system-lead-label">Digitalization of Research Process</span>
                <h2 id="formHeader">Login Access</h2>
                <p class="form-lead-desc">Welcome back! Please sign in.</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="toast-msg <?php echo ($message_type === 'error') ? 'msg-error' : 'msg-success'; ?>">
                    <i data-lucide="info" style="width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px;"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="on">
                <input type="hidden" name="auth_action" value="login">

                <div class="field-box">
                    <label>Account Email</label>
                    <div class="input-group-with-icon">
                        <input type="email" name="email" id="loginEmail" placeholder="example@mcnp.edu.ph" required autocomplete="username">
                        <i data-lucide="mail" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                    </div>
                </div>

                <div class="field-box">
                    <label>Security Password / PIN</label>
                    <div class="input-group-with-icon">
                        <input type="password" name="password" id="loginPass" placeholder="••••••••" required autocomplete="current-password">
                        <i data-lucide="lock" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('loginPass', this)">
                            <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                        </button>
                    </div>
                </div>

                <div class="recovery-line-box">
                    <a href="forgot_password.php" class="forgot-password-link">
                        <i data-lucide="key-round" style="width: 13px; height: 13px;"></i>
                        <span>Forgot password?</span>
                    </a>
                </div>

                <button type="submit" class="btn-portal-submit">
                    <span>LOGIN</span>
                </button>

                <div class="mobile-navigator-link">
                    First time using the portal? <a href="register.php">Create Account</a>
                </div>
            </form>
        </div>

    </div>

    <div class="footer-line">
        Medical Colleges of Northern Philippines
        <span class="institutional-divider">|</span>
        <br>
        International School of Asia and the Pacific
    </div>

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
                btn.innerHTML = '<i data-lucide="eye-off" style="width: 18px; height: 18px;"></i>';
            } else {
                input.type = 'password';
                btn.innerHTML = '<i data-lucide="eye" style="width: 18px; height: 18px;"></i>';
            }
            lucide.createIcons();
        }

        // Real-time Academic Domain Interactive Accent Morph
        const loginEmail = document.getElementById('loginEmail');
        const brandOverlay = document.getElementById('brandOverlay');
        const brandTitle = document.getElementById('brandTitle');
        const brandSubLead = document.getElementById('brandSubLead');
        const formHeader = document.getElementById('formHeader');
        const root = document.documentElement;

        function checkEmailBranding() {
            const emailVal = loginEmail.value.toLowerCase().trim();
            if (emailVal.includes('isap')) {
                brandOverlay.className = "left-visual-pane theme-isap";
                brandTitle.innerText = "ISAP Portal";
                brandSubLead.innerText = "International School of Asia & the Pacific";
                if (formHeader) formHeader.style.color = '#b91c1c';
                root.style.setProperty('--active-accent', '#b91c1c');
                root.style.setProperty('--active-glow', 'rgba(185, 28, 28, 0.12)');

                // Update badge icon to graduation cap/book for ISAP
                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="book-open" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            } else if (emailVal.includes('mcnp')) {
                brandOverlay.className = "left-visual-pane theme-mcnp";
                brandTitle.innerText = "MCNP Portal";
                brandSubLead.innerText = "Medical Colleges of Northern Philippines";
                if (formHeader) formHeader.style.color = '#1e40af';
                root.style.setProperty('--active-accent', '#1e40af');
                root.style.setProperty('--active-glow', 'rgba(30, 64, 175, 0.12)');

                // Update badge icon for MCNP
                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="shield" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            } else {
                brandOverlay.className = "left-visual-pane";
                brandTitle.innerText = "MCNP-ISAP Portal";
                brandSubLead.innerText = "Dual Campus Research Gateway";
                if (formHeader) formHeader.style.color = '#1c1917';
                root.style.setProperty('--active-accent', '#1e40af');
                root.style.setProperty('--active-glow', 'rgba(30, 64, 175, 0.12)');

                // Update badge icon to dual logo graduation cap
                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="graduation-cap" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            }
        }

        if (loginEmail) {
            loginEmail.addEventListener('input', checkEmailBranding);
            // Timeout load check for browser cached credentials
            window.addEventListener('load', () => setTimeout(checkEmailBranding, 150));
        }
    </script>
</body>

</html>