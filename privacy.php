<?php
// privacy.php — the Privacy Notice (2026-10-14), linked from the front page footer and from the consent
// checkbox on auth/register.php and auth/join.php. Public, no login. Written for students in plain words,
// under the Data Privacy Act of 2012 (RA 10173). Keep it TRUE to the code: if the system starts collecting
// something new or sends data to a new service, add it here in the same change.
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$updated = 'October 14, 2026';
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
    <title>Privacy Notice · iSubmit</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: #5b50f5; --brand-ink: #3f35c9; --brand-deep: #2d2594; --brand-soft: #f4f3ff; --brand-line: #dcd9fb;
                --ink: #1c1838; --muted: #5d5a78; --line: #e6e4f3; --canvas: #f7f6fd; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 400 16px/1.7 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        a { color: var(--brand-ink); font-weight: 600; }
        .bar { background: var(--brand); color: #fff; }
        .bar .in { max-width: 820px; margin: 0 auto; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .lockup { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; }
        .lockup img { width: 32px; height: 38px; object-fit: contain; }
        .lockup b { font: 800 19px 'Poppins', sans-serif; letter-spacing: -.02em; }
        .bar a.back { color: #fff; font-size: 14px; }
        main { max-width: 820px; margin: 0 auto; padding: 40px 20px 64px; }
        h1 { font: 800 clamp(30px, 5vw, 40px)/1.15 'Poppins', sans-serif; letter-spacing: -.02em; margin: 0 0 8px; }
        .meta { color: var(--muted); font-size: 14px; margin: 0 0 28px; }
        .lead { font-size: 17px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px 20px; margin-bottom: 30px; }
        h2 { font: 700 21px/1.3 'Poppins', sans-serif; margin: 34px 0 10px; }
        ul { padding-left: 22px; margin: 8px 0; }
        li { margin: 4px 0; }
        p { margin: 8px 0; }
        .muted { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; font-size: 15px; }
        th, td { text-align: left; padding: 10px 14px; border-top: 1px solid var(--line); vertical-align: top; }
        th { background: var(--brand-soft); font-weight: 700; border-top: 0; }
        .contact { background: var(--brand-soft); border: 1px solid var(--brand-line); border-radius: 16px; padding: 18px 20px; }
        footer { text-align: center; color: var(--muted); font-size: 13px; padding: 0 20px 32px; }
        @media print { .bar a.back { display: none; } body { background: #fff; } }
    </style>
</head>
<body>
<div class="bar"><div class="in">
    <a class="lockup" href="./"><img src="assets/images/mascot.png" alt=""><b>iSubmit</b></a>
    <a class="back" href="./">Back to iSubmit</a>
</div></div>
<main>
    <h1>Privacy Notice</h1>
    <p class="meta">Last updated <?= $e($updated) ?> · Research &amp; Development Center (RDC), MCNP &amp; ISAP</p>

    <div class="lead">iSubmit is the research submission system of the MCNP–ISAP Research &amp; Development Center. This notice explains
        what personal information it keeps about you, why, who can see it, and your rights under the
        <b>Data Privacy Act of 2012 (Republic Act No. 10173)</b>.</div>

    <h2>What we collect</h2>
    <ul>
        <li><b>Your account:</b> full name, email address, School ID Number, institution (MCNP or ISAP), course, year level, section, and your research title. Your password is stored only in scrambled (hashed) form; nobody can read it, not even the office.</li>
        <li><b>Your group:</b> who is in your research group, and the email addresses of teammates you invite.</li>
        <li><b>Your research files</b> and requirements you upload, plus the reviews, notes, decisions, defense schedules, payments and control numbers recorded about them.</li>
        <li><b>Messages</b> between your group and the office, and any <b>Help desk</b> requests you send.</li>
        <li><b>Sign-in records:</b> the time of each sign-in attempt, the email used, whether it worked, your IP address and your browser type. These protect your account from people guessing passwords.</li>
    </ul>
    <p class="muted">iSubmit does not use your camera, microphone or location, and does not use advertising or tracking tools.</p>

    <h2>Why we use it</h2>
    <ul>
        <li>To run the research process: receive and check your requirements, schedule your defenses, process statistics and plagiarism checks, and issue clearances.</li>
        <li>To tell you what happened to your paper, by email and inside iSubmit.</li>
        <li>To keep your account secure and to help you when you cannot sign in.</li>
        <li>To produce summary reports for the office (counts by phase, course and institution), which do not single anyone out.</li>
    </ul>

    <h2>Who can see it</h2>
    <ul>
        <li><b>Your group</b> sees your group's papers and progress.</li>
        <li><b>RDC staff</b> see what their role needs: Focal Persons only their assigned courses, Research Coordinators only their own institution, and the Research Director, Secretary and statisticians what their work requires.</li>
        <li><b>The system administrator</b> manages accounts and answers Help desk requests, and cannot approve or change research decisions.</li>
        <li>Your information is <b>not sold or shared</b> for marketing. It is disclosed outside the school only when the law requires it.</li>
    </ul>

    <h2>Services iSubmit relies on</h2>
    <table>
        <tr><th>Service</th><th>What it receives</th></tr>
        <tr><td>Cloud server (DigitalOcean)</td><td>Hosts iSubmit and its database, which hold everything above.</td></tr>
        <tr><td>Email delivery (Brevo)</td><td>Your email address and the message, to deliver verification codes and notifications.</td></tr>
        <tr><td>Google Fonts, unpkg, jsDelivr</td><td>Your IP address when your browser downloads the page's fonts and icons.</td></tr>
        <tr><td>Public holiday list (date.nager.at)</td><td>Your IP address when a calendar loads the Philippine public holidays.</td></tr>
        <tr><td>DiceBear avatars</td><td>Your name, to draw a default picture when you have not uploaded a profile photo.</td></tr>
        <tr><td>Microsoft Office online viewer</td><td>A Word, Excel or PowerPoint file, only when someone previews one inside iSubmit.</td></tr>
    </table>

    <h2>Cookies and browser storage</h2>
    <p>iSubmit uses <b>one cookie</b>, which keeps you signed in. It cannot be read by scripts or used by other websites, and it ends when you sign out or close your browser.
        Your browser also remembers a few settings, such as light or dark mode and whether you have seen the opening animation. None of these track you.</p>

    <h2>How long we keep it, and how we protect it</h2>
    <p>Your account and research records are kept for as long as the RDC needs them for the research process and its records, including after you finish, and are then removed or archived under the school's records policy.
        Access is limited by role, passwords are hashed, every review decision is logged with who made it, and the connection to iSubmit is encrypted (HTTPS).</p>

    <h2>Your rights</h2>
    <p>Under the Data Privacy Act you have the right to be informed, to access your information, to have wrong information corrected, to object, to have information erased or blocked where the law allows, to data portability, and to file a complaint with the National Privacy Commission (privacy.gov.ph).
        Much of your own information can be corrected yourself in your profile; for anything else, contact us below.</p>

    <h2>Contact us</h2>
    <div class="contact">
        <b>Research &amp; Development Center</b><br>
        Alimannao Hills, Peñablanca, Cagayan<br>
        <a href="mailto:researchoffice@mcnp.edu.ph">researchoffice@mcnp.edu.ph</a> · Mon–Fri 8:00 AM – 5:00 PM, Sat 8:00 AM – 12:00 PM<br>
        <span class="muted">Questions about this notice can also be sent through the <a href="auth/help.php">Help desk</a>, or to the school's Data Protection Officer through the research office.</span>
    </div>
</main>
<footer>© <?= date('Y') ?> iSubmit · Research &amp; Development Center (RDC), MCNP &amp; ISAP</footer>
</body>
</html>
