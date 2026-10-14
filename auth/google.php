<?php
// auth/google.php — "Continue with Google" starts here (config/google_auth.php explains the flow).
// A one-time state value ties Google's answer to THIS browser session, so nobody can hand a visitor someone
// else's sign-in.
require_once '../config/db.php';
require_once '../config/google_auth.php';

if (!rd_google_enabled()) {
    $_SESSION['login_notice'] = ['Google sign-in is not set up on this server yet. Please use your email and password.', 'error'];
    header('Location: login.php');
    exit();
}

$_SESSION['google_state'] = ['v' => bin2hex(random_bytes(16)), 'at' => time()];
header('Location: ' . rd_google_auth_url($_SESSION['google_state']['v']));
exit();
