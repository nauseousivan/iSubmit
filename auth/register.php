<?php

/**
 * Academic Registry (register.php)
 * Real-time Academic Milestone & Research Gateway for MCNP-ISAP
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure consistent timezone for OTP generation to prevent immediate expiry
date_default_timezone_set('Asia/Manila');

// Require institutional configuration hooks
require_once '../config/db.php';
require_once '../config/mail.php';

$message = "";
$message_type = "error"; // "error" or "success"

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action']) && $_POST['auth_action'] === 'register') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);
    $role = $_POST['role'] ?? 'Student';
    $department = $_POST['department'] ?? null;
    $program = $_POST['program'] ?? null;
    $group_name = ($role === 'Student') ? trim($_POST['group_name'] ?? '') : null;
    $group_members_list = ($role === 'Student') ? trim($_POST['group_members_list'] ?? '') : null;
    $is_group_leader = isset($_POST['is_group_leader']) && $_POST['is_group_leader'] === 'yes';
    $leader_email = trim($_POST['leader_email'] ?? '');
    $leader_id = null;

    // STUDENT LEADER ACCOUNT LINKING WORKFLOW
    if ($role === 'Student' && !$is_group_leader) {
        if (empty($leader_email)) {
            $message = "Please specify your designated Student Group Leader's email.";
            $message_type = "error";
            goto end_registration;
        }

        // Find leader in DB
        $stmt_leader = $pdo->prepare("SELECT user_id, research_group_name, department, program FROM users WHERE email = ? AND role = 'Student' AND leader_id IS NULL");
        $stmt_leader->execute([$leader_email]);
        $leader_info = $stmt_leader->fetch();

        if ($leader_info) {
            $leader_id = $leader_info['user_id'];
            $group_name = $leader_info['research_group_name']; // Inherit research group
            $department = $leader_info['department'];          // Inherit department
            $program = $leader_info['program'];                // Inherit study course
        } else {
            $message = "Leader email not found or the user is not registered as a primary student leader.";
            $message_type = "error";
            goto end_registration;
        }
    }

    // Verify institutional email uniqueness
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->rowCount() > 0) {
        $message = "The email is already linked with another active portal record.";
        $message_type = "error";
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, department, program, research_group_name, leader_id, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
        if ($stmt->execute([$username, $password, $email, $role, $department, $program, $group_name, $leader_id])) {
            $new_user_id = $pdo->lastInsertId();

            // Insert temporary member names if leader
            if ($role === 'Student' && $is_group_leader && !empty($group_members_list)) {
                $members_array = explode(',', $group_members_list);
                $member_stmt = $pdo->prepare("INSERT INTO research_group_members (owner_user_id, member_name) VALUES (?, ?)");
                foreach ($members_array as $m_name) {
                    $m_trimmed = trim($m_name);
                    if (!empty($m_trimmed)) {
                        $member_stmt->execute([$new_user_id, $m_trimmed]);
                    }
                }
            }

            // Set up secure verification OTP
            $otp_code = strval(rand(100000, 999999));
            $expires_at = date("Y-m-d H:i:s", strtotime("+15 minutes"));

            $stmt = $pdo->prepare("INSERT INTO otp_verifications (email, otp_code, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $otp_code, $expires_at]);

            // Real-time school attributes
            $brand_color = ($department === 'Medical Colleges of Northern Philippines') ? '#1e40af' : '#b91c1c';
            $school_abbrev = ($department === 'Medical Colleges of Northern Philippines') ? 'MCNP' : 'ISAP';

            // HTML Email Template matching portal branding
            $email_body = '
                <div style="font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f7f4eb; padding: 40px 10px; text-align: center; color: #2b261f;">
                    <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(12,52,61,0.06); border-top: 6px solid ' . $brand_color . '; padding: 40px; text-align: left;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <img src="mcnp_isap.jpg" alt="MCNP-ISAP" style="width: 75px; height: 75px; object-fit: contain;">
                            <h2 style="color: #0c343d; font-size: 22px; font-weight: bold; margin-top: 15px; margin-bottom: 5px; font-family: \'Georgia\', serif;">MCNP-ISAP Research Portal</h2>
                            <p style="color: #7d7569; font-size: 13px; margin: 0; text-transform: uppercase; letter-spacing: 1px;"> Verification Service</p>
                        </div>
                        
                        <div style="border-bottom: 1.5px solid #eae5d9; padding-bottom: 20px; margin-bottom: 25px;">
                            <p style="font-size: 16px; line-height: 1.6; margin: 0; color: #2b261f;">
                                Hello, <strong style="color: ' . $brand_color . ';">' . htmlspecialchars($username) . '</strong>!
                            </p>
                            <p style="font-size: 15px; line-height: 1.6; margin-top: 10px; color: #4a453e;">
                                Thank you for registering your credentials. To activate your account under <strong>' . $school_abbrev . '</strong> as a <strong>' . htmlspecialchars($role) . '</strong>, use the secure dynamic code below:
                            </p>
                        </div>

                        <div style="text-align: center; margin: 30px 0;">
                            <div style="background-color: #f0fdf4; border: 1px dashed #6ee7b7; color: #047857; font-size: 34px; font-weight: bold; padding: 20px 30px; border-radius: 12px; display: inline-block; letter-spacing: 6px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                                ' . $otp_code . '
                            </div>
                            <p style="color: #7d7569; font-size:12px; margin-top:12px; font-style:italic;">This code expires in exactly 15 minutes.</p>
                        </div>

                        <div style="background-color: #faf8f5; border-radius: 8px; padding: 15px 20px; border-left: 4px solid #ccaa00; margin-bottom: 25px;">
                            <p style="font-size: 12.5px; line-height: 1.5; color: #72634e; margin: 0;">
                                <strong>System Note:</strong> For non-student accounts, research administrators must securely finalize database credentials before access can be unlocked.
                            </p>
                        </div>

                        <p style="color: #7d7569; font-size: 13px; line-height: 1.5; margin-bottom: 0;">
                            If you did not request this account registration, please delete this email safely.
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

            if (sendSystemEmail($email, "Academic Portal Activation Code", $email_body)) {
                $_SESSION['verify_email'] = $email;
                header("Location: verify_otp.php");
                exit();
            } else {
                $message = "Account created successfully, but your verification email failed to deliver. Please check SMTP.";
                $message_type = "error";
            }
        } else {
            $message = "An error occurred while establishing database credentials.";
            $message_type = "error";
        }
    }
    end_registration:;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Portal Registration</title>
    <!-- Premium Academic & Display Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
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
            --mcnp-dark: #172554;
            --isap-red: #b91c1c;
            --isap-dark: #7f1d1d;
            --eagle-gold: #d97706;

            /* Current Active Settings */
            --active-accent: #1e40af;
            --active-glow: rgba(30, 64, 175, 0.12);

            /* App Radii */
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
            overflow: hidden;
            width: 100%;
            height: 100vh;
            height: 100dvh;
            max-width: 100vw;
            overscroll-behavior-y: none;
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

        /* Ambient Glowing Canvas Nodes */
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
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--mcnp-blue) 0%, rgba(255, 255, 255, 0) 70%);
            top: -10%;
            left: -10%;
        }

        .ambient-sphere-2 {
            width: 550px;
            height: 550px;
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
                transform: scale(1.1) translate(20px, -20px);
                opacity: 0.15;
            }
        }

        /* Modular Master Card Frame */
        .portal-frame {
            background-color: var(--bg-card);
            width: 100%;
            max-width: 1040px;
            min-height: 640px;
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
            animation: frameEntrance 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes frameEntrance {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Diagonal Split Left Banner */
        .left-visual-pane {
            width: 40%;
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

        .left-visual-pane.theme-mcnp {
            background: linear-gradient(135deg, var(--mcnp-blue) 0%, var(--mcnp-dark) 100%) !important;
        }

        .left-visual-pane.theme-isap {
            background: linear-gradient(135deg, var(--isap-red) 0%, var(--isap-dark) 100%) !important;
        }

        /* Subtle mesh detail */
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

        /* Animated banner slash */
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
            animation: sweepSlash 8s infinite ease-in-out;
        }

        @keyframes sweepSlash {
            0% {
                transform: translate(-20%, -20%) rotate(-15deg);
                opacity: 0.3;
            }

            50% {
                transform: translate(20%, 20%) rotate(-15deg);
                opacity: 0.7;
            }

            100% {
                transform: translate(-20%, -20%) rotate(-15deg);
                opacity: 0.3;
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
            transform: scale(1.08) rotate(-3deg);
            border-color: rgba(255, 255, 255, 0.45);
        }

        @keyframes floatBadge {
            0% {
                transform: translateY(0px);
            }

            100% {
                transform: translateY(-6px);
            }
        }

        .brand-eagle-banner {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.25);
            padding: 8px 16px;
            border-radius: var(--radius-pill);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fcedcf;
            margin-bottom: 15px;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2);
        }

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

        .left-visual-pane p {
            font-size: 13.5px;
            opacity: 0.85;
            line-height: 1.6;
            font-weight: 400;
            max-width: 320px;
            margin: 0 auto;
            color: #faf6f0;
        }

        /* Already Registered Footer */
        .pane-action-footer {
            position: relative;
            z-index: 3;
            width: 100%;
            margin-top: auto;
        }

        .btn-to-login {
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

        .btn-to-login:hover {
            background: #ffffff;
            color: var(--text-primary);
            border-color: #ffffff;
            transform: translateY(-2.5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
        }

        /* Elegant Right Container Panel */
        .right-form-pane {
            width: 60%;
            padding: 45px 55px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            position: relative;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.7) 0%, rgba(254, 253, 251, 0.95) 100%);
            overflow-y: auto;
        }

        .app-identity-wrapper {
            margin-bottom: 25px;
        }

        .app-identity-wrapper span.system-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
            margin-bottom: 6px;
            display: block;
        }

        .app-identity-wrapper h2 {
            font-family: 'Cambria', Georgia, serif;
            font-size: 24px;
            color: var(--text-primary);
            font-weight: 800;
            letter-spacing: 0.5px;
            transition: color 0.4s ease;
        }

        .app-identity-wrapper p.desc {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 550;
            margin-top: 4px;
        }

        /* -----------------------------------------
         * PREMIUM STATIC WIZARD PROGRESS TRACKER
         * ----------------------------------------- */
        .registration-progress-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            background: #fdfdfd;
            padding: 12px 18px;
            border-radius: var(--radius-interactive);
            border: 1px solid var(--border-subtle);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.01);
        }

        .registration-progress-bar::before {
            content: '';
            position: absolute;
            height: 2px;
            background-color: var(--border-subtle);
            top: 50%;
            left: 10%;
            right: 10%;
            transform: translateY(-50%);
            z-index: 1;
        }

        .prog-step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            width: 30%;
        }

        .prog-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--bg-canvas);
            border: 2px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .prog-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            color: var(--text-muted);
            transition: all 0.4s ease;
            text-align: center;
            white-space: nowrap;
        }

        /* State Modifiers */
        .prog-step.active .prog-circle {
            border-color: var(--active-accent);
            background-color: var(--active-accent);
            color: #ffffff;
            box-shadow: 0 0 0 4px var(--active-glow);
        }

        .prog-step.active .prog-label {
            color: var(--active-accent);
        }

        .prog-step.done .prog-circle {
            border-color: var(--active-accent);
            background-color: #ffffff;
            color: var(--active-accent);
        }

        /* -----------------------------------------
         * ANIMATED FORM WIZARD STAGE VIEWS
         * ----------------------------------------- */
        .form-step-section {
            display: none;
            min-height: 260px;
        }

        @keyframes slideStepIn {
            from {
                opacity: 0;
                transform: translateX(12px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Premium Form Field Styles */
        .field-box {
            margin-bottom: 20px;
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
            transition: all 0.3s ease;
            z-index: 5;
        }

        .field-box input:not([type="checkbox"]),
        .field-box select {
            width: 100%;
            padding: 14px 16px 14px 52px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            background-color: #f7f5ef;
            border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-interactive);
            outline: none;
            color: var(--text-primary);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-weight: 500;
            appearance: none;
            box-shadow: inset 0 2px 4px rgba(43, 38, 31, 0.01);
        }

        /* Special Select caret override */
        .field-box select {
            padding-right: 44px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239c9284' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            cursor: pointer;
        }

        .field-box input::placeholder {
            color: var(--text-muted);
            opacity: 0.85;
        }

        .field-box input:not([type="checkbox"]):focus,
        .field-box select:focus {
            border-color: var(--active-accent);
            background-color: var(--bg-card);
            box-shadow: 0 0 0 4px var(--active-glow);
        }

        .field-box input:focus+.prefix-icon,
        .field-box select:focus+.prefix-icon {
            color: var(--active-accent);
            transform: scale(1.05);
        }

        .field-box input:hover:not(:focus),
        .field-box select:hover:not(:focus) {
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

        /* Group Leader Option Toggle design */
        .option-toggle-container {
            background: #f7f5ef;
            padding: 14px 18px;
            border-radius: var(--radius-interactive);
            border: 1.5px solid var(--border-subtle);
            width: 100%;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .option-toggle-container:hover {
            border-color: var(--active-accent);
            background-color: #ffffff;
        }

        /* Comma separated interactive members chip system */
        .member-list-wrapper {
            background: #faf9f6;
            padding: 18px;
            border-radius: var(--radius-interactive);
            border: 1.5px dashed var(--border-subtle);
            margin-top: 10px;
        }

        .member-pills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            max-height: 140px;
            overflow-y: auto;
            padding: 2px;
        }

        .member-pill {
            background-color: var(--active-accent);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: var(--radius-pill);
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px var(--active-glow);
            animation: popCheck 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transition: all 0.2s;
        }

        @keyframes popCheck {
            from {
                transform: scale(0.85);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .member-pill i {
            cursor: pointer;
            font-style: normal;
            font-weight: 800;
            opacity: 0.75;
            font-size: 14px;
            line-height: 1;
            padding: 0 2px;
            transition: opacity 0.2s;
        }

        .member-pill i:hover {
            opacity: 1;
        }

        .btn-add-member {
            font-size: 12px;
            color: var(--active-accent);
            background: #ffffff;
            border: 1.5px solid var(--border-subtle);
            padding: 6px 14px;
            border-radius: var(--radius-pill);
            cursor: pointer;
            font-weight: 700;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-add-member:hover {
            border-color: var(--active-accent);
            background-color: var(--bg-canvas);
        }

        .add-member-input-group {
            display: none;
            margin-top: 15px;
            gap: 10px;
            animation: fadeInDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInDown {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* -----------------------------------------
         * CHIC MULTIPHASE FOOTER NAV BUTTONS
         * ----------------------------------------- */
        .wizard-buttons-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 30px;
            gap: 15px;
            animation: slideUpFade 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .btn-wizard {
            padding: 15px 28px;
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            font-weight: 700;
            border-radius: var(--radius-interactive);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-wizard-prev {
            background-color: #ffffff;
            border: 1.5px solid var(--border-subtle);
            color: var(--text-secondary);
        }

        .btn-wizard-prev:hover {
            background-color: var(--bg-canvas);
            border-color: var(--text-muted);
            transform: translateX(-2px);
        }

        .btn-wizard-next {
            background-color: var(--active-accent);
            color: #ffffff;
            border: none;
            box-shadow: 0 8px 24px var(--active-glow);
            margin-left: auto;
        }

        .btn-wizard-next:hover {
            opacity: 0.95;
            transform: translateX(2px);
            box-shadow: 0 10px 30px var(--active-glow);
        }

        .btn-portal-submit {
            background-color: var(--active-accent);
            color: #ffffff;
            border: none;
            box-shadow: 0 8px 24px var(--active-glow);
            margin-left: auto;
        }

        .btn-portal-submit:hover {
            opacity: 0.95;
            transform: translateY(-2px);
            box-shadow: 0 12px 35px var(--active-glow);
        }

        /* Role Selection Cards */
        .role-selection-cards {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .role-card {
            position: relative;
            display: block;
            cursor: pointer;
        }

        .role-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .role-card-content {
            border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-interactive);
            padding: 24px;
            background: #ffffff;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .role-card-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--active-accent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .role-card input[type="radio"]:checked + .role-card-content {
            border-color: var(--active-accent);
            background: #fdfbf7;
            box-shadow: 0 8px 24px var(--active-glow);
            transform: translateY(-2px);
        }

        .role-card input[type="radio"]:checked + .role-card-content::before {
            opacity: 1;
        }
        
        .role-card:hover .role-card-content {
            border-color: var(--active-accent);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        /* Dynamic Administrator Role Ready Layout */
        .admin-submission-overview {
            text-align: center;
            padding: 30px 20px;
            background: var(--bg-canvas);
            border-radius: var(--radius-interactive);
            border: 1.5px dashed var(--border-subtle);
            animation: stepEntrance 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .admin-submission-overview h3 {
            font-family: 'Cambria', Georgia, serif;
            font-size: 18px;
            color: var(--active-accent);
            margin-bottom: 12px;
            font-weight: 700;
        }

        .summary-badge-table {
            margin: 20px auto;
            max-width: 380px;
            text-align: left;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--border-subtle);
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #faf9f6;
            font-size: 13px;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-key {
            font-weight: 700;
            color: var(--text-secondary);
        }

        .summary-val {
            color: var(--text-primary);
            font-weight: 500;
        }

        /* Dynamic Toast Banner */
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

        /* System structural footers */
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

        /* High accuracy mobile responsive adaptation */
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
                padding: 40px 30px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }

            .right-form-pane {
                width: 100%;
                padding: 40px 24px;
            }

            .registration-progress-bar {
                padding: 8px 10px;
            }

            .prog-label {
                font-size: 8.5px;
                letter-spacing: 0.5px;
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
                padding: 30px 20px;
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
    </style>
</head>

<body>

    <!-- Drifting organic blurs -->
    <div class="ambient-sphere ambient-sphere-1"></div>
    <div class="ambient-sphere ambient-sphere-2"></div>

    <div class="portal-frame">

        <!-- Interactive School Accent Left Panel -->
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
                <p>Join the platform to submit research requirements, collaborate with your team, receive adviser feedback, and track your progress every step of the way.</p>
            </div>

            <div class="pane-action-footer">
                <div style="font-size: 13.5px; opacity: 0.8; margin-bottom: 14px; font-weight: 600; color: #fdfaf6;">Already have an account?</div>
                <a href="login.php" class="btn-to-login">
                    <i data-lucide="log-in" style="width: 16px; height: 16px;"></i>
                    <span>Sign In</span>
                </a>
            </div>
        </div>

        <!-- Dynamic Form Fields Panel -->
        <div class="right-form-pane">
            <div class="app-identity-wrapper">
                <span class="system-label">Digitalization of Research Process</span>
                <h2>Register Setup</h2>
                <p class="desc">Create your account and start collaborating with your research team.</p>
            </div>

            <!-- Dynamic Feedback Toast always visible on top -->
            <?php if (!empty($message)): ?>
                <div class="toast-msg <?php echo ($message_type === 'error') ? 'msg-error' : 'msg-success'; ?>">
                    <i data-lucide="<?php echo ($message_type === 'error') ? 'alert-circle' : 'check-circle'; ?>" style="width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px;"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Interactive Multi-Step Progress Indicators -->
            <div class="registration-progress-bar">
                <div class="prog-step active" id="progNode1">
                    <div class="prog-circle">1</div>
                    <div class="prog-label">Role</div>
                </div>
                <div class="prog-step" id="progNode2">
                    <div class="prog-circle">2</div>
                    <div class="prog-label">Identity</div>
                </div>
                <div class="prog-step" id="progNode3">
                    <div class="prog-circle">3</div>
                    <div class="prog-label">Setup</div>
                </div>
            </div>

            <form action="" method="POST" id="regForm" autocomplete="on">
                <input type="hidden" name="auth_action" value="register">

                <!-- -----------------------------------------
                 * STEP 1: ROLE SELECTION
                 * ----------------------------------------- -->
                <div class="form-step-section" id="step-section-1" style="display: block;">
                    <input type="hidden" name="role" id="userRole" value="Student">
                    <div class="role-selection-cards">
                        <label class="role-card">
                            <input type="radio" name="is_group_leader" id="leaderRadio" value="yes" checked onchange="toggleLeaderFields()">
                            <div class="role-card-content">
                                <i data-lucide="crown" style="width: 32px; height: 32px; color: var(--active-accent); margin-bottom: 12px;"></i>
                                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px;">Research Leader</h3>
                                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4;">I am the primary researcher. I will register the group and add my members.</p>
                            </div>
                        </label>
                        <label class="role-card">
                            <input type="radio" name="is_group_leader" id="memberRadio" value="no" onchange="toggleLeaderFields()">
                            <div class="role-card-content">
                                <i data-lucide="users" style="width: 32px; height: 32px; color: var(--active-accent); margin-bottom: 12px;"></i>
                                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px;">Group Member</h3>
                                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4;">I am a group member. My leader will create or has already created the group.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- -----------------------------------------
                 * STEP 2: IDENTITY & AFFILIATION
                 * ----------------------------------------- -->
                <div class="form-step-section" id="step-section-2" style="display: none;">
                    <div class="field-box">
                        <label>Student researchers / Full Name</label>
                        <div class="input-group-with-icon">
                            <i data-lucide="user" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <input type="text" name="username" id="usernameInput" placeholder="e.g. Juan De La Cruz" required autocomplete="name">
                        </div>
                    </div>

                    <div class="field-box">
                        <label>Gmail Address</label>
                        <div class="input-group-with-icon">
                            <i data-lucide="mail" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <input type="email" name="email" id="emailInput" placeholder="example@mcnp.edu.ph" required autocomplete="email">
                        </div>
                    </div>

                    <div class="field-box">
                        <label>Security Password / PIN</label>
                        <div class="input-group-with-icon">
                            <i data-lucide="lock" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <input type="password" name="password" id="registerPass" placeholder="••••••••" required autocomplete="new-password">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('registerPass', this)">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                    </div>

                    <div class="field-box">
                        <label>Department / Institution School</label>
                        <div class="input-group-with-icon">
                            <i data-lucide="landmark" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <select name="department" id="department" required onchange="updateBrandThemeAndPrograms()">
                                <option value="" disabled selected>Select Department / School</option>
                                <option value="Medical Colleges of Northern Philippines">Medical Colleges of Northern Philippines (MCNP)</option>
                                <option value="International School of Asia and the Pacific">International School of Asia and the Pacific (ISAP)</option>
                            </select>
                        </div>
                    </div>

                    <div class="field-box">
                        <label>Academic Degree Program / Course</label>
                        <div class="input-group-with-icon">
                            <i data-lucide="shapes" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                            <select name="program" id="program" required>
                                <option value="" disabled selected>Select Program / Course</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- -----------------------------------------
                 * STEP 3: DEEP RESEARCH PROJECT METRICS
                 * ----------------------------------------- -->
                <div class="form-step-section" id="step-section-3" style="display: none;">

                    <div id="step3StudentSection">

                        <div class="field-box" id="groupNameField">
                            <label>Research Project Title</label>
                            <div class="input-group-with-icon">
                                <i data-lucide="file-text" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                                <input type="text" id="groupNameInput" name="group_name" placeholder="Enter complete proposal title">
                            </div>
                        </div>

                        <div class="field-box" id="leaderEmailField" style="display: none;">
                            <label>Your Group Leader's Email</label>
                            <div class="input-group-with-icon">
                                <i data-lucide="send" class="prefix-icon" style="width: 18px; height: 18px;"></i>
                                <input type="email" name="leader_email" placeholder="leader@mcnp.edu.ph" id="leaderEmailInput">
                            </div>
                        </div>

                        <div class="field-box" id="groupMembersContainer">
                            <label>Research Group Mates</label>
                            <div class="member-list-wrapper">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 11px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Members (Excluding Self)</span>
                                    <button type="button" class="btn-add-member" onclick="toggleAddMemberInput(event)">
                                        <i data-lucide="plus" style="width: 13px; height: 13px;"></i> Add Member
                                    </button>
                                </div>

                                <div class="add-member-input-group" id="memberInputGroup">
                                    <input type="text" id="newMemberName" placeholder="Full Name of Group Member" style="background:#fff; border-color:var(--border-subtle); margin:0; font-size:13px; padding-left: 16px;">
                                    <button type="button" class="btn-wizard" style="width:auto; padding:8px 20px; margin:0; font-size:13px; background-color: var(--active-accent); color: white; border: none; border-radius: var(--radius-interactive);" onclick="saveMemberToArray()">Insert</button>
                                </div>

                                <div class="member-pills-container" id="memberPills"></div>
                                <input type="hidden" name="group_members_list" id="groupMembersHidden">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- -----------------------------------------
                 * FLOW NAVIGATION CONTROL ROWS
                 * ----------------------------------------- -->
                <div class="wizard-buttons-row">
                    <button type="button" class="btn-wizard btn-wizard-prev" id="btnPrevStep" onclick="setStep(currentStep - 1)" style="display: none;">
                        <span>PREVIOUS</span>
                    </button>

                    <button type="button" class="btn-wizard btn-wizard-next" id="btnNextStep" onclick="setStep(currentStep + 1)">
                        <span>CONTINUE</span>
                    </button>

                    <button type="submit" class="btn-wizard btn-portal-submit" id="btnFinalSubmit" style="display: none;">
                        <i data-lucide="user-plus" style="width: 18px; height: 18px; stroke-width: 2.5;"></i>
                        <span>REGISTER</span>
                    </button>
                </div>
            </form>

            <div class="mobile-navigator-link">
                Already have an account? <a href="login.php">Sign In</a>
            </div>
        </div>

    </div>

    <div class="footer-line">
        Medical Colleges of Northern Philippines
        <span class="institutional-divider">|</span>
        <br>
        International School of Asia and the Pacific
    </div>

    <script>
        const programOptions = {
            "Medical Colleges of Northern Philippines": [
                "BS Radiologic Technology",
                "BS Nursing",
                "BS Medical Technology",
                "BS Physical Therapy",
                "BS Pharmacy",
                "BS Midwifery",
                "BS 2-year Dental Technology",
                "BS 2-year Pharmacy Aide",
                "BS Caregiving and TVET Course"
            ],
            "International School of Asia and the Pacific": [
                "BS Information Technology",
                "BS Computer Engineering",
                "BS Business Administration",
                "BS Custom Administration",
                "BS Hospitality Management",
                "BS Tourism Management",
                "BS Accountancy",
                "BS Education",
                "BS Science Criminology",
                "BS Science in Social Work",
                "BS Secondary Education",
                "BS Science in Psychology",
                "BS Physical Education"
            ]
        };

        // Real-time Visual Theme Morph triggers
        function updateBrandThemeAndPrograms() {
            const dept = document.getElementById('department').value;
            const programSelect = document.getElementById('program');
            const brandOverlay = document.getElementById('brandOverlay');
            const root = document.documentElement;
            const brandTitle = document.getElementById('brandTitle');
            const h2Title = document.querySelector('.app-identity-wrapper h2');

            programSelect.innerHTML = '<option value="" disabled selected>Select Program / Course</option>';
            if (dept && programOptions[dept]) {
                programOptions[dept].forEach(p => programSelect.add(new Option(p, p)));
            }

            if (dept === "Medical Colleges of Northern Philippines") {
                brandOverlay.className = "left-visual-pane theme-mcnp";
                root.style.setProperty('--active-accent', '#1e40af');
                root.style.setProperty('--active-glow', 'rgba(30, 64, 175, 0.12)');
                if (brandTitle) brandTitle.innerText = "MCNP Portal";
                if (h2Title) h2Title.style.color = '#1e40af';

                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="graduation-cap" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            } else if (dept === "International School of Asia and the Pacific") {
                brandOverlay.className = "left-visual-pane theme-isap";
                root.style.setProperty('--active-accent', '#b91c1c');
                root.style.setProperty('--active-glow', 'rgba(185, 28, 28, 0.12)');
                if (brandTitle) brandTitle.innerText = "ISAP Portal";
                if (h2Title) h2Title.style.color = '#b91c1c';

                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="book-open" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            } else {
                brandOverlay.className = "left-visual-pane";
                root.style.setProperty('--active-accent', '#1e40af');
                root.style.setProperty('--active-glow', 'rgba(30, 64, 175, 0.12)');
                if (brandTitle) brandTitle.innerText = "MCNP-ISAP Portal";
                if (h2Title) h2Title.style.color = 'var(--text-primary)';

                const badge = brandOverlay.querySelector('.brand-icon-box');
                if (badge) {
                    badge.innerHTML = '<i data-lucide="graduation-cap" style="width: 40px; height: 40px;"></i>';
                    lucide.createIcons();
                }
            }
        }

        // Student Group Role constraints toggling
        function toggleLeaderFields() {
            const isLeader = document.getElementById('leaderRadio').checked;
            const groupNameField = document.getElementById('groupNameField');
            const leaderEmailField = document.getElementById('leaderEmailField');
            const groupMembersContainer = document.getElementById('groupMembersContainer');
            const groupNameInput = document.getElementById('groupNameInput');
            const leaderEmailInput = document.getElementById('leaderEmailInput');

            if (isLeader) {
                groupNameField.style.display = 'block';
                groupMembersContainer.style.display = 'block';
                leaderEmailField.style.display = 'none';
                groupNameInput.setAttribute('required', 'required');
                leaderEmailInput.removeAttribute('required');
            } else {
                groupNameField.style.display = 'none';
                groupMembersContainer.style.display = 'none';
                leaderEmailField.style.display = 'block';
                groupNameInput.removeAttribute('required');
                leaderEmailInput.setAttribute('required', 'required');
            }
        }

        function checkRoleConstraint() {
            toggleLeaderFields();
        }

        // Reveal/Obscure password entry
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

        // Comma separated pilot elements addition & pill rendering
        let members = [];

        function toggleAddMemberInput(e) {
            e.preventDefault();
            const grp = document.getElementById('memberInputGroup');
            grp.style.display = (grp.style.display === 'flex') ? 'none' : 'flex';
            if (grp.style.display === 'flex') {
                document.getElementById('newMemberName').focus();
            }
        }

        function saveMemberToArray() {
            const input = document.getElementById('newMemberName');
            const name = input.value.trim();
            if (name) {
                members.push(name);
                input.value = "";
                document.getElementById('memberInputGroup').style.display = 'none';
                renderMemberPills();
            }
        }

        function removeMemberAt(idx) {
            members.splice(idx, 1);
            renderMemberPills();
        }

        function renderMemberPills() {
            const container = document.getElementById('memberPills');
            const hiddenInput = document.getElementById('groupMembersHidden');

            container.innerHTML = members.map((m, i) => `
                <div class="member-pill">
                    <span>${m}</span>
                    <i onclick="removeMemberAt(${i})">×</i>
                </div>
            `).join('');

            hiddenInput.value = members.join(', ');
        }

        document.getElementById('newMemberName')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveMemberToArray();
            }
        });

        // -----------------------------------------
        // CLIENT-SIDE MULTI-STEP NAVIGATION WIZARD
        // -----------------------------------------
        let currentStep = 1;
        const totalSteps = 3;

        function setStep(stepNum) {
            // Validation step constraints checking
            if (stepNum > currentStep) {
                if (!validateStep(currentStep)) return;
            }

            currentStep = stepNum;

            // Toggle form views
            for (let i = 1; i <= totalSteps; i++) {
                const section = document.getElementById('step-section-' + i);
                if (i === currentStep) {
                    section.style.display = 'block';
                    section.style.animation = 'slideStepIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) both';
                } else {
                    section.style.display = 'none';
                }
            }

            // Sync visual progress trackers
            for (let i = 1; i <= totalSteps; i++) {
                const node = document.getElementById('progNode' + i);
                if (i < currentStep) {
                    node.className = 'prog-step active done';
                } else if (i === currentStep) {
                    node.className = 'prog-step active';
                } else {
                    node.className = 'prog-step';
                }
            }

            // Toggle button states
            const btnPrev = document.getElementById('btnPrevStep');
            const btnNext = document.getElementById('btnNextStep');
            const btnSubmit = document.getElementById('btnFinalSubmit');

            if (currentStep === 1) {
                btnPrev.style.display = 'none';
            } else {
                btnPrev.style.display = 'inline-flex';
            }

            if (currentStep === totalSteps) {
                btnNext.style.display = 'none';
                btnSubmit.style.display = 'inline-flex';
            } else {
                btnNext.style.display = 'inline-flex';
                btnSubmit.style.display = 'none';
            }
        }

        function validateStep(step) {
            const section = document.getElementById('step-section-' + step);
            const inputs = section.querySelectorAll('input, select');
            let isValid = true;
            for (let i = 0; i < inputs.length; i++) {
                // If the dynamic block is hidden, skip validation checks
                const fieldBox = inputs[i].closest('.field-box');
                const isLeaderChecked = document.getElementById('leaderRadio') ? document.getElementById('leaderRadio').checked : true;

                if (fieldBox && fieldBox.style.display === 'none') {
                    continue;
                }

                // If inside leader container but leader is checked, skip validator checks for leader fields
                if (inputs[i].id === 'leaderEmailInput' && isLeaderChecked) {
                    continue;
                }
                if (inputs[i].id === 'groupNameInput' && !isLeaderChecked) {
                    continue;
                }

                if (inputs[i].hasAttribute('required') && !inputs[i].checkValidity()) {
                    inputs[i].reportValidity();
                    isValid = false;
                    break;
                }
            }
            return isValid;
        }

        // Initialize state onload
        document.addEventListener("DOMContentLoaded", () => {
            updateBrandThemeAndPrograms();
            checkRoleConstraint();
            lucide.createIcons();

            // Support form submissions validation triggers
            document.getElementById('regForm').addEventListener('submit', function(e) {
                if (!validateStep(3)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>

</html>