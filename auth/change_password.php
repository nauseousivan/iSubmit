<?php
// auth/change_password.php — choose your own password (2026-10-01).
//
// Where anyone the Admin reset lands, and stays: config/db.php sends every other page here while
// users.must_change_password = 1. The Admin knew the temporary password, so it must not stay in use.
require_once '../config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$uid = (int)$_SESSION['user_id'];
$me  = $pdo->prepare("SELECT username, email, role, password, must_change_password FROM users WHERE user_id = ?");
$me->execute([$uid]);
$me = $me->fetch(PDO::FETCH_ASSOC);
if (!$me) {
    header('Location: logout.php');
    exit;
}
$home = '../dashboards/' . rd_home_for((string)$me['role']);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string)($_POST['new_password'] ?? '');
    $cnf = (string)($_POST['confirm_password'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Your session expired. Refresh the page and try again.';
    } elseif (strlen($new) < 8) {
        $msg = 'Your new password needs 8 characters or more.';
    } elseif ($new !== $cnf) {
        $msg = 'The two passwords do not match.';
    } elseif (password_verify($new, (string)$me['password'])) {
        $msg = 'Choose a password different from the temporary one.';
    } else {
        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
        session_regenerate_id(true);
        header('Location: ' . $home);
        exit;
    }
}
$forced = (int)$me['must_change_password'] === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Password | iSubmit</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/auth_card.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth_card.css') ?>">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
</head>
<body class="ac-page">
<div class="ac-wrap auth-card">
    <header class="ac-hero">
        <div class="ac-hero-in">
            <div class="ac-badge"><?php $mascot_idle = 'plain'; include __DIR__ . '/../assets/mascot/mascot.php'; /* covers her eyes while you type (below) */ ?></div>
            <div class="ac-title">iSubmit</div>
            <p class="ac-sub" id="brandSubLead">Research Digitalization Platform</p>
        </div>
    </header>
    <main class="ac-card bottom-sheet">
        <div class="ac-handle"></div>
        <h1>Choose your new password</h1>
        <p><?= $forced
            ? 'Hi ' . htmlspecialchars($me['username']) . '. The administrator reset your password. Choose your own now: the temporary one stops working once you do.'
            : 'Choose a new password for ' . htmlspecialchars($me['email']) . '.' ?></p>
        <?php if ($msg): ?><div class="ac-alert bad"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="ac-field">
                <label for="np">New password <small>8 characters or more</small></label>
                <input class="ac-in" type="password" id="np" name="new_password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="ac-field">
                <label for="cp">Type it again</label>
                <input class="ac-in" type="password" id="cp" name="confirm_password" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="ac-btn">Save and continue</button>
        </form>
        <div class="ac-foot">
            <a href="help.php">Need help?</a>
            <a href="logout.php">Log out</a>
        </div>
    </main>
</div>
<script src="../assets/mascot/mascot.js"></script>
<script src="../assets/js/bottom-sheet.js"></script>
<script>
    // The eagle covers her eyes while a password is being typed, as on the sign-in page.
    document.querySelectorAll('input[type=password]').forEach(function (i) {
        i.addEventListener('focus', function () { if (window.Quill) window.Quill.coverEyes(); });
        i.addEventListener('blur', function () { if (window.Quill) window.Quill.idle(); });
    });
</script>
</body>
</html>
