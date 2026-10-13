<?php
// dashboards/admin_access_log.php — the Access log (2026-10-01). ADMIN ONLY.
//
// Every sign-in attempt from auth/login.php (login_attempts): who, when, how it went, from where.
// The top answers the three questions an admin opens this for — how many got in today, how many
// failed, and who is locked out right now (with Unlock). Below, the log itself, filtered and
// paged server-side (it only ever grows). Ages come from MySQL, never PHP (Europe/Berlin).

require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$f    = in_array($_GET['f'] ?? '', ['failed', 'ok', 'locked'], true) ? $_GET['f'] : '';
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;

$today = $pdo->query("SELECT SUM(success = 1) ok, SUM(reason = 'wrong_password') bad, COUNT(DISTINCT CASE WHEN success = 1 THEN email END) people
                      FROM login_attempts WHERE created_at >= CURDATE()")->fetch(PDO::FETCH_ASSOC);

// Locked right now: any email with failures in the window, asked of the one function login.php uses.
$mins = rd_setting_int('lockout_minutes', 15, 1, 1440);
$locked = rd_locked_emails($pdo);

$where = ['1']; $args = [];
if ($f === 'failed') $where[] = "la.success = 0 AND la.reason <> 'unlock'";
if ($f === 'ok')     $where[] = "la.success = 1";
if ($f === 'locked') $where[] = "la.reason = 'locked'";
if ($q !== '') { $where[] = "(la.email LIKE ? OR u.username LIKE ? OR la.ip LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
$w = implode(' AND ', $where);

$total = $pdo->prepare("SELECT COUNT(*) FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id WHERE $w");
$total->execute($args);
$total = (int)$total->fetchColumn();
$st = $pdo->prepare("
    SELECT la.*, u.username, u.role,
           DATE_FORMAT(la.created_at, '%W, %b %e') AS day_label, DATE(la.created_at) = CURDATE() AS is_today,
           DATE_FORMAT(la.created_at, '%l:%i %p') AS hhmm
    FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id
    WHERE $w ORDER BY la.created_at DESC, la.attempt_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$pages = max(1, (int)ceil($total / $per));

/** A readable device from the user agent — enough to tell a phone from a laptop. */
function al_device(string $ua): string
{
    if ($ua === '') return 'Unknown device';
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iPhone' : (preg_match('/Windows/i', $ua) ? 'Windows'
        : (preg_match('/Mac OS/i', $ua) ? 'Mac' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Other'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
        : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')));
    return ($br === 'Browser' && $os === 'Other') ? 'Unknown device' : "$br on $os";
}
$reasons = ['ok' => ['Signed in', 'ok'], 'wrong_password' => ['Wrong password', 'bad'], 'no_account' => ['No such account', 'bad'],
            'locked' => ['Refused: locked out', 'bad'], 'inactive' => ['Refused: account not active', 'warn'],
            'maintenance' => ['Refused: maintenance', 'warn'], 'unlock' => ['Unlocked by the Admin', 'info']];
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['f' => $f, 'q' => $q], $o), fn($x) => $x !== '' && $x !== null));
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$flash = $_SESSION['message'] ?? ''; $flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Log</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body.admin-module { padding: 0; }
        .al { --disp: "Outfit", var(--font-ui); padding: 22px 26px 40px; max-width: 1100px; margin: 0 auto; }
        .al-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .al-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .al-sub { font-size: 13px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
        .al-flash { padding: 12px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600; background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .al-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .al-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
        .al-stat { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 16px 18px; }
        .al-stat b { display: block; font-family: var(--disp); font-size: 30px; font-weight: 700; color: var(--ink); line-height: 1.1; }
        .al-stat span { font-size: 12.5px; color: var(--muted); font-weight: 600; }
        .al-stat.bad b { color: var(--danger); }
        .al-stat.warn b { color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .al-locked { background: color-mix(in srgb, var(--danger) 7%, var(--surface)); border: 1px solid color-mix(in srgb, var(--danger) 25%, var(--line)); border-radius: 20px; padding: 14px 18px; margin-bottom: 14px; }
        .al-locked h2 { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0 0 8px; display: flex; gap: 7px; align-items: center; }
        .al-locked h2 i, .al-locked h2 svg { width: 16px; height: 16px; color: var(--danger); }
        .al-lk { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-top: 1px solid color-mix(in srgb, var(--danger) 15%, var(--line)); font-size: 13px; color: var(--ink); }
        .al-lk:first-of-type { border-top: 0; }
        .al-lk small { color: var(--muted); }
        .al-lk form { margin-left: auto; }
        .al-btn { all: unset; cursor: pointer; padding: 6px 14px; border-radius: 999px; background: var(--accent); color: var(--accent-ink); font-size: 12px; font-weight: 750; }
        .al-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
        .al-pills { display: inline-flex; gap: 2px; padding: 4px; border-radius: 999px; background: var(--surface-3); }
        .al-pills a { padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--ink); text-decoration: none; }
        .al-pills a.on { background: var(--surface); box-shadow: var(--shadow-1); }
        .al-search { margin-left: auto; position: relative; flex: 0 1 300px; }
        .al-search input { width: 100%; box-sizing: border-box; padding: 10px 14px 10px 36px; border: 1.5px solid transparent; border-radius: 999px; background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13px; outline: none; }
        .al-search i, .al-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); }
        .al-box { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .al-day { padding: 10px 18px; font-size: 11.5px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); background: var(--surface-2); border-top: 1px solid var(--line); }
        .al-day:first-child { border-top: 0; }
        .al-row { display: grid; grid-template-columns: 76px 1fr 190px 190px; gap: 12px; align-items: center; padding: 10px 18px; border-top: 1px solid var(--line); font-size: 13px; color: var(--ink); }
        .al-time { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--muted); }
        .al-who b { font-weight: 700; } .al-who small { display: block; font-size: 11.5px; color: var(--muted); word-break: break-all; }
        .al-tag { justify-self: start; font-size: 11.5px; font-weight: 750; padding: 3px 10px; border-radius: 999px; }
        .al-tag.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 60%, var(--ink)); }
        .al-tag.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .al-tag.warn { background: color-mix(in srgb, var(--warning) 18%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); }
        .al-tag.info { background: var(--surface-3); color: var(--ink); }
        .al-dev { font-size: 12px; color: var(--muted); } .al-dev small { display: block; font-family: 'JetBrains Mono', monospace; font-size: 11px; }
        .al-none { padding: 30px; text-align: center; color: var(--muted); font-size: 13px; }
        .al-pager { display: flex; justify-content: center; gap: 8px; margin-top: 14px; font-size: 13px; }
        .al-pager a { padding: 8px 14px; border-radius: 999px; background: var(--surface-3); color: var(--ink); text-decoration: none; font-weight: 700; }
        .al-pager span { padding: 8px 6px; color: var(--muted); }
        @media (max-width: 760px) { .al { padding: 16px 12px 30px; } .al-stats { grid-template-columns: 1fr; } .al-row { grid-template-columns: 64px 1fr; } .al-dev { grid-column: 2; } .al-tag { grid-column: 2; } .al-search { margin-left: 0; flex-basis: 100%; } }
    </style>
</head>
<body class="admin-module">
<div class="al">
    <div class="al-eyebrow">System</div>
    <h1 class="al-title">Access log</h1>
    <p class="al-sub">Every sign-in attempt. <?= rd_setting_int('lockout_attempts', 5, 3, 20) ?> wrong passwords lock that email for <?= $mins ?> minutes (change it in System settings).</p>

    <?php if ($flash): ?><div class="al-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><?= $h($flash) ?></div><?php endif; ?>

    <div class="al-stats">
        <div class="al-stat"><b><?= (int)$today['ok'] ?></b><span>sign-ins today, by <?= (int)$today['people'] ?> <?= (int)$today['people'] === 1 ? 'person' : 'people' ?></span></div>
        <div class="al-stat <?= (int)$today['bad'] ? 'warn' : '' ?>"><b><?= (int)$today['bad'] ?></b><span>wrong passwords today</span></div>
        <div class="al-stat <?= $locked ? 'bad' : '' ?>"><b><?= count($locked) ?></b><span>locked out right now</span></div>
    </div>

    <?php if ($locked): ?>
        <div class="al-locked">
            <h2><i data-lucide="lock"></i>Locked out right now</h2>
            <?php foreach ($locked as $em => $left): ?>
                <div class="al-lk"><b><?= $h($em) ?></b><small><?= $left ?> min left</small>
                    <form method="POST" action="admin_system_handler.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="unlock"><input type="hidden" name="email" value="<?= $h($em) ?>">
                        <button class="al-btn" type="submit">Unlock</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="al-toolbar">
        <div class="al-pills">
            <?php foreach (['' => 'All', 'ok' => 'Signed in', 'failed' => 'Failed', 'locked' => 'Lockouts'] as $k => $lab): ?>
                <a class="<?= $f === $k ? 'on' : '' ?>" href="<?= $h($qs(['f' => $k, 'page' => null])) ?>"><?= $lab ?></a>
            <?php endforeach; ?>
        </div>
        <form class="al-search" method="GET"><i data-lucide="search"></i>
            <?php if ($f): ?><input type="hidden" name="f" value="<?= $h($f) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Email, name or IP address">
        </form>
    </div>

    <div class="al-box">
        <?php $day = null; foreach ($rows as $r):
            [$label, $tone] = $reasons[$r['reason']] ?? [$r['reason'], 'info'];
            if ($r['day_label'] !== $day): $day = $r['day_label']; ?>
                <div class="al-day"><?= $r['is_today'] ? 'Today' : $h($day) ?></div>
            <?php endif; ?>
            <div class="al-row">
                <span class="al-time"><?= $h(trim($r['hhmm'])) ?></span>
                <span class="al-who"><b><?= $h($r['username'] ?: $r['email']) ?></b><small><?= $r['username'] ? $h($r['email']) . ' &middot; ' . $h($r['role']) : 'no account with this email' ?></small></span>
                <span class="al-tag <?= $tone ?>"><?= $label ?></span>
                <span class="al-dev"><?= $h(al_device((string)$r['user_agent'])) ?><small><?= $h($r['ip']) ?></small></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="al-none">No sign-in attempts<?= $q !== '' || $f ? ' match this filter' : ' yet' ?>.</div><?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="al-pager">
            <?php if ($page > 1): ?><a href="<?= $h($qs(['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= $h($qs(['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
