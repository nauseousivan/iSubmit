<?php
// terms.php — Terms of Use (2026-10-15). Public, no login; the twin of privacy.php (same look) and the
// "Terms of service" link Google's sign-in setup asks for. Plain words for students; keep it TRUE to how
// iSubmit actually works, and have the school review it like the Privacy Notice.
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$updated = 'October 15, 2026';
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
    <title>Terms of Use · iSubmit</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand: #5b50f5; --brand-ink: #3f35c9; --brand-soft: #f4f3ff; --brand-line: #dcd9fb; --ink: #1c1838; --muted: #5d5a78; --line: #e6e4f3; --canvas: #f7f6fd; }
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
        .contact { background: var(--brand-soft); border: 1px solid var(--brand-line); border-radius: 16px; padding: 18px 20px; }
        .muted { color: var(--muted); }
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
    <h1>Terms of Use</h1>
    <p class="meta">Last updated <?= $e($updated) ?> · Research &amp; Development Center (RDC), MCNP &amp; ISAP</p>

    <div class="lead">iSubmit is the research submission and tracking system of the MCNP–ISAP Research &amp; Development Center. By creating an account or using iSubmit, you agree to these terms.
        How your personal information is handled is explained in the <a href="privacy.php">Privacy Notice</a>.</div>

    <h2>Who may use it</h2>
    <ul>
        <li>Students of MCNP and ISAP doing their research, and the RDC staff who review it.</li>
        <li>Student accounts are made by signing up (with email or with Google). Staff accounts are made by the research office and approved by the system administrator.</li>
    </ul>

    <h2>Your account</h2>
    <ul>
        <li>Give your real name, School ID Number, institution, course and section. The office relies on them to find your group.</li>
        <li>Keep your password to yourself, and sign out on shared computers. You are responsible for what is done with your account.</li>
        <li>One account per person. Do not sign in as someone else, even a groupmate.</li>
        <li>If you think someone used your account, change your password and tell the office through the <a href="auth/help.php">Help desk</a>.</li>
    </ul>

    <h2>What you upload</h2>
    <ul>
        <li>Your research and your files remain yours. By uploading them you allow the RDC to store, review, check and keep them for the research process and its records, including the plagiarism check.</li>
        <li>Upload only your group's own work and the documents a step asks for. Files must be truthful: no altered signatures, receipts or forms.</li>
        <li>Do not upload anything harmful (such as viruses), anything that is not yours to share, or anything unrelated to your research.</li>
    </ul>

    <h2>Using iSubmit fairly</h2>
    <ul>
        <li>Do not try to reach pages, files or accounts that are not yours, get around the system's checks, or overload the site.</li>
        <li>Be respectful in messages to the office and to your group.</li>
        <li>Breaking these terms or the school's policies can lead to your account being disabled and to action under the school's rules.</li>
    </ul>

    <h2>Decisions and dates</h2>
    <p>iSubmit records the office's reviews and schedules; it does not replace them. Approvals, defense dates and clearances are the research office's decisions, and a defense date counts only once it is published.
        If something in iSubmit looks wrong, ask the office: their records decide.</p>

    <h2>Availability</h2>
    <p>The office works to keep iSubmit available, but it may sometimes be down for maintenance or because of problems outside its control. Keep your own copies of your files, and do not leave a deadline to the last minute.</p>

    <h2>Changes to these terms</h2>
    <p>These terms may be updated as iSubmit or the school's rules change. The date at the top shows the latest version; continuing to use iSubmit means you accept it.</p>

    <h2>Contact us</h2>
    <div class="contact">
        <b>Research &amp; Development Center</b><br>
        Alimannao Hills, Peñablanca, Cagayan<br>
        <a href="mailto:researchoffice@mcnp.edu.ph">researchoffice@mcnp.edu.ph</a> · Mon–Fri 8:00 AM – 5:00 PM, Sat 8:00 AM – 12:00 PM<br>
        <span class="muted">Or send a request through the <a href="auth/help.php">Help desk</a>.</span>
    </div>
</main>
<footer>© <?= date('Y') ?> iSubmit · Research &amp; Development Center (RDC), MCNP &amp; ISAP · <a href="privacy.php">Privacy Notice</a></footer>
</body>
</html>
