<?php
// dashboards/admin_accounts_handler.php — all writes for staff account management.
//
// Actions: create_staff, update_scope, set_password, toggle_active,
//          approve_account, reject_account, edit_details   (the last three: Admin only)
//
// THE ADMIN (2026-09-30) manages every staff account and is the one who APPROVES: an account the
// Director or a Coordinator creates starts `pending` (is_verified = 0 as well, so the queries that
// pick staff to email keep skipping it) and cannot sign in until the Admin approves it. An account
// the Admin creates is active at once. See aa_is_admin() in admin_accounts_lib.php.
//
// DIRECTOR, and each COORDINATOR for her own institution. A Coordinator may create and manage
// the Focal Persons of her own courses and nothing else — see aa_may_manage() for the rule and
// why it is enforced here as well as hidden on the screen.
// Follows defense_handler.php conventions: POST-only, CSRF-checked, sets
// $_SESSION['message'] / ['message_type'] and redirects back to the referer.
//
// Deliberately has NO delete action. Disabling is `is_verified = 0`, the same switch
// settings.php already uses to deactivate an account — reversible, and it never orphans
// the approvals/uploads/logs that point at the user row.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'admin_accounts_lib.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, AA_MANAGER_ROLES, true)) {
    exit('Access Denied');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: director.php");
    exit();
}

$actor_id = (int)$_SESSION['user_id'];
$referer  = $_SERVER['HTTP_REFERER'] ?? 'admin_accounts.php';

function aa_finish(string $msg, string $type): void
{
    global $referer;
    $_SESSION['message']      = $msg;
    $_SESSION['message_type'] = $type;
    header("Location: " . $referer);
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    aa_finish("Invalid security token. Please refresh and try again.", "error");
}

$action = $_POST['action'] ?? '';

/**
 * Read the submitted course scope and keep only pairs that genuinely exist among students AND
 * that this manager is allowed to assign. Whitelisting against real data (rather than trusting
 * the posted strings) means a tampered form can never mint a scope row for a course nobody is
 * enrolled in — or, for a Coordinator, one belonging to the other institution.
 */
function aa_clean_scope(PDO $pdo, string $managerRole, int $managerId): array
{
    $valid = [];
    foreach (aa_courses_for($pdo, $managerRole, $managerId) as $c) {
        $valid[$c['department'] . '::' . $c['program']] = $c;
    }
    $out = [];
    foreach ((array)($_POST['scope'] ?? []) as $raw) {
        if (isset($valid[$raw])) {
            $out[$raw] = $valid[$raw];
        }
    }
    return array_values($out);
}

/** Replace a focal person's scope rows wholesale. Simpler than diffing and idempotent. */
function aa_write_scope(PDO $pdo, int $user_id, array $courses, int $actor_id): void
{
    $pdo->prepare("DELETE FROM focal_scope WHERE user_id = ?")->execute([$user_id]);
    $ins = $pdo->prepare("INSERT INTO focal_scope (user_id, department, program, created_by) VALUES (?, ?, ?, ?)");
    foreach ($courses as $c) {
        $ins->execute([$user_id, $c['department'], $c['program'], $actor_id]);
    }
}

// ------------------------------------------------------------------ create a staff account
if ($action === 'create_staff') {
    // $role is about to be reused for the role being created; keep the actor's separately.
    $actor_role = $role;
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        aa_finish("Name, email and password are all required.", "error");
    }
    // $role here is the role being CREATED; $actor_role is who is doing the creating.
    if (!in_array($role, aa_creatable_roles($actor_role), true)) {
        aa_finish("You cannot create that kind of account.", "error");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        aa_finish("That email address is not valid.", "error");
    }
    if (strlen($password) < 8) {
        aa_finish("Password must be at least 8 characters.", "error");
    }

    $dupe = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1");
    $dupe->execute([$email, $username]);
    if ($dupe->fetch()) {
        aa_finish("That name or email is already taken by another account.", "error");
    }

    $courses = aa_clean_scope($pdo, $actor_role, $actor_id);
    if ($role === 'Focal Person' && empty($courses)) {
        aa_finish("A Focal Person must be assigned at least one course.", "error");
    }

    // A Coordinator covers exactly one school, and a Statistician one school or both, and each
    // is scoped by it — so it is required up front: an unset one would see nothing and look
    // broken rather than unfinished. Only a Statistician may answer "both"; a Coordinator is one
    // school by definition, and the form hiding that option is a courtesy, not the rule.
    $school = trim($_POST['school'] ?? '');
    if ($role === 'Research Coordinator' || $role === 'Statistician') {
        $valid = [];
        foreach (aa_all_courses($pdo) as $c) $valid[$c['department']] = true;
        if ($role === 'Statistician') $valid[AA_ALL_INSTITUTIONS] = true;
        if (!isset($valid[$school])) {
            aa_finish($role === 'Statistician'
                ? "Pick which institution this Statistician covers, or Admin for both."
                : "Pick which institution this Coordinator covers.", "error");
        }
    } else {
        $school = null;
    }

    // Staff skip the email-verification loop — is_verified = 1 once the account is active.
    // The password is set here and shown once on screen; it is never emailed.
    // Made by the Admin: active now. Made by the Director or a Coordinator: pending until the
    // Admin approves it (and is_verified = 0 until then, so nothing emails a pending account).
    $by_admin = aa_is_admin($actor_role);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, email, role, department, is_verified, account_status, created_by, reviewed_by, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($by_admin ? "NOW()" : "NULL") . ")"
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $email, $role, $school,
                        $by_admin ? 1 : 0, $by_admin ? 'active' : 'pending', $actor_id, $by_admin ? $actor_id : null]);
        $new_id = (int)$pdo->lastInsertId();

        if ($role === 'Focal Person') {
            aa_write_scope($pdo, $new_id, $courses, $actor_id);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        aa_finish("Could not create the account. Please try again.", "error");
    }

    aa_finish($by_admin
        ? htmlspecialchars($username) . " can now sign in as " . htmlspecialchars($role) . "."
        : htmlspecialchars($username) . "'s account was created and is waiting for the administrator's approval.", "success");
}

// ------------------------------------------------------------------ change a focal's courses
if ($action === 'update_scope') {
    $target = (int)($_POST['user_id'] ?? 0);
    $row    = aa_get_staff($pdo, $target);
    if (!$row) {
        aa_finish("That account no longer exists.", "error");
    }
    if ($row['role'] !== 'Focal Person') {
        aa_finish("Only a Focal Person has a course scope.", "error");
    }
    if (!aa_may_manage($pdo, $role, $actor_id, $row)) {
        aa_finish("That account is not yours to manage.", "error");
    }


    $courses = aa_clean_scope($pdo, $role, $actor_id);
    if (empty($courses)) {
        aa_finish("A Focal Person must keep at least one course.", "error");
    }

    aa_write_scope($pdo, $target, $courses, $actor_id);
    aa_finish(htmlspecialchars($row['username']) . " now handles " . count($courses)
        . " course" . (count($courses) === 1 ? "" : "s") . ".", "success");
}

// ------------------------------------------------------------------ a Coordinator's school
// MCNP and ISAP have one coordinator each; her school is what scopes everything she sees, so
// it is set here rather than guessed. Whitelisted against schools that actually have groups.
if ($action === 'set_school') {
    $target = (int)($_POST['user_id'] ?? 0);
    $school = trim($_POST['school'] ?? '');
    $row    = aa_get_staff($pdo, $target);

    if (!$row) aa_finish("That account no longer exists.", "error");
    if (!in_array($row['role'], ['Research Coordinator', 'Statistician'], true)) {
        aa_finish("Only a Coordinator or a Statistician is tied to an institution.", "error");
    }
    // Only the Director moves a Coordinator between institutions — a Coordinator doing it to
    // herself (or her counterpart) would be rewriting her own reach.
    if ($role !== 'Research Director' && !aa_is_admin($role)) aa_finish("Only the Director can change an institution.", "error");

    $valid = [];
    foreach (aa_all_courses($pdo) as $c) $valid[$c['department']] = true;
    if ($row['role'] === 'Statistician') $valid[AA_ALL_INSTITUTIONS] = true;
    if (!isset($valid[$school])) aa_finish("Pick one of the institutions.", "error");

    $pdo->prepare("UPDATE users SET department = ? WHERE user_id = ?")->execute([$school, $target]);
    // aa_school_tag() falls back to MCNP for anything it does not recognise, so the ALL marker
    // has to be spelled out rather than run through it.
    aa_finish(htmlspecialchars($row['username']) . " now covers "
        . ($school === AA_ALL_INSTITUTIONS ? "both institutions" : aa_school_tag($school)) . ".", "success");
}

// ------------------------------------------------------------------ set a new password
if ($action === 'set_password') {
    $target   = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $row      = aa_get_staff($pdo, $target);

    if (!$row) {
        aa_finish("That account no longer exists.", "error");
    }
    if (strlen($password) < 8) {
        aa_finish("Password must be at least 8 characters.", "error");
    }
    if (!aa_may_manage($pdo, $role, $actor_id, $row)) {
        aa_finish("That account is not yours to manage.", "error");
    }

    // 2026-10-01: only the Admin's form carries force_change; a Director/Coordinator reset is unchanged.
    $force = aa_is_admin($role) && !empty($_POST['force_change']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET password = ?, must_change_password = ? WHERE user_id = ?")
        ->execute([password_hash($password, PASSWORD_BCRYPT), $force, $target]);
    aa_finish("New password set for " . htmlspecialchars($row['username']) . ".", "success");
}

// ------------------------------------------------------------------ enable / disable sign-in
if ($action === 'toggle_active') {
    $target = (int)($_POST['user_id'] ?? 0);
    $row    = aa_get_staff($pdo, $target);

    if (!$row) {
        aa_finish("That account no longer exists.", "error");
    }
    if ($target === $actor_id) {
        aa_finish("You cannot disable your own account.", "error");
    }
    if (!aa_may_manage($pdo, $role, $actor_id, $row)) {
        aa_finish("That account is not yours to manage.", "error");
    }


    // A pending or rejected account is the Admin's decision, made with approve/reject — letting
    // "Enable" switch it on would walk round the approval.
    if (in_array($row['account_status'], ['pending', 'rejected'], true)) {
        aa_finish("That account has not been approved yet.", "error");
    }

    // account_status is the real switch (checked at login and on every request, see config/db.php);
    // is_verified moves with it so the staff-email queries keep skipping a disabled account.
    $now = $row['account_status'] === 'active' ? 0 : 1;
    $pdo->prepare("UPDATE users SET is_verified = ?, account_status = ? WHERE user_id = ?")
        ->execute([$now, $now ? 'active' : 'disabled', $target]);
    aa_finish(htmlspecialchars($row['username']) . ($now ? " can sign in again." : " can no longer sign in."), "success");
}

// ================================================================== ADMIN ONLY (2026-09-30)
if (in_array($action, ['approve_account', 'reject_account', 'edit_details'], true)) {
    if (!aa_is_admin($role)) aa_finish("Only the administrator can do that.", "error");
    $target = (int)($_POST['user_id'] ?? 0);
    $row    = aa_get_staff($pdo, $target);
    if (!$row) aa_finish("That account no longer exists.", "error");

    // ---------------------------------------------------------- approve / reject a new account
    if ($action === 'approve_account') {
        // A rejected account can be approved later — the Admin changing her mind is not a new request.
        if (!in_array($row['account_status'], ['pending', 'rejected'], true)) {
            aa_finish(htmlspecialchars($row['username']) . " is not waiting for approval.", "error");
        }
        $pdo->prepare("UPDATE users SET account_status = 'active', is_verified = 1, reviewed_by = ?, reviewed_at = NOW(), review_note = NULL
                       WHERE user_id = ?")->execute([$actor_id, $target]);
        aa_finish(htmlspecialchars($row['username']) . " is approved and can now sign in.", "success");
    }
    if ($action === 'reject_account') {
        if ($row['account_status'] !== 'pending') {
            aa_finish(htmlspecialchars($row['username']) . " is not waiting for approval.", "error");
        }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        $pdo->prepare("UPDATE users SET account_status = 'rejected', is_verified = 0, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                       WHERE user_id = ?")->execute([$actor_id, $note !== '' ? $note : null, $target]);
        aa_finish(htmlspecialchars($row['username']) . "'s account was not approved.", "success");
    }

    // ---------------------------------------------------------- fix a name or an email
    if ($action === 'edit_details') {
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        if ($username === '' || $email === '') aa_finish("Name and email are both required.", "error");
        if (mb_strlen($username) > 50) aa_finish("That name is too long (50 characters at most).", "error");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) aa_finish("That email address is not valid.", "error");
        $dupe = $pdo->prepare("SELECT user_id FROM users WHERE (email = ? OR username = ?) AND user_id <> ? LIMIT 1");
        $dupe->execute([$email, $username, $target]);
        if ($dupe->fetch()) aa_finish("That name or email is already taken by another account.", "error");
        $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?")->execute([$username, $email, $target]);
        if ($target === $actor_id) $_SESSION['username'] = $username;
        aa_finish("Saved " . htmlspecialchars($username) . "'s details.", "success");
    }
}

aa_finish("Unknown action.", "error");
