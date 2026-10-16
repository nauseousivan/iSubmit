-- 2026-10-16 — the statistics flow as the RDC office runs it (Form 11 in hand).
--
--   1. Requirements: the group uploads FIVE (30 coded data, 31 SOP, 32 questionnaire,
--      34 communication letter, 35 MOM); the Statistician checks them.
--   2. Control number: the SECRETARY issues it ('Phase 2: Form Download' -> new
--      'Phase 3: Control Number Issued'), auto-numbered per course and year (control_number_seq).
--   3. Payment: pay at Finance, upload the receipt (37); the Secretary checks it, records the
--      OR number (main_or_number) and confirms -> 'Phase 7: Statistical Treatment'.
--   4. Treatment -> release (unchanged, incl. the no-new-payment fix-it loop).
-- Retired items: 33 (Final Coded Data — Form 11 lists one coded data) and 36 (validated Form 011
-- upload — the paper form stays at the RDC). Their rows stay as history; nothing reads them.
-- No ALTER: status is a VARCHAR, and control_number_seq / main_or_number already exist.
-- Idempotent: safe to run twice.

-- The coded data carries Form 11's own words.
UPDATE checklist_items SET item_name = 'Coded Data in Excel' WHERE item_id = 30;

-- Requests the OLD flow moved past the coded data alone ('Phase 2' then meant "go and pay") but
-- that do not yet have all five requirements approved go back to step 1: under the new flow the
-- Secretary only issues a number once all five are approved. Their approved coded data stays approved.
UPDATE form_stat_treatment f
SET f.status = 'Phase 1: Pending Coded Data'
WHERE f.status = 'Phase 2: Form Download'
  AND EXISTS (
      SELECT 1 FROM checklist_items ci
      WHERE ci.item_id IN (30, 31, 32, 34, 35)
        AND NOT EXISTS (
            SELECT 1 FROM uploads u
            WHERE u.user_id = f.user_id AND u.item_id = ci.item_id AND u.verification_status = 'Approved'
              AND u.upload_id = (SELECT MAX(u2.upload_id) FROM uploads u2 WHERE u2.user_id = f.user_id AND u2.item_id = ci.item_id)));

-- 'Phase 1: Coded Data Review' now means "all five are in, being checked". A request that is
-- there with fewer than five uploaded is really still waiting on the group.
UPDATE form_stat_treatment f
SET f.status = 'Phase 1: Pending Coded Data'
WHERE f.status = 'Phase 1: Coded Data Review'
  AND (SELECT COUNT(DISTINCT u.item_id) FROM uploads u WHERE u.user_id = f.user_id AND u.item_id IN (30, 31, 32, 34, 35)) < 5;
