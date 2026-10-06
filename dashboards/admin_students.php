<?php
// dashboards/admin_students.php — student account maintenance (2026-09-30). ADMIN ONLY.
//
// The twin of admin_accounts.php, built the same way for the same reason — a LONG list: rows,
// not cards; search + pills; actions behind a Manage toggle; the create form on the right.
// Students still sign up on their own (auth/register.php); this is where the Admin fixes what
// goes wrong after: a misspelt name, a wrong email, a School ID typed twice, a group in the wrong
// section, a forgotten password, an email code that never arrived, an account to switch off.
// All writes go through admin_students_handler.php.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin_students_lib.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    exit('Access Denied');
}

$students = as_all_students($pdo);
$leaders  = as_leaders($pdo);
$programs = as_programs();
$years    = as_year_levels();
$csrf     = htmlspecialchars($_SESSION['csrf_token']);

$flash      = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

$n = ['all' => count($students), 'leader' => 0, 'member' => 0, 'unverified' => 0, 'disabled' => 0];
foreach ($students as $s) {
    $n[$s['leader_id'] === null ? 'leader' : 'member']++;
    if (!(int)$s['is_verified']) $n['unverified']++;
    if ($s['account_status'] !== 'active') $n['disabled']++;
}

function as_initials(string $nm): string
{
    $i = strtoupper(mb_substr($nm, 0, 1));
    if (preg_match('/\s(\S)/u', $nm, $m)) $i .= strtoupper($m[1]);
    return $i;
}
function as_tag(?string $school): string
{
    $school = (string)$school;
    if ($school === '') return '';
    return stripos($school, 'International') !== false ? 'ISAP' : 'MCNP';
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
    <title>Student Accounts</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Same language as admin_accounts.php (.aa-*), so the two screens read as one pair. */
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

        .aa-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .aa-pills { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface-3); flex-wrap: wrap; }
        .aa-pill { all: unset; cursor: pointer; padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--ink); display: inline-flex; gap: 6px; align-items: center; }
        .aa-pill span { font-size: 10.5px; font-weight: 800; color: var(--muted); }
        .aa-pill.on { background: var(--surface); box-shadow: var(--shadow-1); }
        .aa-pill.on span { background: var(--ink); color: var(--surface); border-radius: 999px; padding: 1px 6px; }
        .aa-search { position: relative; margin-left: auto; flex: 0 1 300px; min-width: 200px; }
        .aa-search i, .aa-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); pointer-events: none; }
        .aa-search input { width: 100%; box-sizing: border-box; padding: 10px 14px 10px 36px; border: 1.5px solid transparent; border-radius: 999px;
                           background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .aa-search input:focus { border-color: var(--accent); background: var(--surface); }

        .aa-box { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .aa-row { padding: 12px 16px; border-top: 1px solid var(--line); }
        .aa-row.first-shown { border-top: 0; }
        .aa-row.member { padding-left: 34px; }
        .aa-row.off .aa-row-top { opacity: .55; }
        .aa-row-top { display: flex; align-items: center; gap: 12px; }
        .aa-av { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; font-size: 12.5px; font-weight: 800;
                 background: color-mix(in srgb, var(--accent) 22%, var(--surface)); color: var(--ink); }
        .aa-row.member .aa-av { width: 32px; height: 32px; font-size: 11px; background: var(--surface-3); }
        .aa-who { flex: 1; min-width: 0; }
        .aa-nm { font-size: 14px; font-weight: 750; color: var(--ink); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .aa-em { font-size: 12px; color: var(--muted); margin-top: 1px; word-break: break-all; }
        .aa-grp { font-size: 12px; font-weight: 600; color: var(--ink); margin-top: 3px; line-height: 1.35; }
        .aa-tag { font-size: 10.5px; font-weight: 750; border-radius: 999px; padding: 2px 8px; background: var(--surface-3); color: var(--ink); }
        .aa-tag.lead { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); }
        .aa-tag.warn { background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); }
        .aa-tag.bad { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-chips { display: flex; flex-wrap: wrap; gap: 5px; justify-content: flex-end; max-width: 44%; }
        .aa-chip { font-size: 11.5px; font-weight: 650; padding: 4px 10px; border-radius: 999px; background: var(--surface-3); color: var(--ink); white-space: nowrap; }
        .aa-chip b { font-weight: 800; margin-right: 5px; font-size: 10.5px; }
        .aa-chip.mcnp { background: color-mix(in srgb, var(--success) 14%, var(--surface)); }
        .aa-manage { all: unset; cursor: pointer; flex-shrink: 0; border: 1.5px solid var(--line); border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: var(--ink);
                     display: inline-flex; align-items: center; gap: 5px; }
        .aa-manage:hover { background: var(--surface-2); }
        .aa-manage i, .aa-manage svg { width: 13px; height: 13px; transition: transform .15s; }
        .aa-manage.on i, .aa-manage.on svg { transform: rotate(180deg); }
        .aa-acts { margin: 12px 0 2px 50px; padding-top: 10px; border-top: 1px dashed var(--line); display: flex; flex-direction: column; gap: 4px; }
        .aa-drawer summary { font-size: 12.5px; font-weight: 700; color: var(--accent); cursor: pointer; list-style: none; padding: 4px 0; }
        .aa-drawer summary::-webkit-details-marker { display: none; }
        .aa-drawer form { margin: 8px 0 10px; }
        .aa-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .aa-note { font-size: 12px; color: var(--muted); margin: 0 0 8px; }
        .aa-linkbtn { all: unset; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--accent); padding: 4px 0; }
        .aa-linkbtn.bad { color: var(--danger); }
        .aa-none { padding: 26px; text-align: center; color: var(--muted); font-size: 13px; }
        .aa-more { all: unset; cursor: pointer; display: block; text-align: center; padding: 12px; font-size: 12.5px; font-weight: 750; color: var(--accent); border-top: 1px solid var(--line); }

        .aa-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; padding: 22px; box-sizing: border-box; }
        .aa-panel h2 { font-family: var(--disp); font-size: 21px; font-weight: 700; color: var(--ink); margin: 0 0 3px; }
        .aa-hint { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
        .aa-lab { display: flex; align-items: baseline; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 0 0 7px; }
        .aa-lab small { font-weight: 600; color: var(--muted); }
        .aa-field { margin-bottom: 14px; }
        .aa .aa-in, .aa select, .aa .aa-acts input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 14px; border: 1.5px solid transparent;
                background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .aa .aa-in:focus, .aa select:focus, .aa .aa-acts input:focus { border-color: var(--accent); background: var(--surface); }
        .aa-roles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .aa-rc { position: relative; cursor: pointer; border: 1.5px solid var(--line); border-radius: 16px; padding: 10px 12px; }
        .aa-rc input { position: absolute; opacity: 0; pointer-events: none; }
        .aa-rc b { display: block; font-size: 13px; font-weight: 750; color: var(--ink); }
        .aa-rc small { font-size: 11.5px; color: var(--muted); }
        .aa-rc:has(input:checked) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .aa-pw-row { display: flex; gap: 8px; }
        .aa-pw-row input { flex: 1; font-family: 'JetBrains Mono', monospace; }
        .aa-gen { all: unset; cursor: pointer; flex-shrink: 0; padding: 0 14px; border-radius: 14px; background: var(--surface-3); font-size: 12.5px; font-weight: 750; color: var(--ink); }
        .aa-gen:hover { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); }
        .aa-create { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; text-align: center; padding: 13px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                     font-size: 13.5px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .aa-create:hover { filter: brightness(1.07); }
        .aa-create i, .aa-create svg { width: 16px; height: 16px; }
        .aa-small-btn { all: unset; cursor: pointer; padding: 8px 14px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 12px; font-weight: 750; margin-top: 8px; display: inline-block; }
        @media (max-width: 640px) {
            .aa { padding: 16px 12px 30px; } .aa-title { font-size: 28px; }
            .aa-row-top { flex-wrap: wrap; } .aa-chips { max-width: none; width: 100%; justify-content: flex-start; order: 5; margin-left: 50px; }
            .aa-acts { margin-left: 0; } .aa-2 { grid-template-columns: 1fr; }
            .aa-search { margin-left: 0; flex-basis: 100%; }
        }
    </style>
</head>

<body class="admin-module">
<div class="aa">
    <div class="aa-eyebrow">Accounts</div>
    <h1 class="aa-title">Student accounts</h1>
    <p class="aa-sub">Students sign up on their own. Fix what goes wrong after: details, a group's course or section, a forgotten password, a code that never arrived.
        A change to a leader's group is copied to every member.</p>

    <?php if ($flash): ?>
        <div class="aa-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="aa-layout">
        <!-- ------------------------------------------------------------ list -->
        <div>
            <div class="aa-toolbar">
                <div class="aa-pills">
                    <?php foreach (['all' => 'All', 'leader' => 'Leaders', 'member' => 'Members', 'unverified' => 'Email not verified', 'disabled' => 'Disabled'] as $k => $lab):
                        if ($k !== 'all' && !$n[$k]) continue; ?>
                        <button type="button" class="aa-pill <?= $k === 'all' ? 'on' : '' ?>" data-f="<?= $k ?>" onclick="asPick(this)"><?= $lab ?> <span><?= $n[$k] ?></span></button>
                    <?php endforeach; ?>
                </div>
                <label class="aa-search"><i data-lucide="search"></i>
                    <input type="search" id="asFind" placeholder="Name, email, School ID, group or course" autocomplete="off" oninput="asFilter()" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>"></label>
            </div>

            <div class="aa-box" id="asList">
            <?php foreach ($students as $s):
                $lead   = $s['leader_id'] === null;
                $active = $s['account_status'] === 'active';
                $ver    = (int)$s['is_verified'] === 1;
                $tag    = as_tag($s['department']);
                $hay = strtolower(implode(' ', [$s['username'], $s['email'], $s['student_number'], $s['group_name'], $s['program'], $s['department'], $s['leader_name']]));
                $flags = trim(($lead ? 'leader' : 'member') . ($ver ? '' : ' unverified') . ($active ? '' : ' disabled'));
            ?>
                <div class="aa-row <?= $lead ? '' : 'member' ?> <?= $active ? '' : 'off' ?>" data-f="<?= $flags ?>" data-hay="<?= htmlspecialchars($hay) ?>">
                    <div class="aa-row-top">
                        <span class="aa-av"><?= htmlspecialchars(as_initials($s['username'])) ?></span>
                        <div class="aa-who">
                            <div class="aa-nm"><?= htmlspecialchars($s['username']) ?>
                                <?php if ($lead && (int)$s['member_count'] > 0): ?><span class="aa-tag lead">Leader &middot; <?= (int)$s['member_count'] + 1 ?> in group</span><?php endif; ?>
                                <?php if (!$ver): ?><span class="aa-tag warn">Email not verified</span><?php endif; ?>
                                <?php if (!$active): ?><span class="aa-tag bad">Disabled</span><?php endif; ?>
                            </div>
                            <div class="aa-em"><?= htmlspecialchars($s['email']) ?><?= $s['student_number'] ? ' &middot; ' . htmlspecialchars($s['student_number']) : ' &middot; no School ID' ?></div>
                            <?php if ($s['group_name']): ?><div class="aa-grp"><?= htmlspecialchars($s['group_name']) ?><?= !$lead && $s['leader_name'] ? ' &middot; led by ' . htmlspecialchars($s['leader_name']) : '' ?></div><?php endif; ?>
                        </div>
                        <div class="aa-chips">
                            <?php if ($tag): ?><span class="aa-chip <?= strtolower($tag) ?>" title="<?= htmlspecialchars($s['department']) ?>"><b><?= $tag ?></b><?= htmlspecialchars((string)$s['program']) ?><?= $s['section_label'] ? ' &middot; ' . htmlspecialchars($s['section_label']) : '' ?></span><?php endif; ?>
                        </div>
                        <button type="button" class="aa-manage" onclick="asToggle(this)">Manage <i data-lucide="chevron-down"></i></button>
                    </div>

                    <div class="aa-acts" hidden>
                        <details class="aa-drawer">
                            <summary>Fix their details</summary>
                            <form method="POST" action="admin_students_handler.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="edit_student">
                                <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                <div class="aa-2">
                                    <input type="text" name="username" required maxlength="50" value="<?= htmlspecialchars($s['username']) ?>" placeholder="Full name" autocomplete="off">
                                    <input type="text" name="email" required value="<?= htmlspecialchars($s['email']) ?>" placeholder="Email" autocomplete="off">
                                </div>
                                <div class="aa-2">
                                    <input type="text" name="student_number" value="<?= htmlspecialchars((string)$s['student_number']) ?>" placeholder="School ID, e.g. 2023-10078" pattern="\d{4}-\d{4,6}" autocomplete="off">
                                    <select name="year_level">
                                        <option value="">Year level</option>
                                        <?php foreach ($years as $y): ?><option <?= $s['year_level'] === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="aa-small-btn">Save details</button>
                            </form>
                        </details>

                        <?php if ($lead): ?>
                            <details class="aa-drawer">
                                <summary>Group, course and section</summary>
                                <form method="POST" action="admin_students_handler.php">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="edit_group">
                                    <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                    <?php if ((int)$s['member_count'] > 0): ?><p class="aa-note">Saved for all <?= (int)$s['member_count'] + 1 ?> people in this group.</p><?php endif; ?>
                                    <input type="text" name="group_name" required maxlength="100" value="<?= htmlspecialchars((string)$s['research_group_name']) ?>" placeholder="Group name" autocomplete="off" style="margin-bottom:8px">
                                    <div class="aa-2">
                                        <select name="department" required onchange="asPrograms(this)">
                                            <?php foreach (array_keys($programs) as $sch): ?><option value="<?= htmlspecialchars($sch) ?>" <?= $s['department'] === $sch ? 'selected' : '' ?>><?= as_tag($sch) ?></option><?php endforeach; ?>
                                        </select>
                                        <input type="text" name="section_label" required maxlength="1" pattern="[A-Za-z]" value="<?= htmlspecialchars((string)$s['section_label']) ?>" placeholder="Section letter" autocomplete="off">
                                    </div>
                                    <select name="program" required data-current="<?= htmlspecialchars((string)$s['program']) ?>"></select>
                                    <button type="submit" class="aa-small-btn">Save group</button>
                                </form>
                            </details>
                        <?php endif; ?>

                        <?php if (!$lead || (int)$s['member_count'] === 0): ?>
                            <details class="aa-drawer">
                                <summary><?= $lead ? 'Put them in an existing group' : 'Move to another group' ?></summary>
                                <form method="POST" action="admin_students_handler.php">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="move_member">
                                    <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                    <?php /* Filled from #asLeaderOpts when the drawer opens: one list per page, not one per row. */ ?>
                                    <select name="leader_id" required data-leaders data-skip="<?= (int)$s['user_id'] ?>,<?= (int)$s['leader_id'] ?>">
                                        <option value="" disabled selected>Pick a group</option>
                                    </select>
                                    <button type="submit" class="aa-small-btn">Move</button>
                                </form>
                            </details>
                        <?php endif; ?>

                        <details class="aa-drawer">
                            <summary>Set a new password</summary>
                            <form method="POST" action="admin_students_handler.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="set_password">
                                <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                <div class="aa-pw-row">
                                    <input type="text" name="password" required minlength="8" autocomplete="off" placeholder="New password">
                                    <button type="button" class="aa-gen" onclick="asSuggest(this.previousElementSibling)">Generate</button>
                                </div>
                                <label style="display:flex;gap:7px;align-items:center;font-size:12px;color:var(--ink);margin-top:8px;cursor:pointer">
                                    <input type="checkbox" name="force_change" value="1" checked style="accent-color:var(--accent)"> Make them choose a new one when they sign in
                                </label>
                                <button type="submit" class="aa-small-btn">Set password</button>
                            </form>
                        </details>

                        <?php if (!$ver): ?>
                            <form method="POST" action="admin_students_handler.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="verify_email">
                                <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                                <button type="submit" class="aa-linkbtn">Mark email as verified</button>
                            </form>
                        <?php endif; ?>

                        <form method="POST" action="admin_students_handler.php" onsubmit="return confirm('<?= $active ? 'Stop' : 'Allow' ?> this student from signing in?')">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
                            <button type="submit" class="aa-linkbtn <?= $active ? 'bad' : '' ?>"><?= $active ? 'Disable sign-in' : 'Enable sign-in' ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
                <button type="button" class="aa-more" id="asMore" hidden onclick="asShown += 40; asFilter()">Show more</button>
            </div>
            <div class="aa-box aa-none" id="asNone" hidden>Nothing matches that search.</div>
        </div>

        <!-- ------------------------------------------------------------ create -->
        <div class="aa-panel">
            <h2>New student</h2>
            <p class="aa-hint">For someone who cannot sign up themselves. They can sign in right away, with no email code. Give them the password yourself; it is never emailed.</p>

            <form method="POST" action="admin_students_handler.php" id="asCreate">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_student">

                <div class="aa-field">
                    <div class="aa-lab">They are</div>
                    <div class="aa-roles">
                        <label class="aa-rc"><input type="radio" name="mode" value="leader" checked onchange="asMode()"><b>Group leader</b><small>Starts a new group</small></label>
                        <label class="aa-rc"><input type="radio" name="mode" value="member" onchange="asMode()"><b>Member</b><small>Joins a group</small></label>
                    </div>
                </div>
                <div class="aa-field">
                    <label class="aa-lab" for="asName">Full name</label>
                    <input class="aa-in" type="text" name="username" id="asName" required maxlength="50" autocomplete="off">
                </div>
                <div class="aa-field">
                    <label class="aa-lab" for="asEmail">Email</label>
                    <input class="aa-in" type="email" name="email" id="asEmail" required autocomplete="off">
                </div>
                <div class="aa-field aa-2" style="margin-bottom:14px">
                    <div>
                        <label class="aa-lab" for="asSid">School ID</label>
                        <input class="aa-in" type="text" name="student_number" id="asSid" required pattern="\d{4}-\d{4,6}" placeholder="2023-10078" autocomplete="off">
                    </div>
                    <div>
                        <label class="aa-lab" for="asYear">Year level</label>
                        <select name="year_level" id="asYear">
                            <option value="">Pick one</option>
                            <?php foreach ($years as $y): ?><option><?= $y ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="aa-field">
                    <label class="aa-lab" for="asPw">Password <small>8 characters or more</small></label>
                    <div class="aa-pw-row">
                        <input class="aa-in" type="text" name="password" id="asPw" required minlength="8" autocomplete="off">
                        <button type="button" class="aa-gen" onclick="asSuggest(document.getElementById('asPw'))">Generate</button>
                    </div>
                </div>

                <div id="asLeaderWrap">
                    <div class="aa-field">
                        <label class="aa-lab" for="asGroup">Group name</label>
                        <input class="aa-in" type="text" name="group_name" id="asGroup" maxlength="100" autocomplete="off">
                    </div>
                    <div class="aa-field aa-2" style="margin-bottom:14px">
                        <div>
                            <label class="aa-lab" for="asSchool">Institution</label>
                            <select name="department" id="asSchool" onchange="asPrograms(this)">
                                <?php foreach (array_keys($programs) as $sch): ?><option value="<?= htmlspecialchars($sch) ?>"><?= as_tag($sch) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="aa-lab" for="asSec">Section</label>
                            <input class="aa-in" type="text" name="section_label" id="asSec" maxlength="1" pattern="[A-Za-z]" placeholder="A" autocomplete="off">
                        </div>
                    </div>
                    <div class="aa-field">
                        <label class="aa-lab" for="asProg">Course</label>
                        <select name="program" id="asProg" data-current=""></select>
                    </div>
                </div>
                <div class="aa-field" id="asMemberWrap" hidden>
                    <label class="aa-lab" for="asLeader">Their group</label>
                    <select name="leader_id" id="asLeader">
                        <option value="" disabled selected>Pick a group</option>
                        <?php foreach ($leaders as $l): ?>
                            <option value="<?= (int)$l['user_id'] ?>"><?= htmlspecialchars($l['research_group_name']) ?> (<?= htmlspecialchars($l['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <template id="asLeaderOpts"><?php foreach ($leaders as $l): ?><option value="<?= (int)$l['user_id'] ?>"><?= htmlspecialchars($l['research_group_name']) ?> (<?= htmlspecialchars($l['username']) ?>)</option><?php endforeach; ?></template>

                <button type="submit" class="aa-create"><i data-lucide="user-plus"></i> Create account</button>
            </form>
        </div>
    </div>
</div>

<script>
    var AS_PROGRAMS = <?= json_encode($programs) ?>;
    var asF = 'all', asShown = 40;

    // A course select follows the institution picked beside it (same list as registration).
    function asPrograms(sch) {
        var sel = sch.form.querySelector('select[name="program"]');
        var cur = sel.value || sel.dataset.current || '';
        sel.innerHTML = '';
        (AS_PROGRAMS[sch.value] || []).forEach(function (p) {
            var o = document.createElement('option'); o.textContent = p; o.value = p;
            if (p === cur) o.selected = true;
            sel.appendChild(o);
        });
    }
    asPrograms(document.getElementById('asSchool'));   // the row forms fill theirs on first open (asToggle)

    function asMode() {
        var member = document.querySelector('#asCreate input[name="mode"]:checked').value === 'member';
        document.getElementById('asLeaderWrap').hidden = member;
        document.getElementById('asMemberWrap').hidden = !member;
        document.getElementById('asGroup').required = !member;
        document.getElementById('asSec').required = !member;
        document.getElementById('asYear').required = !member;
        document.getElementById('asLeader').required = member;
    }

    function asPick(btn) {
        document.querySelectorAll('.aa-pill').forEach(function (p) { p.classList.toggle('on', p === btn); });
        asF = btn.dataset.f; asShown = 40; asFilter();
    }
    // Search + pill together; 40 rows at a time so a year of sign-ups stays one quick screen.
    function asFilter() {
        var q = document.getElementById('asFind').value.trim().toLowerCase(), hit = 0, shown = 0, first = true;
        document.querySelectorAll('#asList .aa-row').forEach(function (r) {
            var ok = (asF === 'all' || (' ' + r.dataset.f + ' ').indexOf(' ' + asF + ' ') !== -1) && (!q || r.dataset.hay.indexOf(q) !== -1);
            if (ok) hit++;
            var show = ok && shown < asShown;
            if (show) shown++;
            r.hidden = !show;
            r.classList.toggle('first-shown', show && first);
            if (show) first = false;
        });
        document.getElementById('asMore').hidden = hit <= shown;
        document.getElementById('asList').hidden = hit === 0;
        document.getElementById('asNone').hidden = hit !== 0;
    }
    function asToggle(btn) {
        var acts = btn.closest('.aa-row').querySelector('.aa-acts');
        acts.hidden = !acts.hidden;
        btn.classList.toggle('on', !acts.hidden);
        // The group pickers are filled on first open, minus the student themself and their current group.
        acts.querySelectorAll('select[data-leaders]:not([data-filled])').forEach(function (sel) {
            var skip = sel.dataset.skip.split(',');
            document.getElementById('asLeaderOpts').content.querySelectorAll('option').forEach(function (o) {
                if (skip.indexOf(o.value) === -1) sel.appendChild(o.cloneNode(true));
            });
            sel.dataset.filled = '1';
        });
        // The course select of a group form only needs its options when it can be seen.
        acts.querySelectorAll('select[name="department"]').forEach(function (d) {
            var p = d.form.querySelector('select[name="program"]');
            if (p && !p.options.length) asPrograms(d);
        });
    }
    function asSuggest(target) {
        var abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '', rnd = new Uint32Array(12);
        crypto.getRandomValues(rnd);
        for (var i = 0; i < 12; i++) out += abc[rnd[i] % abc.length];
        target.value = out;
    }
    asMode();
    asFilter();
    if (window.lucide) lucide.createIcons();
</script>
</body>

</html>
