<?php
// dashboards/form_download.php?slot=stat_form[&phase=proposal|final][&group=ID] — a form, FILLED IN
// for the group, as a PDF (2026-10-17).
//
// The Word master (rd_form_template()) is copied, its {{TAGS}} filled from the group's records
// (rd_form_values()), and LibreOffice turns it into a PDF. The student only ever receives a PDF: if the
// form has no tags, LibreOffice is missing or a conversion fails, this hands out the BLANK PDF — the
// same file the button used to link to — so a download never breaks.
//
// Who: a student gets their own group's form. Staff may preview any group they cover (?group=, scoped
// by aa_may_touch_student(); the Admin, view-only, may preview any). Read-only: nothing is written but a
// cached PDF in the temp folder, keyed by the master's version and the values, so a repeat download
// skips LibreOffice.

require_once '../config/db.php';
require_once __DIR__ . '/form_fill_lib.php';
require_once __DIR__ . '/admin_accounts_lib.php';   // aa_may_touch_student()

if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
$me   = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');

$slot  = (string)($_GET['slot'] ?? '');
$phase = in_array($_GET['phase'] ?? '', ['proposal', 'final'], true) ? $_GET['phase'] : '';
$cat   = rd_form_catalogue();
if (!isset($cat[$slot])) { http_response_code(404); exit('Unknown form.'); }

// Whose form: the student's own group, or the group a staff member asked for.
if ($role === 'Student') {
    $l = $pdo->prepare("SELECT COALESCE(leader_id, user_id) FROM users WHERE user_id = ?");
    $l->execute([$me]);
    $leader = (int)$l->fetchColumn();
} else {
    $leader = (int)($_GET['group'] ?? 0);
    if (!$leader || ($role !== 'Admin' && !aa_may_touch_student($pdo, $role, $me, $leader))) { http_response_code(403); exit('Access Denied'); }
}
$inst = rd_user_institution($pdo, $leader);

/** Hand out the BLANK PDF (the file the button linked to before auto-fill). */
$blank = function (string $why) use ($pdo, $slot, $inst) {
    $cur = rd_form_current($pdo, $slot, $inst);
    if ($why !== 'no-tags') error_log("form_download: $slot/$inst served blank ($why)");
    header('X-iSubmit-Form: blank; ' . $why);
    header('Location: ../' . $cur['path'] . ($cur['uploaded'] ? '?v=' . (int)$cur['row']['template_id'] : ''));
    exit;
};

$tpl = rd_form_template($pdo, $slot, $inst);
$tplAbs = dirname(__DIR__) . '/' . $tpl;
if ($tpl === '' || rd_docx_tags($tplAbs) === []) $blank('no-tags');

$vals = rd_form_values($pdo, $leader, $phase);
$work = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'isubmit_forms';
if (!is_dir($work) && !@mkdir($work, 0700, true)) $blank('no-temp-dir');

// Cache: same master version + same values = the same PDF.
$key = sha1($tpl . '|' . filemtime($tplAbs) . '|' . json_encode($vals));
$pdf = $work . DIRECTORY_SEPARATOR . $key . '.pdf';
if (!is_file($pdf)) {
    $docx = $work . DIRECTORY_SEPARATOR . $key . '.docx';
    if (!rd_docx_fill($tplAbs, $docx, $vals)) $blank('fill-failed');
    $out = rd_docx_to_pdf($docx, $work);
    @unlink($docx);
    if ($out === null) $blank(rd_soffice() === '' ? 'no-libreoffice' : 'convert-failed');
    $pdf = $out;
}

// A readable file name: RDC-Form-011_Statistical-Treatment-Form_Franco-Group.pdf
$base  = pathinfo($cat[$slot]['default'][1], PATHINFO_FILENAME);
$base  = preg_replace('/_(ISAP|MCNP)$/', '', $base);
$group = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)($vals['GROUP_NAME'] ?: 'Group')), '-');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $base . '_' . $group . '.pdf"');
header('Content-Length: ' . filesize($pdf));
header('Cache-Control: private, no-store');
header('X-iSubmit-Form: filled');
readfile($pdf);
