<?php
// dashboards/focal_reports.php — the Focal Person's Reports + Visualize (2026-10-01).
//
// Asked for by a focal person: "who has submitted and who has not, so I can send a report later".
// Four reports over ONE dataset (focal_reports_lib.php, rendered client-side so every filter is
// instant and every number agrees):
//   Submissions   a group x requirement matrix — who sent what, who has not; names, remind, export
//   Requirements  each requirement's spread (approved / waiting / sent back / not sent) and review time
//   Schedule      how far groups have got toward a date, and how her dates went up the chain
//   Groups        every group with its members, School IDs, progress, last sent and date
// Visualize (?view=map, or the button) draws the same data as stacked 3D layers:
//   courses -> rooms -> groups (a bar each, height = progress) -> defense dates.
// Exports: CSV of the open report (opens in Excel) and Print (the browser's Save as PDF).
// Reads only; the one write it offers (Remind) posts to focal_handler.php's remind_groups.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Focal Person') {
    header('Location: ../auth/login.php');
    exit();
}
require_once __DIR__ . '/focal_reports_lib.php';

$me   = (int)$_SESSION['user_id'];
$FR   = fr_dataset($pdo, 'Focal Person', $me);
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$startMap = ($_GET['view'] ?? '') === 'map';
$flash = $_SESSION['message'] ?? '';
$flashType = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/css/focal_reports.css">
    <link rel="stylesheet" href="../assets/css/print.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="admin-module fr">

<div class="fr-wrap rp-page" id="frPage">
    <?php /* On paper only (assets/css/print.css): the letterhead. */ ?>
    <div class="rp-only rp-head" aria-hidden="true">
        <div class="rp-brand"><img class="rp-logo" src="../assets/images/mascot.png" alt=""><div><b>Research &amp; Development Center</b><span>MCNP – ISAP · iSubmit Research Digitalization</span></div></div>
        <div class="rp-meta"><b>Focal Person report</b><span>Prepared by <?= htmlspecialchars(($_SESSION['username'] ?? '' ?: (string)$pdo->query('SELECT username FROM users WHERE user_id = ' . (int)$_SESSION['user_id'])->fetchColumn()) . ' · Focal Person') ?></span><br><span class="rp-when"></span></div>
    </div>
    <?php if ($flash): ?>
        <div class="fr-flash <?= $flashType === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flashType === 'error' ? 'alert-circle' : 'check-circle' ?>"></i> <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <nav class="fr-nav">
        <a class="fr-back" href="focal.php" onclick="return frToHub()"><i data-lucide="arrow-left"></i> Reports</a>
    </nav>

    <header class="fr-head">
        <div>
            <div class="fr-crumb" id="frCrumb">Reports</div>
            <h1 id="frTitle">Who has sent what</h1>
            <p id="frLede">&nbsp;</p>
        </div>
        <div class="fr-actions">
            <button type="button" class="fr-btn fr-btn-viz" onclick="frMapOpen()"><i data-lucide="layers"></i> Visualize</button>
            <button type="button" class="fr-btn" onclick="frExport()"><i data-lucide="download"></i> Export CSV</button>
            <button type="button" class="fr-btn" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
        </div>
    </header>

    <?php /* One row of filters above everything, as the reports below all obey it. */ ?>
    <div class="fr-filters">
        <div class="fr-seg" id="frPhase" role="tablist" aria-label="Phase">
            <button type="button" role="tab" data-p="proposal" class="on">Proposal</button>
            <button type="button" role="tab" data-p="final">Final</button>
        </div>
        <label class="fr-select"><i data-lucide="door-open"></i><select id="frRoom" aria-label="Room"></select></label>
        <label class="fr-search"><i data-lucide="search"></i><input type="search" id="frQ" placeholder="Find a group, leader, member or ID" autocomplete="off"></label>
    </div>

    <section class="fr-kpis" id="frKpis" aria-label="Summary"></section>

    <div class="fr-tabs" role="tablist" aria-label="Report">
        <button type="button" role="tab" data-t="sub" class="on"><i data-lucide="table-2"></i> Submissions</button>
        <button type="button" role="tab" data-t="req"><i data-lucide="file-check"></i> Requirements</button>
        <button type="button" role="tab" data-t="sch"><i data-lucide="calendar-check"></i> Schedule</button>
        <button type="button" role="tab" data-t="grp"><i data-lucide="users"></i> Groups</button>
    </div>

    <main class="fr-body" id="frBody" aria-live="polite"></main>
    <p class="fr-printed rp-only rp-end" id="frPrinted"></p>
</div>

<?php /* The group drawer: members, every requirement, the date. Shared by every report and the map. */ ?>
<div class="fr-dbg" id="frDbg" onclick="frDrawer(null)"></div>
<aside class="fr-drawer" id="frDrawer" aria-hidden="true" aria-label="Group details"></aside>

<?php /* VISUALIZE — the same data as stacked layers, drawn on a canvas (no library: it works offline). */ ?>
<div class="fr-map" id="frMap" hidden>
    <canvas id="frCanvas" aria-label="3D overview of courses, rooms, groups and defense dates"></canvas>
    <div class="fr-hud">
        <div class="fr-hud-1" id="frHud1"></div>
        <div class="fr-hud-2" id="frHud2"></div>
    </div>
    <div class="fr-map-bar">
        <div class="fr-seg fr-seg-dark" id="frMapPhase">
            <button type="button" data-p="proposal" class="on">Proposal</button>
            <button type="button" data-p="final">Final</button>
        </div>
        <button type="button" class="fr-map-x" onclick="frMapClose()"><i data-lucide="x"></i> Close</button>
    </div>
    <div class="fr-map-legend" id="frMapLegend"></div>
    <p class="fr-map-help">Drag to turn &middot; scroll to zoom &middot; hover a bar to trace its path &middot; click it for details</p>
    <div class="fr-tip" id="frTip" hidden></div>
</div>

<form method="POST" action="focal_handler.php" id="frRemind" hidden>
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="remind_groups">
    <input type="hidden" name="phase" id="frRemindPhase" value="proposal">
    <div id="frRemindIds"></div>
</form>

<script>
    var FR = <?= json_encode($FR, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var FR_START_MAP = <?= $startMap ? 'true' : 'false' ?>;
</script>
<script>(function () {
    function prep() {
        var s = 'Printed ' + new Date().toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' });
        document.querySelectorAll('.rp-when').forEach(function (e) { e.textContent = s; });
        document.querySelectorAll('th > button').forEach(function (b) {
            var t = b.parentNode.querySelector('.rp-th') || b.parentNode.appendChild(Object.assign(document.createElement('span'), { className: 'rp-only rp-th' }));
            t.textContent = b.textContent;
        });
    }
    prep(); window.addEventListener('beforeprint', prep);
    if (window.matchMedia) { var m = matchMedia('print'); (m.addEventListener ? m.addEventListener('change', function (e) { if (e.matches) prep(); }) : m.addListener(function (e) { if (e.matches) prep(); })); }
})();</script>
<script src="../assets/js/focal_reports.js"></script>
</body>
</html>
