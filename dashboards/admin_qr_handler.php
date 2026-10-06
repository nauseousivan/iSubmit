<?php
// dashboards/admin_qr_handler.php — the Admin's QR code writes (2026-10-01). ADMIN ONLY.
//
// Actions: save_site (the address QR codes point at), create, update (label / caption / where it
// goes — the printed code keeps working), toggle (retire / bring back), recode (a NEW code: every
// printout of the old one stops working — for a poster that leaked or went up in the wrong place).
// There is no delete: a retired code still tells anyone who scans an old poster what happened.

require_once '../config/db.php';

if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_qr.php'); exit; }

$actor = (int)$_SESSION['user_id'];
function qr_finish(string $msg, string $type): void
{
    $_SESSION['message'] = $msg;
    $_SESSION['message_type'] = $type;
    header('Location: admin_qr.php');
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    qr_finish('Invalid security token. Please refresh and try again.', 'error');
}

/** Where a code may send people: a path inside iSubmit, or a full http(s) address. */
function qr_clean_target(string $t): string
{
    $t = trim($t);
    if (preg_match('#^https?://[^\s<>"]+$#i', $t) && mb_strlen($t) <= 255) return $t;
    $t = ltrim($t, '/');
    if ($t !== '' && preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $t) && strpos($t, '..') === false && mb_strlen($t) <= 255) return $t;
    qr_finish('Pick where the code goes, or type a full link that starts with http:// or https://.', 'error');
    return '';
}
function qr_new_code(PDO $pdo): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    do {
        $c = '';
        for ($i = 0; $i < 7; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM qr_links WHERE code = ?");
        $st->execute([$c]);
    } while ($st->fetchColumn());
    return $c;
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'save_site') {
    $url = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    if ($url !== '' && !preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9_.\-/]*)?$#', $url)) {
        qr_finish('The site address should look like http://192.168.1.20/Research_Digital or https://isubmit.school.edu.ph', 'error');
    }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('site_url', ?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)")->execute([$url, $actor]);
    qr_finish($url === '' ? 'QR codes now use the address you opened iSubmit with.' : 'Saved. Every QR code now points at ' . $url . ' — reprint posters made with the old address.', 'success');
}

if ($action === 'create') {
    $label = trim((string)($_POST['label'] ?? ''));
    $cap   = trim((string)($_POST['caption'] ?? ''));
    if ($label === '' || mb_strlen($label) > 80) qr_finish('Give the code a title (80 characters at most).', 'error');
    if (mb_strlen($cap) > 200) qr_finish('Keep the caption to 200 characters.', 'error');
    $target = qr_clean_target((string)(($_POST['target'] ?? '') === 'custom' ? ($_POST['custom_target'] ?? '') : ($_POST['target'] ?? '')));
    $pdo->prepare("INSERT INTO qr_links (code, label, caption, target, created_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([qr_new_code($pdo), $label, $cap !== '' ? $cap : null, $target, $actor]);
    qr_finish('"' . $label . '" is ready to print.', 'success');
}

// The rest act on one code.
$id  = (int)($_POST['qr_id'] ?? 0);
$row = $pdo->prepare("SELECT * FROM qr_links WHERE qr_id = ?");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) qr_finish('That QR code no longer exists.', 'error');

if ($action === 'update') {
    $label = trim((string)($_POST['label'] ?? ''));
    $cap   = trim((string)($_POST['caption'] ?? ''));
    if ($label === '' || mb_strlen($label) > 80) qr_finish('Give the code a title (80 characters at most).', 'error');
    if (mb_strlen($cap) > 200) qr_finish('Keep the caption to 200 characters.', 'error');
    $target = qr_clean_target((string)(($_POST['target'] ?? '') === 'custom' ? ($_POST['custom_target'] ?? '') : ($_POST['target'] ?? '')));
    $pdo->prepare("UPDATE qr_links SET label = ?, caption = ?, target = ? WHERE qr_id = ?")->execute([$label, $cap !== '' ? $cap : null, $target, $id]);
    qr_finish('Saved. Posters already printed keep working and now open the new page.', 'success');
}
if ($action === 'toggle') {
    $on = (int)$row['is_active'] === 1 ? 0 : 1;
    $pdo->prepare("UPDATE qr_links SET is_active = ? WHERE qr_id = ?")->execute([$on, $id]);
    qr_finish($on ? '"' . $row['label'] . '" works again.' : '"' . $row['label'] . '" is retired. Anyone scanning an old poster is told it is no longer in use.', 'success');
}
if ($action === 'recode') {
    $pdo->prepare("UPDATE qr_links SET code = ?, scans = 0, last_scan = NULL WHERE qr_id = ?")->execute([qr_new_code($pdo), $id]);
    qr_finish('"' . $row['label'] . '" has a new code. Old printouts stop working — print the new poster.', 'success');
}

qr_finish('Unknown action.', 'error');
