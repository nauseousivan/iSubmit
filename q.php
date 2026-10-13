<?php
// q.php — the short link every printed Admin QR carries: {site}/q.php?c=CODE (2026-10-01).
//
// The QR never holds the destination itself, so the Admin can repoint a code (admin_qr.php)
// without reprinting the poster. A retired or unknown code gets a polite page instead of an error,
// because the poster may still be on a wall somewhere.
require_once __DIR__ . '/config/db.php';

$code = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['c'] ?? ''));
$row  = null;
if ($code !== '') {
    try {
        $st = $pdo->prepare("SELECT qr_id, target, is_active FROM qr_links WHERE code = ?");
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }
}

if ($row && (int)$row['is_active'] === 1) {
    try {
        $pdo->prepare("UPDATE qr_links SET scans = scans + 1, last_scan = NOW() WHERE qr_id = ?")->execute([(int)$row['qr_id']]);
    } catch (Throwable $e) {
    }
    $t = (string)$row['target'];
    if (preg_match('#^https?://#i', $t)) {
        header('Location: ' . $t);
        exit;
    }
    $dest = rd_app_url() . '/' . ltrim($t, '/');
    // A card inside the portal (e.g. dashboards/student.php?module=stats): signed out, they sign
    // in first and are then taken to it — auth/login.php honours login_next for app pages only.
    if (strpos(ltrim($t, '/'), 'dashboards/') === 0 && empty($_SESSION['user_id'])) {
        $_SESSION['login_next'] = $dest;
        header('Location: ' . rd_app_url() . '/auth/login.php');
        exit;
    }
    header('Location: ' . $dest);
    exit;
}
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR code not in use | iSubmit</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth_card.css">
</head>
<body class="ac-page">
<div class="ac-wrap">
    <a class="ac-brand" href="auth/login.php"><img src="assets/images/mascot.png" alt=""><span><b>iSubmit</b><small>Research Digitalization</small></span></a>
    <div class="ac-card">
        <h1>This QR code is no longer in use</h1>
        <p>The poster you scanned has been replaced. Ask the Research &amp; Development Center for the new one, or open iSubmit directly.</p>
        <a class="ac-btn" href="auth/login.php" style="text-decoration:none">Open iSubmit</a>
    </div>
</div>
</body>
</html>
