<?php
// dashboards/schedule_lib.php — the PUBLISHED defense schedule (2026-10-14).
//
// One source for every calendar that shows who defends when: the student "View more" calendar
// (calendar_all.php), the student mini-calendar, the public schedule page (../schedule.php) and the front
// page strip. "Published" = a defense_batches row the Secretary has signed (status 'Scheduled', the last leg
// of the Focal -> Coordinator -> Secretary chain); nothing earlier in the chain is shown, it can still move.
//
// SECTIONS AND DATES ONLY: course, year, room, phase, institution and how many groups. Never a student's name
// or a research title — this feeds a public page.
require_once __DIR__ . '/../config/forms.php';   // rd_institution_of()

/**
 * Published defenses between two dates (inclusive), oldest first. Each row:
 * ['date', 'phase' (proposal|final), 'school' (MCNP|ISAP), 'program', 'year', 'label', 'groups']
 */
function sch_published(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT b.defense_date, b.phase, s.department, s.program, s.year_level, s.label,
                                (SELECT COUNT(*) FROM defense_bookings k WHERE k.batch_id = b.batch_id AND k.status = 'Scheduled') AS groups_n
                         FROM defense_batches b JOIN sections s ON s.section_id = b.section_id
                         WHERE b.status = 'Scheduled' AND b.defense_date BETWEEN ? AND ?
                         ORDER BY b.defense_date, s.department, s.program, s.year_level, s.label");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['groups_n'] === 0) continue;     // every group moved off it: nothing is happening that day
        $out[] = [
            'date'    => $r['defense_date'],
            'phase'   => $r['phase'],
            'school'  => rd_institution_of((string)$r['department']),
            'program' => (string)$r['program'],
            'year'    => (string)$r['year_level'],
            'label'   => (string)$r['label'],
            'groups'  => (int)$r['groups_n'],
        ];
    }
    return $out;
}

/** "BS Nursing · 4th Year · A" */
function sch_section(array $r): string
{
    return implode(' · ', array_filter([$r['program'], $r['year'], $r['label']], fn($x) => $x !== ''));
}

/**
 * The room as the office writes it, for a calendar chip: "BS Information Technology", "4th Year", "A" -> "BSIT 4-A".
 * Degree prefix kept (BS, AB...), then the initials of the course's significant words; year as its digit.
 */
function sch_short(array $r): string
{
    $words = preg_split('/\s+/', trim(preg_replace('/^Bachelor of (Science|Arts)( in)?/i', 'BS', $r['program'])));
    $deg = '';
    if ($words && preg_match('/^(BS|AB|BA|BSED|BEED|BPED)$/i', $words[0])) $deg = strtoupper(array_shift($words));
    $ini = '';
    foreach ($words as $w) if ($w !== '' && !in_array(strtolower($w), ['of', 'in', 'and', 'the', '&'], true)) $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    $year = preg_match('/\d/', $r['year'], $m) ? $m[0] : mb_substr($r['year'], 0, 1);
    return trim($deg . $ini . ' ' . ($year !== '' ? $year . '-' : '') . $r['label']);
}

/**
 * The rows the shared calendar (assets/js/defense_calendar.js) draws: one item per published section-date.
 * kind 'defense'; title = the section; short = "BSIT 4-A" for the chip; sub = phase + group count.
 */
function sch_calendar_items(array $rows): array
{
    return array_map(fn($r) => [
        'date'   => $r['date'],
        'kind'   => 'defense',
        'short'  => sch_short($r),
        'title'  => sch_section($r),
        'sub'    => ($r['phase'] === 'final' ? 'Final defense' : 'Proposal defense') . ' · ' . $r['groups'] . ' group' . ($r['groups'] === 1 ? '' : 's'),
        'school' => $r['school'],
        'phase'  => $r['phase'],
    ], $rows);
}
