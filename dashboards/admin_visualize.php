<?php
// dashboards/admin_visualize.php — the Admin's Visualizer (2026-10-01, reworked the same day).
//
// The Admin looks after ACCOUNTS, not papers, so this is the system as she sees it, top to bottom:
//   Staff          one marker per ROLE (never per person — the role pop-out lists the people)
//   Institutions   one node each
//   Courses        a bar each: how many students (or, with a month picked, how many joined then)
//   Help desk      a bar per category of reported issue, wired to the institutions it came from
// Nothing is drawn per student or per group, so it stays readable however big the school gets.
// Click anything and a POP-OUT opens beside it (holo_app.js); pop-outs open pop-outs, down to one
// staff member's sign-ins. The month picker and the institution switch re-filter everything; the
// telemetry panel is adjustable (hide blocks, collapse, drag). Same dataset as her Analytics
// (admin_analytics_lib.php), so the two always agree. Reads only. ?pod=staff&id=N answers a pop-out.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    if (isset($_GET['pod'])) { http_response_code(403); header('Content-Type: application/json'); echo '{"error":"Access denied"}'; exit; }
    header('Location: ../auth/login.php');
    exit();
}
require_once __DIR__ . '/admin_analytics_lib.php';

if (isset($_GET['pod'])) {
    header('Content-Type: application/json');
    $out = $_GET['pod'] === 'staff' ? an_staff_signins($pdo, (int)($_GET['id'] ?? 0)) : null;
    if (!$out) { http_response_code(404); echo '{"error":"Not found"}'; exit; }
    echo json_encode($out);
    exit;
}

$AN = an_admin_dataset($pdo);
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
    <canvas id="avMain" aria-label="3D view of staff roles, institutions, courses and the help desk"></canvas>
    <canvas id="avWires" aria-hidden="true"></canvas>
    <div class="av-scan" aria-hidden="true"></div>
    <i class="av-corner tl"></i><i class="av-corner tr"></i><i class="av-corner bl"></i><i class="av-corner br"></i>

    <header class="av-hud av-tl">
        <a class="av-back" href="admin_analytics.php" onclick="return HoloApp.toHub('hub-insights')"><i data-lucide="arrow-left"></i> Insights</a>
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

<script>var AN = <?= json_encode($AN, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script src="../assets/js/holo.js"></script>
<script src="../assets/js/holo_app.js"></script>
<script src="../assets/js/admin_visualize.js"></script>
</body>
</html>
