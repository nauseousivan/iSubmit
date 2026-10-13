<?php
// auth/help.php — the Help Desk form (2026-10-01).
//
// For anyone who cannot fix it themselves: lost the inbox the email reset goes to, typed the wrong
// email or School ID at sign-up, needs moving to another group, or has a suggestion for the system.
// It used to be a walk to the IT office. Open to guests (from the login page) and to signed-in
// users, whose request is tied to their account and who see their earlier requests and the
// Admin's replies below the form. Answered in dashboards/admin_help.php.
require_once '../config/db.php';

$cats    = rd_help_categories();
$me      = null;
if (!empty($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT user_id, username, email, student_number, role FROM users WHERE user_id = ?");
    $st->execute([(int)$_SESSION['user_id']]);
    $me = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$back = $me ? '../dashboards/' . rd_home_for((string)$me['role']) : 'login.php';

$msg = ''; $ok = false;
$old = ['name' => $me['username'] ?? '', 'email' => $me['email'] ?? '', 'student_number' => $me['student_number'] ?? '',
        'category' => isset($cats[$_GET['c'] ?? '']) ? $_GET['c'] : ($me ? 'other' : 'email_access'), 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'email', 'student_number', 'category', 'message'] as $k) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Your session expired. Refresh the page and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $ok = true;                                   // honeypot filled: pretend it worked, store nothing
    } elseif ($old['name'] === '' || mb_strlen($old['name']) > 100) {
        $msg = 'Tell us your full name.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = 'Give an email address we can reach you at.';
    } elseif ($old['student_number'] !== '' && !preg_match('/^\d{4}-\d{4,6}$/', $old['student_number'])) {
        $msg = 'Your School ID Number should look like 2023-10078, or leave it blank.';
    } elseif (!isset($cats[$old['category']])) {
        $msg = 'Pick what you need help with.';
    } elseif (mb_strlen($old['message']) < 10) {
        $msg = 'Tell us a little more (10 characters or more).';
    } elseif (mb_strlen($old['message']) > 3000) {
        $msg = 'That is too long — 3000 characters at most.';
    } else {
        // Five requests an hour from one address: enough for a real person, not for a flood.
        $n = $pdo->prepare("SELECT COUNT(*) FROM help_requests WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR");
        $n->execute([$ip]);
        if ((int)$n->fetchColumn() >= 5) {
            $msg = 'You have sent several requests already. Please wait for a reply before sending more.';
        } else {
            $pdo->prepare("INSERT INTO help_requests (user_id, name, email, student_number, category, message, ip) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$me ? (int)$me['user_id'] : null, $old['name'], $old['email'],
                           $old['student_number'] !== '' ? $old['student_number'] : null, $old['category'], $old['message'], $ip]);
            $ok = true;
        }
    }
    if ($ok) {
        // Redirect-after-POST: a refresh must not send the same request twice.
        $_SESSION['help_sent_to'] = $old['email'];
        header('Location: help.php');
        exit;
    }
}
if (!empty($_SESSION['help_sent_to'])) {
    $ok = true;
    $old['email'] = (string)$_SESSION['help_sent_to'];
    unset($_SESSION['help_sent_to']);
}

// A signed-in user's own requests, newest first, with the Admin's reply.
$mine = [];
if ($me) {
    $st = $pdo->prepare("SELECT category, message, status, admin_reply, DATE_FORMAT(created_at, '%b %e, %Y') sent_on,
                                DATE_FORMAT(resolved_at, '%b %e') replied_on
                         FROM help_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $st->execute([(int)$me['user_id']]);
    $mine = $st->fetchAll(PDO::FETCH_ASSOC);
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Desk | iSubmit</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/auth_card.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth_card.css') ?>">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
</head>
<body class="ac-page">
<?php /* .auth-card / .bottom-sheet / #brandSubLead are what assets/js/bottom-sheet.js (the login sheet) looks for */ ?>
<div class="ac-wrap auth-card">
    <header class="ac-hero">
        <div class="ac-hero-in">
            <div class="ac-badge"><?php $mascot_idle = 'thinking'; include __DIR__ . '/../assets/mascot/mascot.php'; /* happy once sent, worried on an error (mascot.js reads .ac-alert) */ ?></div>
            <div class="ac-title">iSubmit</div>
            <p class="ac-sub" id="brandSubLead">Help Desk</p>
            <p class="ac-blurb">Locked out, or something on your account is wrong? The administrator answers here, so there is no need to walk to the IT office.</p>
        </div>
    </header>
    <main class="ac-card bottom-sheet">
        <div class="ac-handle"></div>
        <h1>How can we help?</h1>
        <p>Pick what's wrong and tell the administrator a little about it. You'll get the reply by email<?= $me ? ' and on this page' : '' ?>.</p>

        <?php if ($ok): ?>
            <div class="ac-alert ok">Sent. The administrator will reply to <?= $h($old['email']) ?>.</div>
        <?php elseif ($msg): ?>
            <div class="ac-alert bad"><?= $h($msg) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
            <input type="text" name="website" class="ac-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="ac-field">
                <span class="ac-label">What do you need?</span>
                <div class="ac-cats">
                    <?php $cat_icons = ['email_access' => 'mail-x', 'password' => 'key-round', 'fix_details' => 'id-card',
                                        'group_change' => 'users', 'suggestion' => 'lightbulb', 'other' => 'message-circle-question'];
                    foreach ($cats as $k => $label): ?>
                        <label class="ac-cat"><input type="radio" name="category" value="<?= $k ?>" <?= $old['category'] === $k ? 'checked' : '' ?>>
                            <span class="ac-cat-ic"><i data-lucide="<?= $cat_icons[$k] ?? 'circle-help' ?>"></i></span><?= $h($label) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ac-2">
                <div class="ac-field">
                    <label for="hn">Full name</label>
                    <input class="ac-in" id="hn" name="name" required maxlength="100" value="<?= $h($old['name']) ?>" autocomplete="name">
                </div>
                <div class="ac-field">
                    <label for="hs">School ID Number <small>if you are a student</small></label>
                    <input class="ac-in" id="hs" name="student_number" maxlength="20" placeholder="2023-10078" value="<?= $h($old['student_number']) ?>">
                </div>
            </div>
            <div class="ac-field">
                <label for="he">Email we can reach you at <small>it can be a different one from your account</small></label>
                <input class="ac-in" id="he" type="email" name="email" required maxlength="100" value="<?= $h($old['email']) ?>" autocomplete="email">
            </div>
            <div class="ac-field">
                <label for="hm">Tell us what happened</label>
                <textarea class="ac-in" id="hm" name="message" required minlength="10" maxlength="3000"
                          placeholder="e.g. I registered with a wrong email and can't receive the reset code. My account email should be ..."><?= $h($old['message']) ?></textarea>
            </div>
            <button type="submit" class="ac-btn">Send to the administrator</button>
        </form>

        <div class="ac-foot">
            <a href="<?= $h($back) ?>">&larr; <?= $me ? 'Back to my dashboard' : 'Back to sign in' ?></a>
            <?php if (!$me): ?><a href="forgot_password.php">Reset by email instead</a><?php endif; ?>
        </div>

        <?php if ($mine): ?>
            <div class="ac-mine">
                <h2>Your requests</h2>
                <?php foreach ($mine as $r): ?>
                    <div class="ac-req">
                        <div class="ac-req-top"><?= $h($cats[$r['category']] ?? $r['category']) ?>
                            <span class="<?= $r['status'] ?>"><?= $r['status'] === 'open' ? 'Waiting' : 'Answered' ?></span></div>
                        <p><?= $h($r['message']) ?></p>
                        <?php if ($r['admin_reply']): ?><p class="reply"><b>Administrator:</b> <?= $h($r['admin_reply']) ?></p><?php endif; ?>
                        <small>Sent <?= $h($r['sent_on']) ?><?= $r['replied_on'] ? ' &middot; answered ' . $h($r['replied_on']) : '' ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/mascot/mascot.js"></script>
<script src="../assets/js/bottom-sheet.js"></script>
<script>
    if (window.lucide) lucide.createIcons();
    // Phone: typing in a field lifts the sheet, so the keyboard never covers a half-hidden form.
    document.querySelector('.ac-card').addEventListener('focusin', function (e) {
        if (e.target.matches('.ac-in') && window.expandBottomSheet) window.expandBottomSheet();
    });
</script>
</body>
</html>
