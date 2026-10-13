<?php
// dashboards/office_calendar.php — the Office calendar (2026-09-29, from the Claude Design concept).
//
// Replaces the shells' in-page "Institutional Calendar" view. One job: say which days the office
// is OUT, because a closed day cannot hold a defense. Opened as a module window from Workspace.
//
// Writes go to the existing handlers, nothing new is stored:
//   close a day    -> calendar_handler.php  action=add         (the office event students already
//                     see on their calendar, which also closes the day for BOTH defenses)
//   reopen a day   -> calendar_handler.php  action=reopen_day  (drops the event(s) + every closure)
//   PH holidays    -> defense_handler.php   action=import_holidays, phase=both
// A day a group already holds is never closed from here (both handlers refuse it too).
//
// The month is a FIXED 42 cells, like both scheduling calendars: a 5-week month beside a 6-week
// one changed the card height and the page jumped.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/defense_lib.php';
require_once __DIR__ . '/admin_accounts_lib.php';

$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['Research Coordinator', 'Research Director'], true)) {
    exit('Access Denied');
}

$today = df_today();
$ym = (string)($_GET['ym'] ?? substr($today, 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = substr($today, 0, 7);
$first = new DateTime($ym . '-01');
$sel = (string)($_GET['d'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sel)) $sel = (substr($today, 0, 7) === $ym) ? $today : $first->format('Y-m-d');

// 42 cells from the Sunday on/before the 1st.
$start = (clone $first)->modify('-' . (int)$first->format('w') . ' days');
$end   = (clone $start)->modify('+41 days');
$from  = $start->format('Y-m-d');
$to    = $end->format('Y-m-d');

// Closures in view + everything the side list needs (upcoming, and a few past).
$bo = $pdo->prepare("SELECT block_date, phase, reason, source FROM defense_blackouts WHERE block_date BETWEEN ? AND ?");
$ev = $pdo->prepare("SELECT event_id, title, description, event_date FROM calendar_events WHERE event_date BETWEEN ? AND ?");
$bk = $pdo->prepare("SELECT b.defense_date, b.phase, u.department, COUNT(*) n FROM defense_bookings b JOIN users u ON u.user_id = b.user_id
                     WHERE b.defense_date BETWEEN ? AND ? AND b.status IN ('Date Selected','Scheduled') GROUP BY b.defense_date, b.phase, u.department");

/** date => ['phases' => [...], 'reason' => str, 'source' => str, 'events' => [...], 'booked' => n, 'by_school' => [...]] */
function oc_days(PDOStatement $bo, PDOStatement $ev, PDOStatement $bk, string $a, string $b): array
{
    $days = [];
    $bo->execute([$a, $b]);
    foreach ($bo->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = &$days[$r['block_date']];
        $d['phases'][] = $r['phase'];
        if (empty($d['reason']) && $r['reason']) $d['reason'] = preg_replace('/^Office calendar:\s*/', '', $r['reason']);
        $d['source'] = $d['source'] ?? $r['source'];
        unset($d);
    }
    $ev->execute([$a, $b]);
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days[$r['event_date']]['events'][] = $r;
    }
    $bk->execute([$a, $b]);
    foreach ($bk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = &$days[$r['defense_date']];
        $d['booked'] = ($d['booked'] ?? 0) + (int)$r['n'];
        $tag = $r['department'] ? aa_school_tag($r['department']) : '?';
        $d['by_school'][$tag] = ($d['by_school'][$tag] ?? 0) + (int)$r['n'];
        unset($d);
    }
    return $days;
}
$days = oc_days($bo, $ev, $bk, $from, $to);

/** What to call a closed day, and for which defenses. */
function oc_label(array $d): string
{
    if (!empty($d['events'])) return $d['events'][0]['title'];
    return $d['reason'] ?? 'Closed';
}
function oc_scope(array $d): string
{
    $p = array_unique($d['phases'] ?? []);
    if (count($p) >= 2) return 'Both defenses';
    if ($p === ['proposal']) return 'Proposal only';
    if ($p === ['final']) return 'Final only';
    return 'Office event';
}
function oc_closed(array $d): bool { return !empty($d['phases']) || !empty($d['events']); }

// Side list: closures from the calendar's whole horizon, not just this month.
$list_from = (new DateTime($today))->modify('-60 days')->format('Y-m-d');
$list_to   = (new DateTime($today))->modify('+18 months')->format('Y-m-d');
$all = oc_days($bo, $ev, $bk, $list_from, $list_to);
ksort($all);
$upcoming = $past = [];
foreach ($all as $date => $d) {
    if (!oc_closed($d)) continue;
    if ($date >= $today) $upcoming[$date] = $d; else $past[$date] = $d;
}
$past = array_slice(array_reverse($past, true), 0, 4, true);

$sd = $days[$sel] ?? [];
$sel_dt = new DateTime($sel);
$prev = (clone $first)->modify('-1 month')->format('Y-m');
$next = (clone $first)->modify('+1 month')->format('Y-m');

$flash = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
$self = 'office_calendar.php?ym=' . $ym . '&d=' . $sel;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office calendar</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .oc { --disp: "Outfit", var(--font-ui); padding: 22px 26px 40px; max-width: 1320px; margin: 0 auto; }
        .oc-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .oc-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .oc-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .oc-sub { font-size: 13px; color: var(--muted); margin: 0; }
        .oc-pill { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px;
                   background: var(--surface-3); color: var(--ink); font-size: 12.5px; font-weight: 700; }
        .oc-pill:hover { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); }
        .oc-pill i, .oc-pill svg { width: 15px; height: 15px; }
        .oc-flash { padding: 11px 16px; border-radius: 14px; margin-bottom: 14px; font-size: 13px; font-weight: 600;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .oc-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }

        .oc-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
        .oc-cal { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; padding: 18px 18px 20px; }
        .oc-cal-top { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .oc-nav { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: var(--surface-3); color: var(--ink); text-decoration: none; }
        .oc-nav i, .oc-nav svg { width: 17px; height: 17px; }
        .oc-month { font-family: var(--disp); font-size: 22px; font-weight: 700; color: var(--ink); min-width: 170px; text-align: center; }
        .oc-legend { margin-left: auto; display: flex; gap: 14px; font-size: 11.5px; color: var(--muted); flex-wrap: wrap; }
        .oc-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .oc-sw { width: 14px; height: 10px; border-radius: 3px; }
        .oc-sw.closed { background: repeating-linear-gradient(135deg, color-mix(in srgb, var(--danger) 35%, transparent) 0 3px, transparent 3px 6px); }
        .oc-sw.booked { width: 9px; height: 9px; border-radius: 50%; background: var(--accent); }
        .oc-sw.today { width: 11px; height: 11px; border: 2px solid var(--ink); border-radius: 3px; box-sizing: border-box; }
        .oc-dow, .oc-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
        .oc-dow div { font-size: 11.5px; font-weight: 700; color: var(--muted); padding: 0 4px 6px; }
        .oc-day { position: relative; min-height: 84px; border-radius: 14px; background: var(--surface-2); border: 1.5px solid transparent; padding: 8px;
                  text-decoration: none; color: var(--ink); display: flex; flex-direction: column; gap: 4px; box-sizing: border-box; }
        .oc-day:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--line)); }
        .oc-day b { font-size: 14px; font-weight: 700; }
        .oc-day.out { opacity: .38; }
        .oc-day.wkend { background: var(--surface-3); }
        .oc-day.past b { color: var(--muted); }
        .oc-day.today { border-color: var(--ink); }
        .oc-day.sel { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
        .oc-day.closed { background: repeating-linear-gradient(135deg, color-mix(in srgb, var(--danger) 13%, var(--surface)) 0 7px, var(--surface) 7px 14px); }
        .oc-day.closed b { color: color-mix(in srgb, var(--danger) 75%, var(--ink)); }
        .oc-day .why { font-size: 10.5px; font-weight: 700; color: color-mix(in srgb, var(--danger) 70%, var(--ink)); line-height: 1.25;
                       display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .oc-chip { align-self: flex-start; display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
                   background: color-mix(in srgb, var(--accent) 14%, var(--surface)); color: var(--ink); }
        .oc-chip::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }

        .oc-side { display: flex; flex-direction: column; gap: 12px; position: sticky; top: 12px; }
        .oc-daycard { background: var(--surface-3); border-radius: 24px; padding: 18px; }
        .oc-dc-top { display: flex; gap: 14px; align-items: center; margin-bottom: 14px; }
        .oc-tile { width: 58px; height: 64px; border-radius: 16px; background: var(--sh-hero-b, var(--ink)); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0; }
        .oc-tile small { font-size: 10.5px; opacity: .8; font-weight: 600; }
        .oc-tile b { font-family: var(--disp); font-size: 24px; line-height: 1; }
        .oc-dc-name { font-family: var(--disp); font-size: 17px; font-weight: 700; color: var(--ink); }
        .oc-dc-state { font-size: 12.5px; font-weight: 700; margin-top: 2px; color: var(--muted); }
        .oc-dc-state.closed { color: color-mix(in srgb, var(--danger) 72%, var(--ink)); }
        .oc-dc-state.open { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .oc-note { font-size: 12.5px; color: var(--muted); line-height: 1.5; margin: 0 0 12px; }
        .oc-in { width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 14px; border: 1.5px solid transparent; background: var(--surface); color: var(--ink);
                 font: inherit; font-size: 13px; outline: none; margin-bottom: 8px; }
        .oc-in:focus { border-color: var(--accent); }
        .oc-btn { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; text-align: center; padding: 12px 16px; border-radius: 999px; font-size: 13px; font-weight: 750;
                  background: var(--accent); color: var(--accent-ink); }
        .oc-btn.ghost { background: var(--surface); color: var(--ink); border: 1.5px solid var(--line); }
        .oc-btn:hover { filter: brightness(1.06); }
        .oc-list { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 16px; }
        .oc-list h4 { margin: 0 0 8px; font-size: 13.5px; font-weight: 750; color: var(--ink); display: flex; justify-content: space-between; }
        .oc-list h4 small { font-weight: 600; color: var(--muted); font-size: 11.5px; }
        .oc-row { display: flex; gap: 12px; align-items: center; padding: 8px 6px; border-radius: 12px; text-decoration: none; color: inherit; }
        .oc-row:hover { background: var(--surface-2); }
        .oc-row .dt { width: 38px; height: 40px; border-radius: 10px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
                      background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 72%, var(--ink)); }
        .oc-row .dt b { font-size: 14px; line-height: 1; } .oc-row .dt small { font-size: 9.5px; font-weight: 700; }
        .oc-row.past { opacity: .55; }
        .oc-row .tx { flex: 1; min-width: 0; }
        .oc-row .tx b { display: block; font-size: 12.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .oc-row .tx small { font-size: 11px; color: var(--muted); }
        .oc-row .tag { font-size: 10.5px; color: var(--muted); }
        .oc-more { all: unset; cursor: pointer; font-size: 12px; font-weight: 700; color: var(--accent); padding: 6px; }
        .oc-empty { font-size: 12.5px; color: var(--muted); padding: 6px; }
        @media (max-width: 1000px) { .oc-grid { grid-template-columns: 1fr; } .oc-side { position: static; } }
        @media (max-width: 640px) {
            .oc { padding: 16px 12px 30px; } .oc-title { font-size: 28px; }
            .oc-dow, .oc-days { gap: 4px; } .oc-day { min-height: 54px; padding: 5px; border-radius: 10px; }
            .oc-day .why, .oc-chip { display: none; }
            .oc-day.has-bk::after { content: ""; position: absolute; left: 50%; bottom: 6px; width: 6px; height: 6px; margin-left: -3px; border-radius: 50%; background: var(--accent); }
            .oc-legend { margin-left: 0; }
        }
    </style>
</head>
<body class="admin-module">
<div class="oc">
    <div class="oc-head">
        <div>
            <div class="oc-eyebrow">Workspace</div>
            <h1 class="oc-title">Office calendar</h1>
            <p class="oc-sub">Close the days the office is out, like holidays or school events. Groups can't be booked on a closed day.</p>
        </div>
        <form method="POST" action="defense_handler.php" onsubmit="return confirm('Close every Philippine public holiday for both defenses? Days a group already holds are skipped.');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="import_holidays">
            <input type="hidden" name="phase" value="both">
            <button type="submit" class="oc-pill"><i data-lucide="flag"></i> Close all PH public holidays</button>
        </form>
    </div>

    <?php if ($flash): ?><div class="oc-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <div class="oc-grid">
        <section class="oc-cal">
            <div class="oc-cal-top">
                <a class="oc-nav" href="office_calendar.php?ym=<?= $prev ?>" aria-label="Previous month"><i data-lucide="chevron-left"></i></a>
                <div class="oc-month"><?= $first->format('F Y') ?></div>
                <a class="oc-nav" href="office_calendar.php?ym=<?= $next ?>" aria-label="Next month"><i data-lucide="chevron-right"></i></a>
                <?php if ($ym !== substr($today, 0, 7)): ?><a class="oc-pill" style="padding:7px 12px" href="office_calendar.php">Today</a><?php endif; ?>
                <div class="oc-legend">
                    <span><i class="oc-sw closed"></i>Closed</span>
                    <span><i class="oc-sw booked"></i>Has defenses</span>
                    <span><i class="oc-sw today"></i>Today</span>
                </div>
            </div>
            <div class="oc-dow"><?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $w): ?><div><?= $w ?></div><?php endforeach; ?></div>
            <div class="oc-days">
                <?php for ($i = 0, $c = clone $start; $i < 42; $i++, $c->modify('+1 day')):
                    $d = $c->format('Y-m-d'); $info = $days[$d] ?? []; $closed = oc_closed($info);
                    $cls = [];
                    if ($c->format('Y-m') !== $ym) $cls[] = 'out';
                    if ((int)$c->format('w') === 0 || (int)$c->format('w') === 6) $cls[] = 'wkend';
                    if ($d < $today) $cls[] = 'past';
                    if ($d === $today) $cls[] = 'today';
                    if ($d === $sel) $cls[] = 'sel';
                    if ($closed) $cls[] = 'closed';
                    if (!empty($info['booked'])) $cls[] = 'has-bk';
                ?>
                    <a class="oc-day <?= implode(' ', $cls) ?>" href="office_calendar.php?ym=<?= $ym ?>&d=<?= $d ?>">
                        <b><?= (int)$c->format('j') ?></b>
                        <?php if ($closed): ?><span class="why"><?= htmlspecialchars(oc_label($info)) ?></span><?php endif; ?>
                        <?php if (!empty($info['booked'])): ?><span class="oc-chip"><?= $d < $today ? 'Held' : ((int)$info['booked'] . ' defense' . ((int)$info['booked'] === 1 ? '' : 's')) ?></span><?php endif; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </section>

        <aside class="oc-side">
            <div class="oc-daycard">
                <div class="oc-dc-top">
                    <div class="oc-tile"><small><?= $sel_dt->format('D') ?></small><b><?= $sel_dt->format('j') ?></b><small><?= $sel_dt->format('M') ?></small></div>
                    <div>
                        <div class="oc-dc-name"><?= $sel_dt->format('l, F j') ?></div>
                        <?php if (oc_closed($sd)): ?>
                            <div class="oc-dc-state closed">Closed: <?= htmlspecialchars(oc_label($sd)) ?> (<?= oc_scope($sd) ?>)</div>
                        <?php elseif ($sel < $today): ?>
                            <div class="oc-dc-state">This day has passed</div>
                        <?php else: ?>
                            <div class="oc-dc-state open">Open for defenses</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($sd['booked'])): ?>
                    <p class="oc-note"><b><?= (int)$sd['booked'] ?> defense<?= (int)$sd['booked'] === 1 ? '' : 's' ?></b> on this day
                        (<?= htmlspecialchars(implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($sd['by_school']), $sd['by_school']))) ?>).
                        <?php if ($sel >= $today && !oc_closed($sd)): ?>A booked day can't be closed from here &mdash; move those groups first, in Scheduling.<?php endif; ?></p>
                <?php endif; ?>

                <?php if ($sel >= $today): ?>
                    <?php if (oc_closed($sd)): ?>
                        <?php if (!empty($sd['events'][0]['description'])): ?><p class="oc-note"><?= htmlspecialchars($sd['events'][0]['description']) ?></p><?php endif; ?>
                        <form method="POST" action="calendar_handler.php" onsubmit="return confirm('Reopen <?= $sel_dt->format('F j') ?>? Groups can be booked on it again, and it leaves the students\' calendar.');">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="reopen_day">
                            <input type="hidden" name="event_date" value="<?= $sel ?>">
                            <button type="submit" class="oc-btn ghost">Reopen this day</button>
                        </form>
                    <?php elseif (empty($sd['booked'])): ?>
                        <form method="POST" action="calendar_handler.php">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="event_date" value="<?= $sel ?>">
                            <input class="oc-in" name="title" required maxlength="140" placeholder="Why is the office out? e.g. Foundation Day">
                            <input class="oc-in" name="description" maxlength="250" placeholder="A note for the students (optional)">
                            <button type="submit" class="oc-btn">Close this day</button>
                        </form>
                        <p class="oc-note" style="margin:10px 0 0">Closes it for both defenses and shows it on the students' calendar.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="oc-list">
                <h4>Closures <small><?= count($upcoming) ?> coming up</small></h4>
                <?php $i = 0; foreach ($upcoming as $date => $d): $dt = new DateTime($date); ?>
                    <a class="oc-row" href="office_calendar.php?ym=<?= $dt->format('Y-m') ?>&d=<?= $date ?>" <?= $i++ >= 6 ? 'data-more hidden' : '' ?>>
                        <span class="dt"><b><?= $dt->format('j') ?></b><small><?= $dt->format('M') ?></small></span>
                        <span class="tx"><b><?= htmlspecialchars(oc_label($d)) ?></b><small><?= $dt->format('l') ?> &middot; <?= oc_scope($d) ?></small></span>
                    </a>
                <?php endforeach; ?>
                <?php if (count($upcoming) > 6): ?><button type="button" class="oc-more" onclick="document.querySelectorAll('[data-more]').forEach(function(r){r.hidden=false});this.remove()">Show all <?= count($upcoming) ?></button><?php endif; ?>
                <?php if (!$upcoming): ?><div class="oc-empty">No closures coming up.</div><?php endif; ?>
                <?php foreach ($past as $date => $d): $dt = new DateTime($date); ?>
                    <a class="oc-row past" href="office_calendar.php?ym=<?= $dt->format('Y-m') ?>&d=<?= $date ?>">
                        <span class="dt"><b><?= $dt->format('j') ?></b><small><?= $dt->format('M') ?></small></span>
                        <span class="tx"><b><?= htmlspecialchars(oc_label($d)) ?></b><small><?= $dt->format('l') ?></small></span>
                        <span class="tag">Past</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</div>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
