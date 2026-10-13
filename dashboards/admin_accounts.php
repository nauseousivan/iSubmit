<?php
// dashboards/admin_accounts.php — the staff account screen.
//
// Creates and manages the non-student accounts: Focal Person, Research Coordinator,
// Research Director, Statistician. Students self-register in auth/register.php and are
// never created here.
//
// A Focal Person is scoped to a LIST of courses (focal_scope) rather than one, because some
// focal persons cover several courses. The picker below is the only place that list is set.
// All writes go through admin_accounts_handler.php.
//
// 2026-09-29 redesign (Claude Design concept): the list is grouped by role, the create form
// sits on the RIGHT and stays in view, roles are cards, and the course picker has institution
// tabs, a "Pick uncovered" shortcut and says WHO covers each course. The one banner above the
// list is the uncovered-course count, with a button that fills the form to fix it. Presentation
// only: every form still posts the same fields to the same handler actions.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'admin_accounts_lib.php';

$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, AA_MANAGER_ROLES, true)) {
    exit('Access Denied');
}

// The Director runs the office; each Coordinator runs ONE institution and sees only the Focal
// Persons of her own courses. Everything below is drawn from these three, so the screen cannot
// offer her a course, a role or an account she is not allowed to touch — and the handler
// refuses the same things independently.
$me       = (int)$_SESSION['user_id'];
// The Admin (2026-09-30) sees what the Director sees plus the Admin accounts, and is the one who
// approves: $isDir below means "sees the whole office", so it is true for both.
$isAdmin  = aa_is_admin($role);
$isDir    = $role === 'Research Director' || $isAdmin;
$mySchool = aa_manager_school($pdo, $role, $me);
$staff    = aa_staff_for($pdo, $role, $me);
$courses  = aa_courses_for($pdo, $role, $me);
$mkRoles  = aa_creatable_roles($role);

// Group the course menu by school so the picker reads the way the office thinks about it.
$by_school = [];
foreach ($courses as $c) {
    $by_school[$c['department']][] = $c;
}

// Which courses already have a focal person — and WHO, so the picker can say it — and which are
// still nobody's job. Coverage is asked of EVERY active focal person, not just the ones this
// manager may edit: a course covered by someone else's account is still covered, and showing it
// as a to-do would have two coordinators both trying to fill it.
$covered = [];
foreach (aa_all_staff($pdo) as $s) {
    if ($s['role'] !== 'Focal Person' || $s['account_status'] !== 'active') continue;
    foreach ($s['scope'] as $sc) {
        $covered[$sc['department'] . '::' . $sc['program']][] = $s['username'];
    }
}
$uncovered = [];
foreach ($courses as $c) {
    if (!isset($covered[$c['department'] . '::' . $c['program']])) $uncovered[] = $c;
}

$flash      = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

// Role groups, in the office's order, with the one line that says what each one does.
$groups = [
    'Admin'                => ['Administrators', 'Maintain every account; view the research workflow', 'Admin'],
    'Research Director'    => ['Research directors', 'Oversee both institutions', 'Director'],
    'Research Coordinator' => ['Research coordinators', 'Confirm defense dates for one institution', 'Coordinator'],
    'Focal Person'         => ['Focal persons', 'Review checklists for their courses', 'Focal'],
    'Statistician'         => ['Statisticians', 'Treat the data for one or both institutions', 'Statistician'],
];
$byRole = [];
foreach ($staff as $s) $byRole[$s['role']][] = $s;
$roleCards = [
    'Focal Person'         => ['Focal person', 'Picks courses'],
    'Research Coordinator' => ['Coordinator', 'One institution'],
    'Research Director'    => ['Director', 'Sees everything'],
    'Statistician'         => ['Statistician', 'One or both institutions'],
    'Admin'                => ['Admin', 'Maintains accounts'],
];

// The sections of the list: accounts WAITING for the Admin's approval lead (they are the only
// rows anyone has to act on), then one section per role. A pending row is listed once, up top.
$pending  = array_values(array_filter($staff, fn($s) => $s['account_status'] === 'pending'));
$sections = [];
if ($pending) {
    $sections[] = ['pending', 'Waiting for approval',
        $isAdmin ? 'Created by the Director or a Coordinator. They cannot sign in until you approve them.'
                 : 'The administrator approves new accounts before they can sign in.', $pending];
}
foreach ($groups as $gRole => [$gTitle, $gLine]) {
    $rows = array_values(array_filter($byRole[$gRole] ?? [], fn($s) => $s['account_status'] !== 'pending'));
    if ($rows) $sections[] = [$gRole, $gTitle, $gLine, $rows];
}
$openPending = $isAdmin && ($_GET['show'] ?? '') === 'pending' && $pending;
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$schoolLabel = $mySchool && $mySchool !== "\0none" ? aa_school_tag($mySchool) : '';

/** Two-letter initials. */
function aa_initials(string $n): string
{
    $i = strtoupper(mb_substr($n, 0, 1));
    if (preg_match('/\s(\S)/u', $n, $m)) $i .= strtoupper($m[1]);
    return $i;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Accounts</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* display:flex/grid beats the `hidden` attribute, so every toggled block needs this. */
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .aa { --disp: "Outfit", var(--font-ui); padding: 22px 26px 40px; max-width: 1360px; margin: 0 auto; }
        .aa-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .aa-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .aa-sub { font-size: 13px; color: var(--muted); margin: 0 0 16px; max-width: 720px; line-height: 1.5; }
        .aa-flash { padding: 11px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600; display: flex; gap: 8px; align-items: center;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .aa-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-flash i, .aa-flash svg { width: 16px; height: 16px; flex-shrink: 0; }

        .aa-layout { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
        @media (max-width: 1080px) { .aa-layout { grid-template-columns: 1fr; } }
        @media (min-width: 1081px) { .aa-panel { position: sticky; top: 14px; max-height: calc(100vh - 28px); overflow-y: auto; } }

        /* the one banner: courses nobody reviews */
        .aa-warn { display: flex; align-items: center; gap: 12px; padding: 13px 16px; border-radius: 18px; margin-bottom: 14px;
                   background: color-mix(in srgb, var(--warning) 16%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); font-size: 13px; }
        .aa-warn i, .aa-warn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .aa-warn b { font-weight: 800; }
        .aa-warn .go { all: unset; cursor: pointer; margin-left: auto; flex-shrink: 0; padding: 9px 15px; border-radius: 999px; font-size: 12.5px; font-weight: 750;
                       background: color-mix(in srgb, var(--warning) 45%, #3b2303); color: #fff; }

        /* toolbar */
        .aa-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .aa-pills { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface-3); flex-wrap: wrap; }
        .aa-pill { all: unset; cursor: pointer; padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--ink); display: inline-flex; gap: 6px; align-items: center; }
        .aa-pill span { font-size: 10.5px; font-weight: 800; color: var(--muted); }
        .aa-pill.on { background: var(--surface); box-shadow: var(--shadow-1); }
        .aa-pill.on span { background: var(--ink); color: var(--surface); border-radius: 999px; padding: 1px 6px; }
        .aa-search { position: relative; margin-left: auto; flex: 0 1 280px; min-width: 200px; }
        .aa-search i, .aa-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); pointer-events: none; }
        .aa-search input { width: 100%; box-sizing: border-box; padding: 10px 14px 10px 36px; border: 1.5px solid transparent; border-radius: 999px;
                           background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .aa-search input:focus { border-color: var(--accent); background: var(--surface); }

        /* role groups */
        .aa-group { margin-bottom: 18px; }
        .aa-group-h { display: flex; align-items: baseline; gap: 10px; margin: 0 4px 8px; }
        .aa-group-h h2 { font-family: var(--disp); font-size: 18px; font-weight: 700; color: var(--ink); margin: 0; }
        .aa-group-h small { font-size: 12px; color: var(--muted); }
        .aa-box { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .aa-row { padding: 12px 16px; border-top: 1px solid var(--line); }
        .aa-row:first-child { border-top: 0; }
        .aa-row.off .aa-row-top { opacity: .55; }
        .aa-row-top { display: flex; align-items: center; gap: 12px; }
        .aa-av { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; font-size: 12.5px; font-weight: 800; color: #fff; background: var(--sh-hero-b, var(--ink)); }
        .aa-av.r-coord { background: color-mix(in srgb, var(--accent) 22%, var(--surface)); color: var(--ink); }
        .aa-av.r-focal { background: #f6e3b4; color: #5b3a06; }
        .aa-av.r-stat { background: #cfe8d7; color: #14532d; }
        .aa-who { flex: 1; min-width: 0; }
        .aa-nm { font-size: 14px; font-weight: 750; color: var(--ink); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .aa-em { font-size: 12px; color: var(--muted); margin-top: 1px; word-break: break-all; }
        .aa-you, .aa-off-tag { font-size: 10.5px; font-weight: 750; border-radius: 999px; padding: 2px 8px; }
        .aa-you { background: var(--surface-3); color: var(--ink); }
        .aa-off-tag { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-chips { display: flex; flex-wrap: wrap; gap: 5px; justify-content: flex-end; max-width: 46%; }
        .aa-chip { font-size: 11.5px; font-weight: 650; padding: 4px 10px; border-radius: 999px; background: var(--surface-3); color: var(--ink); white-space: nowrap;
                   max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
        .aa-chip b { font-weight: 800; margin-right: 5px; font-size: 10.5px; }
        .aa-chip.mcnp { background: color-mix(in srgb, var(--success) 14%, var(--surface)); }
        .aa-chip.none { background: color-mix(in srgb, var(--danger) 11%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-manage { all: unset; cursor: pointer; flex-shrink: 0; border: 1.5px solid var(--line); border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: var(--ink);
                     display: inline-flex; align-items: center; gap: 5px; }
        .aa-manage:hover { background: var(--surface-2); }
        .aa-manage i, .aa-manage svg { width: 13px; height: 13px; transition: transform .15s; }
        .aa-manage.on i, .aa-manage.on svg { transform: rotate(180deg); }
        .aa-acts { margin: 12px 0 2px 50px; padding-top: 10px; border-top: 1px dashed var(--line); display: flex; flex-direction: column; gap: 4px; }
        .aa-drawer summary { font-size: 12.5px; font-weight: 700; color: var(--accent); cursor: pointer; list-style: none; padding: 4px 0; }
        .aa-drawer summary::-webkit-details-marker { display: none; }
        .aa-drawer form { margin: 8px 0 10px; }
        .aa-linkbtn { all: unset; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--accent); padding: 4px 0; }
        .aa-linkbtn.bad { color: var(--danger); }
        .aa-none { padding: 26px; text-align: center; color: var(--muted); font-size: 13px; }

        /* create panel */
        .aa-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; padding: 22px; box-sizing: border-box; }
        .aa-panel h2 { font-family: var(--disp); font-size: 21px; font-weight: 700; color: var(--ink); margin: 0 0 3px; }
        .aa-hint { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
        .aa-lab { display: flex; align-items: baseline; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 0 0 7px; }
        .aa-lab small { font-weight: 600; color: var(--muted); }
        .aa-field { margin-bottom: 14px; }
        .aa .aa-in, .aa .aa-panel select, .aa .aa-acts select, .aa .aa-acts input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 14px; border: 1.5px solid transparent;
                background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .aa .aa-in:focus, .aa .aa-panel select:focus, .aa .aa-acts input:focus { border-color: var(--accent); background: var(--surface); }
        .aa-roles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .aa-rc { position: relative; cursor: pointer; border: 1.5px solid var(--line); border-radius: 16px; padding: 10px 12px; }
        .aa-rc input { position: absolute; opacity: 0; pointer-events: none; }
        .aa-rc b { display: block; font-size: 13px; font-weight: 750; color: var(--ink); }
        .aa-rc small { font-size: 11.5px; color: var(--muted); }
        .aa-rc:has(input:checked) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .aa-rc:has(input:focus-visible) { box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
        .aa-pw-row { display: flex; gap: 8px; }
        .aa-pw-row input { flex: 1; font-family: 'JetBrains Mono', monospace; }
        .aa-gen { all: unset; cursor: pointer; flex-shrink: 0; padding: 0 14px; border-radius: 14px; background: var(--surface-3); font-size: 12.5px; font-weight: 750; color: var(--ink); }
        .aa-gen:hover { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); }
        .aa-scope-top { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
        .aa-picked { margin-left: auto; font-size: 12px; font-weight: 700; color: var(--muted); }
        .aa-picked.some { color: var(--accent); }
        .aa-tabs { display: inline-flex; gap: 2px; padding: 3px; border-radius: 999px; background: var(--surface-3); }
        .aa-tab { all: unset; cursor: pointer; padding: 5px 13px; border-radius: 999px; font-size: 12px; font-weight: 750; color: var(--ink); }
        .aa-tab.on { background: var(--ink); color: var(--surface); }
        .aa-pickfree { all: unset; cursor: pointer; margin-left: auto; font-size: 12px; font-weight: 750; color: var(--accent); }
        .aa-scope { border-radius: 16px; background: var(--surface-2); padding: 6px; max-height: 330px; overflow-y: auto; margin-bottom: 14px; }
        .aa-scope-school { font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin: 8px 8px 4px; }
        .aa-course { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; cursor: pointer; font-size: 12.5px; color: var(--ink); }
        .aa-course:hover { background: var(--surface); }
        .aa-course input { width: 16px; height: 16px; flex-shrink: 0; margin: 0; accent-color: var(--accent); }
        .aa-course .nm { flex: 1; min-width: 0; line-height: 1.3; }
        .aa-course .who { flex-shrink: 0; max-width: 42%; font-size: 10.5px; font-weight: 650; color: var(--accent); text-align: right; line-height: 1.25; }
        .aa-course .free { flex-shrink: 0; font-size: 10.5px; font-weight: 750; padding: 2px 8px; border-radius: 999px;
                           background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 50%, var(--ink)); }
        .aa-create { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; text-align: center; padding: 13px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                     font-size: 13.5px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .aa-create:hover { filter: brightness(1.07); }
        .aa-create i, .aa-create svg { width: 16px; height: 16px; }
        /* approvals (2026-09-30) */
        .aa-av.r-admin { background: var(--ink); color: var(--surface); }
        .aa-wait-tag { font-size: 10.5px; font-weight: 750; border-radius: 999px; padding: 2px 8px;
                       background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); }
        .aa-group-wait .aa-box { border-color: color-mix(in srgb, var(--warning) 45%, var(--line)); background: color-mix(in srgb, var(--warning) 5%, var(--surface)); }
        .aa-decide { margin: 0; flex-shrink: 0; }
        .aa-approve { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px; border-radius: 999px;
                      background: var(--accent); color: var(--accent-ink); font-size: 12px; font-weight: 750; }
        .aa-approve:hover { filter: brightness(1.07); }
        .aa-approve i, .aa-approve svg { width: 14px; height: 14px; }
        .aa-small-btn.aa-small-bad { background: var(--danger); color: #fff; }
        .aa-small-btn { all: unset; cursor: pointer; padding: 8px 14px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 12px; font-weight: 750; margin-top: 8px; display: inline-block; }
        @media (max-width: 640px) {
            .aa { padding: 16px 12px 30px; } .aa-title { font-size: 28px; }
            .aa-row-top { flex-wrap: wrap; } .aa-chips { max-width: none; width: 100%; justify-content: flex-start; order: 5; margin-left: 50px; }
            .aa-acts { margin-left: 0; } .aa-warn { flex-wrap: wrap; } .aa-warn .go { margin-left: 30px; }
            .aa-search { margin-left: 0; flex-basis: 100%; }
        }
    </style>
</head>

<body class="admin-module">
<div class="aa">
    <div class="aa-eyebrow"><?= $isAdmin ? 'Accounts' : 'Workspace' ?></div>
    <h1 class="aa-title"><?= $isDir ? 'Staff accounts' : 'Your focal persons' ?></h1>
    <p class="aa-sub">
        <?php if ($isAdmin): ?>
            Every staff account. What you create can sign in right away; what the Director or a Coordinator creates waits for your approval.
        <?php elseif ($isDir): ?>
            The people who run the research office. A Focal person only sees the courses assigned to them. Students sign up on their own.
        <?php else: ?>
            The focal persons of <b><?= $schoolLabel ?: 'your institution' ?></b>. A Focal person only sees the courses you assign them.
            Only <?= $schoolLabel ?: 'your' ?> courses are listed &mdash; the other institution has its own coordinator.
        <?php endif; ?>
    </p>

    <?php if ($flash): ?>
        <div class="aa-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($mySchool === "\0none"): ?>
        <div class="aa-flash bad"><i data-lucide="alert-circle"></i>No institution is set on your account, so there are no courses you can assign. Ask the Director to set it.</div>
    <?php endif; ?>

    <div class="aa-layout">
        <!-- ------------------------------------------------------------ list -->
        <div>
            <?php if ($uncovered && in_array('Focal Person', $mkRoles, true)): ?>
                <div class="aa-warn">
                    <i data-lucide="triangle-alert"></i>
                    <span><b><?= count($uncovered) ?> course<?= count($uncovered) === 1 ? ' has' : 's have' ?> no Focal person.</b> Nobody is assigned to review those groups' checklists.</span>
                    <button type="button" class="go" onclick="aaAssign()">Assign a Focal person</button>
                </div>
            <?php endif; ?>

            <?php if ($staff): ?>
                <div class="aa-toolbar">
                    <div class="aa-pills" id="aaPills">
                        <button type="button" class="aa-pill <?= $openPending ? '' : 'on' ?>" data-role="" onclick="aaPick(this)">All <span><?= count($staff) ?></span></button>
                        <?php if ($pending): ?>
                            <button type="button" class="aa-pill aa-pill-wait <?= $openPending ? 'on' : '' ?>" data-role="" data-status="pending" onclick="aaPick(this)">Waiting <span><?= count($pending) ?></span></button>
                        <?php endif; ?>
                        <?php foreach ($groups as $r => $g): if (empty($byRole[$r])) continue; ?>
                            <button type="button" class="aa-pill" data-role="<?= htmlspecialchars($r) ?>" onclick="aaPick(this)"><?= $g[2] ?> <span><?= count($byRole[$r]) ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <label class="aa-search"><i data-lucide="search"></i>
                        <input type="search" id="aaFind" placeholder="Name, email or course" autocomplete="off" oninput="aaFilter()"></label>
                </div>
            <?php endif; ?>

            <div id="aaList">
            <?php foreach ($sections as [$gRole, $gTitle, $gLine, $gRows]): ?>
                <section class="aa-group <?= $gRole === 'pending' ? 'aa-group-wait' : '' ?>" data-group="<?= htmlspecialchars($gRole) ?>">
                    <div class="aa-group-h"><h2><?= $gTitle ?></h2><small><?= $gLine ?></small></div>
                    <div class="aa-box">
                    <?php foreach ($gRows as $s):
                        $is_focal = $s['role'] === 'Focal Person';
                        $status   = (string)$s['account_status'];
                        $active   = $status === 'active';
                        $waiting  = $status === 'pending';
                        $sel      = [];
                        foreach ($s['scope'] as $sc) $sel[$sc['department'] . '::' . $sc['program']] = true;
                        $isMe     = (int)$s['user_id'] === $me;
                        $hay = strtolower($s['username'] . ' ' . $s['email'] . ' ' . $s['role'] . ' '
                             . implode(' ', array_column($s['scope'], 'program')) . ' ' . (string)$s['department']);
                        $avc = ['Research Coordinator' => 'r-coord', 'Focal Person' => 'r-focal', 'Statistician' => 'r-stat', 'Admin' => 'r-admin'][$s['role']] ?? '';
                    ?>
                        <div class="aa-row <?= $active || $waiting ? '' : 'off' ?>" data-role="<?= htmlspecialchars($s['role']) ?>" data-status="<?= htmlspecialchars($status) ?>" data-hay="<?= htmlspecialchars($hay) ?>">
                            <div class="aa-row-top">
                                <span class="aa-av <?= $avc ?>"><?= htmlspecialchars(aa_initials($s['username'])) ?></span>
                                <div class="aa-who">
                                    <div class="aa-nm"><?= htmlspecialchars($s['username']) ?>
                                        <?php if ($isMe): ?><span class="aa-you">You</span><?php endif; ?>
                                        <?php if ($waiting): ?><span class="aa-wait-tag"><?= htmlspecialchars($groups[$s['role']][2] ?? $s['role']) ?> &middot; waiting for approval</span>
                                        <?php elseif ($status === 'rejected'): ?><span class="aa-off-tag">Not approved</span>
                                        <?php elseif (!$active): ?><span class="aa-off-tag">Disabled</span><?php endif; ?>
                                    </div>
                                    <div class="aa-em"><?= htmlspecialchars($s['email']) ?></div>
                                    <?php if ($waiting && !empty($s['creator_name'])): ?>
                                        <div class="aa-em">Requested by <?= htmlspecialchars($s['creator_name']) ?> &middot; <?= htmlspecialchars($s['created_on']) ?></div>
                                    <?php elseif ($status === 'rejected'): ?>
                                        <div class="aa-em">Not approved<?= $s['reviewer_name'] ? ' by ' . htmlspecialchars($s['reviewer_name']) : '' ?><?= $s['review_note'] ? ' &middot; &ldquo;' . htmlspecialchars($s['review_note']) . '&rdquo;' : '' ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($waiting && $isAdmin): ?>
                                    <?php /* The one decision on this screen that someone is waiting on: out on the row, not behind Manage. */ ?>
                                    <form method="POST" action="admin_accounts_handler.php" class="aa-decide">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="approve_account">
                                        <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                        <button type="submit" class="aa-approve"><i data-lucide="check"></i> Approve</button>
                                    </form>
                                <?php endif; ?>
                                <div class="aa-chips">
                                    <?php if (in_array($s['role'], ['Research Coordinator', 'Statistician'], true)): ?>
                                        <?php /* Never hand the ALL marker to aa_school_tag(): it would say MCNP. */ ?>
                                        <?php if ($s['role'] === 'Statistician' && ($s['department'] ?? '') === AA_ALL_INSTITUTIONS): ?>
                                            <span class="aa-chip"><b>ADMIN</b>Both institutions</span>
                                        <?php elseif (!empty($s['department'])): $t = aa_school_tag($s['department']); ?>
                                            <span class="aa-chip <?= strtolower($t) ?>" title="<?= htmlspecialchars($s['department']) ?>"><b><?= $t ?></b><?= htmlspecialchars($s['department']) ?></span>
                                        <?php else: ?>
                                            <span class="aa-chip none">No institution, sees nothing</span>
                                        <?php endif; ?>
                                    <?php elseif ($is_focal): ?>
                                        <?php if (empty($s['scope'])): ?>
                                            <span class="aa-chip none">No courses, sees nothing</span>
                                        <?php else: $first = $s['scope'][0]; $t = aa_school_tag($first['department']); ?>
                                            <span class="aa-chip <?= strtolower($t) ?>" title="<?= htmlspecialchars(implode(', ', array_column($s['scope'], 'program'))) ?>"><b><?= $t ?></b><?= htmlspecialchars($first['program']) ?></span>
                                            <?php if (count($s['scope']) > 1): ?><span class="aa-chip">+<?= count($s['scope']) - 1 ?> more</span><?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="aa-manage" onclick="aaToggle(this)">Manage <i data-lucide="chevron-down"></i></button>
                            </div>

                            <div class="aa-acts" hidden>
                                <?php if ($waiting && $isAdmin): ?>
                                    <details class="aa-drawer">
                                        <summary>Do not approve</summary>
                                        <form method="POST" action="admin_accounts_handler.php" onsubmit="return confirm('Turn down this account? They will not be able to sign in.')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="reject_account">
                                            <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                            <input type="text" name="note" maxlength="255" autocomplete="off" placeholder="Reason (optional) — the person who asked will see it">
                                            <button type="submit" class="aa-small-btn aa-small-bad">Do not approve</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($status === 'rejected' && $isAdmin): ?>
                                    <form method="POST" action="admin_accounts_handler.php">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="approve_account">
                                        <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                        <button type="submit" class="aa-linkbtn">Approve after all</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                    <details class="aa-drawer">
                                        <summary>Fix their name or email</summary>
                                        <form method="POST" action="admin_accounts_handler.php">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="edit_details">
                                            <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                            <input type="text" name="username" required maxlength="50" value="<?= htmlspecialchars($s['username']) ?>" autocomplete="off" style="margin-bottom:8px">
                                            <input type="text" name="email" required value="<?= htmlspecialchars($s['email']) ?>" autocomplete="off">
                                            <button type="submit" class="aa-small-btn">Save details</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($is_focal): ?>
                                    <details class="aa-drawer">
                                        <summary>Change their courses</summary>
                                        <form method="POST" action="admin_accounts_handler.php">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="update_scope">
                                            <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                            <div class="aa-scope" style="max-height:240px">
                                                <?php foreach ($by_school as $school => $list): ?>
                                                    <div class="aa-scope-school"><?= aa_school_tag($school) ?></div>
                                                    <?php foreach ($list as $c): $k = $c['department'] . '::' . $c['program']; ?>
                                                        <label class="aa-course">
                                                            <input type="checkbox" name="scope[]" value="<?= htmlspecialchars($k) ?>" <?= isset($sel[$k]) ? 'checked' : '' ?>>
                                                            <span class="nm"><?= htmlspecialchars($c['program']) ?></span>
                                                            <?php if (!isset($covered[$k])): ?><span class="free">No focal yet</span><?php endif; ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </div>
                                            <button type="submit" class="aa-small-btn">Save courses</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if (in_array($s['role'], ['Research Coordinator', 'Statistician'], true) && $isDir): ?>
                                    <details class="aa-drawer">
                                        <summary>Change their institution</summary>
                                        <form method="POST" action="admin_accounts_handler.php">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="set_school">
                                            <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                            <select name="school" required>
                                                <option value="" disabled <?= empty($s['department']) ? 'selected' : '' ?>>Pick an institution</option>
                                                <?php if ($s['role'] === 'Statistician'): ?>
                                                    <option value="<?= AA_ALL_INSTITUTIONS ?>" <?= ($s['department'] ?? '') === AA_ALL_INSTITUTIONS ? 'selected' : '' ?>>Admin &mdash; both institutions</option>
                                                <?php endif; ?>
                                                <?php foreach (array_keys($by_school) as $sch): ?>
                                                    <option value="<?= htmlspecialchars($sch) ?>" <?= $s['department'] === $sch ? 'selected' : '' ?>><?= htmlspecialchars($sch) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="aa-small-btn">Save institution</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <details class="aa-drawer">
                                    <summary>Set a new password</summary>
                                    <form method="POST" action="admin_accounts_handler.php">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="set_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                        <div class="aa-pw-row">
                                            <input type="text" name="password" required minlength="8" autocomplete="off" placeholder="New password">
                                            <button type="button" class="aa-gen" onclick="aaSuggest(this.previousElementSibling)">Generate</button>
                                        </div>
                                        <?php if ($isAdmin): /* 2026-10-01: the Admin's resets can force a change at next sign-in */ ?>
                                            <label style="display:flex;gap:7px;align-items:center;font-size:12px;color:var(--ink);margin-top:8px;cursor:pointer">
                                                <input type="checkbox" name="force_change" value="1" checked style="accent-color:var(--accent)"> Make them choose a new one when they sign in
                                            </label>
                                        <?php endif; ?>
                                        <button type="submit" class="aa-small-btn">Set password</button>
                                    </form>
                                </details>

                                <?php /* Last, and quiet: disabling is the one thing here that stops someone working. */ ?>
                                <?php /* Not on a pending/rejected row: that is the approval's call, and the handler refuses it. */ ?>
                                <?php if (!$isMe && ($active || $status === 'disabled')): ?>
                                    <form method="POST" action="admin_accounts_handler.php" onsubmit="return confirm('<?= $active ? 'Stop' : 'Allow' ?> this person from signing in?')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                        <button type="submit" class="aa-linkbtn <?= $active ? 'bad' : '' ?>"><?= $active ? 'Disable sign-in' : 'Enable sign-in' ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>

            <div class="aa-box aa-none" id="aaNone" hidden>Nothing matches that search.</div>
            <?php if (empty($staff)): ?>
                <div class="aa-box aa-none">No <?= $isDir ? 'staff accounts' : 'focal persons' ?> yet &mdash; create the first one with the form.</div>
            <?php endif; ?>
        </div>

        <!-- ------------------------------------------------------------ create -->
        <div class="aa-panel" id="aaPanel">
            <h2>New <?= $isDir ? 'staff account' : 'focal person' ?></h2>
            <p class="aa-hint"><?= $isAdmin ? 'They can sign in right away.' : 'The administrator approves new accounts before they can sign in.' ?> Give them the password yourself; it is never emailed.</p>

            <form method="POST" action="admin_accounts_handler.php" id="aaCreate">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_staff">

                <?php if (count($mkRoles) > 1): ?>
                    <div class="aa-field">
                        <div class="aa-lab">Role</div>
                        <div class="aa-roles">
                            <?php foreach ($roleCards as $r => [$rl, $rs]): if (!in_array($r, $mkRoles, true)) continue; ?>
                                <label class="aa-rc"><input type="radio" name="role" value="<?= htmlspecialchars($r) ?>" <?= $r === 'Focal Person' ? 'checked' : '' ?> onchange="aaSyncScope()">
                                    <b><?= $rl ?></b><small><?= $rs ?></small></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php /* One role on offer: state it and post it, no control that cannot be used. */ ?>
                    <input type="hidden" name="role" id="aaRoleOnly" value="<?= htmlspecialchars($mkRoles[0] ?? '') ?>">
                <?php endif; ?>

                <?php /* Identity before the course picker: it is twenty-one checkboxes tall. */ ?>
                <div class="aa-field">
                    <label class="aa-lab" for="aaName">Full name</label>
                    <input class="aa-in" type="text" name="username" id="aaName" required autocomplete="off" placeholder="Ms. Reyes">
                </div>
                <div class="aa-field">
                    <label class="aa-lab" for="aaEmail">Email</label>
                    <input class="aa-in" type="email" name="email" id="aaEmail" required autocomplete="off" placeholder="reyes@mcnp.edu.ph">
                </div>
                <div class="aa-field">
                    <label class="aa-lab" for="aaPw">Password <small>8 characters or more</small></label>
                    <div class="aa-pw-row">
                        <input class="aa-in" type="text" name="password" id="aaPw" required minlength="8" autocomplete="off">
                        <button type="button" class="aa-gen" onclick="aaSuggest()">Generate</button>
                    </div>
                </div>

                <?php if ($isDir): ?>
                <div class="aa-field" id="aaSchoolWrap">
                    <label class="aa-lab" for="aaSchool">Institution they cover</label>
                    <select name="school" id="aaSchool">
                        <option value="" disabled selected>Pick an institution</option>
                        <?php /* Statistician only; the JS hides it for a Coordinator and the handler refuses it. */ ?>
                        <option value="<?= AA_ALL_INSTITUTIONS ?>" id="aaSchoolAll" hidden>Admin &mdash; both institutions</option>
                        <?php foreach (array_keys($by_school) as $sch): ?>
                            <option value="<?= htmlspecialchars($sch) ?>"><?= htmlspecialchars($sch) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div id="aaScopeWrap">
                    <div class="aa-scope-top">
                        <span class="aa-lab" style="margin:0">Courses they handle</span>
                        <span class="aa-picked" id="aaPicked">None picked</span>
                    </div>
                    <div class="aa-scope-top">
                        <?php if (count($by_school) > 1): ?>
                            <div class="aa-tabs" id="aaTabs">
                                <?php $ti = 0; foreach (array_keys($by_school) as $sch): ?>
                                    <button type="button" class="aa-tab <?= $ti++ === 0 ? 'on' : '' ?>" data-inst="<?= htmlspecialchars($sch) ?>" onclick="aaTab(this)"><?= aa_school_tag($sch) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($uncovered): ?><button type="button" class="aa-pickfree" onclick="aaPickFree()">Pick uncovered</button><?php endif; ?>
                    </div>
                    <div class="aa-scope" id="aaScopeBox">
                        <?php foreach ($by_school as $school => $list): ?>
                            <?php if (count($by_school) < 2): ?><div class="aa-scope-school"><?= aa_school_tag($school) ?></div><?php endif; ?>
                            <?php foreach ($list as $c): $k = $c['department'] . '::' . $c['program']; ?>
                                <label class="aa-course" data-inst="<?= htmlspecialchars($school) ?>">
                                    <input type="checkbox" name="scope[]" onchange="aaCount()" value="<?= htmlspecialchars($k) ?>" data-free="<?= isset($covered[$k]) ? '0' : '1' ?>">
                                    <span class="nm"><?= htmlspecialchars($c['program']) ?></span>
                                    <?php if (isset($covered[$k])): ?><span class="who"><?= htmlspecialchars(implode(', ', $covered[$k])) ?></span>
                                    <?php else: ?><span class="free">No focal yet</span><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (empty($by_school)): ?><p class="aa-hint" style="margin:8px">No courses have research groups yet.</p><?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="aa-create"><i data-lucide="user-plus"></i> Create account</button>
            </form>
        </div>
    </div>
</div>

<script>
    // The chosen role: a radio card for the Director, a fixed hidden value for a Coordinator.
    function aaRoleValue() {
        var r = document.querySelector('#aaCreate input[name="role"]:checked') || document.getElementById('aaRoleOnly');
        return r ? r.value : '';
    }
    var aaScopeWrap = document.getElementById('aaScopeWrap');
    var aaSchoolWrap = document.getElementById('aaSchoolWrap');
    var aaSchool = document.getElementById('aaSchool');
    var aaSchoolAll = document.getElementById('aaSchoolAll');

    // Only a Focal Person is scoped to courses. Unchecking on hide keeps a stale tick from riding
    // along in the POST (the handler ignores it anyway, but this keeps the form honest).
    function aaSyncScope() {
        var role = aaRoleValue();
        var focal = role === 'Focal Person', coord = role === 'Research Coordinator', stat = role === 'Statistician';
        aaScopeWrap.hidden = !focal;
        if (!focal) aaScopeWrap.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        aaCount();
        if (!aaSchoolWrap || !aaSchool) return;
        // Both are school-shaped, so both ask. Only a Statistician may answer "both":
        // hiding the option is the courtesy, the handler is the rule.
        aaSchoolWrap.hidden = !(coord || stat);
        aaSchool.required = (coord || stat);
        if (aaSchoolAll) {
            aaSchoolAll.hidden = !stat;
            aaSchoolAll.disabled = !stat;
            if (!stat && aaSchool.value === aaSchoolAll.value) aaSchool.value = '';
        }
        if (!coord && !stat) aaSchool.value = '';
    }

    // ---- list: search + role filter, applied together ---------------------------------
    var aaRoleFilter = '', aaStatusFilter = '';
    function aaPick(btn) {
        document.querySelectorAll('.aa-pill').forEach(function (p) { p.classList.toggle('on', p === btn); });
        aaRoleFilter = btn.dataset.role || '';
        aaStatusFilter = btn.dataset.status || '';
        aaFilter();
    }
    function aaFilter() {
        var box = document.getElementById('aaFind');
        var q = box ? box.value.trim().toLowerCase() : '';
        var shown = 0;
        document.querySelectorAll('.aa-group').forEach(function (g) {
            var inGroup = 0;
            g.querySelectorAll('.aa-row').forEach(function (row) {
                var show = (!aaRoleFilter || row.dataset.role === aaRoleFilter)
                        && (!aaStatusFilter || row.dataset.status === aaStatusFilter) && (!q || (row.dataset.hay || '').indexOf(q) !== -1);
                row.hidden = !show;
                if (show) inGroup++;
            });
            g.hidden = inGroup === 0;
            shown += inGroup;
        });
        var none = document.getElementById('aaNone');
        if (none) none.hidden = shown !== 0 || !document.querySelector('.aa-row');
    }
    function aaToggle(btn) {
        var acts = btn.closest('.aa-row').querySelector('.aa-acts');
        if (!acts) return;
        acts.hidden = !acts.hidden;
        btn.classList.toggle('on', !acts.hidden);
    }

    // ---- create form: institution tabs, uncovered shortcut, live count ---------------------
    function aaTab(btn) {
        document.querySelectorAll('.aa-tab').forEach(function (t) { t.classList.toggle('on', t === btn); });
        document.querySelectorAll('#aaScopeBox .aa-course').forEach(function (c) { c.hidden = c.dataset.inst !== btn.dataset.inst; });
    }
    function aaPickFree() {
        // Only the courses on screen (the open institution tab) — a focal person is one institution.
        document.querySelectorAll('#aaScopeBox .aa-course:not([hidden]) input[data-free="1"]').forEach(function (c) { c.checked = true; });
        aaCount();
    }
    function aaCount() {
        var n = document.querySelectorAll('#aaScopeBox input[name="scope[]"]:checked').length;
        var out = document.getElementById('aaPicked');
        if (!out) return;
        out.textContent = n === 0 ? 'None picked' : n + (n === 1 ? ' course' : ' courses') + ' picked';
        out.classList.toggle('some', n > 0);
    }
    // The banner's button: set the form up for a new Focal Person on the uncovered courses.
    function aaAssign() {
        var f = document.querySelector('#aaCreate input[name="role"][value="Focal Person"]');
        if (f) { f.checked = true; aaSyncScope(); }
        // Open the tab of the first institution that still has an uncovered course.
        var free = document.querySelector('#aaScopeBox input[data-free="1"]');
        if (free) {
            var inst = free.closest('.aa-course').dataset.inst;
            var tab = document.querySelector('.aa-tab[data-inst="' + (window.CSS && CSS.escape ? CSS.escape(inst) : inst) + '"]');
            if (tab) aaTab(tab);
        }
        document.getElementById('aaPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () { document.getElementById('aaName').focus(); }, 350);
    }

    // Readable throwaway password: no look-alike characters, so it survives being read off a
    // screen and typed by hand.
    function aaSuggest(target) {
        var abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var out = '', rnd = new Uint32Array(12);
        crypto.getRandomValues(rnd);
        for (var i = 0; i < 12; i++) out += abc[rnd[i] % abc.length];
        (target || document.getElementById('aaPw')).value = out;
    }

    var firstTab = document.querySelector('.aa-tab.on');
    if (firstTab) aaTab(firstTab);
    aaSyncScope();
    // Opened from the Admin's "Account approvals" card: start on the Waiting pill.
    var aaWaitPill = document.querySelector('.aa-pill-wait.on');
    if (aaWaitPill) aaPick(aaWaitPill); else aaFilter();
    if (window.lucide) lucide.createIcons();
</script>
</body>

</html>
