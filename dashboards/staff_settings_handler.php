<?php
// dashboards/staff_settings_handler.php — the staff Settings screen's two saves (2026-09-29).
//
// Shared by director.php, coordinator.php and statistician.php (via _staff_settings.php) and
// answered as JSON, so a save never reloads the shell. The old shell handlers posted back to
// the shell and redirected to Home — the save worked, but nothing on the new home showed the
// picture and the redirect left Settings, so it looked like nothing had happened.
//
// Only ever writes the CALLER's own row. Colour and light/dark are per-browser (portal.js) and
// never come here.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/staff_settings_lib.php';

header('Content-Type: application/json');

function ss_out(bool $ok, string $msg, array $extra = []): void
{
    echo json_encode(['ok' => $ok, 'message' => $msg] + $extra);
    exit;
}

$staff_roles = ['Research Coordinator', 'Research Director', 'Statistician', 'Admin', 'Focal Person'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $staff_roles, true)) {
    http_response_code(403);
    ss_out(false, 'Access denied.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ss_out(false, 'POST only.');
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    ss_out(false, 'Your session expired. Refresh the page and try again.');
}

$uid    = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');

if ($action === 'save_profile') {
    $name = trim((string)($_POST['username'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        ss_out(false, 'Enter a display name (up to 80 characters).');
    }

    $cur = $pdo->prepare("SELECT profile_pic FROM users WHERE user_id = ?");
    $cur->execute([$uid]);
    $pic = (string)$cur->fetchColumn();

    // A photo wins over a preset: picking a file is the more deliberate act.
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) ss_out(false, 'The photo did not upload. Try again.');
        if ($f['size'] > 3 * 1024 * 1024) ss_out(false, 'That photo is over 3 MB. Pick a smaller one.');
        // Trust the bytes, not the name: the extension comes from what the image actually is.
        $info = @getimagesize($f['tmp_name']);
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
        if (!$info || !$ext) ss_out(false, 'That file is not a JPG, PNG, WEBP or GIF image.');
        $dir = '../uploads/profiles/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest = $dir . 'pfp_' . $uid . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) ss_out(false, 'Could not save the photo.');
        $pic = $dest;
    } else {
        $avatar = trim((string)($_POST['avatar'] ?? ''));
        // Only the gallery's own URLs are accepted — never an arbitrary link into the page.
        if ($avatar !== '' && in_array($avatar, ss_avatar_urls(), true)) $pic = $avatar;
    }

    $pdo->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE user_id = ?")
        ->execute([$name, $pic !== '' ? $pic : null, $uid]);
    $_SESSION['username'] = $name;
    $_SESSION['profile_pic'] = $pic;

    ss_out(true, 'Profile saved.', ['username' => $name, 'pic' => $pic, 'initials' => ss_initials($name)]);
}

if ($action === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($current === '' || $new === '') ss_out(false, 'Fill in your current and new password.');
    if (strlen($new) < 8) ss_out(false, 'The new password needs 8 characters or more.');
    if ($new !== $confirm) ss_out(false, 'The two new passwords do not match.');

    $st = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
    $st->execute([$uid]);
    if (!password_verify($current, (string)$st->fetchColumn())) ss_out(false, 'Your current password is not right.');

    $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
        ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
    ss_out(true, 'Password changed.');
}

ss_out(false, 'Unknown action.');
