# Database Documentation

This document maps out the core schema of the `digital_research` MySQL database.

## 📊 Core Tables

### `users`
Stores all registered accounts for the platform.
- **`user_id`** (INT, PK, Auto Increment)
- **`username`** (VARCHAR 50)
- **`email`** (VARCHAR 100) - Should be unique.
- **`password`** (VARCHAR 255) - Stores BCrypt hashes.
- **`role`** (ENUM) - `Student`, `Research Coordinator`, `Statistician`, `Research Director`.
- **`department`** / **`institution`** (VARCHAR) - Used for frontend branding logic.

### `otp_verifications`
Temporarily stores OTP codes for email verification and password resets.
- **`otp_id`** (INT, PK)
- **`email`** (VARCHAR 100)
- **`otp_code`** (VARCHAR 6)
- **`expires_at`** (DATETIME) - Ensures codes expire (usually within 15 minutes).
- **`created_at`** (TIMESTAMP)

### `activity_logs`
Tracks historical actions taken by users across the platform.
- **`activity_id`** (INT, PK)
- **`user_id`** (INT) - Refers to the user who performed the action.
- **`upload_id`** (INT, NULLABLE, FK → `uploads.upload_id` `ON DELETE SET NULL`) - Links a log entry to the
  specific upload it describes; nulls out automatically if that upload is later deleted.
- **`title`** (VARCHAR 100) - Short description (e.g., "File Upload").
- **`description`** (TEXT) - Detailed description.
- **`status_type`** (VARCHAR 30) - Determines UI color (`success`, `warning`, `info`).

### `uploads`
Stores metadata and file paths for research documents uploaded by users.
- **`upload_id`** (INT, PK)
- **`user_id`** (INT) - The uploader.
- **`item_id`** (INT) - Links to the specific checklist item requirement.
- **`original_filename`** (VARCHAR 255)
- **`file_path`** (VARCHAR 255) - Path relative to the server root (e.g., `uploads/file.pdf`).
- **`verification_status`** (ENUM) - `Pending`, `Under Review`, `Approved`, `Revision Requested`.
  Every checklist item follows `Pending → Under Review → Approved/Revision Requested`, **except item 4**
  (plagiarism manuscript), which is single-stage: `Pending → Approved/Revision Requested` directly,
  decided by any of Coordinator/Director/Statistician via a dedicated action that requires an
  attached Turnitin report (item 40) — see "Plagiarism checklist items" below.

**Lifecycle note:** each upload is a version row. The newest row per `(user_id, item_id)` is the "current"
submission; reviewed rows form a read-only history. A student may delete only a `Pending` draft, and a new
upload automatically supersedes (removes) any previous un-reviewed `Pending` draft for that item, so ghost
drafts do not accumulate.

### `form_stat_treatment`
One row per research group's Statistical Treatment request (keyed to the group leader's `user_id`).
- **`form_id`** (INT, PK)
- **`user_id`** (INT) - The group leader (effective user id).
- **`status`** (VARCHAR 255) - The 7-phase workflow state. Valid values:
  `Phase 1: Pending Coded Data` → `Phase 1: Coded Data Review` → (`Phase 1: Coded Data Rejected`) →
  `Phase 2: Form Download` → `Phase 4: Payment Verification` → `Phase 5: Registered` →
  `Phase 6: Under Review` / `Phase 6: Revision Requested` → `Phase 7: Statistical Treatment` →
  `Phase 7: Completed`. (Was an ENUM of the old 4-step flow; migrated 2026-07-07.)
- **`formatted_control_no`** (VARCHAR) - Official control number `STAT-{YEAR}-{SCHOOL}-{DEPT}-{SEQ}`;
  the Statistician enters only the sequence at registration, the system builds and stores the full string.
- **`file_coded_data`** / **`result_file`** (VARCHAR 255) - Latest coded-data upload path and the final
  results file released by the Statistician (written by the `upload_stat_result` action).
- **`statistician_remarks`** (TEXT), **`date_submitted`** / **`date_released`** (TIMESTAMP).

Statistics checklist items (form_id 3): `30` Initial Coded Data, `31–35` deliverables (SOP, Questionnaire,
Final Coded Data, Communication Letter, MOM), `36` Validated RDC Form No. 011, `37` Official Receipt
(items 36/37 accept image scans). Their uploads follow the same version-row lifecycle as proposals, and
deleting a pending draft rewinds `form_stat_treatment.status` to the matching earlier phase.

### `plagiarism_checks`
One row per research group's plagiarism control-number bookkeeping (keyed to the leader's `user_id`).
- **`user_id`** (INT, UNIQUE) - The group leader (effective user id).
- **`control_number_seq`** (INT) - Global auto-incremented sequence, assigned on first manuscript upload.
- **`formatted_control_no`** (VARCHAR) - `PLAG-{SCHOOL}-{COURSE}-{SEQ}` (no year segment), generated
  once and reused across re-uploads/revisions — never regenerated.

Plagiarism checklist items (form_id 4): **`4`** Research Manuscript (Turnitin Scan) — student-uploaded,
single-stage review (see the `verification_status` note above: no `Under Review` stage here, unlike
every other checklist item). **`40`** Turnitin Similarity Report — staff-uploaded only, one new
`uploads` row per Accept/Revise/Replace decision (so the report has full version history, same
lifecycle as any other document); `verification_status` mirrors the decision (`Approved` for Accept/
Replace, `Revision Requested` for Revise), `remarks` holds the optional note. A Turnitin report is
required on every Accept and Revise decision — acceptance is unreachable without one.

### `form_templates`
The blank forms students download, as the Admin maintains them (Admin → System → Forms, 2026-10-05;
migration `2026-10-05_form_templates.sql`). One row per upload, never deleted — replacing a form flips
`is_current`, so any earlier version can be put back. No current row = the file that ships with the system.
- **`template_id`** (INT, PK)
- **`slot`** (VARCHAR) - Which form: a key of `rd_form_catalogue()` in `config/forms.php` (`application`,
  `assigned_adviser`, `endorsement`, `proposal_review`, `capsule_form`, `proposal_cleared`, `stat_form`, `mom`,
  `final_cleared`, `plag_form`).
- **`institution`** (VARCHAR) - `MCNP` or `ISAP`. "Both schools" writes one row for each, sharing the file.
- **`file_path`** (VARCHAR) - Relative to the app root, under `forms/` (that folder runs nothing — `forms/.htaccess`).
- **`original_name`**, **`file_size`**, **`note`** (the Admin's own label, e.g. "2026 revision").
- **`is_current`** (TINYINT) - At most one current row per `slot` + `institution`.
- **`uploaded_by`** (INT), **`uploaded_at`** (DATETIME).

Students reach it only through `rd_form_url($pdo, $slot, $uid)`, which picks their school from their own
`department` (`rd_user_institution()`).

### `approvals`
Tracks the status of a specific document or stage in the research lifecycle.
- **`approval_id`** (INT, PK)
- **`user_id`** (INT)
- **`form_id`** (INT)
- **`coordinator_status`** (ENUM) - `Pending`, `Approved`, `Rejected`.
- **`statistician_status`** (ENUM) - `Not Required`, `Pending`, `Approved`, `Rejected`.

## Research cycles & archives

A group that finishes its research and starts a new one (Settings → Danger Zone →
**Start a New Research**, handled by `dashboards/reset_handler.php`) does not lose anything:
the finished cycle is **moved** out of the live tables into parallel archive tables.

### `research_cycles`
One row per archived cycle, keyed to the group **leader** (`leader_user_id` = the same
`effective_user_id` that owns every `uploads` row for the group).
- **`cycle_no`** (INT) - 1 = the first research this group archived.
- **`research_group_name` / `research_title` / `department` / `program` / `year_level`** -
  snapshot of the group's identity *at the moment of archiving*, copied rather than joined
  because `users.research_group_name` is overwritten with the new title.
- **`members_json`** (LONGTEXT) - the roster as it stood, including whether each member
  carried over into the new research.
- **`stats_control_no` / `plag_control_no`** - denormalised for the history list.

### `*_archive` tables
`uploads_archive`, `approvals_archive`, `form_stat_treatment_archive`,
`plagiarism_checks_archive`, `defense_bookings_archive`, `form_008_reviews_archive`,
`activity_logs_archive`, `payment_logs_archive`.

Each mirrors its live table's columns 1:1, prefixed with `archive_id`, `cycle_id` and the
original primary key preserved as a plain `orig_*` column (so archived rows still
cross-reference each other *inside* the archive). Deliberately **no foreign keys** — their
live parents are deleted immediately after the copy, so any FK would reject the archive itself.

Three rules that are easy to break:
1. **Never delete files from `uploads/` during a reset.** Archive rows keep `file_path`
   verbatim; `research_history.php` opens last year's documents straight from disk. Deleting
   them turns a reversible archive into permanent data loss.
2. **Delete live rows FK-safe, `uploads` last.** `form_008_reviews.upload_id` is a RESTRICT
   foreign key; `activity_logs.upload_id` and `defense_bookings.application_upload_id` are
   `ON DELETE SET NULL`. Order: `form_008_reviews` → `defense_bookings` → `activity_logs` →
   `payment_logs` → `form_stat_treatment` → `plagiarism_checks` → `approvals` → `uploads`.
3. **Any `MAX(control_number_seq)` must span live *and* archive.** Archiving frees
   `plagiarism_checks.uniq_plag_user` and `defense_bookings.uniq_group_phase` (which is what
   lets the new cycle book a defense and get a new PLAG number), but a live-only `MAX()` would
   reissue a control number already given to another group. Use
   `GREATEST(COALESCE(live,0), COALESCE(archive,0)) + 1`.

Schema: `docs/sql/2026-08-research-cycles.sql` (idempotent). It also adds
**`users.year_level`** (VARCHAR(20) NULL) — personal like `student_number`, never inherited
from the leader.

## ⚠️ Current Limitations & Improvements (Planned)
- **Foreign Keys:** Core relations now carry explicit constraints (e.g. `uploads.user_id`/`item_id`
  cascade, `activity_logs.upload_id` sets null on delete). Some peripheral tables may still lack them.
- **Indexing:** Needs `INDEX` additions on `users.email`, `otp_verifications.email`, and `uploads.user_id` to improve query speeds on large datasets.
