<?php
// dashboards/admin_qr.php — the Admin's QR codes (2026-10-01). ADMIN ONLY.
//
// Posters for the RDC office. Each printed QR carries a short link ({site}/q.php?c=CODE), never
// the page itself, so a code can be repointed later without reprinting. The one thing a reprint
// is needed for is a new SITE ADDRESS (a new domain, or moving the server) — set at the top, and
// it must be an address a phone can open, not "localhost". Writes: admin_qr_handler.php.
// (The statistician's desk QR on a student's card is separate — see stat_desk_lib.php.)

require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$rows = $pdo->query("SELECT q.*, DATE_FORMAT(q.last_scan, '%b %e, %l:%i %p') AS last_on
                     FROM qr_links q ORDER BY q.is_active DESC, q.qr_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$site     = rd_site_url();
$site_set = trim((string)rd_setting('site_url', ''));
$phoneless = (bool)preg_match('#//(localhost|127\.0\.0\.1|\[?::1\]?)([:/]|$)#i', $site);
$presets = [
    'auth/login.php'           => 'Sign in page',
    'auth/help.php'            => 'Help Desk',
    'auth/register.php'        => 'Student registration',
    'auth/forgot_password.php' => 'Forgot password',
    // The four phase cards (2026-10-01). Locked stages show the card's lock message instead of opening.
    'dashboards/student.php?module=proposal' => 'Student card: Proposal Defense',
    'dashboards/student.php?module=stats'    => 'Student card: Statistics',
    'dashboards/student.php?module=final'    => 'Student card: Final Defense',
    'dashboards/student.php?module=plag'     => 'Student card: Plagiarism Test',
];
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
    <title>QR Codes</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="../assets/js/qrcode.min.js"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .aa { --disp: "Outfit", var(--font-ui); padding: 22px 26px 40px; max-width: 1360px; margin: 0 auto; }
        .aa-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .aa-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .aa-sub { font-size: 13px; color: var(--muted); margin: 0 0 16px; max-width: 760px; line-height: 1.5; }
        .aa-flash { padding: 11px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600; display: flex; gap: 8px; align-items: center;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .aa-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .aa-flash i, .aa-flash svg { width: 16px; height: 16px; flex-shrink: 0; }
        .aa-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
        @media (max-width: 1080px) { .aa-layout { grid-template-columns: 1fr; } }
        .qs-site { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 16px 18px; margin-bottom: 16px; }
        .qs-site.warn { border-color: color-mix(in srgb, var(--warning) 45%, var(--line)); background: color-mix(in srgb, var(--warning) 7%, var(--surface)); }
        .qs-site-ico { width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0; display: grid; place-items: center; background: var(--surface-3); color: var(--accent); }
        .qs-site-ico i, .qs-site-ico svg { width: 19px; height: 19px; }
        .qs-site-txt { flex: 1; min-width: 220px; }
        .qs-site-txt b { display: block; font-size: 13.5px; color: var(--ink); }
        .qs-site-txt small { display: block; font-size: 12px; color: var(--muted); line-height: 1.45; margin-top: 2px; }
        .qs-site form { display: flex; gap: 8px; flex: 1 1 360px; }
        .qs-in, .aa select, .aa textarea { width: 100%; box-sizing: border-box; padding: 10px 13px; border-radius: 12px; border: 1.5px solid transparent; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .qs-in:focus, .aa select:focus, .aa textarea:focus { border-color: var(--accent); background: var(--surface); }
        .qs-in.mono { font-family: 'JetBrains Mono', monospace; font-size: 12.5px; }
        .qs-btn { all: unset; cursor: pointer; flex-shrink: 0; padding: 9px 16px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 12.5px; font-weight: 750; display: inline-flex; align-items: center; gap: 6px; }
        .qs-btn.ghost { background: var(--surface-3); color: var(--ink); }
        .qs-btn i, .qs-btn svg { width: 14px; height: 14px; }
        .qs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
        .qs-card { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 16px; display: flex; flex-direction: column; }
        .qs-card.off { opacity: .6; }
        .qs-top { display: flex; gap: 14px; }
        .qs-qr { width: 112px; height: 112px; flex-shrink: 0; padding: 8px; border-radius: 14px; background: #fff; border: 1px solid var(--line); box-sizing: content-box; }
        .qs-qr img, .qs-qr canvas { display: block; width: 112px !important; height: 112px !important; }
        .qs-info { flex: 1; min-width: 0; }
        .qs-info b { display: block; font-family: var(--disp); font-size: 17px; font-weight: 700; color: var(--ink); line-height: 1.25; }
        .qs-info p { font-size: 12.5px; color: var(--muted); margin: 4px 0 0; line-height: 1.45; }
        .qs-pill { display: inline-block; font-size: 10.5px; font-weight: 800; padding: 2px 9px; border-radius: 999px; margin-top: 8px;
                   background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 60%, var(--ink)); }
        .qs-card.off .qs-pill { background: var(--surface-3); color: var(--muted); }
        .qs-meta { margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 12px; color: var(--muted); display: grid; gap: 4px; }
        .qs-meta span b { color: var(--ink); font-weight: 700; }
        .qs-meta code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--ink); overflow-wrap: anywhere; }
        .qs-acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; align-items: center; }
        .qs-link { all: unset; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--accent); }
        .qs-link.bad { color: var(--danger); }
        .qs-edit { margin-top: 12px; padding: 12px; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--line); display: grid; gap: 8px; }
        .aa-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; padding: 22px; }
        .aa-panel h2 { font-family: var(--disp); font-size: 21px; font-weight: 700; color: var(--ink); margin: 0 0 3px; }
        .aa-hint { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
        .aa-lab { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 0 0 6px; }
        .aa-field { margin-bottom: 14px; }
        .aa-create { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; text-align: center; padding: 13px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 13.5px; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .aa-create i, .aa-create svg { width: 16px; height: 16px; }
        @media (max-width: 640px) { .aa { padding: 16px 12px 30px; } .aa-title { font-size: 28px; } .qs-site form { flex-basis: 100%; } }
    </style>
</head>
<body class="admin-module">
<div class="aa">
    <div class="aa-eyebrow">System</div>
    <h1 class="aa-title">QR codes</h1>
    <p class="aa-sub">Posters for the Research &amp; Development Center. A printed code keeps working when you change where it goes; only a new site address needs a reprint.</p>

    <?php if ($flash): ?>
        <div class="aa-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= $h($flash) ?></div>
    <?php endif; ?>

    <div class="qs-site <?= $phoneless ? 'warn' : '' ?>">
        <span class="qs-site-ico"><i data-lucide="<?= $phoneless ? 'triangle-alert' : 'globe' ?>"></i></span>
        <div class="qs-site-txt">
            <b>Site address in every QR code</b>
            <small><?= $phoneless
                ? 'Phones cannot open "localhost". Type the address a phone on the school Wi-Fi uses, e.g. http://192.168.1.20/Research_Digital, or the real domain.'
                : ($site_set === '' ? 'Automatic: ' . $h($site) . ' — the address people open iSubmit with (on this computer, its Wi-Fi address). Set it only if the site has a fixed domain.' : 'If the domain changes, update it here and reprint the posters.') ?></small>
        </div>
        <form method="POST" action="admin_qr_handler.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_site">
            <input class="qs-in mono" name="site_url" value="<?= $h($site_set) ?>" placeholder="<?= $h($site) ?>">
            <button class="qs-btn" type="submit">Save</button>
        </form>
    </div>

    <div class="aa-layout">
        <div class="qs-grid">
        <?php foreach ($rows as $r):
            $on = (int)$r['is_active'] === 1;
            $isPreset = isset($presets[$r['target']]); ?>
            <div class="qs-card <?= $on ? '' : 'off' ?>">
                <div class="qs-top">
                    <div class="qs-qr" data-qr="<?= $h(rd_qr_link_url($r['code'])) ?>"></div>
                    <div class="qs-info">
                        <b><?= $h($r['label']) ?></b>
                        <?php if ($r['caption']): ?><p><?= $h($r['caption']) ?></p><?php endif; ?>
                        <span class="qs-pill"><?= $on ? 'In use' : 'Retired' ?></span>
                    </div>
                </div>
                <div class="qs-meta">
                    <span>Opens: <b><?= $h($presets[$r['target']] ?? $r['target']) ?></b></span>
                    <span><b><?= (int)$r['scans'] ?></b> scan<?= (int)$r['scans'] === 1 ? '' : 's' ?><?= $r['last_on'] ? ' &middot; last ' . $h($r['last_on']) : '' ?></span>
                    <code><?= $h(rd_qr_link_url($r['code'])) ?></code>
                </div>
                <div class="qs-acts">
                    <?php if ($on): ?><a class="qs-btn" href="admin_qr_print.php?id=<?= (int)$r['qr_id'] ?>" target="_blank"><i data-lucide="printer"></i> Print poster</a><?php endif; ?>
                    <button type="button" class="qs-link" onclick="this.closest('.qs-card').querySelector('.qs-edit').hidden ^= 1">Edit</button>
                    <form method="POST" action="admin_qr_handler.php" style="margin:0" onsubmit="return confirm('<?= $on ? 'Retire this code? Old posters will say it is no longer in use.' : 'Bring this code back?' ?>')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="qr_id" value="<?= (int)$r['qr_id'] ?>">
                        <button type="submit" class="qs-link <?= $on ? 'bad' : '' ?>"><?= $on ? 'Retire' : 'Bring back' ?></button>
                    </form>
                </div>
                <form class="qs-edit" method="POST" action="admin_qr_handler.php" hidden>
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="qr_id" value="<?= (int)$r['qr_id'] ?>">
                    <input class="qs-in" name="label" required maxlength="80" value="<?= $h($r['label']) ?>" placeholder="Title on the poster">
                    <input class="qs-in" name="caption" maxlength="200" value="<?= $h($r['caption']) ?>" placeholder="One line under the title">
                    <select name="target" onchange="this.nextElementSibling.hidden = this.value !== 'custom'">
                        <?php foreach ($presets as $p => $pl): ?><option value="<?= $p ?>" <?= $r['target'] === $p ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?>
                        <option value="custom" <?= $isPreset ? '' : 'selected' ?>>Another page or link</option>
                    </select>
                    <input class="qs-in mono" name="custom_target" value="<?= $isPreset ? '' : $h($r['target']) ?>" placeholder="https://... or a page in iSubmit" <?= $isPreset ? 'hidden' : '' ?>>
                    <div class="qs-acts" style="margin-top:2px">
                        <button type="submit" class="qs-btn">Save</button>
                        <button type="submit" class="qs-link bad" formaction="admin_qr_handler.php" name="action" value="recode"
                                onclick="return confirm('Give it a NEW code? Every poster already printed stops working.')">New code</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="aa-panel">
            <h2>New QR code</h2>
            <p class="aa-hint">For a poster, a flyer or a slide. You can change where it goes later without reprinting.</p>
            <form method="POST" action="admin_qr_handler.php">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="create">
                <div class="aa-field"><label class="aa-lab" for="qn">Title on the poster</label><input class="qs-in" id="qn" name="label" required maxlength="80" placeholder="e.g. Research forms and requirements"></div>
                <div class="aa-field"><label class="aa-lab" for="qc">One line under it</label><input class="qs-in" id="qc" name="caption" maxlength="200" placeholder="e.g. Scan with your phone camera."></div>
                <div class="aa-field"><label class="aa-lab" for="qt">Opens</label>
                    <select id="qt" name="target" onchange="document.getElementById('qx').hidden = this.value !== 'custom'">
                        <?php foreach ($presets as $p => $pl): ?><option value="<?= $p ?>"><?= $pl ?></option><?php endforeach; ?>
                        <option value="custom">Another page or link</option>
                    </select>
                    <input class="qs-in mono" id="qx" name="custom_target" placeholder="https://... or a page in iSubmit" style="margin-top:8px" hidden>
                </div>
                <button type="submit" class="aa-create"><i data-lucide="qr-code"></i> Create QR code</button>
            </form>
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('[data-qr]').forEach(function (el) {
        new QRCode(el, { text: el.dataset.qr, width: 224, height: 224, correctLevel: QRCode.CorrectLevel.M });
    });
    if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
