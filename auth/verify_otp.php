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
        if (isset($_SESSION['verify_resend_lockout']) && time() < $_SESSION['verify_resend_lockout']) {
            $message = "Too many requests. Please try again in " . ceil(($_SESSION['verify_resend_lockout'] - time()) / 60) . " minutes.";
            $message_type = "error";
        } else {
            $_SESSION['verify_resend_attempts'] = ($_SESSION['verify_resend_attempts'] ?? 0) + 1;
            if ($_SESSION['verify_resend_attempts'] > 3) {
                $_SESSION['verify_resend_lockout'] = time() + (30 * 60);
                $message = "Too many requests. Please try again in 30 minutes.";
                $message_type = "error";
            } else {
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
                        $_SESSION['verify_code_expires'] = time() + (15 * 60);
                        $_SESSION['verify_resend_cooldown'] = time() + 60;
                        header("Location: verify_otp.php?resend_success=1");
                        exit();
                    } else {
                        $msg = "Institutional MTA failed to transmit OTP packet. Review configuration.";
                        if ($is_ajax) {
                            echo json_encode(['status' => 'error', 'message' => $msg]);
                            exit;
                        }
                        $message = $msg;
                        $message_type = "error";
                    }
                }
            }
        }
    } elseif (isset($_POST['otp_code'])) {
        $entered_otp = trim($_POST['otp_code']);
        $is_ajax = isset($_POST['ajax_verify']);

        // Verify code against latest record that isn't expired
        $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $entered_otp]);
        $row = $stmt->fetch();

        if (!$row) {
            if ($is_ajax) {
                echo json_encode(['status' => 'error', 'message' => 'The confirmation credentials entered are invalid.']);
                exit;
            }
            $message = "The confirmation credentials entered are invalid. Verify and try again.";
            $message_type = "error";
        } else {
            $expires_at = $row['expires_at'];
            if (strtotime($expires_at) < time()) {
                if ($is_ajax) {
                    echo json_encode(['status' => 'error', 'message' => 'The activation key has expired.']);
                    exit;
                }
                $message = "The activation key has expired. Request a temporary token replacement.";
                $message_type = "error";
            } else {
                // Get user department first to persist styling on success screen
                $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE email = ? LIMIT 1");
                $stmt_dept->execute([$email]);
                $user_row = $stmt_dept->fetch();
                $dept_val = $user_row ? $user_row['department'] : '';

                // Clean up verification tokens
                $del = $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?");
                $del->execute([$email]);

                $stmt_upd = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE email = ?");
                if ($stmt_upd->execute([$email])) {
                    $_SESSION['verify_success'] = true;
                    $_SESSION['verified_department'] = $dept_val;
                    unset($_SESSION['verify_resend_attempts'], $_SESSION['verify_resend_lockout']);

                    if ($is_ajax) {
                        echo json_encode(['status' => 'success']);
                        exit;
                    }

                    header("Location: verify_otp.php");
                    exit();
                } else {
                    if ($is_ajax) {
                        echo json_encode(['status' => 'error', 'message' => 'System error. Contact support.']);
                        exit;
                    }
                    $message = "System error during final activation. Contact support.";
                    $message_type = "error";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>OTP Verification | iSubmit</title>
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
                    <h1 id="brandTitle" class="brand-title" style="color: white; font-weight: 800; margin-bottom: 4px; margin-top: 0; letter-spacing: -0.5px; font-family: 'Poppins', sans-serif;">Verification</h1>
                    <p id="brandSubLead" class="brand-sub-lead" style="color: white; opacity: 0.95; font-weight: 500; margin-top: 0; font-family: 'Plus Jakarta Sans', sans-serif;">Institutional Registration</p>
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

                <?php if ($verify_success): ?>
                    <!-- SUCCESS VIEW -->
                    <div style="text-align: center; padding: 24px 0;">
                        <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; background: rgba(34, 197, 94, 0.1); color: #22c55e; margin-bottom: 24px;">
                            <i data-lucide="check" style="width: 40px; height: 40px;"></i>
                        </div>
                        <h2 style="font-size: 24px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; font-family: 'Poppins', sans-serif;">Verification Complete!</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 32px; font-size: 15px; line-height: 1.6;">Your email address has been successfully verified. Your account is now fully active.</p>

                        <a href="login.php" class="mat-btn mat-btn-primary" style="width: 100%; justify-content: center; font-size: 15px; padding: 16px; text-decoration: none;">
                            PROCEED TO LOGIN
                        </a>
                    </div>
                    <?php unset($_SESSION['verify_success']); ?>
                <?php else: ?>
                    <!-- VERIFICATION FORM -->
                    <div class="auth-header">
                        <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); font-weight: 700; margin-bottom: 8px; display: block;">Verification Code</span>
                        <h2>Confirm Access</h2>
                        <p>Enter the 6-digit confirmation code sent to <br><strong style="color: var(--primary-color);"><?php echo htmlspecialchars($email); ?></strong></p>
                    </div>

                    <form action="" method="POST" autocomplete="off" id="otpForm">
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
                            VERIFY MY ACCOUNT
                        </button>
                    </form>

                    <form action="" method="POST" style="text-align: center; margin-top: 24px;">
                        <input type="hidden" name="resend_code" value="1">
                        <button type="submit" id="resendBtn" class="mat-btn mat-btn-text" style="color: var(--text-secondary); width: 100%; justify-content: center;">
                            <i data-lucide="refresh-cw" style="width: 16px; height: 16px; margin-right: 6px;"></i>
                            <span id="resendText">Resend Code</span>
                        </button>
                    </form>
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
        const codeExpires = <?php echo ($_SESSION['verify_code_expires'] ?? 0) * 1000; ?>;
        const resendCooldown = <?php echo ($_SESSION['verify_resend_cooldown'] ?? 0) * 1000; ?>;

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

        // OTP Input Logic
        const otpInputs = document.querySelectorAll('.otp-input');
        const actualOtpInput = document.getElementById('actualOtpInput');
        const otpForm = document.getElementById('otpForm');

        if (otpInputs.length > 0) {
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    let val = input.value.replace(/[^0-9]/g, '');

                    if (val.length > 1) {
                        // Distribute across inputs for autocomplete/paste
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

            if (otpForm) {
                otpForm.addEventListener('submit', (e) => {
                    updateActualOtp();
                    if (actualOtpInput.value.length !== 6) {
                        e.preventDefault();
                        alert('Please enter the complete 6-digit verification code.');
                    }
                });
            }
        }
        lucide.createIcons();
        
        // Mascot Password/OTP Interactions
        document.addEventListener('DOMContentLoaded', () => {
            const handleFocus = () => { if(window.Quill) window.Quill.coverEyes(); };
            const handleBlur = () => { if(window.Quill) window.Quill.idle(); };

            const otpInputs = document.querySelectorAll('.otp-input');
            otpInputs.forEach(input => {
                input.addEventListener('focus', handleFocus);
                input.addEventListener('blur', handleBlur);
            });
        });
    </script>
    <script src="../assets/js/constellation.js"></script>
    <script src="../assets/mascot/mascot.js"></script>
</body>

</html>