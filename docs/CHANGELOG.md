# Changelog

All notable changes to the `iSubmit` project will be documented in this file.

## [Unreleased]

### Added
- **Public front page (2026-10-14, `index.php`).** Visitors who are not signed in now see what iSubmit is, the five
  phases in order (Register → Proposal → Statistics → Final → Plagiarism, with who checks each), each institution's
  Research Coordinator by NAME only (no emails or phone numbers on a public page), the Help desk, and the blank forms
  per institution (read from `rd_form_current()`, so the Admin's uploads show), with Sign in / Register. It used to
  jump straight to the login screen. Self-contained styles in the auth language (brand violet + doodles).
  Hero = headline + an EXAMPLE group card (never real data) with the eagle perched on it; forms are small cards
  filtered one phase at a time (Proposal first, Form 002 in both Proposal and Final). RDC/MCNP/ISAP marks show
  beside the wordmark only once `assets/images/logo-rdc|mcnp|isap.png` exist.
- **Continue with Google (2026-10-15).** `config/google_auth.php` + `auth/google.php` + `auth/google_callback.php`.
  Existing email -> signed in through login.php's gates (`rd_session_login()` mirrors login.php's session: keep in step).
  New email -> `register.php` in Google mode (`$_SESSION[google_signup]`, 30 min): email forced to Google's, random
  password, `is_verified = 1`, no OTP. Students only. Hidden until `config/google_secret.php` (gitignored) exists.
- **Defense schedule calendar (2026-10-15).** `schedule_lib.php` `sch_published()` is the one source (Scheduled batches,
  sections only) for the student mini-calendar (dots + rooms on tap), `calendar_all.php` (now a month grid via
  `assets/js/defense_calendar.js`, with a Coming up panel), the public `schedule.php` and the front-page strip. The
  retired Available/Unavailable vocabulary is gone. Front-page team is a hierarchy (Director -> coordinators -> units).
- **Privacy (2026-10-14).** `privacy.php` (RA 10173 notice, kept TRUE to the code: a new data flow or service means
  a line there in the same change). Register + QR join require `privacy_consent` (form AND handler). Session cookie
  HttpOnly + SameSite=Lax (+ Secure on HTTPS) via `config/db.php`; the server repeats it in
  `/etc/php/<ver>/<sapi>/conf.d/99-isubmit-session.ini` because ~40 pages call `session_start()` before db.php.
- **Front page motion + the office (2026-10-14).** Hero parallax (sky / glow / page at different speeds, card tilt on
  mouse only), "How it works" as a scroll story with a pinned panel (phones: a plain timeline), and "Meet the RDC Team"
  (`$lp_team`: the office roster with portraits cut from their calling cards, faces only, no Focal Persons) instead
  of naming two coordinators. Edit `$lp_team` when staff change. All motion is off under reduced motion.
  Headless testing note: virtual time does not run CSS transitions, so screenshot with transitions disabled.
- **Eagle flight intro shared (2026-10-14).** The loading screen moved out of `auth/login.php` + `theme.css` into
  `dashboards/components/eagle_intro.php`, included right after `<body class="no-intro-delay">` on login AND the front
  page. Once per session (`mcnp_isap_intro_played`), on whichever of the two opens first. The front page footer now
  follows the RDC site: brand + description, link columns, the research office address / email / hours / Facebook.
- **Live server hardening (2026-10-14).** Root `.htaccess`: no directory listings; `.md`/`.sql`/lock/log/dotfiles and
  `composer.json` refused; `/.git/` answers 404. `database/.htaccess` refuses the whole folder (CLI scripts only).

### Fixed
- **Admin and Focal Person were signed out by the main link.** `index.php` only routed four roles and sent the rest to
  `logout.php`; it now routes all six, the same as `auth/login.php`.
- **Login loop after logout (2026-10-14).** Login regenerates the session id; logout clears the data, deletes the
  session cookie and destroys the session.

### Added (earlier)
- **Join a group by QR (2026-10-07).** On Research Group (`dashboards/members.php`) a leader can open "Or let them
  scan a QR" under the email invite. Teammates scan it and sign up on the new `auth/join.php` with name, School ID,
  email and password — NO emailed code, since the leader showing the QR in person is the check. They are created
  verified, active and already linked: school, course, section and research title come from the leader (never the
  form), as in register.php's leader-email flow; pending email invites for that address are reconciled. The token is
  signed, not stored (`group_join_token()` / `group_join_leader()` in `config/group_helpers.php`,
  `{leader}.{expires}.{hmac}` on `qr_secret`, 24h), so no migration. Refused: forged or expired tokens, a "leader"
  who is now a member or not active, closed registration, a taken School ID or email. Normal registration still
  sends the code.

### Changed
- **Ready for an online test host (2026-10-07).** `config/db.php` reads the server's database login from
  `config/db_local.php` (gitignored; template in `config/db_local.example.php`) and falls back to the XAMPP defaults;
  off localhost a failed connection shows a plain "temporarily unavailable" page instead of printing the DB host and
  user. The Gmail app password likewise lives in `config/mail_secret.php` (gitignored).
- **New favicon — the iSubmit eagle, on EVERY page (2026-10-07).** `assets/images/favicon.svg` was the old bird and
  only the 6 auth pages linked it; the ~40 dashboard pages (student, all staff shells, admin, module windows) had no
  icon at all. It is now a close-up of the main eagle (head + cap, so it still reads at 16px) on the violet tile,
  generated from `eagle_plain_svg()`, plus `favicon-32.png` (browsers without SVG icons) and `apple-touch-icon.png`
  (180px, full-bleed — iOS rounds it). All 47 pages with a `<head>` carry the three links (`?v=2` to beat the
  favicon cache). A new page needs the same three lines after its `<meta charset>`.
- **Login loading screen: "Eagle flight" (2026-10-07).** Replaces eagle + wordmark + progress bar. On a light
  lavender sky with the doodle wallpaper drifting past (`.intro-cinematic-curtain::before`, the pane's own doodles
  as a mask), the eagle flaps in from the lower left, loops over the stage and lands on the dotless "ı" of iSubmit
  as its dot, its dotted trail drawing behind her (the trail is the progress bar). The letters pop in as she
  passes; on touchdown she squashes, tiptoes and waves, then the curtain fades into the card (~2.6s). The path is
  built at runtime from where the "ı" actually sits (layout offsets + the font's own stem height), so she lands
  on it at any size. Tap skips. Reduced motion: she is simply sitting on the "ı". Still once per session.
- **Auth cards no longer wait 2.2s for an intro that is not playing.** `.auth-wrapper`'s fade-in carried a fixed
  2.2s delay on every auth page (register, forgot password, OTP, and login on a repeat visit). It now starts at
  once; only `body.intro-playing` (set by login's first-visit intro, cleared when the eagle lands, 6s safety
  net) holds it back.
- **Sign-in pages: "Study doodles" look, no gradient (2026-10-07).** The purple brand pane on Login, Register, Forgot
  password, OTP, Help Desk and Change password is now a SOLID colour (`--brand-solid`) wearing a faint wallpaper of
  research doodles (`--brand-doodles`: paper, check, grad cap, pencil, star, bar chart, magnifier) instead of the
  135deg gradient. It is ONE violet for everyone: the MCNP-blue / ISAP-red switch while typing the email is removed
  (login + register JS no longer set `data-theme`; the sub-line text still names the institution). The page behind the
  card gets one faint diagonal band of the same colour (`.auth-wrapper::before`); the desktop constellation stays.
  `auth_card.css` carries a twin of both for the standalone Help / Change password pages.
- **Help Desk page re-laid to match sign-in (2026-10-07).** `auth/help.php` (and `change_password.php`, which shares
  `assets/css/auth_card.css`) now wear the login page's shape: a purple brand pane with the eagle badge and a big
  "iSubmit" beside the form on desktop; on a phone that pane is the header and the form a white sheet with a handle
  rising over it, app-style. Back is a round arrow in the header. "What do you need?" is a grid of icon tiles
  instead of six full-width bars. Same field names, same POST — presentation only.
  Follow-up: the desktop card is now login's exact 900x625 (the form scrolls inside it) so moving between the two
  no longer resizes the card; the phone sheet is DRAGGABLE via the login's `bottom-sheet.js` (hence the
  `.auth-card` / `.bottom-sheet` / `#brandSubLead` hooks on the markup), with a handle, and lifts itself when a field
  is focused. The round back arrow is gone — the phone's own back does that, as elsewhere in the system.
- **Bottom-sheet handle was invisible on Login / Register / Forgot password / OTP.** `.drag-handle` painted
  `var(--border-color)`, which theme.css never defines, so it rendered transparent. It is now `#d9d5ee`.
- **Help Desk link moved (2026-10-06).** On the login page "Need help?" shared a row with "Forgot password?"
  as a rival grey link. "Forgot password?" now sits alone on the right, and help is a quiet centred line
  under the LOGIN button ("Trouble signing in? Ask the Help Desk"). The register page gets the same line
  ("Stuck registering?") under the form; it had no way to reach the Help Desk. On phones that line is
  hidden and a "· Get help" link rides the "First time? / Already have an account?" line instead, so the
  two do not stack.
- **Login / Register on phones (2026-10-05): the white sheet no longer covers "iSubmit".** The sheet rested at a fixed
  35% of the screen, under a ~180px mascot badge, so on short phones (browser bars eat the height) it slid over the title
  and "Research Digitalization Platform". The badge is smaller on phones (`theme.css`), and `assets/js/bottom-sheet.js`
  now parks the resting sheet 16px below `#brandSubLead` (re-checked on load, font load, resize, and when the sub-line
  changes to the school's name while typing). Desktop is untouched.
- **Login data environment (fig 11b, 2026-10-05) now matches the paper's ERD.** Only `users`, with the columns sign-in
  reads (`user_id`, `email`, `password`, `role`). Dropped `username` (login is by email) and `login_attempts`
  (not in the ERD). The OTP stays in registration (11a).

### Added
- **The Focal Person is on the staff shell (2026-10-01).** `dashboards/focal.php` is now the same shell the Director,
  Coordinator, Statistician and Admin use (`_staff_home.php`, `sh_model('Focal Person')`): rail **Home · Proposal ·
  Final · Workspace**, the live mascot hero, group search, light/dark toggle, avatar menu, the group profile modal,
  Recent activity, and badges that refresh every 20s (`nav_badge_counts.php` → `fp_counts()`).
  - **Hubs** lead with **"Every section, at a glance"** — the third twin of the Statistics/Plagiarism boards, one chip
    per room across Requirements · Needs a date · Coordinator · Secretary · Published (`fp_board()` in `focal_lib.php`,
    built in a fixed handful of queries). A chip opens that room in the desk. Under the cards: **By course** (progress
    bar + files to review), also on Home under the Stages cards.
  - **Her working screens moved to `dashboards/focal_desk.php`**, opened as module windows by the Requirements /
    Defense dates cards. Behaviour unchanged; no header bar, no phase switch (the window's phase is the hub's).
    The drill-down cards (courses, years, rooms, groups) are one helper, `fd_card()`, in the shell's card language:
    soft tile, icon tile + count badge, Outfit title, progress bar (groups: one pip per requirement), status chips;
    **purple** when something inside waits on her, **green** when finished. Groups needing her sort first. Panels,
    document cards and screen titles take the same radius and display face.
  - **Requirements is two screens now (Claude Design concept).** (1) Course, year and room in ONE screen: a tree
    (course → year → room, files-waiting badge per room) with "Across your courses" totals beside the room's groups
    as rows (letter tile, members, one pip per requirement, when they last sent), filter tiles (All / Waiting on you /
    Waiting on them / Complete), search, and "Remind all N" for groups that sent nothing. With no room chosen it opens
    the one with the most files waiting. (2) Group review: the list (Needs your review with checkboxes · Sent back ·
    Approved · Not sent yet + Remind) beside the file, and ONE action bar under the file — Approve / Send back, a
    note (required to send back), and a button that says what it does ("Approve, clears 4" for the Capsule,
    "Approve 2 selected"). Same `review_doc` / `review_bulk` writes as before. **Defense dates uses the same layout**
    (the tree + that room's who/which-day screen, opening on the room whose date is hers to set), so the
    course → year → room card walk is gone from both (`fp_side()` draws the tree for both).
  - **Defense dates rebuilt (Claude Design concept).** Titled "Put 4th Year A on a date". A **ticket strip** shows
    every date the room already has in motion (date tile, groups, slots, "tomorrow", and the You → Coordinator →
    Secretary ticks; Withdraw on unpublished ones; groups booked outside a batch too). **Step 1** lists ready groups
    pre-ticked, or "No group is ready yet" with **Closest to ready** (pips + "N to go"). **Step 2** is a fixed
    six-week calendar whose days say the one thing worth knowing (this room's group, "9 left", Full, Closed, Too
    soon) with a Soonest open pill. A dark **send bar** always says what "Send to Coordinator" will do or why it
    cannot. The tree's badges read "N ready" / Sent back / With office, and the left column explains the chain.
    Same `endorse_batch` / `cancel_batch` writes; the old schedule CSS and the newest-batch-only context were removed.
  - **Reports (new rail item, asked for by a focal person).** `dashboards/focal_reports.php` + `focal_reports_lib.php`
    (`fr_dataset()`: ONE dataset in a fixed handful of queries) + `assets/js/focal_reports.js` + `assets/css/focal_reports.css`.
    One filter row (Proposal/Final, room, search) over five summary tiles and four reports: **Submissions** (a group ×
    requirement matrix — who sent what and who has not; filter chips, tap a column for who has not sent it, the
    "haven't sent anything" names with Remind), **Requirements** (a stacked bar per requirement — approved / waiting /
    sent back / not sent — with average review time; tap a part for the names), **Schedule** (how far groups have got,
    tap a step for who is in it or not yet; success rate, sent back/withdrawn, average you-to-published time, every date
    sent up with each chain step's duration), **Groups** (sortable table with members and School IDs). A drawer per
    group (members, every requirement with who decided it, the date, "Open in Requirements"). **Export CSV** of the open
    report (Excel-ready) and **Print** (Save as PDF). **Visualize**: a 3D overview drawn on a canvas with no library
    (works offline) — four stacked discs, courses → rooms → groups (a bar each: height = share approved, colour =
    state) → defense dates, joined by lines; drag to turn, scroll to zoom, hover traces a group's path, click opens it.
    Group states are status colours and always carry an icon and a word.
  - **Admin → System → Forms: the office maintains the blank forms itself (2026-10-05).** Schools change their form
    formats; this used to mean editing code. New `config/forms.php` (loaded by `config/system.php`) holds the ONE
    catalogue of the 10 blank forms students download — Form 002, Assigned Adviser, Endorsement (Form 006), Proposal
    Review (Form 008), Capsule template, Proposal Clearing, RDC Form 011 (Statistics), Minutes, Defense Checklist
    (Form 005), RDC Form 13 (Plagiarism) — and `rd_form_url($pdo, $slot, $uid)`, which every download button now goes
    through (Proposal, Statistics, Final and Plagiarism cards; no hardcoded form links are left). New Admin screen
    `admin_forms.php` + `admin_forms_handler.php` (on the db.php Admin allowlist): each form, grouped by phase, with an
    MCNP and an ISAP side — the current file (Built-in / Your upload, date, who), Preview, Replace (that school or both,
    with an optional note) and its versions (any can be put back, or "the file that came with the system"). Uploads are
    checked by content (a real PDF / image, not just the name), 15 MB at most, stored under `forms/` (no listing, nothing
    runs). New table `form_templates` (migration `2026-10-05_form_templates.sql`, applied). With nothing uploaded, every
    download is exactly what it was. Maintenance only: students are not told a form changed (the user's call).
    `df_application_form()` / `df_institution_form()` now delegate to it.
    Same day, redesigned (the first screen was a wall of 20 identical boxes): a document LIBRARY — one compact row per
    form with phase pills (All · Proposal · Statistics · Final · Plagiarism) and a search; ONE "Both schools" cell when
    MCNP and ISAP get the same file (7 of 10), two when they differ; PDF/IMG type badges, Built-in vs "Updated <date>".
    Clicking a row opens a side panel: what students get now (+ Preview), a drag-and-drop upload (checks type and size
    before sending; MCNP / ISAP / Both), and a version timeline per school with "Use this". Handler unchanged.
  - **Form 002 per institution (2026-10-05).** The blank Application for Research Defense was ISAP's for everyone.
    New `df_application_form($pdo, $uid)` (defense_lib.php) hands MCNP students `application_mcnp.pdf` (MCNP-QMS-DCO-RDC
    Form No: 002) and everyone else `application_isap.pdf`, from the student's own department (inherited from the leader).
    Used in all three places that offered the blank: the Proposal card (item 17), the Final card (item 20) and the
    defense stage bundle. Unrecognised departments still get the ISAP form, as before.
    Same day: the **Endorsement** and **Assigned Adviser** forms too — `df_institution_form($pdo, $uid, $name)` gives MCNP
    students `{name}_mcnp.pdf` whenever that twin exists (else the shared `{name}.pdf`), via `df_is_mcnp()`. Wired into the
    Proposal card (Assigned Adviser, Endorsement), the Final card's cleared banner (Adviser Endorsement, Form 006) and
    the stage bundle. Adding an MCNP twin of any other blank later is just dropping `{name}_mcnp.pdf` in the root.
  - **Mascot follow-up (same day, user): the PLAIN eagle is the main one; the "Hi!" pose is gone.** The no-diploma
    eagle is now the resting face on sign-in, the staff hero (wink only when all caught up), Change password and the
    loading screen. New `assets/mascot/eagle_svg.php` draws it inline with named parts, so its **feathers move like the
    old bird's**: the wings flap gently at rest, and on the loading screen (after load) and on tap/hover she goes up on
    her right foot, lifts the left and waves her left wing high. The live mascot shows this inline eagle for `plain`
    and swaps to the pose pictures (covering-eyes, peeking, oops, happy, wink) only when needed. Nothing uses `hi.svg`.
  - **The new mascot: the iSubmit eagle, with poses (2026-10-05).** The "Quill" bird is retired everywhere.
    `assets/mascot/eagle/` holds ten cleaned SVGs (signature metadata stripped, ~5.5 KB each): plain, hi,
    covering-eyes, peeking, happy, celebrating, thinking, sleeping, oops, wink — one shared frame, so swapping never
    jumps. Where each lives (the office's mascot guide):
    - **Loading screen** (login's intro curtain): the eagle waving with her "Hi!", bobbing, then a wiggle.
    - **Sign-in, Register, Forgot password, Verify code** (shared `assets/mascot/mascot.php` + `mascot.js`, same API
      as before so no page logic changed): **hi** at rest, **covering-eyes** while typing a password, **peeking**
      when "Show password" is on (the page's `togglePassword()` is wrapped), **oops** while an error is showing
      (until you type again), **happy** on a success message, a wiggle on tap.
    - **Change password**: the live eagle covers her eyes while you type. **Help**: **thinking**, then happy once
      the request is sent or worried on an error.
    - **Staff home hero** (every staff shell): **hi** when there is work, **wink** when "you're all caught up";
      the 20-second refresh keeps her in step. The eagle now fills the white circle.
    - **Empty states** (`components/quill_empty.php`, all staff queues): **sleeping** for "nothing waiting on you",
      **thinking** for "nothing here yet" / no match, **wink** for good news ("Nothing has bounced", "Your desk is
      clear"), **happy** for "Everyone has been notified". JS-built ones too (`NX_EMPTY(…, pose)`).
    - **Student portal**: the greeter waves with a **wink** (her own bubble says Hi) and matches the calendar message
      (closed → sleeping, open → happy, Saturday → wink); every toast carries **happy** / **oops**; the defense
      billboard shows **happy** (cleared), **thinking** (in review / proposed), **celebrating** (scheduled),
      **oops** (withdrawn); the "All Milestones Cleared" banner and an unlocked Publish card **celebrate**.
    - `assets/images/mascot.png` re-drawn as the plain eagle at the same 360×420, transparent — so the student
      logo, Help/Change-password brand, QR posters, stat desk, `q.php` and print letterheads all follow.
  - **TRIAL — the rail's edge softened, Director only (2026-10-02).** Same full-height rail; its hard 1px right
    line becomes rounded right corners (30px) and a soft shadow that follows them (on a phone, where the rail is the
    bottom bar, the TOP corners round instead). Opt-in by `body.sh-soft` (only `director.php`, so the Secretary sees it
    too); styles at the end of `staff_home.css`. A floating, divided dock was tried first and dropped by the user.
    To roll out: add `sh-soft` to the other shells' `<body>`; to drop: remove it.
  - **No more white flicker when reloading in dark mode (2026-10-01).** `portal.js` put the theme class on `<body>`
    only at DOMContentLoaded, so every staff page (and every module window, which loads portal.js too) painted its
    LIGHT version first. Now, from `<head>`, it gives `<html>` the dark canvas + `color-scheme` immediately and a
    MutationObserver puts the theme/accent classes on `<body>` the moment the parser creates it (verified: the body is
    already `theme-dark` while `readyState` is still `loading`); `<html>` follows later theme switches too.
    `.window-frame` gets `color-scheme: normal` so a window that is still loading shows the dark surface instead of an
    opaque white `about:blank`.
  - **Printing looks like a real report (2026-10-01, user: "any printing should look good").** New shared
    **`assets/css/print.css`** on every page with a Print button (office Analytics, Admin Analytics, Focal Reports):
    white A4 in the LIGHT palette whatever the screen theme (dark mode used to print dark pages with an invisible
    title), colours kept on charts (`print-color-adjust: exact` — the browser dropped every bar), a letterhead
    (`.rp-head`: mascot, Research & Development Center · MCNP – ISAP, report name, prepared by, printed at),
    "Page X of Y" footers (`@page` margin boxes), a "Scope:" line, no buttons / filters / tabs / "Tap…" hints
    (`rpHint()` wraps them in `.rp-hint`), wide tables in **landscape** (`body.rp-wide` → `@page wide`), table headers
    repeated on every page (a `beforeprint` script gives each sortable `<th>` button a plain-text twin — Chrome left
    the repeated buttons blank), tables no longer cut off on the right, panels no longer leave half-empty pages, and the
    Focal matrix shows all seven requirement columns on its own page. Column charts keep their 16:5 shape on paper
    (`preserveAspectRatio="none"` stretched the labels). The QR poster page was already print-built; checked, unchanged.
  - **Visualizer follow-up (same day, user feedback).** It is called the **Visualizer** everywhere a person reads it
    (page, window, buttons) — never "hologram". It is **no longer permanently dark green**: both pages load
    `portal.css` + `portal.js`, `holo.css` is built only on the portal tokens, and `HoloApp.palette()` reads the
    viewer's `--accent` / `--ink` / `--surface` for the canvas — so it follows THEIR light/dark mode and accent
    colour, and re-colours live when they switch (MutationObserver on `body` → `onTheme` → rebuild). Data colours
    switch to the dataviz slots for that mode; staff roles are tones of the viewer's accent. **Months:** `an_months()`
    is now CONTINUOUS from the first month with data to this one (a quiet month is listed and charted as zero, not
    skipped); the picker groups months by year with an **All of YYYY** choice (`HoloApp.inPeriod()` matches either);
    the "last six months" pop-outs always show six calendar months. The data itself starts in July 2026, which is
    why only a few months exist yet. The Analytics "Visualizer" button wears the accent too.
  - **New Analytics + holograms for the Admin and the office (replaces `analytics.php`).** Built on two shared layers:
    **`assets/js/insights.js` + `assets/css/insights.css`** (the chart kit: bars, stacked bars, stacked columns,
    donuts, tiles, sortable tables, CSV, one hover tooltip on every mark; dataviz categorical slots + status colours)
    and **`assets/js/holo.js` + `assets/js/holo_app.js` + `assets/css/holo.css`** (the 3D engine, plus the screen
    around it: pop-outs, live wires, tooltip, month picker, institution switch, and **adjustable telemetry** — hide any
    block, collapse or drag the panel, remembered per screen in localStorage). Every screen reads ONE dataset built
    in PHP and filters it client-side, so Analytics and its hologram always agree.
    - **Admin → Analytics** (`admin_analytics.php` + `admin_analytics_lib.php` `an_admin_dataset()` +
      `assets/js/admin_analytics.js`): accounts, not papers. Filters: month · institution · course · role. Tabs:
      Overview (new accounts per month, students by institution and course, staff by role), Accounts (staff table,
      status, who needs attention), Institutions & courses (leaders/members per course, sections), Help desk (what
      people report, open vs. answered per month, answer time, the requests), Sign-ins (per day, failure reasons,
      staff gone quiet). Export CSV + Print. Students ship as BUCKETS, never one row each.
    - **Admin → Visualize** (`admin_visualize.php`, reworked): top to bottom **staff roles → institutions →
      courses (bar = students, or joined that month) → help desk (bar per kind of issue, wired to the institutions it
      came from)**. Pop-outs: a role → its accounts and what they cover; an institution → its courses and staff; a
      course → its sections and focal persons; a help category → six months open/answered + the latest requests; a
      person → their 30-day sign-ins (`?pod=staff`). The paper/group view and `admin_visualize_lib.php` are deleted.
    - **Office → Analytics** (`office_analytics.php` + `office_analytics_lib.php` `an_office_dataset()` +
      `assets/js/office_analytics.js`), for the Director, Secretary, Coordinators and the Statistician (Workspace),
      **scoped by `aa_scope_sql()`**: filters month · institution · course · stage + search. Tabs: Overview (stage
      donut, activity per month, stage mix by course), Courses (completion per phase, activity), Phases (how far each
      group is in each phase — tap a bar for the names), Schedule (defenses per month, how the date requests went, who
      has a date), Groups (every group, sortable), People (staff and the groups each focal person covers).
    - **Office → Visualize** (`office_visualize.php` + `assets/js/office_visualize.js`), Director + Coordinator:
      **staff roles → institutions → courses (bar = groups, or that month's activity) → phases (a bar per stage)**,
      courses wired to the stages their groups are in. **Aggregated on purpose — no group is its own mark**, so it
      scales; names live in Analytics. Pop-outs: role → people, institution → courses + stage mix, course → rooms +
      stage mix + focal persons, stage → which courses hold it + six months of that phase.
    - `analytics.php` now REDIRECTS (Admin → `admin_analytics.php`, everyone else → `office_analytics.php`).
      `sh_model()`: office Insights = Analytics · Visualize · Research history; the Admin's = her own two + history.
    - Fix: `holo.js` sizes its canvas from the layout box, not `getBoundingClientRect()` — a pop-out sized while it
      was still scaling in stayed a blurry thumbnail.
  - **Archive (Workspace card, `focal_desk.php?view=archive`).** Proposal / Final, each with two tabs:
    **Requirements** (groups whose every requirement is approved, with who approved each one and when, the Capsule's
    bundled three nested) and **Schedule** (published dates already held, with every group, slot and who signed each
    step). Like the decision log it renders ONE MONTH: a month index is always the page, only the chosen month loads.
    Room chips and search within the month. The room's ticket strip on Defense dates is unchanged — it keeps every date
    the room has had, as in the concept (an archive button there was tried and removed). The hub board says
    "Held on Sep 30"; a date still in the chain stays on the strip after its day, marked "The day passed".
  - **Notifications drawer redesigned**: grouped by Manila day (Today / Yesterday / This week / Earlier), All · Files ·
    Dates tabs with counts, "Mark all read", and each row as headline + where (room or group) + message.
    `fp_feed()` rows now carry `type`, `head` and `where` beside the old `title`/`body`.
  - **Back buttons**: every desk screen has one labelled pill naming where it goes ("← Proposal", "← Section A",
    "← Workspace"); the hub pill closes the window onto that hub in the shell. The unlabelled arrows are gone.
  - **Sizing**: the desk uses the window's width (max 1680px, was 1240px), 13–16px type in the tree and rows (was
    11–13px), and the review list fills the height beside the file with the action bar always in view.
  - **Remind** — new `remind_groups` action in `focal_handler.php`, the office's `remind_upload` for her: email only,
    no status change, once per group per requirement per 6 hours, scoped with `aa_may_touch_student()`.
  - **Sections** (Workspace) — new screen for the `create_section` / `delete_section` / `assign_section` handlers that
    already existed with no UI: rooms per course by year, add a room, delete an empty one, and the **Unassigned tray**
    (groups with no section, moved in one click). Its card badges the tray count.
  - **Settings** — the shared staff Settings (profile, avatar/photo, password, theme, colour) plus a read-only
    **Your courses** section. `staff_settings_handler.php` and `group_profile_data.php` now accept her role.
  - **Bell** — the notification drawer lives in the shell's top bar (`$sh_bell` slot); rows open the desk at the place
    they name. Old `focal.php?view=…` links open the shell and then that screen.

### Fixed
- Focal Person feed ages used `strtotime()` on MySQL DATETIMEs (PHP is Europe/Berlin) and read "just now" for six
  hours; `fp_feed()` now uses `UNIX_TIMESTAMP()` columns. The unread marker key changed (`fp-notif-seen-{id}`) so the
  old, six-hours-ahead value cannot hide new items.
- The schedule screen offered groups already holding a booking from an earlier round (it only looked at the newest
  batch); it now uses `fp_booked()`, the rule `endorse_batch` refuses on, and marks them "has a date".
- `_staff_home.php` no longer runs the Statistics/Plagiarism board queries for rails without those hubs (Admin, Focal).
- **QR codes (2026-10-01).** Migration `database/migrations/2026-10-01b_qr_codes.sql` (applied): table `qr_links`,
  settings `qr_secret` and `site_url`. QR images are drawn in the browser by `assets/js/qrcode.min.js` (qrcodejs
  1.0.0, MIT, vendored so it works on the office Wi-Fi without internet).
  - **Admin QR codes** — `dashboards/admin_qr.php` (+ `_handler`, `_print` for an A4 poster), in the Admin's System hub.
    A printed code carries a short link `q.php?c=CODE` (root), so the Admin can repoint it without reprinting;
    Retire makes old posters say "no longer in use"; New code kills every old printout. The site address the codes
    use is set there too (it warns when it is `localhost`, which phones cannot open). Seeded: Open iSubmit, Need help?,
    Create your account.
  - **Statistician desk QR** — `dashboards/stat_desk_lib.php` + `stat_desk.php`, shown on the student's Statistics card
    (`module_statistics.php`) while the coded data (item 30, Coded Data Review) or all five post-payment requirements
    (31-35, at least one pending, none flagged) wait for the statistician. After going through them in person she
    scans it and confirms; the confirm posts to the EXISTING `bulk_verify` in `admin_module_dynamic.php`, so scope
    checks, the Phase 1 → Phase 2 move (to Finance) and the Phase 7 move are unchanged. Optional: the online review
    still works. The link is HMAC-signed and bound to those exact upload ids, so a re-upload retires it. The other
    institution's statistician is refused; Coordinator/Director see it read-only; a student is told it is not for them;
    a signed-out scanner signs in and is returned (`$_SESSION['login_next']`, app paths only, in `auth/login.php`).
  - **Phase-card posters** (seeded: Proposal Defense, Statistics, Final Defense, Plagiarism Test → `dashboards/student.php?module=…`).
    A signed-out scanner signs in and lands on that card (`q.php` sets `login_next`; `auth/login.php` never sends staff
    into `student.php`).
  - **Fixed: `student.php?module=` opened a card even when it was LOCKED.** The deep-link handler now reads the same
    `$unlock_*` flags and lock reasons as the cards and shows the lock note (`lockedCardNote(reason, el, ms)`, new optional
    duration) instead of opening. The modules themselves still do not check the lock server-side.
  - **Site address is automatic** (`rd_site_url()`): the configured `site_url`, else the address people used — honouring
    `X-Forwarded-Proto/Host` behind a proxy — and, when that is `localhost`, this computer's Wi-Fi address (`rd_lan_ip()`),
    so QR codes made while testing on the server open on a phone.
  - `rd_app_url()` no longer returns a trailing slash from the app root.
  - Screenshots `docs/diagrams/screens/fig17*`.
- **Admin system tools (2026-10-01).** Migration `database/migrations/2026-10-01_admin_system.sql` (applied):
  tables `help_requests`, `login_attempts`, `system_settings`, column `users.must_change_password`. Helpers in the
  new `config/system.php`, loaded by `config/db.php`.
  - **Help Desk.** `auth/help.php` — a request form reachable from the login page ("Need help?") without an
    account, and from student Settings; signed-in users see their requests and the replies. Categories: can't get
    into my email, reset my password, fix my details, group change, suggestion, other. Honeypot + 5 per hour per IP.
    The Admin's inbox `dashboards/admin_help.php` finds the likely account (same email / School ID / name) and
    `admin_help_handler.php` answers: the reply is emailed and lands in the student's notifications, and can
    optionally reset the password to a temporary one.
  - **Forced password change.** `users.must_change_password` = 1 holds the person on `auth/change_password.php`
    (enforced in `config/db.php` for every page) until they choose their own. Set by the Help Desk reset and by
    the Admin's "Set a new password" in Staff and Student accounts (ticked by default).
  - **Access log + lockout.** `auth/login.php` records every attempt (ok / wrong password / no account / locked /
    inactive / maintenance). N wrong passwords within M minutes lock that email (defaults 5 and 15); a success or
    an Admin unlock clears it. `dashboards/admin_access_log.php`: today's numbers, who is locked now (Unlock),
    the paged log with device and IP.
  - **System settings.** `dashboards/admin_settings.php` + `admin_system_handler.php`: announcement banner
    (`components/announcement.php`, on every portal and the login page), maintenance mode (signs everyone but the
    Admin out), student registration open/closed, defense slots per institution per day (now read by
    `defense_lib.php` instead of a hard-coded 10), and the lockout numbers.
  - Admin rail: Accounts gains **Help Desk** (badge `help_open`); new **System** hub (Access log with badge
    `locked_now`, System settings). `rd_admin_counts()` feeds `admin.php` and `nav_badge_counts.php`.
  - Screenshots `docs/diagrams/screens/fig15*` (manuscript 4.1 features) and `fig16*` (these tools).
- **Paper figures, per phase (2026-10-01).** `docs/diagrams/fig14_phase_data_environment.php` draws eight data
  environment diagrams, a submission and a review for each of Proposal, Statistics, Final and Plagiarism
  (`fig14a`–`fig14h_ded_*`), with a matching student-side / staff-side screenshot each
  (`screens/fig14a`–`fig14h_screen_*`). The Chen-notation class now lives in `docs/diagrams/_ded_lib.php`
  (connector bends count toward the fitted canvas). The Admin was added to the use cases (`fig9f_usecase_admin`),
  the context diagram (`fig12`, seventh entity) and the HIPO chart (`fig10`).
- **Admin role (2026-09-30).** The evaluators asked for a system administrator. New role `Admin`, its own shell
  `dashboards/admin.php` on the shared staff home (`sh_model('Admin')`: Home · Accounts · Research · Insights ·
  Workspace). Migration `database/migrations/2026-09-30_admin_accounts.sql` (applied): `users.role` gains `Admin`;
  new `users.account_status` (`active`/`pending`/`rejected`/`disabled`), `created_by`, `reviewed_by`,
  `reviewed_at`, `review_note`. One account seeded: **System Administrator, `admin@mcnp-isap.local`** (user 271).
  - **Account approval.** The Director and the Coordinators still create staff accounts exactly as before, but
    what they create now starts `pending` and cannot sign in until the Admin approves it (Approve on the row,
    "Do not approve" with an optional reason under Manage). What the Admin creates is active at once.
    `admin_accounts_handler.php`: `approve_account`, `reject_account`, `edit_details` (Admin only).
    The Director cannot see, create or manage an Admin account.
  - **Staff Accounts** shows a "Waiting for approval" section + pill, who asked and when, and "Not approved" rows
    with the reason. The Admin's Home badge counts pending accounts (`aa_pending_count()`, `nav_badge_counts.php`
    key `acct_pending`).
  - **Student Accounts** (`admin_students.php` + `_handler` + `_lib`, Admin only): fix name/email/School ID/year,
    change a leader's group name, institution, course and section (copied to every member), move a member to
    another group, set a password, mark an email verified, disable/enable sign-in, create a student (leader or
    member). Same rules as registration; no delete.
  - **Research, view only.** The Admin may open the Proposal/Final checklists, Statistics progress, Plagiarism
    results, both Approvals queues, Analytics and Research history. `config/db.php` refuses every POST from the
    Admin except the account handlers and her own Settings (JSON 403 for fetch, flash + redirect for forms), and
    `components/admin_view_only.php` shows a "View only" pill and dims POST buttons on those screens.

### Fixed
- **Disabling an account did not stick.** Disable was `is_verified = 0`, and the login sent such an account to the
  email-OTP screen, which set it back to 1 — the disabled person re-enabled themself. Sign-in is now decided by
  `account_status`, checked at login before the OTP branch and on every request (`config/db.php` signs a disabled
  account out mid-session). For staff, `is_verified` moves with it so the staff-email queries keep skipping them.
- **Self-registration trusted a hidden `role` field**, so anyone could sign up as a Research Director (or Admin) by
  editing the form. `auth/register.php` now always creates a `Student`.

### Changed
- **Focal Person scheduling copy (2026-09-30).** The section screen said a group is ready "once its five proposal
  requirements are approved" (there are seven) and, for Final, "once its Form 002 is approved" (Form 002 has not
  gated anything since 2026-09-25 — all seven final requirements must clear). Both now say "all seven". Text only.
- **Plagiarism History works like Statistics' (2026-09-29).** The Plagiarism hub's right side is now the
  History PANEL (head opens `?phase=plag&view=payments&arch=1`; moved forward · sent back · cleared, counted as
  the History screen counts them, plus the latest five decisions), replacing "Recent in Plagiarism" — which
  moved INSIDE the History screen (`.lh-recent`, now rendered for both phases). A Recent row opens the group
  at its current plagiarism step with it selected (`&g=`).
- **Plagiarism: three steps, one screen each, plus an at-a-glance board (2026-09-29, Claude Design concept).**
  The hub leads with **Every scan, at a glance** — the Statistics board's twin (`sb-board`, four columns:
  Manuscript · Payment · Checking · Released; solid = your move, outline = the group's, red = sent back,
  green = cleared; a pill opens its step with the group selected) — then plain step cards like every other
  hub. The steps are `?phase=plag&view=manuscript|payment|result` (**Confirm manuscripts · Log payments ·
  Release results**, `.pz-*`): groups on the left, the selected one on the right under a purple header with a
  three-step bar, and one decision with a Yes/No switch and a footer that says what happens next. Log payment
  waits for "Looks good" on both documents and a control number (live preview); the similarity and
  AI-writing scores travel in the note the group receives (no new column). Every form posts the fields its
  handler already took. New `plag_pipeline_lib.php` (`pp_rows` — scoped — `pp_step`, `pp_step_counts`,
  `pp_badge_counts`, `pp_control_prefix`, `pp_current_pay_docs`); badge keys `plag_manu` / `plag_log` /
  `plag_check` in `nav_badge_counts.php` and all three shells. `view=review|cleared|payments|progress`
  redirect to their step (GET) or render it (after a POST); `payments&arch=1` stays the History log, reached
  from "Logged payments". The old desks' markup, queries and JS are deleted. `plag_acknowledge_payment`
  builds its prefix with `pp_control_prefix()` (same output) so the preview cannot drift from the mint.
- **Every Turnitin revision is a new, PAID round (office rule, built 2026-09-29).** A revised
  manuscript used to jump straight back to Turnitin unpaid (`upload_handler.php`), and the email
  said "you do not pay again". Now any manuscript upload goes to *Manuscript Review*; receiving it
  sends the group to pay again. A round is DERIVED — it starts at the latest `revision` row in
  `plagiarism_payment_logs`; no table, no ALTER — and only that round's form (41) and receipt (42)
  count (`pp_current_pay_docs()`), in the upload handler's "both in?" check and on the student's
  cards (round 1's Approved pair would otherwise hide the upload buttons). The group KEEPS its
  control number; the new receipt's number is logged against it. Student module: a "New scan, new
  payment" notice in round ≥2 and a *Revise and re-send* panel. Revise email/activity copy updated.

### Fixed
- **Student Plagiarism card said 100% at step 2.** It read item 4's upload status, so the moment the
  office received the manuscript it showed 100% / Completed while the group was still paying — and
  `$overall_complete` fired with it. It is now phase-driven like Statistics (`$plag_phase_meta`:
  0 · 15 · 30 · 50 · 70 · 100), chip/footer/ring from the phase, a phase ladder in the hover peek,
  and — same rule as Statistics — it never goes backwards: a paid-again round holds at 70%.

### Changed
- **Notify redesigned (`?phase=plag&view=release`, 2026-09-29, `.nt-*`).** Same language as the
  Approvals queue: a title header with a **Waiting · Notified** pill group, one purple feature card
  for the group that defended longest ago (Defense day ✓ → You notify → They send it), compact rows
  for the rest, and the not-yet-defended split into their own quieter **Defends soon** section
  (outline "Notify early" button, same early-warning confirm). Side panel: *What notifying does* +
  *Recently notified*. **Notified is now a follow-up list, not a trophy case:** each row carries a
  four-node spine of where the group got to in Plagiarism (Manuscript · Payment · Turnitin ·
  Cleared, from `plagiarism_checks.status` via `nt_plag_stage()`), a "No manuscript after N days"
  chip once a week passes with nothing sent, the "Before the defense" chip, client-side search,
  and a *Where they are now* panel whose four counts filter the list. The group-queue filter bar
  and sidebar are hidden here (`$is_notify_view`) — they never touched this query.
  **Unchanged:** the `release_manuscript` handler, the form's fields (`action_type`, `csrf_token`,
  `student_id`) and both confirm texts. The query only gained `released_days`
  (`TIMESTAMPDIFF` in MySQL, never PHP — see the Berlin note). Orphaned `.rel-*` CSS removed
  (`.rel-blurb` stays: the payment desk uses it).

### Fixed
- **Student defense copy follows the real chain (2026-09-29).** Students stopped picking dates on
  2026-09-19, but the words never caught up: the billboard said "Book now" / "Pick a date" /
  "Reschedule now", the stage rail read Apply → Pick a date → Scheduled, and the proposal ticket
  sent groups to Final Defense — which stays locked until Statistics is released. Now:
  - `df_stage_meta()` (`defense_lib.php`) is the one source of the wording. Keys and tones are
    unchanged; only `label` / `headline` / `blurb` changed. With no booking it now takes the
    requirements-cleared flag into account ("Finish your requirements first" vs "Waiting for your
    date"), and `student.php` passes that flag instead of the default that always claimed cleared.
    The Final card's footer reads its label/headline rather than a second drifted copy.
  - `dashboard_billboards.php`: honest copy for every state. The date chip shows only for a
    scheduled date, or as "Proposed · …" (no slot) for a proposed one — `cancel_batch` leaves
    `defense_date` on cancelled rows, so the chip used to advertise a withdrawn date.
  - `defense_scheduler.php`: rail is Requirements → Date proposed → Scheduled; the read-only preview
    describes the focal-person flow; the proposal ticket's next step and button open **Statistics**
    (`dfGoStats()`); no more empty document grid with two orphan scroll arrows (application docs are
    always `[]` now); dark mode no longer puts the ticket on a white page.
  - `module_proposal.php`: the Schedule pill tooltip said "all five requirements" (there are seven).

### Removed
- **Dead Director screen and its unguarded handlers.** `director.php` still carried the old
  "Statistics & Approvals" view (unreachable — nothing called `showStatisticsDashboard()`) and the
  four POST handlers only it used: `verify_payment`, `final_signoff`, `verify_upload`,
  `dispatch_email`. None checked the CSRF token, so a forged cross-site POST could approve uploads
  or send mail as the Director. All removed, with the five queries that fed them or nothing.
  `admin_module_dynamic.php` keeps its own (guarded) `verify_upload`.
- **Dead search/clock script in `statistician.php`** — it targeted `#workspaceGlobalSearch` and
  `#navClockTime`, which no longer exist, and its Ctrl+Space handler swallowed the keystroke.

### Changed
- **The Coordinator is on the new staff home (2026-09-29).** All four staff accounts now use the
  same design: Director, Secretary, Statistician and Coordinator. `coordinator.php` drops the old
  side nav and `_master_overview.php` for `_staff_home.php`, the same as `director.php`. Her
  label names her institution ("Research Coordinator · ISAP"). Her Schedule cards read
  "Nothing to log" by design, because the Secretary logs and she confirms. Home's Stages also
  drops the History card from the Statistics row, as the hub did.

### Fixed
- **The staff home is institution-scoped.** The group finder, Recent feed, "research groups"
  and "approved documents" figures used to count both institutions. That affected a
  per-institution Statistician, and would have affected the Coordinator. They now go through
  `aa_scope_sql`. `group_profile_data.php` refuses a group outside the viewer's scope.
- **The Coordinator's document badges count her own institution only**, matching the checklist
  under them. The fix is in both `coordinator.php` and `nav_badge_counts.php`. Before it, the ISAP
  coordinator's badge also counted MCNP's pending documents.

### Changed
- **Statistics page: History takes Recent's place (2026-09-29).** The hub had three cards in a
  row that never looked even. History has left the grid, so Progress and Requirements sit as a
  pair. It now fills the right-hand panel where "Recent in Statistics" used to be: the payment
  log's three totals (moved forward / sent back / reminders) and its latest five entries,
  scoped to the viewer's institution. Its head opens the History window (`_staff_home.php`,
  `staff_home.css`). **Recent now lives inside History**, as a card to the right of the log
  with the same rows. Each row opens the group in Progress. Plagiarism's history keeps its
  single column (`admin_module_dynamic.php`, `.lh-grid` / `.lh-recent`).

### Added
- **Statisticians are institution-scoped (2026-09-26).** The office runs three: one who sees
  everything and one per institution. `Statistician` joins `AA_SCOPED_ROLES` with the SCHOOL shape,
  so `aa_scope_sql()` narrows every listing and `aa_may_touch_student()` refuses every write.

  The admin is marked by an explicit `AA_ALL_INSTITUTIONS = 'ALL'` in `users.department`, NOT by an
  empty field. Empty would have inverted the rule the rest of that file rests on — blank means SEES
  NOTHING for a Coordinator — so one blank column would have meant opposite things for two roles,
  and any statistician created without an institution would silently have seen both schools. A
  marker you have to choose on purpose keeps empty meaning empty. `aa_school_scope_of()` is the one
  place that resolves it, and a Coordinator is never granted the escape: a stray 'ALL' on her row
  is read as an institution name, not as omniscience.

  Verified before the institutions were set: all three statisticians correctly saw NOTHING. After:
  Ruchelle reaches MCNP and not ISAP, Maryjane the reverse, Tshaine both — on the listing filter
  and the write guard alike.

  **Three write paths were unguarded** and took `student_id` straight from the POST:
  `acknowledge_payment`, `upload_stat_result`, and park/nudge/unpark. All three now resolve the
  owner from `form_id` and refuse out-of-scope groups — a hidden row is not a protected row.
  The dock badges in `statistician.php` and `nav_badge_counts.php` go through `aa_scope_sql()` too,
  so a badge can never disagree with the Checklist under it.

  **The Director can now set it from Staff Accounts.** Picking **Statistician** in the role
  dropdown reveals the same "Institution she covers" field a Coordinator gets, plus an
  **Admin — both institutions** option that only she may take. Her row then carries the scope
  chip in the same place a Coordinator's does — `ISAP …` / `MCNP …` / `ADMIN Both institutions`
  — and "Change her institution" works on her too. The Admin option is hidden from the
  Coordinator in JS *and* refused in the handler: hiding it is the courtesy, the handler is the
  rule. Verified both ways — Coordinator + `ALL` is refused, Statistician + `ALL` accepted,
  a made-up institution refused for either. `aa_school_tag()` is deliberately NOT given the
  marker: it falls back to MCNP for anything it does not recognise, which would have labelled
  the admin as one institution.

  **Fixed on the way through:** putting the marker in `users.department` leaked it into the
  institution filter on every Checklist — that dropdown was built from `SELECT DISTINCT
  department FROM users` with no role filter, so "ALL" appeared as a third institution that
  selects nothing and is not a place. It now uses `role = 'Student'`, the same predicate
  `df_schools()` and `aa_all_courses()` already use. Those two were checked and were already
  safe; they are the only other queries of that shape.

  **Still unscoped:** `rd_plag_badge_counts()` counts both institutions for everyone. The plagiarism
  LISTING is scoped (it runs through the same `$fp_scope_sql`), but its four dock badges are not —
  and the same gap applies to Coordinators, who are school-scoped already. Fix it in
  `nav_badge_lib.php` for both roles at once.
### Changed
- **History redesigned (2026-09-29)** — Statistics › History and the Plagiarism manuscript log share
  it. Header with its own search + Export; a summary (moved forward / sent back / reminders sent) with
  a decisions-per-month stacked chart (last 6 months); filter pills All · Moved forward · Sent back ·
  **Reminders** (new `hist_act=rem` → `nudged`; the month index now also counts `rem`); month rows
  with a four-part bar and "7 moved forward, 2 reminders"; the open month read day by day in two
  columns, each entry a card (chip, control number, document, "by …", time). Same month-at-a-time rule,
  same export. Plagiarism hides the reminders number (it has none).
- **The file viewer redesigned (2026-09-29)** — the review drawer (`#docModal`) every checklist opens:
  a rounded sheet over a blurred page; header chip naming the form for the phase, the file name, a
  **1 of 3** pager through the open group's documents waiting for review, and a round close; the file
  on a lavender stage with a zoom pill (−, %, +, full screen); on the side the requirement name,
  "Uploaded by … N days ago", **Approve / Needs revision** cards, "Note to the group (optional)"
  (now a textarea, same `name`/`id`), and **Approve and open next** / Send back to the group /
  Download original. The cards drive the SAME `#modal_status` select (hidden and no longer `required` —
  a hidden required control fails the submit silently; the submit handler checks it and shakes the
  cards). "Open next" stores the next upload in sessionStorage and reopens it after the reload.
  Every id of the review contract is unchanged (openDocumentModal, modal_* fields, previewFrame,
  modalDownloadBtn, form011RubricBox, modalCnBox).
- **Statistics revamp (2026-09-29), from the Claude Design concepts — Director AND Statistician.**
  (1) **The Statistician is on the new home** (`statistician.php` → `_staff_home.php`, `body.sh-shell`):
  rail Home · Statistics · Plagiarism · Workspace. `sh_model($role)` / `sh_workflow_keys($role)` are
  role-aware now — for her, Progress carries `pipeline` and Requirements `checklist` (nav_badge_counts
  keys), Plagiarism leads with Notify, Workspace = Messages · Analytics · Settings, no Calendar
  (`$sh_has_calendar = false`). The file was CRLF and is written back CRLF. (2) **The Statistics page**
  (both roles) gains **"Every request, at a glance"**: one pill per group in the column of the stage it
  stands on (solid = the statistician's move, outline = the group's, red = sent back, green =
  released), "5 need you" / "5 with the statistician", and a pill opens Progress already searched to
  that group (`shOpenProgress()`). Built from `sp_rows()` with the same `aa_scope_sql()` as Progress.
  (3) **Progress** (`body.spx`): header with its own search, a hero ("5 requests need you", the oldest
  one, **Start with the oldest** which opens and scrolls to it, and a waiting-on-you bar per stage with
  Done showing released), institution/course selects beside the band pills, rows with an initial, the
  labelled spine, "what's next" + "Last activity N days ago", Act (Open for the Director), and the
  control-number composer under "Issue the control number" with the button **Issue number**. All row
  forms unchanged. Test data for every stage: see the session's stats_seed_undo.json.
- **Requirements checklist redesigned (2026-09-29), from the Claude Design concept** (`admin_module_dynamic.php`,
  every By-group checklist: Proposal, Final, Statistics Requirements; scoped by `body.ck`). Header
  ("Requirements checklist") and — Proposal/Final only — a banner worded to be true for every viewer:
  "Focal persons check these requirements and put each section forward once every group in it is
  ready. You can open any group to follow along, or step in when needed." (not "you're viewing, not
  approving": the office CAN still decide). The group tiles are now ROWS in one card (initial · name,
  course + ID, members · a segment per requirement — approved / waiting / sent back / not handed in /
  cascade — with "0 of 7 approved, 3 waiting on review" · Longest waiting / Waiting N days / Complete
  + First cleared · **Nudge focal** · open), with column heads and a footer line ("That's everyone in
  Action needed…"). The group page: "← Checklist" + name, a lavender header (initial, chips, View
  names, Longest waiting, First cleared) with a progress card, each section a white card of rows
  (tick · icon · name/file/age · Open & decide · Request revision), and the batch bar as a floating
  violet pill ("Approve all 3"). List chrome hides while a group is open (`body.ws-open`, set in
  `wsOpen()` / `closeGroupWorkspace()`). **Unchanged by design:** every form, field, id and checkbox;
  `.ws-card-name` / `.ws-head-name` (read by `wsConfirmBulk()`); openDocumentModal; the cascade and
  history. New email-only actions, scoped with `aa_may_touch_student()` and limited to once per target
  per 6 hours: `nudge_focal` (the focal person(s) whose `focal_scope` covers that course) and
  `remind_upload` (the group, for a requirement not handed in). No 1-2-3 stepper.
  **Follow-up, same day. "By requirement" is REMOVED, not hidden** — the By group / By requirement switch,
  the whole `#viewByRequirement` view (requirement tiles + one workspace per requirement), its CSS, and
  its JS (`setReviewView`, `openRequirementWorkspace`, the `?item=` restore, the requirement-workspace
  filter). `currentView` is now the constant `'group'` because older checks still read it. The server
  still accepts `return_item` in `bulk_verify` / `redirectToGroupWorkspace()` (harmless, no longer sent).
  **The list is paged**: 15 rows per tab (`CK_PAGE`), then "Showing 15 of 33 in Approved archive ·
  Show 15 more"; switching tab starts again at page one. Approved archive and All submissions only grow,
  so they must not render the whole year at once.
- **Scheduling, the inside (2026-09-29), from the second Claude Design concept.** (1) **Log a section**:
  a hero with the section, its tags and the chain as chips (Focal ✓ · Coordinator ✓ · You — log and
  publish), a date card ("Requested Sep 28, 2:51 AM", MySQL-formatted); **Groups in slot order** as one
  list — tick, slot tile, initial, ID + member count, a Checklist x/7 chip (green when complete) and
  View names; unticked rows fade; a floating violet bar ("4 of 5 groups will be logged onto …") with
  **Send back** (a window posting the same `return_batch`) and **Log and publish**. Same form and
  fields (finalize_batch, keep_mode, keep[]); `dsSyncLog()` still drives the count and disables the
  button at zero. The calendar opens on that month with the date filled violet and a note under it.
  (2) **A day's detail**: date tile + Today/Tomorrow/In N days, each institution's ten as ten blocks,
  "12 slots still open", the slots in order with Scheduled / Date Selected chips, and **See this
  week in the planner** (opens the planner on that month, the day ringed). (3) **Upcoming defenses**:
  grouped under one header per day instead of a chip on every row. (4) **The planner**: month tabs
  (this month → end of the window), two small capacity bars per day instead of "ISAP 2/10", status
  pills (logged green, held amber, closed striped red), past days faded by DATE (a booked past day
  used to stay bright), today ringed. Every window is a big rounded sheet over a blurred page and
  scrolls inside itself. "All N" on Coming up is just "All".
- **Scheduling redesigned (2026-09-29), from the Claude Design concept.** `admin_defense_schedule.php`:
  eyebrow + "Defense scheduling" header; the To log / Record switch in the Approvals pill look (no
  icons, no check); the section to log as the big purple card (date tile, the Focal Person and
  Coordinator signatures as chips, "Open the section", and what the calendar says about that day —
  "Wednesday, Oct 7 is partly booked"), further sections as compact rows; **Coming up** under it,
  grouped by day (Today / Tomorrow / date, three days, three groups each, "+N more", "All N");
  the **Office calendar** card on the right with the concept's legend colours (Full deep purple,
  Partly amber, Closed grey, Open outline), and the **planner kept** behind an "Open the planner"
  pill next to "Close all PH public holidays". No 1-2-3 stepper. Layout and look only: every id and
  class the page script reads is unchanged, and closing a day, logging a section, the record search
  and the planner were exercised in a browser after the change. Side by side down to 900px.
- **Approvals redesigned (2026-09-29), from the Claude Design concept.** `admin_batch_review.php`'s queue
  is now: a header with a **1 Approvals · 2 Schedule · 3 Checklist** stepper (each with this viewer's
  own count) · "Signed in as" · the Waiting / Sent back / Published pills · **On your desk**: the
  soonest section as a big purple card (date tile, the chain with real names — focal person,
  Coordinator, "You, your turn" — "Arrived 32m ago", and the action) · **Further up the chain**:
  compact cards with a three-dot chain and **Nudge** · right column: "How a date gets approved" (your
  step marked) and the latest three published. MANY SECTIONS: only the first on your desk is the big
  card; the rest are compact cards with the same action under "Also on your desk"; the chain list
  collapses after four. Actions are unchanged: Confirm posts `confirm_batch`; "Log slots and publish"
  is still the hand-off to the Scheduling screen. The opened request (`?batch=`) is unchanged.
  New: **`nudge_batch`** in focal_handler.php emails whoever holds the step (that institution's
  Coordinator(s), or the Research Director accounts), refused for your own step, scoped, and limited
  to once per section per person per 6 hours. `fp_chain_queue()` now also returns `sec_name`,
  `sec_ts`, `sec_today`. Test data: Fox (Endorsed, Oct 6), Lion (Confirmed, Oct 7) and Crow (6
  proposal files back to Pending) were set up for this; the backup is in the session scratchpad.
  **Follow-up, same day.** The 1-2-3 stepper is GONE: it navigated the whole module window to Schedule /
  Checklist, which have no way back to Approvals (the rail cards are the way between screens). Its
  pill look now carries Waiting / Sent back / Published (and Coming up / Already held). The OPENED
  request (`?batch=`) is redesigned from the second concept: back pill + status chip, a lavender hero
  with the course, tags (year + section, phase, institution) and a date card (Today / In N days), the
  chain with who signed and when ("Account removed" in italics), your decision right under it when
  it is your step (same confirm_batch / return_batch / Scheduling hand-off), "What's being asked" with
  slot tiles and segmented checklist bars, a warning when a group defends with checklist items still
  open, and a Timeline. `fp_get_batch()` now returns `focal_md` / `coord_md` / `sec_md` / `returned_md`,
  formatted by MySQL (PHP's Berlin clock would move a 1 AM Manila signature to the day before).
- **Director home redesigned (2026-09-29): a seven-item rail, a calm Home, and one hub page per
  destination.** The ~22-button side nav is now **Home · Proposal · Statistics · Final · Plagiarism ·
  Insights · Workspace**. Each phase's steps are CARDS (Approvals, Schedule, Checklist, …) that open the
  same module windows the old buttons did — no module screen changed. Home = hero ("You're all caught
  up" / "N things need you", with where the work is), a Stages switcher over the four phases (opens on
  the one with work), a **Groups** card (recently active; opens the group profile) above **Recent**
  (clicking a row opens that group). The top-bar search finds any group. A card with work waiting turns
  purple; the rail, cards, stage tabs and hero all repaint from ONE nav_badge_counts.php fetch.
  Phones get a bottom tab bar. New: `staff_home_lib.php` (the model — one description of every
  destination), `_staff_home.php` (markup + JS), `assets/css/staff_home.css` (scoped to
  `body.sh-shell`). `_master_overview.php` still serves Coordinator + Statistician until they move.
  The open hub is remembered per tab (`rd-shell-view`) like module windows; portal.js now dispatches
  `portal:open` so the rail follows whichever card opened a window. Recent times come from MySQL
  (`CURDATE()`), not PHP's Berlin clock. The group profile no longer shows the Coordinator /
  Statistician / Director / Payment chips — they read the retired `approvals` table and said
  "Pending / Unpaid" for everyone.
  **Same day, second pass:** the Groups card is gone (the top-bar search and Recent rows already open
  a group); the stage tabs lose their check mark; the rail loses its logo. The top bar now greets like
  the student header ("Good morning", Manila clock) over the ROLE as the title ("Secretary" / "Research
  Director", brand purple). The hero carries the LIVE login mascot (`assets/mascot`: blinks, eyes
  follow the cursor, waves on hover or tap; `coverEyes()` is never called here) in a white circle,
  (the wordmark beside it was tried and dropped). "See all" carries no count. Blocks rise in with a soft de-blur, staggered,
  on load and on every view switch; honours prefers-reduced-motion. The greeting shows on Home only
  (hubs and Settings have their own title); search, theme and avatar stay on every view.
- **Statistics nav reads the same in all three shells: Progress · Requirements · History.** Director
  and Coordinator said "Statistics" for the checklist, and the Coordinator had no History. The Decision
  log drops its title + grey stats line for the Progress pill style, and the pills are real FILTERS
  (`hist_act=ok|no`): All / Moved forward / Sent back narrow the month index, the entries and the CSV
  export. Their numbers are the unfiltered totals (`$hist_count_months`), so picking one never zeroes
  the others. Search and the filter compose; month links keep both.
- **Control number: an example, a quiet hint, and a second door.** The composer placeholder is a
  real-looking example (`CU88759938`, not `015`) and the box + Register button share ONE row, with the preview and a faint "The control number is
  printed on the Official Receipt" on the line under them (the global input style had been drawing a
  second box inside the composer). And approving the **Official Receipt (37)** in the review drawer, for a group not yet
  registered, now asks for the number there (prefix pre-typed, live preview) and registers the
  payment in the same click; Revise hides the field. Refused server-side without a number. Both doors
  call the new `sp_register_payment()` - the acknowledge_payment body, extracted, so they cannot drift.
- **Statistical treatment can be sent back for revision, and a release can be corrected.** The Treat
  stage on Progress is now a verdict, Turnitin-style: **Release results** (file + optional note to
  the group) or **Needs revision** (tick which of the five deliverables need fixing, an optional note
  per tick, a required main note, optional treated file). Ticked deliverables reopen as `Revision
  Requested` and the group drops to `Phase 6: Revision Requested`; the existing checklist loop brings
  it back to Treat once re-approved. Statistics is paid ONCE: a send-back never touches payment.
  Released rows show the file + note and a **Replace file or edit note** form (`update_stat_result`);
  the old file stays on disk and the group is notified. Student side: the completion card no longer
  says "proceed to the Research Office" (nor does the bell entry or email), the release note shows as
  its own note (releasing clears the stale checklist remark), and a treatment send-back gets its own
  alert saying no additional payment is needed.

### Fixed
- **No way back to "On your desk" after "Log slots and publish".** That hand-off walks the Approvals
  window over to the Scheduling screen, whose only back link was "All sections to log"; and clicking
  the Approvals card again just re-showed wherever the window had wandered, so only a reload got you
  back. Two fixes: the hand-off now carries `from=approvals` and the section page offers **Back to
  Approvals** beside "All sections to log"; and `PortalWindows.open()` (portal.js) sends a window HOME
  when its card is clicked and its frame is on a different SCREEN (`screenOf()`: file + phase, plus
  `view` only for admin_module_dynamic.php, where view means a different screen). Same screen — a
  search, an opened group, Approvals' Published tab — is left where you were.
- **Director and Secretary were badged while a section was still the Coordinator's move.** The
  Schedule nav badge counted `defense_bookings` in `Date Selected`, which a booking takes the moment
  the Focal Person endorses. It now counts what the Schedule screen actually asks for: sections the
  Coordinator has CONFIRMED, for a role that can log them (`fp_schedule_badge()` in focal_lib.php;
  0 for the Coordinator, who signs on Approvals). Proposal and Final; director.php, coordinator.php
  and the live nav_badge_counts.php all use it. The waiting cards on the queue screens are unchanged,
  so everyone can still see where a section is.
- **Refreshing a staff portal dropped you back on the Dashboard.** `PortalWindows` (portal.js) now
  remembers the open window per tab in `sessionStorage` (`rd-portal-open`), including the page it had
  navigated to inside the frame (a search, an opened group), and reopens it on boot with its nav item
  lit. Built under the same key a nav click uses, so clicking it afterwards re-focuses rather than
  duplicating. Going back to the Dashboard (or any in-shell view) forgets it. Keyed to the shell path,
  so another role's shell in the same tab never inherits it. Covers all staff shells at once.
- **Searching on Progress dropped you on the Requirements checklist.** The shared filter bar posted
  `phase` but not `view`, so any search reloaded the phase default. It now carries `view` through.
- **The statistics Decision log (and the plagiarism archive) were not institution-scoped** - a
  Coordinator or per-institution Statistician read both institutions. `$hist_where` now carries
  `aa_scope_sql()`, which covers the month index, the entries and the CSV export at once.
- **Review filter tabs locked themselves.** Clicking Approved Archive (or any tab) disabled every
  OTHER tab whose count was 0 - `.status-filter-tab.is-empty` was `pointer-events:none` - so Action
  Needed and Revision History became unclickable and there was no way back to the queue. Zero tabs
  are now dimmed only and open onto their empty state. Every staff review screen shares this toolbar.
- **"Download Final Results" on the student Statistics card did nothing.** The completion card's
  decorative `::before` glow is absolutely positioned and painted over the static link, swallowing
  the click. Now `pointer-events: none`, with the card's content lifted above it.

- **Staff nav follows the office sequence: Proposal → Statistics → Final → Plagiarism.** In
  `director.php` (Director + Secretary) and `coordinator.php` the Statistics section moved up from
  after Plagiarism to between Proposal and Final. Markup moved only; no badge ids or routes changed.
- **The decision log is bounded: it renders ONE MONTH, never the whole history (2026-09-27).**
  This is the fix that was actually asked for and that three earlier attempts missed. Paging a log
  that only grows does not bound it — it just decides how many thousand rows you scroll past before
  the next button, and raising the page size from 25 to 60 made that worse, not better.

  The page is now a **month index** — one row per month, however many years accumulate — with only
  the open month loading its entries. Three years of daily decisions is 36 header rows plus one
  month of detail, not 1,100 rows behind a pager. A collapsed month still says what is in it: its
  count, and a bar split into moved-forward / sent-back / everything else. (The bar was two-part
  at first, which made a month of reminders look like a month of rejections.)

  The index is a `GROUP BY` over the same `$hist_where` the list uses, so **search narrows the
  months themselves** — searching `Lea` collapses the index to the one month holding her single
  decision. Within a month there is still a pager at 80, because an office can clear two hundred
  receipts in a busy week, but it pages one month rather than all of history.

  It is **flat and full width**, like the Progress and Checklist views beside it — it was first
  built as a bordered, shadowed 940px panel and that read as a component borrowed from another
  app. Header carries the running totals, search and export; month rows carry a caret, the name,
  a capped proportion bar and the count; the open month is marked by an accent rule down its left
  rather than by a box, and holds day sub-heads and one row per entry — soft status disc, group,
  a muted meta line, right-aligned time. The bar is capped at 260px: stretched full-bleed it read
  as a progress meter for the month rather than a shape you glance at.

  **Three designs were built and discarded getting here** — a thin one-line table, day-grouped
  cards, and a timeline rail — and all three left CSS behind. Their 40-odd orphaned rules are
  deleted. Verified by reading the stylesheet back out of the browser (480 rules, 518/518 braces,
  86/86 comments) after a first prune left three unbalanced braces, which silently kills every rule
  after them.

  Shared with the Manuscript archive, which gets the same treatment. Both themes checked.
### Added
- **"Progress": the statistician's cohort as ONE ladder, and it is on all three staff shells
  (2026-09-27).** Shipped as "Pipeline" and renamed the same day — that is what the thing is
  BUILT like, not what it is FOR. "Clearance" became "Requirements" for the same reason. The
  route stays `view=pipeline`; only the words changed.

  It is now in the Coordinator and Director docks too, leading their Statistics section exactly
  as it leads hers. **The band labels are role-aware**, because "Your move" is a lie on a screen
  where every action is Statistician-only: they read **With the statistician / Waiting on the
  group / Released** for everyone else, and the legend swaps "your move" for "statistician".
  Same rows, different sentence about them. The Coordinator's copy is institution-scoped by the
  same `aa_scope_sql()` as everything else — verified: she sees 4 of the 12, all MCNP courses,
  every one read-only. The Director's old **Payment** nav entry, which had been redirecting to
  the Pipeline since the desks were retired, now says **Progress** and points there directly.

- **The ladder itself (2026-09-27).** Her work was three
  screens — Checklist, Payment, Release — each showing a slice of the same journey. "Where is this
  group" meant opening all three, and "who is holding this up" could not be asked at all. The
  student has seen a single 7-phase ring the whole time; this is the staff side of that ladder.

  One row per request, carrying a six-node **spine** — Coded · Pay · Reg · Reqs · Treat · Done.
  Everything behind the group is filled, the node they are standing on is coloured by **whose move
  it is** (amber = hers, slate = theirs, green = done), everything ahead is pale. Rows are banded
  **Your move / Waiting on them / Released**, hers first, oldest first inside each band.

  That framing immediately showed something no existing screen could: of 11 requests, **2 are hers
  and 6 are stalled on students** — one for 63 days. Three separate queues had been hiding that.

  `stats_pipeline_lib.php` holds the model. `sp_position()` maps each status to a stage AND an
  owner; the two "sent back" statuses (`Coded Data Rejected`, `Revision Requested`) are deliberately
  NOT stages of their own — they are the same place with a verdict, so they stay on their stage and
  carry a flag. The clock runs from the last thing that actually happened to the request, not from
  `date_submitted`: a request opened in July and paid yesterday has been waiting a day.

  **The control-number composer.** The payment desk exists to mint `STAT-2026-ISAP-CE-015`, and the
  prefix is derived from the group's course — she never chose it, yet she typed a sequence into a
  blank box and only learned what she had minted afterwards. The prefix now shows as locked text
  beside an input that takes only the sequence, with the full string previewed live. The logic
  moved out of the handler into `sp_control_prefix()` so **the preview and the mint are the same
  function** — a second copy would drift, and a wrong preview of a permanent identifier is worse
  than no preview. Verified against every live control number: the prefixes match.

  Scoped through `aa_scope_sql()` like every other listing, honours the toolbar's search and
  filters, and its nav badge counts the same rows the screen renders. `sp_next_sequence()` was
  written and then deleted: the office types free-form suffixes (`CBGAY66`), not a running
  sequence, so suggesting "001" would have been a confident wrong answer.

  **The Reg stage shows the evidence.** The two payment documents — validated Form 011 and the
  Official Receipt — sit directly above the composer, viewable and approvable in place through
  the same `openDocumentModal` the payment desk used. Minting a permanent number without seeing
  what it is minted against was the one thing the old desk did better, and this screen replaces
  that desk.

  **The Payment and Release desks are RETIRED.** Their markup, their queries, 20 now-dead CSS
  rules and ~3KB of their JS are deleted, and their nav entries are gone from `statistician.php`.
  The ROUTES survive and redirect to the Pipeline (`view=payments` / `view=release` →
  `view=pipeline`) rather than 404ing: the Director's dock still links to `phase=stats&view=payments`
  and so will any bookmark, and landing on the Pipeline is strictly more than either desk gave
  them. `phase=plag&view=payments` is a different desk entirely and is untouched.

  The dock badge follows: `nav_badge_counts.php` now returns a `pipeline` key counted from the
  same rows the screen renders, and the shell's live-refresh map points at it. `payment` and
  `release` stay in that JSON — other shells still read them.
### Changed
- **The Release desk is a ledger too, and it stopped contradicting itself (2026-09-26).** It was a
  raw `<table>` inside a collapsed accordion titled **"Ready for Release"** whose badge read
  **"N Processing"** — two opposite claims about the same rows. Every row there IS ready: `Phase 7:
  Statistical Treatment` is only reached once the payment is registered AND all five deliverables
  are approved, so nothing is pending on anyone but the statistician. The title now says
  **Awaiting final results**, and each row carries a **Ready** pill.

  Rebuilt on the payment desk's `.pl-row` classes so the two screens she moves between all day read
  as one system. The closed row answers what the old table made her open a group card for: avatar,
  group, School ID, leader, course, **the control number** (the string she quotes back to a group),
  and how long she has had everything. Expanding adds institution, email, contact number, the
  request date, the cleared date and the research title, above the file picker — which now gets
  real width instead of being squeezed into a table cell beside its button.

  **It ages from the right date.** `last_cleared` is `MAX(uploads.verified_at)` over items 31-35 —
  the moment the last deliverable was approved and the treatment actually became possible — not
  `date_submitted`, which is when the group first asked and may be months earlier. The queue sorts
  by it **oldest first**; it was `date_submitted DESC`, which buried whoever had waited longest.

  The desk is also **scoped** (`$fp_scope_sql`) and no longer vanishes when empty — verified across
  all three statisticians: Tshaine and Maryjane see the one ISAP group, Ruchelle gets the empty
  state. Both themes checked.
### Changed
- **The payment desk is three lanes instead of one never-ending queue (2026-09-26).** "Pending
  Payments Acknowledgment" was a single FIFO of `Phase 2 + Phase 4` ordered by `date_submitted`,
  and it could not drain. Three separate reasons:

  **Half of it was not a payment.** `Phase 2: Form Download` means *"downloaded Form 011, uploaded
  nothing"* — and it covers two opposite situations the system cannot tell apart: a group that paid
  cash at the Research Office and needs registering, and a group that simply has not paid. The
  second has no exit except the student acting, so abandoned groups sat under "Action Needed"
  forever. On live data the badge read **4 Action Needed** while only **2** groups had submitted
  anything.

  **It was sorted by the wrong date.** `date_submitted` is when they downloaded the form, not when
  they paid — so a group that paid yesterday queued at #04 behind two that had done nothing for a
  month. Worse, a REJECTED payment is pushed back to Phase 2 while `date_submitted` never moves, so
  bounced groups reappeared at the front looking urgent. That is the same arrival-index flaw
  already removed from the Checklist.

  Now: **Ready to register** (documents actually in — the real service queue, ordered by when the
  RECEIPT landed), **Waiting on the student** (their move) and **Parked** (set aside, still
  reopenable), as three **lane pills** over one list.

  **And it is rows, not cards** — the same call `admin_accounts.php` made for the same reason: a
  list that grows needs a row. The old card spent ~250px per group on a giant QUEUE ticket stub,
  which read like a deli counter rather than a payments desk and stopped fitting at about six
  rows. A row is ~64px collapsed and carries everything needed to triage without opening it:
  group, School ID, **two document dots whose colour IS the status**, waiting time, status pill.
  Register/Actions unfolds in place. Search filters on a `data-hay` blob, the same trick
  `admin_accounts.php` uses.

  A four-tile stat strip (Awaiting · Not Paid · Registered This Month · Average Wait) was built
  and then **removed the same day** at the office's request: the lane pills already carry the
  counts, and two tiles restating them above the list they filter is furniture. Do not re-add it
  without a number the pills cannot show.

  **Trap worth remembering:** `hidden` is only a UA-level `display:none`, so ANY rule that sets
  `display` beats it. `.pl-body` and `.nx` are both `display:flex`, so the first render came up
  with every row expanded AND the empty state showing over a full list. `.pl-body[hidden],
  .pl-row[hidden], #plEmpty[hidden] { display: none !important }` is load-bearing.

  **Park and nudge give it an exit.** Both are DERIVED from `payment_logs` — the latest
  `parked`/`unparked` row per form wins — so there is no new table, no `ALTER`, and no state to
  fall out of sync; `action` is already `VARCHAR(20)` and the History view falls back to
  `ucfirst()` for labels it does not know (all three are added to `$hist_actions` as `neutral`, so
  they count in the total and in neither the accepted nor rejected tile). Nothing touches
  `form_stat_treatment.status`: a parked group is exactly where it was in the workflow, it has just
  left the active lanes. **Paying beats parking** — a parked group that uploads a receipt is back
  in Ready without anyone remembering to un-park it.

  **Registering a walk-in is still possible from the waiting lane**, folded behind "They paid at
  the office — register anyway". Cash at the Research Office leaves no upload, so that path had to
  stay reachable; it is just no longer the default that made every row look actionable.

  The dock badge in `statistician.php` and `nav_badge_counts.php` now counts the same thing the
  Ready lane shows, instead of every row on the desk.

  Verified on live data, both themes: 4 rows split 2 ready / 2 waiting; parking a group moved it to
  a third lane with its "parked by" line; giving that parked group a receipt returned it to Ready
  and took the badge 2 → 3. All test writes were rolled back by id (one upload, one status, one log
  row) and re-verified as gone. Stylesheet read back out of the browser: 381 rules, comments and
  braces balanced.
### Changed
- **The Final Defense is now the same flow as the Proposal, and it waits for Statistics
  (2026-09-25).** Two changes the office asked for, in one:

  **One grid, seven requirements, sabay-sabay.** Final used to be split in two: Form 002 (item 20)
  was a PHASE 1 reviewed on its own, and only once the office approved it did a Schedule panel
  open carrying items 22-27 beside a date picker. That split is gone. All seven — 20, 22, 23, 24,
  25, 26, 27 — are ordinary requirement cards in one grid, uploaded in any order, exactly like the
  Proposal's seven. `module_final.php` already had the wallet-card UI; it was only ever querying
  one item. Their how-to steps moved back out of `defense_lib.php`'s panel map onto the cards, and
  the blank-form download is now data-driven, so the Minutes (22) gets one too — it always had a
  blank (`mom.pdf`) that the panel showed and the card did not.

  `df_application_items()`, `df_phase2_items()` and `df_phase2_reviewed_items()` now return `[]`
  for BOTH phases. They are kept rather than deleted because four callers ask them what to render,
  and an empty list is the answer that turns each one off. `df_phase1_items('final')` is the live
  list and is now all seven.

  **This also fixes a bug that made the Focal Person chain unusable for Final.** Uploading Form 002
  created a `defense_bookings` row with status `Applied` — and `focal_handler.php` refuses to
  endorse any group that "already holds a booking for this phase". So every group that had
  submitted Form 002 was silently unschedulable. The live data shows it: `defense_batches` has
  **zero** final-phase rows, while `defense_bookings` holds 13 final rows, all from the retired
  student-picks-a-date flow. With no application stage, nothing writes `Applied` any more and the
  Focal Person can endorse a final-defense section the same way she endorses a proposal one.

  **Final now unlocks on statistics, not on the proposal.** `$unlock_final` was `$prop_scheduled`;
  it is now `form_stat_treatment.status = 'Phase 7: Completed'`. You cannot defend Chapter 4
  without the numbers. The card carries its own reason — `$lock_reason_final`, "Opens once your
  statistics results are released" — because the old shared `$lock_reason_next` would have told a
  group with an already-scheduled proposal to go and schedule their proposal.

  `dashboard_billboards.php` is gated on `$unlock_final` for the same reason: those 13 legacy
  bookings belong to groups that never went near a statistician, and without the guard the strip
  would promote "book your final defense" over a card that is locked shut.

  Verified on live data from both sides of the gate: a group at `Phase 7: Completed` gets an open
  Final card showing `All 7 · Needs you 7`, cards `01 Form 002 · 02 Minutes · 03 Communication
  Letter · 04 Self-Assessment · 05 Manuscript · 06 Datagathering Tool · 07 Statistical Tables`; a
  group at `Phase 5: Registered` gets it locked. `fp_ready_items('final')` reads all seven.
### Changed
- **The student's stage cards now read Proposal → Statistics → Final → Plagiarism (2026-09-25).**
  The office confirmed the real rule: **statistics must be finished before a group can sit its
  face-to-face final defense** — you cannot defend Chapter 4 without the numbers. The cards were
  ordered Proposal · Final · Statistics · Plagiarism, which told students the opposite.

  This is the ORDER ONLY. The gate itself has not moved yet: Final still unlocks on the proposal
  booking being `Scheduled`, exactly as before. Nothing about `$unlock_final`, `$unlock_stats` or
  any handler changed — the two markup blocks were swapped, the `card-ghost-num` badges and the
  `$stage_peek_data` `num` values renumbered 1·2·3·4, and that is all. Safe because
  `STAGE_PEEK_DATA` is keyed by phase and looked up through `data-peek`, never by position, and
  no CSS selects these cards by `nth-child`.

  Verified by rendering a real group: DOM order `proposal → stats → final → plag`, numerals
  `1 2 3 4`.
### Added
- **The Proposal Defense asks for the Application for Research Defense (Form 002) — it was
  missing (2026-09-25).** The office files this form for the proposal too; the portal only ever
  collected it for the Final defense. It is now the **first** requirement in the proposal grid,
  because applying is what opens the defense: cards run `01 Application for Research Defense
  (Form 002) · 02 Assigned Adviser · 03 Endorsement From the Adviser · 04 Complete Capsule
  Proposal · 05 Form 008 · 06 Data Gathering Tool · 07 Literature Matrix`. The grid reads
  **7/7**, not 6/6.

  It is its **own row — item 17, `form_id` 1** (`database/migrations/2026-09-25_proposal_form002.sql`),
  deliberately NOT the Final defense's item 20. Uploads are keyed by `item_id`, so one shared row
  would have meant the proposal's approval instantly cleared Final's phase-1 gate and unlocked
  requirements 22-27 early — and one upload history covering two different submissions. The blank
  form downloaded on the card is the same PDF Final serves (`application_isap.pdf`); the
  submissions are separate.

  Carried through **every** place that counts proposal requirements, so the number agrees
  everywhere a student, Focal Person or reviewer can see it: `df_phase1_items()` (the gate the
  Focal Person's scheduling reads), `fp_review_items()`, the staff Checklist's `$phase_map`,
  the Coordinator / Director / nav-badge pending counters, `_master_overview.php`, analytics,
  the group profile, research history, `profile.php` and `download_final_form.php`.

  **Fixed on the way through:** five of those lists were still the pre-2026-09-19 *five*-item
  list and had never picked up item 12 (the endorsement) when it moved into the grid — the
  student dashboard card, `profile.php`, `download_final_form.php` and `_master_overview.php`
  were scoring proposals out of 5 while the module scored out of 6. All now read 7.

  Groups already sitting at 6/6 drop to 6/7 until they upload it. That is the point — it is a
  requirement that was missing, not a regression.

### Fixed
- **Research history filed the Final defense's Form 002 (item 20) under *Proposal* (2026-09-25).**
  Harmless while the proposal had no Form 002 of its own; with item 17 in the grid it would have
  stacked two identically-named documents in the same bucket. Item 20 now sits in Final, where it
  belongs.

### Changed
- **The "Phase 1 complete" banner no longer offers the Adviser Endorsement form (2026-09-25).**
  The endorsement stopped being a post-clearance errand when it became an ordinary requirement
  (item 12) — a student who reaches that banner has already uploaded and had it approved. Handing
  them the blank form again at the finish line only invited them to re-do a cleared step. The
  Clearing Form download stays, and the blank endorsement form still sits on requirement card 03,
  where it is actually used.
### Added
- **The Checklist's status tabs carry their counts, and an empty one stops inviting the click
  (2026-09-25).** Four tabs narrowing the same list, and not one of them said how much was in it —
  so the only way to learn whether there is any revision history was to click *Revision History*
  and land on an empty page. Every other pill group in this app carries its count; these are the
  ones where the count actually decides whether you click at all.

  `Action Needed 2 · Revision History 0 · Approved Archive 31 · All Submissions 33`, and the tab
  with 0 goes quiet and unclickable — it stays visible, because *knowing there is no revision
  history is the answer*, but nobody lands on a blank page any more. The tab you are standing on is
  never disabled, or you would be trapped on it.

  The numbers are counted **from the same tiles the filter itself reads**
  (`.grp-tile[data-action|data-revision|data-approved]`), not from a parallel server query — a
  count that can disagree with the list under it is worse than no count, and a second source is
  exactly how they come to disagree. They are repainted at the end of `filterTableRows()` rather
  than beside each of its six call sites, so no future caller can forget, and they follow the group
  picker and the group/requirement lens.

  Verified against live data: `all` = 33 = the rendered tile count, `action` 2, `approved` 31,
  `revision` 0 and dimmed; both themes, no JS errors, no horizontal overflow.

### Added
- **The release archive now says when a group was notified EARLY (2026-09-25).** "Notify & unlock"
  opens a group's Plagiarism card so it can send the final manuscript, and it is meant to be pressed
  once the defense has actually been heard. Releasing before the defense day is allowed — the office
  may know it went ahead early — and the confirm dialog already warns about it.

  But **the record said nothing afterwards**, and a record of who was notified, when and by whom
  exists precisely so that this is findable later. Rows released before their defense date now carry
  a `⚠ RELEASED EARLY` chip, with the gap in days in its tooltip.

  On live data that is **9 of the 10 releases** — which is either a lot of early hearings or a lot of
  test data, and is exactly the kind of thing the office should be able to see at a glance rather
  than reconstruct from two dates on the same row.

### Changed
- **The release notice's cards lost their edge rail (2026-09-25)**, the last `::before` state stripe
  on the Checklist. Same reasoning as the requirement cards: the tint already carries the state, and
  a column of coloured stripes reads as a colour chart. The border picks up a hint of the state
  instead.
- Its two empty states carry Quill, like the other six on this screen.

  Verified light and dark against live data: 10 rows, 9 early chips, 0 rails, no JS errors, no
  horizontal overflow.

### Changed
- **The Checklist's filter row now speaks the same language as the rest of the app (2026-09-24).**
  It was the last part of that screen still speaking OS: three raw `<select>` boxes wearing the
  platform's own chrome, a square search field, and a red **Reset Query** at the end of the row.

  - They are **still real `<select>` elements** — swapping them for custom dropdowns would cost
    keyboard support, type-ahead and the native picker on a phone, to gain a rounder corner.
    `appearance: none` plus our own chevron is the whole change.
  - **A set filter now looks set.** An untouched pill is quiet; a chosen one carries the accent, so
    the shape of the query is readable without opening three dropdowns.
  - **Reset only exists when there is something to reset**, and it is a quiet `× Clear` rather than
    a red alarm lit whether or not the query was ever touched.
  - Copy follows the house rule: *All institutions* / *All courses*, not "All Departments".

  Scoped to a new `.cl-filters` class — `.filter-bar` and `.search-input-wrapper` live in portal.css
  and are shared by every staff page, so this restyles one screen rather than the product.

- **The two "your filter matched nothing" states carry Quill as well.** It is a different fact from
  "nothing has been handed in yet" — so each keeps its own wording and its own way back ("Show all
  submissions") — but a screen that draws two different kinds of empty reads as two different
  products. Six empty states on this screen, one house style.

  Verified light and dark, filtered and unfiltered: 3 selects with zero native chrome left, 2 pills
  lit on a real query, Clear present only when filtered and absent when not, no `#quill-*` id leaks
  (0), no JS errors, no horizontal overflow.

### Removed
- **The Checklist's queue ordinal ("33rd in queue") is gone (2026-09-24).** It was derived from the
  group's earliest upload in the phase, and the old comment defended it as a placing that never
  moves once earned. That is true, and it is exactly why it had to go: **a number that never moves
  when a group is SERVED is an arrival index, not a queue position.**

  - A fully-approved group still read "3rd in queue".
  - A group that failed a requirement and was sent back **kept its early placing**, so it went on
    looking urgent while it was actually stuck — the worst case, because the number actively
    misled.
  - It never resets. Computed across every upload for the phase's items, it climbs into the
    eighties next school year and never rolls over.
  - The tiles sort by workload, so the ordinals appeared out of order on screen anyway.

  What a reviewer actually wants was already one line below: **how long this has been waiting.**
  That is bounded, actionable and self-correcting. The only ranking worth showing is the extreme,
  so exactly one group is now marked — **`LONGEST WAITING`**, the one that has waited longest *and*
  still has something outstanding (a group with nothing left to review is not waiting, however
  early it arrived). A badge is bounded; an ordinal is not.

  `ws_ordinal()` stays: the **Turnitin queue** keeps its numbers, because that one is a genuine
  FIFO service queue where position tells you how many are ahead.

### Fixed
- **Two more six-hour timers.** `first_upload` now comes back as `UNIX_TIMESTAMP(MIN(uploaded_at))`,
  so the group tile's "Waiting 2d" is true. One legacy caller is left on the string path
  (`paid_at`, in payments) and is still wrong by the same six hours — it reads from a separate
  query that needs its own epoch column.
- **"Waiting 2d ago"** was two grammars glued together. `ws_ago()` takes a `$suffix` now, so a
  caption that already says "Waiting" gets a bare duration (`2d`) and everything else keeps `ago`.

### Fixed
- **`ws_ago()` on the Checklist was six hours wrong (2026-09-24).** It did
  `time() - strtotime($ts)` on a MySQL `DATETIME` — PHP runs Europe/Berlin while the MySQL session
  clock is ahead of it, so the age came out negative and clamped. The same trap CLAUDE.md already
  records for `fp_chain_queue()`. Measured on live rows: a file uploaded **8h** earlier reported
  **"2h ago"**, and anything under ~6h reported **"just now"**.

  The uploads query now carries `UNIX_TIMESTAMP(up.uploaded_at) AS uploaded_ts` — an epoch computed
  by the database, the only clock that agrees with the stored value — and `ws_ago()` takes an `int`.
  The string branch stays so the three older callers (payments, first upload, first cleared) behave
  exactly as before; **each reads from a different query that needs its own `UNIX_TIMESTAMP` column,
  so they are still wrong and deliberately untouched** — half-fixing turns one wrong number into an
  inconsistent pair.

### Changed
- **The Checklist requirement card, rebuilt (2026-09-24).** It had accumulated up to **fourteen**
  elements on a seven-step type scale where everything was nearly the same size, so nothing led.
  Six zones now, and five type steps with one job each: name 14.5/700, buttons 12.5/700, state line
  11.5/600, sub-line 10.5/550, pill 9.5/800.

  - **The signature became the card's leading tile.** It was a three-line block —
    `VERIFIED · FOCAL PERSON · 24 SEP 2026` — and then briefly a corner chip, which fought the
    status pill for the same top-right corner. On an approved card the 34px tile was showing a
    check-circle the pill had already said, so the slot stops repeating the status and names the
    person instead: their face, with a tick on it. The name lives in the `title` and in a new
    visually-hidden `.ws-sr` line, so it stays reachable by screen readers and Ctrl+F.
  - **The absolute timestamp is gone from the face of the card** — "the random time". It is
    `· 8h ago` now, with the exact stamp in the `title` where it is available without being read a
    hundred times a day.
  - **The duplicate file button went.** It called `openDocumentModal` with byte-identical arguments
    to *Open & decide* ninety lines below — two controls, one action. The filename is plain text in
    the sub-line.
  - Score, remarks and the next-step hint **collapse into one line**, never a pile: a real remark
    from a previous decision always beats the generic hint.
  - Batch selection demoted (16px box, "Add to batch"); history demoted from its own labelled row
    to a 34px icon on the actions row; *Request revision* loses its outline until you reach for it;
    the 1px hover lift dropped — twenty cards each jumping reads as jelly.

  Every new rule is scoped under a new `ws-card-doc` class. `.ws-card` and friends are shared by
  **four other renderers**, one of which nests `.ws-card-title` *inside* `.ws-card-name`; unscoped
  edits would have redesigned five cards to fix one. Verified in the browser: 93 `.ws-card-doc`,
  186 other `.ws-card` untouched.

- **Cinzel is gone (2026-09-24).** A Roman-inscription serif on five headings — group names, the
  workspace header, the document drawer — ceremonial, and out of register with an app. They use the
  UI stack at weight 800 with `-0.02em` tracking, which on Windows resolves to Segoe UI Bold: the
  register native title bars use, at zero network cost and with no flash of unstyled text. The font
  is no longer downloaded by this page.

- **One honest piece of motion.** The "you have not opened this file yet" dot breathes — the single
  fact on the card that is still changing while the reviewer sits there, and it stops the instant
  the document is opened. Deliberately **no** entrance stagger on `.ws-card`: opening a group is a
  `display` swap over already-rendered DOM, and `display:none → block` re-runs CSS animations, so a
  stagger would replay all twenty cards every time the reviewer came back from a decision.

  Verified light and dark against live data: no JS errors, no horizontal overflow, and the
  stylesheet read back out of the browser — **353 rules in the file, 353 parsed, none missing**.

### Changed
- **The Checklist's coloured edge rails are gone (2026-09-24).** Every card carried a 3–4px state
  stripe down its leading edge — group tiles, requirement cards, workspace cards, plagiarism cards,
  payment cards, and two section headers. A card already said its state twice (a tint and a pill);
  the stripe was a third, louder voice, and a screen of them read as a colour chart rather than a
  queue. The tint stays and now carries it alone, with the card's own border picking up the
  faintest hint of the same colour — enough to scan a column by, quiet enough to read.

  Two of them were `::before` pseudo-elements, not `border-left`, so the first sweep missed them —
  including `.ws-card`, the one the reviewer actually works in. The remaining `border-left` in the
  file is `.ws-cascade`, which marks **nesting**, not status; it keeps its indent and lost its
  colour.

- **A cleared requirement now says who cleared it (2026-09-24).** It said "Approved" and nothing
  else — not who, not when — and with ~21 focal persons, *which* of them signed is a real question
  this screen could not answer. The card carries a badge-check mark on its corner so a grid shows
  at a glance which requirements are somebody's signature, and inside: the avatar, the name, the
  **capacity** it was signed in, and the day.

  **One signature, not three.** Requirement approval is single-stage on purpose — five requirements
  across eight groups would be forty taps per coordinator — and the focal → coordinator → secretary
  chain governs the **date**, not each document. What varies is the *role* that signed (a Focal
  Person owns her courses; the office can clear anything), so the role is what the mark carries. If
  the office wants a genuine per-requirement countersignature, that is a workflow change, not a
  presentation one.

  The 279 rows approved before `verified_by` existed have no signer, and say so — *"Verified before
  this system recorded who signed"* — rather than implying whoever is looking signed it.

- **Empty states match the other screens.** Quill, a heading and one line, from the same `.nx` block
  as Schedule Approvals and Scheduling. Four screens, one empty state.

- **Cards arrive in a stagger.** Twenty cards appearing in one frame reads as a jump; the same
  twenty landing over a fifth of a second reads as a page settling. Capped so the last card is not
  still arriving after the reviewer has started reading, and the signature mark lands rather than
  blinks on. All of it inside `prefers-reduced-motion: no-preference`.

  Verified in both themes against live data: 39 group tiles, 279 workspace cards, 88 signatures,
  no `::before` rails left, no JS errors, no horizontal overflow.

### Fixed
- **The empty states were using the wrong Quill (2026-09-24).** `assets/images/mascot.png` carries
  a stray white shape beside the left wing. The mascot of record is the **inline SVG** on the login
  screen (`assets/mascot/mascot.php`), so that artwork is now a component,
  `dashboards/components/quill_empty.php`, with three things taken out of it:

  - **no `id` attributes.** `mascot.js` drives the live bird by id (`#quill-svg`, `#quill-pupils`,
    `#quill-wingL`…); a second copy of those ids on a page would have it animating whichever one
    the DOM handed it first.
  - **no `<filter>` defs** — a duplicate filter id is a silent mis-paint, and a 96px mark does not
    need a blurred contact shadow.
  - **no animation.** An empty state is furniture; a waving bird beside "nothing is waiting" pulls
    the eye to the one part of the screen with nothing to say.

  Its colours are the brand's own hex rather than theme tokens: Quill is the same bird in light and
  dark, the way a logo is. `$quill_size` is `unset()` after it is read, because the file is
  included more than once per page and a size left set by the first caller would silently size
  every one after it.

### Changed
- **The rest of Scheduling's empty states caught up (2026-09-24).** Four were still bare grey text:
  an opened section whose date has passed, the Upcoming card, the Upcoming modal, and the record's
  "no match". The two in the 330px sidebar use a 60px `.nx-sm` variant — a 96px bird there *is* the
  card.

  The two built in JavaScript **clone** the bird already in the document rather than carrying a
  second copy of the path data, which would drift the first time the artwork changes. A page whose
  lists are all full has no rendered empty state to clone from, so there is one hidden `#nxBird`
  holder that guarantees a source.

  Verified on both phases and on Approvals, light and dark: no `#quill-*` ids leak onto the page
  (measured: 0), no reference to the PNG remains (0), no JS errors, no horizontal overflow.

### Changed
- **Every "nothing here" on the two staff queue screens is Quill now (2026-09-24).** An empty
  screen was a lucide glyph and two lines of grey, which reads as something that has not finished
  loading — the difference between a shop with a "back in five minutes" sign and a shop with the
  lights off. The mascot stands in the middle and the copy says what the screen is waiting FOR,
  not merely that there is none of it.

  One shared `.nx` block, written into **both** files, because a person moves between Approvals
  and Scheduling all day and an empty one should not look like a different product from the other.
  Six states covered: nothing in the chain, nothing waiting on anybody, nothing bounced, the two
  halves of the archive, nothing to log, and an empty record.

  Where the desk is clear but the term is not, the state now carries a way onward rather than a
  full stop — *"4 published dates already through the chain"* on Approvals, *"1 section is waiting
  on the proposal defense screen"* on Scheduling. "Nothing is waiting" on its own leaves you
  wondering whether the screen is broken, which is the complaint that started this.

  The mascot is `alt=""`: it carries nothing a sighted reader does not also get from the heading,
  so a screen reader should skip it.

  Verified in both themes on both screens, including the cross-phase notice (staged inside a
  rolled-back transaction): image loads, no JS errors, no horizontal overflow.

### Fixed
- **An unterminated `/*` in Defense Scheduling was silently deleting twenty CSS rules
  (2026-09-24).** The calendar rebuild used `/* ── Upcoming rows,` as the end marker of a span
  replacement *and* left that same fragment at the tail of its replacement text — an opening
  comment with no `*/`. It ran on to the next comment close and ate everything in between:
  `.ds-up-row`, `.ds-logged-row`, `.ds-chev` and `.ds-empty`, which is why the empty state had
  been rendering as bare left-aligned text with no box.

  Braces still balanced and PHP still linted, so nothing flagged it. Caught by reading
  `document.styleSheets[…].cssRules` back out of the rendered page: **242 rules parsed against
  254 in the file**. That check is the reliable one for CSS and is now the habit.

- **The "To log" count read 0 while a section sat waiting (2026-09-24).** It is scoped to the
  phase, and the one Confirmed section in the database is a *proposal* — so the final-defense
  screen showed `0` with no way to know the work was one screen away, which reads as a counter
  that does not work. The empty state now names the phase and links across:
  *"1 section is waiting on the proposal defense screen →"*.

- **A Coordinator was shown a "To log" tab she can never fill.** `fp_can_act()` gives that
  signature to the Secretary alone — deliberately, so one person cannot sign two steps of the
  chain. The tab is hidden for her now and she lands on the record.

### Changed
- **The month summary stays one small line (2026-09-24).** `2 open` was dangling on a line of
  its own. The fix was to stop it wrapping, not to make it a feature: `flex-wrap: nowrap` and
  short labels, so wrapping is impossible rather than unlikely. It reads `0 Full · 12 Partly ·
  0 Closed · 2 Open` at **24px tall** — a first attempt at a four-column count-over-label grid
  was 58px, and every pixel here is a pixel of the Upcoming card below it that you cannot see.

- **The way back out of an opened section is a button.** It was a bare `&larr;` entity in a text
  link with its padding zeroed inline — the thing that kept reading as unfinished. Bordered pill,
  lucide arrow, and it slides a pixel left on hover.

- **The planner's key moved to the top right, beside the month.** You need it while you are
  reading the chips, not after four hundred pixels of grid.

  Rendered against live data on both phases, light and dark, plus the opened section and the
  planner: 254 rules parsed, summary one row, no JS errors, no horizontal overflow.

### Changed
- **The Scheduling tabs moved to the list they control, and became one track (2026-09-24).** They
  were right-aligned above the whole two-column page, which put them physically on top of the
  **calendar** — a control hovering over the one thing it has nothing to do with. They now sit at
  the head of the left column, left-aligned, as a single segmented pill like every other pill group
  in the app, and they are hidden inside an opened section, where the back link is the navigation.
  The "Sections to log" heading went with the move: the tab already names the list and carries its
  count, and saying it twice is how the top of this page got noisy.

- **The office calendar, rebuilt on two rules (2026-09-24).**
  - **Six weeks, always**, with the neighbouring months' days shown faintly. A 5-week month and a
    6-week month were different heights, so the whole right-hand column jumped every time you paged.
    Measured: 263px in September, 263px in October.
  - **Capacity on the face of the day.** A flat colour wash said "something is booked here" and
    nothing else, when the question asked of this calendar is always *how much room is left*. Each
    booked date now carries two ticks under the number — one per institution, because the two tens
    are separate pools and one full + one empty is **not** a half-full day, which a single bar would
    have claimed.
  - Closed days are **hatched** as well as tinted: it is the one state somebody decided, rather than
    a consequence of bookings, and in a month where half the days carry colour it needs to survive a
    glance.
  - The three-colour key is replaced by **what the month actually holds** — `0 full · 12 partly
    booked · 1 closed · 2 open`. The colours are legible on their own; the counts were not.
  - A **Today** button, and month + year properly typeset.

- **The planner's grid is a grid again (2026-09-24).** A research title runs to fifteen words, and
  letting three of them set the row height made every row a different size and every month a
  different size again. Cells are a fixed height, chips are **one line** with an ellipsis, at most
  **three** show and the rest is `+N more` (a day can hold twenty), and the per-institution split
  sits in the corner where a diary would put it. Six rows here too.

### Fixed
- **The back link out of an opened section was unfinished** — a bare `&larr;` entity in a text link
  with its padding zeroed inline. It is a proper control now: lucide arrow, accent, matching the
  back link on Schedule Approvals.
- **The planner's legend had lost its styles and its meaning.** `.ds-legend` was deleted along with
  the old mini-calendar block, so the key rendered as one run-on sentence — and it still said
  "Waiting for a decision", the retired flow. Amber in the planner is a day a section **holds** that
  the Secretary has not logged yet, and it now says so.
- The opened section's heading printed the raw `2026-10-06`. Written out.

  Rendered in both themes: queue, record, opened section and planner; 42 day cells, grid height
  identical across months, no JS errors, no horizontal overflow.

### Removed
- **The "Waiting for a decision" queue is gone from Defense Scheduling (2026-09-24).** It read
  `defense_bookings WHERE status = 'Date Selected' AND batch_id IS NULL` — the shape of a row
  from the flow where a student picked their own date. That flow was retired on 2026-09-19 and
  nothing can write that shape any more, so the section had been sitting at the top of the
  screen as a permanently empty box, above the actual work. Zero such rows exist.

- **`schedule` and `reject` are refused in `defense_handler.php`**, the same way `select_date`
  already was. Removing a form is not closing an endpoint, and `schedule` mints a slot and
  publishes a date to a group **carrying none of the three signatures** the focal → coordinator
  → secretary chain exists to collect. `cancel` stays: bookings logged before the chain existed
  have no batch, and undoing one is still the office's job.

### Changed
- **Defense Scheduling now has two tabs instead of three ways to reach one list (2026-09-24).**
  The record used to be a loose bar of pills wedged between the (dead) queue and the bottom of
  the page, with a panel that unfolded under it — and the same rows were also reachable through
  a "Search all" modal and through the Upcoming card beside it. Three doors, no home.

  It is one pill group now, top right, the same shape as Schedule Approvals: **To log** ·
  **Record**. The record's own filtering lives in the record's own header — a search box with
  `All / Upcoming / Past` beside it, list inline, no modal. Upcoming steps aside on that tab
  because it is literally the record's forward half; on the queue it stays beside the calendar,
  which does not move, because reading a day is how this screen is used either way.

### Fixed
- **The office calendar's legend was wrong (2026-09-24).** It labelled the amber day "Requested",
  but the code paints amber on any **partly booked** day (`slots_left > 0`), and nothing has been
  "requested" since students stopped picking dates. On a busy month nearly every day wore it, so
  the Secretary was reading a queue of pending asks that did not exist. It now says what the
  colours mean: *Full — both institutions* / *Has bookings, still open* / *Closed*.

- **The whole screen was rendering light-theme colours in dark mode (2026-09-24).** It reached for
  `var(--border, #e5e7eb)`, `var(--text-primary, #0f172a)` and `var(--text-muted, …)` in 29 places
  — and portal.css defines none of those three names (they are `--line`, `--ink`, `--muted`), so
  every one silently fell through to its hardcoded light value. Light borders throughout, and the
  two signatures under a section card were near-black on dark green: invisible. All 29 repointed.

- "The group has been notified and can pick a new date" on a cancelled booking — nobody picks
  dates any more. It now points at the Focal Person.

  Rendered in both themes on both tabs against a staged section (rolled back): no JS errors, no
  horizontal overflow, record search and all three filters live.

### Changed
- **Three pills, top right, and that is the whole chrome of the Approvals screen (2026-09-24).**
  The All / Proposal / Final filter added hours earlier is gone: the phase is already settled by
  whichever nav section opened the screen, so a filter there only offered you the chance to
  contradict the choice you had just made. In its place, one pill group on the right —
  **Waiting · Sent back · Published**, each with its count — and nothing on the left.

  Sent back moved out of the queue with it. It reads like a third thing waiting on you and it is
  not: those are on the Focal Person's desk, and nothing this office does moves them. The page is
  now only what is actually in motion here — waiting for you, and with someone else.

### Fixed
- **The bundle header date, for the third and last time (2026-09-24).** It went raw ISO in
  monospace beside the same day written out, then a 40px numeral with the month and weekday
  stacked next to it — which still read as three broken lines (`30` / `September 2026` /
  `Wednesday`). A date is one fact, so it now gets one line: a small `DEFENSE DATE` label and
  `Friday, 25 September 2026`. No numeral, no stack, nothing to misread.

  Rendered in both themes and every list view (waiting, sent back, published, archive paged):
  no JS errors, no horizontal overflow.

### Changed
- **The Approvals page shows only what is waiting; Published moved behind its own door
  (2026-09-24).** Reverses the log-row experiment from earlier today — the cards were the right
  shape, the *placement* was the problem. Published is finished business and the only part of
  this screen that grows without limit, so on the working page it did nothing but push the
  things still waiting off the top.

  The queue page is now three sections — waiting for you, sent back, with someone else — and
  nothing else. The archive is `?view=published`: the same cards, a **Coming up / Already held**
  switch, twelve to a page. That caps the page permanently without hiding anything, and it stops
  a term's worth of signed-off dates from competing with the one bundle that needs a signature.

- **The filtering has somewhere to live (2026-09-24).** The phase filter existed only as a `?phase=`
  the nav happened to pass, so from the screen itself it was invisible. There is now one control
  row under the title — All / Proposal / Final on the left, the way through to Published (with its
  count) on the right — and it is the only control row on the page. The jump bar went with it: it
  was navigation for four sections, and three short ones do not need it.

- **A held date no longer reads as an emergency (2026-09-24).** `.br-when.bad` painted every past
  date red, which is right while a bundle is unsigned and wrong for the archive, where a passed
  date is just history. Cards in the `done` state dim it instead.

  Checked against a staged two years of history (26 fabricated published batches, rolled back):
  waiting page 3 cards / no pager, Coming up 4, Already held 12 of 23 across 2 pages, phase filter
  and the archive round-trip both keeping their query state, no JS errors, no horizontal overflow,
  light and dark.

### Fixed
- **The opened bundle's header date was mangled, and the cause was a name collision
  (2026-09-24).** The Approvals rebuild introduced `.br-hero` and `.br-chain` for the list view
  without noticing that the opened-bundle view had been using both names since it was written.
  The opened header therefore picked up a flex row meant for an icon plus two lines of text, and
  the date inside it — a 24px monospace `2026-09-25` sitting beside the *same* day written out
  in full — collapsed into it.

  The list-view classes are the newer ones, so they are the ones that moved: `.br-hero*` →
  `.br-banner*`, `.br-chain*` → `.br-flow*`, markup, CSS and the ticker's `closest()` together.
  The header now prints the date once — `25` / `September 2026` / `Friday` — instead of twice in
  two formats, one of which nobody reads. Verified by scanning the style block for duplicate bare
  selectors; none are left.

### Changed
- **Published is a log now, not a wall of cards (2026-09-24).** It is the one section on this
  screen that never empties: every term adds a handful of signed-off dates and none of them ever
  leave, so by the second year it would have buried everything above it. A card was the wrong
  shape for it anyway — a published date has nothing waiting, nothing to sign, and a chain
  drawing with all three nodes ticked says nothing at all.

  It splits at today. **Still ahead** renders as rows (date chip, section, course · phase ·
  groups, and how far off it is), because those are the dates the office is actually asked about.
  **Already held** folds away behind a counted `<details>`, most recent first — the query sorts
  ascending, which is right for a queue and backwards for a record — capped at 12, with a line
  saying how many older ones are not shown and that the phase filter narrows it. The section
  costs the same to read in five years as it does today.

  Checked against a staged two years of history (26 fabricated published batches, inside a
  rolled-back transaction): 4 still ahead, 23 held, fold shut by default, 12 rows inside it, no
  JS errors, no horizontal overflow, light and dark both.

### Fixed
- **Every "waiting" time on the Approvals screen read as nothing (2026-09-24).** Found while
  building the counter below, and it is the timezone trap in CLAUDE.md wearing a new hat: this
  box runs **PHP on Europe/Berlin and MySQL six hours ahead**, so `strtotime()` on a `DATETIME`
  out of `defense_batches` lands in the *future* relative to PHP's own `time()`. Age came out
  negative, clamped to zero, and a bundle that had sat for five hours reported "just now".

  `fp_chain_queue()` now returns `UNIX_TIMESTAMP(b.focal_at|coord_at|returned_at)` — true epochs
  computed by the **database**, which is the only clock that agrees with the stored values —
  and the screen no longer parses those datetimes in PHP at all. A bundle staged five hours back
  now reports `5h`.

### Added
- **The Approvals screen rebuilt around waiting (2026-09-24).** The crux of this screen is that
  something is sitting on someone's desk, so that is now what it shows.

  - **Sections, not tabs.** Four tabs hid three quarters of the page behind a click, and the one
    question the screen is asked — *what is waiting, and how long has it been waiting* — was
    never answerable without hunting. Everything is on one page now in priority order: yours,
    sent back, someone else's, published. The pill bar is a **jump bar** (scrolls, with a live
    marker for the section you are in) rather than a filter, so nothing is ever hidden.
  - **A live waiting counter.** Each of your bundles carries `waiting 5h`, and the header names
    the oldest one. Rendered from the timestamp on the client and ticking once a minute, so it
    does not go stale in a tab left open — counting from a **server** clock stamped at render,
    never the browser's.
  - **The chain is drawn, and the leg it is sitting in moves.** Three nodes, two rails, and on
    the in-flight leg a dot that travels it every 2.6s, with the waiting node pulsing. Motion
    that *means* "in transit" rather than a spinner that means "please wait" — and the whole lot
    is switched off under `prefers-reduced-motion`.
  - Cards yours to sign get a gradient ground and a lit edge; the date is a calendar chip that
    turns amber inside three days and red once it has passed.

  Verified in the browser against a staged queue (one bundle in each chain state, inside a
  rolled-back transaction): 3 cards, 3 sections, 2 animated legs, 2 pulsing nodes, `brFly` at
  2.6s, counter reading `5h`, no JS errors, no horizontal overflow, light and dark both checked.

### Fixed
- **"See all" opened an empty activity log — my own regression (2026-09-24).** The previous change
  added `moIcon()` for the feed but defined it *inside* `renderGroupProfile()`, while
  `window.filterModalLogs()` — the modal's renderer — is a sibling function that could not see it.
  Opening the modal threw `moIcon is not defined` before a single row was written, so it appeared
  with its filters and a blank list. `moIcon()` now sits at shared script scope above both
  callers; the modal renders all 60 rows and search/status filters work again.

- **The activity-log modal was invisible in dark mode.** Several panels carried hardcoded cream
  (`#faf9f6`, `#faf8f4`, `#fdfdfd`, `background:#fff`) that survives the theme, so correct light
  text landed on a near-white surface — the row titles were unreadable. All of it is theme tokens
  now, including the inline `onmouseover` hover colour and the footer button. Measured rather than
  eyeballed: title-on-surface contrast is **10.4:1 light / 13.1:1 dark** (4.5 is the bar), and the
  partial has no hardcoded light colours left.

  The modal also sat at `z-index: 200`, under the side-nav's 600 — it now clears it at 900, with a
  violet scrim and a blur instead of the old grey wash.

### Added
- **"See all" is the student's pill.** Matched to `student.php`'s `.btn-see-all`: a quiet tinted
  chip in uppercase that fills with the accent and lifts on hover, rather than the flat grey
  chip it was. The modal shell, header, filter bar and footer were rebranded off the leftover
  cream-and-teal palette onto portal.css tokens at the same time.

### Added
- **Staff side-nav and activity feed revamped (2026-09-24).**

  - **The brand lockup is the student one now**: mascot + `iSubmit` + the
    `Research Digitalization` sub-line. A wordmark on its own read as unfinished. Fitted to the
    194px rail and measured in the browser rather than eyeballed — the first cut truncated to
    "Research Digitalizati…".
  - **The nav marks where you are.** An active row used to change colour only, which is hard to
    find in a nav this long; it now carries a violet rail marker at the left edge and tints its
    icon. Section labels got more air.
  - **Activity icons follow the event.** Every ordinary row wore the same generic `file-text`, so
    a feed of ten rows was ten identical glyphs that told you nothing at a glance — and it used
    `--info`, the system **blue**, which is a colour borrowed from no part of this product. Now:
    submissions `file-up`, dates `calendar-days`, payments `banknote`, plagiarism `shield-check`,
    statistics `sigma`, accounts `user-plus`, messages `message-square`, and the ordinary case
    takes the brand accent. A verdict (approved / sent back) still wins over all of it, because
    that is the most important thing a row can say.

  The icon table exists **twice on purpose** — `mo_activity_icon()` in PHP and `moIcon()` in JS —
  because the live poller rebuilds the same markup, and the two drifting apart is how a feed ends
  up with two visual languages depending on whether you reloaded. Both carry a note pointing at
  the other.

  `mo_activity_icon()` is declared at **file scope**. It was first written inside the
  `else`-branch of the "no activity yet" check, where it happened to work only because the
  declaration preceded its use — PHP does not hoist a function declared inside a conditional.

  One scripting trap worth recording: a PHP close-tag sequence inside a `//` comment still
  terminates PHP mode. A patch script that mentioned one in a comment silently echoed its own
  source and changed nothing, while reporting success.

- **The whole staff side now wears the student palette and the iSubmit mascot (2026-09-24).**
  The admin shells were still Apple-grey with a generic graduation-cap icon while the student
  dashboard, the focal portal and Staff Accounts had all moved to the lavender theme.

  **Changed in `portal.css`, not per page.** Every staff shell, module window and overlay loads
  that file, so the palette has exactly one home — and the two per-page copies that had built up
  (`focal.php`, `admin_accounts.php`) were deleted rather than left to drift.

  - **Light:** ink `#4c1d95`, accent `#6d28d9`, lines `#ede9fe`, surfaces tinted lavender —
    lifted from `student.php` `body.theme-purple`. The INK is the part that mattered: the old
    near-black `#1d1d1f` is what made every staff screen read as an admin panel rather than as
    iSubmit.
  - **Dark:** repitched from neutral grey-black to a purple-tinted dark (canvas `#16121f`,
    surfaces `#1e1830`/`#271f3d`) with `#ede9fe` ink and the `#a78bfa` accent, so dark mode is
    the same product rather than a different one.
  - **Shadows** carry the brand hue (`rgba(76,29,149,…)`) instead of neutral black.
  - **The iSubmit mascot** replaces the graduation-cap glyph in all three shells' side-nav, with
    the same floating drop-shadow treatment as the student header lockup (and a purple glow on
    dark, where a near-black silhouette would otherwise disappear).

  Two traps worth recording. **`portal.css` is CRLF end to end** — LF-anchored patches match
  nothing in it and fail silently; normalise both sides and keep the file's endings. And the
  dark-mode screenshot recipe only works if the theme is seeded **before `portal.js` loads from
  `<head>`** — seeding before `<body>` is already too late, and the page just renders light.

  Verified after: 24/24 permission and 13/13 capacity assertions still pass, all 9 pages render
  clean, `portal.css` braces balanced and line endings unchanged, light and dark both checked
  across the Director shell, Coordinator shell, focal portal and Staff Accounts.

- **Staff Accounts wears the app palette, and the bare course counts are gone (2026-09-24).**

  - **The numbers next to each course were group counts** — but unlabelled, a naked "1" on every
    row is noise, not information. Removed from both pickers. The signal that actually matters
    when assigning is the amber dot for a course nobody handles, and that stays.
  - **The student lavender palette**, the same one `focal.php` wears (`student.php`
    `body.theme-purple`: ink `#4c1d95`, accent `#6d28d9`). Declared on `body, body.theme-light`,
    because portal.css sets those tokens on that same pair and a bare `body` loses on
    specificity — verified in the browser that the rendered title really is `rgb(76,29,149)`
    rather than assuming the override applied.
  - Rows 16px with a lift on hover, panel 18px with a soft shadow, course rows with a hover
    state, and the picker capped at 320px so it scrolls in place instead of running the sidebar
    off the page.

  One trap worth recording: the edit that strips the counts is anchored with a leading `\n`. Both
  pickers render the same `<span class="aa-n">` line, one indented 36 spaces and one 52 — and a
  36-space indent is a *substring* of a 52-space one, so an unanchored needle matched both and
  the patch refused. Anchor to the start of the line.

- **Staff Accounts: the top of the page is gone (2026-09-24).** Four stat cards and a red banner
  listing every uncovered course sat above the two controls that do the work, so the list started
  below the fold on a 900px screen. Both removed.

  The uncovered-course information was worth keeping, but not as a banner — it now marks the
  courses **inside the picker**, at the moment she is choosing what to assign: a small amber dot
  on each unhandled course, and one legend line ("19 of these have no focal person yet") under
  the filter box. The count is the same number the banner used to shout.

  Written as a word first — "UNASSIGNED" — which wrapped every second course name in a 352px
  sidebar. A dot costs no width, which is the rule for pills in this project: short variants, no
  wrapping.

  Also: the create form is sticky on wide screens so it follows a long list, and `$focal_count`
  went with the stat card that was its only reader.

  The whole staff list now fits one screen with the form beside it.

- **Staff Accounts rebuilt for a long list (2026-09-24).** Six accounts already filled a screen
  and a half; twenty would have been unusable. The screen is shared by the Director and both
  Coordinators, so it had to survive growing.

  - **A staff member is a row, not a card.** Name, email, role and scope stay visible — those are
    what she scans for — and every action folds behind one **Manage** toggle. Six rows now fit in
    the space two and a half cards used to take.
  - **Search + role pills** above the list: the search matches name, email, role, course and
    institution; the pills carry live counts and narrow alongside the search rather than fighting
    it. An empty result says so instead of showing a blank column.
  - **The create form is re-ordered.** The course picker is twenty-one checkboxes tall, and with
    it above the identity fields the name, email and password sat below the fold. Identity first
    now, scope last, with its own filter box and a live "*n* courses picked" count.
  - **A single-role manager gets no Role dropdown.** A Coordinator can only create Focal Persons,
    so the select had exactly one option — it is a hidden input now and the form is one field
    shorter.
  - **Disabling sign-in is no longer the loudest thing on the row.** It was a red button on every
    card; it is a quiet link at the bottom of the Manage drawer, which is where a destructive
    action belongs.
  - `coordinator.php`'s nav entry is now labelled **Staff**, matching the Director's, with the
    window still titled "Focal Persons".

### Fixed
- **`admin_accounts.php` contained two raw NUL bytes (2026-09-24).** An earlier edit wrote the
  `"\0none"` sentinel as an actual NUL rather than the two-character escape. It *worked* — the
  string value is identical either way — but it made the file binary to `grep` and every other
  text tool, and one editor save could have silently dropped it. Replaced with the real escape;
  `dashboards/` now has none. (The trap: writing the patch through a shell collapsed `\\0` to
  `\0`, so the "replacement" was byte-identical to what it replaced. Built with `chr(92)` instead.)
- **Two bugs this codebase keeps producing, both hit again here.** `.aa-acts` is `display:flex`,
  which beats the `hidden` ATTRIBUTE, so every row's action drawer rendered open — fixed with the
  `[hidden]{display:none!important}` guard. And lucide replaces `<i data-lucide>` with `<svg>`,
  so the `.aa-search i` rule stopped applying once icons rendered and the search glyph fell back
  to 24×24 outside its box — the rules target `i, svg` now.

- **Figure 7: context diagram, DFD level 0 (2026-09-22).**
  `docs/diagrams/fig7_context_diagram.svg` + `.png`, from the same
  `docs/diagrams/flowcharts.php` generator.

  One process — the whole system — and every outside party that puts data in or takes data out,
  each with a labelled flow in both directions. Six entities rather than the usual two or three,
  because the office really does have six distinct roles, and each one's pair of flows is a
  one-line statement of what that role is for:

  | Entity | Puts in | Takes out |
  |---|---|---|
  | Student / research group | Research documents | Remarks, defense date |
  | Focal Person | Approve / revise, section date | Groups and documents to check |
  | Research Coordinator | Confirm / return endorsement | Endorsed sections (own institution) |
  | Secretary | Logged slots, published dates | Confirmed sections to log |
  | Statistician | Treatment status, payments | Statistics forms, payments |
  | Research Director | Staff accounts and scopes | Office-wide reports |

  No internal steps: what happens *inside* iSubmit is figure 6's job, and mixing the two is what
  makes a context diagram unreadable.

  Two drawing bugs fixed on the way, both worth keeping in the helper: the two directions of a
  flow were labelled on the *same* side, so you could not tell which label belonged to which
  arrow (each label now sits out past its own line); and labels were emitted as each flow was
  drawn, so every later line painted over an earlier label's white knockout (they are queued and
  emitted after all the lines).

- **Figure 6: the defense workflow with the Focal Person (2026-09-21).**
  `docs/diagrams/fig6_focal_workflow.svg` + `.png`, generated by the existing
  `docs/diagrams/flowcharts.php` (`php docs/diagrams/flowcharts.php`) so it stays in the same
  plain black-on-white, all-caps paper language as figures 1–3 instead of being drawn by hand.

  Four swimlanes — STUDENT / FOCAL PERSON / COORDINATOR / SECRETARY — because the whole point of
  the redesign is who does what. It replaces the student-picks-a-date model still shown in
  figure 1, and both phases are drawn in the same shape so the figure only has to be read once:

      submit → focal checks → approve → set the section date and endorse
             → coordinator accepts → secretary logs the slots and publishes

  - **Only the DATE travels up the chain.** A requirement approval is the Focal Person's alone,
    which is why the Coordinator and Secretary lanes are empty until a section is endorsed.
  - **The Secretary's step IS the scheduling** — logging the slots is what makes the date real
    to the group, not a separate step after it.
  - **Statistics is submitted inside the final-defense phase**, alongside the other final
    requirements, not after the face-to-face defense. This already matches the code
    (`$unlock_stats = $prop_scheduled` in `student.php`); nothing changed, the figure just says
    it out loud.

  Every edge is orthogonal, and the figure carries no commentary boxes — the first cut had both,
  plus diagonals across four lanes and a return arrow through a box, which is what the old chart
  was being replaced for.

  **Figure 1 is now out of date**: it still shows "APPLY AND BOOK A DEFENSE DATE" in the student
  lane, which is the retired model.

- **Each Coordinator can now mint her own Focal Persons, for her institution's courses only
  (2026-09-21).** Staff Accounts was Director-only; a Coordinator had to ask the Director for
  every new focal person in her own institution.

  Same defence-in-depth as group scope — **the screen hides what she may not touch and the
  handler refuses it independently**. A hidden card is not a protected card.

  New in `admin_accounts_lib.php`:
  - `AA_MANAGER_ROLES` — who may open the screen at all.
  - `aa_creatable_roles()` — Director: all four; Coordinator: **Focal Person only**. Letting her
    mint a Coordinator or Director would let her hand out reach she does not have herself.
  - `aa_manager_school()` — her institution, or a sentinel nobody matches when it is unset, so an
    unfinished account can assign nothing rather than everything.
  - `aa_courses_for()` / `aa_all_courses($pdo, $school)` — the courses she may assign.
  - `aa_may_manage()` — she may act on a Focal Person only when **every** course that person
    handles is hers. "Any" would let one cross-institution assignment hand her the account; a
    focal person with no courses belongs to nobody, so she is not hers to claim either.
  - `aa_staff_for()` — the list, filtered by the same rule.

  Every handler action against an existing row now checks `aa_may_manage()`; `set_school` is
  Director-only, because a Coordinator changing an institution would be rewriting her own reach.
  `aa_clean_scope()` validates posted courses against *her* allowed set, not the global one, so a
  tampered form cannot reach across institutions. The institution field is not rendered for her at
  all (and the role JS null-guards its absence).

  Coverage is still computed across **all** focal persons, not her filtered list — a course
  covered by someone else's account is covered, and showing it as her to-do would have both
  coordinators trying to fill it.

  `coordinator.php` gained a **Focal** nav entry opening the same screen; it scopes itself.

  24 assertions pass, including the ones that matter: MCNP's coordinator cannot manage an ISAP
  focal person, cannot manage the Director, another Coordinator, the Statistician, or herself;
  a focal person split across both institutions belongs to neither; an unscoped coordinator
  assigns 0 courses and manages nobody. Rendered as all three roles: Director sees 4 roles / 21
  courses / 6 staff, MCNP sees Focal Person / 9 MCNP courses (Nursing, Midwifery, Pharmacy — no
  Information Technology) / 0 staff, ISAP sees 12 ISAP courses / her 2 focal persons.

- **Focal Person header spacing, and an icon-sizing bug it exposed (2026-09-21).**
  The lockup and the name sat 54px down the page with a 68px dead gutter on each side. Cause:
  `portal.css` gives `body.admin-module` 28px of padding on every side, and the bar added its
  own 40px on top — the two **stacked**. The page owns its spacing now: `body.admin-module`
  padding zeroed here, one gutter set once. The lockup starts 20px from the top and 40px from
  the left, and every header item shares a centre line (measured, not eyeballed).

  Measuring it turned up a **real bug across the whole page**: lucide REPLACES `<i data-lucide>`
  with `<svg class="lucide">`, so any rule written only against `i` stops matching the moment
  icons render, and they fall back to 24x24 — overflowing every slot sized for 12 or 15. Eight
  rules now target `i, svg`; icons measure 17/15/12 as intended instead of all 24. (`.fp-legend i`
  is deliberately untouched — that one is a colour swatch, a real `<i>`, not an icon.)

- **The Focal Person portal now actually wears the student palette (2026-09-21).** Retuning only
  the accent had not fixed it, and the reason was the **ink**: portal.css sets a near-black
  `#1d1d1f`, while every word on the student side is violet-tinted. Grey type is what read as old,
  not the purple.

  The student's own lavender theme is now lifted verbatim from `student.php`
  (`body.theme-purple`): ink `#4c1d95`, secondary `#5b21b6`, lines `#ede9fe`, accent `#6d28d9`.
  It is declared on `body, body.theme-light` — portal.css sets these on that same pair, so a bare
  `body` selector loses on specificity and the override silently did nothing.

  - **Header runs full width.** It was a 1120px column centred in the viewport, leaving a dead
    gutter down both sides, and a hairline rule across the middle of nothing. Both gone; brand
    hard left, controls hard right, like the student header. The stray divider between the
    lockup and the name went with them.
  - **The iSubmit lockup is full size** — 52px mascot, 24px violet wordmark, floating
    drop-shadow — instead of the small grey version.
  - **The home cards are billboards**, the treatment from
    `components/dashboard_billboards.php`: a saturated gradient ground with white type, uppercase
    eyebrow, drifting highlight and a CTA row. Requirements is violet
    (`#7c3aed → #4c1d95`), Defense dates emerald (`#059669 → #064e3b`) and **rose the moment a
    date bounces** (`#e11d48 → #881337`), so the card itself is the alarm. The pale tinted
    version of these read as grey boxes with a hint of colour, which is what "looks old" meant.

- **The focal date picker is a real month calendar again (2026-09-21).** The flat strip of day
  tiles ran to six rows in a 31-day month and read as a wall. Replaced with the month grid the
  students used to book with — SUN–SAT columns, one month on screen, arrows bounded by the booking
  window — ported from `components/defense_scheduler.php` rather than invented, so both halves of
  the system speak the same calendar.

  - States carry over: open, **your pick**, **filling up** (3 or fewer left, amber), full, closed,
    with the legend underneath. A day only prints "n left" once it has started filling — on an
    empty month "10 left" on all 31 days is noise.
  - Tapping a day shows **who is already on it** — slot, group, course — in one panel under the
    grid, instead of printing the breakdown on every tile at once.
  - `aria-disabled` rather than `disabled` on unavailable days, so tapping one can still say *why*.
  - `$fpCal` is built from `df_month_cells()` scoped to the section's institution, so the grid is
    that institution's ten, not a shared pool.

- **The Focal Person portal now follows the student dashboard's house style (2026-09-21).** The
  office asked for it to look like the student side rather than the muted staff grey.
  `portal.css` stays the base — layout, dark mode and controls all still apply — but:
  - the **iSubmit mascot lockup** leads the bar, same floating drop-shadow treatment as the
    student header (and it links home);
  - the accent is retuned from the staff `#6c5fa0` to the student violet `#7c3aed` (`#a78bfa` on
    dark), which the calendar, rings and chips all inherit;
  - the home cards commit to their colour instead of hinting at it — tinted grounds, coloured
    ring percentage and count, visible ghost numerals.

  This is a deliberate exception to "don't mix the two design systems": it borrows the student
  *language*, not `theme.css`. Nothing from the AUTH stylesheet is imported.

- **"Institution", not "school", in user-facing copy (2026-09-21).** The office calls MCNP and ISAP
  institutions. 13 visible strings changed across 6 files — the Coordinator's account form
  ("Institution she covers", "Pick an institution", "No institution set"), the focal date picker,
  and the three "no slots left" messages students and staff see.

  Identifiers (`$school`, `df_schools()`, `aa_school_tag()`, `DEFENSE_SLOTS_PER_SCHOOL_PER_DAY`)
  were left alone on purpose: renaming them is churn no reader benefits from. Two phrases were
  also left because they mean the campus, not the institution — the calendar's "School event"
  category and "holidays or school events". **"School ID Number" is untouched** — that is
  `users.student_number` and a different thing entirely.

- **The Focal Person can see who is in a group and when they last sent something (2026-09-21).**
  Both were questions she could only answer by opening a card and reading dates off the documents
  one at a time.

  - **"View names"** on every group card and at the head of the document screen: leader first,
    then members, each with their School ID Number and email. Reuses `group_roster_lib.php`
    (`ws_group_rosters()` / `ws_names_markup()`) and the same `wsToggleNames()` contract the
    office screens use, so the roster reads identically wherever it appears rather than being
    rebuilt a third time.
  - **Last submitted** — "sent 1h ago" / "sent Sep 2, 2026" / "nothing sent yet" on each card, and
    "last sent …" on the document screen. `fp_last_submitted()` fetches the whole section in one
    query rather than one per card; `fp_when()` gives recent submissions the relative form,
    because those are the ones she is chasing.

### Removed
- **The 10 animal test accounts were reset to "just registered" (2026-09-21).** Cleared 42 uploads
  (21 files off disk), 7 bookings, 62 activity logs, and the 4 batches those bookings emptied
  (#13, #15, #16, #26). **The accounts themselves were kept**, along with everything typed at
  registration — section, course, year level, group name and School ID Number — so they can be
  re-tested from a clean start without registering again.

  The script guards before deleting: it refuses unless all 10 ids resolve to Students on
  `@gmail.com`, it leaves any batch that still holds a non-test booking, and it only deletes a
  file no other upload row references. A dry run prints the counts and writes the snapshot;
  `--write` is required to apply. Verified afterwards: all 10 accounts present with their section
  still set and 0/6 approved, 289 other uploads and 32 other bookings untouched, batches 3/10/11
  intact.

- **Defense capacity is now PER SCHOOL: 10 each, not 15 shared (2026-09-21).** The office's rule is
  ten a day for MCNP and ten for ISAP — twenty in total. The code enforced one shared pool of
  fifteen, so MCNP booking a day closed it to ISAP: a live bug against the actual rule, and the
  reason two institutions were competing for one queue.

  `DEFENSE_SLOTS_PER_DAY` was **renamed**, not re-valued, to `DEFENSE_SLOTS_PER_SCHOOL_PER_DAY = 10`
  — any caller missed in the sweep becomes a hard fatal rather than silently enforcing 10 where it
  used to mean 15 school-wide. All 17 references across 8 files were updated.

  - `df_taken_dates()`, `df_next_slot()`, `df_month_cells()` and `df_soonest_open()` all take a
    school. `df_taken_dates(null)` still lists every booking on a date for the Secretary, but
    returns `left => null`: "how many are left" has no answer across two pools, and a caller
    treating null as a number is a bug worth crashing on.
  - **`slot_no` runs 1..10 within a school**, so a date legitimately holds two "slot 3"s, one per
    school. Confirmed as the intended model before building it.
  - Capacity under lock now locks the whole DATE with no join — the range lock over
    `idx_booking_date_status` also blocks an INSERT into the gap — and counts that school's rows
    behind it. Locking `users` rows as well would have widened the lock for nothing.
  - **The Secretary's planner splits by school**: "MCNP 3/10 · ISAP 7/10" rather than one pile of
    twenty, and a day is drawn FULL only when *every* school is full. `df_schools()` reads the list
    from data, so a third institution would get its own pool rather than none at all.
  - Which school: a student's own `df_user_school()`, a section's `sections.department`.

  Checked before changing anything: no booking has an owner without a school, no batch's section
  school disagrees with its groups', and no existing day breaches 10 per school — so nothing had to
  be re-slotted. 13 assertions pass (MCNP full leaves ISAP open; both schools hold their own slot
  1), all inside a rolled-back transaction, plus a 9-page render smoke covering both planner phases.

- **The focal date picker shows the whole booking window (2026-09-21).** It was hard-capped at the
  first 12 open days, which all fell in one month, so it looked as though no other month could be
  chosen — the window is `DEFENSE_HORIZON_MONTHS = 4` (98 open days today). Now a month strip
  (`‹ Sep 8 · Oct 26 · Nov 24 · Dec 22 · Jan 18 ›`) with every month already in the DOM: paging is
  client-side, because a link would reload and throw away the groups already ticked. A day chosen in
  a month you have paged away from keeps a dot on its tab, and the commit bar still names it.

- **Scheduling a section rebuilt as two steps with a sticky commit bar (2026-09-21).** It was one
  tall column — the live batch panel at full height, then every group, then twelve date cards,
  then the button at the very bottom. You could never see who you had ticked and which day you
  had picked at the same time, and the action was always below the fold.

  - **Two steps side by side** (`1 Who is going?` · `2 Which day?`), stacking below 940px. The
    group list scrolls inside its own card (`max-height`) so a section of thirteen groups no
    longer pushes the day picker off-screen.
  - **Day tiles instead of date cards** — weekday / big numeral / month, plus a capacity *bar*
    (green → amber → red) and "n free". The per-course breakdown used to print on all twelve
    tiles; it now appears once, under the picker, for the day actually chosen.
  - **A sticky commit bar** carries the decision and the button: "3 groups → Thu, 1 Oct 2026 /
    12 slots would be left on that day". It states the four states plainly — nothing selected,
    no groups ticked, no day picked, and *too many groups for this day* (which blocks the
    button; the handler still re-checks capacity under `FOR UPDATE`).
  - **`portal.css` never styled `:disabled`**, so a blocked Endorse looked identical to a live
    one. Given a local `.fp-commit .btn:disabled` rule.
  - **The in-flight batch panel is compact** — date, chain as three inline ticks, and the group
    /slot list behind a `<details>`. It is context for the decision, not the decision, and at
    full height it filled the first screen on its own.

  Form field names (`groups[]`, `defense_date`, `section_id`, `phase`, `action`, `csrf_token`)
  are untouched, per the presentation-changes rule. Dead CSS from the old screen deleted:
  `.fp-dates`, `.fp-date*`, `.fp-chain`, `.fp-step-i/who/when`, and the long-dead `.fp-open`
  (15 lines), verified by sweeping markup classes against style rules both ways.

- **Focal Person: the tab strip and the chain tracker are gone (2026-09-21).** Both were a second
  way of saying something the screen already said, and she reported the Schedule tab as
  "confusing and messy".

  - **No nav pills.** The header is now identity · Proposal/Final · bell · Sign out. Home is the
    default landing and its **two cards are the navigation** — a Home/Requirements/Schedule strip
    beside them just repeated the cards in smaller type.
  - **The breadcrumb is the only way up, so it now renders on every screen below Home**, the
    course list included (which previously had no exit at all once the tabs went), and it is
    rooted at Home with a round back chevron:
    `‹ Home / Requirements / BS Computer Engineering / 4th Year / Section A`.
  - **The "Dates you have sent" tracker is removed.** Where a date has got to is still on the
    Home card (published count, next date, and a card that turns red on a send-back), in the
    three-step panel inside the section itself, and in the bell the moment it changes — the
    tracker was a fourth telling, and a wall of five identical all-green cards at that.

  Dead CSS was deleted rather than left to rot: `.fp-tabs*`, `.fp-track`, `.fp-trk*`,
  `.fp-chainline`, `.fp-node`, `.fp-rail`, `.fp-sect-h` (30 lines), verified by sweeping markup
  classes against style rules in both directions.

- **Focal Person home is two cards, and the drill-down gained a year step (2026-09-21).**
  The first cut of Home put four counters, a tracker and a feed on one screen — too much going
  on. Replaced with **two wallet cards** shaped like the student portal's stage cards (26px
  radius, ghost numeral, progress ring, status chip, footer row with an arrow), built on
  `portal.css` tokens rather than importing `theme.css` — the STAFF and AUTH design systems stay
  unmixed while the shape is the one she already knows from the student side.

  - **Requirements** — a ring of everything approved across her courses, the count still needing
    her, and what to do next.
  - **Defense dates** — how many are published, the next one by date, and what is blocking. Its
    tint goes red the moment a date is sent back, so the card itself is the alarm.

  **Course → year → section** is the new path (was course → section). The office names rooms just
  "A" / "B" and lets the year tell them apart, so a course opened straight onto sections listed
  "A" six times over with nothing to choose between them. `fp_years()` buckets a course's live
  sections by `year_level`; `?year=` carries the step, `fp_url()` preserves it, an unknown year
  falls back to the year list, and a section deep-link supplies its own year so a pasted URL still
  draws the right breadcrumb (now Courses / Course / Year / Section / Group, each step a link up).

  The chain tracker moved off Home and lives on the Schedule tab, which is the tab she is on when
  she wonders where a date got to. Remaining ISO dates in the section panel and on the section
  pills are now written out ("Sat, 3 Oct 2026").

- **The Focal Person portal got a home screen and a bell (2026-09-21).** She reported the account
  as hard to navigate, worst once a date had been accepted — and she was right: `focal.php` landed
  straight inside Requirements, and the only record of what the chain had done with a date she
  endorsed lived in a panel *inside* the section she had endorsed. Finding out meant reopening
  every section one at a time, and nothing ever told her an answer had arrived.

  Three things, all reads — no workflow, handler or SQL write was touched:

  - **Home is now the landing** (`?view=home`, with Requirements and Schedule as drill-downs).
    An app-style greeting, then only the tiles she can act on: dates sent back, files awaiting a
    decision, groups cleared and needing a date, dates away for approval. All four at zero and the
    board collapses to one "nothing is waiting on you" card rather than four zeroes reading as a
    broken page.
  - **A chain tracker.** One card per date she has put forward, drawing You → Coordinator →
    Secretary as ticks, with the send-back reason inline when it bounced. It answers "where did my
    date get to" at a glance, and is repeated at the top of the Schedule tab, because that is the
    tab she is on when she wonders.
  - **A notification bell**, same behaviour as the student drawer. `fp_feed()` is **derived** from
    `defense_batches` and `uploads` rather than stored: every event is a timestamp those tables
    already hold, so the feed cannot drift from the truth it reports and no write path has to
    remember to log. Unread is a per-browser marker against the newest timestamp (`localStorage`
    `fp-notif-lastread-<uid>`) — comparing server times to the client clock would break here,
    where PHP runs Europe/Berlin and the office is in Manila.

  New in `focal_lib.php`: `fp_my_batches()`, `fp_feed()`. Because `fp_section_name()` emits an
  entity, feed `title`/`body` are HTML and escape the user input they mix in (group names, a
  coordinator's remark) at the point of mixing; the drawer prints them raw.

- **Two real coordinator accounts, one per school (2026-09-21).** MCNP and ISAP each have their
  own coordinator now: `Coordinator MCNP` (#261) and `Coordinator ISAP` (#260), each scoped by
  `users.department`. They do NOT share a login — a shared one cannot be scoped, and "who
  confirmed this batch" would stop meaning anything the moment two people used it. Verified:
  ISAP sees 34 groups, MCNP 21, and 34 + 21 = 55 = the Director unscoped view; each refuses a
  write against the other school's student.

  The old shared `coordinator_node` (#13) was deleted. It had signed four batches, and
  `defense_batches.coord_by` / `returned_by` carry no foreign key, so those ids outlived the
  row and the `LEFT JOIN users` came back empty — a confirmed step would have rendered with a
  blank name, reading as though it had never been signed. `fp_chain_queue()` and
  `fp_get_batch()` now COALESCE every signer to **'Removed account'** when the id is set but
  the user is gone, which is the honest reading: the step was signed, by someone no longer here.
- **A requirement now records who decided it (2026-09-19).** `uploads.verified_by` /
  `verified_at` (migration `2026-09-19c`). A verdict previously left no trace of its author:
  `verification_status` says what was decided, and the `activity_logs` row beside it is addressed
  to the STUDENT (`user_id` is the student, so the group sees it in their feed), so the reviewer's
  identity was stored nowhere. With three roles able to clear a requirement — Focal Person,
  Coordinator, Director — "Approved" on its own stopped saying enough.

  Both write paths stamp it: `fp_write_review()` and `applyReviewDecision()`, including the rows
  the item-14 cascade creates, so the three bundled requirements are attributed to whoever
  approved the Capsule. Cards show **"Approved by Ms. Cruz · FOCAL PERSON · Sep 19"**. Nullable
  on purpose — every row predating this reads "approved before this was recorded" rather than
  being attributed to someone who did not decide it. (The plagiarism and payment desks keep their
  own audit trail and were left alone.)

### Added
- **A Coordinator is scoped to one school (2026-09-21).** MCNP and ISAP run one coordinator
  each, so the role could not stay unscoped: a single coordinator account saw all 55 groups
  across both institutions, and a shared login would have made "who confirmed this" meaningless.
  They are **separate accounts**, scoped by `users.department` — the column already held the
  school, so no migration was needed.

  Scope is now role-shaped rather than one flat rule: `Research Coordinator` → one SCHOOL,
  `Focal Person` → a list of COURSES. `aa_scope_sql()` and `aa_may_touch_student()` branch on
  that, and `fp_school_scope()` is the single place that decides which roles are school-bound —
  used by the approvals queues, the badges, the live poller and the Scheduling screen so they
  cannot disagree. The opened bundle is guarded separately: scoping a list is not the same as
  protecting an item, so a pasted `?batch=` from the other institution is refused outright.

  Both shapes **fail closed**. A coordinator with no school set sees nothing, exactly as a focal
  person with no courses does, and the Staff Accounts screen flags her in red rather than letting
  it be discovered later. Creating a Coordinator now requires picking her school, and an existing
  one can be changed from her card.

  Verified: MCNP coordinator sees 21 groups, ISAP sees 34, and the two add up to the Director's
  55 — neither can read or write across the line.

### Removed
- **The Form 008 22-point rubric (2026-09-21).** Item 14 (Complete Capsule Proposal) opened a
  22-question YES/NO rubric that scored the paper and derived a verdict from the total. That is
  not what this stage is for any more — the office confirms the right file was sent — so item 14
  is approved or sent back exactly like Assigned Adviser. Removed: the 22-question array, the
  rubric box, `runForm008Tally()`, and the score line in the student's notification. The
  **cascade stays** (approving 14 still clears 13/15/16) and Form 011 (stats, item 3) keeps its
  own rubric untouched.

  The `form_008_*` columns are deliberately NOT dropped, and a live bug surfaced on the way: the
  single-review path passed its `rubric` key unconditionally, so every save wrote
  NULL / 0 / 'Deferred' over those columns — re-approving item 14 silently erased a stored score.
  The key is now sent only when a rubric was actually filled in, which is what
  `applyReviewDecision()` always documented. Verified: a historical score of 19 survives an
  approval.

  `$ws_rubric_items` is left as a single empty-array switch. Every branch reading it — the
  per-card rubric notes, `$rubric_pending`, the `all+rubric` bulk mode, the `open_form008` flag —
  is now unreachable and labelled as such at the declaration, rather than being torn out of a
  dozen separate markup sites.

### Changed
- **Approvals filters by tab instead of stat tiles (2026-09-21).** Four cards across the top
  showed only a number and still left you scrolling to find what they counted. The same four
  numbers now sit ON the filter — Waiting for you / With someone else / Sent back / Published —
  using `.status-filter-tab`, the control the Checklist already uses, so the admin screens
  behave alike. It lands on your own queue even when that is empty, the way the Checklist
  lands on Action Needed, because an empty inbox is information.

- **The Secretary logs on the Scheduling screen, not in Approvals (2026-09-19).** "Log the slots
  and publish the date" both lived in Approvals and fired in one click, which made the last and
  most consequential step a rubber stamp in the wrong room: logging a day means reading the
  calendar beside it, and the calendar is on the Scheduling screen.

  Approvals now **hands off** — the Secretary's step shows the request and a link to
  `?view=schedule&batch=<id>`, and carries no publish button at all, so the decision lives in
  exactly one place. Scheduling gained a **"Sections to log"** block above the calendar, in
  two levels that match how the work arrives. Level one is a **section** card that leads with
  the room — *BS Computer Engineering / 4th Year · A* — because that is its identity; the date
  is a stat beneath it, next to the group count, followed by both signatures with names:
  *Checked by Focal Person · Maricris Ganal*, *Confirmed by Coordinator · coordinator_node*.
  Opening it drops to level two: the **group** cards.

  Level one is a **card of the same shape as the group card** — same day box, same body, same
  footer — so a section and a group read as the same kind of object; only the contents differ,
  a room instead of a team. The two signatures sit in a green verified panel with badge-check
  icons, the NAME leading and the role as its caption, because the name is what the Secretary
  is relying on. Level two closes with a single action bar spanning the cards, since the
  decision belongs to the section rather than to whichever card happens to be last.

  Those group cards are the existing request card, not a new one: it was extracted verbatim
  into `components/ds_request_card.php` and is now included by both the legacy queue and this
  screen, so the day box, avatar, School ID chip, "View names" roster and requirement list
  stay identical and there is no second copy to drift. In bundle mode it also carries the
  group's slot and a tick. Untick one and it is **released** — booking cancelled, slot handed
  back to the pool, the group told plainly why, free for a later round.

  The partial deliberately stops before `.ds-rc-foot` and therefore leaves two divs open, the
  legacy queue and this screen closing them after their own (different) footers. Getting that
  count wrong collapses the whole page grid, which is exactly what happened first time.

  `keep_mode` is a hidden marker on that form, and it is load-bearing: an unchecked checkbox
  posts **nothing**, so "she unticked every group" and "no selection was sent" arrive as the same
  empty request. Without it, unticking everything silently published everything — the exact
  opposite of the intent. Caught by the test, not by reading.

### Fixed
- **The old Scheduling screen could bypass the whole approval chain (2026-09-19).** It listed
  every booking at `Date Selected` as a pending request with **Schedule** and **Reject** buttons
  — and that is precisely the status a Focal Person's endorsement writes. Anyone on that screen
  could publish or kill one group's date without the Coordinator's or Secretary's signature, with
  the batch left half-applied behind it. Chain-managed bookings (`batch_id IS NOT NULL`) are now
  filtered out of that queue **and** refused by `defense_handler.php`, with a message pointing at
  Schedule Approvals. The screen keeps what it uniquely owns: the calendar, blackouts, holiday
  import, and the logged record.

- **One signature each, and the rest of the chain is visible but locked (2026-09-19).** The
  Director held step 2 as well as step 3, so her own screen offered "Confirm &mdash; send to the
  Secretary" when she *is* the Secretary: a two-signature chain with one person holding both
  signatures. `fp_can_act()` now gives Coordinator step 2 and Director (as Secretary) step 3, and
  nothing else. This is the one place Coordinator and Director are deliberately not
  interchangeable; it is enforced in `focal_handler.php`, not only in the UI.

  The queue also used to hide the steps you could not act on, which made the chain impossible to
  follow — you could not tell whether a section was still with the Coordinator or already on the
  Secretary's desk. Every live bundle is listed now; the ones that are not yours render **locked**
  (dashed, muted, "with the Secretary") and still open read-only, with a panel naming whose move
  it is and when it reaches you. The stat tiles changed from per-role queues to
  **Waiting For You / With Someone Else / Sent Back / Published**.

### Added
- **Approvals moved into each phase, with its own badge (2026-09-19).** It sat alone under
  Overview, above everything, which put it outside the workflow it belongs to. Each phase now
  reads in the office's real order of work: **Approvals -> Schedule -> Checklist** (confirm the
  section, log the slots, then review documents), with the nav steps renumbered to match and
  Final gaining the same entry.

  Sitting under a phase heading, the screen had to become phase-aware: `admin_batch_review.php`
  takes `?phase=`, `fp_chain_queue()` and `fp_approvals_badge()` take an optional phase, and the
  badge splits into `approvals_proposal` / `approvals_final`. Each counts only the step that
  role owns — a Coordinator sees 'Endorsed' and stops seeing it the moment she confirms; the
  Director, as Secretary, sees 'Confirmed'. Both are served by `nav_badge_counts.php`, so they
  refresh on the same 20-second poll instead of going stale until a reload.

### Fixed
- **Item 12 was missing from four more requirement lists (2026-09-19).** Making the endorsement a
  proposal requirement meant every hardcoded `(11,13,14,15,16)` was now wrong. Fixed in
  `analytics.php` and `group_profile_data.php` (phase health / group progress would have been
  computed out of five), and in the badge queries in `director.php`, `coordinator.php` and
  `nav_badge_counts.php`. Rendered badges and the live poller verified in sync afterwards.
- **A second empty `IN ()` in the same file (2026-09-19).** The guard added earlier covered the
  document query but not the `checklist_items` name lookup below it, so
  `admin_module_dynamic.php?phase=proposal&view=schedule` still died. Both are guarded now, and a
  sweep of every `IN ()` built from a PHP array across `dashboards/` confirmed the rest are either
  guarded or provably non-empty.
- **Removed 116 lines of unreachable code (2026-09-19).** `select_date` was retired by refusing it
  at the top and renaming the old body to `__retired_select_date`, which left a dead branch that
  still called the now-empty `df_application_items('proposal')` — exactly the dead-vs-live trap
  CLAUDE.md warns about. The refusal stays; the body is gone.
- **An unterminated script killed the proposal card's filters and scroller (2026-09-19).** The
  `.items-scroll-nav` `forEach` callback in `module_proposal.php` was never closed, so the whole
  inline `<script>` failed to parse and **nothing in it ran** — not just the left/right arrows,
  but the "All / Needs you / Cleared" status tabs and the first-card margin fix that share the
  block. A single `});` restores all of it. Verified in a browser: the tabs now filter to 6 / 0 /
  5 matching their own badges, and the arrows call `scrollBy({left: 304, behavior: 'smooth'})` as
  intended. (PHP lint cannot catch this — the file is valid PHP; the break is inside the emitted
  JavaScript.)
- **Empty `IN ()` crashed the student's proposal page (2026-09-19).** Making proposal's phase-2
  list empty (the endorsement moving into the requirement grid) fed `IN ()` into two queries,
  which is a MySQL syntax error, not an empty result — `df_stage_bundle()` threw and took the
  whole proposal module down. Both sites now skip the block instead: `defense_lib.php` and
  `admin_defense_schedule.php`. `defense_handler.php` already guarded its two.
- **Re-endorsing a section after a withdrawal failed (2026-09-19).** `defense_bookings` carries a
  `uniq_group_phase` unique key — exactly ONE row per (group, phase) for all time — and
  `endorse_batch` did a plain `INSERT`. A group that had been endorsed and then withdrawn still
  owned its row, so the second attempt collided and the whole batch was refused. It now upserts:
  live rows are still rejected up front, and anything left over is reused.
- **A section could only ever be scheduled once (2026-09-19).** The "already has a date" check
  treated a *published* batch as blocking, which stranded every group that was not ready the
  first time — and sections are scheduled in rounds, whoever is ready on the day. Only a request
  still in the chain blocks now (`FP_PENDING_BATCH_STATUSES`); a published one is shown for
  context while the remaining groups can be put on a later day.
- **An empty batch wedged its section permanently (2026-09-19).** A batch with no bookings left
  still counted as live, so the section could never be endorsed again and the bundle showed in
  the queue as "0 groups". Batches with no bookings are now ignored by both
  `fp_section_batch()` and `fp_chain_queue()`.
- **A section label repeats across year levels (2026-09-19).** `uniq_section_label` was
  `(department, program, label)`, so a course that already had a 4th Year "A" refused a 3rd Year
  "A" as a duplicate — offices name the rooms "A"/"B" and let the YEAR tell them apart. The key is
  now `(department, program, year_level, label)`, and `year_level` became `NOT NULL DEFAULT ''`
  because MySQL permits repeated NULLs inside a UNIQUE index, which would have left the same hole
  open for year-less sections. Section names are rendered through `fp_section_name()`
  ("4th Year · A") wherever two year levels can appear together — "A" alone is ambiguous.
- **Sections are pre-seeded; creating rooms is not the focal person's job (2026-09-19).** The
  picker only appeared when a course already had sections, which was 2 of 21 — so for almost
  every student it was invisible and their group still landed in the tray to be filed by hand.
  `database/seed_sections.php` now creates rooms A&ndash;E for every year level of every course in
  the `auth/register.php` catalogue (630 sections; it reads that catalogue rather than keeping a
  second copy, and `uniq_section_label` makes re-running a no-op, so it is safe after adding a
  course). Sections that already had groups were untouched.

  Pre-seeding means most rooms are empty most of the time, so the focal dashboard now shows only
  the sections that **have groups** and folds the rest behind "N empty rooms in this course" —
  without that, each course card would open with 30 empty tiles.
- **The section picker let students skip straight into the manual tray (2026-09-19).** It listed
  bare labels ("A", "B"), which stop being unique the moment a course has both a 3rd Year A and a
  4th Year A — a student could not tell which was theirs, picked wrong, and the focal person had
  to move them anyway. It was also optional, so it could simply be skipped. Now: the field sits
  **after** Year Level and is filtered to the year chosen (sections with no year always stay on
  offer), each option is labelled "4th Year — A", and it is **required whenever that course has
  sections** — enforced in `register.php` as well as marked in the form, because a marked-up form
  is not an enforced rule. A course with no sections yet still registers freely and lands in the
  tray, so a course whose focal person has not set up rooms can never block a signup.
- **Section is typed, not picked (2026-09-19).** The dropdown had to be pre-seeded, and courses
  like Nursing run sections past E — covering every letter for every course and year would have
  meant a 26-item menu for a one-letter answer, and a course that invented a new room would still
  be stuck. It is now a one-character input showing **A** as the example.

  The dropdown existed to stop one room arriving as A / a / Section A / 4A and shattering into
  four sections; the format does that instead — exactly one letter, uppercased on the way in,
  anything else refused in the browser **and** in PHP. The section is then found-or-created, so
  the first student to type D makes room D for that course and year and everyone after joins it.
  A year level is now required alongside it, because "A" without a year is ambiguous.

- **The proposal application stage is gone; the endorsement is the sixth requirement
  (2026-09-19).** Item 12 (Endorsement From the Adviser) used to sit outside the requirement grid
  as an "application": clearing the five earned it, and re-uploading it signed was how a group
  applied for a date. Students no longer apply — the Focal Person schedules the section — so that
  stage had nothing left to do except be an extra step, and the endorsement still surfaced
  separately on the office screen.

  It is now an ordinary requirement, uploaded and reviewed in the same grid as the other five,
  sitting SECOND — right after Assigned Adviser, since both are the adviser's paperwork and the
  office reads them as a pair. The student's proposal card reads
  **6** instead of 5, `df_phase1_items('proposal')` is the full six, and
  `df_application_items('proposal')` / `df_phase2_items('proposal')` are empty — proposal's
  schedule panel now collects nothing and is purely a status display. Uploading item 12 no longer
  opens a `defense_bookings` row, since the focal person's endorsement creates it. Final is
  untouched: Form 002 is genuinely a separate thing, reviewed before its own requirements.

  `fp_ready_items()` collapsed back to `df_phase1_items()` — the two only diverged while item 12
  was an application document.

- **Form 008's bundled requirements are shown as bundled (2026-09-19).** Items 13, 15 and 16 are
  cleared by the cascade when the Capsule Proposal is approved, but they were listed as three
  peers each with their own Approve button — buttons the cascade would press anyway. They are now
  nested under the Capsule as "cleared automatically when this is approved", with their status and
  no actions of their own.

- **Trimmed the Coordinator's bundle view (2026-09-19).** Removed the list of groups the Focal
  Person left out and the whole-school day meter. Neither is part of the decision in front of
  them — the left-out list only invited "why not them?", which is hers to answer, and the day
  meter is capacity planning. `fp_batch_coverage()` went with them rather than being left dead.

- **Schedule requests arrive as openable bundles (2026-09-19).** The Coordinator/Secretary queue
  listed every request fully expanded, which buried the decision. It is now cards — course,
  section, date, group count, who endorsed it — that open into the full request.

  The opened view answers what the office actually asks before committing a room and a panel:
  a three-step chain strip showing whose move it is; **"putting 3 of the 8 groups in 4th Year · A
  on this day"**, naming how many of the ones left out had already cleared their requirements and
  could have been included; each group in slot order with its own requirement count and a link to
  its files; and a meter for that whole day across the school — this section, other courses, and
  what is free — because the 15 slots are one shared pool. The Secretary's step is labelled for
  what it is: **log the slots and publish the date.**

- **Focal review workspace: read the file in place, and clear a stack at once (2026-09-19).**
  Reviewing opened files in a new tab and decided them one at a time, which is not how anyone
  reviews twenty groups. The group list is now **cards** with a progress bar and a colour tint
  (needs-you, complete, waiting on them) instead of flat rows, and a group's documents sit in a
  **split view**: the requirement list on the left, the file itself rendered on the right.
  Office formats go through Microsoft's web viewer exactly as `openDocumentModal()` does, with a
  Download beside it — that viewer fetches the URL itself, so on localhost Download is the
  working route.

  **Select-and-approve**, like the office screen: tick several requirements (or "select all
  awaiting review") and clear them in one action. Approve only — a send-back needs its own
  reason per document, and one shared remark across a batch tells the student nothing. The batch
  is all-or-nothing, so a rejected entry cannot leave half of it applied.

  Confirmed with the user: **no Form 008 rubric here.** Her screen is file-viewing plus a
  decision; the 22-point tally stays the office's.

- **The Focal Person now reviews on her own screen, and `focal.php` has real navigation
  (2026-09-19).** Two things were wrong. Her dashboard could only schedule — the reviewing half of
  her job was missing — and the first attempt at fixing that pointed her at
  `admin_module_dynamic.php`, which would have put her and the Secretary on one surface with
  different powers. **A Focal Person is no longer admitted to that screen at all**; the phase gate
  and course-filter narrowing added for her there are removed as unreachable, and the two scope
  hooks that remain are labelled as the inert safety net they now are, so nobody later mistakes
  them for live behaviour.

  `focal.php` is rebuilt around a two-tab nav — **Requirements** (with a count of what is waiting)
  and **Schedule** — each drilling the same way: `courses -> sections -> groups`, Requirements
  going one level further into a group's documents. A breadcrumb walks back up. Reviewing shows
  each requirement, its file, who sent it and when, with **Approve** / **Send back** (remarks
  required on a send-back). The write mirrors `applyReviewDecision()` exactly — same
  `verification_status` strings, the same item-14 cascade onto 13/15/16, the same `activity_logs`
  verdict row — so a requirement she clears is indistinguishable from one the office cleared.

  Empty sections are simply **not listed** anywhere in her portal. Pre-seeding gives every course
  30 rooms; the previous build folded the empty ones behind a disclosure, which was just hiding a
  navigation problem. A room with no groups holds no work for her, and it only exists so the
  registration dropdown has something to offer.

### Added
- **Sections, and the Focal Person schedule chain (2026-09-19).** The office's real structure is
  `course -> section -> group`, and the Focal Person owns it end to end: she checks the
  requirements of her courses, then consolidates a whole section onto one defense date.

  **Sections are a table, not a column.** A section is a room ("BSIT 4-A"). If students typed it
  as free text it would arrive as A / a / Section A / 4A / IV-A and one section card would
  fragment into six, so the office defines the list and students pick from it (`sections` +
  `users.section_id`, inherited from the group leader like `research_group_name`/`department`/
  `program`). Groups with no section — every group that predates this, plus transferees and late
  registrants — land in the focal person's **Unassigned tray**, which also fills in the year level
  from the section. One tray, and no migration script.

  **The chain hangs on the batch, not on each requirement.** `defense_batches` = one section's
  groups on one date, travelling focal -> coordinator -> secretary. Per-requirement sign-off would
  have tapped a coordinator 5 requirements x 8 groups = 40 times for a single room; a packet taps
  once. Requirement approval is therefore **unchanged** — a Focal Person's Approve writes
  `Approved` and the student goes green immediately. Only the DATE waits for all three signatures,
  because a defense books a room and a panel and must not be planned around unsigned. Students see
  "proposed" until the Secretary signs. The per-group rows stay in `defense_bookings` (they already
  carry `slot_no`), now joined by `batch_id` — no third table, no new booking statuses.

  **Students no longer pick dates** (`components/defense_scheduler.php` calendar removed,
  `select_date` refused server-side — removing a form is not the same as closing an endpoint).
  What replaces it is an honest status: waiting / proposed / confirmed.

  Two capacity bugs the shared 15-slot pool would otherwise have caused, now that 21 focal persons
  book into it: `df_next_slot()` counted only `Scheduled`, so batches mid-chain were handed
  duplicate slot numbers — it now counts held slots too; and `endorse_batch` counts capacity inside
  a transaction with `FOR UPDATE`, so two focal persons endorsing the same day cannot both read
  "5 free" and overfill it.

  Scope is enforced twice: `aa_scope_sql()` hides out-of-scope groups from every listing, and
  `aa_may_touch_student()` refuses a write against one — a hidden row is not a protected row, and
  `applyReviewDecision()` is the single funnel every review decision passes through.

- **Focal Person role + staff account management (2026-09-19).** New role `Focal Person` on
  `users.role`, and `dashboards/admin_accounts.php` — the first screen in the system that can create
  a staff account. Until now the only `INSERT INTO users` was the public student registration, so
  every non-student account had to be made by hand in the database.

  A Focal Person checks the requirements of the courses assigned to her and sees nothing outside
  them. Her scope is a **list** of courses in the new `focal_scope` table, not a single one: some
  focal persons cover several courses because MCNP/ISAP group courses into departments. Rows also
  cover the cases a single column could not — a co-focal sharing a course, or a reassignment — and
  they avoid inventing a department level, which `users.department` cannot express because that
  column already holds the *school* (MCNP vs ISAP).

  `aa_scope_sql()` in `dashboards/admin_accounts_lib.php` is the one place a group query gets
  narrowed by role. Unscoped roles return the literal `1` so callers can splice it in
  unconditionally; a Focal Person with no courses yet returns `0` and matches **nothing** — a scoped
  role fails closed, never open.

  Director-only. The screen counts how many courses have no Focal Person and lists them, since that
  is the rollout to-do. There is deliberately **no delete action**: disabling is `is_verified = 0`,
  the same switch `settings.php` already uses, which is reversible and never orphans the uploads and
  approvals pointing at a user row. Passwords are set by the Director and shown on screen — never
  emailed. Submitted course scopes are whitelisted against courses that actually have research
  groups, so a tampered form cannot mint a scope row.

- **System architecture diagram (2026-09-15).** `docs/diagrams/architecture.php` generates
  `fig_system_architecture.svg`: client, web server, data and external tiers, with the flows between
  them. It replaces a concept sketch whose student path ran `manuscript -> check using AI -> generate
  report`; no AI or similarity-scoring service takes part in this system, and the figure says so.

  Three outbound integrations the old sketch never showed are now on the page: Gmail SMTP through
  PHPMailer, the asset CDNs the browser pulls from, and `date.nager.at` — which is read **twice**,
  by the calendar client-side and by `defense_handler.php` server-side when the office bulk-closes
  holidays. The two stores are also drawn separately: documents on disk under `uploads/`, and the
  database row that points at them.

- **Manuscript data dictionary (2026-09-15).** `docs/diagrams/data_dictionary.php` generates
  `data_dictionary.html`: 11 tables, 111 fields, each with field name, data type, key, nullability and
  a written description. Same entities as `fig5_erd_paper.svg`.

  Types, sizes, keys and nullability are read from `information_schema` at generation time, so the
  document cannot drift from the schema — only the prose lives in the script. A column added to any
  covered table is reported as MISSING on the next run instead of emitting a blank cell. Includes a
  print stylesheet (repeating table headers, no page breaks inside a table).

- **Paper-ready ERD (2026-09-14).** `docs/diagrams/erd.php` now emits a second figure,
  `fig5_erd_paper.svg`: 11 core entities, principal attributes, uniform crow's-foot lines, no row
  counts. A thesis figure shows the logical design, so archive mirrors, OTP and messaging tables are
  left out and the enforced-vs-convention line weight of `fig4_erd.svg` is dropped — that distinction
  is an implementation detail. Both figures come from one generator via the `$SHOW_COUNTS` and
  `$UNIFORM` flags.

  Elbow waypoints are now derived from their anchors through `vv()` / `hh()` rather than typed as
  literals. Hand-typed coordinates that disagreed with their anchor by a few pixels had drawn six
  relationships as diagonals across the first render.

### Removed
- **Dropped `research_group_members` (2026-09-14).** A free-text roster table from before group
  membership moved onto `users.leader_id`. It had **zero rows and zero writers** — the 2026-07-08
  `group_invites` migration had already recorded it as superseded and stopped writing to it. The one
  remaining read, in `group_profile_data.php`, therefore returned an empty list on every call.

  That endpoint still emits `roster` in its JSON, now as a literal `[]`, so the response shape is
  byte-identical to what callers already received. If the group profile should ever list real member
  names, `group_roster_lib.php` is where they live. A full `mysqldump` was taken to the gitignored
  `backups/` first. Table count: 32 to 31.

### Added
- **Process flowcharts for the paper (2026-09-14).** `docs/diagrams/` now holds three figures in the
  plain black-on-white flowchart language the manuscript uses — `fig1_system_flowchart` (both user
  types end to end), `fig2_student_lifecycle` (five proposal requirements through to Turnitin, with
  every unlock gate) and `fig3_staff_review` (what one approve/revise decision writes). Each ships as
  SVG for the document and a 2× PNG for slides.

  They are **generated**, not drawn: `docs/diagrams/flowcharts.php` declares named nodes and connects
  edges by node name and side, so `php docs/diagrams/flowcharts.php` redraws all three after a
  workflow change and an arrow can never end up pointing at empty canvas. They replace the original
  concept diagram's AI-analysis / similarity-threshold path, which has not existed since AI Pre-Score
  was removed on 2026-07-10.

- **ERD redrawn at the live 19-entity shape (2026-09-14).** `docs/diagrams/erd.php` generates
  `fig4_erd.svg` — the tables that actually carry working data, grouped into six clusters
  (identity, catalog/submissions, scheduling, statistics, plagiarism, messaging). Two things were
  making the old diagram unreadable and both are handled by leaving them out, not by changing the
  schema: the nine `*_archive` mirrors (exact copies of their parents, written only by
  `reset_handler.php`) and the four tables nothing live reads — `notifications`, `approvals`,
  `form_008_reviews`, `research_group_members`. Both exclusions are stated in the figure legend.

  Line weight carries information: a solid edge is a foreign key the schema enforces, a dashed edge
  is a reference only the PHP maintains. Most of the app-level joins around `users` turn out to be
  the dashed kind. **No schema change was made — this is a drawing.**

### Fixed
- **The queue number moved when it should not have (2026-08-30).** The "19th / 1st" placing on the
  Checklist group cards is not a counter and not a ticket — it is derived, on every render, from the
  group's own earliest upload in that phase. Two things were undermining that:
  - **Being reviewed pushed a group backwards.** It ranked on `first_cleared` when a group had one
    and only fell back to `first_upload` otherwise. A first approval is by definition *later* than a
    first upload, so the moment somebody attended to a group it dropped behind every group nobody
    had touched. It now ranks on `first_upload` alone; `first_cleared` stays on the card as a stamp.
  - **Searching renumbered everyone.** The rank was computed over the filtered page, so whoever you
    searched for always came back "1st". It is now computed over every group in the phase, and the
    tooltip says so (`19th of 52`).

  Nothing resets and nothing rolls over: because the key is a `MIN()`, a group that submits half its
  files today and the rest tomorrow **keeps the place today earned it** — which is exactly what a
  nightly reset would destroy. The number counts up with the cohort by design; it is a position in a
  standing line, not a daily ticket.
- Guarded the group-inbox timestamp queries against an empty group set (`IN ()` was a SQL error on a
  filter that matched nothing).

### Changed
- **Student header is now the iSubmit brand bar, and the greeting is gone (2026-08-30).** The header
  was doing two jobs and doing both badly — on desktop it showed the research group name as an
  `<h1>`, on phones that same slot was swapped for a greeting, so the two breakpoints put different
  things in the same place. Both now show one row: the mascot in a rounded app-icon tile, `iSubmit`,
  and *Research Digitalization Platform* on the left; the existing weather / bell / theme-toggle
  cluster, untouched, on the right. In dark mode the tile keeps a light ground so the mascot's
  near-black cap does not dissolve into the surface.

  The *Good morning, {name} 👋* greeting and its tagline are **removed at every breakpoint**, and
  with them `#headerIdentityPop` (the greeting's chevron was its only trigger), `refreshLiveGreeting()`
  and its 5-minute interval, `toggleHeaderIdentity()`/`closeHeaderIdentity()` and their popstate,
  resize, ESC and outside-click listeners, the `.hg-*` / `.hip-*` / `.header-title-container` rules
  across four media queries, and the `$first_name` / `$greeting_hour` / `$greeting` PHP.

  The mascot has **no tile** — it sits directly on the page at 58px (64 ≥1200px, 50 on tablet, 46 on
  phones), roughly the height of the wordmark stack. The lift comes from `drop-shadow`, not
  `box-shadow`: it follows the PNG's alpha silhouette, so the penguin reads as floating rather than
  as a card. A 4.5s bob completes it, off under `prefers-reduced-motion`. Dark mode adds a soft
  accent halo — the graduation cap is near-black and would otherwise dissolve into the canvas.

  The research group identity is **not** in the header any more. `$group_name` appeared exactly once
  on this page (inside that popover), and it now belongs to `profile.php`, which already carried the
  editable **Research Group Name** and **Institution / Department**. The one piece it was missing —
  **Program** — is added there as a read-only field, so the full identity reads in one place.
- **Student dock: lighter glass, and the selection colours the glyph, not the button (2026-08-30).**
  The dock was a near-solid white slab (94% opaque, a double drop shadow) and on phones the selected
  tab was a filled purple pill with a white icon — the heaviest element on a screen whose job is the
  cards above it. Now:
  - The container is real glass — 76% opacity over a 30px blur, a hairline border, one soft shadow
    plus an inset top highlight — so it floats instead of sitting on the page.
  - Selection is a **neutral gray tray** (`--dock-tray` / `--dock-tray-hover`, defined per theme)
    with the accent applied **only to the icon**, at a slightly heavier stroke. Same treatment at
    every breakpoint; the mobile pill still widens (66px) but no longer floods with accent.

  Presentation only — `selectDockItem()`, the `data-view` contract and every dock handler are
  untouched.
- **Defense Scheduling: the screen now spends its space on what is undecided (2026-08-30).**
  - **Requested cards are square and tile.** Each request was a full-width card whose only subject
    — the requested date — was a small chip halfway down it. The date is now the card's head, and
    the cards sit on an `auto-fill` grid like the checklist ones, so three requests are three cards
    across rather than three screens down.
  - **"Logged & recorded" folded into a pill bar.** The record is read occasionally and worked from
    never, so it no longer holds a standing card: one line of `All / Upcoming / Past` pills, each
    with its count, that unfolds the list when pressed and folds it away when pressed again.
    "Search all" still opens the searchable modal.
  - **Upcoming is live, not a preview.** Every row opens the booking, a **View all** button lists
    all of them, and the card says how many more there are. It now reads the forward half of the
    record the page already loads, so the rows carry real booking detail — the reason nothing on
    that card used to be clickable. `df_upcoming()` was its only consumer and is removed rather than
    left as a second, subtly different answer to "what is coming up".
  - **The booking detail window is a record card.** It was six unlabelled key/value rows; it now
    leads with the date and how far off it is, lays the facts out as a labelled grid, lists the
    members and proponents, and opens the application document in the in-app preview instead of
    throwing staff into a new tab.
  - The office calendar and the planner are untouched.
- **Nav split: Final Defense and Plagiarism are two sections, not one run-on list (2026-08-30).**
  Everything after the proposal sat under one **Final Defense** heading — schedule, checklist, and
  then four plagiarism desks — so two different phases read as one seven-item list.

  | | Before | After |
  |---|---|---|
  | Final Defense | Schedule · Checklist · Release · Manuscript · Payment · Cleared · History | Schedule · Checklist · **Notify** |
  | Plagiarism | *(no section)* | Manuscript · Payment · Cleared |

  - **"Release" is now "Notify".** It is the office telling a group its defense is recorded and it
    may send the manuscript; "release" read like releasing a document. It stays under Final Defense
    — it is that phase's last act, not the plagiarism run's first. (The Statistician shell has no
    Final Defense section, so there it opens Plagiarism instead.)
  - **The History nav button is gone, and the log is not.** It only ever answered a payment
    question, so it moved to where that question is asked: **Payment &rarr; Approved archive**.
    Old `?phase=plag&view=history` links redirect there rather than falling back to Manuscript.
    Its five summary counters are dropped on the Manuscript side — furniture above a list the
    office opened to look up one group. Statistics keeps its own History tab and its widgets.
- **Every Manuscript desk now has the same two pills** (`arch=1`), so the pattern is learnable
  instead of being a Cleared-only trick:

  | Desk | Awaiting | Archive |
  |---|---|---|
  | Notify | not yet told | **Notified** — who, when, by whom |
  | Payment | at the finance office | **Approved archive** — the decision log |
  | Cleared | waiting on a verdict | **Approved archive** — finished manuscripts |

  Notify's two lists were the pressing one: the list only grows, so the office was scrolling past
  everyone it had already notified to find the one it had not.

### Added
- **"View names" on every group card (2026-08-30).** A card is filed under the group *leader*, but
  the office schedules, clears and signs for a whole group. The roster pill that only the Plagiarism
  queue had now sits next to the School ID on the Checklist group tiles, the group workspace header,
  and the Defense Scheduling request cards, and is spelled out in full inside the booking detail
  window. `ws_group_rosters()` moved to a new `dashboards/group_roster_lib.php` alongside a
  `ws_names_markup()` builder, so the four surfaces render one component instead of four copies —
  the scheduling screen is included and exits long before `admin_module_dynamic.php` defines its own
  helpers, which is why a shared file was needed.
- **Manuscript desk: badges, history, queue order, roster and archive (2026-08-30):** the Manuscript
  nav had one badge for four desks, no audit trail, and no defined order to work its queues in.

  - **All four desks now carry a live badge** — Release, Manuscript, Payment, Cleared. Payment had
    none at all before, which is what prompted this. Counted off `plagiarism_checks.status`, not
    `uploads.verification_status`: since `plag_receive` exists an "Approved" manuscript means
    *received, go pay*, which is three desks away from being anyone's queue.
  - **`dashboards/nav_badge_lib.php` (new)** holds that count once. The three staff shells and the
    polled endpoint each carried their own copy of the SQL, and they had already drifted —
    Director counted `Pending`, Statistician counted `Pending` + `Under Review`, and Coordinator
    folded item 4 into the bulk uploads query with no latest-per-group filter, so a group that
    re-uploaded three times counted three times. Same badge, three numbers depending who logged in.
  - **Manuscript &rarr; History**, a new sub-view mirroring the Statistics one: month/year filter,
    summary widgets, pagination, CSV export. The two screens are now one parameterised block
    (`$hist_table` / `$hist_doc_names` / `$hist_actions`), not a copy.
  - **The trail covers the whole flow, not just payments.** `plag_log()` writes every decision —
    `released`, `received`, `returned`, `accepted`, `rejected`, `cleared`, `revision`, `report` —
    into `plagiarism_payment_logs`, because a re-check months later asks "who signed this off",
    not "what was the till receipt". Unrecognised actions render as themselves rather than
    defaulting to "Rejected", which is how an audit log lies.
  - **Queues have a stated, fair order.** Payment and *Waiting on your verdict* sort by **who paid
    first** (`MAX(uploaded_at)` over items 41/42, then `registered_at`); Manuscript review sorts by
    **who submitted first**. Each card shows its rank, and each screen says what the order means.
  - **Cleared split into two lists behind a toggle**: *Awaiting verdict* (the queue) and *Approved
    archive* (the record). Archived cards lead with cleared date, who signed off, payment date and
    control number, with the decision forms folded behind "Correct this record" — an archive that
    opens with a "send back" button reads like a queue. A congratulations panel replaces the empty
    state when every paid group has been cleared.
  - **"View names"** on each card opens the group roster — leader first, then members, with School
    ID and email. Cards still lead with the leader alone (that is who the uploads are filed under),
    but the office signs off for a whole group and needs the actual names before it does.
- **Milestone emails (2026-08-29):** the portal only ever spoke to students while they were logged
  into it. The moments that matter most happen when they are *not* — the office confirms a date,
  releases them to send a manuscript, or posts a Turnitin result days later. Those now reach them
  by email as well as the bell.

  Six milestones send, and only these — routine review verdicts stay in-app, because a mail per
  document would be eight messages for one Statistics phase:

  | Milestone | Carries |
  |---|---|
  | Proposal defense scheduled | date + slot |
  | Final defense scheduled | date + slot |
  | Released to send the final manuscript | what to do next |
  | Plagiarism payment verified | control number |
  | Plagiarism check passed / revision needed | verdict + the office's note |
  | Statistics payment verified · results released | control number |

  - **`config/mail.php` gained `rd_mail_html()` and `sendGroupEmail()`.** One template so every
    milestone looks alike (fully inline CSS — clients drop `<style>` blocks), and one helper that
    mails the leader **and every member**, since a milestone belongs to the whole group.
  - **One SMTP transaction per milestone, not one per person.** A Gmail handshake measured ~6s, and
    these run inline from a staff click, so looping per member would have left the office watching
    a spinner for half a minute on a four-person group. Members go in BCC, which also stops one bad
    address bouncing the whole message.
  - **Every send is swallowed on failure.** A mail server having a bad day must never stop a
    defense being scheduled.
- **The Manuscript/Plagiarism flow, end to end (2026-08-29):** the card was a single-stage stub —
  upload, staff accepts with a Turnitin report, done. It now runs the full RDC procedure, and the
  scan is **paid for**, exactly like the statistical treatment is:

  ```
  released → upload manuscript → office RECEIVES it (≠ cleared)
           → RDC Form 13 → pay at Finance (outside the system)
           → upload validated form + official receipt
           → office registers → control number revealed, nothing left to upload
           → office runs Turnitin → passed (no file needed) | plagiarism found (report attached)
           → Cleared — ready to bookbind
  ```

  **Accepting a manuscript no longer clears a group.** `plag_receive` means "this is the right
  document" and sends them to the finance office; `plag_return` sends it back. Clearance is now the
  last act, taken on the Cleared screen after they have paid.

  - **A clean pass needs no file.** `plag_accept` previously demanded a Turnitin report on every
    decision — there is nothing to show when a paper passes. The report stays mandatory for
    `plag_revise` (it is the evidence the group has to act on) and `plag_replace`.
  - **Student side**: the 3-step tracker becomes 4 — *Manuscript · Finance Payment · Turnitin Check
    · Cleared* — and the Finance step its own comment once said was "cut — no payment here" is
    back, using the same `.step-alert.gold` panel and download chip Statistics uses. Which document
    the card asks for is driven entirely by the phase, so a finished job and a not-yet-possible one
    are never on screen together. `plag_form.pdf` moved to `dashboards/downloads/`.
  - **Admin side**: Manuscript grows a **Payment** desk (`?phase=plag&view=payments`) modelled on
    the Statistics one — both scans openable, register with one button, or send a single document
    back. Which screen a group appears on is now its **phase**, not `uploads.verification_status`:
    once "Approved" on item 4 means "received, go pay", splitting on it would have filed every
    unpaid group under Cleared.
  - **The control number is typed from the receipt, and it is mandatory.** It is printed on the
    official receipt the group brings back from finance, so the office opens that scan, reads it,
    and types it — exactly the Statistics desk's job. Registering without one is refused. The
    number is built the same way `STAT-…` is (`PLAG-{SCHOOL}-{COURSE}-{typed}`), and registration
    stamps `registered_at` and writes a `plagiarism_payment_logs` row.
    - **The auto-mint at upload is gone.** It generated a `PLAG-…-{seq}` number before the group
      had paid for anything, burned a sequence value per upload, and produced a filename prefix
      that would not match the number the receipt later gave them. Uploads before registration now
      use a neutral `PLAGSUB-{id}` prefix, so the receipt's number is the only one this group ever
      has.
  - **A server-side phase gate** on items 4/41/42 in `upload_handler.php`, plus a hard
    precondition that the group has been released. Until now anyone could POST `item_id=4` and mint
    themselves a control number.
  - **The billboard follows the whole flow.** Each of the eight plagiarism phases has its own promo
    card, so the strip never says "submit your manuscript" to a group that already has.
- **The post-defense release: the office tells a group it may send its final manuscript
  (2026-08-29):** the final defense happens face to face, outside this system, so nothing here can
  know it actually went ahead. Until now the Plagiarism card opened on a guess — `df_defense_done()`,
  i.e. "the date is in the past". It now opens because a human said so.

  **Manuscript → Release** (new `?phase=plag&view=release`, in all three staff shells) lists every
  group that has **cleared both final phases** — a `Scheduled` booking means Form 002 was approved,
  all six phase-2 documents were approved, and the office confirmed the date, so the group holds
  its ticket. One **Notify & unlock** button per group. Released groups stay in the list showing
  who released them and when, so the screen doubles as the record.

  **The defense day is not a filter.** It is shown on every row (`Defended … 3d ago` /
  `Defends … in 3d`), rows already past their date sort first, and releasing early is possible but
  says so before it commits. Gating on the date made the screen empty and unusable: a defense can
  be heard early, moved, or recorded late, and the office is the authority on whether it happened —
  which is the entire reason this control exists. The server-side guard checks *clearance*
  (`df_requirements_unlocked`), not the calendar.

  One flip does three things for the group, from one flag:
  - the **Plagiarism card unlocks** (`$unlock_plag` now reads `plagiarism_checks.released_at`
    instead of the date guess). Before the defense the lock still reads "Opens after your final
    defense"; after it, it reads "Waiting for the research office to open this" — two different
    waits deserve two different explanations.
  - the **dashboard billboard swaps in place** to *"Send your final manuscript"*, deep-linking to
    `zoom-plag`. It replaces the defense card rather than adding a second one, and its branch sits
    above the component's early return.
  - the defense ticket's Good-luck panel grows an **"I'm ready — send my final manuscript"**
    button (`dfGoPlag()`, mirroring the existing `dfGoFinal()`), hidden until the signal — offering
    it earlier would invite a manuscript nobody is ready to receive.

  The action creates the `plagiarism_checks` row (`ON DUPLICATE KEY UPDATE`, since `user_id` is
  unique and a row may survive from an earlier cycle) and writes the student a `success` activity
  log. It deliberately does **not** mint the control number — that still happens on the group's
  first manuscript upload. Re-checks `df_defense_done()` server-side, so a stale page cannot
  release a group that has not defended.
- **Manuscript/Plagiarism schema, ahead of the flow itself (2026-08-29):**
  `database/migrations/2026-08-29_plagiarism_flow.sql` adds a `status` ladder plus
  `released_at`/`released_by`/`registered_at`/`remarks` to `plagiarism_checks`, two finance-office
  documents (**41** Validated Plagiarism Form / **42** Official Receipt (Plagiarism Scan)), and
  `plagiarism_payment_logs` (+ archive). Purely additive and idempotent — nothing reads it yet.
  The state lives on `plagiarism_checks` rather than being derived from `uploads` because
  "clean pass, approved, no report" and "manuscript received, go pay" are byte-identical there.
  Payments get their own log table rather than a discriminator on `payment_logs`, whose six
  unscoped queries would otherwise start mixing plagiarism rows into the Statistics History screen.

- **Queue fairness stamps on the group tiles (2026-08-28):** the tiles sort by workload, which is
  the right order to *work* in but hides who has been waiting longest — and the office needs to be
  able to say why one group was seen before another. Each tile's footer now carries its **queue
  placing** (`1st`…, accented for the first three) and the timestamp that earned it: when the group
  first cleared a requirement in this phase, or how long it has been waiting if nothing has cleared
  yet. The group workspace header repeats both in full. `uploads` has no `verified_at`, so approval
  times come from the `activity_logs` row every decision already writes (`status_type = 'success'`,
  joined on `upload_id`); ranking falls back to first upload so a group with no decisions yet still
  has a defensible position.
- **Proposal Defense is now a real first stage (2026-08-27):** Final defense was built first, so it
  had the mature flow — apply, pick a date, office logs it, ticket. Proposal is what actually comes
  first in the RDC's procedure but was just a flat list of six uploads. It now mirrors Final, and
  the two are wired together:

  ```
  PROPOSAL  5 requirements → office checks → 100%
            reward: proposal_cleared.pdf + endorsement.pdf
            → upload the signed endorsement → pick a date → office logs it → 🎫
                      │
                      ├──────────────► Final + Statistics UNLOCK
                      ▼
            face-to-face defense (outside the system)
            adviser hands over the Minutes
                      └──────────────► uploaded in Final's application (Form 002 + Minutes)
  ```

  The scheduling backend was already phase-aware (`defense_bookings.phase`,
  `uniq_group_phase`, every `df_*` helper taking `$phase`), so most of this was wiring rather than
  building. `admin_module_dynamic.php` now routes `?phase=proposal&view=schedule` to the same
  secretary screen, and both shells gained a **Proposal Sched** nav button with its own badge.

  - **Item 12 is no longer a proposal requirement.** Clearing the five requirements (11, 13, 14,
    15, 16) *earns* the adviser endorsement; the group has it signed and uploads it as their
    defense application. `DEFENSE_APPLICATION_ITEM = 20` became `df_application_items($phase)` —
    proposal `[12]`, final `[20, 22]`.
  - **Item 22 (Minutes of the Proposal Defense) moved** out of Final's locked requirements into
    Final's application, next to Form 002 — it is handed over by the adviser after the proposal
    defense, which is exactly when the final application is made. Final's requirements are now
    23–27. Both `checklist_items` rows stay (uploads cascade on delete).
  - **Phase 2 is an overlay, not a page.** The Schedule pill beside the filter tabs opens it, so
    the requirement cards and their progress stay underneath and Back is always a close. Below
    100% the same panel renders **greyed out and inert** — the numbered steps and a read-only
    calendar — so a group can see what is coming before they qualify.
  - **Card locking.** Final, Statistics and Plagiarism dim, show a lock, and explain themselves
    when tapped instead of opening. Progress deliberately **stays at 100%** once requirements
    clear — the unlock rules read that number, so resetting it would re-lock everything the
    moment a group applied.
  - Legacy groups whose endorsement was uploaded through the old requirement grid have no booking
    row; picking a date opens one from what is already on file rather than dead-ending.

- **Defense slots — 15 a day, shared between proposal and final (2026-08-27):** capacity used to be
  one group per date per phase, implied by an `isset()` rather than any column. The office actually
  hears up to 15 defenses a day out of a single pool, in sequence, with no clock times — so a group
  is given a **slot number**. `df_taken_dates()` now counts across both phases, the calendar shows
  "12 left" instead of open/taken, and the `FOR UPDATE` lock widened to every booking on the date
  (locking one phase's rows would let a proposal and a final group each take the last slot).
  `slot_no` is written once when the office confirms and never recomputed — a cancellation leaves
  the numbers around it alone, because a student may already have downloaded their ticket. The slot
  appears on the ticket, in the student's notification, and in the office's confirmation.
  Migration: `docs/sql/2026-08-defense-slots.sql` (idempotent, backfills existing bookings).

### Fixed
- **System email could never have arrived (2026-08-29):** `config/mail.php` authenticated to Gmail
  as `mcnpisap2025@gmail.com` but set `From:` to a different address — Gmail rejects or silently
  rewrites that, and the inline comment on that very line said *"Ensure this matches your
  Username"*. `From` is now the authenticated account and the human address moved to `Reply-To`.
  Also added: an 8-second timeout (SMTP must not hang a page load), an explicit UTF-8 charset, and
  a plain-text `AltBody`.
- **Two milestone emails were silently doing nothing.** Statistics' "results released" and its
  payment-document notice used PHP's `mail()` behind an `@`, and XAMPP has no MTA — so they failed
  quietly, forever. Results-released now goes through SMTP; the per-document notice was **deleted
  rather than revived**, since it fires on every one of eight documents and was never a milestone.
- **Groups cleared under the old plagiarism flow reappeared in Review (2026-08-29):** their
  `plagiarism_checks` row was created by the control-number mint, which leaves `status` on its
  DEFAULT — so the new ladder filed an already-cleared group back at step one and asked for its
  manuscript again. `docs/sql/2026-08-29_backfill_plagiarism_phases.sql` derives the phase for
  those rows only (manuscript Approved + report Approved → `Phase 5: Cleared`, and so on), scoped
  to rows still sitting on the default so it is idempotent and cannot touch a group the new flow
  has already advanced. Also relevant: the mint used `INSERT IGNORE`, which silently did nothing
  now that the release pre-creates the row — it is `ON DUPLICATE KEY UPDATE … COALESCE` so the
  number is filled in on first use and an issued one is never overwritten.
- **Proposal defenses could not be confirmed (2026-08-29, same day, self-inflicted):** the
  "approve every phase-2 document before logging a date" gate added with the Final re-staging was
  applied to *both* phases via `df_phase2_items()`. For Proposal that array is `[12]`, the adviser
  endorsement — which is **not** a checklist item and never has been, so the office was told
  *"0 of 1 cleared. Approve the rest in Checklist before logging this date"* with no screen
  anywhere on which to approve it. Two real bookings were stuck.

  The gate now reads `df_phase2_reviewed_items()`, which returns `[]` for Proposal and only the six
  for Final. Proposal's endorsement is accepted, as it always was, by the act of confirming the
  date. Both the office screen and the server-side guard use the new list, so the button no longer
  disables itself over a document it cannot offer a decision on.

### Changed
- **The schedule panel drops its own white card while documents are on screen (2026-08-29):** the
  application step rendered its document cards inside `.df-panel`, which is itself a white sheet —
  so a white card sat inside a white card with a visible gutter between them. `.df-bare` removes
  the panel's background, border, shadow and padding whenever the guide column is showing; the
  bead rail and heading stay, and the calendar keeps its frame because `.df-cal` owns one. Applies
  to Proposal's endorsement and Final's six documents alike, both being the same component.
- **The billboard hands over to Final as soon as the group is actually in it.** Precedence was
  "proposal owns the strip until its defense day has passed", so a group whose Form 002 was already
  approved still saw a proposal card while the real work had moved on. Final now takes the strip
  the moment its phase 1 is approved (or its booking moves past submitted), and the `pick` state
  reads **"Book your final defense"** for that phase. A Form 002 that is merely *submitted* does
  not take over — that would pull the proposal ticket off screen before the proposal defense has
  even happened.
- **The Cleared screen shows Paid / Unpaid, not the document status.** Every group on that screen
  has an Approved manuscript, so repeating it said nothing; whether the scan has been paid for is
  the fact the office actually needs. Driven by `plagiarism_checks.registered_at`, with the
  verification date on hover. The other Manuscript screens keep the document status.
- **The "Publish your research" billboard opens once the plagiarism check clears.** It stops
  rendering as a locked door — accent gradient, *Unlocked* eyebrow — while saying plainly that the
  stage is still being built, rather than promising a screen that does not exist yet.
- **The billboard promotes booking the moment a group earns it (2026-08-29):** the strip only
  appeared once a group already had a `defense_bookings` row, so at the single most useful moment —
  requirements cleared, nothing booked — it said nothing at all. It now shows **"Book your proposal
  defense"** / **"Book your final defense"** as soon as that phase's phase 1 is approved, and both
  land in the schedule sheet (`?view=schedule`) rather than the card's front page. Final also gets
  an *Application in review* state while the office is still checking Form 002.
- **A confirmed booking now proves Phase 1 by itself.** `module_final.php` derived `$df_readonly`
  purely from "is Form 002 approved", so anything that removed that upload later — a reset, a
  re-upload mid-flight — dropped a *scheduled* group back into the greyed-out preview and took
  their ticket with it. The office could not have logged the date otherwise, so a `Scheduled`
  booking now satisfies the check on its own.
- **The office's defense queue collapses its documents into one row (2026-08-29):** six file
  buttons per waiting group turned "Waiting for a decision" into a wall. Each group now shows a
  single **Requirements** row with an *N of 6 cleared* badge that expands to a compact list — one
  line per requirement with its own state (Approved / Revision Requested / Pending / Not
  submitted). The rows are **links, not previews**: clicking one opens
  `admin_module_dynamic.php?phase=…&group=…&open_item=…`, which lands on that group's Checklist
  **and pops that exact requirement's review window**. Nothing is decided from the scheduling
  screen — it is about the date. That deep link is the same `group` + `open_item` pair
  `redirectToGroupWorkspace()` already used after a bulk approve; it just gained a second caller.
  The separate "Missing: a, b, c" line is gone, since every requirement now states itself.
- **Final's single requirement is centred on web.** With one card, the `.items-grid` scroller left
  it pinned to the far left with three empty columns beside it (`.items-grid.is-single`).
- **The Schedule sheet gets its own `‹ ›` scroller.** Its document cards are real `.item-card`s, so
  at ≥1025px they become the same horizontal rail the requirement grid uses — but the module's
  prev/next pair drives the module's own grid. The sheet now renders its own pair, and the scroller
  JS binds each `.items-scroll-nav` to the grid it directly follows instead of to the first grid on
  the page. A nav with nothing to scroll hides itself rather than showing two dead buttons.
  (This replaces the earlier stopgap that made the sheet's cards wrap into a static grid.)
- **The Schedule sheet's documents are Apple-Wallet cards again (2026-08-29):** the six Final
  phase-2 documents (and Proposal's endorsement) were rendered as a bespoke `.df-doc-row` list,
  which lost the card language the rest of the portal uses. They are now the **same `.item-card`
  markup and `dashboard-cards.css`** as the requirement grid — numbered `01`…`06`, status-coloured,
  with the description, status pill, numbered how-to, Download Form, Upload File and the submitted
  file card. Mobile gets the wallet stack, web the two-up grid. `df_stage_bundle()` now carries the
  description and the latest file so the card markup has everything it reads.
  - The sheet's grid is marked `df-items`: at ≥1025px `.items-grid` becomes a horizontal scroller
    driven by the module's prev/next buttons, and those bind to the *first* grid on the page — so
    inside the sheet the cards wrap instead. The same class keeps the module's filter tabs from
    reaching into the sheet and hiding its cards.
- **The date picker is the next step again, not a parallel one (2026-08-29):** it was briefly shown
  beside the document cards so a group could plan around availability. In practice that put two
  jobs on screen at once and read as "pick a date now" before anything had been uploaded. It is
  back to appearing only once every document for the stage is in.
- **Final's filter tabs are back.** They were replaced with a plain "Step 1 of 2" text label on the
  grounds that one requirement card is nothing to filter; the row then no longer matched Proposal's
  and read as unfinished. All / Needs you / Cleared return, with the stage pill as their sibling.
- **The defense application document is now reviewable — without becoming a checklist item
  (2026-08-29):** Proposal's endorsement is uploaded in the Schedule sheet, so it had no review
  surface at all: the office could open it from the scheduling screen but could not accept or
  return it. The group workspace now carries a dedicated **Defense application** section above the
  requirement buckets, with the same View / Open &amp; decide / Request revision controls every other
  document has. It is deliberately **excluded from the checklist count** (a 5-requirement group
  still reads "5 of 5 approved") and reviewing it never blocks the booking. Rendered only when the
  application item is not already a requirement of the phase, so Final's Form 002 (item 20, a real
  phase-1 requirement) is not duplicated.
- **A returned application document can now actually be replaced.** `df_stage_bundle()` only knew
  whether a document had *ever* been uploaded, so once submitted the card showed "Submitted" with
  no upload button — a rejection would have stranded the group with no way to send a corrected
  copy. The bundle now carries each document's latest verdict and remarks: a returned document
  shows a red **Sent back** chip and the office's own words, restores its upload button, and
  re-locks the Request button until the replacement is in.
- **Final Defense re-staged to mirror Proposal, reversed (2026-08-29):** Final was built first, back
  when it was the only defense phase, so it ran application-first with a bare inline scheduler and
  its five requirements sat in the wrong stage. It now has two explicit stages:

  ```
  PHASE 1   Application for Research Defense (Form 002) — one requirement
            office approves or rejects  →  the Schedule pill opens
  PHASE 2   the sheet holds BOTH at once:
              · upload Minutes + Approved Comm. Letter + Self-Assessment
                Checklist + Manuscript + Sample DGT + General Stat Tables
              · pick a date on the office calendar
            office clears the six in Checklist, THEN confirms the date
              →  ticket + slot + Form 005 / Form 006
  ```

  **No rows moved.** Which items belong to which stage is decided by two PHP arrays, so items 20 and
  22-27 keep their `item_id`, their uploads and their FKs. `df_application_items('final')` is now
  `[20]` (the upload that opens the booking and owns `application_upload_id`), and a new
  `df_phase2_items()` carries `[22,23,24,25,26,27]` — what the schedule panel actually collects.
  `$phase_map['final']` carries all seven so item 20 has a review surface at all.

  - **The calendar is browsable while documents are still outstanding.** Six documents is a lot to
    make a group collect before they may even look at availability. `$df_show_calendar` no longer
    waits on `$df_app_missing`; the **Request button** is what waits, so the office still never
    receives a date request with paperwork missing. Enforced server-side in `defense_handler.php`'s
    `select_date`, not just by a disabled button.
  - **Confirming a date requires all six Approved.** The office screen lists each document with its
    status, says "N of 6 cleared", and disables *Log & Schedule* until they are. Also enforced
    server-side in the `schedule` action — a disabled button is not a rule.
  - **The sheet, the three-state pill and their JS are now shared.** `.prop-stage-*`, the
    `is-soon`/`is-ready`/`is-ticket` pill states and `dfOpenStage`/`dfCloseStage`/`dfOpenTicketStage`
    moved out of `module_proposal.php`'s inline `<style>`/`<script>` into
    `components/defense_scheduler.php`, which both modules already include. This also fixes a latent
    bug: `upload_handler.php` and `defense_handler.php` have always redirected Final with
    `view=schedule`, but Final had no sheet to open, so that redirect landed on nothing.
  - **Final's requirement grid is never hidden any more.** Phase 1 is the first thing a group does
    in the card, so the old "locked until a date is confirmed" gate (and the unreachable
    `is-locked` path behind it) is gone.
  - **Dropped two misleading download links.** Items 23 and 24 offered a "blank form" pointing at
    `../placeholder.pdf`, which is a byte-for-byte copy of `endorsement.pdf` — students asking for a
    blank communication letter were handed an adviser endorsement. Only documents the office really
    issues a blank for (12, 20, 22) keep a download chip.
  - Disabled buttons in the date picker now *look* disabled; nothing in `dashboard-cards.css` dims a
    disabled `.btn`, which mattered little when the only reason was "no date picked" and matters a
    lot now that a chosen date can still be un-sendable.

- **Staff review screens: process-ordered nav, one toolbar row, and cards that state their own
  status (2026-08-28):** the admin side of the app had grown module by module, so it read like a
  list of features rather than a procedure. Reworked across `director.php`, `coordinator.php`,
  `statistician.php` and `admin_module_dynamic.php`.

  - **Nav follows the office's real sequence.** The flat *Review* section became two numbered
    stages, because the order genuinely differs per phase:

    ```
    PROPOSAL        ① Checklist   ② Schedule
    FINAL DEFENSE   ① Schedule    ② Checklist   ③ Manuscript   ✓ Cleared
    ```

    Proposal is requirements-then-booking; Final is booking-first, since Form 002 and the Minutes
    are only handed over *after* the proposal defense. **Plagiarism** is renamed **Manuscript**
    everywhere in the nav — that screen reviews the manuscript groups submit after they have
    presented, and "plagiarism" described the check, not the stage. Step markers are a `.nav-step`
    chip inside `.dock-label`, so the collapsed icon rail drops them with the text.
  - **`By group` / `By requirement` moved to the far right of the filter row** (new
    `.review-toolbar`) instead of sitting on a band of its own — the group tiles start a whole
    row higher. Under 1100px the lens keeps its icons and drops its labels rather than wrapping.
  - **Back is out of the profile card.** It was the first child of `.ws-head`, reading as a fact
    about the group; it now sits on its own `.ws-topbar` row above it, renamed **Back**, solid
    `--ink` with a breadcrumb beside it. Both workspaces (group and requirement) use it.
  - **Batch approval no longer confirms through `window.confirm()`** — that rendered the decision
    as a browser alert captioned "localhost says" with the requirements as a bullet list. It is now
    a real dialog (`#wsConfirm`) naming the subject, listing each document as a row, and carrying
    the Form 008 follow-up note when the rubric opens next. `wsConfirmBulk()` always returns false
    and the dialog submits the form itself.
  - **The batch checkbox is a labelled strip**, not a 19px tick beside the title — the old one was
    invisible unless you already knew it was there. Whole strip is the hit area, and it tints on
    select.
  - **Cards carry status, not decoration.** The orange bar across the top of every busy group tile
    is gone; tiles and requirement cards now share a 3px leading rail plus a 4–5% whole-card tint
    per state, and every requirement card leads with a tinted status glyph (the student
    dashboard's card language). Long research titles clamp to two lines, so a row of cards is one
    height instead of six.

### Fixed
- **The Ticket pill opened a blank sheet (2026-08-28):** once a proposal defense is scheduled the
  scheduler component renders *only* the ticket modal — and that modal is a child of the stage
  overlay, which is `display:none` until opened. Clicking the green Ticket pill therefore showed an
  empty white sheet, while the same ticket opened correctly from the dashboard billboard. The pill
  now opens the overlay and the ticket together, and closing the ticket takes the sheet with it.
- **The stage overlay could open invisible (2026-08-28):** its visibility came from a fade-in
  keyframe, so an interrupted or never-started animation left it open at `opacity: 0` — technically
  on screen, with the requirement cards showing straight through it. `.open` now sets opacity
  outright and the animation only eases the sheet in.
- **Uploading kicked you back to the requirement list (2026-08-28):** `upload_handler.php` and
  `defense_handler.php` redirected to `student.php?module=proposal`, which reopens the card on its
  requirements — so every application upload and every date pick dropped the group out of the
  scheduling sheet and made them click back in. Both now redirect with `view=schedule`, and
  `student.php` forwards that through to the module.
- **The hover-peek let you into locked cards (2026-08-28):** the desktop preview panel called
  `pushView()` unconditionally, so clicking anywhere inside it opened a stage the card itself
  refused to open. The panel is a preview of the card, so it now carries the same gate — a locked
  stage shows a **Locked** footer and the reason instead of an invitation.
- **The "not yet" message was the corner toast (2026-08-28):** tapping a locked card fired
  `showToast()`, which slides in from the edge and carries a dismiss ×. Far too heavy for "come
  back later", and worst on a phone. Replaced with a small lock pill that fades in over the card
  that was tapped and leaves on its own — no ×, nothing to dismiss.

### Changed
- **Defense stage UI, after office feedback (2026-08-28):**
  - The **Schedule / Ticket pill** moved out of the filter-pill group (it filters nothing) into
    the row beside it, matching Final's ticket opener. It carries its state in colour: muted
    purple before phase 1 is cleared — **not grey**, which read as broken rather than "coming" —
    full purple when it is your next move, then **green with a slow pulse** once you hold a
    ticket, because the next step happens off-system and is easy to miss. Renamed to just
    **Ticket**. Final's opener matches.
  - **The ticket is no longer just a date.** It now carries what happens next: good luck, go in
    when your slot is called, your teacher writes the Minutes during the defense and your adviser
    keeps it — ask them for it — then upload it in Final. Plus an **I'm ready — open Final
    Defense** button that switches cards from inside the module iframe.
  - **Web layout:** the how-to and the calendar are two halves of one instruction, so above 900px
    they sit side by side instead of the guide running the full panel width with the calendar far
    below. When there is no calendar yet, the upload cards are held to a readable measure rather
    than stretching across a 1200px screen. Phones keep the stacked order.
  - **One card per application document**, each with its own heading and its own **numbered
    how-to** — the same 1-2-3-4 shape the requirement cards use. A one-line prose blurb read
    faster but was much harder to follow while actually doing the thing. The Minutes card also
    answers "what even is this": the notes your teacher took during the defense, which your
    adviser keeps. Buttons are now just **Download** / **Upload**: the document is named directly
    above them, so "Download Endorsement From the Adviser" next to "Upload Endorsement From the
    Adviser" was noise.
  - **Panels no longer run the full width of a desktop module.** Instructions stretched across
    1200px read as an empty page with text stuck to the edges. The panel is capped and centred;
    guide + calendar sit side by side when there is something to explain, and once every document
    is in the calendar becomes the whole panel and is **centred** rather than pinned to one side
    of an empty grid.
  - The **Schedule pill pulses** as soon as phase 1 clears, not only once a ticket exists —
    booking a date is the next thing to do and it lives behind that button.
  - **The toast was replaced.** "File successfully submitted for verification" arrived as a white
    box pinned top-right with a coloured left bar and a dismiss × — the shape of a 2010s alert.
    It is now a dark pill that rises from the bottom, with no × (it leaves on its own) and colour
    carried only by the icon, matching the locked-card pill so every transient message reads as
    one system.
  - The 100% banner no longer says "Capsule Proposal Stage" (that is one requirement of five) —
    it reads **PHASE 1 COMPLETE** and points at the Schedule button.
  - The "date picker opens once every document is uploaded" note used the red rejection style,
    making a normal waiting state look like an error. It has its own neutral style now.

- **`calendar_handler.php` wrote blackouts for one phase only (2026-08-27):** adding an office
  calendar event closed the date for `final` and hardcoded that string, while its "is a group
  holding this date" check had **no phase filter at all**. Harmless only because proposal
  scheduling did not exist; it goes live with this change. The closure is now written for both
  phases (the office being shut shuts it for everyone) and the held-check stays phase-agnostic on
  purpose. The matching delete already spanned both.

- **Workspaces everywhere + the reversed By-requirement view (2026-08-27):** the group workspace
  built for Proposal now covers **Final** and the **Stats checklist**, and **By requirement** was
  rebuilt as its mirror. Both views work the same way: a grid of tiles, click one, it fills the
  right side, you stay there until you press Back.

  - **By group** — pick a group, clear its whole checklist.
  - **By requirement** — pick a requirement, clear it across every group that submitted it.
    Tick the 5 groups whose letters you just checked, approve in one action. Cards lead with the
    group (avatar, leader, School ID) instead of the requirement name, and the sidebar group
    picker still narrows the list inside an open requirement workspace.

  Every workspace element is now addressed by a scope key — `g-<groupId>` or `r-<itemId>` — so
  one set of JS drives both views and the same upload can appear in both without colliding.
  `bulk_verify` gained a second scoping mode to match: `return_group` scopes the re-read to one
  group, `return_item` to one requirement, and ids outside that scope are dropped before
  anything is written.

  **The decision logic is now shared, which it had to be.** Stats items are not plain documents:
  approving item 30 moves `form_stat_treatment` to Phase 2, 31–35 drive the Phase 6 / Phase 7
  rollup, 36/37 write `payment_logs`, and each one emails the student. Batch-approving them
  through a separate code path would have silently skipped all of it. `verify_upload`'s body was
  extracted into **`applyReviewDecision()`**, which both the drawer and the batch call — so the
  stats transitions, the item-14 cascade and the activity log can never drift between them. The
  batch omits the `rubric` key so it never blanks a stored Form 008; the drawer passes it.

  Phase-specific behaviour is now data, not hardcoded ids: `$ws_cascade_items` and
  `$ws_rubric_items` are empty outside Proposal, so Final (22–27) and the Stats checklist (30–35)
  offer all their requirements for batch approval with no Form 008 caveat, while Proposal keeps
  the rubric gate and the 13/15/16 cascade nested under the Capsule Proposal.

  **Plagiarism, Payments, Release and History keep their own layouts** — they are queues and logs,
  not group review, and payments stays FIFO on purpose. They were restyled to the same visual
  language instead: the plagiarism list lost the accordion wrapper it never used (its body was
  always expanded) in favour of the workspace section header, and its cards adopted the workspace
  card treatment. The plagiarism *workflow* is untouched — that flow is still being designed.

- **Full-width review chrome (2026-08-27):** the module gave up ~90px of every screen to a page
  title and a blurb that repeated what the side rail already said — both removed, so the filter bar
  now starts at the top. The **Select Student Group** picker is collapsed by default and the review
  area owns the full module width; a **Groups** button in the filter bar brings the picker back and
  the choice is remembered per browser (`localStorage['rd-module-groups']`). Requirement cards
  reflow to three per row at full width instead of two. The per-workspace *Focus* button is gone —
  full width is now the default, so it had nothing left to do.

### Changed
- **Review is single-stage — the Coordinator→Director chain is gone (2026-08-27):** the handler used
  to downgrade a Coordinator's *Approved* to `Under Review` so it queued for the Director, and the
  workspace filtered each role to only the state it "owned". That chain does not exist in the real
  office: **Coordinator and Director hold the same power and either can decide anything**, and the
  person actually doing this work is the **Secretary** (a role that will be added later; for now
  staff log in as Director and act as her).

  An approval now writes `Approved` whoever makes it, and anything still in flight is decidable by
  any reviewing role. `Under Review` is legacy only — never newly written, still treated as
  actionable so existing rows can be cleared. All role-handoff wording is gone with it: *Endorse to
  Director* → **Approve**, *Endorse all* → **Approve all**, the *Waiting on the Research Director*
  section and the *"3 with coordinator"* chip are removed, and the per-card hint now reads **"Check
  the file is the right one, then approve"** — which is what this stage actually is. Coordinator and
  Director now render byte-identical review screens.

  Scope note: deep manuscript review, with work passed between reviewers, belongs to the **final
  manuscript submission** stage (RDC Form 005 completeness check, Form 013 plagiarism processing)
  and is deliberately not built yet.

### Fixed
- **"Approve all" was missing outside Proposal (2026-08-27):** the one-click button was only
  emitted when a rubric-gated item was pending, which is Proposal item 14 and nothing else — so on
  Final, the Stats checklist and every By-requirement workspace there was no *Approve all* at all,
  just *Select all* followed by *Approve selected*. Both workspaces now always carry an explicit
  **Approve all (N)** / **Endorse all (N)** button next to the granular path, and on Proposal it
  still reads *Endorse all & review Form 008* and walks into the rubric afterwards. The confirm
  dialog now leads with the count as well as the full list of what is about to change.
- **Action Needed was empty even when submissions were pending (2026-08-27):** the tab used to
  match `pending || under review` for everybody. The new tiles filtered on a **role-scoped** count
  instead, so a Research Director — who acts on `Under Review` — saw an empty tab the moment
  nothing had been endorsed yet, even with pending submissions sitting right there. That silently
  narrowed what the tab means and made it useless for half the workflow.

  Tiles now filter on an **in-flight** count (`Pending` *or* `Under Review`), restoring the original
  semantics: Action Needed is everything still moving through the workflow, as opposed to Approved
  Archive and Revision History. Whose turn it is stays visible on the tile instead: **"3 to review"**
  with an amber edge when it is yours, a muted **"3 with coordinator"** when it is not. Inside a
  workspace the *Needs your review* / *Waiting on …* sections already drew that line correctly.
  The empty state also became a real card ("Nothing is waiting on you here" + *Show all
  submissions*) rather than one easy-to-miss grey line.
- **CSRF was not enforced on the Final phase (2026-08-27):** `verify_upload` checked the token
  only for `proposal|stats|plag`, leaving `final` reviews forgeable. The check now covers every
  phase; the only form that posts there (`#modalForm`) has always carried the token.
- **Dark mode across the staff module (2026-08-27):** 53 hardcoded light-theme hex values
  (`#9ca3af`, `#faf8f4`, `#f8fafc`, `#f59e0b`, …) were replaced with theme tokens, plus two
  buttons that rendered white-on-white in dark mode (Replace Report, Download Original File).

- **Group Workspace — review one group without losing your place (2026-08-27, Proposal only):**
  the Research Office reported the approval flow as confusing and laborious, and they were
  right about why. In the group inbox, a decision was a **full page POST with no redirect**:
  the page re-rendered from scratch, `currentView` and `activeGroupId` reset, and **every
  accordion collapsed**. After approving one requirement the reviewer was dumped back at the
  top of the group list and had to find and re-open the same group — six requirements meant six
  round trips.

  Clicking a group now opens a **full-width workspace** in `.main-content-panel` (an in-flow
  view swap, not a fixed overlay — the module already fills the area right of the side rail),
  and the reviewer **stays there until they press Back**. The document drawer is unchanged: the
  same `#docModal`, same file preview, same Form 008 rubric. It just gained an optional 4th
  argument, `openDocumentModal(data, reqName, itemId, returnGroup)`, so decisions made from a
  workspace redirect back into it. Existing 3-argument callers (By-requirement view, stats
  payments, history entries) pass nothing and behave exactly as before.

  **Batch approval.** Checkboxes on the requirements that can honestly be cleared without
  opening a form, a sticky action bar with `Select all` / a live count, and a confirm step that
  names every requirement before committing. Selection survives the page reload via
  `sessionStorage`, so ticking three boxes and then opening one document doesn't lose the other
  two. New `bulk_verify` POST handler: CSRF-checked, re-reads every posted id from the database
  scoped to that group (forged or foreign ids drop out), and refuses anything not in the state
  the actor may act on — Director acts on `Under Review`, Coordinator/Statistician on
  `Pending`. That state gate also makes the action **idempotent**: a stray resubmit finds
  nothing to do. Unlike the single-document path it leaves `form_008_data/score/decision`
  untouched rather than stamping `0`/`Deferred`.

  **What cannot be bulk-approved, by design.** Item **14** (Complete Capsule Proposal) carries
  the 22-question Form 008 rubric, and **13/15/16** are cascade children auto-cleared when 14
  is approved. Only 11 and 12 are ever selectable. So `Approve all & review Form 008` clears
  those two and then **opens the Capsule Proposal rubric by itself** (`&open_item=14`) instead
  of leaving it as a silent loose end.

  **Rejections carry their reason.** `Request revision` expands a required reason box **on that
  card** — no need to open the document window just to type feedback. It reuses the existing
  `verify_upload` handler unchanged. Disabled on item 14, where a rubric-less write would wipe
  a stored Form 008.

  **Clarity work**, since the office's actual complaint was confusion: requirements are grouped
  into *Needs your review* → *Sent back for revision* → *Waiting on …* → *Approved* → *Not
  submitted yet*; missing requirements render as ghost cards so "4 of 6" is finally readable;
  13/15/16 appear as nested rows under the Capsule Proposal that clears them rather than three
  independent chores; and the primary button reads **"Endorse to Director"** for a Coordinator,
  because a Coordinator's approval is written as `Under Review`, not `Approved`. Status tabs now
  filter *which groups* appear — inside a workspace all six requirements always show. Plus a
  `Focus` toggle that hides the group picker, an unopened-file dot, and `Esc` to back out one
  level.

  Scoped to `?phase=proposal` (`$use_group_workspace`); **Final, Stats and Plagiarism keep the
  existing accordion untouched** pending office sign-off. Also fixed dark-mode bugs on this
  screen: the By group/By requirement toggle and the sidebar's "Show all groups" button had
  hardcoded light hex values that never flipped.

- **"Start a New Research" — archive-and-reset for returning students (2026-08-27):** a group
  that finished its research in 3rd year had no way to come back in 4th year with a new study.
  Their portal was stuck at 100%, their control numbers were permanent, and there was nowhere
  to put a new title. The group leader can now archive the finished cycle from
  **Settings → Danger Zone**, which drops the portal back to **0%** and opens a setup form for
  the new research — while the Research Office keeps every last row.

  **Nothing is destroyed.** The reset *moves* the finished cycle's rows into parallel
  `*_archive` tables (`uploads_archive`, `approvals_archive`, `form_stat_treatment_archive`,
  `plagiarism_checks_archive`, `defense_bookings_archive`, `form_008_reviews_archive`,
  `activity_logs_archive`, `payment_logs_archive`) keyed to a new `research_cycles` row, which
  also snapshots the title, year level, control numbers and the full member roster as it stood.
  Files under `uploads/` are **never unlinked** — archive rows keep `file_path`, so staff can
  still open last year's PDFs. Group chat is deliberately left live.

  Rows were moved rather than filtered behind a `cycle_id` because `uploads` alone is queried
  from ~65 hand-written SQL sites across 20 files; one missed `WHERE cycle_id = active` would
  leak last year's research into this year's review queue. Moving them means every existing
  live query keeps working untouched, and progress needs no recomputation — the stage cards
  count `uploads` rows, so emptying them *is* the reset.

  Leader-only and self-service, guarded by CSRF, a `leader_id IS NULL` check and a typed
  `START NEW RESEARCH` phrase; members see a note pointing at their leader. The leader picks
  which members carry over — unticked members are unlinked and become their own leader.
  `reset_handler.php?dry=1` runs the whole transaction and rolls it back, printing per-table
  counts, for checking a group before doing it for real. Schema in
  `docs/sql/2026-08-research-cycles.sql` (idempotent).

- **Research History for staff (2026-08-27):** new read-only `research_history.php`, reachable
  from the Workspace section of all three staff shells. Pick a group → read its archived
  cycles newest-first: title, year level, proponents at time of archiving (marked *stayed* or
  *left*), every requirement grouped by phase with its final verdict, remarks and a working
  **Open** link, plus the defense schedule, statistical treatment, payment record and the full
  activity log. No POST handlers at all — archived verdicts are history and cannot be
  re-litigated from here.

- **Year level (2026-08-27):** `users.year_level` is now collected at registration (Step 3) and
  shown to staff on the group explorer and Group 360 card. Personal like `student_number` —
  **never inherited from the leader.** A copy is stamped onto each `research_cycles` row so an
  archive reads "3rd Year" even after the student moves up.

- **Research Title is editable in `profile.php` (2026-08-27):** registration's Step 4 field is
  labelled "Research Project Title" but actually writes `users.research_group_name`;
  `users.research_title` was only ever written by the statistical treatment form, so groups
  showed as "Untitled Research" to staff for most of the workflow. Both are now set together
  by the new-research setup form, and the title can be corrected any time from the profile.

### Fixed
- **Control numbers could be reissued after an archive (2026-08-27):** `PLAG-…` sequences come
  from `SELECT MAX(control_number_seq)` over the live table, so moving a finished group's row
  into the archive would have handed the *next* group a control number already issued to
  somebody else. Both generators (`upload_handler.php`, `stat_form_handler.php`) now take
  `GREATEST(live, archive)`. Verified: with an archived sequence of 77 the next number is 78,
  where the old query said 4.
- **Handler toasts never appeared (2026-08-27):** `upload_handler.php` and friends have always
  redirected back to `student.php?msg=…&type=…`, but nothing read those parameters, so every
  success and error message was silently dropped. `student.php` now reads them and calls the
  existing `showToast()` before the `?module=` branch wipes the querystring.
- **`settings.php` had no CSRF protection (2026-08-27):** both the password-change and
  account-suspension forms accepted a bare POST. Both now carry and verify `csrf_token` with
  the same `hash_equals` pattern used elsewhere in the project.
- **Final Defense now starts with an application and a scheduled date (2026-08-26):** RDC's
  published "Application for Final Defense" flow has six steps, and the portal previously
  implemented only the middle of it — a group could upload requirements without ever applying
  or being given a defense date. Two new tables (`defense_bookings`, `defense_blackouts`) plus
  a new checklist item 20 (Form 002) add the missing front stage: **apply → pick a date → the
  office logs it → requirements unlock.**

  **Availability is open-by-default.** The office does not publish slots; every working day is
  bookable and staff close the *exceptions* instead — holidays, closures — mirroring how the
  existing admin calendar already works. A date is bookable when it is inside the booking
  window (`DEFENSE_LEAD_DAYS`/`DEFENSE_HORIZON_MONTHS`, tomorrow → +4 months), is not a Sunday,
  carries no blackout row, and is not already held by another group. All four rules live in one
  function, `df_unavailable_reason()`, which the calendar renders from *and* the handler
  enforces — so the picker can never offer a date the server would refuse.

  Requirements 22–27 are withheld **server-side** until `status='Scheduled'`: before that the
  grid, the filter pills and the whole upload form are absent from the HTML rather than merely
  hidden, so a locked requirement cannot be posted by hand either. Form 002 rides the existing
  `upload_handler.php` path, inheriting versioning, history and activity logging for free.
  Bookings are keyed to the group leader (`effective_user_id`), exactly like `uploads`.
  Both tables carry a `phase` column from day one — wiring Proposal later is a copy of the
  markup, not a rewrite. Schema in `docs/sql/2026-08-defense-scheduling.sql` (idempotent).

- **Student stage flow shows one job at a time (2026-08-26):** step 1 renders *only* the
  application panel — no "next step" banner, no All/Needs you/Cleared pills, no requirement
  cards, since none of them are actionable yet. Step 2 is a full month calendar with month
  paging bounded by the booking window, a "soonest available" shortcut, and a tap-to-explain
  behaviour on unavailable days (implemented with `aria-disabled` rather than the `disabled`
  property, because a genuinely disabled button never fires a click and so could never say
  *why* it was closed). Once scheduled, the cards and pills return and the schedule collapses
  into a **View** pill beside the filter tabs that opens the defense ticket in a blurred modal,
  so it stops sitting on top of the requirements.

- **Apply step presentation (2026-08-26):** the download now serves the official
  `application_isap.pdf` (ISAP-QMS-DCO-RDC Form No: 002). The 1-2-3 step rail moved outside the
  panel card — it tracks the whole stage, not that one panel — and the instructions became a
  numbered list in the same shape as the requirement cards' `$item_steps` lists instead of a
  single run-on sentence.

- **Defense ticket is downloadable, with the mascot (2026-08-26):** the ticket modal's action
  is now **Download** (plus Close), producing a 2x PNG named `Final-Defense-<date>.png`. It is
  painted onto a `<canvas>` rather than screenshotted with a library, so the download stays
  dependency-free and works on a phone; the party mascot is composited with a `screen` blend so
  it reads as a watermark instead of muddying the dark gradient (`isolation: isolate` on the
  ticket keeps that blend from reaching the page behind it). The modal no longer blurs its
  backdrop, and the ticket opener moved *out* of the `.status-filter-tabs` group into a sibling
  row — it filters nothing, so it does not belong inside that control group, though it keeps its
  position beside the pills.

- **All-clear banner reworded** to congratulate and say what happens next, and internal
  "poster step N" phrasing was replaced throughout with "application flow step N" — the word
  described a Facebook image, which means nothing to a future reader of the code.

- **Office scheduling screen** at `admin_module_dynamic.php?phase=final&view=schedule`: a
  decision queue, a mini month calendar where tapping an open day closes it and a closed day
  reopens it, an **Import PH holidays** action that turns the client-side holiday feed the
  calendars already used into server-enforced blackouts, an **Upcoming** list, a **View all**
  planner window, and clickable "Logged & recorded" rows opening a detail window with
  **Cancel**. Cancelling frees the date, notifies the group and re-locks their requirements —
  but deliberately keeps every file they already uploaded.

- **Edge cases handled explicitly:** a request whose date got closed while it sat in the queue
  is flagged in the office queue and cannot be confirmed; a date a group is holding cannot be
  closed or holiday-imported over (the office must reject or cancel first, so the group always
  learns what happened); a cancelled or rejected booking clears its stale remarks when the group
  re-picks; a booking can only be decided once; and a past defense date reads as "date passed"
  rather than upcoming.

- **Billboard strip on the student home (2026-08-26):** a promo-style status card under the
  milestone row, in the pattern payment apps use for their "for you" banners. It carries a solid
  colour that changes with the group's booking state (purple = pick a date, amber = waiting on
  the office, green = scheduled, red = rejected/cancelled), a slow drifting highlight for motion
  that never distracts, and a call to action whose wording follows the state — "Pick a date" /
  "View request" / "See requirements" / "Get a new date" / "Reschedule now" — landing straight in
  the Final Defense module. It only appears once a group has actually applied. Beside it sits a
  graphite "Publish your research" card previewing the journal publication stage, deliberately
  inert (it explains itself instead of navigating nowhere) since that module does not exist yet.
  Lives in `dashboards/components/dashboard_billboards.php`; read-only, reusing values
  `student.php` already fetched for the stage cards. A scheduled group's card reads **View ticket** and
  deep-links to `module_final.php?ticket=1`, which opens the ticket on arrival — reusing the
  `?item=` deep-link convention the module already had. The ticket sheet is now plain white
  rather than a dimmed backdrop, so the perforated card reads as a real ticket on paper.

- **Office scheduling screen, second pass (2026-08-26):** the requester block became a proper
  profile card with icon-labelled leader / school ID / program instead of a run-together text
  blob. The uploaded Form 002 now opens in an in-app preview popup rather than throwing staff
  into a new browser tab. "Logged & recorded" caps at the 6 most recent with a searchable
  **View all** window, so the list cannot grow down the page forever. The **Defense planner** is
  now a real month grid — Google-Calendar style, one cell per day, group chips coloured by state,
  clickable straight through to the booking detail. **Import PH holidays** lost its dedicated
  card and became a one-line action inside the calendar card: it is a convenience (without it
  nothing stops a group booking Christmas Day), not a feature that earns its own panel. A
  conflicted booking's **Log & Schedule** button is now visibly disabled — `portal.css` has no
  `:disabled` style for `.btn`, so it previously looked perfectly clickable while doing nothing. The planner opens at up to 1120px so a
  six-week month is readable, **View all** is always offered rather than only once the list
  overflows, Reject reads as destructive (red-tinted, without shouting over the primary), and
  the queue/done sections are colour-coded amber and green.

- **The office calendar and defense scheduling are now one entry, not two (2026-08-26):**
  `calendar_events` and `defense_blackouts` were separate systems — adding "Faculty Assembly" to
  the office calendar left that date perfectly bookable for defenses, and staff had to close it a
  second time by hand. `calendar_handler.php` now writes through: adding an event also closes
  that date for defenses (`source='event'`, reason prefixed "Office calendar:"), and deleting the
  event lifts that closure again — but only if no other event still covers the date, and never
  touching closures made by hand or imported from the holiday feed. A date a group already holds
  is never closed this way; the office has to reject or cancel the booking first, so the group
  always learns what happened. Because every event closes its date by default, the Defense
  Scheduling screen labels where a closure came from and reopens it in one click, leaving the
  calendar entry itself alone — that is the escape hatch for events that are not really closures.
  Existing future events were backfilled.

- **Live "Scheduling" badge** in the Director and Coordinator side-nav, server-rendered and
  refreshed by the existing `nav_badge_counts.php` poller (new `schedule` key).

- **Defense dates on both student calendars:** `calendar_all.php` has always had "Available" /
  "Unavailable" filter pills, chip colours and dark-mode variants that *nothing ever produced* —
  `classify()` could only keyword-guess from `calendar_events` titles, and that table is empty.
  Office closures, dates taken by other groups, and the group's own defense now feed those
  categories. The `student.php` mini-calendar gets the same data as pseudo-events titled to
  match the vocabulary its renderer already keyword-matches, colouring dates with **no change to
  the calendar JS at all**.

### Changed
- **Requirement 21 (Endorsement From the Adviser) retired as a Final Defense requirement
  (2026-08-26):** on RDC's poster the adviser endorsement is **step 6** — the last thing a
  cleared group secures, at the defense itself, which is outside this system's scope. It was
  sitting first in the module and being treated as an upload. Item 21 is now removed from all
  **10** item lists (`module_final.php`, `student.php` ×3, `admin_module_dynamic.php`,
  `analytics.php`, `group_profile_data.php`, `coordinator.php`, `director.php`,
  `nav_badge_counts.php`, `_master_overview.php`) and Form 006 has moved to the all-clear reward
  block beside the completion card and Form 005, where a cleared group downloads it. The
  `checklist_items` row is left in place; its single existing upload was test data.
- **The Final card footer now shows at 0% progress when there is a front-stage action.**
  Zero-progress cards normally hide the footer because there is nothing specific to say; "Apply
  for final defense" / "Pick a date" / "Waiting for the research office" is specific, so it earns
  the line. Short and full text variants per breakpoint, no truncation. The card's hover-peek
  leads with an "Application & Schedule" row and reads the requirements below it as **Locked**
  rather than "Not uploaded", which would otherwise invite an action the group cannot take yet.
- **CSRF enforcement extended to the `final` upload context** in `upload_handler.php`.
  `module_final.php` is its only poster and has always sent the token, so this closes the gap
  without changing any form.

- **Defense date logic pinned to Asia/Manila (2026-08-26):** PHP on the dev box runs
  `Europe/Berlin`, so a bare `date('Y-m-d')` was a full day behind for the entire Manila
  evening — long enough to offer "tomorrow" as a date that had already started, or to mark a
  live defense as past. All defense date maths now goes through `df_today()`/`df_shift()`,
  which read an explicit `DateTimeZone` rather than calling `date_default_timezone_set()`, so
  the rest of the project's timestamp handling is untouched (the same approach `student.php`
  already uses for its greeting). SQL `CURDATE()` comparisons were replaced with bound
  PHP-computed dates for the same reason.

### Added
- **Stage hover-peek on the student home cards (2026-08-19, desktop only):** dwelling ~380ms on
  any of the 4 milestone cards grows it into a large preview over a blurred scrim — a FLIP
  transform starts the panel at the card's own footprint and springs it out to full size, so it
  reads as the card becoming the panel. The panel is anchored on the card it came from (clamped
  to the viewport) rather than screen-centred; that is also what guarantees the pointer lands
  inside it, so moving card → panel can't trip the leave-to-close. Contents are a read-only
  projection of data `student.php` already fetched (`$stage_peek_data`): big ring + %, the
  "what's next" line, and a live requirement checklist with per-item Approved / Under review /
  Needs revision / Submitted / Not uploaded status. Proposal lists items in the same order
  `module_proposal.php` does (12, 11, 14, then cascaded 13/15/16 tagged "Follows Capsule
  Proposal"); Statistics has no checklist rows so it shows the 7-phase ladder marked against the
  same monotonic `$stats_progress` the ring uses; Plagiarism shows manuscript (4) + Turnitin
  report (40). Chip and ring tone are copied from the card's own logic so the preview can never
  disagree with the card it grew out of. Clicking anywhere in the panel opens the module exactly
  as clicking the card does; a "What to do" button reaches the existing ⋮ steps modal (which the
  panel would otherwise cover); backdrop click, Esc, wheel, resize, blur and back all dismiss.
  One new read-only `SELECT` (checklist item names) — no workflow logic, POST handlers or SQL
  writes touched. Off entirely below 1025px and for coarse/touch pointers, guarded in both the
  CSS and the JS. Status chips in the panel carry no lucide `<i>` on purpose: injecting one would
  force a `lucide.createIcons()` full-DOM rescan on every hover, the same scan that previously
  froze the card modal. Verified with headless-Chrome renders of all 4 panels plus dark mode.

### Changed
- **Student mobile header is now an app-style greeting instead of a clipped title (2026-08-24):**
  below 641px the header showed the research group name capped at a fixed `max-width: 180px`
  with `text-overflow: ellipsis`. Because the cap was a hard pixel value rather than
  flex-derived, a long group name rendered as `THE EFFECT OF CLASS A…` even on a 430px phone
  with room to spare — a full header row that conveyed nothing. On phones the title is now
  replaced by a two-line greeting: a time-aware `Good morning / afternoon / evening` over the
  student's first name, which cannot truncate because it is bounded by a name. The
  `border-bottom` divider is dropped at that breakpoint too, so the header reads as app chrome
  rather than a shrunken desktop header. The full group name has not gone away — tapping the
  greeting opens a popover with the complete name (free to wrap, no ellipsis anywhere) plus the
  `MCNP • BS Nursing` subtitle that mobile already hid. The greeting fills the previously dead
  `#liveGreeting` CSS hook, which had sat in the file with no matching element. PHP renders an
  initial Asia/Manila value so there is no flash, then the device clock corrects it on load and
  every 5 min; the hour is read via an explicit `DateTimeZone` rather than
  `date_default_timezone_set()` so the rest of the file's timestamp handling is untouched.
  Popover closes on outside click, Esc, `popstate`, and orientation change, and is mutually
  exclusive with the avatar dropdown. The resize listener deliberately compares `innerWidth`
  first: mobile browsers fire `resize` every time the URL bar collapses on scroll, which
  otherwise snapped the popover shut the moment it opened. Presentation only — no workflow
  logic, POST handlers, SQL writes or review-JS contract touched. Desktop and tablet render
  byte-for-byte as before (verified against the committed version at 820px and 1280px);
  checked at 390px and 430px in light and dark, with the longest group name in the database
  (83 chars).

- **Header blurbs hidden on mobile in the student sub-pages (2026-08-19):** `activities_all.php`
  and `calendar_all.php` each open with an explanatory `.header-desc` paragraph ("A record of
  everything you've done…" / "Everything on the research office calendar…"). On phones those two
  sentences pushed the search box and filter pills below the fold. Both now `display: none` under
  768px, with `.page-header` gap/margin tightened so the title sits directly above the search
  field. Desktop is unchanged, and the paragraphs stay in the markup for wider screens.

### Fixed
- **Dark band bleeding over the right edge of the student modules (2026-08-16):** on web, closed
  `.history-panel` drawers were parked off-screen with `transform: translateX(100%)` only. A
  transform doesn't stop the browser painting the element's `box-shadow`, so the drawer's
  left-side shadow (`-12px 0 40px rgba(15,23,42,.22)`) spilled back onto the page as a blurry
  grey/black vertical strip hugging the right edge — reported as "a black LCD-looking thing".
  It only showed once a requirement had been acted on, because history panels are emitted only
  for items with review history, and it darkened as more approved items stacked more off-screen
  panels. Fixed in `assets/css/dashboard-cards.css` by adding `visibility: hidden` while closed
  (`visible` on `.open`, with a `visibility 0s linear 0.35s` delay so the slide-out still
  animates). Same latent bug on mobile, where the bottom sheet's `0 -8px 40px` shadow bled
  upward. Applies to all four student modules (proposal, final, statistics, plagiarism), which
  share this stylesheet. Verified with headless-Chrome renders: band present with the fix
  disabled, gone with it on, drawer still opens and closes normally.

### Changed
- **Statistics "Payment History" redone as a POS-style log (2026-07-25):** the old view was a
  single collapsible "Payment Decision History" card with a hardcoded 30-row limit, sitting next
  to the group sidebar and the group/department/program filter bar — neither of which the payment
  log actually uses. Now (`view=history` on `admin_module_dynamic.php`): the group sidebar and
  filter bar are hidden on this tab only (`$is_history_view`, `.workspace-grid.full-width`); 5
  summary widgets up top (Logged Decisions, Accepted, Rejected, Accept Rate, Decided Today, all
  reusing the existing `.stat-card` component so nothing new was invented); a dedicated Month/Year
  filter; pagination (25/page) instead of a hard cutoff; and a CSV export
  (`&export=csv`, respects the active filter) that opens directly in Excel. "Total accumulated"
  was scoped to counts, not currency — `payment_logs` has no amount field, the payment step here is
  a document accept/reject decision, not a tracked transaction value; user confirmed count-based is
  fine, no schema change made. Verified end-to-end over real HTTP: widgets/log/export all agree,
  year filter correctly narrows to an empty state, screenshotted light + dark.

### Removed
- **AI Pre-Score (2026-07-10):** removed the "AI Pre-Score" button and its `runAIPrescore()` flow
  from the Form 008 rubric in `dashboards/admin_module_dynamic.php`, and deleted
  `dashboards/ai_prescore_handler.php`. That handler shipped a **hardcoded live OpenAI API key**
  in source — it's been in the repo since the initial commit, so the key is in git history
  regardless of this deletion and needs to be rotated on OpenAI's side. See `CLAUDE.md`.

### Fixed
- **Document Viewer Console: close button hidden by long titles (2026-07-25):** `#modalTitle`
  (`research_group_name + ' - ' + original_filename`) had no width constraint in `.preview-header`
  (a flex row), so a long group/file name forced the title block wider than the header and pushed
  the `×` close button out of view. `.preview-header-titles` now has `min-width:0` with
  ellipsis-truncated children (full text still available via a `title` hover tooltip), and
  `.close-preview` got `flex-shrink:0` so it can never be pushed out regardless of title length
  (`assets/css/portal.css`, `dashboards/admin_module_dynamic.php`). Also added click-outside-to-close
  on the modal backdrop (`event.target === this` guard, so clicks inside the panel don't close it).
- **Staff dock badges: stale counts + a badge that could never show (2026-07-10):** the
  Proposal/Final/Stats/Plagiarism review-queue badges in `director.php`/`coordinator.php`/
  `statistician.php` were computed once at page load only — since review modules open in iframe
  overlays that never touch the parent shell, the counts went stale until a full manual refresh.
  They now poll a new read-only endpoint (`dashboards/nav_badge_counts.php`) every 20s and on tab
  focus. Also fixed two real bugs found along the way: (1) the Director's Plagiarism badge counted
  `verification_status = 'Under Review'`, but item 4 (plagiarism) is single-stage and stays
  `'Pending'` the whole time it's actionable — so that badge could never light up. Now counts
  `'Pending'` for item 4 specifically, matching `statistician.php`'s existing logic. (2) Director's
  and Coordinator's combined Statistics badge only counted item range `30-35`, silently missing
  items 36/37 (the payment docs — Validated Form / Official Receipt). Since neither role has a
  separate Payment badge the way `statistician.php` does, a pending payment-doc submission was
  invisible in their sidebar entirely. Now counts `30-37`. Verified end-to-end over real HTTP with
  forged session cookies (not just static renders) for all three roles, including inserting real
  test upload rows and confirming both the initial page render and the live poll agree.
- **Director's badges scoped to full pipeline, not just 'Under Review' (2026-07-25):** Director
  outranks Coordinator and wants visibility the moment a student submits, not just the subset
  already advanced to Director's own action queue. `director.php`'s `$pending_counts` and
  `nav_badge_counts.php`'s Director branch now count `'Pending'` + `'Under Review'` combined per
  phase, so Director's badges light up in sync with Coordinator's instead of one workflow stage
  behind. Coordinator's own badges are unchanged (still `'Pending'` only — that's genuinely their
  action queue). Verified live against real in-flight submissions.

### Added
- **School ID Number on student accounts (2026-07-22):** students now carry an institutional ID
  (e.g. `2023-10078`), captured as a **required** field in **Step 3 "Academic Affiliation"** of
  `auth/register.php` and surfaced everywhere staff already identify a group by its leader.
  - **Schema:** new `users.student_number VARCHAR(20) NULL UNIQUE`
    (`database/migrations/2026-07-22_student_number.sql`, idempotent). Named `student_number`
    rather than `school_id` because `users.department` already stores the school (MCNP vs. ISAP),
    so `school_id` would read like a foreign key. The **UI label stays "School ID Number"**.
  - **Format:** `/^\d{4}-\d{4,6}$/` — year, hyphen, 4–6 digit serial. Enforced client-side via the
    `pattern` attribute (picked up for free by `validateStep()`) *and* server-side in both
    `register.php` and `profile.php`. Both pre-check uniqueness with a `SELECT` so a duplicate
    reads as a friendly message instead of a raw `uniq_student_number` PDO exception.
  - **Editable** by the student in `dashboards/profile.php` — this is also the backfill path for
    accounts created before the field existed. Empty is normalised to **`NULL`**, never `''`
    (two rows holding `''` would collide on the unique index). A bad value rejects the whole save
    rather than leaving the profile half-written.
  - **Personal, never inherited.** Unlike `research_group_name`/`department`/`program`, a group
    member does *not* pick this up from their leader at registration, and `profile.php` always
    writes it to the caller's own row (`$user_id`, not `$effective_user_id`).
  - **Staff visibility:** leader ID now shows on the sidebar group list, the group-inbox cards, the
    selected-group profile card, the payment queue, and the plagiarism cards in
    `dashboards/admin_module_dynamic.php` (new shared `.school-id-chip`), plus the Explorer
    dropdown and the Group 360 modal in `dashboards/_master_overview.php` /
    `dashboards/group_profile_data.php`. Students see teammates' IDs on `dashboards/members.php`.
  - **Search:** the admin module's search matches on `u.student_number`, and the sidebar's live
    client-side filter matches a new `data-school-id` attribute — so staff can type `2023-10078`
    (or just `10078`) to jump to a group. Existing name/group/program searches are unaffected.
  - Cards degrade cleanly (chip simply omitted) for accounts with no ID yet.

### Changed
- **Student Final Defense module: full UI parity with Proposal (2026-07-16):**
  `dashboards/module_final.php` was rewritten from its old standalone design (inline `<style>`,
  Cambria serif, plain grid) to reuse the shared Apple-Wallet card system
  (`assets/css/dashboard-cards.css` + `assets/js/dashboard-cards.js`), mirroring
  `dashboards/module_proposal.php`. It now has: pure-white wallet cards, the **All / Needs you /
  Cleared** pill filter, per-card read-only **History** panel (reviewed versions only), the
  download-preview modal, Pending-only delete guard (`deleteUpload(id,'final')`), the web
  horizontal card scroller, and the mobile bottom-sheet. Dropped the dead copy-pasted Proposal
  cascade (items 13/15/16) and Form 008 logic — Final (items **21–27**) has neither. Per-card
  downloads: 21→`endorsement.pdf`, 22→`mom.pdf`, 23/24→`placeholder.pdf` (real forms TBD),
  25/26/27 upload-only; the cleared banner downloads `final_done_card.png`. Wired `#zoom-final`
  into the three `@media(max-width:768px)` bottom-sheet blocks and the drag-handle JS array in
  `dashboards/student.php` (it was the only module missing from it). Added a root `placeholder.pdf`.
  Presentation-only: no `upload_handler.php`/SQL/field-name/`verification_status`/CSRF-contract
  changes (`module_context` stays `final`).
- **Requirement-card action buttons now align across cards (2026-07-16):** the "Download Form" /
  "Upload File" buttons used to sit at different heights because cards had different numbers of
  how-to steps (3 vs 4) and different title/description line counts. Fixed in the shared
  `assets/css/dashboard-cards.css` (affects **both** Final and Proposal, and any module using these
  cards): the step list is now `.card-steps` with a fixed `min-height` (reserves 4 rows; shorter
  lists center vertically), and the desktop `.card-title` / `.card-meta` reserve two lines each
  (`min-height`) so headers are equal height. Also reworded Final's **Minutes of the Proposal
  Defense** card steps to match the real workflow: download the form → adviser processes the
  minutes → adviser sends it back to the student → student uploads it here for admin review (it's a
  file the adviser prepares and returns, so no signing/capture-photo step). Also gave the three
  upload-only cards (Manuscript, Sample Datagathering Tool, General Statistical Tables) short
  prepare-and-upload step lists so every requirement card reads consistently.
- **Student Plagiarism module: centered lone card, extracted Turnitin report, right-sized tracker
  (2026-07-15):** `dashboards/module_plagiarism.php` now mirrors its sister
  `dashboards/module_statistics.php` framing. Presentation-only: (1) wrapped the page in
  `.page-wrapper { max-width: 960px; margin: 0 auto }` so the 3-step tracker no longer stretches
  edge-to-edge (the "too large" look); (2) added Statistics' `@media (min-width:769px)` items-grid
  override (`flex-wrap` + `justify-content:center`, `.item-card` fixed at 288px) so the single
  requirement card sits centered instead of filling the row; (3) pulled the Turnitin
  clearance/similarity report (`$latest_report` / `.report-ready-card`) **out of** the requirement
  card body and surfaced it right below the tracker, **above** the manuscript card (result-first),
  centered at `margin: 16px auto 0; max-width: 480px` — so it's visible on mobile without tapping the
  card open; (4) added the small-screen step-label
  shrink Statistics has (`@media (max-width:600px)`). No workflow/POST/SQL/JS-contract changes.
- **Admin navigation: bottom dock → compact left side-nav; modules go full-view (2026-07-14):** all
  three staff shells (`dashboards/coordinator.php`, `director.php`, `statistician.php`) moved from the
  bottom macOS dock to a **~194px vertical left rail** (`assets/css/portal.css`: `.app-dashboard-frame`
  → row, `.app-dock-navigation` → vertical rail of icon+label rows with `.nav-section-label` group
  headings, account cluster pinned bottom via `.nav-account`; icon-only collapse ≤860px). Labels
  shortened ("Proposal Defense" → "Proposal"). Items grouped into sections per role — Coordinator:
  Overview / Review / Workspace / Account; Director adds a **Statistics** group (Statistics, Payment,
  History); Statistician: **Statistics** (Clearance, Payment, History, Release) / Review / Workspace /
  Account. Kept the `nav-item-btn` class (cross-file selector) and every existing `onclick`.
- **Module windows are now full-view, chrome-free (2026-07-14):** `PortalWindows._build`
  (`assets/js/portal.js`) dropped the 3 macOS traffic-light buttons; windows now open **full-view** to
  the right of the rail (`portal.css` `.app-window { inset:0 }`, `.window-layer` offset by `--nav-w`).
  Follow-up: the slim window titlebar (title + × close) was **removed entirely** too — the module iframe
  now fills the whole area; navigation back is via the side rail (which collapses open windows).
- **Plagiarism: redundant manuscript card removed + Review/Cleared sub-nav (2026-07-14):** the plag
  workflow stays single-stage, but the confusing duplicate **"Research Manuscript (Turnitin Scan)"**
  generic card was removed (`admin_module_dynamic.php` skips item 4 in the requirement loop and hides
  the status tabs for `phase=plag`) — the manuscript is still viewable inside the dedicated decision
  section. That section now splits by a `plag_view` sub-nav mirroring Statistics: **Review** (manuscripts
  still needing a decision, default) and **Cleared** (already-approved + their Turnitin reports), with
  per-view header/badge/empty-state. All three shells gained a **Plagiarism** nav section with **Review**
  (carries the pending badge) + **Cleared** items (`?phase=plag&view=review|cleared`). No workflow change
  — the `plag_accept`/`plag_revise`/`plag_replace` handlers are untouched.
- **Payment queue & history no longer duplicated in the combined Statistics view (2026-07-14):** now
  that Payment and History are their own nav items, the **Pending Payments Acknowledgment** card shows
  only in `view=payments` and **Payment Decision History** only in `view=history` (previously both also
  appeared in the `all`/combined stats view seen by Coordinator/Director). `admin_module_dynamic.php`.

### Added
- **Payment Decision History is its own view + Director access (2026-07-14):**
  `dashboards/admin_module_dynamic.php` gained `view=history` (added to the `$stats_view` allow-list and
  the stats skip-guards) that shows **only** the Payment Decision History card (no pending queue, no
  requirement cards, no status tabs). Both **Statistician and Director** now have Payment + History nav
  items; the payment **Register & Unlock** form renders only for `$role === 'Statistician'` (Director
  sees the queue + a read-only note). The `acknowledge_payment` handler stays statistician-guarded.
- **Group-inbox review layout — one profile → requirement mini-cards inside (2026-07-14):**
  the admin review engine (`dashboards/admin_module_dynamic.php`) was requirement-centric — each
  requirement a collapsible card with a table of every group — so one group's files were scattered
  across many cards. Added a **group-inbox** default: one profile card per student group (avatar, name,
  leader · program, `N to review` + `M files` badges), expandable to show that group's requirements as
  status-colored **mini-cards** (name, status pill, file, Form 008 score for item 14, version history,
  Review button). A **"By group / By requirement" toggle** near the status tabs flips to the original
  view, which is kept verbatim (wrapped in `#viewByRequirement`) — nothing removed. **Applies to
  Proposal, Final, and the Stats checklist** (`$use_group_inbox`); the Stats **payment docs (36/37)** are
  excluded from the inbox since they live in the Pending Payments FIFO queue, and the Stats
  payments/release sub-views and the Plagiarism dedicated review section keep their own layouts. New PHP
  pivot `$submissions_by_group` (reuses the same upload rows, no new query); the filter JS
  (`selectStudentGroup` / `filterByStatus` / `filterTableRows`) is view-aware and drives per-group action
  badges; `openDocumentModal`, `toggleHistory`, and the `verify_upload` review flow are untouched.
  Presentation-only; Coordinator (Pending) + Director (Under Review) + Statistician (Pending) all covered
  by the one shared file.
- **Plagiarism Review & Clearance reskinned to profile cards (2026-07-14):** the dedicated plag review
  section (`admin_module_dynamic.php`, `phase=plag`) swapped its 3-column table for per-group profile
  cards (`.plag-card`) that match the group-inbox look — avatar + group + program · control number +
  status pill in the header, and a body with the manuscript link, latest Turnitin report (+ remarks and
  report history), and a "Decision" panel holding the same Accept / Replace / Request-Revision forms.
  Presentation-only: the `plag_accept` / `plag_replace` / `plag_revise` forms and their fields
  (`report_file`, `notes`, `student_id`, `csrf_token`) are unchanged; query only gained `u.profile_pic`.
- **Payment decision audit trail + history view (2026-07-14):** added a dedicated `payment_logs`
  table (`log_id, form_id, student_id, actor_id, action, control_no, item_id, remarks, created_at`;
  self-healing `CREATE TABLE IF NOT EXISTS` in `admin_module_dynamic.php` since the project has no
  migration runner). Every statistician payment decision is now recorded with **who did it**:
  `acknowledge_payment` (Register & Unlock) writes an `action='accepted'` row with the control number;
  rejecting a payment document (item 36/37 → Revision Requested) writes an `action='rejected'` row with
  the doc id + remarks. A new collapsible **"Payment Decision History"** card in the Statistician's
  payment view lists the latest 30 decisions (green Accepted / red Rejected badge, group, control no or
  doc, actor username, remarks, timestamp). Previously there was no payment-specific audit — only thin
  student-side `activity_logs` with no actor. The accept/reject workflow itself is unchanged; the audit
  INSERTs are additive.
- **Payment Verification redesigned as a first-come-first-served queue (2026-07-14):** the
  Statistician's Payment Verification view (`admin_module_dynamic.php`, `phase=stats&view=payments`)
  replaced the dense 4-column table with a stack of **queue cards** — each has a ticket stub (queue
  position `01, 02…` + days-waiting), the student's avatar, research group, leader · program, and
  email, a status chip (Receipt uploaded / Physical · awaiting), the two viewable payment documents
  (Validated Form 36 / Official Receipt 37, still opening the same `openDocumentModal` review flow),
  and the Register (control-number) input. Queue is ordered `date_submitted ASC` (oldest first).
  Presentation-only: the `acknowledge_payment` form fields (`action_type`, `csrf_token`, `form_id`,
  `student_id`, `control_no`) and the doc-review JS contract are unchanged; the query only gained
  `u.email, u.profile_pic` (read-only). Scoped `.pay-*` CSS added to the module head.
- **Student calendar "View More" → full Events & Holidays list (2026-07-13):** the home
  "Availability & events" mini-calendar now has a `View More` pill (`btn-see-all`) that opens a new
  full-screen sub-page, `dashboards/calendar_all.php`, via the existing `pushView('zoom-calendar', …)`
  overlay mechanism (added a matching `#zoom-calendar` iframe panel to `dashboards/student.php`). The
  page lists every calendar entry as an easy-to-scan card list grouped by month: staff-scheduled
  `calendar_events` (server-side) merged with PH public holidays fetched client-side for the current
  and next year (`date.nager.at`, same source the mini-calendar uses). Filter pills — All / Holidays /
  School events / Available / Unavailable — each with a live count; plus a search box. Entries are
  categorised with the same keyword logic as the mini-calendar (unavailable keywords win over
  "available"), colour-tinted date badges + tags, past dates dimmed, a "Today" chip. Pure-white,
  theme-synced to the parent portal (`rd-portal-theme`) with full light/dark support; matches the
  `activities_all.php` sub-page pattern. No workflow, POST, SQL-write, or field changes.

### Changed
- **Activity Log is now the user's OWN actions only, filtered by module (2026-07-13):**
  `dashboards/activities_all.php` (the home "See All") previously pulled *every* log row, including
  review verdicts (`Document Review - Approved`, `Statistician Evaluation - …`, `Payment
  Acknowledged`, `Plagiarism Clearance …`) — but those are staff→student **notifications**, not the
  student's own activity. The page now queries `status_type = 'info'` only (the self-action log:
  files submitted / uploaded / removed, account changes), matching the home widget's existing intent.
  Renamed to **"My Activity Log"**. The four status pills were replaced with six **module** pills
  with live counts — **All / Proposal / Final / Statistics / Plagiarism / Others** — categorised by a
  server-side `classify_module()` keyword matcher (plagiarism/turnitin → plag; statistical-treatment/
  coded-data/RDC/statistician → stats; capsule/form 008/endorsement/adviser/proposal → proposal;
  manuscript/final/communication-letter → final; else others = account/profile/generic). Each module
  has its own colour + Lucide tag icon. Still the calendar-style card layout (date badge, tag pill,
  month grouping, no timeline line/nodes), pure-white, parent-synced light/dark. Read-only — no
  workflow, POST, SQL-write, or field changes.
- **Notification bell: unread count badge instead of a static dot (2026-07-13):** in
  `dashboards/student.php` the always-on `.notification-ping` red dot was replaced with a
  `.notif-count-badge` number pill. Count = review-outcome logs (`status_type IN ('success','warning')`,
  latest 50) newer than a per-browser "last read" marker (`localStorage['rd-notif-lastread-{uid}']`),
  rendered client-side (caps at `9+`, hidden at 0, pops in on show). Opening the bell drawer marks
  read and clears the badge; it stays cleared across reloads until a newer notification arrives. No
  schema change — reuses the same `activity_logs` rows the drawer already displays.
- **Notification badge wouldn't clear on view — timezone fix (2026-07-13):** the "mark read" write
  used `Date.now()` (client UTC) compared against server-derived `strtotime()` timestamps, so under
  any DB/PHP timezone offset the stored times read as *future* and the count never dropped to 0. Now
  marks read up to `max(notifTimes)` — the newest notification already known — which is timezone-
  agnostic and always clears, while a genuinely newer log later re-shows the badge.
- **Notifications are now clickable → jump to their module (2026-07-13):** each row in the bell
  drawer resolves to a module window and, when it does, becomes clickable (hover chevron affordance,
  `goToNotif()` closes the drawer and `pushView`s the module). Routing is by `uploads.item_id`
  (LEFT JOINed onto the log via `upload_id`; proposal 11–16 → `module_proposal.php`, final 21–27 →
  `module_final.php`, stats 30–37 → `module_statistics.php`, plag 4/40 → `module_plagiarism.php`),
  falling back to title keywords for rows without an upload (e.g. Payment Acknowledged → stats).
  Rows with no resolvable module stay static (default cursor, no chevron).
- **Notifications deep-link to the exact requirement card (2026-07-14):** `goToNotif()` now appends
  `?item=NN` (the log's `uploads.item_id`) to the module URL. Each module
  (`module_proposal/final/statistics/plagiarism.php`) gained `id="req-item-{item_id}"` on its wallet
  cards plus a small on-load handler that reads `?item=`, resets any status filter to "All", scrolls
  the card into view (`scrollIntoView` center), and flashes a purple highlight ring
  (`.deeplink-flash`, ~2.8s, presentation-only). Graceful fallbacks: notifications without an item id
  just open the module top; in Statistics, cards are server-filtered by workflow step, so a target
  outside the current step isn't in the DOM and the jump no-ops (module opens normally). No workflow,
  POST, SQL-write, or field changes — purely scroll + highlight.
- **"See All" / "View More" pills go text-only on mobile (2026-07-13):** added a
  `@media (max-width: 768px)` override for `.btn-see-all` in `dashboards/student.php` — the violet
  glow fill and hover fill are dropped so only the violet label shows on phones (desktop keeps the
  filled pill).
- **Statistics module: centered wrapping card grid on web + Step 2 RDC download relocated
  (2026-07-13):** on `dashboards/module_statistics.php`, the wallet requirement cards on web used the
  shared `dashboard-cards.css` horizontal scroller (fixed-width row + prev/next `<>` nav) inherited
  from Module Proposal, which looked unbalanced for statistics' small, variable card counts (1 card in
  step 1, 2 in step 2, 5 in step 3). Replaced it, at `@media (min-width:769px)`, with a centered
  wrapping flex grid of fixed 288px cards (`flex-wrap:wrap; justify-content:center`, overriding the
  shared `nowrap`/`overflow-x:auto`): 3 cards per row in the 960 column, so step 3's five cards fall
  into a balanced 3-over-2 stack and a lone 1–2 card step sits centered. Removed the `.items-scroll-nav`
  markup and its scroller JS from this module (still used by Module Proposal). Separately, the
  "Download RDC Form No. 011" button moved from a full-width row at the bottom of the Step 2
  (`Phase 2: Form Download`) alert to a compact `.step-alert-dl` chip pinned top-right of the alert
  heading (`flex-wrap` so it drops below the title on very narrow screens), shrinking the oversized
  Step 2 card. Presentation-only — no workflow, POST, SQL, or field changes.

### Fixed
- **Statistics Review progress ring jumped around non-monotonically (2026-07-13):** on
  `dashboards/student.php` the `$stats_progress_map` assigned percentages by *color bucket*, not
  workflow order, so the ring lurched backwards as a group advanced — uploading coded data
  (`Phase 1: Coded Data Review`) showed **75%**, then approval → payment (`Phase 2: Form Download` =
  20%, `Phase 5: Registered` = 40%) dropped it to 40%. Replaced the map with `$stats_phase_meta`, a
  single phase→[pct, chip, footer] table whose percentages are **monotonic** in real workflow order
  (0 → 15 → 30 → 45 → 55 → 80 → 92 → 100) and **hold steady** on a rejection/revision (the ring never
  regresses; only the chip flips red). Crucially the chip colour, ring-fill colour and footer text/tone
  are now derived from the **phase itself**, not from `%` thresholds — previously the two were coupled,
  which is why an honest mid-workflow number would have painted "Registered" (control number issued,
  just needs more uploads) red like a revision. Removed the now-redundant `$stats_next_full/short_map`
  (folded into the meta table). Ring/chip/footer for the other three stage cards are unchanged.
- **Statistics Review card permanently stuck at 0% (2026-07-11):** `dashboards/student.php` derived
  the card's ring/chip off `uploads` for `item_id = 3` — but that item doesn't exist in
  `checklist_items` and has zero rows in `uploads` for any user, ever, so the card could never
  show progress no matter how far a group actually advanced through the real 7-phase statistics
  workflow (`form_stat_treatment.status`, the same source `module_statistics.php` already used).
  Replaced with a `getStatsPhaseStatus()`/phase-to-percent map read off `form_stat_treatment`,
  with values chosen to land in the correct existing chip/ring color bucket (e.g. "Registered" —
  control number issued, student just needs to upload more — is progress, not a rejection, so it
  must not fall in the red revision band even though it's mid-workflow). Footer next-step text is
  now phase-aware too ("Submit payment proof", "Upload remaining requirements", etc.) instead of a
  generic placeholder.

### Changed
- **Student dashboard: milestone card redesign + mobile dock pill (2026-07-11):** the 4 milestone
  cards on `dashboards/student.php` (`.stage-card-tab`) were reworked from a centered vertical
  stack to a horizontal layout — a faint background ghost numeral (1-4) per card, status chip
  top-right, a 64px progress ring next to the title/meta line, and a bottom action pill showing
  context-aware next-step text ("Download clearing form", "Upload Endorsement Letter", "Under
  review", etc.). The old per-stage tonal purple icon tile was removed entirely (was colliding
  visually with the 3-dot info button). Default cards now carry a low-saturation purple tint
  (`color-mix(in srgb, var(--active-accent) 4%, white)`, ~10% in dark mode) mirroring the existing
  green tint completed cards get. The footer action pill and meta line are both hidden entirely
  until a card has real progress (was showing "Upload X to begin" / "Not started yet" on brand-new
  accounts, which read as noise). Footer text renders two variants — a full/specific one for
  tablet+desktop and a short/generic one for phone (`Download form`, `Upload next file`, etc.) —
  so it never wraps to 2 lines or gets ellipsis-truncated, since phone card widths can't fit the
  longest checklist item names on one line. Also fixed a real bug in the process: the progress ring
  had a hardcoded inline `style="stroke:#7c3aed"` that silently overrode the CSS `.cp-fill.approved`
  class, so completed cards never actually turned green. The `$final_locked`/`$stats_locked`/
  `$plag_locked` gating (all 4 cards are unlocked by design) and its dead `card-locked-blur`/
  `lock-shake` CSS+JS were removed. Separately, the mobile bottom dock (`≤640px`) got an
  "expressive pill" treatment — the active tab morphs from a 50px circle to a 78px filled pill
  (`var(--active-accent)` background, white icon) instead of the previous static tonal highlight.
  The "Recent Group Activities" timeline card was also capped at a fixed max-height with an
  internal scroll (previously grew unbounded with a group's full upload history, sometimes 15+
  items tall) — "See All" still opens the untruncated list.
- **Proposal module: filter pills replace progress ring, side-scroll cards, history panel polish
  (2026-07-11):** `dashboards/module_proposal.php` swapped the circular progress-ring widget for a
  segmented All/Needs you/Cleared filter-pill control (counts computed server-side, excluding the
  Form 008 cascade items 13/15/16 from "Needs you" since they clear automatically). Web-only: the
  filter tabs moved above the Next Step banner, centered and matched to the banner's width so the
  hero area balances against the hint card the way `module_statistics.php`'s top nav does; mobile's
  pill placement/DOM order was left untouched. The status-color legend ("No Upload / Under Review /
  Revision / Accepted") was removed as redundant with the cards' own status chips. Requirement cards
  05-06 (previously isolated below the 4-card grid) now sit in the same horizontally-scrollable row
  as 01-04 with prev/next buttons and a partial "peek" of the next card, web-only — the carousel
  conversion uses `flex: 0 0 calc(25% - 12px)` specifically to reproduce the old grid's stretched
  card width, since a naive fixed-px flex-basis had shrunk cards 01-04 from their original size.
  Fixed a mobile-only bug where filtering (Needs you/Cleared) left the wallet cards' cascading
  `margin-top: -85px` stacking applied to a now-hidden first card, causing visible overlap — the
  filter JS now resets `margin-top` on the first *visible* card, not just the DOM first-child. The
  history panel's desktop backdrop went from fully transparent (`rgba(0,0,0,0)`, which read as a
  rendering glitch on open) to a real `rgba(15,23,42,.32)` tint with `backdrop-filter: blur(6px)`,
  and the panel itself gained a curvy `border-radius: 24px 0 0 24px` + soft drop shadow to match the
  Apple-style visual language — scoped inside the existing `@media (min-width: 769px)` block, so
  mobile's flush bottom-sheet history panel is unaffected.

### Added
- **Plagiarism module: single-stage Accept/Revise + versioned Turnitin reports (2026-07-10):**
  redesigned how a plagiarism submission gets decided. Previously the generic per-phase review
  modal let staff set `Approved` directly (no Turnitin report required), while a separate "Report
  Release" card independently released a report — two conflicting decision surfaces, and
  acceptance was reachable before any report existed. Now: the generic modal is disabled for
  item 4 (`admin_module_dynamic.php` rejects a direct Approve on `target_item_id=4`, and the
  per-item card shows a plain "View Manuscript" link instead of the Approve/Revision-Requested
  dropdown); the two-stage Coordinator-forwards/Director-finalizes flow is collapsed into a single
  stage — any of Coordinator/Director/Statistician can directly **Accept Submission** or
  **Request Revision**, both of which now *require* uploading the Turnitin similarity report
  (previously revision only carried a text remark). A new **Replace Report** action (neutral/
  outline styling, distinct from Accept's green) corrects an already-uploaded report without
  touching the Approved status. The report itself is stored as a normal, versioned row in the
  same `uploads` table every other document uses (new `item_id = 40`, "Turnitin Similarity
  Report") instead of a single overwritable column — so report history now works exactly like
  manuscript history (a "Report History" panel on both the admin and student side shows every
  past version, nothing is silently overwritten). The manuscript checklist item was also renamed
  from "Plagiarism-Free Manuscript" to "Research Manuscript (Turnitin Scan)" (the old name
  presumed an outcome before review), and the student-facing Pending label changed to "In Review".
  Migration: `database/migrations/2026-07-10_plagiarism_report_history.sql`.
- **Real Plagiarism module lifecycle (2026-07-10):** the Plagiarism module (`item_id = 4`) was
  previously a bare checklist entry with no catalog row (filenames silently fell back to
  `"Document"`), no control number, and an "Approved" state that linked to a hardcoded static
  `stats.pdf`. It now has: a `PLAG-{DEPT}-{COURSE}-{SEQ}` control number auto-generated on first
  upload and reused across re-uploads (new `plagiarism_checks` table + migration
  `database/migrations/2026-07-10_plagiarism_module.sql`, also backfills the missing `forms`/
  `checklist_items` catalog rows); a real staff-side "Report Release — Plagiarism Clearance" card
  in `admin_module_dynamic.php` letting Coordinator/Director/Statistician release (or replace) the
  actual per-student report with an optional note, or send an Approved item back for revision;
  Statistician now has full-parity access to `phase=plag` (acts as first-pass reviewer or
  finalizer depending on the item's current stage) with a new nav button in `statistician.php`;
  and `module_plagiarism.php` was rewritten onto the same wallet-card / history-panel / modal-
  upload UI (`dashboard-cards.css`/`.js`) already used by Statistics and Proposal, replacing the
  old plain upload list. The unrelated Proposal-phase payment check that `module_plagiarism.php`
  was borrowing (`approvals WHERE form_id=1`) was removed — plagiarism clearance has no payment
  step. Also added a 3-step tracker (Upload Manuscript → Under Review → Clearance Report, matching
  Statistics' tracker visual minus the payment step) and removed the page's redundant `<h2>` title
  (module title already lives in the parent window chrome, same as Statistics).
- **Plagiarism control-number bug fix (2026-07-10):** the school-code half of `PLAG-{DEPT}-...`
  was wired to the wrong reference implementation — it checked `users.department` for the literal
  substring `"ISAP"`, which never matches (that column stores full institution names like
  "International School of Asia and the Pacific"), so every control number silently defaulted to
  `MCNP` regardless of the student's actual program. Fixed to mirror the real, live logic already
  used for STAT control numbers (`admin_module_dynamic.php`'s `acknowledge_payment` action):
  derive the school from a keyword whitelist against `users.program`, and the course code from
  stopword-stripped initials of the same field. Also fixed: sending an Approved-and-released
  plagiarism item back to revision (from the new Report Release card) now clears the previously
  released `result_file`/`release_notes` — otherwise the stale report would silently reappear as
  "ready" once the resubmitted manuscript is re-approved, without staff ever reviewing the new
  version. Also switched the module's default accent off the old blue (`#1e40af`) onto the same
  neutral slate (`#0f172a`) Statistics already uses.
- **Group 360 Profile (2026-07-09):** the master dashboard's Group Explorer card now has a "View
  Full Profile" button opening a full dossier — team roster (`research_group_members`), all 4
  milestone statuses + days-in-current-stage, per-phase completion (Proposal/Final/Stats/Plag)
  computed with the exact same latest-upload-per-item formula `admin_module_dynamic.php` already
  uses for its own progress bars (verified to match on a sample group), and the group's full
  submission timeline. New read-only endpoint `dashboards/group_profile_data.php`; no writes, no
  workflow changes.
- **Analytics & Export Dashboard (2026-07-09):** new `dashboards/analytics.php`, linked from a new
  "Analytics" dock button (Director/Coordinator/Statistician). Cohort-wide phase pass/fail rates,
  current bottleneck by phase, turnaround time (first submission → final approval, for groups that
  fully cleared a phase), stage-aging distribution, department breakdown, and a 30-day activity
  sparkline — all plain read-only SQL, no chart library. Includes a CSV export
  (`analytics.php?export=csv`) of the full 52-group roster + statuses.

### Fixed
- **"Profile information updated" reappearing on reload (2026-07-10):** `director.php` /
  `coordinator.php` / `statistician.php` processed the Settings "Save" POST inline without
  redirecting afterward, so any page refresh silently resubmitted the form and re-triggered the
  success message. Both the profile-update and change-password handlers now redirect-after-POST
  (flash the message via `$_SESSION['flash_message']`, `Location:` redirect, `exit()`), so the
  message shows exactly once and a reload no longer resubmits anything. Verified end-to-end via a
  simulated POST → GET → GET sequence: message present on the first reload, gone on the second.
- **Sticky success banner + redundant clock (2026-07-09):** the "Profile information updated
  successfully" banner (and other post-action success banners on the master dashboard and on the
  Proposal/Final/Stats/Plagiarism review modules) was a static server-rendered `<div>` with no
  dismiss logic, so it stayed on screen until the page was refreshed. Both banners now auto-fade
  and remove themselves ~3.9s after render. Also removed the redundant live clock widget from
  `admin_module_dynamic.php` (shown on every review-module page) — the clock now only appears on
  the master dashboard/overview header, as intended.
- **Staff dashboard follow-ups (code review, 2026-07-09):** `PortalWindows.close()` and
  `collapseAll()` (`assets/js/portal.js`) hid/reset a module window on a bare 300ms timeout;
  re-opening within that window blanked or hid it. Both now guard on `.shown` (a re-open aborts
  the teardown), matching `minimize()`. The master-dashboard "needs your review" count
  (`_master_overview.php`) is now scoped to the same item ranges as the dock badges
  (11-16/21-27/30-35/4) so the banner always equals the badge sum instead of counting Pending
  items no review module surfaces.

### Changed
- **Plagiarism module — mobile bottom-sheet + white sheet parity (2026-07-10)**: finished the student-facing
  mobile parity so opening the Plagiarism module now animates up as a white rounded draggable bottom-sheet
  over the dashboard (drag-to-dismiss), identical to Proposal/Statistics. In `dashboards/student.php` the
  three `@media(max-width:768px)` sheet CSS blocks and the drag-handle JS array now also include `#zoom-plag`
  (were `#zoom-proposal, #zoom-stats`). In `dashboards/module_plagiarism.php` the default page background was
  switched from the light-blue `--bg-beige: #f3f7fa` to `#ffffff` so the sheet reads pure white. No workflow
  or card logic touched.
- **Staff dashboards — Apple/macOS redesign + shared design system + data fixes (2026-07-09)**: the four
  staff surfaces (`dashboards/director.php`, `coordinator.php`, `statistician.php`, the shared approval
  engine `dashboards/admin_module_dynamic.php`, and the `dashboards/_master_overview.php` partial) carried
  ~700 lines of duplicated inline CSS each. All of it was extracted into a single **shared design system** —
  new `assets/css/portal.css` and `assets/js/portal.js` — and the look was rebuilt in a **true Apple/macOS
  direction**:
  - **Pure-white surfaces, system font** (`-apple-system`/SF Pro; the `Cinzel` serif was dropped from
    headings), soft realistic card shadows, generous whitespace.
  - **Themes collapsed to Light + Dark only** (one `rd-portal-theme` key for all roles, replacing the seven
    colored themes and coordinator's divergent key; legacy theme values migrate to Light; theme is
    postMessage-synced into module iframes). Settings now uses a Light/Dark segmented toggle.
  - **Navbar replaced with a bottom macOS dock** (magnifying icons, tooltips, always-visible count badges,
    an avatar menu) mirroring the student dashboard, so notification counts are never hidden.
  - **Modules now open as macOS windows** with traffic lights — red = close (resets the iframe), yellow =
    minimize back to the dock (state preserved), green = expand full — via a new `PortalWindows` manager,
    replacing the single reused iframe overlay so admins can multitask between modules.
  - **Master dashboard is action-first**: a "needs your review" banner, a premium widget clock (time + date
    + live dot), compact stat widgets, then recent activity.
  - **Data fixes (read-only re-sourcing, no workflow change):** the Interactive Student/Group Explorer and
    the "Research Groups" stat were re-sourced from the full student-leader population (LEFT JOIN to each
    group's latest approval) instead of the `approvals` table — so the explorer now lists **all 52 groups**
    (previously only the 4 with an approvals row; 12 groups with uploads were invisible) and is searchable
    across all of them. The misleading ISAP/MCNP partial tiles were replaced with accurate **Research Groups
    / Needs Action / Approved Documents** counts (role-aware, latest-upload-deduped).
  - The v1 review drawer (right-side, solid non-blur scrim replacing the glassmorphic `#docModal`) is kept
    and re-toned to Light/Dark.
  - Accent colour is a **muted brand purple** (`#6c5fa0` light / `#b3a6e6` dark) — desaturated so it reads
    as brand without overpowering. The **Recent Activity** feed was rebuilt: real Lucide status icons +
    colour-coded chips (Approved/Revision/Update) replacing emoji, a corrected status mapping
    (`success`/`warning` were previously never colour-matched), and the "See all" control moved from the
    bottom to the section header. The header clock now appears **only** on the master dashboard (removed
    from the Settings/Statistics headers).
  **Presentation + read-only data-source changes only — no approval/workflow logic, POST handlers, form
  field names, or JS review contracts (`openDocumentModal`, `runForm008Tally`/`runForm011Tally`, AI
  pre-score, CSRF) changed.** Verified: all surfaces render with no PHP fatals/warnings; headless-Chrome
  screenshots of the dock, a module window (traffic lights), and Light + Dark; and a DB check confirming the
  explorer/stat now report 52 groups.
- **Statistics module UI parity with Proposal (2026-07-08)**: `dashboards/module_statistics.php`'s
  deliverable cards were rebuilt on the shared Apple-Wallet card system in `assets/css/dashboard-cards.css`
  (the same one Module Proposal uses) instead of a self-contained inline card design. Cards now use the
  colour-coded status system (purple `no-upload` · orange `review` · red `revision` · green `approved`),
  the overlapping mobile stack, and the draggable full-screen sheet, and reuse the shared `.status-pill`,
  `.btn-history` History button and `.apple-file-card` View/Delete placements. The conflicting inline
  `.item-card`/`.items-grid`/`.card-*` CSS was removed so the shared classes apply; the step tracker,
  step-alert instructions, upload modal and completion card are unchanged. Added the shared download-preview
  modal and raised the upload modal's `z-index` above the wallet layer. Fixed the page background from the
  light-blue `--bg: #f3f7fa` to `#ffffff` on the default/blue theme. Follow-up polish to match Proposal's
  Apple aesthetic: default `--teal` accent changed from blue (`#1e40af`) to neutral slate (`#0f172a`) so
  headings/chips are no longer blue; the current-step alert cards were flattened from the heavy
  bordered/blurred/left-bar look into clean pure-white cards with a soft shadow and 20px radius (matching
  the Proposal progress / next-step widgets), keeping all instruction text; and the verbose in-page hero
  title/subtext ("Statistical Treatment Module" / "Follow the consultation pipeline…") plus the "Required
  Uploads" section heading were removed (the title already lives in the overlay chrome), leaving only a
  right-aligned control-number chip. Locked (Phase 7) cards keep `pointer-events` so their sheet still
  opens read-only. Finally, `dashboards/student.php`'s **mobile bottom-sheet** presentation (slide-up from
  the bottom, 85vh, 24px rounded top, drag-to-dismiss handle) was extended from `#zoom-proposal` to
  `#zoom-stats` so opening the Statistics module animates up as a white draggable sheet over the dashboard,
  identical to Proposal — the three `@media (max-width:768px)` sheet CSS blocks now also select `#zoom-stats`
  and the drag-handle JS iterates `['zoom-proposal','zoom-stats']`.

### Added
- **Working group invites (2026-07-08)**: Adding research-group members was split across three
  disconnected mechanisms and only one reliably worked. `auth/register.php`'s "Research Group Mates"
  step wrote free-text names into a `research_group_members` table that nothing ever read back — those
  "added" members silently vanished. `dashboards/members.php`'s "Invite Member" search only worked if the
  invitee already had a registered account, which a brand-new teammate usually doesn't. Introduced a
  `group_invites` table (see `database/migrations/2026-07-08_group_invites.sql`) and shared
  `config/group_helpers.php` as the single mechanism behind both entry points: inviting an email with no
  account now creates a pending invite and emails the invitee (`send_invite_email`), and **every** new
  registration (`auth/register.php`) checks for a matching pending invite and auto-links regardless of
  which role the invitee picked at Step 1 — needed because "Research Leader" is the default radio choice,
  so an invited teammate who just clicks through the wizard would otherwise register as an unlinked
  leader instead of joining the inviting leader's group. `members.php` gained a "Pending Invites" section
  with a cancel action. Registration Step 4's pill input now collects teammate emails instead of
  free-text names. `research_group_members` is left in place (unused) rather than dropped.
- **Staff dashboard overhaul (2026-07-08)**: Consolidated the Master Dashboard (stat cards, Interactive
  Group Explorer, Activity Logs) that had drifted into three hand-copied implementations across
  `coordinator.php`, `director.php`, and `statistician.php` into the single shared `_master_overview.php`
  partial, now role-aware (Statistician sees its own stat cards; Coordinator/Director see ISAP/MCNP task
  counts).
  - Statistician's Statistics nav split into three dedicated tabs — **Statistics Clearance**,
    **Payment Verification**, **Release Results** — via a new `admin_module_dynamic.php?phase=stats&view=`
    param; Coordinator/Director's existing combined view is unchanged.
  - **Select Student Group** (admin module sidebar) and **Interactive Student/Group Explorer** (Master
    Dashboard) now sort by most-recent `activity_logs` activity and show only the top 7 / top 4 by
    default, with a "Show all" expander; search still reaches every group.
  - Panel Settings split into separate **Profile Information** / **Change Password** forms (previously one
    combined save), each with its own CSRF token; avatar picker is now presets-only (no more free-text
    DiceBear URL field).
  - Institutional Calendar (Coordinator/Director) redesigned from a plain table to a card list matching
    the Activity Log's visual style, with a proper empty state and dimmed past events.
  - Sidebar logo now points at the local `mcnp-isap.jpg` instead of a remote ISAP CDN URL; staff dashboard
    typography (font weights, fallback stack) now matches `student.php` exactly.

### Fixed
- **`research_group_name` drift between leader and members (2026-07-08)**: `dashboards/profile.php`
  already computed `$effective_user_id` (the leader's `user_id`) for shared data, but its profile-save
  `UPDATE` wrote `research_group_name` to the editing user's own row regardless — so a member renaming
  the group only changed their own row, leaving it out of sync with the leader's (and every other
  member's) copy. Group name now always writes to the leader's row; personal fields (username, avatar,
  banner) still write to the editor's own row.
- **Statistics control number / badge accuracy bugs (2026-07-08)**:
  - The official control number generated on payment registration (`acknowledge_payment` in
    `admin_module_dynamic.php`) built its course segment from the student's `department` field (the
    institution's full name, e.g. "International School of Asia and the Pacific") instead of their actual
    `program`/course — producing gibberish like `STAT-2026-ISAP-ISOAATP-...` instead of
    `STAT-2026-MCNP-NURSING-...`. Now derives the course code from `program`, stripping degree/connector
    stopwords (BS, OF, IN, ...) and using the full course name when a single significant word remains
    (e.g. "Nursing"), else initials (e.g. "Information Technology" → "IT"); the statistician-entered
    sequence number segment is unchanged.
  - Requirement item cards' black "Total Submitted" badge always showed the lifetime count across every
    status, even while viewing the **Action Needed** tab — so an item with 9 already-Approved submissions
    and 0 pending still showed "9 Total Submitted" with an empty list underneath. The badge (and the red
    "Action Needed" badge) now recompute from the rows actually visible in the active tab: Action Needed
    shows the current pending count, Revision History / Approved Archive show their own accurate counts,
    and All Submissions keeps the true lifetime total.
- **Dashboard accuracy bugs (2026-07-08)**:
  - `statistician.php`'s pending-count query had no latest-per-upload dedup (unlike the equivalent
    Coordinator/Director queries), so superseded re-uploads inflated its nav badge and stat card; also
    mixed checklist items with payment-document items into one number. Split into three correctly-deduped
    counts (checklist / payments / release).
  - Master Dashboard's ISAP/MCNP pending-task counts had the same missing-dedup bug; fixed with the same
    `MAX(uploaded_at)`-per-item join already used elsewhere.
  - Settings-save success/error message was set and then immediately overwritten by an unconditional
    `$message = "";` right after (coordinator.php, director.php), so the confirmation alert never actually
    displayed. statistician.php didn't have this bug; all three now initialize `$message` once, up front.
  - Added CSRF token checks to the Profile/Password settings forms and Institutional Calendar add/delete
    forms, which previously had none (mirrors the token check already used elsewhere in
    `admin_module_dynamic.php`).
- **Statistics Module — 7-phase sequential workflow (2026-07-07)**: Redesigned `module_statistics.php` +
  `stat_upload_handler.php` + `admin_module_dynamic.php?phase=stats` from a parallel-upload module into a
  phase-gated wizard mirroring the Research Office's real Statistical Treatment process:
  - **Phase 1** Initial Coded Data (item 30) → Statistician verifies (approve / reject with remarks).
  - **Phase 2** Student downloads RDC Form No. 011 (`dashboards/downloads/rdc.jpg`), pays at the Finance
    Office (payment itself stays outside the system), then uploads the **Validated Form (item 36)** and
    **Official Receipt (item 37)** — both accept photos/scans. **Hybrid path**: a physically presented
    receipt can be registered by the Statistician without any upload.
  - **Phase 4** Payment verification: rejecting a payment document reverts the group to Phase 2 so the
    upload cards unlock for resubmission (previously deadlocked).
  - **Phase 5** Official registration: Statistician types only the sequence number; the system builds
    `STAT-{YEAR}-{SCHOOL}-{DEPT}-{SEQ}`, auto-approves pending payment docs, and renames the **latest**
    uploaded files to the official control-number format (older reviewed versions stay as history).
  - **Phase 6** Remaining requirements (items 31–35) with per-item review; any rejection flags the whole
    request `Phase 6: Revision Requested` so the student sees the revision alert.
  - **Phase 7** All approved → `Statistical Treatment` (processing) → Statistician **uploads final
    results** (new `upload_stat_result` action; writes the previously-dead `result_file` column and
    `date_released`) → `Completed`; the student's *Download Final Results* button now actually works.
  - Statistician queue additions: *Pending Payments* now lists Phase 2 + Phase 4 groups with links to the
    uploaded payment documents, and a new *Ready for Release* card lists groups awaiting results.
- **Statistics submissions now follow the proposal lifecycle (2026-07-07)**: one active submission per
  requirement, supersede-on-reupload (INSERT + prune pending, replacing the old update-in-place that
  destroyed history), reviewed-only Version History panels, and Pending-only delete. Deleting a pending
  draft also rewinds the group's phase (e.g. removing the pending Coded Data returns the request to
  `Phase 1: Pending Coded Data`) so nothing lingers in the Statistician's queue. CSRF protection added to
  the stats upload, delete, review, registration, and release actions.
- **Migration**: `database/migrations/2026-07-07_stats_module_phases.sql` (idempotent) — status column
  enum → `VARCHAR(255)`, seeds `forms` row 3 + checklist items 30–37, marks items 36/37 as image uploads,
  remaps all legacy statuses to the 7-phase model, and adds `activity_logs.upload_id` for fresh imports.
  Both schema dumps (`digital_research.sql`, `databasev2.sql`) updated to match; the ad-hoc
  `scratch_db.php` / `scratch_migrate.php` scripts were folded into the migration and removed.
- **"What's Next" banner (proposal module, 2026-07-07)**: A desktop-only (≥1025px) smart banner below the
  hero widgets that reads the current statuses and surfaces the student's single next action ("Upload your
  Endorsement", "Revision needed on …", "… is under review", or "All requirements cleared"). Uses existing
  data — no DB changes — and is `display:none` below 1025px so mobile/tablet flow is unchanged.
- **Proposal Submission Lifecycle (2026-07-07)**: Hardened the whole upload → review → history flow:
  - Delete is now a guarded action — only an un-reviewed `Pending` draft can be removed; reviewed
    versions are locked into a read-only history (`module_proposal.php`, `upload_handler.php`).
  - **Supersede-on-reupload**: uploading a new file automatically removes the previous un-reviewed
    `Pending` draft for that item (files pruned only when no surviving row shares the path), so at most
    one Pending version exists and ghost drafts no longer accumulate.
  - **Audit link**: added `activity_logs.upload_id` (nullable FK, `ON DELETE SET NULL`) so log entries
    reconcile to a specific upload.
  - **CSRF protection** for the proposal upload/withdraw and admin proposal-review actions.
  - **Admin archive workflow**: status tabs restructured to *Action Needed* (default) / *Revision History*
    / *Approved Archive* / *All*, so approved submissions leave the active queue but stay viewable.
- **Premium Messaging UI (v2.0)**: Overhauled the entire `message.php` module to feature a dual-pane responsive layout.
- **Interactive Emoji Reactions**: Implemented persistent emoji reactions on message bubbles, triggering via desktop hover or mobile double-tap. Added `reaction` columns to `group_messages` and `staff_messages` tables.
- **Dynamic Active States**: Pinned and recent groups now visually indicate online status and highlight elegantly when active.
- **README.md, INSTALLATION.md, SYSTEM_ARCHITECTURE.md**: Comprehensive Markdown documentation generated.
- **Favicon**: Created `favicon.svg` featuring Quill the Mascot and linked it across all authentication pages.

### Changed
- **Desktop full-view module framing (`student.php`, 2026-07-07)**: On web (≥1025px) the zoom-overlay pages
  (Proposal, Final, Statistics, Plagiarism, Activities, Chat, Profile, Members, Settings) are now edge-to-edge
  full-view instead of a bordered/rounded/shadowed floating card — no header bar or divider line. The floating
  circular back button moved into a 60px transparent top strip so it no longer overlaps each page's own title.
  Scoped entirely to ≥1025px; tablet (769–1024) and mobile (≤768) framing is unchanged.
- **Compact proposal module on web (`dashboard-cards.css`, ≥1025px)**: Slimmed the hero widgets (the oversized
  "0 / 6" progress card) and tightened the requirement cards' padding/sizes so all six requirements fit without
  scrolling. Mobile wallet and tablet grid untouched.
- **Desktop module header removed (`student.php`, 2026-07-07)**: The zoom-overlay modules (Proposal,
  Final, Statistics, Plagiarism, Activities, Chat, Profile, Members, Settings) no longer show the
  desktop header bar with its divider line above the iframe. The back button survives as a small
  floating circular control (top-left, card background + subtle border/shadow) so desktop users can
  still exit an overlay while the dock is covered. `.nav-back-wrapper` stays `display: none` at
  ≤1024px, so mobile and tablet layouts are byte-for-byte unchanged.
- **Coordinator = Director sibling (`coordinator.php`)**: The Coordinator sidebar now exposes all review
  modules like the Director — **Proposal Defense, Final Manuscript, Statistics Clearance, Plagiarism Verify**
  (the shared `admin_module_dynamic.php` already permitted coordinators on every phase) — each with a
  latest-per-student pending badge. The Coordinator **Master Dashboard** was replaced with the Director-style
  overview (stats grid, interactive group explorer, recent-activity feed + logs modal) via a new shared
  partial `dashboards/_master_overview.php`. Approval semantics are unchanged for now
  (Coordinator → Under Review); the two roles will be refactored/differentiated later.
- **Admin Review UI (`admin_module_dynamic.php`)**: Consolidated the per-submission review into a single
  **View & Evaluate** entry point — removed the redundant inline status dropdown + **Update** button. The
  Document column now shows a truncated filename (full name on hover) so long titles no longer break the card.
- **Revision styling (`dashboard-cards.css`)**: Requirement cards marked *Revision Requested* now render red
  (matching the badge/border/icon) instead of the amber shared with pending/under-review.
- **Notification badges**: Sidebar nav counts (`coordinator.php`, `director.php`) now count only the latest
  submission per group per item (director proposal count now spans items 11–16, added a Statistics badge),
  so superseded drafts no longer inflate the numbers.
- **Splash Screen Loader (`login.php`)**: Increased the animation duration to 4.2 seconds to give the cinematic intro more breathing room.
- **Mascot Animation Delay**: Delayed Quill's automatic wave on the login screen by 1.2 seconds so he stands still briefly before interacting.
- **Mascot Wave Mechanics (`mascot.css`)**: Adjusted the translation values in the `@keyframes quillWaveHigh` to ensure Quill's wing remains physically attached to his shoulder while waving.
- **Mascot SVG Architecture (`mascot.php`)**: Grouped body and limb vectors into proper `<g>` tags and applied explicit `transform-origin` pivots (e.g., planting the right foot at `74px 130px`) to prevent floating/detaching limbs during CSS animations.
- **Page Titles**: Standardized all browser tab `<title>` tags in the `auth/` directory to use the `iSubmit` brand name.

### Fixed
- **Statistics review queue mis-filed resubmissions (2026-07-08)**: on the Statistician page the status
  tabs decided a row's bucket by reading the *first* `.status-pill` in the row — but the "Show History"
  box sits in the first table cell, so once a submission had any history the filter read the older
  *rejected* pill and hid the resubmitted **Pending** row under *Revision History* instead of *Action
  Needed*. Rows now carry a `data-status` attribute read directly by `filterTableRows`.
- **Statistics payment-verification actions invisible (2026-07-08)**: `filterTableRows` looped over
  *every* `<table>`, including *Pending Payments Acknowledgment*, whose pills ("Receipt Uploaded — Verify",
  "Awaiting Docs / Physical Receipt") match no status tab — so under the default *Action Needed* filter the
  whole payment table was `display:none` and the section looked empty/actionless. Filtering is now scoped to
  `table.submissions-table` only, leaving the payment and release queues always visible.
- **Statistics payment docs are now verifiable in place (2026-07-08)**: the *Pending Payments* section gained
  a per-document **View & Verify** button that opens the evaluation console (image/scan viewer + approve /
  reject-with-remarks) so the whole payment workflow — view, verify, reject, register — lives in one section.
  Items 36/37 no longer render as duplicate generic checklist cards. Rejecting a receipt still reverts the
  group to Phase 2 for resubmission.
- **Statistics Version History was undiscoverable (2026-07-08)**: the student card exposed history only as a
  bare clock icon crammed into the action row. It's now a labeled *Version History (n)* pill under each
  requirement, matching the Module Proposal card; the action row keeps just Upload/Re-upload and (for pending
  drafts) Delete.
- **Dark-mode readability (2026-07-07)**: The framed pages (settings, profile, members, activities, message)
  use a transparent `<body>` and relied on the overlay iframe container for their background — but that
  container was hardcoded white, so in dark mode their light text sat on white and was invisible. Added a
  `body.theme-dark .overlay-iframe-container` dark background. Also filled in `activities_all.php`'s missing
  dark `--border-light`/`--text-lighter` vars and removed a malformed duplicate theme block in `profile.php`.
- **Light-ward theme-toggle animation (`student.php`)**: The circular View-Transitions ripple only animated
  when switching *to* dark; switching back to light snapped instantly. The z-index override targeted
  `.theme-dark::view-transition-*`, but the theme class is on `<body>` while the pseudo lives on the root
  (`<html>`), so it never matched. Now driven by a temporary `html.vt-reverse` class set by the toggle, so
  the reverse (shrink-away) animation plays both directions.
- **Broken current-submission delete**: the student current-card delete button was sending `upload_id=0`
  (the query never selected `upload_id`); it now deletes the correct upload.
- **Item 14 "Approve" doing nothing**: the 22 Form-008 rubric radios were `required` while hidden inside
  collapsed accordions, so a native submit failed silently after a rubric-less rejection. Rubric completeness
  is now validated in JS with a clear message that expands the incomplete section.
- **Preview → Cancel navigation**: closing the document preview no longer collapses the mobile requirement
  card; it returns to the same card (`dashboard-cards.js` `closeDlModal`).
- **Removal log said "a file"**: the item name is now looked up before the row is deleted.
- **Staff Messaging Syntax Error**: Removed a stray curly brace in `staff_message_handler.php` that was blocking communication between Group Leaders and Admins.
- **Splash Mascot Floating Foot**: Added missing `transform-origin` to the left foot inline style in the splash screen SVG to stop it from rotating wildly off-screen.

---
*Note: Older history is tracked via Git commits.*
