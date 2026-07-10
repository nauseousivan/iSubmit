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

### `approvals`
Tracks the status of a specific document or stage in the research lifecycle.
- **`approval_id`** (INT, PK)
- **`user_id`** (INT)
- **`form_id`** (INT)
- **`coordinator_status`** (ENUM) - `Pending`, `Approved`, `Rejected`.
- **`statistician_status`** (ENUM) - `Not Required`, `Pending`, `Approved`, `Rejected`.

## ⚠️ Current Limitations & Improvements (Planned)
- **Foreign Keys:** Core relations now carry explicit constraints (e.g. `uploads.user_id`/`item_id`
  cascade, `activity_logs.upload_id` sets null on delete). Some peripheral tables may still lack them.
- **Indexing:** Needs `INDEX` additions on `users.email`, `otp_verifications.email`, and `uploads.user_id` to improve query speeds on large datasets.
