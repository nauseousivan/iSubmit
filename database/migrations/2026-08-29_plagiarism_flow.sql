-- ============================================================================
-- Manuscript / Plagiarism clearance: state machine + finance payment step
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
--
-- Why plagiarism_checks and not a derived state: three pairs of distinct states are
-- byte-identical in `uploads`. The headline case is "clean pass — approved, no report
-- attached" versus "manuscript received, now go pay": both are item 4 = 'Approved' with
-- no item 40 row, and the registration step writes no upload row at all. The flow cannot
-- be read back out of `uploads`, so it gets a status column of its own — the same shape
-- `form_stat_treatment.status` already uses for Statistics.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. The per-group state machine.
--
--    Phase strings reuse form_stat_treatment's `Phase N: Label` grammar. Numbering is
--    dense here (Statistics has a historical gap at 3):
--      Phase 1: Pending Manuscript    released by the office, nothing uploaded yet
--      Phase 1: Manuscript Review     item 4 in, office has not looked yet
--      Phase 1: Manuscript Returned   bounced before payment (wrong draft)
--      Phase 2: Form Download         received. Print the form, pay at Finance
--      Phase 3: Payment Verification  items 41 + 42 in, awaiting staff
--      Phase 4: Registered            payment verified, control no. revealed, Turnitin runs
--      Phase 5: Revision Requested    plagiarism found; report attached; re-upload item 4
--      Phase 5: Cleared               passed — ready to bookbind
-- ---------------------------------------------------------------------------
ALTER TABLE `plagiarism_checks`
  ADD COLUMN IF NOT EXISTS `status`        VARCHAR(255) NOT NULL DEFAULT 'Phase 1: Pending Manuscript',
  ADD COLUMN IF NOT EXISTS `released_at`   DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS `released_by`   INT          NULL,
  ADD COLUMN IF NOT EXISTS `registered_at` DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS `remarks`       TEXT         NULL;

-- released_at / released_by ARE the "secretary let this group send its final manuscript"
-- flag. They live here rather than on defense_bookings for two reasons:
--   * reset_handler.php writes an EXPLICIT column list into defense_bookings_archive, so
--     columns added there are silently dropped at archive time (slot_no already suffers
--     from exactly this).
--   * defense_handler.php can flip a booking to 'Cancelled' and NULL its date, which would
--     leave a released group whose booking no longer reads as done. A calendar correction
--     must not retract a manuscript release.

-- The archive mirrors the live table so a reset cycle keeps the whole story.
ALTER TABLE `plagiarism_checks_archive`
  ADD COLUMN IF NOT EXISTS `status`        VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `released_at`   DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS `released_by`   INT          NULL,
  ADD COLUMN IF NOT EXISTS `registered_at` DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS `remarks`       TEXT         NULL;

-- Queue lookups ("who is waiting on payment verification") scan by status.
-- Guarded because ALTER ... ADD INDEX IF NOT EXISTS is not available on every 10.4 build.
SET @ix := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plagiarism_checks'
              AND INDEX_NAME = 'idx_plag_status');
SET @sql := IF(@ix = 0, 'ALTER TABLE `plagiarism_checks` ADD INDEX `idx_plag_status` (`status`)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2. The two finance-office documents, mirroring Statistics' items 36 / 37.
--
--    41/42 sit next to the plagiarism family (form_id 4 already owns items 4 and 40).
--    38/39 are also free but read as Statistics items, which occupy the 30-37 band.
--
--    Item 37 is already named plain 'Official Receipt'. Two identically-named rows would
--    be genuinely ambiguous in the group workspace, in the payment-history CSV's Document
--    column, and in the handlers' filename derivation — hence the qualifier.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `checklist_items`
  (`item_id`, `form_id`, `item_name`, `required_format`, `description`, `requirement_name`, `required_file_type`)
VALUES
(41, 4, 'Validated Plagiarism Form (RDC Form No. 13)', 'IMAGE',
    'Scanned copy of the official Validated form from the Finance Office.', '', 'image'),
(42, 4, 'Official Receipt (Plagiarism Scan)', 'IMAGE',
    'Scanned copy of the Official Receipt from the Finance Office.', '', 'image');

-- ---------------------------------------------------------------------------
-- 3. Payment audit trail — its own table, not a `domain` column on payment_logs.
--
--    payment_logs.form_id means form_stat_treatment.form_id, and SIX existing queries read
--    that table unscoped (the CSV export, the history count/page, the summary widgets, the
--    year dropdown, and reset_handler's archive copy). Making form_id polymorphic would not
--    crash any of them — it would quietly start listing plagiarism payments on the
--    Statistics History screen. A separate table has zero regression surface.
--
--    No FK on plag_id, matching payment_logs (which has none either): an FK would force
--    this table's DELETE to precede plagiarism_checks' during a reset.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plagiarism_payment_logs` (
    `log_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `plag_id`    INT NOT NULL,                    -- plagiarism_checks.id
    `student_id` INT NOT NULL,                    -- group leader user_id
    `actor_id`   INT NOT NULL,                    -- who decided
    `action`     VARCHAR(20) NOT NULL,            -- 'accepted' | 'rejected'
    `control_no` VARCHAR(60) DEFAULT NULL,        -- set on 'accepted' only
    `item_id`    INT DEFAULT NULL,                -- set on 'rejected' only (41 or 42)
    `remarks`    TEXT DEFAULT NULL,               -- set on 'rejected' only
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_plag_pay` (`plag_id`),
    INDEX `idx_plag_pay_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plagiarism_payment_logs_archive` (
    `archive_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `cycle_id`    INT NOT NULL,
    `orig_log_id` INT NOT NULL,
    `plag_id`     INT NOT NULL,
    `student_id`  INT NOT NULL,
    `actor_id`    INT NOT NULL,
    `action`      VARCHAR(20) NOT NULL,
    `control_no`  VARCHAR(60) DEFAULT NULL,
    `item_id`     INT DEFAULT NULL,
    `remarks`     TEXT DEFAULT NULL,
    `created_at`  TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_plag_pay_arch_cycle` (`cycle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
