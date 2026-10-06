-- ============================================================================
-- ONE-OFF DATA RESET — Final phase + Manuscript/Plagiarism only.
--
-- Run ONCE, after database/migrations/2026-08-29_plagiarism_flow.sql.
--
-- WHY THIS IS NOT reset_handler.php: that handler is the student-facing "Start a New
-- Research" flow. It archives a WHOLE cycle (proposal + stats + final + plagiarism) into
-- research_cycles and the *_archive tables. Running it here would (a) wipe Proposal and
-- Statistics, which must survive, and (b) mint bogus "cycle" records in Research History
-- for a schema change no student performed. This is a scoped developer reset instead; the
-- rollback is the mysqldump taken alongside it (backups/pre_final_revamp_*.sql).
--
-- WHAT SURVIVES: every proposal upload, every proposal booking (8 Scheduled), all of
-- Statistics, all users/groups/members. Groups stay "proposal scheduled" and land straight
-- into the new Final Phase 1.
--
-- FILES ON DISK ARE NEVER UNLINKED — same rule as reset_handler.php. Orphaned files under
-- uploads/ are harmless; deleting them would make this irreversible.
-- ============================================================================

START TRANSACTION;

-- Before ------------------------------------------------------------------
SELECT 'BEFORE' AS stage,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (20,21,22,23,24,25,26,27)) AS final_uploads,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (4,40))                     AS plag_uploads,
       (SELECT COUNT(*) FROM defense_bookings WHERE phase = 'final')              AS final_bookings,
       (SELECT COUNT(*) FROM plagiarism_checks)                                   AS plag_rows,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (11,12,13,14,15,16))        AS proposal_uploads_KEEP,
       (SELECT COUNT(*) FROM defense_bookings WHERE phase = 'proposal')           AS proposal_bookings_KEEP;

-- 1. Final defense bookings ------------------------------------------------
--    First, so application_upload_id can never dangle while uploads go.
DELETE FROM `defense_bookings` WHERE `phase` = 'final';

-- 2. Plagiarism control-number rows ----------------------------------------
--    All four are pre-revamp. The new flow re-creates a row when the secretary
--    releases a group, and the control number is minted on first upload as before.
DELETE FROM `plagiarism_checks`;

-- 3. Notifications that would point at documents about to vanish -----------
--    3a. Anything joined to a Final/Manuscript upload. activity_logs.upload_id is
--        ON DELETE SET NULL, so these would otherwise survive as orphan bell entries
--        describing files the student can no longer open.
DELETE a FROM `activity_logs` a
  JOIN `uploads` u ON u.upload_id = a.upload_id
 WHERE u.item_id IN (4,20,21,22,23,24,25,26,27,40);

--    3b. Booking/clearance events that carry no upload_id. Only titles that are
--        UNAMBIGUOUSLY Final or Manuscript are removed. 'Defense Date Requested' and
--        'Defense Schedule Cancelled' are deliberately KEPT: both phases write them and
--        there is no column to tell them apart. They are status_type='info' (the student's
--        own action log, not the bell), so a stale row there is harmless.
DELETE FROM `activity_logs`
 WHERE `upload_id` IS NULL
   AND `title` IN ('Final Defense Scheduled',
                   'Plagiarism Clearance Accepted',
                   'Plagiarism Revision Requested',
                   'Plagiarism Report Replaced');

-- 4. The uploads themselves ------------------------------------------------
--    LAST: uploads.item_id and form_008_reviews.upload_id are RESTRICT foreign keys.
--    (Verified: zero form_008_reviews rows reference these items — Form 008 is item 14.)
DELETE FROM `uploads` WHERE `item_id` IN (4,20,21,22,23,24,25,26,27,40);

-- After -------------------------------------------------------------------
SELECT 'AFTER' AS stage,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (20,21,22,23,24,25,26,27)) AS final_uploads,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (4,40))                     AS plag_uploads,
       (SELECT COUNT(*) FROM defense_bookings WHERE phase = 'final')              AS final_bookings,
       (SELECT COUNT(*) FROM plagiarism_checks)                                   AS plag_rows,
       (SELECT COUNT(*) FROM uploads WHERE item_id IN (11,12,13,14,15,16))        AS proposal_uploads_KEEP,
       (SELECT COUNT(*) FROM defense_bookings WHERE phase = 'proposal')           AS proposal_bookings_KEEP;

COMMIT;
