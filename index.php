<?php
// index.php — the front door (2026-10-14).
// Signed in: straight to your own dashboard, every role (Admin and Focal Person used to fall through to
// logout.php, which signed them out whenever they opened the site's main link).
// Not signed in: a public page that says what iSubmit is, the five phases in order, who to contact per
// institution, the blank forms, and Sign in / Register. It replaces the bare jump to the login screen.
// Contacts are NAMES ONLY — never email addresses or phone numbers on a public page; questions go
// through the Help desk (auth/help.php), which is open to guests.
// The hero's tracker card is an EXAMPLE of a group's card (no real group's data on a public page).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/forms.php';

if (isset($_SESSION['user_id'])) {
    $home = [
        'Student'              => 'dashboards/student.php',
        'Research Coordinator' => 'dashboards/coordinator.php',
        'Statistician'         => 'dashboards/statistician.php',
        'Research Director'    => 'dashboards/director.php',
        'Focal Person'         => 'dashboards/focal.php',
        'Admin'                => 'dashboards/admin.php',
    ][$_SESSION['role'] ?? ''] ?? 'auth/logout.php';
    header('Location: ' . $home);
    exit();
}

// "Who defends next": the next published defense days (dashboards/schedule_lib.php, the same source as the
// public schedule.php and the student calendars). Sections only. None coming up -> the most recent ones,
// labelled so, rather than an empty box.
require_once __DIR__ . '/dashboards/defense_lib.php';
require_once __DIR__ . '/dashboards/schedule_lib.php';
$lp_sched = []; $lp_sched_label = 'Coming up';
try {
    $rows = sch_published($pdo, df_today(), df_window_end());
    if (!$rows) {
        $rows = sch_published($pdo, date('Y-m-d', strtotime(df_today() . ' -60 days')), date('Y-m-d', strtotime(df_today() . ' -1 day')));
        $lp_sched_label = 'Recently held';
    }
    foreach (sch_calendar_items($rows) as $it) $lp_sched[$it['date']][] = $it;
    $lp_sched = $lp_sched_label === 'Coming up' ? array_slice($lp_sched, 0, 4, true) : array_slice($lp_sched, -4, 4, true);
} catch (Throwable $ex) { $lp_sched = []; }

// Meet the RDC team — the office's own roster and grouping (from the RDC website's "Meet the RDC Team"), with the
// official portrait (assets/images/officers/<name>.png: circular 600x600, transparent corners, shown WHOLE with object-fit: contain). The calling cards carry personal
// mobile numbers and emails, which never go on this page — the office email in the contact card is the way in.
// Focal Persons are not on it (they are per course, not the office). When staff change, edit this list.
$lp_team = [
    'Leadership & Coordinators' => [
        ['ryan-fontanilla.png', 'Dr. Ryan C. Fontanilla, Ph.D.-EM', 'Research Director', 'Executive Leadership', 'Directs the research agenda, ethics compliance and research linkages for MCNP and ISAP.'],
        ['rio-frances-callores.png', 'Ms. Rio Frances G. Callores, MSc', 'MCNP Research Coordinator', 'MCNP Health Wing', 'Oversees health sciences research: Nursing, Medical Technology, Radiologic Technology, Pharmacy and Physical Therapy.'],
        ['kc-placido.png', 'Engr. KC R. Placido', 'ISAP Research Coordinator', 'ISAP Multidisciplinary Wing', 'Oversees Criminology, Customs Administration, IT, Teacher Education, Business and the Arts.'],
    ],
    'Biostatisticians' => [
        ['tshaine-labang.png', 'Ms. Tshaine Anne T. Labang, LPT', 'Statistician for Faculty and Administration', 'Faculty & Admin Analytics', 'Statistics for faculty and institutional research, and faculty research clearances.'],
        ['ruchelle-langcay.png', 'Ms. Ruchelle B. Langcay', 'MCNP Statistician', 'Health Biostatistics Desk', 'Statistical treatment and Form 011 clearance for MCNP theses.'],
        ['maryjane-agabin.png', 'Ms. Maryjane B. Agabin', 'ISAP Statistician', 'Multidisciplinary Biostatistics Desk', 'Statistical treatment, instrument testing and clearance for ISAP theses.'],
    ],
    'Systems & Secretariat' => [
        ['lea-paddayuman.png', 'Ms. Lea M. Paddayuman', 'Secretary', 'Secretariat & Records', 'Defense scheduling, records, and completion clearances.'],
        ['jerald-guiquing.png', 'Mr. Jerald J. Guiquing', 'IT Specialist', 'Systems & Digital Repositories', 'The digital repository, research journals and the Turnitin database.'],
    ],
];

// The blank forms, per institution (the Admin's latest upload, else the shipped default), each tagged
// with the phases it belongs to so the filter can show one phase at a time.
$lp_forms = [];
foreach (rd_form_catalogue() as $slot => $f) {
    $row = ['label' => $f['label'], 'code' => $f['code'], 'url' => [],
            'phases' => array_map(fn($p) => strtolower(trim($p)), explode('·', $f['phase']))];
    foreach (['MCNP', 'ISAP'] as $i) {
        try { $cur = rd_form_current($pdo, $slot, $i); } catch (Throwable $e) { $cur = ['path' => '']; }
        if (($cur['path'] ?? '') !== '') $row['url'][$i] = $cur['path'] . (!empty($cur['uploaded']) ? '?v=' . (int)$cur['row']['template_id'] : '');
    }
    if ($row['url']) $lp_forms[] = $row;
}
$lp_filters = ['proposal' => 'Proposal', 'statistics' => 'Statistics', 'final' => 'Final', 'plagiarism' => 'Plagiarism'];

// Official marks beside the wordmark — shown only once the files are in assets/images/.
$lp_logos = array_filter(['logo-rdc.png' => 'RDC', 'logo-mcnp.png' => 'MCNP', 'logo-isap.png' => 'ISAP'],
                         fn($f) => is_file(__DIR__ . '/assets/images/' . $f), ARRAY_FILTER_USE_KEY);

require_once __DIR__ . '/assets/mascot/eagle_svg.php';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$lp_phases = [
    // [title, what happens, checked ON PAPER by (null = none), handled IN ISUBMIT by (null = none), the story
    // panel's "where your paper is" line]. The paper sign-off is real and comes FIRST: the research teacher,
    // adviser and dean check the paper in person and have no accounts. Only after that does iSubmit take over.
    ['Register your group', 'The leader creates the group; teammates join by scanning the group\'s QR code or with its invite code.', null, null, 'Your group signs up'],
    ['Proposal', 'After your research teacher, adviser and dean have checked and signed your paper, upload the seven proposal requirements. They are checked, then your section is endorsed for a defense date, confirmed and published.', 'Research teacher · Adviser · Dean', 'Focal Person · Coordinator · Research office', 'Signed on paper, then checked in iSubmit'],
    ['Statistics', 'Opens once your proposal defense is scheduled. Hand in your coded data, pay with RDC Form 011 to get your control number, then receive your statistical results.', null, 'Statistician', 'With the Statistician'],
    ['Final', 'Once your statistics are done and your final paper is signed the same way, upload the seven final requirements, ending in your final defense.', 'Research teacher · Adviser · Dean', 'Focal Person · Coordinator · Research office', 'Signed on paper, then your final defense'],
    ['Plagiarism', 'Upload the final manuscript and pay with RDC Form 13 for the Turnitin check. Each revision is a new round.', null, 'Research office', 'With the research office, then cleared'],
];
$ic_dl = '<svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-5-5m5 5 5-5M5 21h14"/></svg>';
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
    <title>iSubmit · Research Digitalization</title>
    <meta name="description" content="iSubmit: research submission and tracking for MCNP and ISAP research groups, from proposal to plagiarism check.">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* The auth pages' language (theme.css): solid brand violet with the research doodles, Poppins
           headings, Plus Jakarta Sans body. Self-contained on purpose — theme.css styles the login card
           layout globally and would fight a long page. The two brand tokens are copied from it. */
        :root {
            --brand: #5b50f5; --brand-ink: #3f35c9; --brand-deep: #2d2594; --brand-soft: #f4f3ff; --brand-line: #dcd9fb;
            --ink: #1c1838; --muted: #5d5a78; --line: #e6e4f3; --canvas: #f7f6fd; --card: #fff;
            --ok: #0f7a52; --ok-soft: #e7f6ef; --amber: #8a5300; --amber-soft: #fff2d6;
            --doodles: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160' fill='none' stroke='%23ffffff' stroke-opacity='.14' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 12h18l7 7v25H14z'/%3E%3Cpath d='M32 12v7h7M19 28h14M19 34h10'/%3E%3Ccircle cx='112' cy='26' r='11'/%3E%3Cpath d='M107 26l4 4 7-8'/%3E%3Cpath d='M58 66l14-7 14 7-14 7z'/%3E%3Cpath d='M64 69v8c4 3 12 3 16 0v-8M86 66v9'/%3E%3Cpath d='M112 119l22-22 7 7-22 22h-7z'/%3E%3Cpath d='M129 102l7 7'/%3E%3Cpath d='M28 92l3.5 7.5 8 1-6 5.5 1.5 8-7-4-7 4 1.5-8-6-5.5 8-1z'/%3E%3Cpath d='M62 146v-14M72 146v-24M82 146v-18M57 149h30'/%3E%3Ccircle cx='134' cy='138' r='8'/%3E%3Cpath d='M140 144l7 7'/%3E%3Ccircle cx='100' cy='62' r='2'/%3E%3Ccircle cx='44' cy='140' r='2'/%3E%3C/svg%3E");
            --head: 'Poppins', system-ui, sans-serif; --body: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
            --brand-solid: var(--brand); --brand-doodles: var(--doodles);   /* the names the shared intro reads (theme.css's) */
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 400 16px/1.6 var(--body); -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; }
        h1, h2, h3 { font-family: var(--head); margin: 0; letter-spacing: -.02em; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 22px; border-radius: 999px;
               font: 700 15px var(--body); text-decoration: none; border: 1.5px solid transparent; transition: transform .15s, background .15s, box-shadow .15s; white-space: nowrap; cursor: pointer; }
        .btn:hover { transform: translateY(-1px); }
        .btn-light { background: #fff; color: var(--brand-ink); box-shadow: 0 10px 24px -12px rgba(20, 12, 90, .55); }
        .btn-ghost { color: #fff; border-color: rgba(255, 255, 255, .55); background: transparent; }
        .btn-ghost:hover { background: rgba(255, 255, 255, .1); }
        .btn-solid { background: var(--brand); color: #fff; }
        .btn-solid:hover { background: var(--brand-ink); }
        .btn-line { color: var(--brand-ink); border-color: var(--brand-line); background: #fff; }
        .btn-sm { height: 38px; padding: 0 16px; font-size: 14px; }
        .btn-xs { height: 32px; padding: 0 12px; font-size: 13px; gap: 6px; }
        svg.i { width: 16px; height: 16px; flex: none; }

        /* ---------- hero: headline left, an example group card right with the eagle perched on it ---------- */
        /* Depth: three layers move at different speeds as you scroll (sky slowest, glow, then the page), and
           the example card tilts toward the pointer with the eagle standing out in front of it. */
        .hero { position: relative; background-color: var(--brand); color: #fff; overflow: hidden; }
        .hero > .wrap { position: relative; z-index: 2; }
        .hero-sky { position: absolute; inset: -40% 0 -10%; background-image: var(--doodles); will-change: transform; pointer-events: none; }
        .hero-glow { position: absolute; inset: 0; pointer-events: none; will-change: transform;
                     background: radial-gradient(520px 380px at 78% 30%, rgba(255, 255, 255, .16), transparent 70%),
                                 radial-gradient(460px 340px at 8% 92%, rgba(45, 37, 148, .55), transparent 70%); }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 18px; padding-bottom: 18px; }
        .lockup { display: flex; align-items: center; gap: 10px; text-decoration: none; min-width: 0; }
        .lockup > img { width: 38px; height: 44px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(20, 12, 90, .3)); }
        .lockup b { display: block; font: 800 21px/1 var(--head); letter-spacing: -.02em; }
        .lockup small { display: block; font-size: 12px; font-weight: 600; opacity: .85; margin-top: 4px; }
        .marks { display: flex; align-items: center; gap: 8px; padding-left: 14px; margin-left: 4px; border-left: 1px solid rgba(255, 255, 255, .35); }
        .marks img { height: 38px; width: auto; }
        .top-actions { display: flex; gap: 10px; flex: none; }
        .hero-in { display: grid; grid-template-columns: 1.1fr .9fr; gap: 48px; align-items: center; padding-top: 56px; padding-bottom: 96px; }
        .hero h1 { font-size: clamp(34px, 5vw, 56px); line-height: 1.08; margin: 0 0 18px; }
        .hero p.lead { font-size: 18px; line-height: 1.6; max-width: 500px; margin: 0 0 30px; opacity: .93; }
        .hero-cta { display: flex; flex-wrap: wrap; align-items: center; gap: 22px; }
        .link-how { font-weight: 700; color: #fff; text-underline-offset: 5px; text-decoration-thickness: 2px; }

        .demo { position: relative; justify-self: end; width: 100%; max-width: 420px; margin-top: 70px; perspective: 1100px; will-change: transform; }
        .demo-tilt { position: relative; transform-style: preserve-3d; transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)); transition: transform .35s ease-out; }
        .demo .bird { position: absolute; right: -18px; top: -128px; width: 150px; z-index: 2; transform: translateZ(70px);
                      filter: drop-shadow(0 14px 22px rgba(20, 12, 90, .35)); }
        .demo .bird-in { animation: bob 4.5s ease-in-out infinite; }
        .demo .bird svg { width: 100%; height: auto; display: block; }
        @keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        .gc { position: relative; background: #fff; color: var(--ink); border-radius: 22px; padding: 20px 20px 18px; box-shadow: 0 30px 60px -28px rgba(15, 8, 70, .7); }
        .gc-k { font-size: 11.5px; font-weight: 800; letter-spacing: .08em; color: var(--muted); }
        .gc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin: 4px 0 14px; padding-right: 64px; }
        .gc-title { font: 700 17px/1.3 var(--head); }
        .gc-sub { font-size: 13px; color: var(--muted); }
        .chip { flex: none; font-size: 11.5px; font-weight: 800; padding: 4px 10px; border-radius: 999px; background: var(--amber-soft); color: var(--amber); white-space: nowrap; }
        .gc ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; }
        .gc li { display: grid; grid-template-columns: 28px 1fr auto; gap: 12px; align-items: center; padding: 9px 10px; border-radius: 12px; font-weight: 700; font-size: 14.5px; }
        .gc li .dot { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-size: 12.5px; font-weight: 800; border: 1.5px solid var(--line); color: var(--muted); }
        .gc li.done .dot { background: var(--ok); border-color: var(--ok); color: #fff; }
        .gc li.done em { font-style: normal; font-size: 12.5px; color: var(--ok); }
        .gc li.now { background: var(--brand-soft); }
        .gc li.now .dot { background: var(--brand); border-color: var(--brand); color: #fff; }
        .gc li small { display: block; font-weight: 500; font-size: 12.5px; color: var(--muted); line-height: 1.35; }
        .gc li.later { color: var(--muted); font-weight: 600; }
        .gc-date { display: flex; gap: 12px; align-items: center; margin-top: 12px; padding: 12px 14px; border-radius: 14px; background: var(--canvas); border: 1px solid var(--line); }
        .gc-date svg { width: 22px; height: 22px; color: var(--brand-ink); flex: none; }
        .gc-date small { display: block; font-size: 12px; color: var(--muted); }
        .gc-date b { font-size: 14px; }
        .gc-note { margin-top: 10px; text-align: center; font-size: 12px; color: rgba(255, 255, 255, .78); }

        /* ---------- sections ---------- */
        section.block { padding: 72px 0 8px; }
        .sec-h { max-width: 640px; margin-bottom: 30px; }
        .sec-h .eyebrow { font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--brand-ink); }
        .sec-h h2 { font-size: clamp(26px, 3.4vw, 34px); line-height: 1.2; margin: 8px 0 10px; }
        .sec-h p { margin: 0; color: var(--muted); }

        /* scroll reveal: anything .rv rises in as it enters the screen (visible at once without JS / with less motion) */
        .rv { opacity: 0; transform: translateY(26px); transition: opacity .7s ease, transform .8s cubic-bezier(.2, .7, .2, 1); transition-delay: var(--d, 0s); }
        .rv.in, .js-off .rv { opacity: 1; transform: none; }

        /* "How it works" as a scroll story: the five chapters scroll on the left while a pinned panel on the right
           follows the paper; the eagle hops to the phase you are reading. Phones get the chapters alone. */
        .story { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 64px; align-items: start; }
        .chapters { list-style: none; margin: 0; padding: 0; }
        .chapter { min-height: clamp(340px, 58vh, 560px); display: flex; flex-direction: column; justify-content: center; padding: 28px 0; opacity: .28; transition: opacity .45s ease; }
        .chapter.on, .js-off .chapter { opacity: 1; }
        .chapter .num { font: 800 14px var(--head); letter-spacing: .12em; color: var(--brand-ink); }
        .chapter h3 { font-size: clamp(28px, 3.4vw, 40px); line-height: 1.12; margin: 6px 0 12px; }
        .chapter p { margin: 0 0 18px; color: var(--muted); font-size: 17px; line-height: 1.65; max-width: 540px; }
        .chapter .checks { display: flex; flex-wrap: wrap; gap: 8px; }
        .stage { position: sticky; top: 40px; padding: 32px 0; }
        .panel { position: relative; background: #fff; border: 1px solid var(--line); border-radius: 26px; padding: 24px 24px 22px; box-shadow: 0 40px 70px -40px rgba(45, 37, 148, .55); }
        .panel-k { font-size: 11.5px; font-weight: 800; letter-spacing: .08em; color: var(--muted); }
        .panel-now { font: 700 21px/1.25 var(--head); margin: 6px 0 18px; min-height: 52px; transition: opacity .25s ease; }
        .panel-now.swap { opacity: 0; }
        .rail { padding: 0 0 0 4px; position: relative; }
        .stops { list-style: none; margin: 0; padding: 0; }
        .rail::before { content: ""; position: absolute; left: 21px; top: 22px; bottom: 22px; width: 3px; border-radius: 3px; background: var(--line); }
        .rail-fill { position: absolute; left: 21px; top: 22px; width: 3px; border-radius: 3px; background: var(--brand);
                     height: calc(var(--i, 0) * 52px); transition: height .6s cubic-bezier(.2, .7, .2, 1); }
        .stop { position: relative; display: flex; align-items: center; gap: 14px; height: 52px; font-weight: 700; font-size: 15px; color: var(--muted); }
        .stop i { width: 36px; height: 36px; border-radius: 50%; flex: none; display: grid; place-items: center; font: normal 800 13px var(--head);
                  background: #fff; border: 2px solid var(--line); color: var(--muted); transition: all .35s ease; position: relative; z-index: 1; }
        .stop.done { color: var(--ink); }
        .stop.done i { background: var(--ok); border-color: var(--ok); color: #fff; }
        .stop.now { color: var(--ink); }
        .stop.now i { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 0 0 6px var(--brand-soft); }
        .rail-bird { position: absolute; right: -8px; top: -14px; width: 70px; transform: translateY(calc(var(--i, 0) * 52px)); z-index: 2;
                     transition: transform .7s cubic-bezier(.34, 1.4, .64, 1); filter: drop-shadow(0 8px 12px rgba(45, 37, 148, .3)); }
        .rail-bird svg { width: 100%; height: auto; display: block; }
        .panel-bar { height: 6px; border-radius: 6px; background: var(--canvas); margin-top: 16px; overflow: hidden; }
        .panel-bar b { display: block; height: 100%; width: calc((var(--i, 0) + 1) * 20%); background: linear-gradient(90deg, var(--brand), #a39dff); border-radius: 6px; transition: width .6s ease; }
        .checks { display: grid; gap: 6px; }
        .chk { font-size: 12.5px; font-weight: 700; line-height: 1.4; padding: 6px 10px; border-radius: 10px; color: var(--brand-ink); background: var(--brand-soft); border: 1px solid var(--brand-line); }
        .chk span { display: block; font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .chk.paper { background: #fff; border-style: dashed; color: var(--ink); }   /* happens in person, outside the system */
        .note { margin-top: 26px; padding: 14px 18px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); color: var(--muted); font-size: 14.5px; text-wrap: pretty; }
        .note b { color: var(--ink); }

        /* "Who defends next": up to four published days, each a date tile + the rooms; the full calendar is schedule.php */
        .sched-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 14px 24px; margin-bottom: 20px; }
        .sched-head .sec-h { margin-bottom: 0; }
        .sched-label { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 10px; }
        .sched-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
        .sday { display: flex; gap: 14px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 14px;
                text-decoration: none; color: inherit; transition: transform .2s ease, box-shadow .2s ease, opacity .7s ease; }
        .sday:hover { transform: translateY(-3px); box-shadow: 0 22px 40px -28px rgba(45, 37, 148, .7); }
        .sday.rv { transform: translateY(26px); } .sday.rv.in { transform: none; } .sday.rv.in:hover { transform: translateY(-3px); }
        .sdate { flex: none; width: 60px; text-align: center; border-radius: 14px; background: var(--brand); color: #fff; padding: 8px 0 7px; }
        .sdate b { display: block; font: 800 24px/1.05 var(--head); }
        .sdate span { display: block; font-size: 11.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .sdate small { display: block; font-size: 11px; opacity: .8; }
        .sday.past .sdate { background: var(--muted); }
        .srooms { display: flex; flex-direction: column; gap: 6px; min-width: 0; padding-top: 2px; }
        .sroom { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; white-space: nowrap; }
        .sroom em { font-style: normal; font-size: 10px; font-weight: 800; width: 16px; height: 16px; display: inline-grid; place-items: center; border-radius: 4px;
                    background: var(--brand-soft); color: var(--brand-ink); flex: none; }
        .sroom i { font-style: normal; font-size: 10.5px; font-weight: 800; color: var(--muted); border: 1px solid var(--line); border-radius: 999px; padding: 0 6px; }
        .smore { font-size: 12.5px; font-weight: 600; color: var(--muted); }
        .sched-empty { background: #fff; border: 1px dashed var(--brand-line); border-radius: 18px; padding: 18px 20px; color: var(--muted); }

        /* Meet the RDC team as a HIERARCHY: the Director, the two wing coordinators under them, then the two units
           that serve both wings as panels of compact rows. Everything centred, so no row ends in an empty gap. */
        .org { display: flex; flex-direction: column; align-items: center; }
        .person { background: #fff; border: 1px solid var(--line); border-radius: 22px; transition: transform .25s ease, box-shadow .25s ease, opacity .7s ease; }
        .person:hover { transform: translateY(-4px); box-shadow: 0 26px 44px -30px rgba(45, 37, 148, .7); }
        .person.rv { transform: translateY(26px); } .person.rv.in { transform: none; } .person.rv.in:hover { transform: translateY(-4px); }
        .person img { border-radius: 50%; object-fit: contain; flex: none; box-shadow: 0 0 0 4px #fff, 0 0 0 6px var(--brand-line), 0 14px 26px -14px rgba(45, 37, 148, .6); }
        .person .unit { display: block; font-size: 11.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--brand-ink); }
        .person b { display: block; font: 700 16.5px/1.3 var(--head); margin: 3px 0 2px; }
        .person .title { display: block; font-size: 14px; font-weight: 600; color: var(--ink); }
        .person p { margin: 8px 0 0; color: var(--muted); font-size: 13.5px; line-height: 1.55; }
        .boss { width: 100%; max-width: 440px; padding: 26px 26px 24px; text-align: center; background: linear-gradient(180deg, var(--brand-soft), #fff 62%); border-color: var(--brand-line); }
        .boss img { width: 124px; height: 124px; margin: 0 auto 14px; display: block; }
        .stem { width: 3px; height: 30px; border-radius: 3px; background: var(--brand-line); }
        .pair { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; width: 100%; max-width: 860px; padding-top: 30px; }
        .pair::before { content: ""; position: absolute; top: 0; left: calc(25% - 6px); right: calc(25% - 6px); height: 3px; border-radius: 3px; background: var(--brand-line); }
        .pair > .coord::before { content: ""; position: absolute; top: -31px; left: calc(50% - 1.5px); width: 3px; height: 31px; background: var(--brand-line); }
        .coord { position: relative; display: flex; gap: 16px; align-items: flex-start; padding: 20px; text-align: left; }
        .coord img { width: 84px; height: 84px; }
        .units { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; width: 100%; margin-top: 44px; align-items: start; }
        .squad { background: #fff; border: 1px solid var(--line); border-radius: 22px; padding: 20px 20px 6px; }
        .squad h3 { margin: 0; font: 800 12.5px var(--body); letter-spacing: .1em; text-transform: uppercase; color: var(--brand-ink); }
        .squad > p { margin: 4px 0 8px; color: var(--muted); font-size: 14px; }
        .mini { display: flex; gap: 14px; align-items: center; padding: 12px 0; border-top: 1px solid var(--line); }
        .mini img { width: 54px; height: 54px; border-radius: 50%; object-fit: contain; flex: none; box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--brand-line); }
        .mini b { display: block; font: 700 15px/1.3 var(--head); }
        .mini span { display: block; font-size: 13.5px; font-weight: 600; color: var(--ink); }
        .mini small { display: block; font-size: 12.5px; color: var(--muted); }
        .reach { display: grid; grid-template-columns: 1.2fr 1fr; gap: 18px; margin-top: 34px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 22px 22px 20px; display: flex; flex-direction: column; }
        .card .tag { font: 800 13px var(--head); letter-spacing: .06em; color: var(--brand-ink); }
        .card h3 { font-size: 17px; line-height: 1.35; margin: 4px 0 12px; }
        .card p { margin: 0 0 16px; color: var(--muted); font-size: 14.5px; }
        .card .act { margin-top: auto; }
        .card.help { background: var(--brand-soft); border-color: var(--brand-line); }
        .visit { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; font-size: 14.5px; }
        .visit li { display: flex; gap: 10px; align-items: flex-start; line-height: 1.5; }
        .visit svg { width: 18px; height: 18px; flex: none; margin-top: 2px; color: var(--brand-ink); }
        .visit a { color: var(--brand-ink); font-weight: 700; text-decoration: none; }

        /* blank forms: small cards, one phase at a time (Proposal first). Without JS every card shows. */
        .forms-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .seg { display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 4px; border-radius: 999px; background: #fff; border: 1px solid var(--line); }
        .seg button { border: 0; background: none; font: 700 14px var(--body); color: var(--muted); padding: 8px 16px; border-radius: 999px; cursor: pointer; }
        .seg button[aria-pressed="true"] { background: var(--brand); color: #fff; }
        .seg button:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .forms-count { font-size: 14px; color: var(--muted); font-weight: 600; }
        .js-off .seg, .js-off .forms-count { display: none; }
        .fgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; }
        .fcard { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px 16px 14px; display: flex; flex-direction: column; gap: 4px; }
        .fcard[hidden] { display: none; }
        .fcard .code { font-size: 12px; font-weight: 800; letter-spacing: .04em; color: var(--brand-ink); }
        .fcard b { font-size: 15px; line-height: 1.35; }
        .fcard .dl { display: flex; gap: 8px; margin-top: auto; padding-top: 12px; }

        /* footer: brand + description, link columns, the research office's own contact, then a copyright bar */
        footer { margin-top: 80px; background-color: var(--brand-deep); color: rgba(255, 255, 255, .8); font-size: 14.5px; }
        .foot { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1.4fr; gap: 36px; padding-top: 56px; padding-bottom: 40px; }
        .foot h4 { margin: 0 0 14px; font: 800 12.5px var(--body); letter-spacing: .1em; text-transform: uppercase; color: #fff; }
        .foot ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
        .foot a { color: rgba(255, 255, 255, .85); text-decoration: none; }
        .foot a:hover { color: #fff; text-decoration: underline; text-underline-offset: 3px; }
        .foot-brand .lockup { margin-bottom: 14px; color: #fff; }
        .foot-brand p { margin: 0 0 16px; max-width: 330px; line-height: 1.6; }
        .foot-marks { display: flex; gap: 10px; }
        .foot-marks img { height: 34px; width: auto; }
        .foot-contact li { display: flex; gap: 10px; align-items: flex-start; line-height: 1.5; }
        .foot-contact svg { width: 17px; height: 17px; flex: none; margin-top: 2px; color: #a39dff; }
        .foot-bar { border-top: 1px solid rgba(255, 255, 255, .14); }
        .foot-bar .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; padding-top: 18px; padding-bottom: 22px; font-size: 13px; color: rgba(255, 255, 255, .65); }

        @media (max-width: 920px) {
            .hero-in { grid-template-columns: 1fr; gap: 24px; padding-top: 36px; padding-bottom: 56px; }
            .demo { justify-self: center; margin-top: 96px; }
            .demo .bird { width: 118px; top: -100px; right: -4px; }
            .story { grid-template-columns: 1fr; gap: 0; }
            .stage { display: none; }                                  /* phones: the chapters tell it alone */
            .chapters { border-left: 3px solid var(--brand-line); padding-left: 22px; }
            .chapter { min-height: 0; opacity: 1; padding: 0 0 34px; position: relative; }
            .chapter::before { content: ""; position: absolute; left: -32px; top: 2px; width: 17px; height: 17px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 0 5px var(--canvas); }
            .chapter h3 { font-size: 24px; }
            .chapter p { font-size: 15.5px; }
            .pair, .units { grid-template-columns: 1fr; }
            .pair { padding-top: 0; gap: 14px; }
            .pair::before, .pair > .coord::before, .stem { display: none; }
            .boss { margin-bottom: 14px; }
            .units { margin-top: 18px; }
            .reach { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .wrap { padding-left: 16px; padding-right: 16px; }
            .lockup small, .marks { display: none; }
            .top-actions .btn-light { display: none; }   /* Register is the big hero button; Sign in must stay */
            .gc-head { padding-right: 0; flex-wrap: wrap; }
            .boss { padding: 22px 18px 20px; }
            .boss img { width: 104px; height: 104px; }
            .coord { padding: 16px; gap: 14px; }
            .coord img { width: 64px; height: 64px; }
            .person b { font-size: 15.5px; } .person p { font-size: 13px; }
            .sched-row { grid-auto-flow: column; grid-template-columns: none; grid-auto-columns: 82%; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 6px; }
            .sday { scroll-snap-align: start; }
            .hero-cta .btn { flex: 1 1 100%; }
            .hero-cta { justify-content: center; }
            .seg { width: 100%; justify-content: space-between; }
            .seg button { padding: 8px 10px; font-size: 13px; }
            .foot { grid-template-columns: 1fr 1fr; gap: 30px 20px; padding-top: 44px; }
            .foot-brand, .foot-contact { grid-column: 1 / -1; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; } .btn { transition: none; } .demo .bird-in { animation: none; }
            .rv, .person.rv { opacity: 1; transform: none; transition: none; }
            .demo-tilt, .rail-bird, .rail-fill, .panel-bar b { transition: none; }
        }
    </style>
</head>
<body class="js-off no-intro-delay">
<script>document.body.classList.remove('js-off');</script>
<?php include __DIR__ . '/dashboards/components/eagle_intro.php'; /* the "Eagle flight" loading screen, once per session */ ?>
<?php include __DIR__ . '/dashboards/components/announcement.php'; ?>

<header class="hero" id="hero">
    <div class="hero-sky" id="heroSky" aria-hidden="true"></div>
    <div class="hero-glow" id="heroGlow" aria-hidden="true"></div>
    <div class="wrap top">
        <a class="lockup" href="./">
            <img src="assets/images/mascot.png" alt="">
            <span><b>iSubmit</b><small>by the MCNP–ISAP Research &amp; Development Center</small></span>
            <?php if ($lp_logos): ?><span class="marks"><?php foreach ($lp_logos as $file => $alt): ?><img src="assets/images/<?= $e($file) ?>" alt="<?= $e($alt) ?>"><?php endforeach; ?></span><?php endif; ?>
        </a>
        <nav class="top-actions" aria-label="Account">
            <a class="btn btn-ghost btn-sm" href="auth/login.php">Sign in</a>
            <a class="btn btn-light btn-sm" href="auth/register.php">Register</a>
        </nav>
    </div>
    <div class="wrap hero-in">
        <div>
            <h1>No more guessing which office has your paper.</h1>
            <p class="lead">From proposal to plagiarism check, iSubmit shows your group's phase, who is reviewing it, and what to do next.</p>
            <div class="hero-cta">
                <a class="btn btn-light" href="auth/register.php">Register your group</a>
                <a class="link-how" href="#how">See how it works</a>
            </div>
        </div>
        <div class="demo" id="heroDemo" aria-label="Example of a group's progress card"><div class="demo-tilt" id="heroTilt">
            <div class="bird"><div class="bird-in"><?= eagle_plain_svg('lp') ?></div></div>
            <div class="gc">
                <div class="gc-k">YOUR GROUP · EXAMPLE</div>
                <div class="gc-head">
                    <div><div class="gc-title">Your research title</div><div class="gc-sub">Course · Section</div></div>
                    <span class="chip">With the Statistician</span>
                </div>
                <ol>
                    <li class="done"><span class="dot" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span><span>Proposal</span><em>Cleared</em></li>
                    <li class="now"><span class="dot">2</span><span>Statistics<small>Next: receive your statistical results</small></span><span></span></li>
                    <li class="later"><span class="dot">3</span><span>Final</span><span></span></li>
                    <li class="later"><span class="dot">4</span><span>Plagiarism check</span><span></span></li>
                </ol>
                <div class="gc-date">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/></svg>
                    <div><small>Final defense</small><b>Appears here once published</b></div>
                </div>
            </div>
        </div></div>
    </div>
</header>

<main>
    <section class="block" id="how">
        <div class="wrap">
            <div class="sec-h rv">
                <span class="eyebrow">How it works</span>
                <h2>Five phases, always in this order</h2>
                <p>Each phase opens when the one before it is done. iSubmit shows where your group is, and who has your paper right now.</p>
            </div>
            <div class="story">
                <ol class="chapters">
                    <?php foreach ($lp_phases as $n => [$title, $what, $paper, $system, $now]): ?>
                        <li class="chapter" data-i="<?= $n ?>" data-now="<?= $e($now) ?>">
                            <span class="num">PHASE <?= sprintf('%02d', $n + 1) ?></span>
                            <h3><?= $e($title) ?></h3>
                            <p><?= $e($what) ?></p>
                            <?php if ($paper || $system): ?><div class="checks">
                                <?php if ($paper): ?><div class="chk paper"><span>On paper</span><?= $e($paper) ?></div><?php endif; ?>
                                <?php if ($system): ?><div class="chk sys"><span>In iSubmit</span><?= $e($system) ?></div><?php endif; ?>
                            </div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <aside class="stage" aria-hidden="true">
                    <div class="panel" id="storyPanel" style="--i:0">
                        <div class="panel-k">WHERE YOUR PAPER IS</div>
                        <div class="panel-now" id="storyNow"><?= $e($lp_phases[0][4]) ?></div>
                        <div class="rail">
                            <span class="rail-fill"></span>
                            <ol class="stops"><?php foreach ($lp_phases as $n => $ph): ?><li class="stop<?= $n === 0 ? ' now' : '' ?>"><i><?= $n + 1 ?></i><?= $e($ph[0]) ?></li><?php endforeach; ?></ol>
                            <span class="rail-bird"><?= eagle_plain_svg('rb') ?></span>
                        </div>
                        <div class="panel-bar"><b></b></div>
                    </div>
                </aside>
            </div>
            <div class="note rv"><b>You don't pick your defense date.</b> Your Focal Person schedules your whole section, and the date shows up here once it is published.</div>
        </div>
    </section>

    <section class="block" id="schedule">
        <div class="wrap">
            <div class="sched-head rv">
                <div class="sec-h">
                    <span class="eyebrow">Defense schedule</span>
                    <h2>Who defends next</h2>
                    <p>Published defense dates, by section. Names and titles are never shown.</p>
                </div>
                <a class="btn btn-solid btn-sm" href="schedule.php">Open the full calendar</a>
            </div>
            <?php if ($lp_sched): ?>
                <div class="sched-label"><?= $e($lp_sched_label) ?></div>
                <div class="sched-row">
                    <?php $k = 0; foreach ($lp_sched as $day => $list): $ts = strtotime($day); ?>
                        <a class="sday rv<?= $day < df_today() ? ' past' : '' ?>" href="schedule.php" style="--d:<?= $k++ * .08 ?>s">
                            <span class="sdate"><b><?= date('j', $ts) ?></b><span><?= date('M', $ts) ?></span><small><?= date('D', $ts) ?></small></span>
                            <span class="srooms">
                                <?php foreach (array_slice($list, 0, 3) as $it): ?>
                                    <span class="sroom"><em><?= $it['phase'] === 'final' ? 'F' : 'P' ?></em><?= $e($it['short']) ?><i><?= $e($it['school']) ?></i></span>
                                <?php endforeach; ?>
                                <?php if (count($list) > 3): ?><span class="smore">+<?= count($list) - 3 ?> more section<?= count($list) - 3 > 1 ? 's' : '' ?></span><?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="sched-empty rv">No defense dates are published yet. They appear here as soon as the research office publishes them.</div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($lp_forms): ?>
    <section class="block" id="forms">
        <div class="wrap">
            <div class="sec-h rv">
                <span class="eyebrow">Blank forms</span>
                <h2>Download the forms you'll need</h2>
                <p>Always the current version for your institution. Fill them in, have them signed, then upload them in iSubmit at the step that asks for them.</p>
            </div>
            <div class="forms-bar">
                <div class="seg" role="group" aria-label="Show forms for">
                    <?php foreach ($lp_filters as $key => $label): ?><button type="button" data-f="<?= $key ?>" aria-pressed="<?= $key === 'proposal' ? 'true' : 'false' ?>"><?= $e($label) ?></button><?php endforeach; ?>
                </div>
                <span class="forms-count" id="formsCount" aria-live="polite"></span>
            </div>
            <div class="fgrid">
                <?php foreach ($lp_forms as $f): ?>
                    <div class="fcard" data-phases="<?= $e(implode(' ', $f['phases'])) ?>">
                        <span class="code"><?= $e($f['code'] ?: 'Form') ?></span>
                        <b><?= $e($f['label']) ?></b>
                        <div class="dl">
                            <?php foreach ($f['url'] as $inst => $url): ?>
                                <a class="btn btn-line btn-xs" href="<?= $e($url) ?>" target="_blank" rel="noopener" aria-label="<?= $e($f['label'] . ', ' . $inst . ' copy') ?>"><?= $ic_dl . $e($inst) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="block" id="contact">
        <div class="wrap">
            <div class="sec-h rv">
                <span class="eyebrow">Meet the RDC team</span>
                <h2>The people behind your paper</h2>
                <p>One Research &amp; Development Center serving both MCNP and ISAP.</p>
            </div>
            <?php [$boss, $coordA, $coordB] = $lp_team['Leadership & Coordinators'];
            $lp_units = ['Biostatisticians' => 'Statistical treatment, Form 011 clearance and data clinics for both institutions.',
                         'Systems & Secretariat' => 'Defense scheduling, records, clearances, and the digital repository.']; ?>
            <div class="org">
                <article class="person boss rv">
                    <img src="assets/images/officers/<?= $e($boss[0]) ?>" alt="<?= $e($boss[1]) ?>" loading="lazy" width="124" height="124">
                    <span class="unit"><?= $e($boss[3]) ?></span><b><?= $e($boss[1]) ?></b><span class="title"><?= $e($boss[2]) ?></span><p><?= $e($boss[4]) ?></p>
                </article>
                <div class="stem"></div>
                <div class="pair">
                    <?php foreach ([$coordA, $coordB] as $k => [$photo, $name, $title, $unit, $does]): ?>
                        <article class="person coord rv" style="--d:<?= .1 + $k * .12 ?>s">
                            <img src="assets/images/officers/<?= $e($photo) ?>" alt="<?= $e($name) ?>" loading="lazy" width="84" height="84">
                            <div><span class="unit"><?= $e($unit) ?></span><b><?= $e($name) ?></b><span class="title"><?= $e($title) ?></span><p><?= $e($does) ?></p></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="units">
                    <?php $k = 0; foreach ($lp_units as $group => $desc): ?>
                        <section class="squad rv" style="--d:<?= $k++ * .12 ?>s">
                            <h3><?= $e($group) ?></h3>
                            <p><?= $e($desc) ?></p>
                            <?php foreach ($lp_team[$group] as [$photo, $name, $title, $unit, $does]): ?>
                                <div class="mini">
                                    <img src="assets/images/officers/<?= $e($photo) ?>" alt="<?= $e($name) ?>" loading="lazy" width="54" height="54">
                                    <div><b><?= $e($name) ?></b><span><?= $e($title) ?></span><small><?= $e($unit) ?></small></div>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="reach">
                <div class="card rv">
                    <span class="tag">VISIT OR WRITE</span>
                    <h3>Research &amp; Development Center</h3>
                    <ul class="visit">
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><span>Alimannao Hills, Peñablanca, Cagayan</span></li>
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg><a href="mailto:researchoffice@mcnp.edu.ph">researchoffice@mcnp.edu.ph</a></li>
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Mon–Fri 8:00 AM – 5:00 PM · Sat 8:00 AM – 12:00 PM</span></li>
                    </ul>
                    <p style="margin:14px 0 0">Signed in? Message the office from your dashboard instead.</p>
                </div>
                <div class="card help rv" style="--d:.12s">
                    <span class="tag">HELP DESK</span>
                    <h3>Can't sign in, or something looks wrong?</h3>
                    <p>Lost access to your email, typed the wrong School ID, or need to change groups? Send a request and the administrator will get back to you.</p>
                    <div class="act"><a class="btn btn-solid btn-sm" href="auth/help.php">Open the Help desk</a></div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="wrap foot">
        <div class="foot-brand">
            <a class="lockup" href="./"><img src="assets/images/mascot.png" alt=""><span><b>iSubmit</b><small>by the MCNP–ISAP Research &amp; Development Center</small></span></a>
            <p>Research submission and tracking for the Medical Colleges of Northern Philippines and the International School of Asia and the Pacific, from proposal to plagiarism check.</p>
            <?php if ($lp_logos): ?><div class="foot-marks"><?php foreach ($lp_logos as $file => $alt): ?><img src="assets/images/<?= $e($file) ?>" alt="<?= $e($alt) ?>"><?php endforeach; ?></div><?php endif; ?>
        </div>
        <div>
            <h4>iSubmit</h4>
            <ul>
                <li><a href="#how">How it works</a></li>
                <li><a href="schedule.php">Defense schedule</a></li>
                <li><a href="#forms">Blank forms</a></li>
                <li><a href="#contact">Meet the RDC team</a></li>
                <li><a href="auth/help.php">Help desk</a></li>
                <li><a href="privacy.php">Privacy Notice</a></li>
                <li><a href="terms.php">Terms of Use</a></li>
            </ul>
        </div>
        <div>
            <h4>Your account</h4>
            <ul>
                <li><a href="auth/login.php">Sign in</a></li>
                <li><a href="auth/register.php">Register your group</a></li>
                <li><a href="auth/forgot_password.php">Forgot password</a></li>
            </ul>
        </div>
        <div class="foot-contact">
            <h4>Research &amp; Development Center</h4>
            <ul>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><span>Alimannao Hills, Peñablanca, Cagayan</span></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg><a href="mailto:researchoffice@mcnp.edu.ph">researchoffice@mcnp.edu.ph</a></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Mon–Fri 8:00 AM – 5:00 PM<br>Sat 8:00 AM – 12:00 PM</span></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 9h3V5h-3a4 4 0 0 0-4 4v2H8v4h2v6h4v-6h3l1-4h-4V9z"/></svg><a href="https://www.facebook.com/profile.php?id=61593412015654" target="_blank" rel="noopener">RDC Facebook page ↗</a></li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg><a href="https://jeraldguiquing.github.io/rdc-website/" target="_blank" rel="noopener">RDC website: agenda, journals, news ↗</a></li>
            </ul>
        </div>
    </div>
    <div class="foot-bar">
        <div class="wrap">
            <span>© <?= date('Y') ?> iSubmit · Research &amp; Development Center (RDC), MCNP &amp; ISAP</span>
            <span><a href="privacy.php" style="color:inherit">Privacy Notice</a> · <a href="terms.php" style="color:inherit">Terms of Use</a> · Research Digitalization</span>
        </div>
    </div>
</footer>
<script>
// Motion: scroll reveal, the "How it works" story, hero parallax + tilt. Everything degrades to a still page:
// no IntersectionObserver = everything shown; reduced motion = no parallax, no tilt, no reveal.
(function () {
    var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var hasIO = 'IntersectionObserver' in window;

    // 1. reveal on scroll
    var rv = document.querySelectorAll('.rv');
    if (!hasIO || still) { rv.forEach(function (el) { el.classList.add('in'); }); }
    else {
        var io = new IntersectionObserver(function (es) {
            es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { rootMargin: '0px 0px -10% 0px' });
        rv.forEach(function (el) { io.observe(el); });
    }

    // 2. the story: whichever chapter crosses the middle of the screen drives the pinned panel
    var chapters = Array.prototype.slice.call(document.querySelectorAll('.chapter'));
    var panel = document.getElementById('storyPanel'), nowEl = document.getElementById('storyNow');
    var stops = panel ? panel.querySelectorAll('.stop') : [];
    var cur = -1;
    function step(i) {
        if (i === cur || !panel) return;
        cur = i;
        chapters.forEach(function (c, k) { c.classList.toggle('on', k === i); });
        panel.style.setProperty('--i', i);
        stops.forEach(function (s, k) { s.className = 'stop ' + (k < i ? 'done' : k === i ? 'now' : 'later'); });
        if (nowEl) {
            nowEl.classList.add('swap');
            setTimeout(function () { nowEl.textContent = chapters[i].dataset.now; nowEl.classList.remove('swap'); }, still ? 0 : 200);
        }
    }
    if (hasIO && panel) {
        var so = new IntersectionObserver(function (es) {
            es.forEach(function (e) { if (e.isIntersecting) step(+e.target.dataset.i); });
        }, { rootMargin: '-45% 0px -45% 0px' });
        chapters.forEach(function (c) { so.observe(c); });
    }
    step(0);

    // 3. hero depth: layers drift at different speeds while the hero is on screen
    if (still) return;
    var hero = document.getElementById('hero'), sky = document.getElementById('heroSky'), glow = document.getElementById('heroGlow');
    var demo = document.getElementById('heroDemo'), tilt = document.getElementById('heroTilt');
    var ticking = false;
    function paint() {
        ticking = false;
        var y = window.scrollY || window.pageYOffset;
        if (y > hero.offsetHeight) return;
        sky.style.transform = 'translate3d(0,' + (y * 0.45).toFixed(1) + 'px,0)';
        glow.style.transform = 'translate3d(0,' + (y * 0.25).toFixed(1) + 'px,0)';
        if (demo) demo.style.transform = 'translate3d(0,' + (y * -0.08).toFixed(1) + 'px,0)';
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(paint); } }, { passive: true });
    paint();

    // the example card leans toward the pointer (mouse only — phones have nothing to lean toward)
    if (tilt && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        hero.addEventListener('pointermove', function (ev) {
            var r = demo.getBoundingClientRect();
            var dx = (ev.clientX - (r.left + r.width / 2)) / window.innerWidth;
            var dy = (ev.clientY - (r.top + r.height / 2)) / window.innerHeight;
            tilt.style.setProperty('--ry', (dx * 14).toFixed(2) + 'deg');
            tilt.style.setProperty('--rx', (dy * -10).toFixed(2) + 'deg');
        });
        hero.addEventListener('pointerleave', function () { tilt.style.setProperty('--ry', '0deg'); tilt.style.setProperty('--rx', '0deg'); });
    }
})();

// Forms filter: one phase at a time, Proposal first. A form used in two phases (Form 002) shows in both.
(function () {
    var btns = document.querySelectorAll('.seg button'), cards = document.querySelectorAll('.fcard'), out = document.getElementById('formsCount');
    if (!btns.length) return;
    function show(f) {
        var n = 0;
        cards.forEach(function (c) { var on = (' ' + c.dataset.phases + ' ').indexOf(' ' + f + ' ') !== -1; c.hidden = !on; if (on) n++; });
        btns.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.f === f ? 'true' : 'false'); });
        if (out) out.textContent = n + (n === 1 ? ' form' : ' forms');
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.f); }); });
    show('proposal');
})();
</script>
</body>
</html>
