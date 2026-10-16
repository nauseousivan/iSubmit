<?php
/**
 * config/forms.php — the blank forms students download, per institution (2026-10-05).
 * Loaded by config/system.php, so every page has it.
 *
 * rd_form_catalogue()  the ONE list of form slots: what each is called, where students see it, which
 *                      file types it takes, and the file that ships with the system for each school.
 * rd_form_url()        what a download button links to: the Admin's current upload for that slot and
 *                      school (form_templates, Admin → System → Forms), else the shipped default.
 *                      Every download of a blank form goes through here — never hardcode a file.
 * rd_user_institution() which school a student belongs to ('MCNP' / 'ISAP'), from their department
 *                      (a member inherits it from the leader). Unrecognised = ISAP, which is what
 *                      everyone was handed before the forms were split, so no download changes by surprise.
 * AUTO-FILL (2026-10-17): a slot whose Word master (rd_form_template()) carries {{TAGS}} is downloaded
 *                      through dashboards/form_download.php, which fills the student's details in and
 *                      hands back a PDF. No tags = the blank file, exactly as before.
 */

require_once __DIR__ . '/form_fill.php';   // rd_docx_tags() / rd_docx_fill() / rd_docx_to_pdf()

const RD_FORM_INSTITUTIONS = ['MCNP', 'ISAP'];
const RD_FORMS_DIR = 'forms';   // uploads live here, relative to the app root (forms/.htaccess: nothing runs)
const RD_TEMPLATES_DIR = 'forms/templates';   // Word masters — never served directly (its .htaccess denies all)

function rd_form_catalogue(): array
{
    // 2026-10-17: every form carries its RDC number and official title (they are printed on the forms
    // themselves), and the shipped files live in forms/blank (PDF) and forms/templates (Word master,
    // with {{TAGS}} — see config/form_fill.php). default / template = [MCNP file, ISAP file]; a school
    // with no copy of its own gets ISAP's. template '' = no Word master yet (downloads stay blank).
    $b = 'forms/blank/';
    $t = 'forms/templates/';
    return [
        'application' => ['label' => 'Application for Research Defense', 'code' => 'RDC Form 002', 'phase' => 'Proposal · Final',
                          'where' => 'Proposal and Final cards — the Form 002 requirement', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-002_Application-for-Research-Defense_MCNP.pdf', $b . 'RDC-Form-002_Application-for-Research-Defense_ISAP.pdf'],
                          'template' => [$t . 'RDC-Form-002_Application-for-Research-Defense_MCNP.docx', $t . 'RDC-Form-002_Application-for-Research-Defense_ISAP.docx']],
        'capsule_form' => ['label' => 'Capsule Proposal Form', 'code' => 'RDC Form 003', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Capsule Proposal requirement', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-003_Capsule-Proposal-Form_ISAP.pdf', $b . 'RDC-Form-003_Capsule-Proposal-Form_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-003_Capsule-Proposal-Form_ISAP.docx']],
        'assigned_adviser' => ['label' => 'Letter of Assignment as an Adviser', 'code' => 'RDC Form 004', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Assigned Adviser requirement', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-004_Letter-of-Assignment-as-Adviser_MCNP.pdf', $b . 'RDC-Form-004_Letter-of-Assignment-as-Adviser_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-004_Letter-of-Assignment-as-Adviser_ISAP.docx']],
        'proposal_cleared' => ['label' => 'Application for Research Defense Checklist (Proposal)', 'code' => 'RDC Form 005', 'phase' => 'Proposal',
                          'where' => 'Proposal card, once every requirement is approved', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-005_Defense-Checklist_Proposal_ISAP.pdf', $b . 'RDC-Form-005_Defense-Checklist_Proposal_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-005_Defense-Checklist_ISAP.docx']],
        'endorsement' => ['label' => 'Endorsement for Defense from the Adviser', 'code' => 'RDC Form 006', 'phase' => 'Proposal · Final',
                          'where' => 'Proposal card (Endorsement) and the Final card once cleared', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-006_Endorsement-for-Defense-from-the-Adviser_MCNP.pdf', $b . 'RDC-Form-006_Endorsement-for-Defense-from-the-Adviser_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-006_Endorsement-for-Defense-from-the-Adviser_ISAP.docx']],
        'mom' => ['label' => 'Research Proposal Review Minutes of the Meeting', 'code' => 'RDC Form 007', 'phase' => 'Final',
                          'where' => 'Final card — the Minutes requirement', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-007_Proposal-Review-Minutes-of-the-Meeting_ISAP.pdf', $b . 'RDC-Form-007_Proposal-Review-Minutes-of-the-Meeting_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-007_Proposal-Review-Minutes-of-the-Meeting_ISAP.docx']],
        'proposal_review' => ['label' => 'Research Proposal Review', 'code' => 'RDC Form 008', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Proposal Review requirement', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-008_Research-Proposal-Review_ISAP.pdf', $b . 'RDC-Form-008_Research-Proposal-Review_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-008_Research-Proposal-Review_ISAP.docx']],
        'stat_form' => ['label' => 'Statistical Treatment Form', 'code' => 'RDC Form 011', 'phase' => 'Statistics',
                          'where' => 'Statistics card — "Go to the RDC Office"', 'types' => ['pdf', 'docx', 'jpg', 'jpeg', 'png'],
                          'default'  => [$b . 'RDC-Form-011_Statistical-Treatment-Form_ISAP.pdf', $b . 'RDC-Form-011_Statistical-Treatment-Form_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-011_Statistical-Treatment-Form_ISAP.docx']],
        'final_cleared' => ['label' => 'Application for Research Defense Checklist (Final)', 'code' => 'RDC Form 005', 'phase' => 'Final',
                          'where' => 'Final card, once the final defense is cleared', 'types' => ['pdf', 'docx'],
                          'default'  => [$b . 'RDC-Form-005_Defense-Checklist_Final_ISAP.pdf', $b . 'RDC-Form-005_Defense-Checklist_Final_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-005_Defense-Checklist_ISAP.docx']],
        'plag_form' => ['label' => 'Plagiarism Processing Form', 'code' => 'RDC Form 013', 'phase' => 'Plagiarism',
                          'where' => 'Plagiarism card — "Proceed to Finance Office"', 'types' => ['pdf', 'docx', 'jpg', 'jpeg', 'png'],
                          'default'  => [$b . 'RDC-Form-013_Plagiarism-Processing-Form_ISAP.pdf', $b . 'RDC-Form-013_Plagiarism-Processing-Form_ISAP.pdf'],
                          'template' => ['', $t . 'RDC-Form-013_Plagiarism-Processing-Form_ISAP.docx']],
    ];
}

/** 'MCNP' or 'ISAP' for a department name (or an already-short tag). */
function rd_institution_of(string $department): string
{
    $d = strtolower($department);
    return (strpos($d, 'medical colleges') !== false || preg_match('/\bmcnp\b/', $d)) ? 'MCNP' : 'ISAP';
}

function rd_user_institution(PDO $pdo, int $userId): string
{
    static $memo = [];
    if (!isset($memo[$userId])) {
        $st = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
        $st->execute([$userId]);
        $memo[$userId] = rd_institution_of((string)($st->fetchColumn() ?: ''));
    }
    return $memo[$userId];
}

/** The Admin's current upload for a slot + school (a form_templates row), or null. */
function rd_form_row(PDO $pdo, string $slot, string $inst): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM form_templates WHERE slot = ? AND institution = ? AND is_current = 1 ORDER BY template_id DESC LIMIT 1");
        $st->execute([$slot, $inst]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;   // the table is not there yet: the shipped files still work
    }
}

/**
 * The BLANK file a slot resolves to for a school, relative to the app root, plus where it came from.
 * ['path' => 'forms/...pdf', 'uploaded' => bool, 'row' => form_templates row|null]
 * Never a Word file: when the Admin's current upload is a Word master, the blank is the PDF made from
 * it at upload time (same name, .pdf, in forms/), else the shipped blank.
 */
function rd_form_current(PDO $pdo, string $slot, string $inst): array
{
    $cat = rd_form_catalogue();
    if (!isset($cat[$slot])) return ['path' => '', 'uploaded' => false, 'row' => null];
    $inst = in_array($inst, RD_FORM_INSTITUTIONS, true) ? $inst : 'ISAP';
    $root = dirname(__DIR__) . '/';
    $row  = rd_form_row($pdo, $slot, $inst);
    if ($row && is_file($root . $row['file_path'])) {
        if (strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION)) !== 'docx') {
            return ['path' => $row['file_path'], 'uploaded' => true, 'row' => $row];
        }
        $blank = RD_FORMS_DIR . '/' . pathinfo($row['file_path'], PATHINFO_FILENAME) . '.pdf';
        if (is_file($root . $blank)) return ['path' => $blank, 'uploaded' => true, 'row' => $row];
    }
    $def = $cat[$slot]['default'][$inst === 'MCNP' ? 0 : 1];
    if (!is_file($root . $def)) $def = $cat[$slot]['default'][1];   // no MCNP copy shipped: ISAP's
    return ['path' => $def, 'uploaded' => false, 'row' => null];
}

/**
 * The Word MASTER a slot is filled from for a school, relative to the app root, or '' when there is
 * none. The Admin's current upload wins when it is a Word file; when it is a PDF or a picture she has
 * chosen a plain file, so nothing is filled. Otherwise the shipped master (ISAP's if the school has none).
 */
function rd_form_template(PDO $pdo, string $slot, string $inst): string
{
    $cat = rd_form_catalogue();
    if (!isset($cat[$slot])) return '';
    $inst = in_array($inst, RD_FORM_INSTITUTIONS, true) ? $inst : 'ISAP';
    $root = dirname(__DIR__) . '/';
    $row  = rd_form_row($pdo, $slot, $inst);
    if ($row && is_file($root . $row['file_path'])) {
        return strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION)) === 'docx' ? $row['file_path'] : '';
    }
    $tpl = $cat[$slot]['template'] ?? ['', ''];
    $own = $tpl[$inst === 'MCNP' ? 0 : 1] ?? '';
    if ($own !== '' && is_file($root . $own)) return $own;
    // No master of its own: borrow ISAP's ONLY when the school also shares ISAP's blank. A school with a
    // blank of its own (its own letterhead) keeps that blank — a filled form on the other school's
    // letterhead would be worse than an empty one of its own.
    $def = $cat[$slot]['default'] ?? ['', ''];
    if ($inst === 'MCNP' && ($def[0] ?? '') !== ($def[1] ?? '') && is_file($root . ($def[0] ?? ''))) return '';
    $isap = $tpl[1] ?? '';
    return ($isap !== '' && is_file($root . $isap)) ? $isap : '';
}

/** True when this slot's download is filled in for the student (its Word master carries tags). */
function rd_form_fillable(PDO $pdo, string $slot, string $inst): bool
{
    $tpl = rd_form_template($pdo, $slot, $inst);
    return $tpl !== '' && rd_docx_tags(dirname(__DIR__) . '/' . $tpl) !== [];
}

/**
 * What a download button links to, for this student's school. $prefix is how the calling page reaches
 * the app root ('../' from dashboards/). A fillable form goes through form_download.php ($ctx adds to its
 * query, e.g. ['phase' => 'final'] for Form 002); a blank one is the file itself, with a version stamp
 * on uploads to defeat a stale browser cache.
 */
function rd_form_url(PDO $pdo, string $slot, int $userId, string $prefix = '../', array $ctx = []): string
{
    $inst = rd_user_institution($pdo, $userId);
    if (rd_form_fillable($pdo, $slot, $inst)) {
        return $prefix . 'dashboards/form_download.php?' . http_build_query(['slot' => $slot] + $ctx);
    }
    $cur = rd_form_current($pdo, $slot, $inst);
    if ($cur['path'] === '') return '';
    return $prefix . $cur['path'] . ($cur['uploaded'] ? '?v=' . (int)$cur['row']['template_id'] : '');
}

/** What a form is called where students see it: "RDC Form 006 · Endorsement for Defense from the Adviser". */
function rd_form_title(string $slot): string
{
    $c = rd_form_catalogue()[$slot] ?? null;
    if (!$c) return 'Form';
    return ($c['code'] !== '' ? $c['code'] . ' · ' : '') . $c['label'];
}

/** The download button's words for a form link: pre-filled when it goes through the auto-fill. */
function rd_form_btn_label(string $url): string
{
    return strpos($url, 'form_download.php') !== false ? 'Download Pre-filled Form' : 'Download Blank Form';
}
