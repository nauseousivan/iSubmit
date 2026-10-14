<?php
// index.php — the front door (2026-10-14).
// Signed in: straight to your own dashboard, every role (Admin and Focal Person used to fall through to
// logout.php, which signed them out whenever they opened the site's main link).
// Not signed in: a public page that says what iSubmit is, the five phases in order, who to contact per
// institution, the blank forms, and Sign in / Register. It replaces the bare jump to the login screen.
// Contacts are NAMES ONLY — never email addresses or phone numbers on a public page; questions go
// through the Help desk (auth/help.php), which is open to guests.
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

// Who to contact: each institution's Research Coordinator, by name, from the live accounts.
$lp_inst = [
    'MCNP' => ['name' => 'Medical Colleges of Northern Philippines', 'coord' => []],
    'ISAP' => ['name' => 'International School of Asia and the Pacific', 'coord' => []],
];
try {
    $st = $pdo->query("SELECT username, department FROM users WHERE role = 'Research Coordinator' AND account_status = 'active' ORDER BY username");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['department']) === '') continue;
        $lp_inst[rd_institution_of((string)$c['department'])]['coord'][] = $c['username'];
    }
} catch (Throwable $e) { /* the page still works without names */ }

// The blank forms, per institution (the Admin's latest upload, else the shipped default).
$lp_forms = [];
foreach (rd_form_catalogue() as $slot => $f) {
    $row = ['label' => $f['label'], 'code' => $f['code'], 'phase' => $f['phase'], 'url' => []];
    foreach (['MCNP', 'ISAP'] as $i) {
        try { $cur = rd_form_current($pdo, $slot, $i); } catch (Throwable $e) { $cur = ['path' => '']; }
        if (($cur['path'] ?? '') !== '') $row['url'][$i] = $cur['path'] . (!empty($cur['uploaded']) ? '?v=' . (int)$cur['row']['template_id'] : '');
    }
    if ($row['url']) $lp_forms[] = $row;
}

require_once __DIR__ . '/assets/mascot/eagle_svg.php';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$lp_phases = [
    ['Register your group', 'The leader creates the group; teammates join by scanning the group\'s QR code or with its invite code.', 'Your group'],
    ['Proposal', 'Upload the seven proposal requirements. Your Focal Person checks them and endorses your section for a defense date; the Coordinator confirms it and the research office publishes it.', 'Focal Person · Coordinator · Research office'],
    ['Statistics', 'Opens once your proposal defense is scheduled. Hand in your coded data, pay with RDC Form 011 to get your control number, then receive your statistical results.', 'Statistician'],
    ['Final', 'The same seven-requirement grid as the proposal, once your statistics are done, ending in your final defense.', 'Focal Person · Coordinator · Research office'],
    ['Plagiarism', 'Upload the final manuscript and pay with RDC Form 13 for the Turnitin check. Each revision is a new round.', 'Research office'],
];
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
            --doodles: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160' fill='none' stroke='%23ffffff' stroke-opacity='.14' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 12h18l7 7v25H14z'/%3E%3Cpath d='M32 12v7h7M19 28h14M19 34h10'/%3E%3Ccircle cx='112' cy='26' r='11'/%3E%3Cpath d='M107 26l4 4 7-8'/%3E%3Cpath d='M58 66l14-7 14 7-14 7z'/%3E%3Cpath d='M64 69v8c4 3 12 3 16 0v-8M86 66v9'/%3E%3Cpath d='M112 119l22-22 7 7-22 22h-7z'/%3E%3Cpath d='M129 102l7 7'/%3E%3Cpath d='M28 92l3.5 7.5 8 1-6 5.5 1.5 8-7-4-7 4 1.5-8-6-5.5 8-1z'/%3E%3Cpath d='M62 146v-14M72 146v-24M82 146v-18M57 149h30'/%3E%3Ccircle cx='134' cy='138' r='8'/%3E%3Cpath d='M140 144l7 7'/%3E%3Ccircle cx='100' cy='62' r='2'/%3E%3Ccircle cx='44' cy='140' r='2'/%3E%3C/svg%3E");
            --head: 'Poppins', system-ui, sans-serif; --body: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 400 16px/1.6 var(--body); -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; }
        h1, h2, h3 { font-family: var(--head); margin: 0; letter-spacing: -.02em; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 22px; border-radius: 999px;
               font: 700 15px var(--body); text-decoration: none; border: 1.5px solid transparent; transition: transform .15s, background .15s, box-shadow .15s; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn-light { background: #fff; color: var(--brand-ink); box-shadow: 0 10px 24px -12px rgba(20, 12, 90, .55); }
        .btn-ghost { color: #fff; border-color: rgba(255, 255, 255, .55); }
        .btn-ghost:hover { background: rgba(255, 255, 255, .1); }
        .btn-solid { background: var(--brand); color: #fff; }
        .btn-solid:hover { background: var(--brand-ink); }
        .btn-line { color: var(--brand-ink); border-color: var(--brand-line); background: #fff; }
        .btn-sm { height: 38px; padding: 0 16px; font-size: 14px; }
        svg.i { width: 18px; height: 18px; flex: none; }

        /* hero */
        .hero { background-color: var(--brand); background-image: var(--doodles); color: #fff; position: relative; overflow: hidden; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 18px; padding-bottom: 18px; }
        .lockup { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .lockup img { width: 40px; height: 47px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(20, 12, 90, .3)); }
        .lockup b { display: block; font: 800 21px/1 var(--head); letter-spacing: -.02em; }
        .lockup small { display: block; font-size: 12px; font-weight: 600; opacity: .82; margin-top: 3px; }
        .top-actions { display: flex; gap: 10px; }
        .hero-in { display: grid; grid-template-columns: 1.15fr .85fr; gap: 32px; align-items: center; padding-top: 40px; padding-bottom: 84px; }
        .kicker { display: inline-block; font-size: 13px; font-weight: 700; letter-spacing: .04em; padding: 6px 12px; border-radius: 999px; background: rgba(255, 255, 255, .14); }
        .hero h1 { font-size: clamp(34px, 5vw, 54px); line-height: 1.08; margin: 18px 0 16px; }
        .hero p.lead { font-size: 18px; line-height: 1.6; max-width: 540px; margin: 0 0 28px; opacity: .93; }
        .hero-cta { display: flex; flex-wrap: wrap; gap: 12px; }
        .hero-art { display: flex; justify-content: center; }
        .hero-art svg { width: min(300px, 80%); height: auto; filter: drop-shadow(0 18px 30px rgba(20, 12, 90, .35)); }
        .points { list-style: none; padding: 0; margin: 28px 0 0; display: grid; gap: 8px; font-weight: 600; font-size: 15px; }
        .points li { display: flex; gap: 10px; align-items: center; }
        .points svg { width: 20px; height: 20px; flex: none; }

        /* sections */
        section.block { padding: 72px 0 8px; }
        .sec-h { max-width: 640px; margin-bottom: 30px; }
        .sec-h .eyebrow { font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--brand-ink); }
        .sec-h h2 { font-size: clamp(26px, 3.4vw, 34px); line-height: 1.2; margin: 8px 0 10px; }
        .sec-h p { margin: 0; color: var(--muted); }

        /* the five phases: a horizontal track on desktop, a vertical one on phones */
        .phases { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(5, 1fr); gap: 18px; position: relative; }
        .phases::before { content: ""; position: absolute; top: 23px; left: 24px; right: calc((100% - 72px) / 5 - 24px); height: 3px; border-radius: 3px;
                          background: linear-gradient(90deg, var(--brand), #a39dff); opacity: .35; }
        .phase { position: relative; }
        .phase .num { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 16px; position: relative;
                      background: var(--brand); color: #fff; font: 800 18px var(--head); box-shadow: 0 0 0 6px var(--canvas), 0 10px 20px -10px rgba(63, 53, 201, .8); }
        .phase h3 { font-size: 18px; margin-bottom: 6px; }
        .phase p { margin: 0 0 12px; color: var(--muted); font-size: 14.5px; line-height: 1.55; }
        .phase .who { display: inline-block; font-size: 12.5px; font-weight: 700; color: var(--brand-ink); background: var(--brand-soft); border: 1px solid var(--brand-line);
                      padding: 4px 10px; border-radius: 8px; line-height: 1.4; }
        .note { margin-top: 26px; padding: 14px 18px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); color: var(--muted); font-size: 14.5px; }
        .note b { color: var(--ink); }

        /* contacts */
        .contacts { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 22px 22px 20px; display: flex; flex-direction: column; }
        .card .tag { font: 800 13px var(--head); letter-spacing: .06em; color: var(--brand-ink); }
        .card h3 { font-size: 17px; line-height: 1.35; margin: 4px 0 14px; }
        .card .role { font-size: 13px; color: var(--muted); font-weight: 600; }
        .card .person { font-weight: 700; font-size: 16px; margin-bottom: 6px; }
        .card p { margin: 0 0 16px; color: var(--muted); font-size: 14.5px; }
        .card .act { margin-top: auto; }
        .card.help { background: var(--brand-soft); border-color: var(--brand-line); }

        /* forms */
        .forms { background: var(--card); border: 1px solid var(--line); border-radius: 18px; overflow: hidden; }
        .form-row { display: grid; grid-template-columns: 1fr auto; gap: 14px; align-items: center; padding: 14px 22px; border-top: 1px solid var(--line); }
        .form-row:first-child { border-top: 0; }
        .form-row b { display: block; font-weight: 700; }
        .form-row small { color: var(--muted); font-size: 13px; }
        .form-row .dl { display: flex; gap: 8px; }

        footer { margin-top: 72px; background: var(--brand-deep); color: rgba(255, 255, 255, .85); font-size: 14px; }
        footer .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px 24px; padding-top: 26px; padding-bottom: 26px; }
        footer a { color: #fff; font-weight: 700; text-decoration: none; margin-left: 18px; }
        footer a:first-child { margin-left: 0; }

        @media (max-width: 920px) {
            .hero-in { grid-template-columns: 1fr; padding-top: 28px; padding-bottom: 56px; }
            .hero-art { display: none; }
            .phases { grid-template-columns: 1fr; gap: 0; }
            .phases::before { top: 24px; bottom: 24px; left: 23px; right: auto; width: 3px; height: auto; background: linear-gradient(180deg, var(--brand), #a39dff); }
            .phase { display: grid; grid-template-columns: 48px 1fr; gap: 0 16px; padding-bottom: 26px; }
            .phase .num { margin: 0; grid-row: span 3; }
            .phase .who { justify-self: start; }
            .contacts { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .wrap { padding: 0 16px; }
            .top-actions .btn-ghost { display: none; }
            .hero-cta .btn { flex: 1 1 100%; }
            .form-row { grid-template-columns: 1fr; padding: 14px 16px; }
            footer a { margin-left: 0; margin-right: 18px; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .btn { transition: none; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/dashboards/components/announcement.php'; ?>

<header class="hero">
    <div class="wrap top">
        <a class="lockup" href="./"><img src="assets/images/mascot.png" alt=""><span><b>iSubmit</b><small>Research Digitalization</small></span></a>
        <nav class="top-actions" aria-label="Account">
            <a class="btn btn-ghost btn-sm" href="auth/register.php">Register</a>
            <a class="btn btn-light btn-sm" href="auth/login.php">Sign in</a>
        </nav>
    </div>
    <div class="wrap hero-in">
        <div>
            <span class="kicker">MCNP · ISAP Research &amp; Development Center</span>
            <h1>Your research paper, from proposal to plagiarism check.</h1>
            <p class="lead">iSubmit is where MCNP and ISAP research groups hand in their requirements, follow every review, and get their defense schedule. No more guessing which office has your paper.</p>
            <div class="hero-cta">
                <a class="btn btn-light" href="auth/register.php">Register your group</a>
                <a class="btn btn-ghost" href="auth/login.php">Sign in</a>
            </div>
            <ul class="points">
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>See what's approved, and what was sent back and why</li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Know your defense date as soon as it's published</li>
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>One account for your whole group, on any phone</li>
            </ul>
        </div>
        <div class="hero-art"><?= eagle_plain_svg('lp') ?></div>
    </div>
</header>

<main>
    <section class="block" id="how">
        <div class="wrap">
            <div class="sec-h">
                <span class="eyebrow">How it works</span>
                <h2>Five phases, always in this order</h2>
                <p>Each phase opens when the one before it is done. iSubmit shows where your group is, and who has your paper right now.</p>
            </div>
            <ol class="phases">
                <?php foreach ($lp_phases as $n => [$title, $what, $who]): ?>
                    <li class="phase">
                        <span class="num"><?= $n + 1 ?></span>
                        <h3><?= $e($title) ?></h3>
                        <p><?= $e($what) ?></p>
                        <span class="who"><?= $e($who) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="note"><b>You don't pick your defense date.</b> Your Focal Person schedules your whole section, and the date appears in iSubmit once the research office publishes it.</div>
        </div>
    </section>

    <section class="block" id="contact">
        <div class="wrap">
            <div class="sec-h">
                <span class="eyebrow">Who to contact</span>
                <h2>Questions about your research</h2>
                <p>Requirements, schedules and reviews go through your institution's Research Coordinator. Trouble with your account goes to the Help desk.</p>
            </div>
            <div class="contacts">
                <?php foreach ($lp_inst as $code => $inst): ?>
                    <div class="card">
                        <span class="tag"><?= $e($code) ?></span>
                        <h3><?= $e($inst['name']) ?></h3>
                        <span class="role">Research Coordinator</span>
                        <div class="person"><?= $inst['coord'] ? $e(implode(', ', $inst['coord'])) : 'To be announced' ?></div>
                        <p>Ask at the Research &amp; Development Center, or message the office from your dashboard once you've signed in.</p>
                    </div>
                <?php endforeach; ?>
                <div class="card help">
                    <span class="tag">HELP DESK</span>
                    <h3>Can't sign in, or something looks wrong?</h3>
                    <p>Lost access to your email, typed the wrong School ID, or need to change groups? Send a request and the administrator will get back to you.</p>
                    <div class="act"><a class="btn btn-solid btn-sm" href="auth/help.php">Open the Help desk</a></div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($lp_forms): ?>
    <section class="block" id="forms">
        <div class="wrap">
            <div class="sec-h">
                <span class="eyebrow">Blank forms</span>
                <h2>Download the forms you'll need</h2>
                <p>The current version of each form, for your institution. Fill them in, have them signed, then upload them in iSubmit at the step that asks for them.</p>
            </div>
            <div class="forms">
                <?php foreach ($lp_forms as $f): ?>
                    <div class="form-row">
                        <div><b><?= $e($f['label']) ?></b><small><?= $e(trim(($f['code'] ? $f['code'] . ' · ' : '') . $f['phase'])) ?></small></div>
                        <div class="dl">
                            <?php foreach ($f['url'] as $inst => $url): ?>
                                <a class="btn btn-line btn-sm" href="<?= $e($url) ?>" target="_blank" rel="noopener">
                                    <svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0-5-5m5 5 5-5M5 21h14"/></svg><?= $e($inst) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</main>

<footer>
    <div class="wrap">
        <span>iSubmit · Research Digitalization for the MCNP–ISAP Research &amp; Development Center</span>
        <span><a href="auth/login.php">Sign in</a><a href="auth/register.php">Register</a><a href="auth/help.php">Help desk</a></span>
    </div>
</footer>
</body>
</html>
