-- Final Defense: Apply -> Schedule stage (RDC "Application for Final Defense", steps 1-3)
--
-- Adds the front stage the portal was missing: a group must upload Form 002 and be given a
-- date by the research office before the Form 005 requirements (22-27) open up.
--
-- Availability model: the office is open by DEFAULT. Rather than publishing individual slots,
-- staff close the exceptions — holidays, closures, booked-out days — in `defense_blackouts`.
-- A date is bookable when it is inside the booking window, is not a Sunday, is not blacked
-- out, and is not already held by another group.
--
-- Idempotent: safe to re-run on the defense machine.
--   mysql -u root digital_research < docs/sql/2026-08-defense-scheduling.sql

-- Dates the office has CLOSED. Absence of a row means the date is available.
-- `source` records where the closure came from: closed by hand, imported from the public
-- holiday feed, or written through from an entry on the office calendar (calendar_events).
CREATE TABLE IF NOT EXISTS defense_blackouts (
  blackout_id INT AUTO_INCREMENT PRIMARY KEY,
  phase       ENUM('proposal','final') NOT NULL DEFAULT 'final',
  block_date  DATE NOT NULL,
  reason      VARCHAR(150) NULL,
  source      ENUM('manual','holiday','event') NOT NULL DEFAULT 'manual',
  created_by  INT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_blackout (phase, block_date),
  KEY idx_block_date (block_date),
  CONSTRAINT fk_blackout_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per group per phase. `user_id` is the LEADER id (effective_user_id), matching how
-- `uploads` rows are keyed, so any member of a group sees the same booking.
CREATE TABLE IF NOT EXISTS defense_bookings (
  booking_id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id               INT NOT NULL,
  phase                 ENUM('proposal','final') NOT NULL DEFAULT 'final',
  defense_date          DATE NULL,
  application_upload_id INT NULL,
  status  ENUM('Applied','Date Selected','Scheduled','Rejected','Cancelled') NOT NULL DEFAULT 'Applied',
  decided_by            INT NULL,
  decided_at            DATETIME NULL,
  remarks               TEXT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_group_phase (user_id, phase),
  KEY idx_booking_date (defense_date),
  CONSTRAINT fk_booking_user   FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_upload FOREIGN KEY (application_upload_id) REFERENCES uploads(upload_id) ON DELETE SET NULL,
  CONSTRAINT fk_booking_decider FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Form 002 rides the normal upload path (upload_handler.php), so it needs a checklist row.
-- form_id 2 = Final Phase. Deliberately NOT added to admin_module_dynamic's 'final' items
-- array: the application has exactly one review path, the schedule view.
INSERT INTO checklist_items (item_id, form_id, item_name, description)
VALUES (20, 2, 'Application for Research Defense (Form 002)',
        'Download the form, fill it out, have it signed, then upload it to request a defense date.')
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), description = VALUES(description);
