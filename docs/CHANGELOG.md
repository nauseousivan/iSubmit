# Changelog

All notable changes to the `iSubmit` project will be documented in this file.

## [Unreleased]

### Changed
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
