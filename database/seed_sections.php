<?php
// database/seed_sections.php — make sure every course has rooms to pick from.
//
//   php database/seed_sections.php          show what would change
//   php database/seed_sections.php --write  apply it
//
// WHY THIS EXISTS: sections have to be waiting before a student registers, or the Section
// dropdown has nothing in it and the group drops into the focal person's Unassigned tray for
// her to file by hand. Creating rooms is not her job — the list should just be there.
//
// Idempotent: uniq_section_label (department, program, year_level, label) makes re-running a
// no-op, and sections anyone has already created — with groups sitting in them — are untouched.
// Re-run it after adding a course to the catalogue in auth/register.php.

require __DIR__ . '/../config/db.php';

$WRITE = in_array('--write', $argv, true);

// The same catalogue auth/register.php offers, read from that file so the two can never drift.
$src = file_get_contents(__DIR__ . '/../auth/register.php');
if (!preg_match('/const programOptions = (\{.*?\n            \});/s', $src, $m)) {
    exit("Could not read programOptions from auth/register.php\n");
}
$catalogue = json_decode(preg_replace('/,(\s*[\]\}])/', '$1', $m[1]), true);
if (!$catalogue) {
    exit("programOptions did not parse\n");
}

// Five rooms covers every section a course here actually runs, and the registration dropdown
// filters by the year the student picked, so they only ever see this year's handful.
$YEARS  = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
$LABELS = ['A', 'B', 'C', 'D', 'E'];

$existing = [];
foreach ($pdo->query("SELECT department, program, year_level, label FROM sections") as $r) {
    $existing[$r['department'] . '|' . $r['program'] . '|' . $r['year_level'] . '|' . $r['label']] = true;
}

$toAdd = [];
foreach ($catalogue as $school => $programs) {
    foreach ($programs as $program) {
        foreach ($YEARS as $year) {
            foreach ($LABELS as $label) {
                $key = $school . '|' . $program . '|' . $year . '|' . $label;
                if (!isset($existing[$key])) {
                    $toAdd[] = [$school, $program, $year, $label];
                }
            }
        }
    }
}

printf("catalogue: %d courses  |  %d years x %d labels\n", array_sum(array_map('count', $catalogue)), count($YEARS), count($LABELS));
printf("already present: %d    |  to create: %d\n", count($existing), count($toAdd));

if (!$WRITE) {
    echo "\nDry run. Re-run with --write to apply.\n";
    exit;
}

$ins = $pdo->prepare("INSERT IGNORE INTO sections (department, program, year_level, label, created_by) VALUES (?, ?, ?, ?, NULL)");
$pdo->beginTransaction();
foreach ($toAdd as $row) {
    $ins->execute($row);
}
$pdo->commit();

printf("created %d sections; total now %d\n", count($toAdd), (int)$pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn());
