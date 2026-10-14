<?php
// schedule.php — the PUBLIC defense schedule (2026-10-14). No login. The same month calendar students get
// (assets/js/defense_calendar.js) fed only what is fit for a public page: published defense dates by SECTION
// (course, year, room, phase, institution, number of groups), office closures and public holidays. Never a
// student's name or a research title, and not the staff's internal school events.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/dashboards/defense_lib.php';
require_once __DIR__ . '/dashboards/schedule_lib.php';

$from  = date('Y-m-01', strtotime(df_today() . ' -2 months'));
$to    = df_window_end();
$items = sch_calendar_items(sch_published($pdo, $from, $to));
$closed = [];
foreach (['proposal', 'final'] as $ph) {
    foreach (df_blackouts($pdo, $ph, $from, $to) as $date => $bo) $closed[$date] = $bo['reason'] ?: 'The research office is closed on this date.';
}
foreach ($closed as $date => $why) $items[] = ['date' => $date, 'kind' => 'closed', 'short' => 'Closed', 'title' => 'Research office closed', 'sub' => $why];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <title>Defense schedule · iSubmit</title>
    <meta name="description" content="Published research defense dates of MCNP and ISAP, by section.">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/defense_calendar.css?v=<?= filemtime(__DIR__ . '/assets/css/defense_calendar.css') ?>">
    <style>
        :root { --accent: #5b50f5; --chip-bg: #f6f5fd; --border-line: #e6e4f3; --text-muted: #5d5a78; --text-dark: #1c1838; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f6fd; color: #1c1838; font: 400 15px/1.6 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .bar { background: #5b50f5; color: #fff; }
        .bar .in { max-width: 1180px; margin: 0 auto; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .lockup { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; }
        .lockup img { width: 32px; height: 38px; object-fit: contain; }
        .lockup b { font: 800 19px 'Poppins', sans-serif; letter-spacing: -.02em; }
        .bar nav { display: flex; gap: 14px; align-items: center; }
        .bar nav a { color: #fff; font-size: 14px; font-weight: 600; }
        .bar nav a.pill { text-decoration: none; background: #fff; color: #3f35c9; padding: 7px 14px; border-radius: 999px; font-weight: 700; }
        main { max-width: 1180px; margin: 0 auto; padding: 32px 20px 56px; }
        h1 { font: 800 clamp(28px, 4.4vw, 38px)/1.15 'Poppins', sans-serif; letter-spacing: -.02em; margin: 0 0 6px; }
        .lead { color: #5d5a78; margin: 0 0 22px; max-width: 760px; }
        .dc-month { font-family: 'Poppins', sans-serif; }
        .fine { color: #5d5a78; font-size: 13px; margin-top: 16px; }
        @media (max-width: 560px) { .bar nav a:not(.pill) { display: none; } }
    </style>
</head>
<body>
<div class="bar"><div class="in">
    <a class="lockup" href="./"><img src="assets/images/mascot.png" alt=""><b>iSubmit</b></a>
    <nav><a href="./">Home</a><a href="privacy.php">Privacy</a><a class="pill" href="auth/login.php">Sign in</a></nav>
</div></div>
<main>
    <h1>Defense schedule</h1>
    <p class="lead">Research defense dates published by the Research &amp; Development Center for MCNP and ISAP, by section. Tap a day to see which rooms defend.
        Signed-in students also see their own group's date.</p>
    <div id="cal"></div>
    <p class="fine">Only published dates appear here. Sections are shown, never names or research titles. Groups are heard in slot order on the day.</p>
</main>
<script src="assets/js/defense_calendar.js?v=<?= filemtime(__DIR__ . '/assets/js/defense_calendar.js') ?>"></script>
<script>
    const cal = DefenseCalendar.mount(document.getElementById('cal'), {
        items: <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        today: <?= json_encode(df_today()) ?>,
        school: null
    });
    (async function () {
        const y = new Date().getFullYear();
        const get = async (yr) => {
            try {
                const r = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${yr}/PH`);
                return r.ok ? (await r.json()).map(h => ({ date: h.date, kind: 'holiday', short: 'Holiday', title: h.localName || h.name, sub: 'Public holiday · the research office is closed' })) : [];
            } catch (e) { return []; }
        };
        const [a, b] = await Promise.all([get(y), get(y + 1)]);
        const closedDays = new Set(cal.items().filter(i => i.kind === 'closed').map(i => i.date));
        cal.add(a.concat(b).filter(h => !closedDays.has(h.date)));
    })();
</script>
</body>
</html>
