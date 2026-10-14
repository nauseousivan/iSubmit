<?php
// dashboards/calendar_all.php — the student's full calendar, opened from the dashboard's "Availability & events"
// card ("View more"). Rebuilt 2026-10-14 as a MONTH calendar (assets/js/defense_calendar.js) instead of a long
// scrolling list: the published defense schedule by section, the group's own defense, school events, office
// closures and public holidays. "Coming up" in the side panel keeps the old list's job.
// The old "Available / Unavailable" categories are gone: they belonged to the retired student-picks-a-date flow.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

require_once 'defense_lib.php';
require_once 'schedule_lib.php';

$df_effective_id = df_effective_user_id($pdo, $_SESSION['user_id']);
$my_school = rd_institution_of((string)df_user_school($pdo, $df_effective_id));
$from = date('Y-m-01', strtotime(df_today() . ' -2 months'));
$to   = df_window_end();

// Every published section-date (both institutions; the page opens filtered to the student's own).
$items = sch_calendar_items(sch_published($pdo, $from, $to));

// The group's own defense, once it is published (the Secretary's signature). Earlier it can still move.
foreach (['proposal' => 'Proposal', 'final' => 'Final'] as $ph => $phLabel) {
    $bk = df_get_booking($pdo, $df_effective_id, $ph);
    if ($bk && $bk['status'] === 'Scheduled' && !empty($bk['defense_date'])) {
        $items[] = ['date' => $bk['defense_date'], 'kind' => 'mine', 'short' => 'Your ' . $phLabel,
                    'title' => 'Your ' . $phLabel . ' defense', 'school' => $my_school,
                    'sub' => !empty($bk['slot_no']) ? 'Slot ' . (int)$bk['slot_no'] . ' · groups are heard in slot order' : 'Published by the research office'];
    }
}

// Office closures (the Secretary's blackout dates; one per day whichever phase set it).
$closed = [];
foreach (['proposal', 'final'] as $ph) {
    foreach (df_blackouts($pdo, $ph, $from, $to) as $date => $bo) $closed[$date] = $bo['reason'] ?: 'The research office is closed on this date.';
}
foreach ($closed as $date => $why) $items[] = ['date' => $date, 'kind' => 'closed', 'short' => 'Closed', 'title' => 'Research office closed', 'sub' => $why];

// School events the staff put on the calendar. A title that says closed / not available is a closure.
foreach ($pdo->query("SELECT title, description, event_date FROM calendar_events ORDER BY event_date")->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $t = strtolower($ev['title']);
    $isClosed = str_contains($t, 'closed') || str_contains($t, 'not available');
    $items[] = ['date' => $ev['event_date'], 'kind' => $isClosed ? 'closed' : 'event', 'short' => $isClosed ? 'Closed' : null, 'title' => $ev['title'],
                'sub' => trim((string)$ev['description']) ?: 'Scheduled by the research office.'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense schedule &amp; events | MCNP-ISAP</title>
    <link rel="stylesheet" href="../assets/css/defense_calendar.css?v=<?= filemtime(__DIR__ . '/../assets/css/defense_calendar.css') ?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            --bg-white: #ffffff; --text-dark: #0f172a; --text-muted: #64748b; --border-line: #e2e8f0; --chip-bg: #f8fafc; --accent: #7c3aed;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: transparent; color: var(--text-dark); min-height: 100vh; padding: 28px 36px 40px; }
        .frame { max-width: 1120px; margin: 0 auto; }
        .head { margin-bottom: 18px; animation: fadeDown .4s ease both; }
        .head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
        .head p { color: var(--text-muted); font-size: 14.5px; margin-top: 4px; max-width: 720px; line-height: 1.55; }
        .note { font-size: 12.5px; color: var(--text-muted); margin-top: 14px; }
        @keyframes fadeDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }

        /* THEME CLASSES (mirrors sibling sub-pages) */
        body.theme-default, body.theme-blue { --bg-white: #ffffff; --text-dark: #0f172a; --text-muted: #64748b; --border-line: #e2e8f0; --chip-bg: #f8fafc; --accent: #1e40af; }
        body.theme-red { --bg-white: #ffffff; --text-dark: #7f1d1d; --text-muted: #991b1b; --border-line: #fecaca; --chip-bg: #fef2f2; --accent: #dc2626; }
        body.theme-dark { --bg-white: #151c24; --text-dark: #f8fafc; --text-muted: #94a3b8; --border-line: #334155; --chip-bg: #1b232d; --accent: #38bdf8; }
        body.theme-pink, body.theme-rose { --bg-white: #ffffff; --text-dark: #831843; --text-muted: #9d174d; --border-line: #fbcfe8; --chip-bg: #fdf2f8; --accent: #db2777; }
        body.theme-green { --bg-white: #ffffff; --text-dark: #14532d; --text-muted: #166534; --border-line: #bbf7d0; --chip-bg: #f0fdf4; --accent: #16a34a; }
        body.theme-purple, body.theme-lavender { --bg-white: #ffffff; --text-dark: #4c1d95; --text-muted: #6d28d9; --border-line: #ddd6fe; --chip-bg: #f5f3ff; --accent: #7c3aed; }
        body.theme-orange, body.theme-amber { --bg-white: #ffffff; --text-dark: #78350f; --text-muted: #92400e; --border-line: #fde68a; --chip-bg: #fffbeb; --accent: #d97706; }

        @media (max-width: 768px) {
            body { padding: 20px 14px 32px; }
            .head h1 { font-size: 22px; }
        }
    </style>
</head>

<body>
    <div class="frame">
        <div class="head">
            <h1>Defense schedule &amp; events</h1>
            <p>Published defense dates by section, your own defense, school events, office closures and public holidays. Tap a day for the details.</p>
        </div>
        <div id="cal"></div>
        <p class="note">Only dates the research office has published are shown. A date your Focal Person has proposed appears once it is published.</p>
    </div>

    <script src="../assets/js/defense_calendar.js?v=<?= filemtime(__DIR__ . '/../assets/js/defense_calendar.js') ?>"></script>
    <script>
        const cal = DefenseCalendar.mount(document.getElementById('cal'), {
            items: <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            today: <?= json_encode(df_today()) ?>,
            school: <?= json_encode($my_school) ?>
        });

        // Public holidays (Philippines) for this year and next, added once they arrive.
        (async function () {
            const y = new Date().getFullYear();
            const get = async (yr) => {
                try {
                    const r = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${yr}/PH`);
                    return r.ok ? (await r.json()).map(h => ({ date: h.date, kind: 'holiday', short: 'Holiday', title: h.localName || h.name, sub: 'Public holiday · the research office is closed' })) : [];
                } catch (e) { return []; }
            };
            const [a, b] = await Promise.all([get(y), get(y + 1)]);
            // The office logs most holidays as a closure already; one entry per day is enough.
            const closedDays = new Set(cal.items().filter(i => i.kind === 'closed').map(i => i.date));
            cal.add(a.concat(b).filter(h => !closedDays.has(h.date)));
        })();

        // Sync theme from the parent portal (same handshake the other sub-pages use)
        const syncTheme = () => {
            const saved = localStorage.getItem('rd-portal-theme') || 'theme-purple';
            document.body.className = saved;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className.replace('zoom-active', '').trim();
                    if (pTheme && pTheme !== document.body.className) document.body.className = pTheme;
                }
            } catch (e) {}
        }, 500);
    </script>
</body>
</html>
