-- Defense slots: many groups per day, numbered in the order the office confirms them.
--
-- The original scheduling model allowed exactly ONE group per date per phase — capacity was
-- never a column, it was implied by df_taken_dates() returning one row per date and
-- df_unavailable_reason() doing isset($taken[$date]).
--
-- The RDC actually runs up to 15 defenses a day, SHARED between proposal and final: a proposal
-- and a final defense can sit on the same date, and groups are heard in sequence. There is no
-- clock time — a defense takes as long as it takes — so what a group is given is a SLOT NUMBER.
--
-- `slot_no` is written once, when the office logs the schedule, and never recomputed. A later
-- cancellation deliberately leaves the numbers around it alone: a student may already have
-- screenshotted or downloaded their ticket, and renumbering it after the fact would make that
-- ticket wrong.
--
-- The per-day cap itself lives in PHP (DEFENSE_SLOTS_PER_DAY in dashboards/defense_lib.php),
-- not here, so the office can be given a different number without a migration.
--
-- Idempotent: safe to re-run.
--   mysql -u root digital_research < docs/sql/2026-08-defense-slots.sql

ALTER TABLE defense_bookings
  ADD COLUMN IF NOT EXISTS slot_no TINYINT UNSIGNED NULL AFTER defense_date;

-- Same column on the archive mirror, so "Start a New Research" keeps carrying it across.
ALTER TABLE defense_bookings_archive
  ADD COLUMN IF NOT EXISTS slot_no TINYINT UNSIGNED NULL AFTER defense_date;

-- Finding "who else is on this date" is now a per-date COUNT across both phases rather than a
-- single-row lookup, and it runs on every calendar render.
ALTER TABLE defense_bookings
  ADD INDEX IF NOT EXISTS idx_booking_date_status (defense_date, status);

-- Backfill: number the defenses already confirmed, ordered by when the office decided them so
-- the sequence matches the order they were actually logged. Groups sharing a date (only
-- possible across phases before this change) come out 1, 2, ... by decision time.
SET @rank := 0, @prev := NULL;
UPDATE defense_bookings b
JOIN (
    SELECT booking_id,
           @rank := IF(@prev = defense_date, @rank + 1, 1) AS computed_slot,
           @prev := defense_date
    FROM defense_bookings
    WHERE status = 'Scheduled' AND defense_date IS NOT NULL
    ORDER BY defense_date ASC, COALESCE(decided_at, updated_at) ASC, booking_id ASC
) ranked ON ranked.booking_id = b.booking_id
SET b.slot_no = ranked.computed_slot
WHERE b.slot_no IS NULL;
