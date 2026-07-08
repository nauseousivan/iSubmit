<?php

/**
 * Academic Registry (register.php)
 * Real-time Academic Milestone & Research Gateway for MCNP-ISAP
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Ensure consistent timezone for OTP generation to prevent immediate expiry
date_default_timezone_set('Asia/Manila');

// Require institutional configuration hooks
require_once '../config/db.php';
require_once '../config/mail.php';
require_once '../config/group_helpers.php';

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
    $group_member_emails = ($role === 'Student') ? trim($_POST['group_member_emails'] ?? '') : null;
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

            // Leader invited teammates by email in Step 4: link immediately if they
            // already have an unlinked account, otherwise leave a pending invite that
            // auto-links (and emails them) once they register.
            if ($role === 'Student' && $is_group_leader && !empty($group_member_emails)) {
                $emails_array = explode(',', $group_member_emails);
                foreach ($emails_array as $m_email) {
                    $m_email = trim($m_email);
                    if (empty($m_email) || !filter_var($m_email, FILTER_VALIDATE_EMAIL) || strcasecmp($m_email, $email) === 0) {
                        continue;
                    }
                    $stmt_existing = $pdo->prepare("SELECT user_id, leader_id, department FROM users WHERE email = ? AND role = 'Student'");
                    $stmt_existing->execute([$m_email]);
                    $existing_member = $stmt_existing->fetch();

                    if ($existing_member && $existing_member['leader_id'] === null && get_dept_code($existing_member['department'] ?? '') === get_dept_code($department)) {
                        $stmt_new_leader = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                        $stmt_new_leader->execute([$new_user_id]);
                        $new_leader_row = $stmt_new_leader->fetch();
                        link_student_to_leader($pdo, $new_user_id, $new_leader_row, $existing_member['user_id']);
                    } else {
                        create_or_reactivate_invite($pdo, $new_user_id, $m_email);
                        send_invite_email($m_email, $username, $group_name ?? '');
                    }
                }
            }

            // Resolve any pending invite addressed to this new account's own email
            // (covers teammates who were invited but self-registered normally,
            // including the common case of leaving Step 1 on its default "Leader" choice).
            consume_pending_invites_for_new_account($pdo, $new_user_id, $email, $leader_id);

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
                $_SESSION['verify_code_expires'] = time() + (15 * 60);
                $_SESSION['verify_resend_cooldown'] = time() + 60; // 1 min cooldown
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="color-scheme" content="light only">
    <title>Register | iSubmit</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
    <!-- Premium Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Link to the main centralized theme CSS -->
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?= time() ?>">
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
    <link rel="stylesheet" href="../assets/mascot/mascot.css?v=<?= time() ?>">
</head>

<body>
    <!-- iSubmit Loading Curtain (Optional, but let's keep it consistent if needed, or skip for register) -->

    <div class="auth-wrapper">
        <div class="auth-card">

            <!-- iSubmit Dynamic Brand Pane -->
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

                <!-- Action Footer (Already Registered) -->
                <div class="pane-action-footer desktop-only" style="margin-top: auto; display: flex; flex-direction: column; align-items: center; gap: 12px;">
                    <div style="font-size: 13.5px; opacity: 0.9; font-weight: 500; color: white;">Already have an account?</div>
                    <a href="login.php" class="mat-btn mat-btn-outline" style="background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.3); color: white;">
                        <i data-lucide="log-in" style="width: 18px; height: 18px; margin-right: 6px;"></i>
                        <span>Sign In</span>
                    </a>
                </div>
            </div>

            <!-- Dynamic Registration Form Panel -->
            <div class="auth-form-pane bottom-sheet">
                <div class="drag-handle"></div>
                <div class="auth-header">
                    <h2>Register Setup</h2>
                    <p>Create your account and start collaborating with your research team.</p>
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
                        <div class="prog-label">Academic</div>
                    </div>
                    <div class="prog-step" id="progNode4">
                        <div class="prog-circle">4</div>
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
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 12px; text-align: center;">What's your role?</div>
                        <div class="role-selection-cards">
                            <label class="role-card">
                                <input type="radio" name="is_group_leader" id="leaderRadio" value="yes" checked onchange="toggleLeaderFields()">
                                <div class="role-card-content">
                                    <i data-lucide="crown" style="width: 32px; height: 32px; color: var(--active-accent); margin-bottom: 12px;"></i>
                                    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 0;">Research Leader</h3>
                                </div>
                            </label>
                            <label class="role-card">
                                <input type="radio" name="is_group_leader" id="memberRadio" value="no" onchange="toggleLeaderFields()">
                                <div class="role-card-content">
                                    <i data-lucide="users" style="width: 32px; height: 32px; color: var(--active-accent); margin-bottom: 12px;"></i>
                                    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 0;">Group Member</h3>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- -----------------------------------------
                 * STEP 2: IDENTITY & AFFILIATION
                 * ----------------------------------------- -->
                    <div class="form-step-section" id="step-section-2" style="display: none;">
                        <div class="mat-input-group">
                            <label>Student researcher / Full Name</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="user" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <input type="text" class="mat-input" name="username" id="usernameInput" placeholder="e.g. Juan De La Cruz" required autocomplete="name">
                            </div>
                        </div>

                        <div class="mat-input-group">
                            <label>Gmail Address</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="mail" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <input type="email" class="mat-input" name="email" id="emailInput" placeholder="example@mcnp.edu.ph" required autocomplete="email">
                            </div>
                        </div>

                        <div class="mat-input-group" style="margin-bottom: 8px;">
                            <label>Password / PIN</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="lock" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <input type="password" class="mat-input" name="password" id="registerPass" placeholder="••••••••" required autocomplete="new-password" onkeyup="validatePassword()">
                                <button type="button" class="password-toggle-btn" onclick="togglePassword('registerPass', this)" style="position: absolute; right: 12px; top: 0; background: transparent; border: none; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; height: 100%;">
                                    <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Strength Rules Box -->
                        <div id="passwordRequirements" style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px; background: var(--bg-surface-variant); padding: 12px; border-radius: 8px; display: none;">
                            <div style="margin-bottom: 4px; font-weight: 600;">Password must contain:</div>
                            <div id="reqLength" style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;"><i data-lucide="circle" style="width:12px; height:12px;"></i> At least 8 characters</div>
                            <div id="reqUpper" style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;"><i data-lucide="circle" style="width:12px; height:12px;"></i> One uppercase letter</div>
                            <div id="reqSpecial" style="display: flex; align-items: center; gap: 6px;"><i data-lucide="circle" style="width:12px; height:12px;"></i> One special character (!@#$%^&*)</div>
                        </div>

                        <div class="mat-input-group">
                            <label>Confirm Password</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="shield-check" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <input type="password" class="mat-input" name="confirm_password" id="confirmPass" placeholder="••••••••" required autocomplete="new-password" onkeyup="validatePasswordMatch()">
                                <button type="button" class="password-toggle-btn" onclick="togglePassword('confirmPass', this)" style="position: absolute; right: 12px; top: 0; background: transparent; border: none; cursor: pointer; color: var(--text-muted); display: flex; align-items: center; height: 100%;">
                                    <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                                </button>
                            </div>
                            <div id="passwordMatchError" style="font-size: 12.5px; color: #dc2626; margin-top: 8px; display: none; font-weight: 500; align-items: center; gap: 4px;">
                                <i data-lucide="alert-circle" style="width: 14px; height: 14px;"></i> Passwords do not match.
                            </div>
                        </div>
                    </div>

                    <!-- -----------------------------------------
                 * STEP 3: ACADEMIC AFFILIATION
                 * ----------------------------------------- -->
                    <div class="form-step-section" id="step-section-3" style="display: none;">
                        <div class="mat-input-group">
                            <label>Department / Institution School</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="landmark" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <select name="department" class="mat-input" id="department" required onchange="updateBrandThemeAndPrograms()">
                                    <option value="" disabled selected>Select Department / School</option>
                                    <option value="Medical Colleges of Northern Philippines">Medical Colleges of Northern Philippines (MCNP)</option>
                                    <option value="International School of Asia and the Pacific">International School of Asia and the Pacific (ISAP)</option>
                                </select>
                            </div>
                        </div>

                        <div class="mat-input-group">
                            <label>Academic Degree Program / Course</label>
                            <div class="mat-input-with-icon">
                                <i data-lucide="shapes" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                <select name="program" class="mat-input" id="program" required>
                                    <option value="" disabled selected>Select Program / Course</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- -----------------------------------------
                 * STEP 4: DEEP RESEARCH PROJECT METRICS
                 * ----------------------------------------- -->
                    <div class="form-step-section" id="step-section-4" style="display: none;">

                        <div id="step3StudentSection">

                            <div class="mat-input-group" id="groupNameField">
                                <label>Research Project Title</label>
                                <div class="mat-input-with-icon">
                                    <i data-lucide="file-text" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                    <input type="text" class="mat-input" id="groupNameInput" name="group_name" placeholder="Enter complete research title">
                                </div>
                            </div>

                            <div class="mat-input-group" id="leaderEmailField" style="display: none;">
                                <label>Your Group Leader's Email</label>
                                <div class="mat-input-with-icon">
                                    <i data-lucide="send" class="mat-icon" style="width: 18px; height: 18px;"></i>
                                    <input type="email" class="mat-input" name="leader_email" placeholder="leader@mcnp.edu.ph" id="leaderEmailInput">
                                </div>
                            </div>

                            <div class="mat-input-group" id="groupMembersContainer">
                                <label>Research Group Mates</label>
                                <div class="member-list-wrapper">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Invite by Email (Excluding Self)</span>
                                        <button type="button" class="btn-add-member" onclick="toggleAddMemberInput(event)">
                                            <i data-lucide="plus" style="width: 13px; height: 13px;"></i> Add Member
                                        </button>
                                    </div>

                                    <div class="add-member-input-group" id="memberInputGroup">
                                        <input type="email" class="mat-input" id="newMemberEmail" placeholder="teammate@mcnp.edu.ph" style="font-size:13px; flex: 1;">
                                        <button type="button" class="mat-btn mat-btn-primary" style="padding: 0 16px; height: 46px;" onclick="saveMemberToArray()">Insert</button>
                                    </div>

                                    <div class="member-pills-container" id="memberPills"></div>
                                    <input type="hidden" name="group_member_emails" id="groupMembersHidden">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-buttons-row" style="display: flex; gap: 12px; margin-top: 32px; justify-content: space-between;">
                        <button type="button" class="mat-btn mat-btn-outline" id="btnPrevStep" onclick="setStep(currentStep - 1)" style="display: none; border-color: var(--border-color); color: var(--text-secondary);">
                            <span>PREVIOUS</span>
                        </button>

                        <button type="button" class="mat-btn mat-btn-primary" id="btnNextStep" onclick="setStep(currentStep + 1)" style="margin-left: auto;">
                            <span>CONTINUE</span>
                        </button>

                        <button type="submit" class="mat-btn mat-btn-primary" id="btnFinalSubmit" style="display: none; margin-left: auto;">
                            <i data-lucide="user-plus" style="width: 18px; height: 18px;"></i>
                            <span>REGISTER</span>
                        </button>
                    </div>
                </form>

                <div style="text-align: center; margin-top: 24px; font-size: 14px; color: var(--text-secondary);" class="mobile-only-link">
                    Already have an account? <a href="login.php" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Sign In</a>
                </div>

                <!-- Minimal Institutional Footer -->
                <div class="auth-footer" style="text-align: center; margin-top: 48px; font-size: 11px; color: #a1a1aa; letter-spacing: 0.5px; font-weight: 400;">
                    Medical Colleges of Northern Philippines <span style="opacity:0.4; margin:0 8px;">|</span> International School of Asia and the Pacific
                </div>

            </div>
        </div>

        <script src="../assets/js/bottom-sheet.js"></script>
        <script>
            const programOptions = {
                "Medical Colleges of Northern Philippines": [
                    "BS Nursing",
                    "BS Radiologic Technology",
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
                    "BS Criminology",
                    "BS Social Work",
                    "BS Secondary Education",
                    "BS Psychology",
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
                    brandOverlay.setAttribute("data-theme", "mcnp");
                    root.style.setProperty('--active-accent', 'var(--primary-color)');
                    if (brandTitle) brandTitle.innerText = "iSubmit";
                    const brandSubLead = document.getElementById('brandSubLead');
                    if (brandSubLead) brandSubLead.innerText = "MCNP Portal - Research Gateway";
                    if (h2Title) h2Title.style.color = 'var(--text-primary)';

                } else if (dept === "International School of Asia and the Pacific") {
                    brandOverlay.setAttribute("data-theme", "isap");
                    root.style.setProperty('--active-accent', 'var(--primary-color)');
                    if (brandTitle) brandTitle.innerText = "iSubmit";
                    const brandSubLead = document.getElementById('brandSubLead');
                    if (brandSubLead) brandSubLead.innerText = "ISAP Portal - Research Gateway";
                    if (h2Title) h2Title.style.color = 'var(--text-primary)';

                } else {
                    brandOverlay.removeAttribute("data-theme");
                    root.style.setProperty('--active-accent', 'var(--primary-color)');
                    if (brandTitle) brandTitle.innerText = "iSubmit";
                    const brandSubLead = document.getElementById('brandSubLead');
                    if (brandSubLead) brandSubLead.innerText = "Research Digitalization Platform";
                    if (h2Title) h2Title.style.color = 'var(--text-primary)';
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
                    document.getElementById('newMemberEmail').focus();
                }
            }

            function saveMemberToArray() {
                const input = document.getElementById('newMemberEmail');
                const email = input.value.trim();
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (email && emailPattern.test(email) && !members.includes(email)) {
                    members.push(email);
                    input.value = "";
                    document.getElementById('memberInputGroup').style.display = 'none';
                    renderMemberPills();
                } else if (email) {
                    input.reportValidity ? (input.setCustomValidity('Enter a valid email address'), input.reportValidity(), input.setCustomValidity('')) : null;
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

            document.getElementById('newMemberEmail')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveMemberToArray();
                }
            });

            // -----------------------------------------
            // CLIENT-SIDE MULTI-STEP NAVIGATION WIZARD
            // -----------------------------------------
            let currentStep = 1;
            const totalSteps = 4;

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

                // Auto-expand sheet on mobile when progressing to step 2 or 3
                if (window.innerWidth <= 768 && window.expandBottomSheet) {
                    window.expandBottomSheet();
                }
            }

            function validatePassword() {
                const pass = document.getElementById('registerPass').value;
                const reqBox = document.getElementById('passwordRequirements');
                const reqLength = document.getElementById('reqLength');
                const reqUpper = document.getElementById('reqUpper');
                const reqSpecial = document.getElementById('reqSpecial');

                if (pass.length > 0) {
                    reqBox.style.display = 'block';
                } else {
                    reqBox.style.display = 'none';
                    return false;
                }

                let valid = true;

                if (pass.length >= 8) {
                    reqLength.style.color = '#16a34a';
                    reqLength.innerHTML = '<i data-lucide="check-circle-2" style="width:12px; height:12px;"></i> At least 8 characters';
                } else {
                    reqLength.style.color = 'var(--text-secondary)';
                    reqLength.innerHTML = '<i data-lucide="circle" style="width:12px; height:12px;"></i> At least 8 characters';
                    valid = false;
                }

                if (/[A-Z]/.test(pass)) {
                    reqUpper.style.color = '#16a34a';
                    reqUpper.innerHTML = '<i data-lucide="check-circle-2" style="width:12px; height:12px;"></i> One uppercase letter';
                } else {
                    reqUpper.style.color = 'var(--text-secondary)';
                    reqUpper.innerHTML = '<i data-lucide="circle" style="width:12px; height:12px;"></i> One uppercase letter';
                    valid = false;
                }

                if (/[!@#$%^&*(),.?":{}|<>]/.test(pass)) {
                    reqSpecial.style.color = '#16a34a';
                    reqSpecial.innerHTML = '<i data-lucide="check-circle-2" style="width:12px; height:12px;"></i> One special character (!@#$%^&*)';
                } else {
                    reqSpecial.style.color = 'var(--text-secondary)';
                    reqSpecial.innerHTML = '<i data-lucide="circle" style="width:12px; height:12px;"></i> One special character (!@#$%^&*)';
                    valid = false;
                }

                // Auto hide if valid
                if (valid && pass.length > 0) {
                    reqBox.style.display = 'none';
                }

                lucide.createIcons();
                validatePasswordMatch();
                return valid;
            }

            function validatePasswordMatch() {
                const pass = document.getElementById('registerPass').value;
                const confirm = document.getElementById('confirmPass').value;
                const error = document.getElementById('passwordMatchError');

                if (confirm.length === 0) {
                    error.style.display = 'none';
                    return false;
                }

                if (pass !== confirm) {
                    error.style.display = 'flex';
                    return false;
                } else {
                    error.style.display = 'none';
                    return true;
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

                if (step === 2 && isValid) {
                    if (!validatePassword() || !validatePasswordMatch()) {
                        isValid = false;
                        const passInput = document.getElementById('registerPass');
                        passInput.reportValidity(); // Optional visual cue
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
                    if (!validateStep(4)) {
                        e.preventDefault();
                    }
                });
            });
        </script>
        
        <!-- Mascot Interactive Script -->
        <script src="../assets/mascot/mascot.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const pass1 = document.getElementById('registerPass');
                const pass2 = document.getElementById('confirmPass');
                
                const handleFocus = () => { if(window.Quill) window.Quill.coverEyes(); };
                const handleBlur = () => { if(window.Quill) window.Quill.idle(); };

                if (pass1) { pass1.addEventListener('focus', handleFocus); pass1.addEventListener('blur', handleBlur); }
                if (pass2) { pass2.addEventListener('focus', handleFocus); pass2.addEventListener('blur', handleBlur); }
            });
        </script>
        
        <script src="../assets/js/constellation.js"></script>
</body>

</html>