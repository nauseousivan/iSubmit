-- ============================================================================
-- Plagiarism Module: satellite table + missing catalog rows (2026-07-10)
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
-- ============================================================================

-- 1. Forms catalog: Plagiarism Phase was never registered (only 1/2/3 exist).
INSERT IGNORE INTO `forms` (`form_id`, `form_name`, `description`) VALUES
(4, 'Plagiarism Phase', 'Turnitin originality scan and plagiarism clearance');

-- 2. checklist_items row for item_id = 4 (items currently jump 3 -> 5).
INSERT IGNORE INTO `checklist_items` (`item_id`, `form_id`, `item_name`, `required_format`, `description`, `requirement_name`, `required_file_type`) VALUES
(4, 4, 'Plagiarism-Free Manuscript (Turnitin Scan)', 'OFFICE_DOC', 'Full chapter manuscript submitted for Turnitin originality/plagiarism scan clearance.', '', 'pdf');

-- 3. Satellite table: control-number + result-release state ONLY.
--    Review lifecycle itself stays in uploads.verification_status via the
--    existing generic admin_module_dynamic.php engine.
CREATE TABLE IF NOT EXISTS `plagiarism_checks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'Always the group leader / effective_user_id, never a member id',
  `control_number_seq` INT(11) DEFAULT NULL,
  `formatted_control_no` VARCHAR(100) DEFAULT NULL,
  `result_file` VARCHAR(255) DEFAULT NULL,
  `release_notes` TEXT DEFAULT NULL,
  `date_released` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plag_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
