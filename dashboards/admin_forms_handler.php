<?php
// dashboards/admin_forms_handler.php — the Admin's form writes (2026-10-05). ADMIN ONLY.
//
// Actions (all on form_templates — see config/forms.php for the slots):
//   upload   a new version of one form for MCNP, ISAP or both; the previous one stays in history
//   restore  make an earlier upload the current one again
//   builtin  go back to the file that ships with the system for that school
// Nothing is ever deleted: a form a student already downloaded is still on the server.

require_once '../config/db.php';

if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_forms.php'); exit; }

$actor = (int)$_SESSION['user_id'];
function fm_finish(string $msg, string $type, string $slot = ''): void
{
    $_SESSION['message'] = $msg;
    $_SESSION['message_type'] = $type;
    header('Location: admin_forms.php' . ($slot !== '' ? '#form-' . rawurlencode($slot) : ''));
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    fm_finish('Invalid security token. Please refresh and try again.', 'error');
}

$cat    = rd_form_catalogue();
$action = (string)($_POST['action'] ?? '');
$slot   = (string)($_POST['slot'] ?? '');
if (!isset($cat[$slot])) fm_finish('That form is not one the system knows.', 'error');
$label  = $cat[$slot]['label'];

if ($action === 'upload') {
    $who = (string)($_POST['institution'] ?? '');
    $targets = $who === 'BOTH' ? RD_FORM_INSTITUTIONS : (in_array($who, RD_FORM_INSTITUTIONS, true) ? [$who] : []);
    if (!$targets) fm_finish('Choose which school this form is for.', 'error', $slot);

    $f = $_FILES['form_file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $big = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        fm_finish($big ? 'That file is too large.' : 'Choose a file to upload.', 'error', $slot);
    }
    if ((int)$f['size'] > 15 * 1024 * 1024) fm_finish('That file is too large (15 MB at most).', 'error', $slot);
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $types = $cat[$slot]['types'];
    if (!in_array($ext, $types, true)) fm_finish('This form takes ' . strtoupper(implode(', ', $types)) . ' files only.', 'error', $slot);
    // Check what the file IS, not what it is called.
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = $ext === 'pdf' ? strncmp($head, '%PDF-', 5) === 0 : (@getimagesize($f['tmp_name']) !== false);
    if (!$ok) fm_finish('That file does not look like a real ' . strtoupper($ext) . '. Export it again and retry.', 'error', $slot);

    $dir = dirname(__DIR__) . '/' . RD_FORMS_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) fm_finish('The forms folder could not be created on the server.', 'error', $slot);
    $name = $slot . '_' . strtolower($who) . '_' . (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fm_finish('The file could not be saved on the server.', 'error', $slot);

    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    $orig = mb_substr(basename((string)$f['name']), 0, 255);
    $pdo->beginTransaction();
    $off = $pdo->prepare("UPDATE form_templates SET is_current = 0 WHERE slot = ? AND institution = ?");
    $ins = $pdo->prepare("INSERT INTO form_templates (slot, institution, file_path, original_name, file_size, note, is_current, uploaded_by)
                          VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    foreach ($targets as $inst) {
        $off->execute([$slot, $inst]);
        $ins->execute([$slot, $inst, RD_FORMS_DIR . '/' . $name, $orig, (int)$f['size'], $note !== '' ? $note : null, $actor]);
    }
    $pdo->commit();
    fm_finish($label . ' updated for ' . ($who === 'BOTH' ? 'both schools' : $who) . '. Students get the new file from now on.', 'success', $slot);
}

if ($action === 'restore') {
    $st = $pdo->prepare("SELECT *, DATE_FORMAT(uploaded_at, '%b %e, %Y') AS on_day FROM form_templates WHERE template_id = ? AND slot = ?");   // MySQL formats it: PHP runs 6h behind
    $st->execute([(int)($_POST['template_id'] ?? 0), $slot]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) fm_finish('That version was not found.', 'error', $slot);
    if (!is_file(dirname(__DIR__) . '/' . $row['file_path'])) fm_finish('That version\'s file is no longer on the server.', 'error', $slot);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE form_templates SET is_current = 0 WHERE slot = ? AND institution = ?")->execute([$slot, $row['institution']]);
    $pdo->prepare("UPDATE form_templates SET is_current = 1 WHERE template_id = ?")->execute([(int)$row['template_id']]);
    $pdo->commit();
    fm_finish($label . ' for ' . $row['institution'] . ' is back to the version from ' . $row['on_day'] . '.', 'success', $slot);
}

if ($action === 'builtin') {
    $inst = (string)($_POST['institution'] ?? '');
    if (!in_array($inst, RD_FORM_INSTITUTIONS, true)) fm_finish('Choose which school.', 'error', $slot);
    $pdo->prepare("UPDATE form_templates SET is_current = 0 WHERE slot = ? AND institution = ?")->execute([$slot, $inst]);
    fm_finish($label . ' for ' . $inst . ' is back to the file that came with the system. Your uploads are still in its history.', 'success', $slot);
}

fm_finish('Nothing was changed.', 'error', $slot);
