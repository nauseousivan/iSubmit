<?php
// dashboards/office_analytics.php — the office's Analytics (2026-10-01; replaces analytics.php).
//
// For the Director, Secretary, Coordinators and Statistician: where the research groups are and
// what happened when — by institution, course, phase and month — with names behind every number.
// Scoped like every office screen (aa_scope_sql): a Coordinator sees her institution only. One
// dataset (office_analytics_lib.php) rendered client-side on the shared chart toolkit, the same
// one the office Visualizer reads. Export CSV and Print per report.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['Research Director', 'Research Coordinator', 'Statistician'], true)) {
    header('Location: ../auth/login.php');
    exit();
}
require_once __DIR__ . '/office_analytics_lib.php';
$OA = an_office_dataset($pdo, $role, (int)$_SESSION['user_id']);
$hub = $role === 'Statistician' ? 'hub-workspace' : 'hub-insights';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/css/insights.css">
    <link rel="stylesheet" href="../assets/css/print.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="admin-module in">
<div class="in-wrap rp-page">
    <?php /* On paper only (assets/css/print.css): the letterhead. */ ?>
    <div class="rp-only rp-head" aria-hidden="true">
        <div class="rp-brand"><img class="rp-logo" src="../assets/images/mascot.png" alt=""><div><b>Research &amp; Development Center</b><span>MCNP – ISAP · iSubmit Research Digitalization</span></div></div>
        <div class="rp-meta"><b>Analytics report</b><span>Prepared by <?= htmlspecialchars(($_SESSION['username'] ?? '' ?: (string)$pdo->query('SELECT username FROM users WHERE user_id = ' . (int)$_SESSION['user_id'])->fetchColumn()) . ' · ' . an_role_label($role, (string)($_SESSION['email'] ?? ''))) ?></span><br><span class="rp-when"></span></div>
    </div>
    <nav class="in-nav"><a class="in-back" href="#" onclick="return inToHub('<?= $hub ?>')"><i data-lucide="arrow-left"></i> <?= $role === 'Statistician' ? 'Workspace' : 'Insights' ?></a></nav>
    <header class="in-head">
        <div>
            <div class="in-crumb" id="inCrumb">Analytics</div>
            <h1 id="inTitle">Analytics</h1>
            <p id="inLede">&nbsp;</p>
        </div>
        <div class="in-actions">
            <?php if ($role !== 'Statistician'): ?><a class="in-btn in-btn-holo" href="office_visualize.php"><i data-lucide="layers"></i> Visualizer</a><?php endif; ?>
            <button type="button" class="in-btn" onclick="inExport()"><i data-lucide="download"></i> Export CSV</button>
            <button type="button" class="in-btn" onclick="window.print()"><i data-lucide="printer"></i> Print</button>
        </div>
    </header>

    <div class="in-filters">
        <label class="in-sel"><i data-lucide="calendar"></i><select id="fMonth" aria-label="Month"></select></label>
        <label class="in-sel" id="fInstWrap"><i data-lucide="building-2"></i><select id="fInst" aria-label="Institution"></select></label>
        <label class="in-sel"><i data-lucide="book-open"></i><select id="fCourse" aria-label="Course"></select></label>
        <label class="in-sel"><i data-lucide="git-commit-horizontal"></i><select id="fStage" aria-label="Stage"></select></label>
        <label class="in-sel in-search"><i data-lucide="search"></i><input type="search" id="fQ" placeholder="Find a group or leader" autocomplete="off"></label>
        <button type="button" class="in-reset" id="fReset">Reset</button>
    </div>

    <section class="in-tiles" id="inTiles"></section>

    <div class="in-tabs" role="tablist">
        <button type="button" data-t="over" class="on"><i data-lucide="layout-dashboard"></i> Overview</button>
        <button type="button" data-t="course"><i data-lucide="book-open"></i> Courses</button>
        <button type="button" data-t="phase"><i data-lucide="git-commit-horizontal"></i> Phases</button>
        <button type="button" data-t="sched"><i data-lucide="calendar-check"></i> Schedule</button>
        <button type="button" data-t="groups"><i data-lucide="users"></i> Groups</button>
        <button type="button" data-t="people"><i data-lucide="user-cog"></i> People</button>
    </div>
    <main class="in-grid" id="inBody" aria-live="polite"></main>
    <p class="in-printed rp-only rp-end" id="inPrinted"></p>
</div>
<script>var OA = <?= json_encode($OA, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
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
<script src="../assets/js/insights.js"></script>
<script src="../assets/js/office_analytics.js"></script>
</body>
</html>
