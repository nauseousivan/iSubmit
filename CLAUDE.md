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
- **Design systems (two, don't mix them):**
  - `assets/css/portal.css` + `assets/js/portal.js` = **STAFF** system: Apple/macOS, pure-white, Light/Dark, bottom dock, `PortalWindows` (module windows w/ traffic lights).
  - `assets/css/theme.css` = **AUTH/student** language (Material 3, purple). `assets/css/dashboard-cards.css` = student Apple-Wallet cards.

## Domain facts (non-obvious)
- Roles: `Student`, `Research Coordinator`, `Research Director`, `Statistician`.
- `uploads.verification_status`: `Pending` → (coordinator) `Under Review` → (director) `Approved` / `Revision Requested`.
  Coordinator/Statistician act on **Pending**; Director acts on **Under Review**.
- `checklist_items.item_id`: proposal 11–16 (Capsule/Form008 = **14**, approving it cascades-clears 13/15/16), final 21–27, stats 30–37, plagiarism = **4**. Statistician progress lives in `form_stat_treatment.status` (`Phase 1..7`).
- A "research group" = a `users` row with `role='Student'`, non-empty `research_group_name`, `leader_id IS NULL` (~52). `approvals` has ≤1 workflow row per group — **don't** source group lists from `approvals` (incomplete); use the leader-group query.
- Theme key: `localStorage['rd-portal-theme']` = `theme-light` | `theme-dark`.

## Rules
- **Presentation changes must not touch** workflow logic, POST handlers, SQL writes, form field
  names, or the review JS contract: `openDocumentModal`, `runForm008Tally`/`runForm011Tally`,
  AI pre-score, and the `csrf_token` field. `verification_status` values are fixed strings.
- Keep class `nav-item-btn` on staff nav/dock buttons — `_master_overview.php` selects it cross-file.
- Files are UTF-8 and start with `<?php`; **never write a BOM** (breaks `header()` redirects). For
  large in-place edits use the Edit tool or a PHP-CLI regex (raw bytes) — **not** PowerShell
  `Set-Content`/`Out-File` (adds a BOM).
- After code changes, update `docs/CHANGELOG.md` under `## [Unreleased]` (see memory `keep-docs-updated`).

## Verify a staff-dashboard change (reuse — I re-derived this twice)
1. `php -l dashboards/<file>.php` for each touched file.
2. Render + screenshot read-only: forge `$_SESSION['user_id'|'role']` from a real user of that role,
   `chdir` into `dashboards/`, `ob_start()`+`include` the shell, write a temp `.html` **under
   `dashboards/`** (so `../assets/...` resolves), screenshot with Chrome
   `--headless=new --disable-gpu --virtual-time-budget=4500`, then **delete the temp file**.
   Seed dark by injecting `localStorage.setItem('rd-portal-theme','theme-dark')` before `portal.js` boots.
