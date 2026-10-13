-- Research cycles: "Start a New Research" (archive-and-reset)
--
-- A group that finished its 3rd-year research comes back in 4th year with a NEW study.
-- The leader triggers a reset from Settings -> Danger Zone; everything the group did in
-- the old cycle is MOVED into the `*_archive` tables below and the live tables are left
-- empty for that group, so the student portal reads 0% again.
--
-- Why move rows instead of adding a `cycle_id` filter: `uploads` alone is queried from
-- ~65 hand-written SQL sites across 20 files. One missed `WHERE cycle_id = active` would
-- leak last year's research into this year's review queue. Moving the rows means every
-- existing live query keeps working untouched, and the staff history view is a separate,
-- obviously read-only code path.
--
-- IMPORTANT: the reset NEVER deletes files from `uploads/` on disk. Archive rows keep
-- `file_path` verbatim so staff can still open every old PDF from Research History.
--
-- Archive tables deliberately carry NO foreign keys — their live parents get deleted
-- immediately after the copy, so any FK would reject the archive itself. The original
-- primary key is preserved as a plain `orig_*` column so rows still cross-reference each
-- other *inside* the archive (e.g. an archived activity log -> an archived upload).
--
-- Idempotent: safe to re-run on the defense machine.
--   mysql -u root digital_research < docs/sql/2026-08-research-cycles.sql

-- ---------------------------------------------------------------------------
-- One row per archived research cycle, keyed on the group LEADER's user_id
-- (the same effective_user_id that owns every `uploads` row for the group).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS research_cycles (
  cycle_id            INT AUTO_INCREMENT PRIMARY KEY,
  leader_user_id      INT NOT NULL,
  cycle_no            INT NOT NULL COMMENT '1 = the first research this group archived',

  -- Snapshot of the group's identity AT THE MOMENT OF ARCHIVING. Copied rather than
  -- joined, because users.research_group_name is overwritten with the new title.
  research_group_name VARCHAR(255) NULL COMMENT 'Registration calls this the Research Project Title',
  research_title      VARCHAR(255) NULL,
  department          VARCHAR(100) NULL,
  program             VARCHAR(100) NULL,
  year_level          VARCHAR(20)  NULL COMMENT 'Year level the group was in for THIS cycle',

  -- [{"user_id":..,"username":..,"email":..,"student_number":..,"role":"Leader|Member"}, ...]
  members_json        LONGTEXT NULL,

  -- Denormalised so the history list can show them without touching the archive tables.
  stats_control_no    VARCHAR(100) NULL,
  plag_control_no     VARCHAR(100) NULL,

  archived_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_by         INT NULL COMMENT 'Who pressed the button (the leader)',

  UNIQUE KEY uniq_leader_cycle (leader_user_id, cycle_no),
  KEY idx_cycle_leader (leader_user_id),
  KEY idx_cycle_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Archive mirrors. Column lists match the live tables 1:1 (minus AUTO_INCREMENT,
-- FKs and JSON CHECK constraints) so `INSERT INTO x_archive SELECT ... FROM x`
-- stays a straight positional copy.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS uploads_archive (
  archive_id          INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id            INT NOT NULL,
  orig_upload_id      INT NOT NULL,
  user_id             INT NULL,
  item_id             INT NULL,
  original_filename   VARCHAR(255) NULL,
  file_path           VARCHAR(255) NOT NULL COMMENT 'File is still on disk - never unlinked by the reset',
  verification_status ENUM('Pending','Under Review','Approved','Revision Requested') NULL DEFAULT 'Pending',
  remarks             TEXT NULL,
  uploaded_at         TIMESTAMP NULL DEFAULT NULL,
  form_008_data       LONGTEXT NULL,
  form_008_score      INT NULL,
  form_008_decision   VARCHAR(100) NULL,
  KEY idx_ua_cycle (cycle_id),
  KEY idx_ua_orig (orig_upload_id),
  KEY idx_ua_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approvals_archive (
  archive_id          INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id            INT NOT NULL,
  orig_approval_id    INT NOT NULL,
  user_id             INT NULL,
  form_id             INT NULL,
  coordinator_status  ENUM('Pending','Approved','Rejected') NULL,
  statistician_status ENUM('Not Required','Pending','Approved','Rejected') NULL,
  director_status     ENUM('Pending','Approved','Rejected') NULL,
  payment_status      ENUM('Unpaid','Paid') NULL,
  printing_enabled    TINYINT(1) NULL,
  updated_at          TIMESTAMP NULL DEFAULT NULL,
  KEY idx_aa_cycle (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS form_stat_treatment_archive (
  archive_id           INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id             INT NOT NULL,
  orig_form_id         INT NOT NULL,
  control_number_seq   INT NULL COMMENT 'Kept so the live MAX() never reuses a STAT number',
  formatted_control_no VARCHAR(100) NULL,
  user_id              INT NULL,
  research_title       VARCHAR(255) NULL,
  proponents           LONGTEXT NULL,
  course               VARCHAR(100) NULL,
  main_or_number       VARCHAR(100) NULL,
  date_submitted       TIMESTAMP NULL DEFAULT NULL,
  date_released        TIMESTAMP NULL DEFAULT NULL,
  contact_email        VARCHAR(100) NULL,
  contact_number       VARCHAR(50) NULL,
  file_sop             VARCHAR(255) NULL,
  file_questionnaire   VARCHAR(255) NULL,
  file_coded_data      VARCHAR(255) NULL,
  file_comm_letter     VARCHAR(255) NULL,
  file_mom             VARCHAR(255) NULL,
  status               VARCHAR(255) NULL,
  statistician_remarks TEXT NULL,
  result_file          VARCHAR(255) NULL,
  KEY idx_fsa_cycle (cycle_id),
  KEY idx_fsa_orig (orig_form_id),
  KEY idx_fsa_seq (control_number_seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plagiarism_checks_archive (
  archive_id           INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id             INT NOT NULL,
  orig_id              INT NOT NULL,
  user_id              INT NOT NULL,
  control_number_seq   INT NULL COMMENT 'Kept so the live MAX() never reuses a PLAG number',
  formatted_control_no VARCHAR(100) NULL,
  KEY idx_pca_cycle (cycle_id),
  KEY idx_pca_seq (control_number_seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS defense_bookings_archive (
  archive_id            INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id              INT NOT NULL,
  orig_booking_id       INT NOT NULL,
  user_id               INT NOT NULL,
  phase                 ENUM('proposal','final') NOT NULL DEFAULT 'final',
  defense_date          DATE NULL,
  application_upload_id INT NULL COMMENT 'Points at uploads_archive.orig_upload_id',
  status                ENUM('Applied','Date Selected','Scheduled','Rejected','Cancelled') NOT NULL DEFAULT 'Applied',
  decided_by            INT NULL,
  decided_at            DATETIME NULL,
  remarks               TEXT NULL,
  created_at            TIMESTAMP NULL DEFAULT NULL,
  updated_at            TIMESTAMP NULL DEFAULT NULL,
  KEY idx_dba_cycle (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS form_008_reviews_archive (
  archive_id           INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id             INT NOT NULL,
  orig_review_id       INT NOT NULL,
  upload_id            INT NOT NULL COMMENT 'Points at uploads_archive.orig_upload_id',
  student_user_id      INT NOT NULL,
  reviewer_id          INT NOT NULL,
  item_id              INT NOT NULL DEFAULT 14,
  research_group_name  VARCHAR(255) NULL,
  adviser_name         VARCHAR(255) NULL,
  approved_title       TEXT NULL,
  proponents           TEXT NULL,
  department           VARCHAR(255) NULL,
  assessment_responses LONGTEXT NULL,
  yes_count            INT NULL,
  final_score          INT NULL,
  final_decision       VARCHAR(50) NULL,
  comments             LONGTEXT NULL,
  created_at           TIMESTAMP NULL DEFAULT NULL,
  updated_at           TIMESTAMP NULL DEFAULT NULL,
  KEY idx_f8a_cycle (cycle_id),
  KEY idx_f8a_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs_archive (
  archive_id       INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id         INT NOT NULL,
  orig_activity_id INT NOT NULL,
  user_id          INT NOT NULL,
  upload_id        INT NULL COMMENT 'Points at uploads_archive.orig_upload_id',
  title            VARCHAR(100) NOT NULL,
  description      TEXT NOT NULL,
  status_type      VARCHAR(30) NULL DEFAULT 'info',
  created_at       TIMESTAMP NULL DEFAULT NULL,
  KEY idx_ala_cycle (cycle_id),
  KEY idx_ala_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_logs_archive (
  archive_id   INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id     INT NOT NULL,
  orig_log_id  INT NOT NULL,
  form_id      INT NOT NULL,
  student_id   INT NOT NULL,
  actor_id     INT NOT NULL,
  action       VARCHAR(20) NOT NULL,
  control_no   VARCHAR(60) NULL,
  item_id      INT NULL,
  remarks      TEXT NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  KEY idx_pla_cycle (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- users.year_level — personal, like student_number. NEVER inherited from the
-- leader the way research_group_name / department / program are. Nullable so
-- existing accounts stay valid; the reset stamps a copy onto research_cycles.
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'year_level'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN year_level VARCHAR(20) NULL AFTER program',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
