<?php
// dashboards/form_fill_lib.php — WHAT goes into a form's {{TAGS}} (2026-10-17).
//
// rd_form_values() is the ONE list of tags iSubmit knows. A tag the Word master uses but that is not
// here simply prints blank (for the office to write on), so a new form can use any of these freely.
// Keep this list and the one shown on Admin -> Forms (rd_form_tag_help()) in step.
//
// Everything is the GROUP's, read from the leader's row (members inherit course, section and title).
// Personal things the system does not hold — signatures, cellphone, OR numbers, the adviser — are not
// tags on purpose, and neither is the defense date (the office writes it): they stay blank on the paper.

require_once __DIR__ . '/group_roster_lib.php';   // ws_group_rosters() — leader first
require_once __DIR__ . '/defense_lib.php';        // df_today()

/** Tag => what it means, for the Admin's help panel. Same order the values are built in. */
function rd_form_tag_help(): array
{
    return [
        'GROUP_NAME'     => 'Research group name',
        'RESEARCH_TITLE' => 'Research title',
        'LEADER'         => 'Group leader\'s full name',
        'MEMBER_1 … MEMBER_10' => 'Each member\'s name, leader first',
        'MEMBER_1_ROLE … MEMBER_10_ROLE' => '"Student Researcher" on the rows that have a member',
        'MEMBERS'        => 'All members in one line, comma-separated (use it for a single "Proponents:" line, so the form stays one page)',
        'STUDENT_NUMBER' => 'Leader\'s School ID Number',
        'EMAIL'          => 'Leader\'s email',
        'COURSE'         => 'Course (program)',
        'YEAR_LEVEL'     => 'Year level',
        'SECTION'        => 'Section, e.g. 4th Year A',
        'INSTITUTION'    => 'Institution name (MCNP or ISAP, in full)',
        'CONTROL_NO'     => 'Statistics control number, once the Secretary issued it',
        'DATE'           => 'Today\'s date, e.g. October 17, 2026',
        'PROPOSAL_X / FINAL_X' => 'A check mark (✓) in the box of the defense this form is for (Form 002)',
    ];
}

/** Format a Y-m-d date the way the forms are written: October 17, 2026. */
function rd_form_date(?string $ymd): string
{
    if (!$ymd || !preg_match('/^\d{4}-\d{2}-\d{2}/', $ymd)) return '';
    return (new DateTime(substr($ymd, 0, 10), new DateTimeZone('Asia/Manila')))->format('F j, Y');
}

/**
 * Every tag's value for one group. $leaderId is the group's effective (leader) id. $phase is
 * 'proposal' or 'final' where a form serves both (Form 002), '' otherwise.
 */
function rd_form_values(PDO $pdo, int $leaderId, string $phase = ''): array
{
    $g = $pdo->prepare("SELECT u.username, u.email, u.student_number, u.department, u.program, u.year_level,
                               u.research_group_name, u.research_title, s.label AS section_label, s.year_level AS section_year
                        FROM users u LEFT JOIN sections s ON s.section_id = u.section_id WHERE u.user_id = ?");
    $g->execute([$leaderId]);
    $g = $g->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT formatted_control_no, research_title FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
    $st->execute([$leaderId]);
    $st = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $roster = ws_group_rosters($pdo, [$leaderId])[$leaderId] ?? [];
    $names  = array_values(array_filter(array_map(fn($m) => trim((string)$m['username']), $roster)));

    $year = trim((string)($g['section_year'] ?? '')) ?: trim((string)($g['year_level'] ?? ''));
    $v = [
        'GROUP_NAME'     => (string)($g['research_group_name'] ?? ''),
        'RESEARCH_TITLE' => trim((string)($g['research_title'] ?? '')) ?: (string)($st['research_title'] ?? ''),
        'LEADER'         => $names[0] ?? (string)($g['username'] ?? ''),
        'MEMBERS'        => implode(', ', $names),
        'STUDENT_NUMBER' => (string)($g['student_number'] ?? ''),
        'EMAIL'          => (string)($g['email'] ?? ''),
        'COURSE'         => (string)($g['program'] ?? ''),
        'YEAR_LEVEL'     => $year,
        'SECTION'        => trim($year . ' ' . (string)($g['section_label'] ?? '')),
        'INSTITUTION'    => (string)($g['department'] ?? ''),
        'CONTROL_NO'     => (string)($st['formatted_control_no'] ?? ''),
        'DATE'           => rd_form_date(df_today()),
        // A check mark, not an X (the user's call). Arial has no ✓; LibreOffice prints it from a
        // fallback font (DejaVu Sans on the server), which is fine for one symbol.
        'PROPOSAL_X'     => $phase === 'proposal' ? '✓' : '',
        'FINAL_X'        => $phase === 'final' ? '✓' : '',
    ];
    for ($i = 1; $i <= 10; $i++) {
        $v['MEMBER_' . $i]           = $names[$i - 1] ?? '';
        $v['MEMBER_' . $i . '_ROLE'] = isset($names[$i - 1]) ? 'Student Researcher' : '';
    }
    // NO defense date (the user's call, 2026-10-17): the office writes it on the form itself.
    return $v;
}
