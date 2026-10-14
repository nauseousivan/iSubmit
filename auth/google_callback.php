<?php
// auth/google_callback.php — Google sends the visitor back here (the "Authorized redirect URI").
//
// An account with this email exists  -> the same gates as a password sign-in (login.php): maintenance mode,
//                                       account_status, logged to the access log; then signed in.
// No account                         -> a SIGN-UP: register.php opens with the name and email from Google;
//                                       the student still gives School ID, institution, course, year, section,
//                                       group and the privacy consent there. Students only.
require_once '../config/db.php';
require_once '../config/google_auth.php';

$back = function (string $msg, string $type = 'error'): never {
    $_SESSION['login_notice'] = [$msg, $type];
    header('Location: login.php');
    exit();
};

if (!rd_google_enabled()) $back('Google sign-in is not set up on this server yet.');
if (isset($_GET['error'])) $back('Google sign-in was cancelled.', 'warning');     // they closed Google's screen

$st = $_SESSION['google_state'] ?? null;
unset($_SESSION['google_state']);                                                   // one use only
if (!$st || !hash_equals($st['v'], (string)($_GET['state'] ?? '')) || time() - $st['at'] > 600) {
    $back('That sign-in link expired. Please press Continue with Google again.');
}

$who = rd_google_identity((string)($_GET['code'] ?? ''), $err);
if (!$who) $back($err ?: 'Google sign-in failed. Please try again.');

$stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
$stmt->execute([$who['email']]);
$user = $stmt->fetch();

if ($user) {
    $uid = (int)$user['user_id'];
    if (rd_setting('maintenance_mode') === '1' && $user['role'] !== 'Admin') {
        rd_log_login($pdo, $who['email'], $uid, false, 'maintenance');
        $back('The system is under maintenance. Please try again later.');
    }
    if (($user['account_status'] ?? 'active') !== 'active') {
        rd_log_login($pdo, $who['email'], $uid, false, 'inactive');
        $back([
            'pending'  => 'Your account is waiting for the administrator\'s approval. You can sign in once it is approved.',
            'rejected' => 'This account was not approved. Please contact the research office.',
            'disabled' => 'This account has been disabled. Please contact the research office.',
        ][$user['account_status']] ?? 'This account cannot sign in. Please contact the research office.');
    }
    if (empty($user['is_verified'])) {
        // Google has just proved this person owns the email, which is all the email code proves — for a STUDENT.
        // A staff account keeps is_verified and account_status in step (CLAUDE.md), so it is not touched here.
        if ($user['role'] !== 'Student') {
            rd_log_login($pdo, $who['email'], $uid, false, 'unverified');
            $back('Please sign in with your email and password first.');
        }
        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?")->execute([$uid]);
    }
    rd_log_login($pdo, $who['email'], $uid, true, 'google');
    header('Location: ' . rd_session_login($pdo, $user));
    exit();
}

// No account yet: only students sign themselves up, and only while registration is open.
if (rd_setting('registration_open', '1') !== '1') {
    $back('Registration is closed right now. Please check back later or ask the research office.');
}
$_SESSION['google_signup'] = ['email' => $who['email'], 'name' => $who['name'], 'sub' => $who['sub'], 'at' => time()];
header('Location: register.php?google=1');
exit();
