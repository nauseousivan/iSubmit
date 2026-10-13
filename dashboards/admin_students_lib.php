<?php
// dashboards/admin_students_lib.php — student account maintenance, for the Admin (2026-09-30).
//
// Read helpers for admin_students.php and the shared rules admin_students_handler.php validates
// against. The shape of a student row is registration's (auth/register.php): school + course +
// section + group name are GROUP facts inherited from the leader; name, email, School ID Number
// and year level are PERSONAL and never inherited.

/**
 * Institutions and their courses. MIRRORS `programOptions` in auth/register.php — the list a
 * student picks from at sign-up. Change both together or the Admin can set a course a student
 * could never have registered with.
 */
function as_programs(): array
{
    return [
        'Medical Colleges of Northern Philippines' => [
            'BS Nursing', 'BS Radiologic Technology', 'BS Medical Technology', 'BS Physical Therapy',
            'BS Pharmacy', 'BS Midwifery', 'BS 2-year Dental Technology', 'BS 2-year Pharmacy Aide',
            'BS Caregiving and TVET Course',
        ],
        'International School of Asia and the Pacific' => [
            'BS Information Technology', 'BS Computer Engineering', 'BS Business Administration',
            'BS Custom Administration', 'BS Hospitality Management', 'BS Tourism Management',
            'BS Accountancy', 'BS Criminology', 'BS Social Work', 'BS Secondary Education',
            'BS Psychology', 'BS Physical Education',
        ],
    ];
}

/** The year levels registration accepts. */
function as_year_levels(): array
{
    return ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
}

/**
 * The section row for (school, course, year, letter) — found, or made, exactly as registration
 * does it: the first student to name a room creates it. Returns the section_id.
 */
function as_resolve_section(PDO $pdo, string $school, string $program, string $year, string $letter, int $actorId): int
{
    $st = $pdo->prepare("SELECT section_id FROM sections WHERE department = ? AND program = ? AND year_level = ? AND label = ?");
    $st->execute([$school, $program, $year, $letter]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO sections (department, program, year_level, label, created_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$school, $program, $year, $letter, $actorId]);
    return (int)$pdo->lastInsertId();
}

/** Every student, members listed under their leader, with the effective group of each. */
function as_all_students(PDO $pdo): array
{
    return $pdo->query("
        SELECT u.user_id, u.username, u.email, u.student_number, u.department, u.program, u.year_level,
               u.section_id, sec.label AS section_label, u.research_group_name, u.leader_id,
               l.username AS leader_name, u.is_verified, u.account_status,
               COALESCE(l.research_group_name, u.research_group_name) AS group_name,
               (SELECT COUNT(*) FROM users m WHERE m.leader_id = u.user_id) AS member_count,
               DATE_FORMAT(u.created_at, '%b %e, %Y') AS created_on
        FROM users u
        LEFT JOIN users l    ON l.user_id = u.leader_id
        LEFT JOIN sections sec ON sec.section_id = u.section_id
        WHERE u.role = 'Student'
        ORDER BY COALESCE(NULLIF(COALESCE(l.research_group_name, u.research_group_name), ''), '~'), u.leader_id IS NOT NULL, u.username
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Group leaders a member can be put under (the canonical research-group predicate). */
function as_leaders(PDO $pdo): array
{
    return $pdo->query("
        SELECT user_id, username, research_group_name, department, program
        FROM users
        WHERE role = 'Student' AND leader_id IS NULL AND research_group_name IS NOT NULL AND research_group_name <> ''
        ORDER BY research_group_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}
