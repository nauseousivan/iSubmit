<?php
// dashboards/admin_batch_review.php — the Coordinator's and Secretary's schedule queues.
//
// A Focal Person consolidates one SECTION onto one day and endorses it. That arrives here as a
// bundle: course + section + the groups she put on the date. One item per section, not one per
// requirement — per-requirement sign-off would tap a coordinator 5 requirements x 8 groups = 40
// times for a single room.
//
//   list    the bundles waiting on you, as cards
//   ?batch= one bundle opened: the groups she put forward, in slot order, with their files
//
// Coordinator acts on 'Endorsed'; Secretary on 'Confirmed' — her signature logs the slots and
// is what publishes the date to the students. (The Director acts as Secretary until that
// account exists — see CLAUDE.md.) Writes go through focal_handler.php.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'focal_lib.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Research Coordinator', 'Research Director', 'Admin'], true)) {
    exit('Access Denied');
}

$me   = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$csrf = htmlspecialchars($_SESSION['csrf_token']);
$today = df_today();

$canConfirm  = fp_can_act($role, 'Endorsed');    // step 2
$canFinalize = fp_can_act($role, 'Confirmed');   // step 3, as Secretary

// Opened from a phase's nav section, so the queues are scoped to that phase. No phase in the
// URL means show both, which is what a direct link or a bookmark gets.
$br_phase = in_array($_GET['phase'] ?? '', ['proposal', 'final'], true) ? $_GET['phase'] : null;

// Published lives behind its own door. It is the only part of this screen that is FINISHED
// business, and it is the only part that grows without limit — on the working page it did
// nothing but push the things still waiting off the top.
$br_view = in_array($_GET['view'] ?? '', ['published', 'returned'], true) ? $_GET['view'] : '';
$br_when = ($_GET['when'] ?? '') === 'past' ? 'past' : 'up';
$br_page = max(1, (int)($_GET['p'] ?? 1));

$openId = isset($_GET['batch']) ? (int)$_GET['batch'] : 0;
$batch  = $openId ? fp_get_batch($pdo, $openId) : null;
// Scoping the LIST is not the same as protecting the item: a pasted ?batch= from the other
// institution has to be refused too.
if ($batch) {
    $br_guard = fp_school_scope($pdo, $role, $me);
    if ($br_guard !== null && $batch['department'] !== $br_guard) {
        exit('Access Denied');
    }
}

$flash      = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

function br_h(?string $v): string { return htmlspecialchars((string)$v); }

if ($batch) {
    $rows     = fp_batch_groups($pdo, $openId);
    $stale    = $batch['defense_date'] < $today;
    $myStep   = fp_can_act($role, $batch['status']);
    // Requirement progress for each group on the day, so the reviewer is not taking the
    // focal person's word for it.
    $ready    = fp_readiness($pdo, array_column($rows, 'user_id'), $batch['phase']);
} else {
    // Everything in flight is listed, not just your own step. Hiding the other steps made the
    // chain invisible — you could not tell whether a section was still with the Coordinator or
    // already on the Secretary's desk. The ones that are not yours render locked.
    $br_school = fp_school_scope($pdo, $role, $me);
    $endorsed  = fp_chain_queue($pdo, 'Endorsed',  $br_phase, $br_school);
    $confirmed = fp_chain_queue($pdo, 'Confirmed', $br_phase, $br_school);
    $returned  = fp_chain_queue($pdo, 'Returned',  $br_phase, $br_school);
    $scheduled = fp_chain_queue($pdo, 'Scheduled', $br_phase, $br_school);

    $mine     = $canConfirm ? $endorsed : ($canFinalize ? $confirmed : []);
    $notMine  = $canConfirm ? $confirmed : ($canFinalize ? $endorsed : array_merge($endorsed, $confirmed));
    $myLabel  = $canConfirm ? 'Waiting for you &mdash; confirm and send to the Secretary'
              : ($canFinalize ? 'Waiting for you &mdash; log the slots and publish' : 'Waiting for you');

    // Published splits at today, because those are two different things: a date still ahead is
    // something the office gets asked about, a date already held is a record. Ascending is right
    // for what is coming; for the record it is backwards, so that half is reversed.
    $br_pub_up = $br_pub_past = [];
    foreach ($scheduled as $b) {
        if ($b['defense_date'] >= $today) { $br_pub_up[] = $b; } else { $br_pub_past[] = $b; }
    }
    $br_pub_past = array_reverse($br_pub_past);

    // Paged, so the archive costs the same to open in five years as it does today.
    $br_all   = $br_when === 'past' ? $br_pub_past : $br_pub_up;
    $br_per   = 12;
    $br_pages = max(1, (int)ceil(count($br_all) / $br_per));
    $br_page  = min($br_page, $br_pages);
    $br_rows  = array_slice($br_all, ($br_page - 1) * $br_per, $br_per);

    // Every control on this screen is a link, so one place builds them: keep what is already
    // set, change what is asked for, drop whatever is passed as null.
    $br_q   = ['phase' => $br_phase, 'view' => $br_view ?: null,
               'when'  => $br_view === 'published' && $br_when === 'past' ? 'past' : null,
               'p'     => $br_view === 'published' && $br_page > 1 ? $br_page : null];
    $br_url = function (array $over) use ($br_q): string {
        $q = array_filter(array_merge($br_q, $over), fn($v) => $v !== null && $v !== '');
        return 'admin_batch_review.php' . ($q ? '?' . http_build_query($q) : '');
    };}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense Schedule Approvals</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        .br-alert-error { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 58%, var(--ink)); border-color: color-mix(in srgb, var(--danger) 25%, transparent); }


        /* ================= APPROVALS ================= */
        /* This screen is about WAITING, so waiting is what it draws: how long something has sat
           on your desk, and where in the chain it currently is. The one piece of motion — a dot
           travelling the in-flight leg — carries that meaning; a spinner would not. */

        .br-banner { display: flex; align-items: center; gap: 15px; border-radius: 18px;
            padding: 17px 20px; margin-bottom: 18px; border: 1px solid var(--line);
            background: var(--surface); box-shadow: 0 2px 10px -8px color-mix(in srgb, var(--ink) 40%, transparent); }
        .br-banner.live { background: linear-gradient(115deg,
                color-mix(in srgb, var(--accent) 12%, var(--surface)) 0%,
                color-mix(in srgb, var(--accent) 4%, var(--surface)) 60%);
            border-color: color-mix(in srgb, var(--accent) 28%, var(--line)); }
        .br-banner-ico { width: 42px; height: 42px; border-radius: 13px; display: grid; place-items: center;
            flex-shrink: 0; background: color-mix(in srgb, var(--accent) 15%, var(--surface)); color: var(--accent); }
        .br-banner.clear .br-banner-ico { background: color-mix(in srgb, var(--success) 15%, var(--surface)); color: var(--success); }
        .br-banner-ico i, .br-banner-ico svg { width: 21px; height: 21px; }
        /* The hourglass turns once a minute: enough to read as "still waiting", slow enough that
           nobody watching the screen all day is bothered by it. */
        .br-banner.live .br-banner-ico i, .br-banner.live .br-banner-ico svg { animation: brTurn 60s linear infinite; }
        @keyframes brTurn { 0%, 88% { transform: rotate(0); } 96%, 100% { transform: rotate(180deg); } }
        .br-banner-txt b { display: block; font-size: 15.5px; font-weight: 780; color: var(--ink); letter-spacing: -.015em; }
        .br-banner-txt span { font-size: 12.5px; color: var(--muted); }

        /* ---- nothing here, and that is fine ----
           Quill, then the line that says why. The mascot is 96px and decorative: alt="" so a
           screen reader gets the heading and skips the picture, which carries no information a
           sighted reader does not also get from the text. */
        .nx { display: flex; flex-direction: column; align-items: center; text-align: center;
            padding: 44px 24px 40px; }
        /* Inline SVG, not the PNG: the raster copy has a stray white shape beside the left
           wing. Sized here, and settled down a touch so a bright sticker reads as furniture
           on a screen that has nothing else on it. */
        .nx .quill-still { width: 96px; height: 96px; margin-bottom: 4px; opacity: .95; }
        .nx h3 { font-size: 16px; font-weight: 800; color: var(--ink); margin: 6px 0 0;
            letter-spacing: -.015em; }
        .nx p { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 7px 0 0; max-width: 42ch; }
        .nx p a { color: var(--accent); font-weight: 700; }
        .nx .nx-go { display: inline-flex; align-items: center; gap: 7px; margin-top: 15px;
            font-size: 12.5px; font-weight: 750; text-decoration: none; color: var(--ink-soft);
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-pill);
            padding: 8px 16px; transition: color .14s ease, border-color .14s ease, transform .14s ease; }
        .nx .nx-go:hover { color: var(--accent); transform: translateY(-1px);
            border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); }
        .nx .nx-go i, .nx .nx-go svg { width: 14px; height: 14px; }
        @media (prefers-reduced-motion: reduce) { .nx .nx-go { transition: none; } }
        /* ---- the one control row ----
           Filter on the left, the way out to the archive on the right. Both are links: this
           screen has no state a reload should lose. */
        .br-bar { display: flex; justify-content: flex-end; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 18px; }
        .br-filter { display: inline-flex; gap: 3px; background: var(--surface-2);
            border: 1px solid var(--line); border-radius: var(--r-pill); padding: 3px; }
        .br-fbtn { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 700;
            color: var(--muted); text-decoration: none; white-space: nowrap;
            padding: 6px 15px; border-radius: var(--r-pill); transition: color .14s ease, background .14s ease; }
        .br-fbtn span { font-size: 11px; font-weight: 800; opacity: .68; }
        .br-fbtn:hover { color: var(--ink); }
        .br-fbtn.on { background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm); }
        .br-out { margin-left: auto; display: inline-flex; align-items: center; gap: 7px;
            font-size: 12.5px; font-weight: 750; text-decoration: none; color: var(--ink-soft);
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-pill);
            padding: 7px 14px; transition: border-color .14s ease, color .14s ease, transform .14s ease; }
        .br-out:hover { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); transform: translateY(-1px); }
        .br-out i, .br-out svg { width: 14px; height: 14px; }
        .br-out span { font-size: 11px; font-weight: 800; color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, var(--surface));
            border-radius: var(--r-pill); padding: 1px 8px; }

        /* Coming up / Already held, inside the archive. */
        .br-seg { display: inline-flex; gap: 3px; background: var(--surface-2); border: 1px solid var(--line);
            border-radius: var(--r-pill); padding: 3px; margin-bottom: 16px; }
        .br-seg a { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 700;
            color: var(--muted); text-decoration: none; padding: 6px 15px; border-radius: var(--r-pill);
            transition: color .14s ease, background .14s ease; }
        .br-seg a:hover { color: var(--ink); }
        .br-seg a.on { background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm); }
        .br-seg a span { font-size: 11px; font-weight: 800; opacity: .68; }

        .br-page { display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap; margin-top: 18px; }
        .br-page a { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 750;
            color: var(--accent); text-decoration: none; background: var(--surface);
            border: 1px solid var(--line); border-radius: var(--r-pill); padding: 7px 14px; }
        .br-page a:hover { border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); }
        .br-page a.off { color: var(--muted); opacity: .45; pointer-events: none; }
        .br-page a i, .br-page a svg { width: 14px; height: 14px; }
        .br-page span { font-size: 12px; color: var(--muted); }

        /* ---- a section of the queue ---- */
        .br-sect { margin-bottom: 26px; scroll-margin-top: 16px; }
        .br-sect-head { margin-bottom: 11px; }
        .br-sect-head h2 { display: flex; align-items: center; gap: 8px; margin: 0;
            font-size: 12px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); }
        .br-sect-head h2 i, .br-sect-head h2 svg { width: 14px; height: 14px; }
        .br-sect-n { background: var(--surface-3); color: var(--ink-soft); border-radius: var(--r-pill);
            padding: 1px 8px; font-size: 11px; font-weight: 800; letter-spacing: 0; }
        .br-sect-head p { margin: 5px 0 0; font-size: 12.5px; color: var(--muted); line-height: 1.5; }

        /* ---- the bundle card ---- */
        .br-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
        .br-card { display: block; text-decoration: none; color: inherit; background: var(--surface);
            border: 1px solid var(--line); border-radius: 18px; padding: 16px 17px 14px;
            transition: transform .22s var(--ease), box-shadow .22s var(--ease), border-color .22s var(--ease); }
        .br-card:hover { transform: translateY(-3px); box-shadow: 0 14px 28px -16px color-mix(in srgb, var(--ink) 50%, transparent);
            border-color: color-mix(in srgb, var(--accent) 38%, var(--line)); }
        /* Yours to sign: a gradient ground and a lit edge, so the actionable cards are obvious
           before you have read one word on them. */
        .br-card.mine { background: linear-gradient(150deg,
                color-mix(in srgb, var(--accent) 9%, var(--surface)) 0%,
                var(--surface) 55%);
            border-color: color-mix(in srgb, var(--accent) 30%, var(--line)); }
        .br-card.locked, .br-card.done { opacity: .82; }
        .br-card.locked:hover, .br-card.done:hover { opacity: 1; }
        .br-card.bounced { border-color: color-mix(in srgb, var(--danger) 34%, var(--line));
            background: linear-gradient(150deg, color-mix(in srgb, var(--danger) 7%, var(--surface)) 0%, var(--surface) 55%); }
        .br-card.stale { border-color: color-mix(in srgb, var(--danger) 45%, var(--line)); }

        .br-top { display: flex; align-items: flex-start; gap: 12px; }
        .br-ident { flex: 1; min-width: 0; }
        .br-course { font-size: 14.5px; font-weight: 780; color: var(--ink); line-height: 1.3; letter-spacing: -.012em; }
        .br-sec { font-size: 12px; color: var(--muted); margin-top: 3px; }
        /* The date as a calendar chip — a number you can find at a glance beats an ISO string. */
        .br-when { flex-shrink: 0; width: 46px; text-align: center; border-radius: 12px; padding: 5px 0 6px;
            background: var(--surface-2); border: 1px solid var(--line); }
        .br-when-d { display: block; font-size: 19px; font-weight: 800; color: var(--ink); line-height: 1.05; letter-spacing: -.03em; }
        .br-when-m { display: block; font-size: 9.5px; font-weight: 800; letter-spacing: .07em;
            text-transform: uppercase; color: var(--muted); margin-top: 1px; }
        .br-when.soon { background: color-mix(in srgb, var(--warning) 15%, var(--surface));
            border-color: color-mix(in srgb, var(--warning) 30%, transparent); }
        .br-when.soon .br-when-d { color: color-mix(in srgb, var(--warning) 72%, var(--ink)); }
        .br-when.past { opacity: .55; }
        .br-when.bad { background: color-mix(in srgb, var(--danger) 13%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 30%, transparent); }
        .br-when.bad .br-when-d { color: color-mix(in srgb, var(--danger) 68%, var(--ink)); }

        /* ---- the chain, and the dot in flight ---- */
        .br-flow { display: flex; align-items: center; margin: 15px 0 5px; }
        .br-node { width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center;
            background: var(--surface-3); color: var(--muted); font-size: 10px; font-weight: 800; }
        .br-node i, .br-node svg { width: 11px; height: 11px; }
        .br-node.done { background: var(--success); color: #fff; }
        .br-node.now { background: var(--accent); color: var(--accent-ink);
            box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 55%, transparent); animation: brPulse 2.6s ease-out infinite; }
        .br-node.bad { background: var(--danger); color: #fff; }
        @keyframes brPulse {
            0%   { box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 45%, transparent); }
            70%  { box-shadow: 0 0 0 7px transparent; }
            100% { box-shadow: 0 0 0 0 transparent; }
        }
        .br-leg { position: relative; flex: 1; height: 2px; margin: 0 7px; border-radius: 2px;
            background: var(--surface-3); overflow: hidden; }
        .br-leg.done { background: var(--success); }
        /* The leg it is sitting in gets a dot that runs the gap — the request is in transit, and
           this is the only thing on the page that moves for that reason. */
        .br-leg.flying::after {
            content: ''; position: absolute; top: 50%; left: 0; width: 7px; height: 7px;
            margin-top: -3.5px; border-radius: 50%; background: var(--accent);
            box-shadow: 0 0 7px color-mix(in srgb, var(--accent) 70%, transparent);
            animation: brFly 2.6s cubic-bezier(.45, 0, .55, 1) infinite;
        }
        @keyframes brFly {
            0%   { left: -8px; opacity: 0; }
            18%  { opacity: 1; }
            82%  { opacity: 1; }
            100% { left: 100%; opacity: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .br-leg.flying::after, .br-node.now, .br-banner.live .br-banner-ico i, .br-banner.live .br-banner-ico svg { animation: none; }
            .br-leg.flying::after { left: 50%; opacity: 1; }
        }
        .br-flow-legend { display: flex; justify-content: space-between; margin-bottom: 12px; }
        .br-flow-legend span { font-size: 9px; font-weight: 700; letter-spacing: .05em;
            text-transform: uppercase; color: var(--muted); opacity: .75; }

        .br-foot { display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
            border-top: 1px solid var(--line); padding-top: 11px; }
        .br-chip { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
            color: var(--muted); background: var(--surface-2); border-radius: var(--r-pill); padding: 3px 9px; }
        .br-chip i, .br-chip svg { width: 11px; height: 11px; }
        .br-chip.lock { background: var(--surface-3); }
        .br-chip.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface));
            color: color-mix(in srgb, var(--success) 62%, var(--ink)); }
        .br-chip.bad { background: color-mix(in srgb, var(--danger) 13%, var(--surface));
            color: color-mix(in srgb, var(--danger) 62%, var(--ink)); }
        /* The live counter. It ticks once a minute — the number IS the point of this screen. */
        .br-age { margin-left: auto; font-size: 11px; font-weight: 800; color: var(--accent);
            background: color-mix(in srgb, var(--accent) 11%, var(--surface));
            border-radius: var(--r-pill); padding: 3px 10px; white-space: nowrap; }

        /* ---- opened bundle ---- */
        .br-back { font-size: 12.5px; font-weight: 700; color: var(--accent); text-decoration: none; display: inline-flex; align-items: center; gap: 5px; margin-bottom: 14px; }
        .br-hero { background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 22px; margin-bottom: 14px; }
        .br-hero h1 { font-size: 21px; font-weight: 800; color: var(--ink); margin: 0 0 3px; letter-spacing: -.01em; }
        .br-hero .br-sub { font-size: 13px; color: var(--muted); margin: 0; }
        .br-hero-date { display: flex; align-items: center; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--line); flex-wrap: wrap; }
        .br-hero-lb { font-size: 10px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); }
        .br-hero-d { font-size: 17px; font-weight: 780; color: var(--ink); letter-spacing: -.015em; }

        .br-panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 20px; margin-bottom: 14px; }
        .br-panel h2 { font-size: 14.5px; font-weight: 750; margin: 0 0 3px; color: var(--ink); }
        .br-panel .br-note { font-size: 12.5px; color: var(--muted); margin: 0 0 15px; line-height: 1.55; }

        .br-g { display: flex; align-items: center; gap: 12px; padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--r-md); margin-bottom: 7px; background: var(--surface-2); }
        .br-g-name { font-size: 13.5px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .br-g-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
        .br-g-right { margin-left: auto; display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
        .br-slot { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 800; color: var(--surface); background: var(--ink); border-radius: var(--r-pill); padding: 4px 11px; flex-shrink: 0; }
        .br-files { font-size: 12px; font-weight: 700; color: var(--accent); text-decoration: none; white-space: nowrap; }

        /* chain */
        .br-chain { display: flex; gap: 0; border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; margin-bottom: 14px; }
        .br-step { flex: 1; padding: 13px 15px; border-right: 1px solid var(--line); }
        .br-step:last-child { border-right: none; }
        .br-step.done { background: color-mix(in srgb, var(--success) 9%, var(--surface)); }
        .br-step.now { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .br-step-t { font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 6px; }
        .br-step-t b { width: 16px; height: 16px; border-radius: 50%; display: grid; place-items: center; font-size: 10px; background: var(--surface-3); color: var(--muted); }
        .br-step.done .br-step-t b { background: var(--success); color: #fff; }
        .br-step.now .br-step-t b { background: var(--accent); color: var(--accent-ink); }
        .br-step-w { font-size: 12.5px; font-weight: 700; color: var(--ink); margin-top: 6px; }
        .br-step-d { font-size: 11.5px; color: var(--muted); }

        .br-acts { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; }
        .br-send { margin-top: 13px; }
        .br-send summary { font-size: 12.5px; font-weight: 650; color: var(--muted); cursor: pointer; list-style: none; }
        .br-send summary::-webkit-details-marker { display: none; }
        .br-send textarea { width: 100%; max-width: 520px; margin: 10px 0; }
        .br-warn { font-size: 12.5px; font-weight: 650; color: color-mix(in srgb, var(--danger) 62%, var(--ink)); display: flex; align-items: center; gap: 7px; }

        /* ================= APPROVALS, 2026-09-29 redesign (.ap-*) =================
           From the Claude Design concept. Tokens only, so dark mode follows; the feature card is
           brand purple in both themes on purpose, like the home hero. */
        body.admin-module { --ap-display: "Outfit", var(--font-ui); }
        .ap-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; margin-bottom: 14px; }
        .ap-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .ap-head h1 { font-family: var(--ap-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin: 2px 0 6px; }
        .ap-head p { font-size: 13.5px; color: var(--muted); max-width: 470px; line-height: 1.5; }
        .ap-steps { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); align-self: center; }
        .ap-steps a { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px;
            font-size: 13px; font-weight: 600; color: var(--ink); text-decoration: none; }
        .ap-steps a:hover { background: color-mix(in srgb, var(--surface) 60%, transparent); }
        .ap-steps a.on { background: var(--surface); box-shadow: var(--shadow-1); font-weight: 700; }
        .ap-steps b { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-size: 11px;
            background: color-mix(in srgb, var(--accent) 16%, var(--surface)); color: var(--accent); }
        .ap-steps a.on b { background: var(--accent); color: var(--accent-ink); }
        .ap-steps span { font-size: 11.5px; font-weight: 800; color: var(--accent); }

        .ap-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .ap-who { display: inline-flex; align-items: center; gap: 7px; padding: 7px 13px; border: 1px solid var(--line); border-radius: 999px;
            font-size: 12.5px; color: var(--muted); background: var(--surface); }
        .ap-who i, .ap-who svg { width: 14px; height: 14px; }
        .ap-who b { color: var(--ink); }
        .ap-seg { display: inline-flex; border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--line)); border-radius: 12px; overflow: hidden; }
        .ap-seg a { padding: 8px 15px; font-size: 12.5px; font-weight: 600; color: var(--ink); text-decoration: none; }
        .ap-seg a + a { border-left: 1px solid color-mix(in srgb, var(--accent) 30%, var(--line)); }
        .ap-seg a:hover { background: var(--surface-2); }
        .ap-seg a.on { background: color-mix(in srgb, var(--accent) 14%, var(--surface)); font-weight: 700; }
        .ap-seg-sm { margin-bottom: 14px; display: inline-flex !important; width: auto !important; }
        .ap-flash { display: flex; align-items: center; gap: 8px; padding: 11px 15px; border-radius: 14px; margin-bottom: 16px;
            background: color-mix(in srgb, var(--success) 13%, var(--surface)); font-size: 13px; font-weight: 600; color: var(--ink); }
        .ap-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); }
        .ap-flash i, .ap-flash svg { width: 16px; height: 16px; }

        .ap-grid { display: grid; grid-template-columns: minmax(0, 1fr) 290px; gap: 22px; align-items: start; }
        .ap-sect + .ap-sect { margin-top: 26px; }
        .ap-sect h2 { font-family: var(--ap-display); font-size: 19px; font-weight: 600; color: var(--ink); margin-bottom: 12px;
            display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .ap-sect h2 small { font-family: var(--font-ui); font-size: 12.5px; font-weight: 500; color: var(--muted); }
        .ap-also { font-size: 13px; font-weight: 700; color: var(--ink); margin: 16px 0 8px; }
        .ap-also span { font-size: 11px; font-weight: 800; color: var(--accent); margin-left: 4px; }

        .ap-date { flex-shrink: 0; width: 56px; padding: 7px 0; border-radius: 14px; background: var(--surface);
            display: flex; flex-direction: column; align-items: center; line-height: 1.1; color: var(--ink); box-shadow: var(--shadow-1); }
        .ap-date b { font-family: var(--ap-display); font-size: 24px; font-weight: 700; }
        .ap-date small { font-size: 10.5px; font-weight: 600; color: var(--muted); }
        .ap-date.light { width: 64px; background: #fff; color: var(--sh-hero-a, #2e1065); }
        .ap-date.light small { color: #6b5b95; }
        .ap-date.ok { background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); box-shadow: none; }
        .ap-date.sm { width: 44px; padding: 5px 0; border-radius: 11px; }
        .ap-date.sm b { font-size: 17px; }

        .ap-feature { position: relative; overflow: hidden; border-radius: 26px; padding: 22px 24px; color: #fff;
            background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); box-shadow: 0 18px 40px -20px rgba(46, 16, 101, .7); }
        .ap-feature::after { content: ''; position: absolute; right: -12%; top: -40%; width: 46%; height: 150%;
            border-radius: 50%; background: rgba(255, 255, 255, .07); pointer-events: none; }
        .ap-feature > * { position: relative; z-index: 1; }
        .ap-feature-top { display: flex; align-items: center; gap: 16px; }
        .ap-feature-id { flex: 1; min-width: 0; }
        .ap-feature-id h3 { font-family: var(--ap-display); font-size: 23px; font-weight: 700; letter-spacing: -.01em; color: #fff; }
        .ap-feature-id p { font-size: 12.5px; color: rgba(255, 255, 255, .75); margin-top: 3px; }
        .ap-arrived { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px;
            background: rgba(255, 255, 255, .12); font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .ap-arrived .br-age { all: unset; }   /* the old timer pill style must not leak in */
        .ap-arrived > i { width: 6px; height: 6px; border-radius: 50%; background: #fbbf24; }
        .ap-chain { list-style: none; display: grid; grid-template-columns: repeat(3, 1fr); margin: 22px 0 20px; position: relative; }
        .ap-chain::before { content: ''; position: absolute; top: 13px; left: 14px; right: calc(33.3% - 14px); height: 2px; background: rgba(255, 255, 255, .35); }
        .ap-chain li { position: relative; display: flex; flex-direction: column; gap: 1px; }
        .ap-node { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 10px;
            background: #3b1a7a; border: 2px solid rgba(255, 255, 255, .35); }
        .ap-chain li.done .ap-node { background: #86efac; border-color: #86efac; color: #14532d; }
        .ap-node i, .ap-node svg { width: 14px; height: 14px; stroke-width: 3; }
        .ap-chain li.now .ap-node { background: #fff; border-color: #fff; box-shadow: 0 0 0 5px rgba(255, 255, 255, .18); }
        .ap-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--sh-hero-b, #4c1d95); animation: apPulse 1.8s ease-in-out infinite; }
        @keyframes apPulse { 50% { transform: scale(.6); opacity: .6; } }
        .ap-chain b { font-size: 13px; font-weight: 700; }
        .ap-chain small { font-size: 11.5px; color: rgba(255, 255, 255, .7); }
        .ap-feature-acts { display: flex; gap: 10px; flex-wrap: wrap; }
        .ap-feature-acts form { display: contents; }

        .ap-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; cursor: pointer;
            font-family: inherit; font-size: 13px; font-weight: 700; text-decoration: none; border: 1.5px solid transparent; }
        .ap-btn i, .ap-btn svg { width: 15px; height: 15px; }
        .ap-btn-light { background: #fff; color: var(--sh-hero-a, #2e1065); }
        .ap-btn-light:hover { background: #f3e8ff; }
        .ap-btn-ghost { background: transparent; color: #fff; border-color: rgba(255, 255, 255, .45); }
        .ap-btn-ghost:hover { background: rgba(255, 255, 255, .1); }
        .ap-btn-solid { background: var(--accent); color: var(--accent-ink); padding: 8px 14px; font-size: 12.5px; }
        .ap-btn-outline { background: transparent; color: var(--ink); border-color: var(--line); padding: 7px 14px; font-size: 12.5px; }
        .ap-btn-outline:hover { border-color: var(--accent); background: var(--surface); }

        .ap-list { display: flex; flex-direction: column; gap: 10px; }
        .ap-list-2 { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); }
        .ap-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 20px; background: var(--surface-3); }
        .ap-row.done { background: var(--surface); border: 1px solid var(--line); }
        .ap-row-main { flex: 1; min-width: 0; text-decoration: none; color: inherit; }
        .ap-row-main h4 { font-family: var(--ap-display); font-size: 16px; font-weight: 600; color: var(--ink); }
        .ap-row-main p { font-size: 12px; color: var(--muted); margin-top: 1px; }
        .ap-row-main q { display: block; margin-top: 6px; font-size: 12px; color: var(--ink); font-style: italic; }
        .ap-row-act form { margin: 0; }
        .ap-by { font-size: 11.5px; color: var(--muted); white-space: nowrap; }
        .ap-mini { display: flex; align-items: center; margin-top: 8px; }
        .ap-mini > i { width: 12px; height: 12px; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--accent) 30%, var(--line)); background: var(--surface); flex-shrink: 0; }
        .ap-mini > i.done { background: #86efac; border-color: #4ade80; }
        .ap-mini > i.now { width: 16px; height: 16px; background: var(--accent); border-color: color-mix(in srgb, var(--accent) 40%, var(--surface)); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
        .ap-mini > i.bad { background: var(--danger); border-color: var(--danger); }
        .ap-mini > em { width: 38px; height: 2px; background: color-mix(in srgb, var(--accent) 22%, var(--line)); }
        .ap-mini > em.on { background: #4ade80; }
        .ap-mini > span { margin-left: 10px; font-size: 11.5px; color: var(--muted); }
        .ap-more { display: contents; }
        .ap-more[hidden] { display: none !important; }
        .ap-show { align-self: flex-start; background: none; border: none; cursor: pointer; font: inherit; font-size: 12.5px; font-weight: 700; color: var(--accent); padding: 4px 2px; }
        .ap-clear { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-radius: 20px; border: 1px dashed var(--line); background: var(--surface); }
        .ap-clear b { display: block; font-size: 15px; color: var(--ink); }
        .ap-clear span { font-size: 12.5px; color: var(--muted); }

        .ap-side { display: flex; flex-direction: column; gap: 14px; }
        .ap-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; padding: 18px 16px; }
        .ap-panel h3 { font-family: var(--ap-display); font-size: 16.5px; font-weight: 600; color: var(--ink); margin-bottom: 12px; }
        .ap-panel-head { display: flex; justify-content: space-between; align-items: baseline; }
        .ap-panel-head a { font-size: 12px; font-weight: 700; color: var(--accent); text-decoration: none; }
        .ap-how { list-style: none; position: relative; }
        .ap-how::before { content: ''; position: absolute; left: 11px; top: 14px; bottom: 14px; width: 2px; background: var(--line); }
        .ap-how li { position: relative; display: flex; gap: 12px; padding: 5px 0; }
        .ap-how li > b { flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: 11px;
            background: var(--surface-3); color: var(--accent); }
        .ap-how li.me > b { background: var(--accent); color: var(--accent-ink); }
        .ap-how strong { display: block; font-size: 13px; color: var(--ink); }
        .ap-how small { font-size: 11.5px; color: var(--muted); }
        .ap-pub { display: flex; align-items: center; gap: 11px; padding: 8px; border-radius: 14px; text-decoration: none; color: inherit; }
        .ap-pub:hover { background: var(--surface-2); }
        .ap-pub-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .ap-pub-body b { font-size: 12.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ap-pub-body small { font-size: 11px; color: var(--muted); }
        .ap-today { font-size: 10.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px;
            background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .ap-none { font-size: 12.5px; color: var(--muted); padding: 6px 4px; }

        @media (max-width: 980px) { .ap-grid { grid-template-columns: minmax(0, 1fr); } .ap-list-2 { grid-template-columns: 1fr; } }
        @media (max-width: 620px) {
            .ap-head h1 { font-size: 26px; }
            .ap-steps { display: flex; width: 100%; }
            .ap-steps a { flex: 1; justify-content: center; gap: 5px; padding: 6px 6px; font-size: 12px; }
            .ap-steps b { width: 19px; height: 19px; font-size: 10px; }
            .ap-feature { padding: 18px; border-radius: 22px; }
            .ap-feature-top { flex-wrap: wrap; }
            .ap-arrived { order: -1; flex-basis: 100%; background: none; padding: 0; }
            .ap-row { flex-wrap: wrap; }
            .ap-row-act { width: 100%; }
            .ap-mini > em { width: 22px; }
        }
        @media (prefers-reduced-motion: reduce) { .ap-dot { animation: none; } }

        /* ================= ONE REQUEST, OPENED (.bd-*), 2026-09-29 ================= */
        .bd-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .bd-back { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px;
            background: var(--surface-3); color: var(--ink); font-size: 13px; font-weight: 700; text-decoration: none; }
        .bd-back:hover { background: color-mix(in srgb, var(--accent) 14%, var(--surface-3)); }
        .bd-back i, .bd-back svg { width: 15px; height: 15px; }
        .bd-status { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; }
        .bd-status i, .bd-status svg { width: 14px; height: 14px; }
        .bd-status.ok   { background: color-mix(in srgb, var(--success) 15%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .bd-status.wait { background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--accent); }
        .bd-status.bad  { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 70%, var(--ink)); }
        .bd-status.off  { background: var(--surface-2); color: var(--muted); }

        .bd-hero { display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap;
            padding: 26px 26px; border-radius: 26px; background: var(--surface-3); margin-bottom: 16px; }
        .bd-hero h1 { font-family: var(--ap-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; }
        .bd-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
        .bd-tags span { padding: 5px 11px; border-radius: 9px; background: var(--surface); font-size: 12px; font-weight: 600; color: var(--ink); }
        .bd-datecard { display: flex; align-items: center; gap: 14px; padding: 12px 22px 12px 12px; border-radius: 22px;
            background: var(--surface); box-shadow: var(--shadow-2); }
        .bd-tile { width: 60px; padding: 8px 0; border-radius: 15px; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: linear-gradient(135deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; }
        .bd-tile b { font-family: var(--ap-display); font-size: 25px; }
        .bd-tile small { font-size: 10.5px; font-weight: 700; opacity: .85; }
        .bd-datecard > div { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
        .bd-datecard > div > small { font-size: 12px; color: var(--muted); }
        .bd-datecard > div > b { font-family: var(--ap-display); font-size: 18px; font-weight: 600; color: var(--ink); }
        .bd-when { margin-top: 4px; font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px;
            background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .bd-when.today { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 72%, var(--ink)); }
        .bd-when.past { background: var(--surface-2); color: var(--muted); }

        .bd-card { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 20px 22px; margin-bottom: 16px; }
        .bd-card h2 { font-family: var(--ap-display); font-size: 18px; font-weight: 600; color: var(--ink); margin-bottom: 4px; }

        .bd-chain { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; }
        .bd-step { display: flex; flex-direction: column; min-width: 0; }
        .bd-rail { display: flex; align-items: center; margin-bottom: 12px; }
        .bd-node { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center;
            background: var(--surface-2); border: 2px solid var(--line); color: var(--muted); }
        .bd-node i, .bd-node svg { width: 15px; height: 15px; stroke-width: 3; }
        .bd-step.done .bd-node { background: color-mix(in srgb, var(--success) 18%, var(--surface)); border-color: transparent; color: color-mix(in srgb, var(--success) 72%, var(--ink)); }
        .bd-step.now .bd-node { background: var(--surface); border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 16%, transparent); }
        .bd-step.now .ap-dot { background: var(--accent); }
        .bd-step.bad .bd-node { background: color-mix(in srgb, var(--danger) 14%, var(--surface)); border-color: transparent; color: var(--danger); }
        .bd-line { flex: 1; height: 3px; margin: 0 10px; border-radius: 2px; background: var(--line); }
        .bd-line.done { background: color-mix(in srgb, var(--success) 60%, #14532d); }
        .bd-step small { font-size: 11.5px; color: var(--muted); }
        .bd-step b { font-size: 14px; font-weight: 700; color: var(--ink); margin: 1px 0; }
        .bd-step b i { font-weight: 600; color: var(--muted); }
        .bd-step > span { font-size: 11.5px; color: var(--muted); }
        .bd-step.now > span { color: var(--accent); font-weight: 700; }

        .bd-act { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap;
            border-color: color-mix(in srgb, var(--accent) 35%, var(--line)); background: color-mix(in srgb, var(--accent) 5%, var(--surface)); }
        .bd-act-copy { flex: 1; min-width: 240px; }
        .bd-act-copy p { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
        .bd-act-btns { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
        .bd-go { padding: 11px 18px !important; font-size: 13px !important; }
        .bd-stale { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; color: color-mix(in srgb, var(--warning) 75%, var(--ink)); }
        .bd-stale i, .bd-stale svg { width: 16px; height: 16px; }
        .bd-send summary { cursor: pointer; font-size: 12px; font-weight: 700; color: var(--muted); list-style: none; text-align: right; }
        .bd-send summary::-webkit-details-marker { display: none; }
        .bd-send[open] summary { color: var(--ink); margin-bottom: 8px; }
        .bd-send form { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; }
        .bd-send textarea { width: 320px; max-width: 70vw; min-height: 70px; padding: 9px 11px; border-radius: 12px; border: 1px solid var(--line);
            background: var(--surface); color: var(--ink); font: inherit; font-size: 12.5px; resize: vertical; }

        .bd-grid { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 16px; align-items: start; }
        .bd-grid > .bd-card { margin-bottom: 0; }
        .bd-lead { font-size: 12.5px; color: var(--muted); margin-bottom: 14px; }
        .bd-groups { display: flex; flex-direction: column; gap: 8px; }
        .bd-g { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 16px; background: var(--surface-3); }
        .bd-slot { width: 42px; padding: 5px 0; border-radius: 11px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: linear-gradient(135deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; }
        .bd-slot small { font-size: 9.5px; font-weight: 700; opacity: .85; }
        .bd-slot b { font-family: var(--ap-display); font-size: 19px; }
        .bd-g-id { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .bd-g-id b { font-size: 14px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bd-g-id span { font-size: 12px; color: var(--muted); }
        .bd-prog { display: flex; flex-direction: column; gap: 5px; }
        .bd-segs { display: flex; gap: 3px; }
        .bd-segs i { width: 13px; height: 4px; border-radius: 2px; background: var(--line); }
        .bd-segs i.on { background: color-mix(in srgb, var(--warning) 80%, var(--ink)); }
        .bd-prog.ok .bd-segs i.on { background: color-mix(in srgb, var(--success) 65%, #14532d); }
        .bd-prog small { font-size: 11px; font-weight: 700; color: color-mix(in srgb, var(--warning) 70%, var(--ink)); }
        .bd-prog.ok small { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .bd-files { padding: 7px 14px !important; }
        .bd-note { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 16px; margin-top: 12px; font-size: 12.5px; color: var(--ink); }
        .bd-note > i, .bd-note > svg { width: 18px; height: 18px; flex-shrink: 0; }
        .bd-note > span { flex: 1; }
        .bd-note a { font-weight: 800; color: var(--ink); text-decoration: underline; white-space: nowrap; }
        .bd-note.warn { background: color-mix(in srgb, var(--warning) 18%, var(--surface)); }
        .bd-note.bad { background: color-mix(in srgb, var(--danger) 11%, var(--surface)); margin: 0 0 16px; }

        .bd-tl { list-style: none; position: relative; margin-top: 14px; }
        .bd-tl::before { content: ''; position: absolute; left: 4px; top: 8px; bottom: 12px; width: 2px; background: var(--line); }
        .bd-tl li { position: relative; padding: 0 0 16px 20px; display: flex; flex-direction: column; }
        .bd-tl li:last-child { padding-bottom: 0; }
        .bd-tl li::before { content: ''; position: absolute; left: 0; top: 5px; width: 10px; height: 10px; border-radius: 50%; background: var(--line); }
        .bd-tl li.on::before { background: var(--accent); }
        .bd-tl li.ok::before { background: color-mix(in srgb, var(--success) 70%, #14532d); }
        .bd-tl li.bad::before { background: var(--danger); }
        .bd-tl li.todo::before { background: var(--surface); border: 2px solid var(--line); width: 6px; height: 6px; }
        .bd-tl li.day::before { background: var(--surface); border: 2px solid var(--muted); width: 6px; height: 6px; }
        .bd-tl li.day.today::before { border-color: var(--danger); }
        .bd-tl b { font-size: 13px; color: var(--ink); }
        .bd-tl small { font-size: 11.5px; color: var(--muted); line-height: 1.4; }
        .bd-tl li.todo b { color: var(--muted); font-weight: 600; }

        @media (max-width: 900px) { .bd-grid { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 620px) {
            .bd-hero { padding: 20px; }
            .bd-hero h1 { font-size: 25px; }
            .bd-datecard { width: 100%; }
            .bd-chain { grid-template-columns: 1fr; gap: 14px; }
            .bd-rail .bd-line { display: none; }
            .bd-g { flex-wrap: wrap; }
            .bd-prog { order: 3; width: calc(100% - 56px); margin-left: 56px; }
            .bd-act-btns { align-items: stretch; width: 100%; }
            .bd-send summary { text-align: left; }
            .bd-send textarea { width: 100%; max-width: none; }
            .bd-note { flex-wrap: wrap; }
        }
    </style>
</head>

<body class="admin-module">
<?php include __DIR__ . '/components/admin_view_only.php'; ?>

<?php if (!$batch): /* ================================================ QUEUE ==== */ ?>
<?php
/*
 * APPROVALS (2026-09-29 redesign, from the Claude Design concept).
 *
 *   On your desk          the soonest section waiting on YOU as one big card; any others as
 *                         compact cards under it — five purple cards in a row is a wall
 *   Further up the chain  in flight on another desk: compact rows, with Nudge (emails whoever
 *                         holds that step). Collapses after four.
 *   right column          how the chain works (your step marked) + the latest published
 *
 * Sent back and Published stay behind the pills, as before: one is on the Focal Person's desk,
 * the other is finished business that only grows. No action here writes anything new — Confirm
 * and Send back post the same fields to focal_handler.php, and "Log slots and publish" is still
 * the hand-off to the Scheduling screen, where the calendar is.
 */
$ap_ph      = $br_phase ?? 'proposal';
$ap_me      = $canConfirm ? 'Coordinator' : ($canFinalize ? 'Secretary' : '');
$ap_me_real = $role === 'Research Coordinator' ? 'Coordinator'
            : ((stripos((string)$pdo->query("SELECT email FROM users WHERE user_id = " . $me)->fetchColumn(), 'secretary') !== false) ? 'Secretary' : 'Director');

// Recently published: newest SIGNATURE first, not newest date — "what just went out".
$ap_recent = $scheduled;
usort($ap_recent, fn($a, $b) => (int)$b['sec_ts'] <=> (int)$a['sec_ts']);
$ap_recent = array_slice($ap_recent, 0, 3);

/** "4th Year, Section A, proposal defense, 1 group" */
function ap_sub(array $b): string
{
    $parts = [];
    if (!empty($b['year_level'])) $parts[] = br_h($b['year_level']);
    $parts[] = 'Section ' . br_h($b['label']);
    $parts[] = df_phase_label($b['phase']);
    $parts[] = (int)$b['group_count'] . ' group' . ((int)$b['group_count'] === 1 ? '' : 's');
    return implode(', ', $parts);
}
/** Seconds this has sat on the current desk — DB epochs, never strtotime() (Berlin vs Manila). */
function ap_since(array $b): int
{
    return (int)($b['status'] === 'Confirmed' ? ($b['coord_ts'] ?? 0)
         : ($b['status'] === 'Returned' ? ($b['returned_ts'] ?? 0) : ($b['focal_ts'] ?? 0)));
}
function ap_date_tile(array $b, string $cls = ''): void
{
    $ts = strtotime((string)$b['defense_date']); ?>
    <span class="ap-date <?= $cls ?>"><small><?= date('D', $ts) ?></small><b><?= date('j', $ts) ?></b><small><?= date('M', $ts) ?></small></span>
<?php }

/** The primary action for a section on MY desk — the same POSTs / hand-off as the opened view. */
function ap_action(array $b, string $today, string $csrf, bool $big): void
{
    $stale = $b['defense_date'] < $today;
    $cls   = $big ? 'ap-btn ap-btn-light' : 'ap-btn ap-btn-solid';
    if ($stale): ?>
        <a class="<?= $cls ?>" href="?batch=<?= (int)$b['batch_id'] ?>"><i data-lucide="corner-up-left"></i>Date passed &mdash; send it back</a>
    <?php elseif ($b['status'] === 'Endorsed'): ?>
        <form method="POST" action="focal_handler.php" onsubmit="return confirm('Confirm this date and send it to the Secretary?');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="confirm_batch">
            <input type="hidden" name="batch_id" value="<?= (int)$b['batch_id'] ?>">
            <button type="submit" class="<?= $cls ?>"><i data-lucide="check"></i>Confirm and send</button>
        </form>
    <?php else: ?>
        <?php // from=approvals: the section page then offers "Back to Approvals", not only its own list. ?>
        <a class="<?= $cls ?>" href="admin_module_dynamic.php?phase=<?= br_h($b['phase']) ?>&view=schedule&batch=<?= (int)$b['batch_id'] ?>&from=approvals">
            <i data-lucide="calendar-check"></i>Log slots and publish</a>
    <?php endif;
}

/** The big purple card: the one to do now. */
function ap_feature(array $b, string $today, string $csrf, string $meLabel): void
{
    $coordDone = !empty($b['coord_at']);
    $nodes = [
        ['done', $b['focal_name'] ?: 'Focal person', 'Focal person'],
        $coordDone ? ['done', $b['coord_name'] ?: 'Coordinator', 'Coordinator']
                   : ['now', 'You', $meLabel . ', your turn'],
        $coordDone ? ['now', 'You', $meLabel . ', your turn'] : ['', 'Secretary', 'Logs and publishes'],
    ];
    ?>
    <article class="ap-feature">
        <div class="ap-feature-top">
            <?php ap_date_tile($b, 'light'); ?>
            <div class="ap-feature-id">
                <h3><?= br_h($b['program']) ?></h3>
                <p><?= ap_sub($b) ?></p>
            </div>
            <?php if ($since = ap_since($b)): ?>
                <span class="ap-arrived"><i></i><span class="br-age" data-since="<?= $since ?>" data-fmt="arrived">Arrived</span></span>
            <?php endif; ?>
        </div>
        <ol class="ap-chain">
            <?php foreach ($nodes as [$st, $name, $role]): ?>
                <li class="<?= $st ?>">
                    <span class="ap-node"><?php if ($st === 'done'): ?><i data-lucide="check"></i><?php elseif ($st === 'now'): ?><i class="ap-dot"></i><?php endif; ?></span>
                    <b><?= br_h($name) ?></b><small><?= br_h($role) ?></small>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="ap-feature-acts">
            <?php ap_action($b, $today, $csrf, true); ?>
            <a class="ap-btn ap-btn-ghost" href="?batch=<?= (int)$b['batch_id'] ?>">Read the request</a>
        </div>
    </article>
    <?php
}

/**
 * A compact card. $mode: 'mine' (action button), 'chain' (Nudge), 'returned', 'done'.
 * The three dots are the same chain as the big card, small.
 */
function ap_row(array $b, string $today, string $csrf, string $mode): void
{
    $coordDone = !empty($b['coord_at']) && $b['status'] !== 'Returned';
    $secDone   = !empty($b['sec_at']);
    $d = [ 'done',
           $b['status'] === 'Returned' ? 'bad' : ($coordDone ? 'done' : 'now'),
           $secDone ? 'done' : ($coordDone && $b['status'] !== 'Returned' ? 'now' : '') ];
    $where = $mode === 'done' ? 'Published' : ($b['status'] === 'Returned' ? 'With the Focal Person' : 'With the ' . fp_step_owner($b['status']));
    ?>
    <article class="ap-row <?= $mode ?>">
        <?php ap_date_tile($b, $mode === 'done' ? 'ok' : ''); ?>
        <a class="ap-row-main" href="?batch=<?= (int)$b['batch_id'] ?>">
            <h4><?= br_h($b['program']) ?></h4>
            <p><?= ap_sub($b) ?></p>
            <span class="ap-mini">
                <i class="<?= $d[0] ?>"></i><em class="<?= $d[1] === 'done' || $d[1] === 'now' ? 'on' : '' ?>"></em>
                <i class="<?= $d[1] ?>"></i><em class="<?= $d[2] === 'done' || $d[2] === 'now' ? 'on' : '' ?>"></em>
                <i class="<?= $d[2] ?>"></i>
                <span><?= $where ?></span>
            </span>
            <?php if ($mode === 'returned' && !empty($b['remarks'])): ?><q><?= br_h($b['remarks']) ?></q><?php endif; ?>
        </a>
        <div class="ap-row-act">
            <?php if ($mode === 'mine'): ap_action($b, $today, $csrf, false);
            elseif ($mode === 'chain'): ?>
                <form method="POST" action="focal_handler.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="nudge_batch">
                    <input type="hidden" name="batch_id" value="<?= (int)$b['batch_id'] ?>">
                    <button type="submit" class="ap-btn ap-btn-outline" title="Email a reminder to whoever holds this step"><i data-lucide="bell"></i>Nudge</button>
                </form>
            <?php elseif ($mode === 'done' && !empty($b['sec_name'])): ?>
                <span class="ap-by">Logged by <?= br_h($b['sec_name']) ?></span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
?>

    <header class="ap-head">
        <div>
            <span class="ap-eyebrow"><?= $br_phase ? ucfirst($br_phase) : 'Defense' ?></span>
            <h1><?= $br_view === 'published' ? 'Published dates' : ($br_view === 'returned' ? 'Sent back' : 'Defense approvals') ?></h1>
            <p><?php if ($br_view === 'published'): ?>Signed off all the way through &mdash; the groups can see these dates.
               <?php elseif ($br_view === 'returned'): ?>Back with the Focal Person. Nothing moves until she puts a new date forward.
               <?php else: ?>A Focal Person puts a section on a date, the Coordinator confirms it, then the Secretary logs the slots and publishes.<?php endif; ?></p>
        </div>
        <?php // The pill switches between THIS screen's three lists only. It used to be a
              // 1-2-3 stepper that navigated the whole window to Schedule / Checklist, which
              // have no way back here — the rail cards are the way between screens. ?>
        <nav class="ap-steps" aria-label="Which requests">
            <?php foreach ([['', 'Waiting', count($mine) + count($notMine)], ['returned', 'Sent back', count($returned)], ['published', 'Published', count($scheduled)]] as [$vv, $vl, $vn]): ?>
                <a class="<?= $br_view === $vv ? 'on' : '' ?>" href="<?= $br_url(['view' => $vv ?: null, 'when' => null, 'p' => null]) ?>"><?= $vl ?><?php if ($vn): ?><span><?= $vn ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
    </header>

    <div class="ap-bar">
        <span class="ap-who"><i data-lucide="user"></i>Signed in as <b><?= $ap_me_real ?></b></span>
    </div>

    <?php if ($flash): ?>
        <div class="ap-flash <?= $flash_type === 'error' ? 'bad' : '' ?>">
            <i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= br_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($br_view === 'returned'): ?>

        <?php if (!$returned): ?>
            <div class="nx">
                <?php $quill_pose = 'wink'; include __DIR__ . '/components/quill_empty.php'; ?>
                <h3>Nothing has bounced</h3>
                <p>A section lands here only when somebody sends it back to the Focal Person for a new date.</p>
            </div>
        <?php else: ?>
            <div class="ap-list"><?php foreach ($returned as $b) ap_row($b, $today, $csrf, 'returned'); ?></div>
        <?php endif; ?>

    <?php elseif ($br_view === 'published'): ?>

        <div class="ap-steps ap-seg-sm">
            <a class="<?= $br_when === 'up' ? 'on' : '' ?>" href="<?= $br_url(['when' => null, 'p' => null]) ?>">Coming up <span><?= count($br_pub_up) ?></span></a>
            <a class="<?= $br_when === 'past' ? 'on' : '' ?>" href="<?= $br_url(['when' => 'past', 'p' => null]) ?>">Already held <span><?= count($br_pub_past) ?></span></a>
        </div>
        <?php if (!$br_rows): ?>
            <div class="nx">
                <?php include __DIR__ . '/components/quill_empty.php'; ?>
                <h3><?= $br_when === 'past' ? 'No defense has been held yet' : 'Nothing published is still ahead' ?></h3>
                <p><?= $br_when === 'past' ? 'A published date moves here the day after it happens.' : 'Every published date has already passed &mdash; see <b>Already held</b>.' ?></p>
            </div>
        <?php else: ?>
            <div class="ap-list ap-list-2"><?php foreach ($br_rows as $b) ap_row($b, $today, $csrf, 'done'); ?></div>
            <?php if ($br_pages > 1): ?>
                <div class="br-page">
                    <a class="<?= $br_page <= 1 ? 'off' : '' ?>" href="<?= $br_url(['p' => max(1, $br_page - 1)]) ?>"><i data-lucide="chevron-left"></i>Previous</a>
                    <span><?= count($br_all) ?> in total &middot; page <?= $br_page ?> of <?= $br_pages ?></span>
                    <a class="<?= $br_page >= $br_pages ? 'off' : '' ?>" href="<?= $br_url(['p' => min($br_pages, $br_page + 1)]) ?>">Next<i data-lucide="chevron-right"></i></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php else: ?>

        <div class="ap-grid">
            <div class="ap-main">
                <section class="ap-sect">
                    <h2>On your desk <small><?= $canConfirm ? 'Confirm the date and send it to the Secretary.' : ($canFinalize ? 'Log the slots and publish the date to the groups.' : 'Nothing here is yours to sign.') ?></small></h2>
                    <?php if ($mine): ?>
                        <?php ap_feature($mine[0], $today, $csrf, $ap_me_real); ?>
                        <?php if (count($mine) > 1): ?>
                            <h3 class="ap-also">Also on your desk <span><?= count($mine) - 1 ?></span></h3>
                            <div class="ap-list"><?php foreach (array_slice($mine, 1) as $b) ap_row($b, $today, $csrf, 'mine'); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="ap-clear">
                            <?php $quill_size = 60; $quill_pose = 'wink'; include __DIR__ . '/components/quill_empty.php'; ?>
                            <div><b>Your desk is clear</b><span><?= $notMine ? 'Everything below is on someone else&rsquo;s desk.' : 'Sections appear here the moment one reaches your step.' ?></span></div>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($notMine): ?>
                <section class="ap-sect">
                    <h2>Further up the chain <small>Shown so you can follow along. Nothing to do yet.</small></h2>
                    <div class="ap-list" id="apChain">
                        <?php foreach ($notMine as $i => $b): ?>
                            <?php if ($i === 4): ?><div class="ap-more" hidden><?php endif; ?>
                            <?php ap_row($b, $today, $csrf, 'chain'); ?>
                        <?php endforeach; ?>
                        <?php if (count($notMine) > 4): ?></div>
                            <button type="button" class="ap-show" onclick="this.previousElementSibling.hidden=false;this.remove();">Show <?= count($notMine) - 4 ?> more</button>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>
            </div>

            <aside class="ap-side">
                <section class="ap-panel">
                    <h3>How a date gets approved</h3>
                    <ol class="ap-how">
                        <?php foreach ([['Focal person', 'Puts a section on a date'], ['Coordinator', 'Confirms the date'], ['Secretary', 'Logs the slots and publishes']] as $i => [$t, $s]):
                            $mineStep = ($i === 1 && $canConfirm) || ($i === 2 && $canFinalize); ?>
                            <li class="<?= $mineStep ? 'me' : '' ?>"><b><?= $i + 1 ?></b><span><strong><?= $t ?><?= $mineStep ? ', you' : '' ?></strong><small><?= $s ?></small></span></li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <section class="ap-panel">
                    <div class="ap-panel-head"><h3>Recently published</h3>
                        <?php if ($scheduled): ?><a href="<?= $br_url(['view' => 'published']) ?>">See all <?= count($scheduled) ?></a><?php endif; ?></div>
                    <?php if (!$ap_recent): ?><p class="ap-none">Nothing published yet.</p><?php endif; ?>
                    <?php foreach ($ap_recent as $b): $ts = strtotime((string)$b['defense_date']); ?>
                        <a class="ap-pub" href="?batch=<?= (int)$b['batch_id'] ?>">
                            <span class="ap-date ok sm"><b><?= date('j', $ts) ?></b><small><?= date('M', $ts) ?></small></span>
                            <span class="ap-pub-body"><b><?= br_h($b['program']) ?></b><small>Logged by <?= br_h($b['sec_name'] ?: 'the Secretary') ?></small></span>
                            <?php if (!empty($b['sec_today'])): ?><span class="ap-today">Today</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </section>
            </aside>
        </div>

    <?php endif; ?>

<?php else: /* ============================================== ONE BUNDLE, OPENED ==== */ ?>
<?php
/*
 * One request, opened (2026-09-29 redesign, from the Claude Design concept): the section and
 * its date, the chain with who signed and when, what is being asked (groups in slot order with
 * their checklist progress), and a timeline. When it is YOUR step the decision sits right under
 * the chain — same POSTs as before: confirm_batch / return_batch, and "Log the slots" is still
 * the hand-off to the Scheduling screen, where the calendar is.
 */
$bd_ts   = strtotime((string)$batch['defense_date']);
$bd_days = (int)round(($bd_ts - strtotime($today)) / 86400);   // both plain dates: no clock skew
$bd_when = $bd_days === 0 ? 'Today' : ($bd_days === 1 ? 'Tomorrow'
         : ($bd_days > 1 ? 'In ' . $bd_days . ' days' : ($bd_days === -1 ? 'Yesterday' : abs($bd_days) . ' days ago')));
$bd_st   = $batch['status'];
$bd_chip = [
    'Scheduled' => ['ok',   'check',          'Published'],
    'Confirmed' => ['wait', 'hourglass',      $myStep ? 'Your turn' : 'With the Secretary'],
    'Endorsed'  => ['wait', 'hourglass',      $myStep ? 'Your turn' : 'With the Coordinator'],
    'Returned'  => ['bad',  'corner-up-left', 'Sent back'],
    'Cancelled' => ['off',  'x',              'Cancelled'],
][$bd_st] ?? ['off', 'circle', $bd_st];
$bd_you  = $role === 'Research Coordinator' ? 'Coordinator' : 'Secretary';
$bd_name = function (?string $n): string {
    return $n === 'Removed account' ? '<i>Account removed</i>' : br_h($n ?: '—');
};
// The chain: [label, name html, line under it, state]
$coordDone = !empty($batch['coord_at']) && $bd_st !== 'Returned';
$secDone   = !empty($batch['sec_at']);
$bd_nodes = [
    ['Focal person', $bd_name($batch['focal_name']), 'Requested ' . br_h($batch['focal_md'] ?: ''), 'done'],
    ['Coordinator',
        $coordDone ? $bd_name($batch['coord_name']) : ($bd_st === 'Endorsed' && $myStep ? 'You' : 'Waiting'),
        $coordDone ? 'Confirmed ' . br_h($batch['coord_md']) : ($bd_st === 'Returned' ? 'Sent back ' . br_h($batch['returned_md']) : ($bd_st === 'Endorsed' ? ($myStep ? 'Your turn' : 'Their move now') : '')),
        $coordDone ? 'done' : ($bd_st === 'Returned' ? 'bad' : ($bd_st === 'Endorsed' ? 'now' : ''))],
    ['Secretary',
        $secDone ? $bd_name($batch['sec_name']) : ($bd_st === 'Confirmed' && $myStep ? 'You' : 'Waiting'),
        $secDone ? 'Logged and published ' . br_h($batch['sec_md']) : ($bd_st === 'Confirmed' ? ($myStep ? 'Your turn' : 'Their move now') : ''),
        $secDone ? 'done' : ($bd_st === 'Confirmed' ? 'now' : '')],
];
// Groups still short of a full checklist — the thing worth a warning, not a footnote.
$bd_open = [];
foreach ($rows as $r) {
    $rd = $ready[(int)$r['user_id']] ?? null;
    if ($rd && !$rd['ready']) $bd_open[] = [$r, $rd['total'] - $rd['done']];
}
?>

    <div class="bd-top">
        <a class="bd-back" href="admin_batch_review.php<?= $br_phase ? '?phase=' . $br_phase : '' ?>"><i data-lucide="arrow-left"></i>All requests</a>
        <span class="bd-status <?= $bd_chip[0] ?>"><i data-lucide="<?= $bd_chip[1] ?>"></i><?= $bd_chip[2] ?></span>
    </div>

    <section class="bd-hero">
        <div class="bd-hero-id">
            <h1><?= br_h($batch['program']) ?></h1>
            <div class="bd-tags">
                <span><?= $batch['year_level'] ? br_h($batch['year_level']) . ', ' : '' ?>Section <?= br_h($batch['label']) ?></span>
                <span><?= ucfirst(df_phase_label($batch['phase'])) ?></span>
                <span><?= aa_school_tag($batch['department']) ?></span>
            </div>
        </div>
        <div class="bd-datecard">
            <span class="bd-tile"><small><?= date('D', $bd_ts) ?></small><b><?= date('j', $bd_ts) ?></b><small><?= date('M', $bd_ts) ?></small></span>
            <div>
                <small>Defense date</small>
                <b><?= date('l, j F Y', $bd_ts) ?></b>
                <span class="bd-when <?= $bd_days === 0 ? 'today' : ($bd_days < 0 ? 'past' : '') ?>"><?= $bd_when ?></span>
            </div>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="ap-flash <?= $flash_type === 'error' ? 'bad' : '' ?>">
            <i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i><?= br_h($flash) ?>
        </div>
    <?php endif; ?>

    <section class="bd-card bd-chain">
        <?php foreach ($bd_nodes as $i => [$lab, $nm, $sub, $st]): ?>
            <div class="bd-step <?= $st ?>">
                <div class="bd-rail">
                    <span class="bd-node"><?php if ($st === 'done'): ?><i data-lucide="check"></i><?php elseif ($st === 'bad'): ?><i data-lucide="corner-up-left"></i><?php elseif ($st === 'now'): ?><i class="ap-dot"></i><?php endif; ?></span>
                    <?php if ($i < 2): ?><span class="bd-line <?= $bd_nodes[$i + 1][3] === 'done' ? 'done' : '' ?>"></span><?php endif; ?>
                </div>
                <small><?= $lab ?></small>
                <b><?= $nm ?></b>
                <?php if ($sub): ?><span><?= $sub ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($bd_st === 'Returned'): ?>
        <div class="bd-note bad"><i data-lucide="corner-up-left"></i>
            <span>Sent back by <b><?= br_h($batch['returned_name'] ?: 'the office') ?></b>: <?= br_h($batch['remarks']) ?></span></div>
    <?php endif; ?>

    <?php /* The decision — only on your own step, right under the chain it moves. */ ?>
    <?php if ($myStep): ?>
        <section class="bd-card bd-act">
            <div class="bd-act-copy">
                <h2><?= $bd_st === 'Endorsed' ? 'Confirm this date' : 'Log the slots and publish' ?></h2>
                <p><?= $bd_st === 'Endorsed'
                    ? 'Confirming passes it to the Secretary. Nothing is published to the groups yet.'
                    : 'Logging happens on the ' . ucfirst($batch['phase']) . ' Scheduling screen, next to the calendar, where you tick which groups go in.' ?></p>
            </div>
            <div class="bd-act-btns">
                <?php if ($stale): ?>
                    <span class="bd-stale"><i data-lucide="alert-triangle"></i>This date has passed &mdash; send it back so a new one can be set.</span>
                <?php elseif ($bd_st === 'Endorsed'): ?>
                    <form method="POST" action="focal_handler.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="confirm_batch">
                        <input type="hidden" name="batch_id" value="<?= (int)$batch['batch_id'] ?>">
                        <button type="submit" class="ap-btn ap-btn-solid bd-go"><i data-lucide="check"></i>Confirm &mdash; send to the Secretary</button>
                    </form>
                <?php else: ?>
                    <a class="ap-btn ap-btn-solid bd-go" href="admin_module_dynamic.php?phase=<?= br_h($batch['phase']) ?>&view=schedule&batch=<?= (int)$batch['batch_id'] ?>&from=approvals">
                        <i data-lucide="calendar-check"></i>Log the slots in <?= ucfirst($batch['phase']) ?> Scheduling</a>
                <?php endif; ?>
                <details class="bd-send">
                    <summary>Send it back instead</summary>
                    <form method="POST" action="focal_handler.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="return_batch">
                        <input type="hidden" name="batch_id" value="<?= (int)$batch['batch_id'] ?>">
                        <textarea name="remarks" required placeholder="What needs changing? The Focal Person sees this."></textarea>
                        <button type="submit" class="ap-btn ap-btn-outline">Send back</button>
                    </form>
                </details>
            </div>
        </section>
    <?php endif; ?>

    <div class="bd-grid">
        <section class="bd-card">
            <h2>What&rsquo;s being asked</h2>
            <?php /* Only the groups she put forward — the ones she left out are hers to explain. */ ?>
            <p class="bd-lead"><?= br_h($batch['focal_name'] ?: 'The focal person') ?> is putting
                <?= count($rows) ?> group<?= count($rows) === 1 ? '' : 's' ?> from
                <?= $batch['year_level'] ? br_h($batch['year_level']) . ', ' : '' ?>Section <?= br_h($batch['label']) ?> on this day, in this order.</p>

            <div class="bd-groups">
                <?php foreach ($rows as $r): $rd = $ready[(int)$r['user_id']] ?? ['done' => 0, 'total' => 0, 'ready' => false]; ?>
                    <div class="bd-g">
                        <span class="bd-slot"><small>Slot</small><b><?= $r['slot_no'] ? (int)$r['slot_no'] : '–' ?></b></span>
                        <div class="bd-g-id">
                            <b><?= br_h($r['research_group_name']) ?></b>
                            <span><?= br_h($r['username']) ?></span>
                        </div>
                        <div class="bd-prog <?= $rd['ready'] ? 'ok' : '' ?>">
                            <span class="bd-segs"><?php for ($k = 0; $k < $rd['total']; $k++): ?><i class="<?= $k < $rd['done'] ? 'on' : '' ?>"></i><?php endfor; ?></span>
                            <small><?= (int)$rd['done'] ?> of <?= (int)$rd['total'] ?> checklist items</small>
                        </div>
                        <a class="ap-btn ap-btn-outline bd-files" target="_blank" rel="noopener"
                           href="admin_module_dynamic.php?phase=<?= br_h($batch['phase']) ?>&group=<?= (int)$r['user_id'] ?>"><i data-lucide="file-text"></i>Files</a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($bd_open && !in_array($bd_st, ['Returned', 'Cancelled'], true) && $bd_days >= 0):
                $bd_n = count($bd_open); $bd_items = array_sum(array_column($bd_open, 1));
                $bd_day = $bd_days === 0 ? 'today' : ($bd_days === 1 ? 'tomorrow' : 'on ' . date('M j', $bd_ts)); ?>
                <div class="bd-note warn"><i data-lucide="alert-triangle"></i>
                    <span><b><?= $bd_n === 1 ? 'This group defends' : $bd_n . ' groups defend' ?> <?= $bd_day ?> with <?= $bd_items ?> checklist item<?= $bd_items === 1 ? '' : 's' ?> still open.</b>
                        You may want to check with the Focal Person.</span>
                    <a target="_blank" rel="noopener" href="admin_module_dynamic.php?phase=<?= br_h($batch['phase']) ?><?= $bd_n === 1 ? '&group=' . (int)$bd_open[0][0]['user_id'] : '' ?>">Open checklist</a>
                </div>
            <?php endif; ?>
        </section>

        <aside class="bd-card">
            <h2>Timeline</h2>
            <ol class="bd-tl">
                <li class="on"><b>Date requested</b><small><?= br_h($batch['focal_name'] ?: 'Focal person') ?>, <?= br_h($batch['focal_md']) ?></small></li>
                <?php if ($bd_st === 'Returned'): ?>
                    <li class="bad"><b>Sent back</b><small><?= br_h($batch['returned_name'] ?: 'The office') ?>, <?= br_h($batch['returned_md']) ?></small></li>
                <?php else: ?>
                    <li class="<?= $coordDone ? 'on' : 'todo' ?>"><b><?= $coordDone ? 'Confirmed by the Coordinator' : 'Coordinator to confirm' ?></b>
                        <small><?= $coordDone ? br_h($batch['coord_md']) : 'Not yet' ?></small></li>
                    <li class="<?= $secDone ? 'ok' : 'todo' ?>"><b><?= $secDone ? 'Slots logged and published' : 'Secretary to log and publish' ?></b>
                        <small><?= $secDone ? br_h($batch['sec_name'] ?: 'The Secretary') . ', ' . br_h($batch['sec_md']) . '. The group can see their date and slot.' : 'Not yet' ?></small></li>
                <?php endif; ?>
                <li class="day <?= $bd_days === 0 ? 'today' : '' ?>"><b>Defense day</b><small><?= $bd_days === 0 ? 'Today, ' : '' ?><?= date('M j', $bd_ts) ?></small></li>
            </ol>
        </aside>
    </div>

<?php endif; ?>

<script>
    // Server clock at render time; the ticker counts forward from it.
    var BR_NOW = <?= time() ?>;
    // How long a request has been on this desk. The number is the point of this screen, so it
    // is rendered from the timestamp on the client and ticks rather than being baked in at
    // page-render time and going stale the moment you leave the tab open.
    function brAge(seconds) {
        if (seconds < 90) return 'just now';
        var m = Math.floor(seconds / 60);
        if (m < 60) return m + 'm';
        var h = Math.floor(m / 60);
        if (h < 48) return h + 'h';
        return Math.floor(h / 24) + 'd';
    }

    function brTick() {
        // Server time, not the browser's: PHP runs Europe/Berlin here while the office is in
        // Manila, so a client-side "now" would read hours out (see CLAUDE.md).
        var now = BR_NOW + Math.floor((Date.now() - BR_LOADED) / 1000);
        document.querySelectorAll('.br-age').forEach(function (el) {
            var since = parseInt(el.dataset.since || '0', 10);
            if (!since) return;
            var age = brAge(Math.max(0, now - since));
            el.textContent = el.dataset.fmt === 'arrived' ? (age === 'just now' ? 'Arrived just now' : 'Arrived ' + age + ' ago')
                           : (el.closest('.br-banner') ? age : 'waiting ' + age);
        });
    }
    var BR_LOADED = Date.now();
    brTick();
    setInterval(brTick, 60000);

    if (window.lucide) lucide.createIcons();
</script>
</body>

</html>
