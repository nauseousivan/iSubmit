-- ============================================================================
-- Plagiarism Module: single-stage Accept/Revise + versioned Turnitin reports
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
-- ============================================================================

-- 1. Rename the manuscript item away from presuming an outcome ("Plagiarism-Free" implied a
--    verdict before any review happened).
UPDATE `checklist_items` SET `item_name` = 'Research Manuscript (Turnitin Scan)' WHERE `item_id` = 4;

-- 2. New staff-uploaded item: the Turnitin similarity report itself. Stored in the same `uploads`
--    table as every other document so it gets full version history for free (no bespoke
--    versioning code) — this is what problem #2 of the redesign asked for.
INSERT IGNORE INTO `checklist_items` (`item_id`, `form_id`, `item_name`, `required_format`, `description`, `requirement_name`, `required_file_type`) VALUES
(40, 4, 'Turnitin Similarity Report', 'PDF', 'Official Turnitin similarity report attached by the Research Office when a manuscript is accepted or sent back for revision.', '', 'pdf');

-- 3. Migrate any already-released report out of the single-value column into a real uploads row
--    before dropping the columns, so no existing test/real data is silently lost.
INSERT INTO `uploads` (`user_id`, `item_id`, `file_path`, `original_filename`, `verification_status`, `remarks`, `uploaded_at`)
SELECT `user_id`, 40, `result_file`, SUBSTRING_INDEX(`result_file`, '/', -1), 'Approved', `release_notes`, COALESCE(`date_released`, CURRENT_TIMESTAMP)
FROM `plagiarism_checks` WHERE `result_file` IS NOT NULL AND `result_file` != '';

-- 4. plagiarism_checks shrinks back down to pure control-number bookkeeping.
ALTER TABLE `plagiarism_checks`
  DROP COLUMN IF EXISTS `result_file`,
  DROP COLUMN IF EXISTS `release_notes`,
  DROP COLUMN IF EXISTS `date_released`;
