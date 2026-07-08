-- ============================================================================
-- Statistics Module: 7-phase workflow migration (2026-07-07)
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
-- Consolidates and replaces the ad-hoc scratch_db.php / scratch_migrate.php.
-- ============================================================================

-- 1. Free-text phase status (was an enum of the old 4-step flow)
ALTER TABLE `form_stat_treatment`
  MODIFY COLUMN `status` VARCHAR(255) NOT NULL DEFAULT 'Phase 1: Pending Coded Data';

-- 2. Audit-trail link from activity logs to a specific upload version
--    (already present on DBs patched by commit 990d407; needed on fresh imports)
ALTER TABLE `activity_logs`
  ADD COLUMN IF NOT EXISTS `upload_id` INT(11) DEFAULT NULL AFTER `user_id`;
ALTER TABLE `activity_logs`
  ADD KEY IF NOT EXISTS `idx_activity_upload` (`upload_id`);
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_activity_upload` FOREIGN KEY IF NOT EXISTS (`upload_id`)
  REFERENCES `uploads` (`upload_id`) ON DELETE SET NULL;

-- 3. Statistics form + checklist requirements (form_id = 3, items 30-37)
INSERT IGNORE INTO `forms` (`form_id`, `form_name`, `description`) VALUES
(3, 'Statistics Phase', 'Statistical treatment and data validation');

INSERT IGNORE INTO `checklist_items` (`item_id`, `form_id`, `item_name`, `required_format`, `description`, `requirement_name`, `required_file_type`) VALUES
(30, 3, 'Initial Coded Data (For Verification)', 'EXCEL', 'Upload your initial coded data in Excel for Statistician verification before proceeding.', '', 'xlsx'),
(31, 3, 'Statement of the Problem (SOP)', 'PDF', 'Upload your approved Statement of the Problem.', '', 'pdf'),
(32, 3, 'Research Questionnaire', 'PDF', 'Upload the final research questionnaire or datagathering tool.', '', 'pdf'),
(33, 3, 'Final Coded Data', 'EXCEL', 'Upload the final encoded data for statistical treatment.', '', 'xlsx'),
(34, 3, 'Communication Letter', 'PDF', 'Approved communication letter to conduct the study.', '', 'pdf'),
(35, 3, 'Minutes of the Meeting (MOM)', 'PDF', 'Minutes of the Proposal Defense meeting.', '', 'pdf'),
(36, 3, 'Validated Statistical Treatment Form (RDC Form No. 011)', 'IMAGE', 'Scanned copy of the official Validated form from the Finance Office.', '', 'image'),
(37, 3, 'Official Receipt', 'IMAGE', 'Scanned copy of the Official Receipt from the Finance Office.', '', 'image');

-- Payment documents are photos/scans: accept images, not just PDF
UPDATE `checklist_items`
   SET `required_format` = 'IMAGE', `required_file_type` = 'image'
 WHERE `item_id` IN (36, 37);

-- 4. Remap legacy statuses (old enum values + retired phase names) to the 7-phase model
UPDATE `form_stat_treatment` SET `status` = 'Phase 1: Pending Coded Data'  WHERE `status` = 'Pending Initial Data';
UPDATE `form_stat_treatment` SET `status` = 'Phase 1: Coded Data Review'   WHERE `status` = 'Initial Data Uploaded';
UPDATE `form_stat_treatment` SET `status` = 'Phase 1: Coded Data Rejected' WHERE `status` = 'Initial Data Rejected';
UPDATE `form_stat_treatment` SET `status` = 'Phase 2: Form Download'       WHERE `status` IN ('Waiting for Payment', 'Phase 3: Pending Payment');
UPDATE `form_stat_treatment` SET `status` = 'Phase 5: Registered'          WHERE `status` IN ('Payment Acknowledged', 'Phase 6: Requirements Processing');
UPDATE `form_stat_treatment` SET `status` = 'Phase 6: Under Review'        WHERE `status` IN ('Requirements Uploaded', 'Under Review');
UPDATE `form_stat_treatment` SET `status` = 'Phase 7: Completed'           WHERE `status` = 'Completed';

-- Catch-all: normalize anything unrecognized (broken test data, empty strings)
UPDATE `form_stat_treatment` SET `status` = 'Phase 1: Pending Coded Data'
 WHERE `status` NOT IN (
    'Phase 1: Pending Coded Data',
    'Phase 1: Coded Data Review',
    'Phase 1: Coded Data Rejected',
    'Phase 2: Form Download',
    'Phase 4: Payment Verification',
    'Phase 5: Registered',
    'Phase 6: Under Review',
    'Phase 6: Revision Requested',
    'Phase 7: Statistical Treatment',
    'Phase 7: Completed'
 );
