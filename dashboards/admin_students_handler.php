<?php
// dashboards/admin_students_handler.php — all writes for student account maintenance.
//
// ADMIN ONLY (2026-09-30). Actions: create_student, edit_student, edit_group, move_member,
// set_password, verify_email, toggle_active.
//
// Same conventions as admin_accounts_handler.php: POST-only, CSRF-checked, sets
// $_SESSION['message'] / ['message_type'] and redirects back. No delete, as there: a student row
// is pointed at by uploads, logs, bookings and payments, and disabling is reversible.
//
// The rules are registration's (auth/register.php), not new ones:
//   - School ID Number: /^\d{4}-\d{4,6}$/, unique, '' stored as NULL (two blanks must not collide).
//   - School, course, section and group name are GROUP facts: the leader holds them and every
//     member carries a copy, so a change to the leader's is written to the whole group.
//   - Name, email, School ID and year level are personal: never copied from the leader.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin_students_lib.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'Admin') {
    exit('Access Denied');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin_students.php");
    exit();
}

$actor_id = (int)$_SESSION['user_id'];
$referer  = $_SERVER['HTTP_REFERER'] ?? 'admin_students.php';

function as_finish(string $msg, string $type): void
{
    global $referer;
    $_SESSION['message']      = $msg;
    $_SESSION['message_type'] = $type;
    header("Location: " . $referer);
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    as_finish("Invalid security token. Please refresh and try again.", "error");
}

$action = (string)($_POST['action'] ?? '');

/** One student row, or null. Never returns staff. */
function as_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'Student' LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Validate the personal fields shared by create and edit; returns them cleaned. */
function as_personal(PDO $pdo, int $selfId): array
{
    $name  = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $sid   = trim((string)($_POST['student_number'] ?? ''));
    $year  = trim((string)($_POST['year_level'] ?? ''));

    if ($name === '' || $email === '') as_finish("Name and email are both required.", "error");
    if (mb_strlen($name) > 50) as_finish("That name is too long (50 characters at most).", "error");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) as_finish("That email address is not valid.", "error");
    if ($sid !== '' && !preg_match('/^\d{4}-\d{4,6}$/', $sid)) {
        as_finish("School ID Number should look like 2023-10078.", "error");
    }
    if ($year !== '' && !in_array($year, as_year_levels(), true)) as_finish("Pick a year level from the list.", "error");

    $dupe = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
    $dupe->execute([$email, $selfId]);
    if ($dupe->fetch()) as_finish("That email is already used by another account.", "error");
    if ($sid !== '') {
        $dupe = $pdo->prepare("SELECT user_id FROM users WHERE student_number = ? AND user_id <> ? LIMIT 1");
        $dupe->execute([$sid, $selfId]);
        if ($dupe->fetch()) as_finish("That School ID Number belongs to another account.", "error");
    }
    return [$name, $email, $sid !== '' ? $sid : null, $year !== '' ? $year : null];
}

/** Validate a group's school + course + section letter; returns [school, program, letter]. */
function as_group_fields(): array
{
    $school  = trim((string)($_POST['department'] ?? ''));
    $program = trim((string)($_POST['program'] ?? ''));
    $letter  = strtoupper(trim((string)($_POST['section_label'] ?? '')));
    $all = as_programs();
    if (!isset($all[$school])) as_finish("Pick an institution.", "error");
    if (!in_array($program, $all[$school], true)) as_finish("Pick a course offered by that institution.", "error");
    if (!preg_match('/^[A-Z]$/', $letter)) as_finish("Type the section as a single letter, for example A.", "error");
    return [$school, $program, $letter];
}

// ------------------------------------------------------------------ create a student
if ($action === 'create_student') {
    [$name, $email, $sid, $year] = as_personal($pdo, 0);
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 8) as_finish("Password must be at least 8 characters.", "error");
    if ($sid === null) as_finish("A student needs a School ID Number.", "error");

    $mode = (string)($_POST['mode'] ?? 'leader');
    if ($mode === 'member') {
        $leader = as_get($pdo, (int)($_POST['leader_id'] ?? 0));
        if (!$leader || $leader['leader_id'] !== null || trim((string)$leader['research_group_name']) === '') {
            as_finish("Pick the group they belong to.", "error");
        }
        $row = [$leader['research_group_name'], $leader['department'], $leader['program'], $leader['section_id'], (int)$leader['user_id']];
    } else {
        $group = trim((string)($_POST['group_name'] ?? ''));
        if ($group === '' || mb_strlen($group) > 100) as_finish("Give the group a name (100 characters at most).", "error");
        if ($year === null) as_finish("Pick their year level: a section is a room in a year.", "error");
        [$school, $program, $letter] = as_group_fields();
        $row = [$group, $school, $program, null, null];
    }

    $pdo->beginTransaction();
    try {
        if ($mode !== 'member') $row[3] = as_resolve_section($pdo, $row[1], $row[2], $year, $letter, $actor_id);
        // Made by the Admin: verified and active at once, like an Admin-made staff account.
        $pdo->prepare("INSERT INTO users (username, password, email, student_number, role, department, program, year_level,
                                          section_id, research_group_name, leader_id, is_verified, account_status, created_by, reviewed_by, reviewed_at)
                       VALUES (?, ?, ?, ?, 'Student', ?, ?, ?, ?, ?, ?, 1, 'active', ?, ?, NOW())")
            ->execute([$name, password_hash($password, PASSWORD_BCRYPT), $email, $sid, $row[1], $row[2], $year,
                       $row[3], $row[0], $row[4], $actor_id, $actor_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        as_finish("Could not create the account. Please try again.", "error");
    }
    as_finish(htmlspecialchars($name) . " can now sign in" . ($mode === 'member' ? " as a member of " . htmlspecialchars($row[0]) : "") . ".", "success");
}

// Every other action is on one existing student.
$target = (int)($_POST['user_id'] ?? 0);
$stu    = as_get($pdo, $target);
if (!$stu) as_finish("That student account no longer exists.", "error");

// ------------------------------------------------------------------ personal details
if ($action === 'edit_student') {
    [$name, $email, $sid, $year] = as_personal($pdo, $target);
    $pdo->prepare("UPDATE users SET username = ?, email = ?, student_number = ?, year_level = ? WHERE user_id = ?")
        ->execute([$name, $email, $sid, $year, $target]);
    as_finish("Saved " . htmlspecialchars($name) . "'s details.", "success");
}

// ------------------------------------------------------------------ the group's name, course and section
if ($action === 'edit_group') {
    if ($stu['leader_id'] !== null) as_finish("A member follows their leader. Change the leader's group instead.", "error");
    $group = trim((string)($_POST['group_name'] ?? ''));
    if ($group === '' || mb_strlen($group) > 100) as_finish("Give the group a name (100 characters at most).", "error");
    [$school, $program, $letter] = as_group_fields();
    if (empty($stu['year_level'])) as_finish("Set the leader's year level first: a section is a room in a year.", "error");

    $pdo->beginTransaction();
    try {
        $sec = as_resolve_section($pdo, $school, $program, (string)$stu['year_level'], $letter, $actor_id);
        // The leader and every member carry the group's copy — write them together.
        $up = $pdo->prepare("UPDATE users SET research_group_name = ?, department = ?, program = ?, section_id = ?
                             WHERE role = 'Student' AND (user_id = ? OR leader_id = ?)");
        $up->execute([$group, $school, $program, $sec, $target, $target]);
        $n = $up->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        as_finish("Could not save the group. Please try again.", "error");
    }
    as_finish("Saved " . htmlspecialchars($group) . ($n > 1 ? " for all " . $n . " people in the group." : "."), "success");
}

// ------------------------------------------------------------------ put a member under another leader
if ($action === 'move_member') {
    if ($stu['leader_id'] === null && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE leader_id = " . $target)->fetchColumn() > 0) {
        as_finish("This student leads a group with members; they cannot join another one.", "error");
    }
    $leader = as_get($pdo, (int)($_POST['leader_id'] ?? 0));
    if (!$leader || $leader['leader_id'] !== null || trim((string)$leader['research_group_name']) === '' || (int)$leader['user_id'] === $target) {
        as_finish("Pick the group they belong to.", "error");
    }
    $pdo->prepare("UPDATE users SET leader_id = ?, research_group_name = ?, department = ?, program = ?, section_id = ? WHERE user_id = ?")
        ->execute([(int)$leader['user_id'], $leader['research_group_name'], $leader['department'], $leader['program'], $leader['section_id'], $target]);
    as_finish(htmlspecialchars($stu['username']) . " is now in " . htmlspecialchars($leader['research_group_name']) . ".", "success");
}

// ------------------------------------------------------------------ password
if ($action === 'set_password') {
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 8) as_finish("Password must be at least 8 characters.", "error");
    // 2026-10-01: ticked by default — the Admin knows this password, so the student should replace it.
    $force = !empty($_POST['force_change']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET password = ?, must_change_password = ? WHERE user_id = ?")
        ->execute([password_hash($password, PASSWORD_BCRYPT), $force, $target]);
    as_finish("New password set for " . htmlspecialchars($stu['username']) . ($force ? ". They will choose their own when they sign in." : "."), "success");
}

// ------------------------------------------------------------------ skip the email code
if ($action === 'verify_email') {
    $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?")->execute([$target]);
    as_finish(htmlspecialchars($stu['username']) . "'s email is marked verified. They can sign in without a code.", "success");
}

// ------------------------------------------------------------------ enable / disable sign-in
// account_status only: for a student is_verified is the email flag and stays what it was.
if ($action === 'toggle_active') {
    $now = $stu['account_status'] === 'active' ? 'disabled' : 'active';
    $pdo->prepare("UPDATE users SET account_status = ? WHERE user_id = ?")->execute([$now, $target]);
    as_finish(htmlspecialchars($stu['username']) . ($now === 'active' ? " can sign in again." : " can no longer sign in."), "success");
}

as_finish("Unknown action.", "error");
