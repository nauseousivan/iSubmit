<?php
// dashboards/focal.php — the Focal Person's shell (2026-10-01).
//
// She used to have a portal of her own: a header bar, two billboards and a bell, with no
// Settings, no theme switch, no search and no way to see her rooms. She is on the staff shell
// now, the one the Director, Coordinator, Statistician and Admin share (_staff_home.php +
// staff_home_lib.php, sh_model('Focal Person')):
//
//   Home       the live mascot, what needs her, both phases' step cards, her groups' activity
//   Proposal   the sections board ("Every section, at a glance") + Requirements / Defense dates
//   Final      the same, for the final defense
//   Workspace  Sections (rooms + the Unassigned tray) and Settings
//
// The working screens are focal_desk.php, opened as module windows by the step cards. The bell
// is hers alone (fp_feed(), derived — see focal_lib.php); the shell gives it a slot ($sh_bell).
//
// This file handles no POST: the desk posts to focal_handler.php, Settings to
// staff_settings_handler.php.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Focal Person') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . '/focal_lib.php';

$uid  = (int)$_SESSION['user_id'];
$role = 'Focal Person';
// Only the shell's own flash. $_SESSION['message'] belongs to the desk: focal_handler.php sets it
// and redirects the WINDOW, and taking it here on a refresh would swallow a verdict.
$message = (string)($_SESSION['flash_message'] ?? '');
unset($_SESSION['flash_message']);

$stmt_me = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_me->execute([$uid]);
$currentUser = $stmt_me->fetch();
$current_pfp = !empty($currentUser['profile_pic']) ? $currentUser['profile_pic']
             : "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($currentUser['username']);

// Both phases' boards, built once: the hubs draw them and the badges are counted from them, so
// the first paint and nav_badge_counts.php (fp_counts) cannot disagree.
$fb_boards = ['proposal' => fp_board($pdo, $role, $uid, 'proposal'), 'final' => fp_board($pdo, $role, $uid, 'final')];
$sh_counts = fp_counts($pdo, $role, $uid, $fb_boards);

// The bell.
$fp_feed = fp_feed($pdo, $uid, $role, 30);
$fp_ago = function (int $ts): string {
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return intdiv($d, 60) . 'm ago';
    if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('M j', $ts);
};

// Hero extras: how many courses, and the next date the Secretary has published.
$fp_courses = aa_focal_scope($pdo, $uid);
$sh_pills_extra = [['book-open', count($fp_courses) . ' course' . (count($fp_courses) === 1 ? '' : 's')]];
$fp_next = null;
foreach (fp_my_batches($pdo, $uid) as $b) {
    if ($b['status'] !== 'Scheduled' || $b['defense_date'] < df_today()) continue;
    if ($fp_next === null || $b['defense_date'] < $fp_next['defense_date']) $fp_next = $b;
}
if ($fp_next) {
    $sh_pills_extra[] = ['calendar-check', 'Next defense ' . date('M j', strtotime((string)$fp_next['defense_date'])) . ' · ' . fp_short_section($fp_next)];
}

// An old link (a bookmark, a feed row from before 2026-10-01) carries the desk's own query:
// open the shell, then that screen in its window.
$fp_deep = null;
if (in_array($_GET['view'] ?? '', ['requirements', 'schedule', 'sections'], true)) {
    $fp_deep = 'focal_desk.php?' . http_build_query(array_intersect_key($_GET, array_flip(['view', 'phase', 'course', 'year', 'section', 'group'])));
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
    <title>Focal Person | MCNP-ISAP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/mascot/mascot.css">
    <link rel="stylesheet" href="../assets/css/staff_home.css">
    <script src="../assets/js/portal.js"></script>
</head>
<body class="sh-shell">
    <div class="app-dashboard-frame">
        <?php
        $sh_role_label   = 'Focal Person';
        $sh_has_calendar = false;   // the office calendar is the office's to edit
        $sh_bell         = true;    // shBell() below
        ?>
        <script>window.SH_SHELL_VIEWS = ['settingsDashboard'];</script>
        <?php include __DIR__ . '/_staff_home.php'; ?>

            <?php include __DIR__ . '/_staff_settings.php'; ?>
        </main>

        <div class="window-layer" id="windowLayer"></div>
    </div>

    <?php /* NOTIFICATIONS. Derived from defense_batches + uploads (fp_feed), never stored, so
             "read" is a per-browser marker against the newest timestamp — the student portal's
             rule. head/where/msg are HTML that fp_feed() escapes as it builds; printed raw here.
             Grouped by Manila day (PHP runs Europe/Berlin, so the day is taken in Asia/Manila). */
    $nfMeta = [
        'file'      => ['file-up', 'file', 'Files'],
        'published' => ['calendar-check', 'date', 'Dates'],
        'confirmed' => ['stamp', 'date', 'Dates'],
        'back'      => ['corner-up-left', 'date', 'Dates'],
    ];
    $nfToday = new DateTime(df_today(), new DateTimeZone('Asia/Manila'));
    $nfDays  = [];
    foreach ($fp_feed as $e) {
        $d    = (new DateTime('@' . (int)$e['at']))->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0, 0);
        $diff = (int)$nfToday->diff($d)->format('%r%a');
        $lab  = $diff === 0 ? 'Today' : ($diff === -1 ? 'Yesterday' : ($diff > -7 ? 'This week' : 'Earlier'));
        $nfDays[$lab][] = $e;
    }
    $nfN = ['file' => 0, 'date' => 0];
    foreach ($fp_feed as $e) $nfN[$nfMeta[$e['type']][1] ?? 'date']++;
    ?>
    <div class="sh-drawer-bg" id="fpDrawerBg" onclick="shBell()"></div>
    <aside class="sh-drawer nf" id="fpDrawer" aria-label="Notifications" aria-hidden="true">
        <header class="nf-head">
            <span class="nf-bell"><i data-lucide="bell"></i></span>
            <div class="nf-title">
                <h3>Notifications</h3>
                <p>Files in your courses, and the office on your dates</p>
            </div>
            <button type="button" class="sh-icon-btn" onclick="shBell()" aria-label="Close"><i data-lucide="x"></i></button>
        </header>

        <?php if ($fp_feed): ?>
            <div class="nf-bar">
                <div class="nf-tabs" role="tablist" aria-label="Show">
                    <button type="button" role="tab" class="on" data-f="all" onclick="nfTab(this)">All</button>
                    <button type="button" role="tab" data-f="file" onclick="nfTab(this)">Files <em><?= $nfN['file'] ?></em></button>
                    <button type="button" role="tab" data-f="date" onclick="nfTab(this)">Dates <em><?= $nfN['date'] ?></em></button>
                </div>
                <button type="button" class="nf-mark" id="nfMark" onclick="nfMarkRead()" hidden><i data-lucide="check-check"></i> Mark all read</button>
            </div>
        <?php endif; ?>

        <div class="nf-body">
            <?php if (!$fp_feed): ?>
                <div class="sh-drawer-empty">
                    <?php $quill_size = 72; include __DIR__ . '/components/quill_empty.php'; ?>
                    <b>All quiet</b>
                    <span>You will hear here when a file arrives or the office answers one of your dates.</span>
                </div>
            <?php endif; ?>
            <?php foreach ($nfDays as $lab => $list): ?>
                <section class="nf-day">
                    <h4><?= $lab ?></h4>
                    <?php foreach ($list as $e): [$ico, $grp] = $nfMeta[$e['type']] ?? ['bell', 'date']; ?>
                        <button type="button" class="nf-row" data-at="<?= (int)$e['at'] ?>" data-f="<?= $grp ?>"
                                onclick="fpOpenNote(<?= htmlspecialchars(json_encode($e['href']), ENT_QUOTES) ?>)">
                            <span class="nf-ico t-<?= htmlspecialchars($e['type']) ?>"><i data-lucide="<?= $ico ?>"></i></span>
                            <span class="nf-main">
                                <span class="nf-top"><b><?= $e['head'] ?></b><time><?= htmlspecialchars($fp_ago((int)$e['at'])) ?></time></span>
                                <span class="nf-where"><?= $e['where'] ?></span>
                                <span class="nf-msg"><?= $e['body'] ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <p class="nf-none" id="nfNone" hidden>Nothing here.</p>
        </div>
        <?php if ($fp_feed): ?>
            <footer class="nf-foot">A file leaves this list once you decide it. Tap one to open it.</footer>
        <?php endif; ?>
    </aside>

    <script>
        lucide.createIcons();

        // The same names the other shells define; _staff_home.php and portal.js call them.
        function hideAllDashboards() { if (window.PortalWindows) PortalWindows.collapseAll(); }
        function showMasterDashboard() { shShow('home'); }
        function showSettingsDashboard() { shShow('settingsDashboard'); }
        function openOverlay(url, btn) {
            const title = (btn && btn.dataset.winTitle) || 'Module';
            const icon = (btn && btn.dataset.winIcon) || 'app-window';
            PortalWindows.open(url, url, title, icon, btn);
        }
        function setSettingTheme(t) { setPortalTheme(t); }

        (function () {
            // Unread = newer than the last time she opened the drawer. A new key: the old portal
            // stored strtotime() values six hours ahead of the real ones, which would have hidden
            // the next six hours of news.
            var SEEN_KEY = 'fp-notif-seen-<?= $uid ?>';
            var drawer = document.getElementById('fpDrawer'), bg = document.getElementById('fpDrawerBg');
            document.body.appendChild(bg); document.body.appendChild(drawer);
            function seen() { try { return parseInt(localStorage.getItem(SEEN_KEY) || '0', 10) || 0; } catch (e) { return 0; } }
            function rows() { return drawer.querySelectorAll('.nf-row[data-at]'); }
            function top() { var t = 0; rows().forEach(function (r) { t = Math.max(t, parseInt(r.getAttribute('data-at'), 10)); }); return t; }
            // The dots, the "Mark all read" button and the bell badge all read the same marker.
            function paint(keepDots) {
                var s = seen(), n = 0;
                rows().forEach(function (row) {
                    var fresh = parseInt(row.getAttribute('data-at'), 10) > s;
                    if (!keepDots) row.classList.toggle('is-new', fresh);
                    if (fresh) n++;
                });
                var b = document.getElementById('shBellN');
                if (b) { b.textContent = n > 9 ? '9+' : String(n); b.hidden = n === 0; }
                var m = document.getElementById('nfMark');
                if (m) m.hidden = !drawer.querySelector('.nf-row.is-new');
            }
            window.nfMarkRead = function () {
                try { localStorage.setItem(SEEN_KEY, String(top())); } catch (e) {}
                paint();
            };
            window.nfTab = function (btn) {
                var f = btn.getAttribute('data-f'), any = false;
                drawer.querySelectorAll('.nf-tabs button').forEach(function (b) { b.classList.toggle('on', b === btn); });
                drawer.querySelectorAll('.nf-day').forEach(function (day) {
                    var shown = 0;
                    day.querySelectorAll('.nf-row').forEach(function (r) { var ok = f === 'all' || r.getAttribute('data-f') === f; r.hidden = !ok; if (ok) shown++; });
                    day.hidden = shown === 0; if (shown) any = true;
                });
                var none = document.getElementById('nfNone'); if (none) none.hidden = any;
            };
            window.shBell = function () {
                var open = !drawer.classList.contains('on');
                drawer.classList.toggle('on', open); bg.classList.toggle('on', open);
                drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
                if (open) {
                    // The badge clears on OPEN; the dots stay until she closes it or marks them
                    // read, so she can see which ones were new.
                    var b = document.getElementById('shBellN'); if (b) b.hidden = true;
                    try { localStorage.setItem(SEEN_KEY, String(top())); } catch (e) {}
                    paint(true);
                } else {
                    paint();
                }
            };            window.fpOpenNote = function (href) { shBell(); shOpenDesk(href); };
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('on')) shBell(); });
            paint();

            <?php if ($fp_deep): ?>
            // An old link: the screen it named, in its window.
            shOpenDesk(<?= json_encode($fp_deep) ?>);
            try { history.replaceState(null, '', 'focal.php'); } catch (e) {}
            <?php endif; ?>
        })();
    </script>
</body>
</html>
