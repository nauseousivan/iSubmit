# CLAUDE.md — MCNP-ISAP "Research Digital" (iSubmit)

Durable project facts so sessions act without re-exploring. Keep this **lean** — it
loads every session. Add a line only when it saves more future tokens than it costs.

## What this is
School research submission + approval portal. Students upload research documents; staff
(Coordinator → Director, plus Statistician) review them through a multi-stage workflow.

## Stack & run (no build step)
- **PHP 8.2 + MySQL on XAMPP.** Vanilla JS, Lucide icons, Google Fonts. Served at
  `http://localhost/Research_Digital/`.
- DB `digital_research`, user `root`, **no password** → `config/db.php` (exposes PDO `$pdo`,
  starts the session, sets `$_SESSION['csrf_token']`). Mail: `config/mail.php` `sendSystemEmail()`.
- CLI available: `php` (`/c/xampp/php/php`) and headless Chrome
  (`C:/Program Files/Google/Chrome/Application/chrome.exe`).

## Where things live
- `auth/` login/register/logout. `dashboards/` is the app:
  - `student.php` — student portal (own macOS dock + `pushView`/zoom modules; **4600+ lines, grep it, don't read whole**).
  - `director.php` / `coordinator.php` / `statistician.php` — **staff shells** (near-identical; change one, mirror to the others).
  - `admin_module_dynamic.php` — the **shared approval engine**, opened per `?phase=proposal|final|stats|plag` (+ statistician `&view=checklist|payments|release`).
  - `_master_overview.php` — shared staff home partial (stat widgets, group explorer, activity feed).
    **Director has moved to the 2026-09-29 redesign** (`_staff_home.php` + `staff_home_lib.php` +
    `assets/css/staff_home.css`, `body.sh-shell`): a 7-item rail (Home + 4 phases + Insights +
    Workspace), each phase a HUB of step cards that call `openOverlay()` like the old buttons.
    `sh_model()` is the ONE list of destinations — rail badges, cards and the live refresh all read
    it; add a step there, never as markup. The hero holds the ONE live mascot (`assets/mascot/mascot.php`,
    bound by id) — never include it twice, never call `coverEyes()` outside a password field. **ALL staff shells are on it** (Focal Person too, 2026-10-01);
    `_master_overview.php` is included by none of them. Statistician's rail is Home · Statistics ·
    Plagiarism · Workspace (`sh_model($role)` / `sh_workflow_keys($role)` branch on the role).
    **Everything on the home is `aa_scope_sql`'d** (`$sh_sc_sql`: groups, activity, board, History
    panel) and `group_profile_data.php` refuses out-of-scope ids — so is the Coordinator's document
    badge, in BOTH `coordinator.php` and `nav_badge_counts.php`. On the Statistics hub, History is
    the right-hand PANEL (not a card) and Recent lives inside the History screen (`.lh-recent`).
- **Design systems (two, don't mix them):**
  - `assets/css/portal.css` + `assets/js/portal.js` = **STAFF** system: Light/Dark, left side-nav, `PortalWindows` (module windows w/ traffic lights).
    **The theme is painted from the FIRST frame** (portal.js `early()`: `<html>` canvas + color-scheme at once,
    body classes via MutationObserver as the parser creates `<body>`) — never move it back to DOMContentLoaded
    only, that was the dark-mode white flicker. `.window-frame` stays `color-scheme: normal` for the same reason.
    A refresh REOPENS the open window (sessionStorage `rd-portal-open`, inner URL included);
    anything that returns to the Dashboard must go through `collapseAll()`/`_home()`, which forget it.
    **portal.css holds the ONE staff palette** — the student lavender theme (ink `#4c1d95`,
    accent `#6d28d9`; dark is purple-tinted, not grey). Change it there, never per page, and
    do not re-add a per-page copy. **It is CRLF** — LF-anchored patches match nothing and fail
    silently. The iSubmit mascot (`assets/images/mascot.png`) + wordmark + "Research
    Digitalization" sub-line is the lockup in all three shells (fits the 194px rail — measure,
    do not eyeball). Activity-feed icons follow the EVENT via `mo_activity_icon()` in
    `_master_overview.php`, mirrored as `moIcon()` in its JS because the live poller rebuilds
    the same markup — change one, change both. `moIcon()` must stay at SHARED script scope —
    the "See all" modal (`filterModalLogs`) calls it too, and hiding it inside another
    function silently empties that modal. Hardcoded colours in `_master_overview.php` survive
    the theme: use tokens or dark mode renders light text on a white panel.
  - `assets/css/theme.css` = **AUTH/student** language (Material 3, purple). `assets/css/dashboard-cards.css` = student Apple-Wallet cards.

## Domain facts (non-obvious)
- Roles: `Student`, `Research Coordinator`, `Research Director`, `Statistician`, `Focal Person`. Plus **`Admin`** (2026-09-30,
  `admin.php`): approves the staff accounts Director/Coordinator create (they start `users.account_status = pending`),
  maintains staff + students (`admin_students*.php`), and is **VIEW ONLY** in the workflow — `config/db.php` refuses her POSTs
  outside the account handlers. **Sign-in is `account_status`, never `is_verified`** (the OTP flips that back); for staff
  keep the two in step. The Director never sees or manages an Admin row.
  **System tools (2026-10-01)** live in `config/system.php` (loaded by db.php): `rd_setting()` reads `system_settings`
  (slots/day, lockout, registration, maintenance, announcement) — `DEFENSE_SLOTS_PER_SCHOOL_PER_DAY` is now defined from it.
  db.php also enforces maintenance mode and `must_change_password` on every request. Any new Admin POST handler must be
  added to db.php's Admin allowlist or it is refused.
  **QR (2026-10-01):** Admin posters = `qr_links` + root `q.php` short links (`admin_qr.php`). The statistician DESK QR
  (`stat_desk_lib.php`/`stat_desk.php`) only ever CONFIRMS via the existing `bulk_verify` — never approve on a GET, never
  add a second approval path. Its token is bound to the exact pending upload ids (sorted).
  `student.php?module=X` obeys the card locks (`$unlock_*`) — keep it that way; printed phase-card QRs rely on it.
- **SCOPE HAS TWO SHAPES** (`admin_accounts_lib.php`):
  `Research Coordinator` → **one SCHOOL**, her own `users.department`.
  `Focal Person` → a **LIST of COURSES**, `focal_scope` rows.
  MCNP and ISAP have **one coordinator each, on separate accounts** — never a shared login.
  Director + Secretary are unscoped and see both. Hierarchy: Director(1) · Secretary(1) ·
  Coordinator(2, one per school) · focal persons under whichever coordinator owns their school.
  Both shapes **fail closed**: no school / no courses = sees nothing, never everything.
  `fp_school_scope()` is the one place that says which roles are school-bound.
- **The decision log (`view=history`, and the plag archive) renders ONE MONTH, never the whole
  log.** A month index (`$hist_months`, a GROUP BY over `$hist_where`) is always the page; only
  `$hist_ym` loads entries. **Paging alone does not bound a growing log** — do not "fix" the
  height by changing `$hist_per_page`. Search narrows the MONTHS too, so a hit in one month
  collapses the index to it. The month bar is THREE segments (ok / sent back / other): two
  segments made a month of reminders read as a month of rejections. It is FLAT and full width
  like the views beside it - not a bordered panel. Four designs were tried;
  `.lg-*` is the live one and the rest were deleted.
- **"PROGRESS" (`?phase=stats&view=pipeline` - the ROUTE kept the old name) leads the Statistics
  section in ALL THREE staff shells.** In Director/Coordinator that section sits between Proposal
  and Final (the office sequence: Final waits on stats), never after Plagiarism. Items read **Progress · Requirements · History in all three shells**; the
  history log is institution-scoped via `aa_scope_sql()` in `$hist_where`. The nav says Progress, not Pipeline, and Requirements, not
  Clearance - plain words, not build metaphors. **Band labels are role-aware**: every action on
  that screen is Statistician-only, so Coordinator and Director see "With the statistician /
  Waiting on the group" and a read-only note per row. Never label it "Your move" for them. One row per
  request carrying a six-node spine (Coded · Pay · Reg · Reqs · Treat · Done), banded by WHOSE
  MOVE it is. `stats_pipeline_lib.php` owns the model: `sp_position()` maps a status to a stage
  AND an owner ('you' / 'them' / 'done'). **The two "sent back" statuses are not stages** —
  they are the same stage with a verdict, so they keep their position and carry a flag; do not
  give them a column. Ages run from the LAST THING THAT HAPPENED, never `date_submitted`.
  **`sp_control_prefix()` is the single source of the control-number prefix** — the composer
  previews with it and `acknowledge_payment` mints with it. Never inline a second copy: a
  preview that drifts from the mint is worse than no preview. **Registration has TWO doors**
  (Progress composer; approving receipt 37 in the drawer) and ONE body, `sp_register_payment()`.
  The old Payment and Release
  **The Payment and Release desks are RETIRED** (2026-09-27) - markup, queries, CSS and JS all
  deleted. `view=payments` and `view=release` REDIRECT to the pipeline rather than 404: the
  Director dock and old bookmarks still point at them. `phase=plag&view=payments` is a different
  desk and is untouched. The Reg stage shows the two payment documents inline.
  **Treat is a VERDICT** (`upload_stat_result` + `verdict=release|revise`): revise reopens the ticked
  deliverables (31-35) and drops to `Phase 6: Revision Requested` - no new status, the checklist loop
  returns it to Treat. **Stats is paid ONCE: a send-back must never touch the payment phases.**
  Release REPLACES `statistician_remarks` with its note. Released rows are correctable
  (`update_stat_result`).
- **Statistician is INSTITUTION-SCOPED (2026-09-26).** Three accounts: an admin who sees both and
  one per institution. The admin is marked by **`AA_ALL_INSTITUTIONS` (`users.department = "ALL"`)**,
  never by an empty department — empty still means SEES NOTHING, as it does for a Coordinator, or
  one blank column would mean opposite things for two roles. `aa_school_scope_of()` resolves it and
  is the ONLY place that grants the escape; a Coordinator never gets it. Scoping is enforced in
  BOTH places as always: `aa_scope_sql()` hides, `aa_may_touch_student()` refuses. **`acknowledge_payment`,
  `upload_stat_result` and park/nudge/unpark take `student_id` from the POST** — they resolve the
  owner from `form_id` and guard; do not add a fourth that skips it. **Known gap:**
  The Director sets it in `admin_accounts.php`: picking Statistician reveals the same
  institution field a Coordinator gets, plus an **Admin - both institutions** option only she
  may take (hidden in JS for a Coordinator, REFUSED in the handler - hiding is courtesy, the
  handler is the rule). Her row carries the scope chip the same way. **Never pass the ALL
  marker to `aa_school_tag()`** - it falls back to MCNP for anything it does not recognise and
  would label the admin as one institution. **Any list of institutions must read from STUDENT
  rows** (`role = 'Student'`, as `df_schools()` and `aa_all_courses()` do) — a bare
  `DISTINCT department FROM users` now picks the marker up and offers "ALL" as an
  institution. **Known gap:**
  `rd_plag_badge_counts()` is NOT scoped (Notify's `plag_release` still uses it); the Progress badge
  `plag_progress` IS, via `pp_badge()`. The plag decision handlers do not call `aa_may_touch_student()`.
- **PLAGIARISM = THREE STEP SCREENS + AN AT-A-GLANCE BOARD (2026-09-29, Claude Design concept).**
  `view=manuscript|payment|result` (`.pz-*`, list left / group right, one decision each) and, on the hub,
  "Every scan, at a glance" — the Statistics `sb-board` with 4 columns. Hub cards stay PLAIN sh-cards (no
  group/you sub-lines — the user asked). `plag_pipeline_lib.php` (`pp_rows()` scoped, `pp_step()` files a
  status). Old `review|cleared|payments|progress` redirect; `payments&arch=1` is the History log and stays.
  A one-screen "Progress" twin was tried and REJECTED by the user — do not bring it back.
  Its hub side is a History PANEL + Recent inside the History screen — identical to Statistics'. `pp_control_prefix()` is the ONE source of the PLAG prefix (mint + preview).
  **Every Turnitin revision is a new PAID round** (office rule): the re-upload goes to Manuscript
  Review, then pay again. A round is DERIVED from the latest `revision` row in
  `plagiarism_payment_logs` — no column. Only that round's 41/42 count (`pp_current_pay_docs()`),
  or round 1's Approved receipt makes round 2 look paid. The control number is kept across rounds.
  The student card % is phase-driven (`$plag_phase_meta`), never item 4's status.
- **Focal Person** (added 2026-09-19) checks the requirements of **only the courses assigned to
  her** (`focal_scope`, a LIST — some cover several courses) and sets defense dates for her
  sections. Scope is enforced by `aa_scope_sql()` (hides rows) **and** `aa_may_touch_student()`
  (refuses writes) in `admin_accounts_lib.php` — use both, a hidden row is not a protected row.
  Staff accounts are created in `admin_accounts.php` by the **Director OR a Coordinator** —
  a Coordinator may create and manage **only Focal Persons whose every course is in her own
  institution** (`aa_creatable_roles()`, `aa_courses_for()`, `aa_may_manage()`,
  `aa_staff_for()`). Enforce in BOTH places: the screen hides, the handler refuses.
  `set_school` stays Director-only. Disabling is `is_verified = 0`, there is no delete.
  That screen is built for a LONG list — rows not cards, search + role pills, actions behind a
  Manage toggle, identity fields before the 21-checkbox course picker, and NO stat cards or
  to-do banner above it (a course with no focal person is marked by a dot in the picker, where
  it is actionable). It wears the STUDENT lavender palette (portal.css) — declare it on
  `body, body.theme-light` or portal.css wins. Keep it that way.
  **Her portal is the STAFF SHELL (2026-10-01)**: `focal.php` = `_staff_home.php` with
  `sh_model('Focal Person')` — rail Home · Proposal · Final · Reports · Workspace. Each phase hub leads with
  "Every section, at a glance" (`fp_board()`, one chip per room, 5 columns) + a By course panel.
  `fp_counts()` paints the first render AND answers `nav_badge_counts.php` — keep it one function.
  Her working screens are `focal_desk.php` (module windows; `shOpenDesk(url)` routes any desk URL to
  its card's window). Workspace = Sections (rooms + Unassigned tray, the `*_section` handlers) +
  the shared Settings. The bell is hers only, in the shell's `$sh_bell` slot. **Requirements is ONE
  screen** (tree + room rows, `.rq-*`) then the group review (`.gr-*`, one action bar under the file) —
  and **Defense dates is the same layout** (tree + that room's who/which-day screen). Both use
  `fp_side()` and open on the room that needs her; the course→year→room card walk is deleted.
  Back is a NAMED pill (`fp_hub_pill()` → `window.top.shShow()` closes the window onto the hub).
  Defense dates (`.dt-*`): ticket strip of the room's dates in motion (`$tickets`, every live batch +
  loose bookings) → who / which day (FIXED 42-cell calendar) → dark send bar; never offers a `fp_booked()` group.
  Reports (rail item): `focal_reports.php`, all tabs + the 3D Visualize read ONE `fr_dataset()` client-side —
  add a number there, never a second query in the page. The 3D view is a hand-rolled canvas (no library).
  **Analytics + holograms (2026-10-01):** Admin = `admin_analytics.php` / `admin_visualize.php` on
  `an_admin_dataset()` (ACCOUNTS, help desk, sign-ins — never papers, the user said that is not her job);
  office = `office_analytics.php` / `office_visualize.php` on `an_office_dataset()` (scoped by `aa_scope_sql`).
  Each page pair reads ONE dataset client-side — add a number to the lib, never a query in the page.
  Charts = `insights.js`; 3D = `holo.js` (engine) + `holo_app.js` (pop-outs, wires, month, adjustable
  telemetry) + `holo.css`. **Holograms are AGGREGATED: roles → institutions → courses → phases/help — never a
  mark per group or student** (the user asked, for scale); people appear only inside a pop-out.
  `analytics.php` is a redirect. The Focal map predates holo.js and keeps its own copy.
  **Say "Visualizer", never "hologram"** in anything a person reads (the user asked). It wears the
  VIEWER'S theme: portal.css/portal.js loaded, `holo.css` on portal tokens only, canvas colours from
  `HoloApp.palette()` — never hardcode a stage colour. Periods are `YYYY-MM` or `YYYY`: filter with
  `HoloApp.inPeriod()`, never `===`. `an_months()` is continuous (gap months included).
  **PRINTING = `assets/css/print.css`** (+ each page's own small `@media print`): light palette forced, letterhead
  `.rp-head`, page numbers, `.rp-hint` / `.rp-screen` hidden, `body.rp-wide` = landscape. Any new page with a Print
  button links print.css, adds the letterhead + the `beforeprint` script, and is checked with `--print-to-pdf`.
  The strip KEEPS past dates (the concept shows them) — do not move them off the room screen; the
  user rejected that. The archive is Workspace → Archive (`?view=archive`, Proposal/Final ×
  Requirements/Schedule, ONE MONTH at a time via a month index — it only grows).
  portal.css paints body `background-color: var(--canvas) !important` — tint a page via `--canvas`. `body.admin-module`
  padding is zeroed in the desk (portal.css 28px stacks with `.fp-wrap`). **lucide replaces
  `<i data-lucide>` with `<svg>`, so size icons with `i, svg`: an `i`-only rule silently stops
  applying once they render and they blow up to 24x24.**
  The desk must not import theme.css. The date picker is the **student month
  calendar** ported from `components/defense_scheduler.php` (`$fpCal` from `df_month_cells()`,
  scoped to the section institution) — do not go back to a flat list of day tiles. The desk breadcrumb starts once
  a course is open (the rail is the way home). Drill-down is **course -> year -> section
  -> group -> documents** (`fp_years()`, `?year=`): rooms are named just "A"/"B", so without
  the year step a course lists "A" six times. Group cards and the document screen carry the
  **"View names" roster** (`group_roster_lib.php` — leader first, with School ID Number and
  email) and **when the group last submitted** (`fp_last_submitted()` + `fp_when()`), so neither
  question needs the card opened. Endorsing a section is **two steps side by side**
  (who / which day) with a **sticky commit bar** carrying the button — it used to be one tall
  column with the action below the fold. portal.css styles no `:disabled`, so any disabled
  button needs a local rule or it looks live. Where a date has got to shows on the hub
  board, in the three-step panel inside the section, and in the bell — there is deliberately no
  fourth list of it.
  The bell feed `fp_feed()` is **derived** from `defense_batches` + `uploads`, never stored —
  do not add a notifications table for it; unread is a localStorage marker, as on the student
  portal. Its `title`/`body` are HTML (fp_section_name() emits an entity) and escape their own
  user input, so the drawer prints them raw.
- `uploads.verification_status`: `Pending` → `Approved` / `Revision Requested`. **Single-stage —
  there is no Coordinator→Director chain.** Coordinator and Director hold the same power and either
  can decide anything; an approval writes `Approved` whoever makes it. `Under Review` is **legacy
  only** (rows written before 2026-08-27, when a coordinator's approve was downgraded to queue it
  for the director) — still treated as actionable so those rows can be cleared, never newly written.
  The real processor is the **Secretary** — **Lea Paddayuman, `user_id` 15,
  `secretarymcnpisap@gmail.com`** (created 2026-09-25). Her row carries role **`Research Director`**
  deliberately: that role IS the Secretary in code (`fp_can_act()` gives it the
  `Confirmed → Published` signature, `fp_step_owner()` prints "Secretary"). There is no separate
  `Secretary` role. **`Research Director` is held by TWO accounts** (2026-09-25): Lea as Secretary
  and **Ryan Fontanilla, `user_id` 266, `directormcnpisap@gmail.com`**, the actual Director —
  so BOTH can sign the `Confirmed` leg. The office accepted that; it is the known cost of not
  having a real `Secretary` role, not a bug to "fix" by deleting one of them. Staff log
  in as her, so review UI wording stays role-neutral. **Exception: item 4**
  (plagiarism manuscript) is single-stage — `Pending` → `Approved`/`Revision Requested` directly, no
  `Under Review`, decided by any of Coordinator/Director/Statistician, and only via the dedicated
  Accept/Revise/Replace actions in `admin_module_dynamic.php` (the generic review modal is blocked
  from approving item 4 — server-side guard, not just UI).
- **Scheduling (rewritten 2026-09-19). Students do NOT pick dates.** The Focal Person consolidates
  a whole **section** onto one date and endorses it; **Coordinator confirms → Secretary finalises**
  (`fp_can_act()` — **the one place Coordinator and Director are NOT interchangeable**: one
  signature each, or a Director acting as Secretary would sign both and the chain would be
  theatre. Do not "fix" this by letting the Director confirm.)
  (`defense_batches`, one row per section+date, joined to `defense_bookings.batch_id`). Only then
  does the student see a date — until then it is "proposed". `focal_lib.php` (reads) +
  `focal_handler.php` (writes) + `focal.php` (her screen) + `admin_batch_review.php` (the two
  approval queues). **Checklist = one axis, BY GROUP** (2026-09-29): "By requirement" was deleted,
  not hidden — do not bring it back. The group list is PAGED (`CK_PAGE` = 15, "Show more"); the
  archive tabs only grow. **Approvals queue = the 2026-09-29 design (`.ap-*`)**: one big card for the
  soonest section on your desk, the rest compact; "Further up the chain" rows carry Nudge
  (`nudge_batch`, 6h/section). "Log slots and publish" stays a HAND-OFF to Scheduling — never a
  direct POST. **Badges count only the viewer's OWN step**: Approvals = `fp_approvals_badge()`,
  Schedule = `fp_schedule_badge()` (Confirmed, loggable). Never count `Date Selected` bookings — a
  booking takes that on the focal endorse, while it is still the Coordinator's move.
  **The queue screen shows only what is WAITING** — sections not tabs (yours,
  sent back, someone else's), a live `waiting 5h` counter, and the in-flight chain leg animated.
  **Published is not on it**: it is finished business and the only part that grows without limit,
  so it lives behind `?view=published` (same cards, Coming up / Already held, paged 12), and
  **Sent back behind `?view=returned`** (those sit on the Focal Person's desk, not yours). Both are
  reached from ONE pill group, right-aligned: Waiting · Sent back · Published. **No phase filter
  there** — the nav section already chose the phase. Do not put either back on the working page. A passed date is `bad` (red) only while unsigned; in the archive it is `past` (dim). **Ages come from `UNIX_TIMESTAMP()` in `fp_chain_queue()`, never `strtotime()`**
  — PHP is Europe/Berlin and MySQL is 6h ahead, so parsing a stored DATETIME in PHP puts it in
  the future and every age reads as zero.
  `select_date`, **`schedule` and `reject`** in `defense_handler.php` are **retired and refused**
  (all three belong to the student-picks-a-date flow; `schedule` minted a slot and published a
  date carrying none of the three signatures — `cancel` stays, for pre-chain bookings with no
  batch); the student calendar is gone from `components/defense_scheduler.php`.
  **`admin_defense_schedule.php` (Secretary) is two tabs: To log · Record**, one segmented pill at
  the HEAD OF THE LEFT COLUMN (never right-aligned above the page — that lands it on the calendar,
  which it does not control) and hidden inside an opened section. The
  record's search + All/Upcoming/Past in its OWN header, list inline, no modal. The calendar stays
  on both tabs; Upcoming hides on Record, being the same rows. Calendar amber means **partly
  booked**, not "requested" — nothing is requested any more.
  **Both calendars render a FIXED 42 cells** (six weeks, neighbouring months dimmed): a 5-week
  month beside a 6-week one changed the card height and the column jumped on every page. Each
  booked day carries one tick PER INSTITUTION, never one combined bar — one full + one empty is
  not a half-full day. Planner chips are one line, three max, then `+N more`: a research title is
  fifteen words and a day can hold twenty groups.
  **Empty states on both staff queue screens use the `.nx` block** —
  `components/quill_empty.php` (96px, `.nx-sm` = 60px in the sidebar) + heading + one line +
  an optional `.nx-go` link onward. The bird is an `<img>` of the eagle (`$quill_pose`, default
  `sleeping`; see THE MASCOT below). JS-built empty states clone the rendered one (`#nxBird` is the
  guaranteed source; `NX_EMPTY(title, body, pose)`); never paste the artwork inline. Never leave a
  "nothing here" as a bare icon and grey text: it reads as a screen that failed to load. Keep the
  two screens identical — staff move between them all day.
  **Style it with `--line` / `--ink` / `--muted`**: it once used `var(--border,#e5e7eb)`,
  `--text-primary`, `--text-muted`, none of which portal.css defines, so 29 rules silently
  rendered their hardcoded LIGHT fallback in dark mode. Never reintroduce those three names.
  **This chain applies to the DATE only** — requirement approval is still single-stage, and a Focal
  Person's Approve writes `Approved` immediately. Do not add per-requirement sign-off. The
  Checklist SHOWS the one signature it has (`uploads.verified_by/verified_at`, joined as
  `verified_name/verified_role/verified_pfp`): a corner mark plus name + capacity + day inside the
  card. Rows older than that column have no signer and must read as unknown, never as the current
  reviewer. **No card on that screen wears a coloured edge rail** — tint + pill only; two of the
  old ones were `::before`, not `border-left`, so grep for both when checking. The workspace
  requirement card is `.ws-card-doc` — **scope every new rule to it**: `.ws-card`/`.ws-card-top`/
  `.ws-card-name`/`.ws-card-meta` are shared by FOUR other renderers, one nesting `.ws-card-title`
  inside `.ws-card-name`. `.ws-card-name` must hold the requirement name and nothing else —
  `wsConfirmBulk()` reads its textContent into the irreversible approve dialog. On an approved
  card the leading tile IS the signer (face + tick); no corner chip, it fights the status pill.
  **`ws_ago()` takes an EPOCH** (`UNIX_TIMESTAMP(col)` from the query). Its string branch is the
  legacy path and is 6h wrong for the same PHP-Berlin/MySQL reason as `fp_chain_queue()`; three
  callers (payments, first upload, first cleared) still use it and each needs its own
  UNIX_TIMESTAMP column before it can move. Cinzel is removed from this page — headings use
  `var(--font-ui)` at 800 with -.02em tracking.
  **There is no queue ordinal on the Checklist** — it was an ARRIVAL index (never moved when a
  group was served, so a bounced group kept looking urgent) and it never reset. Exactly one group
  is marked `LONGEST WAITING`: oldest `first_upload` among those with `action_count > 0`. Do not
  reintroduce a running number. `ws_ordinal()` is still right for the TURNITIN queue, which is a
  real FIFO service queue.
- **ONE stage per defense phase (2026-09-25).** Both phases collect EVERY requirement in ONE grid,
  reviewed in `admin_module_dynamic.php`. Neither has an application stage any more. Proposal is
  **7** (17, 11, 12, 14, 13, 15, 16), Final is **7** (20, 22, 23, 24, 25, 26, 27) — same flow, same
  UI: `module_final.php` mirrors `module_proposal.php`. Form 002 (20) and the Minutes (22) are
  ordinary requirement cards, NOT a phase gating the rest. **`df_application_items()`,**
  **`df_phase2_items()` and `df_phase2_reviewed_items()` return `[]` for BOTH phases** — kept, not
  deleted, because four callers ask them what to render and empty is the answer that turns each one
  off (`admin_defense_schedule.php`, `ds_request_card.php`, `defense_handler.php`,
  `upload_handler.php`). **`df_phase1_items()` is the live list**, and it is also
  `fp_ready_items()` — so it is what decides when the Focal Person may endorse a group.
  `components/defense_scheduler.php` is **status-only** for both phases: students never pick a
  date, the Focal Person schedules the section (see the scheduling chain above).
  **CARD ORDER AND GATING (2026-09-25, confirmed with the office): Proposal → Statistics → Final →
  Plagiarism.** Statistics opens when the **proposal booking is `Scheduled`** (which implies its
  requirements cleared — the Focal Person cannot endorse otherwise). **Final waits for
  STATISTICS**: `form_stat_treatment.status = 'Phase 7: Completed'`, the one terminal state of that
  ladder — you cannot defend Chapter 4 without the numbers. Use `$lock_reason_final` for that card,
  never `$lock_reason_next`, which names the proposal wait and is wrong there. `dashboard_billboards.php`
  gates `$bb_final_can` on `$unlock_final` too — there are legacy `defense_bookings` rows from the
  retired student-picks-a-date flow whose groups never saw a statistician, and without it the strip
  shouts "book your final defense" over a locked card. Plagiarism opens when the **office releases
  the manuscript** (`plagiarism_checks.released_at` via `df_manuscript_released()`) — the defense
  happening face-to-face is invisible to the system, so a passed date is necessary but not sufficient.
- **The PUBLISHED schedule has ONE source: `dashboards/schedule_lib.php` `sch_published()`** (batches the Secretary signed,
  `Scheduled`; SECTIONS only, never names/titles). It feeds the student mini-calendar, `calendar_all.php` (month view),
  the public `schedule.php` and the front-page strip, all drawn by `assets/js/defense_calendar.js` + `.css`.
- **Defense slots: `DEFENSE_SLOTS_PER_SCHOOL_PER_DAY = 10`, PER INSTITUTION.** The office calls
  MCNP and ISAP **institutions**, never "schools" — say institution in all user-facing copy (the
  identifiers still read `school`, deliberately: renaming them is churn no reader benefits from,
  and "School ID Number" is `users.student_number`, a different thing). They run their
  own panels, so the two pools are independent — a day holds 10 + 10 = **20**, and MCNP filling up
  must never close that day to ISAP. **Shared across both phases within a school** (a proposal and
  a final come out of the same 10). Any capacity question needs a school:
  `df_taken_dates(..., $school)`, `df_next_slot($pdo, $date, $school)`, `df_month_cells(..., $school)`.
  Pass null only to list every booking on a date (the Secretary's combined view) — `left` is then
  **null**, because two pools have no single answer. Which school: a student's `df_user_school()`,
  a section's `sections.department`. `df_schools($pdo)` reads the list from data — never hardcode
  the two names.
  **`slot_no` runs 1..10 within a school**, so one date legitimately has two "slot 3"s — always show
  a slot number with its school. Written once when the office confirms and never recomputed (a
  student may already hold the ticket). No clock times — groups are heard in slot order.
  The pool is contested by **~21 focal persons**, so capacity is counted inside a transaction: lock
  the whole DATE (`SELECT ... FOR UPDATE`, no join — the range lock over `idx_booking_date_status`
  also blocks an INSERT into the gap), then count that school's rows behind it. `df_next_slot()`
  counts **held** slots (`Date Selected`) as well as `Scheduled` — counting only the latter handed
  mid-chain batches duplicate slot numbers.
- **`sections`** = one room (`BSIT 4-A`): department(school) + program(course) + year_level + label.
  `users.section_id` is **inherited from the group leader** like `research_group_name`. Students
  PICK from the office's list, never type it — free text fragments one section into six. Groups with
  `section_id IS NULL` show in the focal person's **Unassigned tray** (that is the backfill path;
  there is no migration script).
- `checklist_items.item_id`: proposal requirements **17, 11, 12, 14, 13, 15, 16** — SEVEN, in that
  card order (Capsule/Form008 = **14**, approving it cascades-clears 13/15/16; **12** the signed
  endorsement and **17** the Application for Research Defense (Form 002) are ordinary
  requirements now, not separate application steps). **17 is the PROPOSAL's Form 002, 20 is
  FINAL's — never share one row**: uploads are keyed by item_id, so one row would let a proposal
  approval clear Final's phase-1 gate. **BLANK FORMS are the Admin's** (Admin → System → Forms): every student
  download goes through `rd_form_url($pdo, $slot, $uid)` (`config/forms.php`: the ONE catalogue of slots + shipped
  defaults per MCNP/ISAP; `form_templates` = her uploads, versioned). A new form = a new catalogue slot, never a
  hardcoded link. Final
  requirements **20, 22, 23, 24, 25, 26, 27** — SEVEN, all in one grid (20 = Form 002 and 22 =
  Minutes are ordinary requirements now, NOT an application stage; 21 retired), stats 30–37,
  plagiarism manuscript = **4**, plagiarism Turnitin report = **40**
  (staff-uploaded only, never through the student upload flow — one new `uploads` row per Accept/
  Revise/Replace decision, giving the report full version history same as any other document).
  Plagiarism control numbers (`PLAG-{SCHOOL}-{COURSE}-{SEQ}`) live in their own `plagiarism_checks`
  table (one row per group), not on `uploads`. Statistician progress lives in `form_stat_treatment.status` (`Phase 1..7`).
- `users.student_number` = the student's **School ID Number** (UI label), format `YYYY-NNNNN`
  (regex `/^\d{4}-\d{4,6}$/`), `VARCHAR(20) NULL UNIQUE`. Required at registration (Step 3),
  editable in `profile.php`. **Personal — never inherited from the leader** the way
  `research_group_name`/`department`/`program` are, and always written to the caller's own row.
  Writers must normalise `''` → `NULL` or two blank saves collide on `uniq_student_number`.
  Not named `school_id`: `department` already holds the school (MCNP vs. ISAP).
- A "research group" = a `users` row with `role='Student'`, non-empty `research_group_name`, `leader_id IS NULL` (~52). `approvals` has ≤1 workflow row per group — **don't** source group lists from `approvals` (incomplete); use the leader-group query.
- Theme key: `localStorage['rd-portal-theme']` = `theme-light` | `theme-dark`.
- **The payment desk (`?phase=stats&view=payments`) is THREE LANES, not a queue.**
  `Ready to register` (documents in — the only real service queue, ordered by when the RECEIPT
  landed) · `Waiting on the student` (Phase 2, their move) · `Parked`, as **lane pills over one
  list of ROWS** (`.pl-row`), not cards — same call `admin_accounts.php` made, for the same
  reason. Two document dots carry the status by colour so a row triages without being opened.
  **`hidden` is only a UA `display:none`** — `.pl-body` and `.nx` both set `display:flex`, so
  `.pl-body[hidden], .pl-row[hidden], #plEmpty[hidden] { display:none !important }` is what stops
  the desk rendering fully expanded with the empty state over a full list. It used to be one FIFO of `Phase 2 + Phase 4` by `date_submitted` and it
  could not drain: **`Phase 2: Form Download` means two opposite things** — paid cash at the
  office (register them) and never paid (nobody should act) — and `date_submitted` is when they
  downloaded the form, not when they paid, so a rejected payment (which is pushed back to Phase 2)
  reappeared at the FRONT. Do not re-merge the lanes and do not sort by `date_submitted`.
  **Park/nudge are DERIVED from `payment_logs`** (latest `parked`/`unparked` row per form wins) —
  no table, no ALTER, and they never touch `form_stat_treatment.status`. **Paying beats parking**
  in the bucketing, which is what makes "they come back when they upload a receipt" true.
  Registering a walk-in from the waiting lane stays reachable behind a `<details>` — cash at the
  office leaves no upload. The dock badges in `statistician.php` + `nav_badge_counts.php` count
  the Ready lane's definition; keep all three in step or the badge lies again.
- **Watch for dead vs. live reference code**: this codebase sometimes has two implementations of the
  same logic where only one is actually wired up (e.g. `stat_form_handler.php`'s control-number
  auto-increment is dead — overwritten later — while `admin_module_dynamic.php`'s `acknowledge_payment`
  action is the real one). Before copying a pattern, grep for where the *displayed* value actually
  comes from, don't assume the first match you find is live.

## THE MASCOT (2026-10-05): the iSubmit eagle
- One SVG per pose in `assets/mascot/eagle/` (all share viewBox `-30 -24 330 310`, so a swap never jumps;
  `plain.svg` = the no-diploma lockup crop). `assets/images/mascot.png` is the plain eagle re-drawn at the old
  360x420 (transparent) so every PNG use — logos, posters, print letterheads — follows it.
- **The MAIN eagle is `plain` (no diploma)** — the resting face everywhere, incl. the loading screen. **Never use the
  `hi` pose (the "Hi!" bubble)** — the user rejected it; the file stays but nothing shows it. `plain` is drawn INLINE by
  `assets/mascot/eagle_svg.php` (`eagle_plain_svg($prefix)`, named groups wingL/wingR/body/footL/cap/eyes) so its
  feathers move like the old bird: a flap at rest, the left wing waved high on a tiptoe on tap/hover/after load.
- Poses mean things — keep to the office's guide: **covering-eyes**
  typing a password · **peeking** show-password on · **happy** approved / saved / submitted · **celebrating**
  everything cleared, defense published · **thinking** nothing here yet / no match · **sleeping** nothing waiting
  on you, office closed · **oops** errors, sent back, failed · **wink** tips, you're all caught up.
- The LIVE one is `assets/mascot/mascot.php` + `mascot.js` (one per page, `#quill-container`, `window.Quill`):
  same API as the old bird (`idle()`, `coverEyes()`, `wave()`) plus `pose()`, `happy()`, `oops()`; 'plain' shows the
  inline SVG, any other pose the `<img>` (CSS on `data-pose`); it wraps
  the page's global `togglePassword()` for peeking and reads `.mat-alert-error/.msg-error/.ac-alert.bad`
  (oops until you type) and the success twins (happy) on load. `$mascot_idle` sets the resting pose (default plain).

## Rules
- **Presentation changes must not touch** workflow logic, POST handlers, SQL writes, form field
  names, or the review JS contract: `openDocumentModal`, `runForm008Tally`/`runForm011Tally`,
  and the `csrf_token` field. `verification_status` values are fixed strings. (AI Pre-Score was
  removed 2026-07-10 — it shipped a hardcoded OpenAI key in `ai_prescore_handler.php`; that key
  is still in git history from the initial commit and needs rotating on OpenAI's side.)
- Keep class `nav-item-btn` on staff nav/dock buttons — `_master_overview.php` selects it cross-file.
- Files are UTF-8 and start with `<?php`; **never write a BOM** (breaks `header()` redirects). For
  large in-place edits use the Edit tool or a PHP-CLI regex (raw bytes) — **not** PowerShell
  `Set-Content`/`Out-File` (adds a BOM).
- Every page with a `<head>` carries the 3 favicon links (`favicon.svg` / `favicon-32.png` / `apple-touch-icon.png`,
  `?v=2`) right after `<meta charset>` — a new page without them shows a blank tab icon.
- After code changes, update `docs/CHANGELOG.md` under `## [Unreleased]` (see memory `keep-docs-updated`).

## Verify a staff-dashboard change (reuse — I re-derived this twice)
0. **For CSS, read the rules back out of the browser**, not out of the file: an unterminated
   `/*` deletes every rule until the next `*/` while braces still balance and `php -l` still
   passes. Compare `document.styleSheets[inline].cssRules.length` against the selector count in
   the file — a shortfall is the tell. (Cost me twenty rules on admin_defense_schedule.php.)
   When splicing CSS, never end a replacement with the same comment-opener used as the span
   marker.
1. `php -l dashboards/<file>.php` for each touched file.
2. Render + screenshot read-only: forge `$_SESSION['user_id'|'role']` from a real user of that role,
   `chdir` into `dashboards/`, `ob_start()`+`include` the shell, write a temp `.html` **under
   `dashboards/`** (so `../assets/...` resolves), screenshot with Chrome
   `--headless=new --disable-gpu --virtual-time-budget=4500`, then **delete the temp file**.
   Seed dark by injecting `localStorage.setItem('rd-portal-theme','theme-dark')` immediately
   BEFORE the `<script src="../assets/js/portal.js">` tag — portal.js boots from `<head>`, so
   injecting before `<body>` is already too late and the page silently renders light.
