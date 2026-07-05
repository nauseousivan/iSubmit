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
        if (isset($_SESSION['resend_lockout']) && time() < $_SESSION['resend_lockout']) {
            $message = "Too many requests. Please try again in " . ceil(($_SESSION['resend_lockout'] - time()) / 60) . " minutes.";
            $message_type = "error";
        } else {
            $_SESSION['resend_attempts'] = ($_SESSION['resend_attempts'] ?? 0) + 1;
            if ($_SESSION['resend_attempts'] > 3) {
                $_SESSION['resend_lockout'] = time() + (30 * 60);
                $message = "Too many requests. Please try again in 30 minutes.";
                $message_type = "error";
            } else {
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
                            $_SESSION['reset_code_expires'] = time() + (15 * 60);
                            $_SESSION['reset_resend_cooldown'] = time() + 60; // 1 minute cooldown
                            header("Location: forgot_password.php");
                            exit();
                        } else {
                            $message = "MTA failure occurred. Reset email could not be safely transmitted.";
                            $message_type = "error";
                        }
                    }
                } else {
                    $message = "No account found with this email address.";
                    $message_type = "error";
                }
            }
        }
    } elseif ($action === 'verify_code') {
        $entered_otp = trim($_POST['otp_code'] ?? '');
        $email = $_SESSION['reset_email'] ?? '';
        $is_ajax = isset($_POST['ajax_verify']);

        // Query the latest record timezone independenly on PHP side
        $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $entered_otp]);
        $row = $stmt->fetch();

        if ($row) {
            $expires_at = $row['expires_at'];
            if (strtotime($expires_at) < time()) {
                if ($is_ajax) {
                    echo json_encode(['status' => 'error', 'message' => 'This verification code has expired.']);
                    exit;
                }
                $message = "This verification code has expired. Request a new one.";
                $message_type = "error";
            } else {
                $_SESSION['reset_step'] = 'reset';
                unset($_SESSION['resend_attempts'], $_SESSION['resend_lockout']);

                if ($is_ajax) {
                    echo json_encode(['status' => 'success']);
                    exit;
                }

                header("Location: forgot_password.php");
                exit();
            }
        } else {
            if ($is_ajax) {
                echo json_encode(['status' => 'error', 'message' => 'The verification code you entered is incorrect.']);
                exit;
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Account Recovery | iSubmit</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Theme CSS -->
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/mascot/mascot.css?v=<?= time() ?>">

    <style>
        .otp-container {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 24px 0;
        }

        .otp-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid rgba(0, 0, 0, 0.15);
            border-radius: 12px;
            background: #f8f9fa;
            color: var(--primary-color);
            transition: all 0.2s;
            font-family: 'Poppins', sans-serif;
        }

        .otp-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
            outline: none;
        }

        .otp-input.filled {
            border-color: var(--primary-color);
            background: rgba(139, 92, 246, 0.02);
        }

        .otp-input.success {
            border-color: #22c55e !important;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.1) !important;
        }

        .otp-input.error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1) !important;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            20%,
            60% {
                transform: translateX(-5px);
            }

            40%,
            80% {
                transform: translateX(5px);
            }
        }

        .shake {
            animation: shake 0.4s cubic-bezier(.36, .07, .19, .97) both;
        }

        /* Loader Spinner */
        .spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid #ffffff;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <!-- Visual Pane -->
            <div class="auth-visual-pane" id="brandOverlay">
                <!-- Branding Mascot Badge -->
                <div class="brand-icon-box" id="brandBadge" style="margin-bottom: 8px; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; position: relative; z-index: 50;">
                    <?php include '../assets/mascot/mascot.php'; ?>
                </div>
                <div style="text-align: center; max-width: 320px; margin: 0 auto 0;">
                    <h1 id="brandTitle" class="brand-title" style="color: white; font-weight: 800; margin-bottom: 4px; margin-top: 0; letter-spacing: -0.5px; font-family: 'Poppins', sans-serif;">Account Recovery</h1>
                    <p id="brandSubLead" class="brand-sub-lead" style="color: white; opacity: 0.95; font-weight: 500; margin-top: 0; font-family: 'Plus Jakarta Sans', sans-serif;">Secure Password Reset</p>
                </div>
            </div>

            <!-- Form Pane -->
            <div class="auth-form-pane bottom-sheet">
                <div class="drag-handle"></div>

                <?php if (!empty($message)): ?>
                    <div class="mat-alert <?php echo ($message_type === 'error') ? 'mat-alert-error' : 'mat-alert-success'; ?>" style="margin-bottom: 24px;">
                        <i data-lucide="info" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'request'): ?>
                    <div class="auth-header">
                        <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); font-weight: 700; margin-bottom: 8px; display: block;">Step 1 of 3</span>
                        <h2>Forgot Password</h2>
                        <p>Enter your valid email address to receive a recovery code.</p>
                    </div>

                    <form action="" method="POST" autocomplete="on">
                        <input type="hidden" name="action" value="send_code">
                        <div class="mat-input-group">
                            <label>Account Email</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="mail" class="prefix-icon"></i>
                                <input type="email" name="email" id="recoveryEmail" class="mat-input" placeholder="example@mcnp.edu.ph" required autocomplete="email">
                            </div>
                        </div>
                        <button type="submit" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px; margin-top: 8px;">
                            <span>Send Recovery Code</span>
                            <i data-lucide="send" style="width: 18px; height: 18px; margin-left: 8px;"></i>
                        </button>
                    </form>
                    <div style="text-align: center; margin-top: 24px;">
                        <a href="login.php" style="font-size: 14px; color: var(--text-secondary); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                            <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                            Return to sign-in
                        </a>
                    </div>

                <?php elseif ($step === 'verify'): ?>
                    <div class="auth-header">
                        <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); font-weight: 700; margin-bottom: 8px; display: block;">Step 2 of 3</span>
                        <h2>Verify Code</h2>
                        <p>Enter the 6-digit code sent to <br><strong style="color: var(--primary-color);"><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong></p>
                    </div>

                    <form action="" method="POST" autocomplete="off" id="otpForm">
                        <input type="hidden" name="action" value="verify_code">
                        <input type="hidden" name="otp_code" id="actualOtpInput">

                        <div id="timerDisplay" style="text-align: center; color: var(--primary-color); font-weight: 700; margin-bottom: 16px; font-size: 16px; display: none;"></div>

                        <div class="otp-container">
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required autofocus>
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required>
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required>
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required>
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required>
                            <input type="text" class="otp-input" pattern="[0-9]*" inputmode="numeric" required>
                        </div>

                        <button type="submit" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px; margin-top: 8px;">
                            VERIFY
                        </button>
                    </form>

                    <form action="" method="POST" style="text-align: center; margin-top: 24px;">
                        <input type="hidden" name="action" value="send_code">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['reset_email']); ?>">
                        <button type="submit" id="resendBtn" class="mat-btn mat-btn-text" style="color: var(--primary-color); width: 100%; justify-content: center;">
                            <i data-lucide="refresh-cw" style="width: 16px; height: 16px; margin-right: 6px;"></i>
                            <span id="resendText">Resend Code</span>
                        </button>
                    </form>

                    <form action="" method="POST" style="text-align: center; margin-top: 8px;">
                        <input type="hidden" name="action" value="restart">
                        <button type="submit" class="mat-btn mat-btn-text" style="border: none; color: var(--text-secondary); width: 100%; justify-content: center;">
                            <i data-lucide="arrow-left" style="width: 16px; height: 16px; margin-right: 6px;"></i>
                            <span>Use a different email</span>
                        </button>
                    </form>

                <?php elseif ($step === 'reset'): ?>
                    <div class="auth-header">
                        <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); font-weight: 700; margin-bottom: 8px; display: block;">Step 3 of 3</span>
                        <h2>New Password</h2>
                        <p>Create a secure new password for your account.</p>
                    </div>

                    <form action="" method="POST">
                        <input type="hidden" name="action" value="update_password">
                        <div class="mat-input-group">
                            <label>New Password</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="lock" class="prefix-icon"></i>
                                <input type="password" name="new_password" id="newPass" class="mat-input" placeholder="••••••••" required autocomplete="new-password">
                                <button type="button" class="mat-btn mat-btn-text" onclick="togglePassword('newPass', this)" style="position: absolute; right: 4px; padding: 8px; min-width: auto; height: 36px;">
                                    <i data-lucide="eye" style="width: 18px; height: 18px; margin: 0;"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mat-input-group">
                            <label>Confirm Password</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="check-circle" class="prefix-icon"></i>
                                <input type="password" name="confirm_password" id="confirmPass" class="mat-input" placeholder="••••••••" required autocomplete="new-password">
                                <button type="button" class="mat-btn mat-btn-text" onclick="togglePassword('confirmPass', this)" style="position: absolute; right: 4px; padding: 8px; min-width: auto; height: 36px;">
                                    <i data-lucide="eye" style="width: 18px; height: 18px; margin: 0;"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px; margin-top: 8px;">
                            CONFIRM PASSWORD
                        </button>
                    </form>

                <?php elseif ($step === 'success'): ?>
                    <div style="text-align: center; padding: 24px 0;">
                        <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; background: rgba(34, 197, 94, 0.1); color: #22c55e; margin-bottom: 24px;">
                            <i data-lucide="check" style="width: 40px; height: 40px;"></i>
                        </div>
                        <h2 style="font-size: 24px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; font-family: 'Poppins', sans-serif;">Password Updated!</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 32px; font-size: 15px; line-height: 1.6;">Your security credentials have been restored. You can now securely sign in to your dashboard.</p>

                        <a href="login.php" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px; text-decoration: none;">
                            PROCEED TO LOGIN
                        </a>
                    </div>
                    <?php unset($_SESSION['reset_step']); ?>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="../assets/js/bottom-sheet.js"></script>
    <script>
        lucide.createIcons();

        // Countdown Timer Logic
        const resendBtn = document.getElementById('resendBtn');
        const resendText = document.getElementById('resendText');
        const timerDisplay = document.getElementById('timerDisplay');

        const codeExpires = <?php echo ($_SESSION['reset_code_expires'] ?? 0) * 1000; ?>;
        const resendCooldown = <?php echo ($_SESSION['reset_resend_cooldown'] ?? 0) * 1000; ?>;

        if (resendBtn && resendText) {
            if (timerDisplay) timerDisplay.style.display = 'block';

            function updateTimers() {
                const now = new Date().getTime();

                // 1. Code Expiration Timer (15 mins)
                const codeDistance = codeExpires - now;
                if (codeDistance > 0) {
                    const cMins = Math.floor((codeDistance % (1000 * 60 * 60)) / (1000 * 60));
                    const cSecs = Math.floor((codeDistance % (1000 * 60)) / 1000);
                    if (timerDisplay) timerDisplay.textContent = `Code expires in: ${cMins < 10 ? "0"+cMins : cMins}:${cSecs < 10 ? "0"+cSecs : cSecs}`;
                } else {
                    if (timerDisplay) timerDisplay.textContent = 'Code expired!';
                }

                // 2. Resend Cooldown Timer (1 min)
                const resendDistance = resendCooldown - now;
                if (resendDistance > 0) {
                    resendBtn.style.opacity = '0.5';
                    resendBtn.style.pointerEvents = 'none';
                    const rMins = Math.floor((resendDistance % (1000 * 60 * 60)) / (1000 * 60));
                    const rSecs = Math.floor((resendDistance % (1000 * 60)) / 1000);
                    resendText.textContent = `Wait ${rMins < 10 ? "0"+rMins : rMins}:${rSecs < 10 ? "0"+rSecs : rSecs}`;
                } else {
                    resendBtn.style.opacity = '1';
                    resendBtn.style.pointerEvents = 'auto';
                    resendText.textContent = 'Resend Code';
                }

                if (codeDistance <= 0 && resendDistance <= 0) {
                    clearInterval(timerInterval);
                }
            }

            updateTimers();
            const timerInterval = setInterval(updateTimers, 1000);
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                input.type = 'password';
                icon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }
        
        // Mascot Password Interactions
        document.addEventListener('DOMContentLoaded', () => {
            const handleFocus = () => { if(window.Quill) window.Quill.coverEyes(); };
            const handleBlur = () => { if(window.Quill) window.Quill.idle(); };

            const pass1 = document.getElementById('newPass');
            const pass2 = document.getElementById('confirmPass');
            if (pass1) { pass1.addEventListener('focus', handleFocus); pass1.addEventListener('blur', handleBlur); }
            if (pass2) { pass2.addEventListener('focus', handleFocus); pass2.addEventListener('blur', handleBlur); }

            const otpInputs = document.querySelectorAll('.otp-input');
            otpInputs.forEach(input => {
                input.addEventListener('focus', handleFocus);
                input.addEventListener('blur', handleBlur);
            });
        });

        const otpInputs = document.querySelectorAll('.otp-input');
        const actualOtpInput = document.getElementById('actualOtpInput');

        if (otpInputs.length > 0) {
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    let val = input.value.replace(/[^0-9]/g, '');

                    if (val.length > 1) {
                        for (let i = 0; i < val.length && index + i < 6; i++) {
                            otpInputs[index + i].value = val[i];
                            otpInputs[index + i].classList.add('filled');
                        }
                        let nextFocus = Math.min(index + val.length, 5);
                        otpInputs[nextFocus].focus();
                    } else {
                        input.value = val;
                        if (val) {
                            input.classList.add('filled');
                            if (index < 5) otpInputs[index + 1].focus();
                        } else {
                            input.classList.remove('filled');
                        }
                    }
                    updateActualOtp();
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !input.value && index > 0) {
                        otpInputs[index - 1].focus();
                        otpInputs[index - 1].classList.remove('filled');
                    }
                });

                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                    if (pastedData) {
                        for (let i = 0; i < pastedData.length; i++) {
                            if (otpInputs[i]) {
                                otpInputs[i].value = pastedData[i];
                                otpInputs[i].classList.add('filled');
                            }
                        }
                        if (pastedData.length < 6) {
                            otpInputs[pastedData.length].focus();
                        } else {
                            otpInputs[5].focus();
                        }
                        updateActualOtp();
                    }
                });
            });

            function updateActualOtp() {
                let val = '';
                otpInputs.forEach(inp => val += inp.value);
                if (actualOtpInput) {
                    actualOtpInput.value = val;
                }
                if (val.length === 6) {
                    performAjaxVerify(val);
                }
            }

            function performAjaxVerify(code) {
                const otpForm = document.getElementById('otpForm');
                if (!otpForm) return;

                const btn = otpForm.querySelector('button[type="submit"]');
                const btnHtml = btn.innerHTML;

                // Clear previous states
                otpInputs.forEach(inp => {
                    inp.classList.remove('success', 'error');
                });
                const container = document.querySelector('.otp-container');
                container.classList.remove('shake');

                // Loading state
                btn.style.pointerEvents = 'none';
                btn.innerHTML = '<div class="spinner"></div>';

                const formData = new FormData();
                formData.append('action', 'verify_code');
                formData.append('otp_code', code);
                formData.append('ajax_verify', '1');

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            otpInputs.forEach(inp => inp.classList.add('success'));
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        } else {
                            otpInputs.forEach(inp => inp.classList.add('error'));
                            container.classList.add('shake');
                            // Remove shake class so it can be re-triggered
                            setTimeout(() => container.classList.remove('shake'), 400);

                            // Restore button
                            btn.style.pointerEvents = 'auto';
                            btn.innerHTML = btnHtml;
                        }
                    })
                    .catch(() => {
                        // Fallback to standard form submission if network error
                        otpForm.submit();
                    });
            }

            const otpForm = document.getElementById('otpForm');
        }
    </script>
    <script src="../assets/js/constellation.js"></script>
    <script src="../assets/mascot/mascot.js"></script>
</body>

</html>