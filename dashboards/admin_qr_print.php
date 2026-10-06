<?php
// dashboards/admin_qr_print.php — one QR code as an A4 poster for the RDC office wall (2026-10-01). ADMIN ONLY.
// Prints the short link (q.php), so the Admin can repoint it later without reprinting.
require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$st = $pdo->prepare("SELECT * FROM qr_links WHERE qr_id = ? AND is_active = 1");
$st->execute([(int)($_GET['id'] ?? 0)]);
$q = $st->fetch(PDO::FETCH_ASSOC);
if (!$q) exit('That QR code is retired or does not exist.');
$url = rd_qr_link_url($q['code']);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <title><?= $h($q['label']) ?> — poster</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800&family=Outfit:wght@600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <script src="../assets/js/qrcode.min.js"></script>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #ece9f7; font-family: Inter, system-ui, sans-serif; color: #2e1065; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .bar { display: flex; justify-content: center; gap: 10px; padding: 14px; }
        .bar button { all: unset; cursor: pointer; padding: 10px 20px; border-radius: 999px; background: #6d28d9; color: #fff; font-weight: 700; font-size: 14px; }
        .bar button.ghost { background: #fff; color: #4c1d95; }
        .sheet { width: 210mm; height: 297mm; margin: 0 auto 30px; background: #fff; position: relative; overflow: hidden;
                 display: flex; flex-direction: column; align-items: center; text-align: center; padding: 26mm 20mm 18mm; }
        .band { position: absolute; left: 0; right: 0; top: 0; height: 12mm; background: linear-gradient(90deg, #4c1d95, #7c3aed); }
        .lock { display: flex; align-items: center; gap: 12px; margin-bottom: 16mm; }
        .lock img { width: 58px; height: 58px; }
        .lock b { display: block; font-family: Outfit, sans-serif; font-size: 30px; font-weight: 800; color: #4c1d95; line-height: 1; text-align: left; }
        .lock small { display: block; font-size: 13px; font-weight: 600; color: #6b5fa5; text-align: left; margin-top: 3px; }
        h1 { font-family: Outfit, sans-serif; font-size: 44px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 6mm; line-height: 1.1; color: #2e1065; }
        .cap { font-size: 18px; font-weight: 500; color: #4c4570; max-width: 150mm; line-height: 1.45; margin: 0 0 12mm; }
        .qr { padding: 9mm; border-radius: 10mm; border: 2.5px solid #ddd6fe; background: #fff; }
        .qr img, .qr canvas { display: block; width: 95mm !important; height: 95mm !important; }
        .how { margin-top: 10mm; font-size: 16px; font-weight: 700; color: #4c1d95; }
        .url { margin-top: 3mm; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; color: #6b5fa5; }
        .foot { margin-top: auto; font-size: 12px; color: #8b83b3; letter-spacing: .02em; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print</button><button class="ghost" onclick="window.close()">Close</button></div>
<div class="sheet">
    <div class="band"></div>
    <div class="lock"><img src="../assets/images/mascot.png" alt=""><span><b>iSubmit</b><small>Research Digitalization Platform</small></span></div>
    <h1><?= $h($q['label']) ?></h1>
    <?php if ($q['caption']): ?><p class="cap"><?= $h($q['caption']) ?></p><?php endif; ?>
    <div class="qr" id="qr"></div>
    <div class="how">Point your phone camera at the code</div>
    <div class="url"><?= $h($url) ?></div>
    <div class="foot">Research &amp; Development Center &middot; MCNP &ndash; ISAP</div>
</div>
<script>new QRCode(document.getElementById('qr'), { text: <?= json_encode($url) ?>, width: 640, height: 640, correctLevel: QRCode.CorrectLevel.H });</script>
</body>
</html>
