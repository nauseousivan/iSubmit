<?php
// dashboards/admin_accounts_lib.php — staff accounts and role scope.
//
// Read helpers shared by admin_accounts.php (the Director's screen) and by anything that has to
// narrow a group list to what a role may see: a Focal Person's courses, a Coordinator's school.
// All writes live in admin_accounts_handler.php.

/** Roles a Director can mint from the accounts screen. Students self-register; never here. */
const AA_STAFF_ROLES = [
    'Focal Person',
    'Research Coordinator',
    'Research Director',
    'Statistician',
];

/**
 * Roles whose reach is narrower than "the whole school system", and the SHAPE of that reach.
 * The two are different kinds of thing, which is why scope cannot be one flat list:
 *
 *   Focal Person         -> a list of COURSES   (focal_scope rows)
 *   Research Coordinator -> one SCHOOL          (users.department on her own row)
 *   Statistician         -> one SCHOOL, or ALL  (users.department, see AA_ALL_INSTITUTIONS)
 *
 * MCNP and ISAP each have their own coordinator, holding the focal persons of that school's
 * courses. They are separate accounts, never a shared login — a shared one could not be scoped,
 * and "who signed this" would stop meaning anything.
 */
const AA_SCOPED_ROLES = ['Focal Person', 'Research Coordinator', 'Statistician'];

/**
 * The one department value that means "both institutions" instead of naming one.
 *
 * ONLY a Statistician may carry it. The office runs three: one who sees everything and one
 * per institution. Writing that as an EMPTY department would have inverted the rule the rest
 * of this file depends on — empty means SEES NOTHING for a Coordinator — so the same blank
 * column would have meant opposite things for two roles, and any Statistician created without
 * an institution would silently have seen every group in both schools. A marker you have to
 * choose on purpose keeps empty meaning empty.
 */
const AA_ALL_INSTITUTIONS = 'ALL';

/**
 * The school-shaped scope of a user, resolved once for both the listing filter and the write
 * guard so the two can never disagree about who is visible.
 *
 * Returns null for "no limit" (the admin Statistician), '' for "not set — see nothing", or the
 * institution name. A Coordinator is never granted the all-institutions escape: hers is one
 * school by definition, and reading a stray 'ALL' on her row as omniscience is how a typo
 * becomes a privilege.
 */
function aa_school_scope_of(PDO $pdo, string $role, int $userId): ?string
{
    $school = aa_user_school($pdo, $userId);
    if ($role === 'Statistician' && $school === AA_ALL_INSTITUTIONS) return null;
    return $school;
}

/** The school a school-scoped user belongs to, or '' when nobody has set one yet. */
function aa_user_school(PDO $pdo, int $user_id): string
{
    $stmt = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return (string)($stmt->fetchColumn() ?: '');
}

/**
 * Every (school, course) pair that actually has research groups in it, with a group count.
 * This is the menu a Focal Person's scope is picked from, and the whitelist the handler
 * validates against — a course nobody is enrolled in can never become a scope row.
 *
 * Uses the canonical leader-group predicate (role Student, named group, leader_id IS NULL),
 * the same one _master_overview.php / analytics.php / research_history.php use.
 */
function aa_all_courses(PDO $pdo, ?string $school = null): array
{
    $stmt = $pdo->prepare("
        SELECT department, program, COUNT(*) AS group_count
        FROM users
        WHERE role = 'Student'
          AND (? IS NULL OR department = ?)
          AND leader_id IS NULL
          AND research_group_name IS NOT NULL AND research_group_name <> ''
          AND department IS NOT NULL AND department <> ''
          AND program    IS NOT NULL AND program    <> ''
        GROUP BY department, program
        ORDER BY department ASC, program ASC
    ");
    $stmt->execute([$school, $school]);
    return $stmt->fetchAll();
}

/**
 * Short school tag for a chip. `users.department` holds the SCHOOL, not a department — the full
 * names ("International School of Asia and the Pacific") are far too long to render inline.
 */
function aa_school_tag(string $department): string
{
    return stripos($department, 'International') !== false || stripos($department, 'ISAP') !== false ? 'ISAP' : 'MCNP';
}

/** One staff row, or null. Never returns a Student. */
function aa_get_staff(PDO $pdo, int $user_id): ?array
{
    $stmt = $pdo->prepare("SELECT user_id, username, email, role, department, is_verified, account_status, created_by, created_at
                           FROM users WHERE user_id = ? AND role <> 'Student' LIMIT 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

/** All staff accounts, newest role-grouped first, each with its scope rows attached. */
function aa_all_staff(PDO $pdo): array
{
    // account_status (2026-09-30): 'active' | 'pending' (made by a Director/Coordinator, waiting
    // for the Admin) | 'rejected' | 'disabled'. creator_* / reviewer_* say who asked and who decided.
    $staff = $pdo->query("
        SELECT u.user_id, u.username, u.email, u.role, u.department, u.is_verified, u.created_at,
               u.account_status, u.created_by, u.reviewed_at, u.review_note,
               c.username AS creator_name, c.role AS creator_role, r.username AS reviewer_name,
               DATE_FORMAT(u.created_at, '%b %e, %Y') AS created_on
        FROM users u
        LEFT JOIN users c ON c.user_id = u.created_by
        LEFT JOIN users r ON r.user_id = u.reviewed_by
        WHERE u.role <> 'Student'
        ORDER BY FIELD(u.role, 'Admin', 'Research Director', 'Research Coordinator', 'Focal Person', 'Statistician'),
                 u.username ASC
    ")->fetchAll();

    $scope = [];
    foreach ($pdo->query("SELECT user_id, department, program FROM focal_scope ORDER BY program ASC") as $s) {
        $scope[(int)$s['user_id']][] = $s;
    }
    foreach ($staff as &$row) {
        $row['scope'] = $scope[(int)$row['user_id']] ?? [];
    }
    return $staff;
}

/** The courses one Focal Person handles. Empty array = handles nothing yet. */
function aa_focal_scope(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("SELECT department, program FROM focal_scope WHERE user_id = ? ORDER BY program ASC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * May this staff member act on this particular student?
 *
 * The companion to aa_scope_sql(): that one hides out-of-scope groups from a listing, this one
 * refuses a write against one. A hidden row is not a protected row — without this a Focal
 * Person could POST another course's upload_id, or one school's Coordinator another school's,
 * straight at a review handler.
 *
 * Checks the student's own department/program, so it holds for group members as well as leaders.
 */
function aa_may_touch_student(PDO $pdo, string $role, int $actorId, int $studentUserId): bool
{
    if (!in_array($role, AA_SCOPED_ROLES, true)) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT department, program FROM users WHERE user_id = ?");
    $stmt->execute([$studentUserId]);
    $s = $stmt->fetch();
    if (!$s) {
        return false;
    }

    if ($role === 'Research Coordinator' || $role === 'Statistician') {
        $school = aa_school_scope_of($pdo, $role, $actorId);
        if ($school === null) return true;          // admin Statistician
        return $school !== '' && $school === $s['department'];
    }

    foreach (aa_focal_scope($pdo, $actorId) as $c) {
        if ($c['department'] === $s['department'] && $c['program'] === $s['program']) {
            return true;
        }
    }
    return false;
}

/**
 * SQL fragment that narrows a group query to the courses a role may see.
 *
 * Returns [whereSql, params]. For unscoped staff — the Director, and the ADMIN Statistician
 * (department = AA_ALL_INSTITUTIONS) — the SQL is the literal '1' and nothing is bound, so
 * callers can splice it in unconditionally:
 *
 *     [$scopeSql, $scopeParams] = aa_scope_sql($pdo, $role, $user_id, 'u');
 *     $sql = "SELECT ... FROM users u WHERE <canonical predicate> AND $scopeSql";
 *
 * A scoped user with nothing assigned yet matches nothing ('0') rather than everything — a
 * Focal Person with no courses, a Coordinator with no school. Failing closed is the only safe
 * default; the Staff Accounts screen surfaces both so they get fixed rather than discovered.
 */
function aa_scope_sql(PDO $pdo, string $role, int $user_id, string $alias = 'u'): array
{
    if (!in_array($role, AA_SCOPED_ROLES, true)) {
        return ['1', []];
    }

    // A Coordinator is scoped to her SCHOOL; a Statistician to one school or to ALL of them.
    if ($role === 'Research Coordinator' || $role === 'Statistician') {
        $school = aa_school_scope_of($pdo, $role, $user_id);
        // null = the admin Statistician, who is deliberately unlimited.
        if ($school === null) return ['1', []];
        // Fails closed, exactly like a Focal Person with no courses: a coordinator or
        // statistician whose institution nobody has set yet must see nothing rather than
        // everything. The Staff Accounts screen flags it so it is fixed rather than discovered.
        return $school === '' ? ['0', []] : ["$alias.department = ?", [$school]];
    }

    // A Focal Person is scoped to her list of COURSES.
    $courses = aa_focal_scope($pdo, $user_id);
    if (empty($courses)) {
        return ['0', []];
    }

    $parts  = [];
    $params = [];
    foreach ($courses as $c) {
        $parts[]  = "($alias.department = ? AND $alias.program = ?)";
        $params[] = $c['department'];
        $params[] = $c['program'];
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/* ===================================================================================
   WHO MAY MANAGE STAFF ACCOUNTS

   The Director runs the whole office. Each Coordinator runs ONE institution, so she may
   mint and manage the Focal Persons of her own courses and nothing else — MCNP's
   coordinator must never see, rename, re-scope, unlock or re-password an ISAP account,
   nor any Director / Coordinator / Statistician.

   Same defence-in-depth as group scope: the screen HIDES what she may not touch, and the
   handler REFUSES it. A hidden card is not a protected card.
   =================================================================================== */

/** Roles that may open the Staff Accounts screen at all. */
const AA_MANAGER_ROLES = ['Admin', 'Research Director', 'Research Coordinator'];

/**
 * THE ADMIN (2026-09-30). The system administrator the evaluators asked for: maintains every
 * account (staff AND students) and may VIEW the research workflow but never act in it (the POST
 * guard in config/db.php). The Director and the Coordinators still create staff accounts as they
 * did, but what they create starts `pending` and cannot sign in until the Admin approves it; what
 * the Admin creates is active at once. Only the Admin may create or touch an Admin account.
 */
function aa_is_admin(string $role): bool
{
    return $role === 'Admin';
}

/** Staff accounts waiting for the Admin's approval — the Admin's one badge. */
function aa_pending_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'Student' AND account_status = 'pending'")->fetchColumn();
}

/**
 * Roles this manager may create.
 * A Coordinator may only make Focal Persons: letting her mint another Coordinator (or a
 * Director) would let her hand out reach she does not have herself.
 */
function aa_creatable_roles(string $managerRole): array
{
    if ($managerRole === 'Admin')                return array_merge(AA_STAFF_ROLES, ['Admin']);
    if ($managerRole === 'Research Director')    return AA_STAFF_ROLES;
    if ($managerRole === 'Research Coordinator') return ['Focal Person'];
    return [];
}

/**
 * The institution this manager is confined to, or null for none (the Director).
 *
 * A Coordinator with no institution set gets a sentinel nobody matches rather than null —
 * null would silently widen her to every institution, which is the opposite of what an
 * unfinished account should do.
 */
function aa_manager_school(PDO $pdo, string $managerRole, int $managerId): ?string
{
    if ($managerRole !== 'Research Coordinator') return null;
    $school = aa_user_school($pdo, $managerId);
    return $school !== '' ? $school : "\0none";
}

/** The courses this manager may assign — all of them, or just her own institution's. */
function aa_courses_for(PDO $pdo, string $managerRole, int $managerId): array
{
    return aa_all_courses($pdo, aa_manager_school($pdo, $managerRole, $managerId));
}

/**
 * May this manager act on this existing staff row?
 *
 * A Coordinator may touch a Focal Person only when EVERY course that person handles is in
 * her institution — "any" would let one cross-institution assignment hand her the account.
 * A focal person with no courses yet belongs to nobody, so she is not hers to take either.
 */
function aa_may_manage(PDO $pdo, string $managerRole, int $managerId, array $target): bool
{
    if ($managerRole === 'Admin')                 return true;
    // The Director runs the office, not the system: an Admin account is not hers to touch.
    if ($managerRole === 'Research Director')     return ($target['role'] ?? '') !== 'Admin';
    if ($managerRole !== 'Research Coordinator')  return false;
    if (($target['role'] ?? '') !== 'Focal Person') return false;

    $school = aa_manager_school($pdo, $managerRole, $managerId);
    if ($school === null || $school === "\0none") return false;

    $scope = aa_focal_scope($pdo, (int)$target['user_id']);
    if (empty($scope)) return false;
    foreach ($scope as $c) {
        if ($c['department'] !== $school) return false;
    }
    return true;
}

/** The staff rows this manager may see and act on, in the order aa_all_staff() returns them. */
function aa_staff_for(PDO $pdo, string $managerRole, int $managerId): array
{
    $all = aa_all_staff($pdo);
    if ($managerRole === 'Admin') return $all;
    return array_values(array_filter(
        $all,
        fn($s) => aa_may_manage($pdo, $managerRole, $managerId, $s)
    ));
}