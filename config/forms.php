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
 */

const RD_FORM_INSTITUTIONS = ['MCNP', 'ISAP'];
const RD_FORMS_DIR = 'forms';   // uploads live here, relative to the app root (forms/.htaccess: nothing runs)

function rd_form_catalogue(): array
{
    // default = [MCNP file, ISAP file], relative to the app root.
    return [
        'application' => ['label' => 'Application for Research Defense', 'code' => 'Form 002', 'phase' => 'Proposal · Final',
                          'where' => 'Proposal and Final cards — the Form 002 requirement', 'types' => ['pdf'],
                          'default' => ['application_mcnp.pdf', 'application_isap.pdf']],
        'assigned_adviser' => ['label' => 'Assigned Adviser Form', 'code' => '', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Assigned Adviser requirement', 'types' => ['pdf'],
                          'default' => ['assigned_adviser_mcnp.pdf', 'assigned_adviser.pdf']],
        'endorsement' => ['label' => 'Adviser Endorsement', 'code' => 'Form 006', 'phase' => 'Proposal · Final',
                          'where' => 'Proposal card (Endorsement) and the Final card once cleared', 'types' => ['pdf'],
                          'default' => ['endorsement_mcnp.pdf', 'endorsement.pdf']],
        'proposal_review' => ['label' => 'Proposal Review reference', 'code' => 'Form 008', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Proposal Review requirement', 'types' => ['pdf'],
                          'default' => ['proposal_review.pdf', 'proposal_review.pdf']],
        'capsule_form' => ['label' => 'Capsule Proposal template', 'code' => '', 'phase' => 'Proposal',
                          'where' => 'Proposal card — the Capsule Proposal requirement', 'types' => ['pdf'],
                          'default' => ['capsule_form.pdf', 'capsule_form.pdf']],
        'proposal_cleared' => ['label' => 'Proposal Clearing Form', 'code' => '', 'phase' => 'Proposal',
                          'where' => 'Proposal card, once every requirement is approved', 'types' => ['pdf'],
                          'default' => ['proposal_cleared.pdf', 'proposal_cleared.pdf']],
        'stat_form' => ['label' => 'Statistical Treatment payment form', 'code' => 'RDC Form 011', 'phase' => 'Statistics',
                          'where' => 'Statistics card — "Proceed to Finance Office"', 'types' => ['pdf', 'jpg', 'jpeg', 'png'],
                          'default' => ['dashboards/downloads/rdc.jpg', 'dashboards/downloads/rdc.jpg']],
        'mom' => ['label' => 'Minutes of the Proposal Defense', 'code' => '', 'phase' => 'Final',
                          'where' => 'Final card — the Minutes requirement', 'types' => ['pdf'],
                          'default' => ['mom.pdf', 'mom.pdf']],
        'final_cleared' => ['label' => 'Defense Checklist', 'code' => 'Form 005', 'phase' => 'Final',
                          'where' => 'Final card, once the final defense is cleared', 'types' => ['pdf'],
                          'default' => ['final_cleared.pdf', 'final_cleared.pdf']],
        'plag_form' => ['label' => 'Plagiarism payment form', 'code' => 'RDC Form 13', 'phase' => 'Plagiarism',
                          'where' => 'Plagiarism card — "Proceed to Finance Office"', 'types' => ['pdf', 'jpg', 'jpeg', 'png'],
                          'default' => ['dashboards/downloads/plag_form.pdf', 'dashboards/downloads/plag_form.pdf']],
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

/**
 * The file a slot resolves to for a school, relative to the app root, plus where it came from.
 * ['path' => 'forms/...pdf', 'uploaded' => bool, 'row' => form_templates row|null]
 */
function rd_form_current(PDO $pdo, string $slot, string $inst): array
{
    $cat = rd_form_catalogue();
    if (!isset($cat[$slot])) return ['path' => '', 'uploaded' => false, 'row' => null];
    $inst = in_array($inst, RD_FORM_INSTITUTIONS, true) ? $inst : 'ISAP';
    $row = null;
    try {
        $st = $pdo->prepare("SELECT * FROM form_templates WHERE slot = ? AND institution = ? AND is_current = 1 ORDER BY template_id DESC LIMIT 1");
        $st->execute([$slot, $inst]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $row = null;   // the table is not there yet: the shipped files still work
    }
    if ($row && is_file(dirname(__DIR__) . '/' . $row['file_path'])) return ['path' => $row['file_path'], 'uploaded' => true, 'row' => $row];
    $def = $cat[$slot]['default'][$inst === 'MCNP' ? 0 : 1];
    if (!is_file(dirname(__DIR__) . '/' . $def)) $def = $cat[$slot]['default'][1];   // no MCNP copy shipped: ISAP's
    return ['path' => $def, 'uploaded' => false, 'row' => null];
}

/**
 * What a download button links to, for this student's school. $prefix is how the calling page reaches
 * the app root ('../' from dashboards/). A version stamp on uploads defeats a stale browser cache.
 */
function rd_form_url(PDO $pdo, string $slot, int $userId, string $prefix = '../'): string
{
    $cur = rd_form_current($pdo, $slot, rd_user_institution($pdo, $userId));
    if ($cur['path'] === '') return '';
    return $prefix . $cur['path'] . ($cur['uploaded'] ? '?v=' . (int)$cur['row']['template_id'] : '');
}
