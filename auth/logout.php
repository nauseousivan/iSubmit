<?php
// Ends the session completely: clears its data, deletes the cookie, then destroys it server-side,
// so the next sign-in starts from a fresh session instead of a half-dead one.
session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();
header("Location: login.php");
exit();
