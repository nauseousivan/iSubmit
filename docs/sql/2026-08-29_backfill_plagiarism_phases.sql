-- ============================================================================
-- Backfill plagiarism_checks.status for groups that moved through the OLD
-- single-stage flow (upload → staff accepts with a Turnitin report = cleared).
--
-- Those rows were created by the control-number mint in upload_handler.php, which
-- sets user_id / seq / formatted_control_no and leaves `status` on its DEFAULT
-- ('Phase 1: Pending Manuscript'). Under the new ladder that default files them
-- back into Manuscript → Review, i.e. a group that was already cleared would be
-- asked for its manuscript again.
--
-- Derivation is legitimate here ONLY because we are seeding a starting point for
-- rows written before the ladder existed — never for reading live state. Every
-- statement is scoped to rows still sitting on the default, so this is
-- idempotent and cannot touch a group the new flow has already advanced.
--
-- Run once, after database/migrations/2026-08-29_plagiarism_flow.sql.
-- ============================================================================

START TRANSACTION;

SELECT 'BEFORE' AS stage, status, COUNT(*) AS rows_
FROM plagiarism_checks GROUP BY status;

-- 1. Cleared: the manuscript was approved AND a Turnitin report was released.
--    That is exactly what "accepted" meant in the old flow.
UPDATE plagiarism_checks pc
   SET pc.status      = 'Phase 5: Cleared',
       pc.released_at = COALESCE(pc.released_at, NOW()),
       pc.registered_at = COALESCE(pc.registered_at, NOW())
 WHERE pc.status = 'Phase 1: Pending Manuscript'
   AND EXISTS (SELECT 1 FROM uploads u WHERE u.user_id = pc.user_id AND u.item_id = 4  AND u.verification_status = 'Approved')
   AND EXISTS (SELECT 1 FROM uploads r WHERE r.user_id = pc.user_id AND r.item_id = 40 AND r.verification_status = 'Approved');

-- 2. Sent back after a scan: the old flow always attached a report to a revision.
UPDATE plagiarism_checks pc
   SET pc.status      = 'Phase 5: Revision Requested',
       pc.released_at = COALESCE(pc.released_at, NOW()),
       pc.registered_at = COALESCE(pc.registered_at, NOW())
 WHERE pc.status = 'Phase 1: Pending Manuscript'
   AND EXISTS (SELECT 1 FROM uploads u WHERE u.user_id = pc.user_id AND u.item_id = 4 AND u.verification_status = 'Revision Requested')
   AND EXISTS (SELECT 1 FROM uploads r WHERE r.user_id = pc.user_id AND r.item_id = 40);

-- 3. Still waiting on a first decision. Also mark them released: they clearly got
--    this far, and without it the phase gate would refuse their next upload.
UPDATE plagiarism_checks pc
   SET pc.status      = 'Phase 1: Manuscript Review',
       pc.released_at = COALESCE(pc.released_at, NOW())
 WHERE pc.status = 'Phase 1: Pending Manuscript'
   AND EXISTS (SELECT 1 FROM uploads u WHERE u.user_id = pc.user_id AND u.item_id = 4 AND u.verification_status = 'Pending');

SELECT 'AFTER' AS stage, status, COUNT(*) AS rows_
FROM plagiarism_checks GROUP BY status;

COMMIT;
