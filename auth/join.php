<?php
// auth/join.php — JOIN A GROUP BY QR (2026-10-07).
//
// The leader shows a QR on dashboards/members.php; it opens this page with ?t=TOKEN (signed, 24h —
// group_join_leader() in config/group_helpers.php). The teammate types name, School ID, email and a
// password and is created ALREADY LINKED to that leader: school, course, section and research title
// come from the leader, exactly like the leader-email flow in register.php. There is NO emailed code:
// the leader handing over the QR in person is the check, so the account is verified on creation.
// Registering the normal way (register.php) still sends the code.
require_once '../config/db.php';
require_once '../config/group_helpers.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Success lands here after the redirect (POST -> redirect -> GET, so a refresh cannot sign up twice).
$done = null;
if (isset($_GET['done']) && !empty($_SESSION['join_done'])) {
    $done = $_SESSION['join_done'];
    unset($_SESSION['join_done']);
}

$token  = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$why    = null;
$leader = $done ? null : group_join_leader($pdo, $token, $why);
$closed = function_exists('rd_setting') && rd_setting('registration_open', '1') !== '1';

$section = null;
if ($leader && $leader['section_id']) {
    $st = $pdo->prepare("SELECT program, year_level, label FROM sections WHERE section_id = ?");
    $st->execute([(int)$leader['section_id']]);
    $section = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$msg = '';
$old = ['name' => '', 'student_number' => '', 'email' => ''];

if ($leader && !$closed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'student_number', 'email'] as $k) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Your session expired. Scan the QR again and retry.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $msg = 'Something went wrong. Please try again.';            // honeypot: a bot filled the hidden field
    } elseif ($old['name'] === '' || mb_strlen($old['name']) > 100) {
        $msg = 'Please type your full name.';
    } elseif (!preg_match('/^\d{4}-\d{4,6}$/', $old['student_number'])) {
        $msg = 'Please enter your School ID Number in the format 2023-10078.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 100) {
        $msg = 'Please enter a valid email address. You will use it to sign in.';
    } elseif (strlen($pw) < 8) {
        $msg = 'Your password needs 8 characters or more.';
    } elseif ($pw !== $pw2) {
        $msg = 'The two passwords do not match.';
    } elseif (empty($_POST['privacy_consent'])) {                    // Data Privacy Act consent, as on register.php
        $msg = 'Please read and agree to the Privacy Notice to join.';
    } else {
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE student_number = ?");
        $dup->execute([$old['student_number']]);
        $taken_id = (bool)$dup->fetchColumn();
        $dup = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
        $dup->execute([$old['email']]);
        $taken_mail = (bool)$dup->fetchColumn();

        if ($taken_id) {
            $msg = 'That School ID Number already has an account. Sign in instead, and ask your leader to add you from the members page.';
        } elseif ($taken_mail) {
            $msg = 'That email already has an account. Sign in instead, and ask your leader to add you from the members page.';
        } else {
            // Group facts from the leader (never from the form); personal facts from the form.
            $grp = trim((string)$leader['research_group_name']) !== '' ? $leader['research_group_name'] : 'My Group';
            $pdo->prepare("INSERT INTO users (username, password, email, student_number, role, department, program, year_level,
                                              section_id, research_group_name, leader_id, is_verified, account_status)
                           VALUES (?, ?, ?, ?, 'Student', ?, ?, NULL, ?, ?, ?, 1, 'active')")
                ->execute([$old['name'], password_hash($pw, PASSWORD_BCRYPT), $old['email'], $old['student_number'],
                           $leader['department'], $leader['program'], $leader['section_id'], $grp, (int)$leader['user_id']]);
            $new_id = (int)$pdo->lastInsertId();
            // An emailed invite from the same leader is now fulfilled; anyone else's is cancelled.
            consume_pending_invites_for_new_account($pdo, $new_id, $old['email'], (int)$leader['user_id']);

            $_SESSION['join_done'] = ['email' => $old['email'], 'group' => $grp, 'leader' => $leader['username']];
            header('Location: join.php?done=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Your Group | iSubmit</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/auth_card.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth_card.css') ?>">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
    <style>
        /* The group being joined: what the QR opened, before anything is typed. */
        .jn-group { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 16px; background: #f5f3ff; border: 1px solid #e4defd; margin: 0 0 20px; }
        .jn-group-ic { width: 40px; height: 40px; border-radius: 12px; background: #5b50f5; color: #fff; display: grid; place-items: center; flex-shrink: 0; }
        .jn-group-ic svg, .jn-group-ic i { width: 20px; height: 20px; }
        .jn-group b { display: block; font-size: 14.5px; color: #2e1065; line-height: 1.35; }
        .jn-group span { display: block; font-size: 12.5px; color: #6b6590; margin-top: 3px; line-height: 1.4; }
        .jn-pw { position: relative; }
        .jn-eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: #8b83b3; cursor: pointer; padding: 6px; line-height: 0; }
        .jn-eye svg, .jn-eye i { width: 18px; height: 18px; }
        .jn-done-cta { display: flex; justify-content: center; margin-top: 8px; text-decoration: none; }
    </style>
</head>
<body class="ac-page">
<div class="ac-wrap auth-card">
    <header class="ac-hero">
        <div class="ac-hero-in">
            <div class="ac-badge"><?php $mascot_idle = $done ? 'celebrating' : ($leader ? 'plain' : 'oops'); include __DIR__ . '/../assets/mascot/mascot.php'; ?></div>
            <div class="ac-title">iSubmit</div>
            <p class="ac-sub" id="brandSubLead">Join your research group</p>
            <p class="ac-blurb">Your leader's QR brings you straight into the group. No email code needed: just tell us who you are and choose a password.</p>
        </div>
    </header>
    <main class="ac-card bottom-sheet">
        <div class="ac-handle"></div>

        <?php if ($done): ?>
            <h1>You're in!</h1>
            <p>You joined <b><?= $h($done['group']) ?></b>, <?= $h($done['leader']) ?>'s group. Sign in with <b><?= $h($done['email']) ?></b> and the password you just chose.</p>
            <div class="ac-alert ok">Account created and linked to your group.</div>
            <a class="ac-btn jn-done-cta" href="login.php">Sign in now</a>

        <?php elseif (!$leader): ?>
            <h1>This QR can't be used</h1>
            <div class="ac-alert bad"><?= $h($why ?: 'This link is not a valid group invite.') ?></div>
            <p>Ask your group leader to open <b>Research Group</b> in iSubmit and show you a fresh QR, or register the usual way.</p>
            <div class="ac-foot"><a href="register.php">Register instead</a><a href="login.php">Sign in</a></div>

        <?php elseif ($closed): ?>
            <h1>Registration is closed</h1>
            <div class="ac-alert bad">The research office has closed sign-ups for now. Please check back later.</div>
            <div class="ac-foot"><a href="login.php">&larr; Sign in</a></div>

        <?php else: ?>
            <h1>Join the group</h1>
            <p>You'll be added as a member. Your school, course and section come from your leader.</p>

            <div class="jn-group">
                <span class="jn-group-ic"><i data-lucide="users"></i></span>
                <div>
                    <b><?= $h(trim((string)$leader['research_group_name']) !== '' ? $leader['research_group_name'] : 'Research group') ?></b>
                    <span>Leader: <?= $h($leader['username']) ?></span>
                    <span><?= $h(get_dept_code($leader['department'] ?? '')) ?><?= $leader['program'] ? ' · ' . $h($leader['program']) : '' ?><?= $section ? ' · ' . $h(trim(($section['year_level'] ?? '') . ' Section ' . $section['label'])) : '' ?></span>
                </div>
            </div>

            <?php if ($msg): ?><div class="ac-alert bad"><?= $h($msg) ?></div><?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="t" value="<?= $h($token) ?>">
                <input type="text" name="website" class="ac-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                <div class="ac-field">
                    <label for="jnName">Full name</label>
                    <input class="ac-in" id="jnName" name="name" required maxlength="100" value="<?= $h($old['name']) ?>" autocomplete="name" placeholder="e.g. Juan Dela Cruz">
                </div>
                <div class="ac-field">
                    <label for="jnId">School ID Number</label>
                    <input class="ac-in" id="jnId" name="student_number" required maxlength="20" value="<?= $h($old['student_number']) ?>" placeholder="2023-10078" inputmode="numeric" pattern="\d{4}-\d{4,6}">
                </div>
                <div class="ac-field">
                    <label for="jnEmail">Email <small>you'll sign in with this</small></label>
                    <input class="ac-in" id="jnEmail" type="email" name="email" required maxlength="100" value="<?= $h($old['email']) ?>" autocomplete="email" placeholder="you@mcnp.edu.ph">
                </div>
                <div class="ac-2">
                    <div class="ac-field">
                        <label for="jnPw">Password <small>8 or more</small></label>
                        <div class="jn-pw">
                            <input class="ac-in" id="jnPw" type="password" name="password" required minlength="8" autocomplete="new-password">
                            <button type="button" class="jn-eye" onclick="togglePassword('jnPw', this)" aria-label="Show password"><i data-lucide="eye"></i></button>
                        </div>
                    </div>
                    <div class="ac-field">
                        <label for="jnPw2">Type it again</label>
                        <input class="ac-in" id="jnPw2" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;margin:4px 0 14px;font-size:13px;line-height:1.5;color:#5d5a78;cursor:pointer">
                    <input type="checkbox" name="privacy_consent" value="1" required style="width:18px;height:18px;margin-top:2px;flex:none;accent-color:#5b50f5">
                    <span>I have read the <a href="../privacy.php" target="_blank" rel="noopener" style="color:#3f35c9;font-weight:700">Privacy Notice</a> and agree that the Research &amp; Development Center processes my personal information and research files for the research process, under the Data Privacy Act of 2012 (RA 10173).</span>
                </label>
                <button type="submit" class="ac-btn">Join the group</button>
            </form>
            <div class="ac-foot"><a href="login.php">Already have an account? Sign in</a></div>
        <?php endif; ?>
    </main>
</div>
<script>
    // Show / hide the password. Named togglePassword so the mascot peeks, as on the sign-in page.
    function togglePassword(id, btn) {
        var input = document.getElementById(id), show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        document.getElementById('jnPw2').type = input.type;
        btn.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>';
        if (window.lucide) lucide.createIcons();
    }
</script>
<script src="../assets/mascot/mascot.js"></script>
<script src="../assets/js/bottom-sheet.js"></script>
<script>
    if (window.lucide) lucide.createIcons();
    // The eagle covers her eyes while a password is typed, as everywhere else.
    document.querySelectorAll('input[type=password]').forEach(function (i) {
        i.addEventListener('focus', function () { if (window.Quill) window.Quill.coverEyes(); });
        i.addEventListener('blur', function () { if (window.Quill) window.Quill.idle(); });
    });
    // Phone: typing in a field lifts the sheet, so the keyboard never covers a half-hidden form.
    var sheet = document.querySelector('.ac-card');
    if (sheet) sheet.addEventListener('focusin', function (e) {
        if (e.target.matches('.ac-in') && window.expandBottomSheet) window.expandBottomSheet();
    });
</script>
</body>
</html>
