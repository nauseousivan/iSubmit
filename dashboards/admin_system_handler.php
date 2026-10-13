<?php
// dashboards/admin_system_handler.php — the Admin's system writes (2026-10-01). ADMIN ONLY.
//
// Actions:
//   save_settings — system_settings (announcement, maintenance, registration, slots, lockout).
//                   Every value is validated here; the page's own limits are only courtesy.
//   unlock        — clear an email's lockout by writing an 'unlock' row to login_attempts
//                   (rd_locked_minutes() only counts failures after the latest ok/unlock).

require_once '../config/db.php';

if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_settings.php'); exit; }

$actor = (int)$_SESSION['user_id'];
function sys_finish(string $msg, string $type): void
{
    $_SESSION['message'] = $msg;
    $_SESSION['message_type'] = $type;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'admin_settings.php'));
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    sys_finish('Invalid security token. Please refresh and try again.', 'error');
}
$action = (string)($_POST['action'] ?? '');

if ($action === 'save_settings') {
    $int = function (string $k, int $min, int $max, string $label) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '' || !ctype_digit($v) || (int)$v < $min || (int)$v > $max) {
            sys_finish("$label must be a whole number from $min to $max.", 'error');
        }
        return (string)(int)$v;
    };
    $text = trim((string)($_POST['announcement_text'] ?? ''));
    if (mb_strlen($text) > 400) sys_finish('Keep the announcement to 400 characters.', 'error');
    $on = !empty($_POST['announcement_active']);
    if ($on && $text === '') sys_finish('Write the announcement before turning it on.', 'error');
    $level = in_array($_POST['announcement_level'] ?? '', ['info', 'warning', 'success'], true) ? $_POST['announcement_level'] : 'info';

    $vals = [
        'announcement_active' => $on ? '1' : '0',
        'announcement_level'  => $level,
        'announcement_text'   => $text,
        'maintenance_mode'    => !empty($_POST['maintenance_mode']) ? '1' : '0',
        'registration_open'   => !empty($_POST['registration_open']) ? '1' : '0',
        'slots_per_day'       => $int('slots_per_day', 1, 50, 'Defense slots per institution per day'),
        'lockout_attempts'    => $int('lockout_attempts', 3, 20, 'Wrong passwords before a lockout'),
        'lockout_minutes'     => $int('lockout_minutes', 1, 1440, 'Lockout length'),
    ];
    // A new announcement text gets a new id, so people who dismissed the old one see this one.
    if ($text !== (string)rd_setting('announcement_text', '')) $vals['announcement_id'] = (string)time();

    $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
    foreach ($vals as $k => $v) $st->execute([$k, $v, $actor]);
    sys_finish('Settings saved.' . ($vals['maintenance_mode'] === '1' ? ' Maintenance mode is ON: only the Admin can sign in.' : ''), 'success');
}

if ($action === 'unlock') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') sys_finish('No email given.', 'error');
    rd_log_login($pdo, $email, null, false, 'unlock');
    sys_finish($email . ' can sign in again.', 'success');
}

sys_finish('Unknown action.', 'error');
