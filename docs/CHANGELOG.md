# Changelog

All notable changes to the `iSubmit` project will be documented in this file.

## [Unreleased]

### Changed
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
