<?php
// dashboards/stat_desk.php — what the statistician's DESK QR opens (2026-10-01).
//
// The student shows the QR on their Statistics card; the statistician scans it with her phone
// after going through the files with them. This page CONFIRMS — it never approves on the scan
// alone (phone cameras preload links, and a look must not be a decision). Confirming posts to the
// existing bulk approval in admin_module_dynamic.php, which does the scope check, the phase
// moves and the student's notification — nothing here writes. See stat_desk_lib.php.
//
// Who sees what:
//   Statistician of the group's institution (or the admin statistician) -> Confirm
//   Statistician of the OTHER institution -> told whose it is, nothing shown
//   Coordinator / Director / Admin in scope -> the same card, read-only
//   Student -> "this code is for the statistician"
//   Not signed in -> sign in, then straight back here

require_once '../config/db.php';
require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/stat_desk_lib.php';

if (empty($_SESSION['user_id'])) {
    $_SESSION['login_next'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: ../auth/login.php');
    exit;
}

$role  = (string)($_SESSION['role'] ?? '');
$me    = (int)$_SESSION['user_id'];
$g     = (int)($_GET['g'] ?? 0);
$ids   = array_values(array_filter(array_map('intval', explode('-', (string)($_GET['u'] ?? '')))));
sort($ids);
$valid = $g > 0 && $ids && hash_equals(sd_token($g, $ids), (string)($_GET['t'] ?? ''));

$grp = null;
if ($valid) {
    $st = $pdo->prepare("SELECT user_id, research_group_name, department, program FROM users WHERE user_id = ? AND role = 'Student'");
    $st->execute([$g]);
    $grp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Decide the one thing this screen says.
$screen = 'invalid';
$files = []; $pending = null; $ctl = '';
if ($grp) {
    $inScope = aa_may_touch_student($pdo, $role, $me, $g);
    if ($role === 'Student')                          $screen = 'student';
    elseif ($role === 'Statistician' && !$inScope)    $screen = 'other_inst';
    elseif (!$inScope)                                $screen = 'other_inst';
    else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT up.upload_id, up.item_id, up.original_filename, up.file_path, up.verification_status,
                   ci.item_name, v.username AS verifier,
                   DATE_FORMAT(up.uploaded_at, '%b %e, %l:%i %p') AS up_on, DATE_FORMAT(up.verified_at, '%b %e, %l:%i %p') AS ver_on
            FROM uploads up LEFT JOIN checklist_items ci ON ci.item_id = up.item_id LEFT JOIN users v ON v.user_id = up.verified_by
            WHERE up.upload_id IN ($in) AND up.user_id = ? ORDER BY up.item_id");
        $st->execute(array_merge($ids, [$g]));
        $files = $st->fetchAll(PDO::FETCH_ASSOC);
        $c = $pdo->prepare("SELECT formatted_control_no FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
        $c->execute([$g]);
        $ctl = (string)$c->fetchColumn();

        $pending = sd_pending($pdo, $g);
        $current = $pending && $pending['ids'] === $ids;
        $states  = array_column($files, 'verification_status');
        if (count($files) !== count($ids))                                 $screen = 'invalid';
        elseif ($current)                                                   $screen = $role === 'Statistician' ? 'confirm' : 'readonly';
        elseif ($states && count(array_filter($states, fn($s) => $s === 'Approved')) === count($states)) $screen = 'done';
        elseif (in_array('Revision Requested', $states, true))              $screen = 'returned';
        else                                                                $screen = 'replaced';
    }
}
$stage  = $pending['stage'] ?? (($files && (int)$files[0]['item_id'] === 30) ? 'coded' : 'deliverables');
$h      = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tag    = $grp ? aa_school_tag((string)$grp['department']) : '';
$gname  = $grp ? ($grp['research_group_name'] ?: 'Research group') : '';
$csrf   = $h($_SESSION['csrf_token']);
$back   = rd_home_for($role) ?: 'student.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistics desk | iSubmit</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body.admin-module { padding: 0; }
        .sd { --disp: "Outfit", var(--font-ui); max-width: 560px; margin: 0 auto; padding: 18px 16px 40px; }
        .sd-brand { display: flex; align-items: center; gap: 9px; text-decoration: none; margin-bottom: 16px; }
        .sd-brand img { width: 34px; height: 34px; }
        .sd-brand b { display: block; font-size: 15px; font-weight: 800; color: var(--ink); }
        .sd-brand small { display: block; font-size: 11.5px; color: var(--muted); font-weight: 600; }
        .sd-eyebrow { font-size: 12px; font-weight: 750; color: var(--muted); letter-spacing: .02em; }
        .sd-title { font-family: var(--disp); font-size: 28px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 14px; line-height: 1.15; }
        .sd-card { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 18px; }
        .sd-grp { display: flex; align-items: center; gap: 12px; }
        .sd-av { width: 46px; height: 46px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center; font-family: var(--disp); font-size: 20px; font-weight: 700;
                 background: color-mix(in srgb, var(--accent) 16%, var(--surface)); color: var(--ink); }
        .sd-grp b { display: block; font-size: 16px; font-weight: 800; color: var(--ink); line-height: 1.3; }
        .sd-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
        .sd-chip { font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: var(--surface-3); color: var(--ink); }
        .sd-chip.mono { font-family: 'JetBrains Mono', monospace; font-size: 11px; }
        .sd-files { margin: 14px 0 0; padding: 0; list-style: none; border-top: 1px solid var(--line); }
        .sd-files li { display: flex; align-items: center; gap: 10px; padding: 11px 0; border-bottom: 1px solid var(--line); }
        .sd-fi { width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--warning) 16%, var(--surface)); color: color-mix(in srgb, var(--warning) 50%, var(--ink)); }
        .sd-fi.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: var(--success); }
        .sd-fi i, .sd-fi svg { width: 17px; height: 17px; }
        .sd-fn { flex: 1; min-width: 0; }
        .sd-fn b { display: block; font-size: 13.5px; font-weight: 750; color: var(--ink); }
        .sd-fn small { display: block; font-size: 11.5px; color: var(--muted); overflow-wrap: anywhere; }
        .sd-view { flex-shrink: 0; font-size: 12.5px; font-weight: 750; color: var(--accent); text-decoration: none; padding: 6px 10px; border-radius: 999px; background: var(--surface-3); }
        .sd-go { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; margin-top: 16px; padding: 15px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                 display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 15px; font-weight: 750; }
        .sd-go i, .sd-go svg { width: 18px; height: 18px; }
        .sd-note { font-size: 12.5px; color: var(--muted); line-height: 1.5; margin: 12px 2px 0; }
        .sd-msg { text-align: center; padding: 28px 18px; }
        .sd-msg .ico { width: 58px; height: 58px; border-radius: 18px; margin: 0 auto 12px; display: grid; place-items: center; background: var(--surface-3); color: var(--ink); }
        .sd-msg .ico.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: var(--success); }
        .sd-msg .ico.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: var(--danger); }
        .sd-msg .ico.warn { background: color-mix(in srgb, var(--warning) 18%, var(--surface)); color: color-mix(in srgb, var(--warning) 55%, var(--ink)); }
        .sd-msg .ico i, .sd-msg .ico svg { width: 28px; height: 28px; }
        .sd-msg h2 { font-family: var(--disp); font-size: 20px; font-weight: 700; color: var(--ink); margin: 0 0 6px; }
        .sd-msg p { font-size: 13.5px; color: var(--muted); line-height: 1.55; margin: 0; }
        .sd-foot { text-align: center; margin-top: 18px; }
        .sd-foot a { font-size: 13px; font-weight: 700; color: var(--accent); text-decoration: none; }
    </style>
</head>
<body class="admin-module">
<div class="sd">
    <a class="sd-brand" href="<?= $h($back) ?>"><img src="../assets/images/mascot.png" alt=""><span><b>iSubmit</b><small>Statistics desk</small></span></a>

    <?php if (in_array($screen, ['confirm', 'readonly'], true)): ?>
        <div class="sd-eyebrow"><?= $stage === 'coded' ? 'Step 1 · Requirements check' : 'Revised requirements check' ?></div>
        <h1 class="sd-title"><?= $stage === 'coded' ? 'Approve the requirements?' : 'Approve the revised requirements?' ?></h1>
        <div class="sd-card">
            <div class="sd-grp">
                <span class="sd-av"><?= $h(mb_strtoupper(mb_substr($gname, 0, 1))) ?></span>
                <div><b><?= $h($gname) ?></b>
                    <div class="sd-chips"><span class="sd-chip"><?= $h($tag) ?> &middot; <?= $h($grp['program']) ?></span>
                        <?php if ($ctl): ?><span class="sd-chip mono"><?= $h($ctl) ?></span><?php endif; ?></div>
                </div>
            </div>
            <ul class="sd-files">
                <?php foreach ($files as $f): ?>
                    <li><span class="sd-fi"><i data-lucide="<?= (int)$f['item_id'] === 30 || (int)$f['item_id'] === 33 ? 'sheet' : 'file-text' ?>"></i></span>
                        <span class="sd-fn"><b><?= $h($f['item_name'] ?: 'Requirement') ?></b><small><?= $h($f['original_filename']) ?> &middot; <?= $h($f['up_on']) ?></small></span>
                        <a class="sd-view" href="<?= $h($f['file_path']) ?>" target="_blank" rel="noopener">View</a></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($screen === 'confirm'): ?>
                <?php // The existing bulk approval: scope-checked, phase moves, student notified. Nothing new writes. ?>
                <form method="POST" action="admin_module_dynamic.php?phase=stats&amp;view=checklist">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action_type" value="bulk_verify">
                    <input type="hidden" name="return_group" value="<?= $g ?>">
                    <input type="hidden" name="remarks" value="Checked in person at the statistician's office.">
                    <?php foreach ($ids as $id): ?><input type="hidden" name="upload_ids[]" value="<?= (int)$id ?>"><?php endforeach; ?>
                    <button type="submit" class="sd-go"><i data-lucide="check"></i>
                        <?= 'Approve ' . (count($ids) === 1 ? 'this requirement' : 'these ' . count($ids) . ' requirements') ?></button>
                </form>
                <p class="sd-note"><?= $stage === 'coded'
                    ? 'When all five are approved, the group goes to the Secretary for their control number and Form 11.'
                    : 'When all five are approved, the request goes back to statistical treatment.' ?>
                    Something needs fixing? Don't approve. Tell the group what to change; they upload it again and bring a new code.</p>
            <?php else: ?>
                <p class="sd-note"><b>Only the statistician confirms this.</b> You are seeing it read-only.</p>
            <?php endif; ?>
        </div>

    <?php else:
        [$ico, $tone, $title, $body] = [
            'invalid'    => ['qr-code', 'bad', 'This code isn\'t valid', 'It may have been cut off or copied wrong. Ask the group to open their Statistics card and show the code again.'],
            'student'    => ['user-round', '', 'This code is for the statistician', 'Show it at the statistician\'s office. She scans it after going through your files with you.'],
            'other_inst' => ['building-2', 'warn', 'This group is from ' . $tag, 'Only the ' . $tag . ' statistician or the admin statistician can confirm it.'],
            'done'       => ['circle-check', 'ok', 'Already approved', $gname . ': approved' . (!empty($files[0]['verifier']) ? ' by ' . $files[0]['verifier'] : '') . (!empty($files[0]['ver_on']) ? ' on ' . $files[0]['ver_on'] : '') . '. Nothing else to do here.'],
            'returned'   => ['rotate-ccw', 'warn', 'Sent back for revision', $gname . ' was asked to fix something. Once they upload it again, their card shows a new code.'],
            'replaced'   => ['refresh-cw', 'warn', 'These files were replaced', $gname . ' uploaded newer files since this code was made. Ask them to show the code on their card now.'],
        ][$screen]; ?>
        <div class="sd-card sd-msg">
            <div class="ico <?= $tone ?>"><i data-lucide="<?= $ico ?>"></i></div>
            <h2><?= $h($title) ?></h2>
            <p><?= $h($body) ?></p>
        </div>
    <?php endif; ?>

    <div class="sd-foot"><a href="<?= $h($back) ?>">Go to my dashboard</a></div>
</div>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
