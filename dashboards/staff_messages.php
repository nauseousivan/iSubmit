<?php
// dashboards/staff_messages.php — staff Messages (2026-09-29, from the Claude Design concept).
//
// Replaces message.php for staff; students keep message.php. One screen, three columns:
// conversations · the thread · who the group is and where they are. Opening a conversation
// never reloads the page, and the list is ALWAYS on screen (on a phone: list, or thread with a
// Back button). The old screen hid the list entirely when a role had exactly one conversation
// ("single-contact mode") and on narrow windows after opening one — with no way back, which is
// the "I can never get back to the other message" bug.
//
// Reads: this file (?ajax=list / ?ajax=info). Thread + send: staff_message_handler.php, which
// refuses a group outside the viewer's institution (aa_may_touch_student) — the list here hides
// the same groups (aa_scope_sql). Conversations belong to a ROLE (staff_conversations.staff_role),
// so both Research Director accounts share one inbox, as they always have.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin_accounts_lib.php';
require_once __DIR__ . '/group_roster_lib.php';
require_once __DIR__ . '/stats_pipeline_lib.php';

$staff_roles = ['Research Coordinator', 'Research Director', 'Statistician'];
$me   = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
if (!$me || !in_array($role, $staff_roles, true)) {
    header('Location: ../auth/login.php');
    exit;
}
[$sm_sql, $sm_params] = aa_scope_sql($pdo, $role, $me, 'u');

/** Every conversation this role holds, newest first, with preview + unread for THIS user. */
function sm_list(PDO $pdo, string $role, int $me, string $scopeSql, array $scopeParams): array
{
    $st = $pdo->prepare("
        SELECT sc.conversation_id, sc.group_leader_id AS gid, u.research_group_name AS name, u.username AS leader,
               u.program, u.department, u.profile_pic,
               lm.message_text AS last_text, lm.sender_id AS last_sender, UNIX_TIMESTAMP(lm.created_at) AS last_ts,
               (SELECT COUNT(*) FROM staff_messages m2
                  LEFT JOIN staff_message_reads r ON r.conversation_id = m2.conversation_id AND r.user_id = ?
                 WHERE m2.conversation_id = sc.conversation_id AND m2.sender_id <> ?
                   AND (r.last_read_at IS NULL OR m2.created_at > r.last_read_at)) AS unread
        FROM staff_conversations sc
        JOIN users u ON u.user_id = sc.group_leader_id
        LEFT JOIN staff_messages lm ON lm.message_id = (SELECT MAX(m.message_id) FROM staff_messages m WHERE m.conversation_id = sc.conversation_id)
        WHERE sc.staff_role = ? AND $scopeSql
        ORDER BY COALESCE(lm.created_at, sc.updated_at) DESC");
    $st->execute(array_merge([$me, $me, $role], $scopeParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['gid'] = (int)$r['gid'];
        $r['unread'] = (int)$r['unread'];
        $r['last_ts'] = $r['last_ts'] !== null ? (int)$r['last_ts'] : null;
        $r['mine'] = (int)$r['last_sender'] === $me;
        $r['name'] = $r['name'] ?: ('Group ' . $r['gid']);
        $r['school'] = $r['department'] ? aa_school_tag($r['department']) : '';
        unset($r['last_sender'], $r['department']);
    }
    return $rows;
}

// ---- JSON: the list (polled) -------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'list') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'rows' => sm_list($pdo, $role, $me, $sm_sql, $sm_params)]);
    exit;
}

// ---- JSON: the right-hand panel for one group ----------------------------------------------
if (($_GET['ajax'] ?? '') === 'info') {
    header('Content-Type: application/json');
    $gid = (int)($_GET['g'] ?? 0);
    if (!$gid || !aa_may_touch_student($pdo, $role, $me, $gid)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $g = $pdo->prepare("SELECT user_id, username, research_group_name, program, department, research_title FROM users WHERE user_id = ? AND role = 'Student'");
    $g->execute([$gid]);
    $group = $g->fetch(PDO::FETCH_ASSOC);
    if (!$group) { echo json_encode(['ok' => false]); exit; }

    $people = [];
    foreach ((ws_group_rosters($pdo, [$gid])[$gid] ?? []) as $p) {
        $people[] = ['name' => $p['username'], 'leader' => $p['leader_id'] === null, 'pic' => $p['profile_pic'] ?: ''];
    }

    // WHERE THEY ARE: requirements cleared per phase (latest upload per item, as the review
    // screens count it), plus the statistics ladder and its control number when there is one.
    $phases = ['Proposal' => [11, 12, 13, 14, 15, 16, 17], 'Final' => [20, 22, 23, 24, 25, 26, 27]];
    $all = array_merge(...array_values($phases));
    $in  = implode(',', $all);
    $lat = $pdo->prepare("SELECT up.item_id, up.verification_status FROM uploads up
                          JOIN (SELECT item_id, MAX(uploaded_at) md FROM uploads WHERE user_id = ? AND item_id IN ($in) GROUP BY item_id) l
                            ON l.item_id = up.item_id AND l.md = up.uploaded_at
                          WHERE up.user_id = ?");
    $lat->execute([$gid, $gid]);
    $st_by = [];
    foreach ($lat->fetchAll(PDO::FETCH_ASSOC) as $r) $st_by[(int)$r['item_id']] = $r['verification_status'];
    $where = [];
    foreach ($phases as $label => $items) {
        $ok = 0;
        foreach ($items as $i) if (($st_by[$i] ?? '') === 'Approved') $ok++;
        $where[] = ['label' => $label, 'done' => $ok, 'total' => count($items)];
    }
    $fs = $pdo->prepare("SELECT status, formatted_control_no FROM form_stat_treatment WHERE user_id = ? ORDER BY form_id DESC LIMIT 1");
    $fs->execute([$gid]);
    $stats = null;
    if ($f = $fs->fetch(PDO::FETCH_ASSOC)) {
        $pos = sp_position((string)$f['status']);
        $stage = sp_stages()[$pos['at']]['tip'] ?? '';
        $stats = ['text' => $pos['owner'] === 'done' ? 'Statistics results released' : 'Statistics: ' . lcfirst($stage),
                  'done' => $pos['owner'] === 'done', 'back' => $pos['flag'] !== '', 'control' => (string)($f['formatted_control_no'] ?? '')];
    }
    echo json_encode(['ok' => true, 'group' => [
        'name' => $group['research_group_name'] ?: ('Group ' . $gid), 'program' => $group['program'],
        'school' => $group['department'] ? aa_school_tag($group['department']) : '', 'title' => $group['research_title'],
    ], 'people' => $people, 'where' => $where, 'stats' => $stats]);
    exit;
}

$sm_rows = sm_list($pdo, $role, $me, $sm_sql, $sm_params);
$sm_open = (int)($_GET['g'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; height: 100vh; overflow: hidden; background: var(--canvas); }
        .sm { --disp: "Outfit", var(--font-ui); display: grid; grid-template-columns: 320px minmax(0, 1fr) 290px; gap: 14px; height: 100vh; padding: 18px; box-sizing: border-box; }
        .sm-col { min-height: 0; display: flex; flex-direction: column; }

        /* list */
        .sm-h1 { font-family: var(--disp); font-size: 30px; font-weight: 700; color: var(--ink); letter-spacing: -.02em; margin: 2px 4px 12px; }
        .sm-search { position: relative; margin-bottom: 10px; }
        .sm-search i, .sm-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); }
        .sm-search input { width: 100%; box-sizing: border-box; padding: 11px 14px 11px 38px; border-radius: 999px; border: 1.5px solid transparent; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .sm-search input:focus { border-color: var(--accent); background: var(--surface); }
        .sm-pills { display: flex; gap: 6px; margin-bottom: 10px; }
        .sm-pill { all: unset; cursor: pointer; padding: 6px 13px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); font-size: 12px; font-weight: 700; color: var(--ink); }
        .sm-pill.on { background: var(--ink); color: var(--surface); border-color: var(--ink); }
        .sm-list { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; padding: 2px; }
        .sm-item { all: unset; box-sizing: border-box; cursor: pointer; display: flex; gap: 11px; align-items: center; padding: 11px 12px; border-radius: 18px; }
        .sm-item:hover { background: var(--surface-2); }
        .sm-item.on { background: var(--surface); box-shadow: var(--shadow-2); }
        .sm-tile { width: 42px; height: 42px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center; font-family: var(--disp); font-weight: 700; font-size: 16px; position: relative; }
        .sm-tile.big { width: 64px; height: 64px; border-radius: 20px; font-size: 26px; margin: 0 auto 10px; }
        .sm-it-body { flex: 1; min-width: 0; }
        .sm-it-top { display: flex; justify-content: space-between; gap: 8px; }
        .sm-it-name { font-size: 13.5px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sm-it-when { font-size: 11px; color: var(--muted); flex-shrink: 0; }
        .sm-it-bot { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-top: 2px; }
        .sm-it-prev { font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sm-item.unread .sm-it-prev { color: var(--ink); font-weight: 700; }
        .sm-badge { min-width: 18px; height: 18px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 10.5px; font-weight: 800; display: grid; place-items: center; padding: 0 5px; box-sizing: border-box; }
        .sm-foot { margin-top: 10px; padding: 12px 14px; border-radius: 16px; background: var(--surface-3); font-size: 12px; color: var(--muted); display: flex; gap: 8px; line-height: 1.45; }
        .sm-foot i, .sm-foot svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
        .sm-none { padding: 26px 12px; text-align: center; font-size: 13px; color: var(--muted); }

        /* thread */
        .sm-thread { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; overflow: hidden; }
        .sm-th-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--line); }
        .sm-th-head .sm-tile { width: 38px; height: 38px; border-radius: 12px; font-size: 15px; }
        .sm-th-name { font-size: 14.5px; font-weight: 750; color: var(--ink); }
        .sm-th-sub { font-size: 12px; color: var(--muted); }
        .sm-back { all: unset; cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: none; place-items: center; background: var(--surface-3); color: var(--ink); }
        .sm-back i, .sm-back svg { width: 18px; height: 18px; }
        .sm-info-btn { all: unset; cursor: pointer; margin-left: auto; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-3); color: var(--ink); }
        .sm-info-btn i, .sm-info-btn svg { width: 17px; height: 17px; }
        .sm-stream { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 4px; background: var(--surface-2); }
        .sm-day { text-align: center; font-size: 11px; font-weight: 700; color: var(--muted); margin: 12px 0 8px; display: flex; align-items: center; gap: 12px; }
        .sm-day::before, .sm-day::after { content: ""; flex: 1; height: 1px; background: var(--line); }
        .sm-msg { display: flex; flex-direction: column; align-items: flex-start; max-width: 72%; }
        .sm-msg.me { align-self: flex-end; align-items: flex-end; }
        .sm-who { font-size: 11px; color: var(--muted); margin: 6px 6px 3px; }
        .sm-bub { padding: 10px 14px; border-radius: 18px 18px 18px 6px; background: var(--surface); color: var(--ink); font-size: 13.5px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; box-shadow: var(--shadow-1); }
        body.theme-dark .sm-bub { background: var(--surface-3); box-shadow: none; }
        .sm-msg.me .sm-bub { background: var(--accent); color: var(--accent-ink); border-radius: 18px 18px 6px 18px; }
        .sm-at { font-size: 10.5px; color: var(--muted); margin: 3px 6px 6px; }
        .sm-empty-th { margin: auto; text-align: center; color: var(--muted); font-size: 13px; }
        .sm-empty-th i, .sm-empty-th svg { width: 34px; height: 34px; color: var(--accent); margin-bottom: 8px; }
        .sm-quick { display: flex; gap: 6px; padding: 10px 16px 0; flex-wrap: wrap; border-top: 1px solid var(--line); }
        .sm-q { all: unset; cursor: pointer; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); font-size: 12px; font-weight: 600; color: var(--ink); }
        .sm-q:hover { border-color: var(--accent); }
        .sm-compose { display: flex; gap: 10px; padding: 10px 16px 16px; align-items: flex-end; }
        .sm-compose textarea { flex: 1; resize: none; max-height: 140px; min-height: 44px; box-sizing: border-box; padding: 12px 16px; border-radius: 22px; border: 1.5px solid transparent;
                               background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13.5px; outline: none; }
        .sm-compose textarea:focus { border-color: var(--accent); background: var(--surface); }
        .sm-send { all: unset; cursor: pointer; width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; flex-shrink: 0; }
        .sm-send:disabled { background: var(--surface-3); color: var(--muted); cursor: default; }
        .sm-send i, .sm-send svg { width: 18px; height: 18px; }

        /* right panel */
        .sm-side { gap: 12px; overflow-y: auto; }
        .sm-card { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 16px; }
        .sm-card.hero { background: var(--surface-3); border-color: transparent; text-align: center; padding: 22px 16px; }
        .sm-card h4 { margin: 0 0 10px; font-size: 13px; font-weight: 750; color: var(--ink); }
        .sm-g-name { font-family: var(--disp); font-size: 19px; font-weight: 700; color: var(--ink); }
        .sm-g-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .sm-person { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
        .sm-person .av { width: 30px; height: 30px; border-radius: 50%; overflow: hidden; background: var(--surface-3); display: grid; place-items: center; font-size: 11px; font-weight: 800; color: var(--ink); flex-shrink: 0; }
        .sm-person .av img { width: 100%; height: 100%; object-fit: cover; }
        .sm-person b { display: block; font-size: 12.5px; color: var(--ink); }
        .sm-person small { font-size: 11px; color: var(--muted); }
        .sm-bar { margin: 8px 0; }
        .sm-bar-top { display: flex; justify-content: space-between; font-size: 12px; color: var(--ink); font-weight: 600; margin-bottom: 5px; }
        .sm-bar-top span { color: var(--muted); font-weight: 600; }
        .sm-track { height: 6px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
        .sm-track i { display: block; height: 100%; background: var(--accent); border-radius: 3px; }
        .sm-chip { display: flex; align-items: center; gap: 7px; padding: 8px 12px; border-radius: 12px; font-size: 12px; font-weight: 650; margin-top: 10px;
                   background: var(--surface-3); color: var(--ink); }
        .sm-chip.ok { background: color-mix(in srgb, var(--success) 15%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .sm-chip.back { background: color-mix(in srgb, var(--warning) 18%, var(--surface)); color: color-mix(in srgb, var(--warning) 60%, var(--ink)); }
        .sm-chip i, .sm-chip svg { width: 14px; height: 14px; flex-shrink: 0; }
        .sm-ctl { font-family: "JetBrains Mono", monospace; font-size: 11px; color: var(--muted); margin-top: 8px; }

        @media (max-width: 1180px) {
            .sm { grid-template-columns: 300px minmax(0, 1fr); }
            .sm-side { display: none; }
            .sm.show-info .sm-side { display: flex; position: fixed; right: 18px; top: 18px; bottom: 18px; width: 290px; z-index: 20;
                                     background: var(--canvas); border-radius: 26px; padding: 12px; box-shadow: var(--shadow-3); }
        }
        @media (min-width: 1181px) { .sm-info-btn { display: none; } }
        /* Phone / narrow window: list OR thread, never neither — and the thread always has Back. */
        @media (max-width: 760px) {
            .sm { grid-template-columns: minmax(0, 1fr); padding: 12px; }
            .sm.on-thread .sm-listcol { display: none; }
            .sm:not(.on-thread) .sm-thread { display: none; }
            .sm-back { display: grid; }
            .sm-msg { max-width: 86%; }
            .sm-quick { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
            .sm-q { flex-shrink: 0; }
        }
    </style>
</head>
<body class="admin-module">
<div class="sm" id="sm">
    <!-- conversations -->
    <section class="sm-col sm-listcol">
        <h1 class="sm-h1">Messages</h1>
        <label class="sm-search"><i data-lucide="search"></i><input type="search" id="smFind" placeholder="Search conversations" autocomplete="off" oninput="smPaintList()"></label>
        <div class="sm-pills">
            <button type="button" class="sm-pill on" data-f="all" onclick="smFilter(this)">All</button>
            <button type="button" class="sm-pill" data-f="unread" onclick="smFilter(this)">Unread <span id="smUnreadN"></span></button>
        </div>
        <div class="sm-list" id="smList"></div>
        <div class="sm-foot"><i data-lucide="info"></i><span>Only a group's leader can message the office. Each group has one thread with your role, shared by everyone who holds it.</span></div>
    </section>

    <!-- thread -->
    <section class="sm-col sm-thread" id="smThread">
        <div class="sm-th-head" id="smHead" hidden>
            <button type="button" class="sm-back" onclick="smCloseThread()" aria-label="Back to conversations"><i data-lucide="chevron-left"></i></button>
            <span class="sm-tile" id="smHeadTile"></span>
            <div><div class="sm-th-name" id="smHeadName"></div><div class="sm-th-sub" id="smHeadSub"></div></div>
            <button type="button" class="sm-info-btn" onclick="document.getElementById('sm').classList.toggle('show-info')" aria-label="About this group"><i data-lucide="info"></i></button>
        </div>
        <div class="sm-stream" id="smStream">
            <div class="sm-empty-th"><i data-lucide="messages-square"></i><div><?= $sm_rows ? 'Pick a conversation on the left.' : 'No group has messaged your role yet.' ?></div></div>
        </div>
        <div id="smComposeWrap" hidden>
            <div class="sm-quick">
                <button type="button" class="sm-q" onclick="smQuick(this)">Hello! How can we help?</button>
                <button type="button" class="sm-q" onclick="smQuick(this)">Received, we'll check it today.</button>
                <button type="button" class="sm-q" onclick="smQuick(this)">Please re-upload the file.</button>
            </div>
            <form class="sm-compose" onsubmit="smSend(event)">
                <textarea id="smText" rows="1" placeholder="Write a message" oninput="smGrow(this)" onkeydown="smKey(event)"></textarea>
                <button type="submit" class="sm-send" id="smSendBtn" disabled aria-label="Send"><i data-lucide="send"></i></button>
            </form>
        </div>
    </section>

    <!-- about the group -->
    <aside class="sm-col sm-side" id="smSide"></aside>
</div>

<script>
(function () {
    var ME = <?= $me ?>;
    var rows = <?= json_encode($sm_rows) ?>;
    var open = <?= $sm_open ?: 'null' ?>;
    var filter = 'all', lastSig = '', threadTimer = null;
    var TINTS = ['#f6e3b4', '#cfe8d7', '#dcd3fb', '#fbd5dc', '#cde3f7', '#f7d9c4'];

    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function initial(n) { var m = String(n || '?').replace(/^(the|a)\s+/i, '').trim(); return (m[0] || '?').toUpperCase(); }
    function tint(id) { return TINTS[id % TINTS.length]; }
    function tile(el, r) { el.textContent = initial(r.name); el.style.background = tint(r.gid); el.style.color = '#2a2140'; }
    function when(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000), now = new Date(), diff = (now - d) / 1000;
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        var y = new Date(now); y.setDate(now.getDate() - 1);
        if (d.toDateString() === y.toDateString()) return 'Yesterday';
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    // ---- list ----------------------------------------------------------------------
    window.smFilter = function (b) {
        document.querySelectorAll('.sm-pill').forEach(function (p) { p.classList.toggle('on', p === b); });
        filter = b.dataset.f; smPaintList();
    };
    window.smPaintList = function () {
        var q = (document.getElementById('smFind').value || '').trim().toLowerCase();
        var list = document.getElementById('smList'), html = '', unreadN = 0, shown = 0;
        rows.forEach(function (r) {
            if (r.unread > 0) unreadN++;
            if (filter === 'unread' && r.unread === 0 && r.gid !== open) return;
            if (q && (r.name + ' ' + (r.leader || '') + ' ' + (r.program || '')).toLowerCase().indexOf(q) === -1) return;
            shown++;
            var prev = r.last_text ? (r.mine ? 'You: ' : '') + r.last_text : 'No messages yet';
            html += '<button type="button" class="sm-item' + (r.gid === open ? ' on' : '') + (r.unread > 0 ? ' unread' : '') + '" onclick="smOpen(' + r.gid + ')">' +
                '<span class="sm-tile" style="background:' + tint(r.gid) + ';color:#2a2140">' + esc(initial(r.name)) + '</span>' +
                '<span class="sm-it-body"><span class="sm-it-top"><span class="sm-it-name">' + esc(r.name) + '</span><span class="sm-it-when">' + esc(when(r.last_ts)) + '</span></span>' +
                '<span class="sm-it-bot"><span class="sm-it-prev">' + esc(prev) + '</span>' + (r.unread > 0 ? '<span class="sm-badge">' + r.unread + '</span>' : '') + '</span></span></button>';
        });
        list.innerHTML = html || '<div class="sm-none">' + (rows.length ? 'Nothing matches.' : 'No conversations yet.') + '</div>';
        document.getElementById('smUnreadN').textContent = unreadN ? unreadN : '';
    };
    function refreshList() {
        fetch('staff_messages.php?ajax=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            rows = d.rows;
            // Whatever is open has just been read here — never show it as unread.
            rows.forEach(function (r) { if (r.gid === open) r.unread = 0; });
            smPaintList();
        }).catch(function () {});
    }

    // ---- thread --------------------------------------------------------------------
    function row(gid) { for (var i = 0; i < rows.length; i++) if (rows[i].gid === gid) return rows[i]; return null; }
    window.smOpen = function (gid) {
        open = gid;
        var r = row(gid) || { gid: gid, name: 'Group', leader: '' };
        r.unread = 0;
        lastSig = '';
        document.getElementById('sm').classList.add('on-thread');
        document.getElementById('sm').classList.remove('show-info');
        document.getElementById('smHead').hidden = false;
        document.getElementById('smComposeWrap').hidden = false;
        tile(document.getElementById('smHeadTile'), r);
        document.getElementById('smHeadName').textContent = r.name;
        document.getElementById('smHeadSub').textContent = r.leader ? 'Leader: ' + r.leader : '';
        document.getElementById('smStream').innerHTML = '<div class="sm-empty-th">Loading…</div>';
        try { history.replaceState(null, '', 'staff_messages.php?g=' + gid); } catch (e) {}
        smPaintList();
        loadThread(true);
        loadInfo(gid);
        clearInterval(threadTimer);
        threadTimer = setInterval(function () { loadThread(false); }, 5000);
    };
    window.smCloseThread = function () {
        document.getElementById('sm').classList.remove('on-thread');
    };
    function loadThread(scroll) {
        if (!open) return;
        var gid = open, fd = new FormData();
        fd.append('action', 'get_messages'); fd.append('target_group', gid);
        fetch('staff_message_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (gid !== open) return;                       // switched away meanwhile
            var s = document.getElementById('smStream');
            if (!d || d.status !== 'success') { s.innerHTML = '<div class="sm-empty-th">' + esc((d && d.message) || 'This conversation could not be loaded.') + '</div>'; return; }
            var sig = d.messages.length + ':' + (d.messages.length ? d.messages[d.messages.length - 1].message_id : 0);
            if (sig === lastSig) return;                    // nothing new — leave the scroll alone
            lastSig = sig;
            var nearBottom = s.scrollHeight - s.scrollTop - s.clientHeight < 80;
            if (!d.messages.length) { s.innerHTML = '<div class="sm-empty-th"><i data-lucide="message-circle"></i><div>No messages yet.</div></div>'; if (window.lucide) lucide.createIcons(); return; }
            var html = '', day = '';
            d.messages.forEach(function (m) {
                var t = m.ts ? new Date(m.ts * 1000) : new Date(String(m.created_at).replace(' ', 'T'));
                var dk = t.toDateString();
                if (dk !== day) {
                    day = dk;
                    var today = new Date().toDateString(), y = new Date(); y.setDate(y.getDate() - 1);
                    var lab = dk === today ? 'Today' : (dk === y.toDateString() ? 'Yesterday' : t.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }));
                    html += '<div class="sm-day">' + lab + '</div>';
                }
                var mine = Number(m.sender_id) === ME;
                html += '<div class="sm-msg' + (mine ? ' me' : '') + '">' +
                    (mine ? '' : '<div class="sm-who">' + esc(m.display_name) + '</div>') +
                    '<div class="sm-bub">' + esc(m.message_text) + '</div>' +
                    '<div class="sm-at">' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + (m.reaction ? ' · ' + esc(m.reaction) : '') + '</div></div>';
            });
            s.innerHTML = html;
            if (scroll || nearBottom) s.scrollTop = s.scrollHeight;
        }).catch(function () {});
    }
    function loadInfo(gid) {
        var side = document.getElementById('smSide');
        side.innerHTML = '';
        fetch('staff_messages.php?ajax=info&g=' + gid, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (gid !== open || !d || !d.ok) return;
            var g = d.group, h = '';
            h += '<div class="sm-card hero"><span class="sm-tile big" style="background:' + tint(gid) + ';color:#2a2140">' + esc(initial(g.name)) + '</span>' +
                 '<div class="sm-g-name">' + esc(g.name) + '</div><div class="sm-g-sub">' + esc([g.school, g.program].filter(Boolean).join(' · ')) + '</div></div>';
            if (d.people.length) {
                h += '<div class="sm-card"><h4>People</h4>';
                d.people.forEach(function (p) {
                    h += '<div class="sm-person"><span class="av">' + (p.pic ? '<img src="' + esc(p.pic) + '" alt="">' : esc(initial(p.name))) + '</span>' +
                         '<span><b>' + esc(p.name) + '</b><small>' + (p.leader ? 'Leader, can message' : 'Member') + '</small></span></div>';
                });
                h += '</div>';
            }
            h += '<div class="sm-card"><h4>Where they are</h4>';
            d.where.forEach(function (w) {
                h += '<div class="sm-bar"><div class="sm-bar-top">' + esc(w.label) + ' requirements<span>' + w.done + ' of ' + w.total + '</span></div>' +
                     '<div class="sm-track"><i style="width:' + Math.round(w.done / w.total * 100) + '%"></i></div></div>';
            });
            if (d.stats) {
                h += '<div class="sm-chip ' + (d.stats.done ? 'ok' : (d.stats.back ? 'back' : '')) + '"><i data-lucide="' + (d.stats.done ? 'check' : 'sigma') + '"></i>' + esc(d.stats.text) + '</div>';
                if (d.stats.control) h += '<div class="sm-ctl">' + esc(d.stats.control) + '</div>';
            }
            h += '</div>';
            side.innerHTML = h;
            if (window.lucide) lucide.createIcons();
        }).catch(function () {});
    }

    // ---- compose -------------------------------------------------------------------
    var ta = document.getElementById('smText'), sendBtn = document.getElementById('smSendBtn');
    window.smGrow = function (el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 140) + 'px'; sendBtn.disabled = !el.value.trim(); };
    window.smKey = function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); smSend(e); } };
    window.smQuick = function (b) { ta.value = b.textContent; smGrow(ta); ta.focus(); };
    window.smSend = function (e) {
        e.preventDefault();
        var text = ta.value.trim();
        if (!text || !open) return;
        var fd = new FormData();
        fd.append('action', 'send_message'); fd.append('target_group', open); fd.append('message_text', text);
        sendBtn.disabled = true;
        fetch('staff_message_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.status === 'success') { ta.value = ''; smGrow(ta); loadThread(true); refreshList(); }
            else { alert((d && d.message) || 'The message was not sent.'); sendBtn.disabled = false; }
        }).catch(function () { alert('The message was not sent — check the connection.'); sendBtn.disabled = false; });
    };

    smPaintList();
    if (open && row(open)) smOpen(open);
    else if (rows.length && window.matchMedia('(min-width: 761px)').matches) smOpen(rows[0].gid);
    setInterval(refreshList, 15000);
    if (window.lucide) lucide.createIcons();
})();
</script>
</body>
</html>
