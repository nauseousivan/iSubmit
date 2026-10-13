<?php
// dashboards/office_visualize.php — the office's Visualizer (2026-10-01).
//
// For the Director, Secretary and Coordinators: the whole research office as one 3D view, top to
// bottom — Staff (a marker per ROLE) -> Institutions -> Courses (a bar each: groups, or with a month
// picked, what happened in that course then) -> Phases (a bar per stage: groups there, or that
// phase's activity in the month), every course wired to the stages its groups are in.
// AGGREGATED on purpose: no group is ever its own mark, so it reads the same at 50 groups or 5,000.
// Click anything for a pop-out (holo_app.js) — role -> its people, institution -> its courses and
// stages, course -> rooms and stage mix, stage -> which courses hold it. Names live in Analytics.
// Scoped like every office screen (aa_scope_sql): a Coordinator sees her institution only. Same
// dataset as office_analytics.php, so the two always agree. Reads only.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['Research Director', 'Research Coordinator'], true)) {
    header('Location: ../auth/login.php');
    exit();
}
require_once __DIR__ . '/office_analytics_lib.php';
$OA = an_office_dataset($pdo, $role, (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="../assets/css/holo.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="av">
<div class="av-stage" id="avStage">
    <canvas id="avMain" aria-label="3D view of staff roles, institutions, courses and phases"></canvas>
    <canvas id="avWires" aria-hidden="true"></canvas>
    <div class="av-scan" aria-hidden="true"></div>
    <i class="av-corner tl"></i><i class="av-corner tr"></i><i class="av-corner bl"></i><i class="av-corner br"></i>

    <header class="av-hud av-tl">
        <a class="av-back" href="office_analytics.php" onclick="return HoloApp.toHub('hub-insights')"><i data-lucide="arrow-left"></i> Insights</a>
        <div>
            <h1>Visualizer</h1>
            <div class="av-read" id="avRead">&nbsp;</div>
            <div class="av-path" id="avPath">&nbsp;</div>
        </div>
    </header>

    <div class="av-hud av-tr">
        <label class="av-month"><i data-lucide="calendar"></i><select id="avMonth" aria-label="Month"></select></label>
        <div class="av-seg" id="avInst" role="tablist" aria-label="Institution"></div>
    </div>

    <aside class="av-tele" aria-label="Telemetry"></aside>

    <div class="av-legend" id="avLegend"></div>
    <p class="av-help">Drag to turn &middot; scroll to zoom &middot; hover to trace &middot; <b>click anything to open it</b></p>
    <div class="av-tip" id="avTip" hidden></div>
    <div id="avPods"></div>
</div>

<script>var OA = <?= json_encode($OA, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script src="../assets/js/holo.js"></script>
<script src="../assets/js/holo_app.js"></script>
<script src="../assets/js/office_visualize.js"></script>
</body>
</html>
