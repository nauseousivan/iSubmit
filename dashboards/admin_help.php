<?php
// dashboards/admin_help.php — the Help Desk inbox (2026-10-01). ADMIN ONLY.
//
// Requests from auth/help.php: people who cannot fix it themselves (lost inbox, wrong email or
// School ID, group change) and suggestions for the system. Built like the other account screens:
// a long list of ROWS, pills + search, the action behind a toggle. For each request the likely
// ACCOUNT is found by email, School ID and name, with which of those matched — so the Admin can
// check who is asking before resetting anything. Writes go to admin_help_handler.php.

require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$cats = rd_help_categories();
$rows = $pdo->query("
    SELECT h.*, r.username AS resolver,
           TIMESTAMPDIFF(MINUTE, h.created_at, NOW()) AS age_min,
           DATE_FORMAT(h.created_at, '%b %e, %l:%i %p') AS sent_on, DATE_FORMAT(h.resolved_at, '%b %e') AS answered_on
    FROM help_requests h LEFT JOIN users r ON r.user_id = h.resolved_by
    ORDER BY h.status = 'open' DESC, h.created_at DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

/** Accounts this request is probably about, strongest match first, with what matched. */
function hd_matches(PDO $pdo, array $r): array
{
    $st = $pdo->prepare("
        SELECT user_id, username, email, student_number, role, account_status, department, program
        FROM users
        WHERE user_id = ? OR email = ? OR (? <> '' AND student_number = ?) OR LOWER(username) = LOWER(?)
        LIMIT 5");
    $sn = (string)($r['student_number'] ?? '');
    $st->execute([(int)$r['user_id'], $r['email'], $sn, $sn, $r['name']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $why = [];
        if ((int)$r['user_id'] === (int)$u['user_id']) $why[] = 'sent while signed in';
        if (strcasecmp($u['email'], $r['email']) === 0) $why[] = 'same email';
        if ($sn !== '' && $u['student_number'] === $sn) $why[] = 'same School ID';
        if (strcasecmp(trim($u['username']), trim($r['name'])) === 0) $why[] = 'same name';
        $u['why'] = $why;
        $out[] = $u;
    }
    usort($out, fn($a, $b) => count($b['why']) <=> count($a['why']));
    return $out;
}
function hd_ago(int $m): string
{
    if ($m < 1) return 'just now';
    if ($m < 60) return $m . 'm ago';
    if ($m < 1440) return floor($m / 60) . 'h ago';
    return floor($m / 1440) . 'd ago';
}

$n = ['open' => 0, 'resolved' => 0];
foreach ($rows as $r) $n[$r['status']]++;
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$flash = $_SESSION['message'] ?? ''; $flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Desk</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .aa { --disp: "Outfit", var(--font-ui); padding: 22px 26px 40px; max-width: 1100px; margin: 0 auto; }
        .aa-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .aa-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .aa-sub { font-size: 13px; color: var(--muted); margin: 0 0 16px; max-width: 720px; line-height: 1.5; }
        .aa-flash { padding: 12px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600; display: flex; gap: 8px; align-items: flex-start; line-height: 1.5;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .aa-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-flash i, .aa-flash svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; }
        .aa-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .aa-pills { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface-3); }
        .aa-pill { all: unset; cursor: pointer; padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--ink); display: inline-flex; gap: 6px; align-items: center; }
        .aa-pill span { font-size: 10.5px; font-weight: 800; color: var(--muted); }
        .aa-pill.on { background: var(--surface); box-shadow: var(--shadow-1); }
        .aa-pill.on span { background: var(--ink); color: var(--surface); border-radius: 999px; padding: 1px 6px; }
        .aa-search { position: relative; margin-left: auto; flex: 0 1 300px; min-width: 200px; }
        .aa-search i, .aa-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); pointer-events: none; }
        .aa-search input { width: 100%; box-sizing: border-box; padding: 10px 14px 10px 36px; border: 1.5px solid transparent; border-radius: 999px; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .aa-box { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .hd-row { padding: 16px 18px; border-top: 1px solid var(--line); }
        .hd-row.first-shown { border-top: 0; }
        .hd-row.done .hd-top, .hd-row.done .hd-msg { opacity: .7; }
        .hd-top { display: flex; align-items: flex-start; gap: 12px; }
        .hd-ico { width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .hd-ico i, .hd-ico svg { width: 18px; height: 18px; }
        .hd-row.done .hd-ico { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: var(--success); }
        .hd-who { flex: 1; min-width: 0; }
        .hd-cat { font-size: 14px; font-weight: 750; color: var(--ink); }
        .hd-meta { font-size: 12px; color: var(--muted); margin-top: 2px; word-break: break-word; }
        .hd-when { font-size: 12px; font-weight: 700; color: var(--muted); flex-shrink: 0; text-align: right; }
        .hd-when b { display: block; font-size: 11px; font-weight: 800; border-radius: 999px; padding: 2px 9px; margin-top: 4px;
                     background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); }
        .hd-row.done .hd-when b { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 60%, var(--ink)); }
        .hd-msg { margin: 10px 0 0 50px; font-size: 13.5px; line-height: 1.55; color: var(--ink); white-space: pre-line; }
        .hd-reply { margin: 10px 0 0 50px; padding: 10px 12px; border-radius: 12px; background: var(--surface-3); font-size: 13px; color: var(--ink); white-space: pre-line; }
        .hd-reply small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 4px; }
        .hd-acct { margin: 12px 0 0 50px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; color: var(--muted); }
        .hd-chip { display: inline-flex; gap: 5px; align-items: center; font-size: 11.5px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: var(--surface-3); color: var(--ink); }
        .hd-chip.strong { background: color-mix(in srgb, var(--success) 14%, var(--surface)); }
        .hd-chip.none { background: color-mix(in srgb, var(--warning) 18%, var(--surface)); }
        .hd-open { all: unset; cursor: pointer; margin: 12px 0 0 50px; font-size: 12.5px; font-weight: 750; color: var(--accent); display: inline-flex; align-items: center; gap: 5px; }
        .hd-open i, .hd-open svg { width: 14px; height: 14px; }
        .hd-form { margin: 12px 0 0 50px; padding: 14px; border-radius: 16px; background: var(--surface-2); border: 1px solid var(--line); }
        .hd-form textarea, .hd-form select { width: 100%; box-sizing: border-box; padding: 11px 13px; border-radius: 12px; border: 1.5px solid transparent; background: var(--surface); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .hd-form textarea { min-height: 90px; resize: vertical; line-height: 1.5; }
        .hd-form textarea:focus, .hd-form select:focus { border-color: var(--accent); }
        .hd-lab { display: block; font-size: 12px; font-weight: 750; color: var(--ink); margin: 0 0 6px; }
        .hd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }
        .hd-check { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; color: var(--ink); line-height: 1.4; margin-top: 8px; cursor: pointer; }
        .hd-check input { margin-top: 2px; accent-color: var(--accent); }
        .hd-check small { display: block; color: var(--muted); }
        .hd-actions { display: flex; gap: 10px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
        .hd-btn { all: unset; cursor: pointer; padding: 9px 16px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 12.5px; font-weight: 750; }
        .hd-link { all: unset; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--accent); }
        .aa-none { padding: 30px; text-align: center; color: var(--muted); font-size: 13px; }
        @media (max-width: 640px) { .aa { padding: 16px 12px 30px; } .hd-msg, .hd-reply, .hd-acct, .hd-open, .hd-form { margin-left: 0; } .hd-grid { grid-template-columns: 1fr; } .aa-search { margin-left: 0; flex-basis: 100%; } }
    </style>
</head>
<body class="admin-module">
<div class="aa">
    <div class="aa-eyebrow">Accounts</div>
    <h1 class="aa-title">Help Desk</h1>
    <p class="aa-sub">Requests from people who can't fix it themselves, and suggestions for the system. Check who is asking against their account before you reset anything.</p>

    <?php if ($flash): ?>
        <div class="aa-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><span><?= $h($flash) ?></span></div>
    <?php endif; ?>

    <div class="aa-toolbar">
        <div class="aa-pills">
            <button type="button" class="aa-pill on" data-f="open" onclick="hdPick(this)">Open <span><?= $n['open'] ?></span></button>
            <button type="button" class="aa-pill" data-f="resolved" onclick="hdPick(this)">Answered <span><?= $n['resolved'] ?></span></button>
            <button type="button" class="aa-pill" data-f="" onclick="hdPick(this)">All <span><?= count($rows) ?></span></button>
        </div>
        <label class="aa-search"><i data-lucide="search"></i><input type="search" id="hdFind" placeholder="Name, email, School ID or words" oninput="hdFilter()"></label>
    </div>

    <div class="aa-box" id="hdList">
    <?php foreach ($rows as $r):
        $done = $r['status'] === 'resolved';
        $m = hd_matches($pdo, $r);
        $icon = ['email_access' => 'mail-x', 'password' => 'key-round', 'fix_details' => 'user-pen', 'group_change' => 'users',
                 'suggestion' => 'lightbulb', 'other' => 'message-circle-question'][$r['category']] ?? 'message-circle';
        $hay = strtolower($r['name'] . ' ' . $r['email'] . ' ' . $r['student_number'] . ' ' . $r['message'] . ' ' . ($cats[$r['category']] ?? ''));
    ?>
        <div class="hd-row <?= $done ? 'done' : '' ?>" data-f="<?= $r['status'] ?>" data-hay="<?= $h($hay) ?>">
            <div class="hd-top">
                <span class="hd-ico"><i data-lucide="<?= $icon ?>"></i></span>
                <div class="hd-who">
                    <div class="hd-cat"><?= $h($cats[$r['category']] ?? $r['category']) ?></div>
                    <div class="hd-meta"><?= $h($r['name']) ?> &middot; <?= $h($r['email']) ?><?= $r['student_number'] ? ' &middot; ' . $h($r['student_number']) : '' ?></div>
                </div>
                <div class="hd-when"><?= hd_ago((int)$r['age_min']) ?><b><?= $done ? 'Answered' : 'Open' ?></b></div>
            </div>
            <div class="hd-msg"><?= $h($r['message']) ?></div>

            <?php if ($r['category'] !== 'suggestion'): ?>
            <div class="hd-acct">
                <?php if (!$m): ?>
                    <span class="hd-chip none"><i data-lucide="circle-help" style="width:13px;height:13px"></i>No matching account</span>
                <?php else: foreach ($m as $u): ?>
                    <span class="hd-chip <?= count($u['why']) >= 2 ? 'strong' : '' ?>" title="<?= $h(implode(', ', $u['why'])) ?>">
                        <?= $h($u['username']) ?> &middot; <?= $h($u['role']) ?><?= $u['account_status'] !== 'active' ? ' &middot; ' . $h($u['account_status']) : '' ?>
                    </span>
                    <span><?= $h(implode(', ', $u['why'])) ?></span>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($done): ?>
                <div class="hd-reply"><?= $h($r['admin_reply']) ?><small>Answered <?= $h($r['answered_on']) ?><?= $r['resolver'] ? ' by ' . $h($r['resolver']) : '' ?></small></div>
                <form method="POST" action="admin_help_handler.php" style="margin:8px 0 0 50px">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                    <button type="submit" class="hd-link">Reopen</button>
                </form>
            <?php else: ?>
                <button type="button" class="hd-open" onclick="hdToggle(this)"><i data-lucide="reply"></i> Reply</button>
                <form class="hd-form" method="POST" action="admin_help_handler.php" hidden>
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="answer"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                    <label class="hd-lab">Your reply <span style="font-weight:600;color:var(--muted)">&mdash; emailed to <?= $h($r['email']) ?></span></label>
                    <textarea name="reply" required maxlength="3000" placeholder="e.g. I have updated your account email. You can sign in now."></textarea>
                    <?php if ($r['category'] !== 'suggestion'): ?>
                        <div class="hd-grid">
                            <div>
                                <label class="hd-lab">Their account</label>
                                <select name="link_user_id">
                                    <option value="">Not linked to an account</option>
                                    <?php foreach ($m as $i => $u): ?>
                                        <option value="<?= (int)$u['user_id'] ?>" <?= $i === 0 && count($u['why']) >= 1 ? 'selected' : '' ?>><?= $h($u['username'] . ' — ' . $u['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="hd-check"><input type="checkbox" name="reset_password" value="1" onchange="this.closest('.hd-form').querySelector('.hd-inc').hidden = !this.checked">
                                    <span>Reset their password<small>They must choose a new one when they sign in.</small></span></label>
                                <label class="hd-check hd-inc" hidden><input type="checkbox" name="include_password" value="1">
                                    <span>Email the temporary password<small>Only if you are sure this email is theirs.</small></span></label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="hd-actions">
                        <button type="submit" class="hd-btn">Send reply and mark answered</button>
                        <?php if ($m): ?><a class="hd-link" href="admin_students.php?q=<?= urlencode($r['student_number'] ?: $m[0]['email']) ?>">Fix their details in Student accounts</a><?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="aa-box aa-none" id="hdNone" hidden>Nothing here. New requests from the login page and the portal will show up here.</div>
</div>
<script>
    var hdF = 'open';
    function hdPick(b) { document.querySelectorAll('.aa-pill').forEach(function (p) { p.classList.toggle('on', p === b); }); hdF = b.dataset.f; hdFilter(); }
    function hdFilter() {
        var q = document.getElementById('hdFind').value.trim().toLowerCase(), n = 0, first = true;
        document.querySelectorAll('.hd-row').forEach(function (r) {
            var ok = (!hdF || r.dataset.f === hdF) && (!q || r.dataset.hay.indexOf(q) !== -1);
            r.hidden = !ok; if (ok) n++;
            r.classList.toggle('first-shown', ok && first); if (ok) first = false;
        });
        document.getElementById('hdList').hidden = n === 0;
        document.getElementById('hdNone').hidden = n !== 0;
    }
    function hdToggle(b) { var f = b.nextElementSibling; f.hidden = !f.hidden; if (!f.hidden) f.querySelector('textarea').focus(); }
    hdFilter();
    if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
