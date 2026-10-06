-- 2026-09-19 — sections, and the Focal Person -> Coordinator -> Secretary schedule chain.
--
-- WHY SECTIONS ARE A TABLE AND NOT A COLUMN ON users
-- A section is a room ("BSIT 4-A"): one course, one year level, many research groups. If each
-- student typed their own section as free text it would arrive as A / a / Section A / 4A / IV-A
-- and one section card would fragment into six. The office defines the list; students pick from
-- it. Only the group LEADER picks — members inherit section_id the same way they already inherit
-- research_group_name / department / program.

CREATE TABLE IF NOT EXISTS sections (
    section_id INT(11)      NOT NULL AUTO_INCREMENT,
    department VARCHAR(100) NOT NULL COMMENT 'school: MCNP / ISAP — matches users.department',
    program    VARCHAR(100) NOT NULL COMMENT 'course, matches users.program',
    year_level VARCHAR(20)      NULL,
    label      VARCHAR(60)  NOT NULL COMMENT 'what the office calls the room, e.g. BSIT 4-A',
    created_by INT(11)          NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id),
    UNIQUE KEY uniq_section_label (department, program, label),
    KEY idx_section_course (department, program)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ON DELETE SET NULL: removing a section must never delete students. They fall back into the
-- focal person's "Unassigned" tray, which is exactly where someone should look at them again.
ALTER TABLE users
    ADD COLUMN section_id INT(11) NULL DEFAULT NULL AFTER year_level,
    ADD KEY idx_users_section (section_id),
    ADD CONSTRAINT fk_users_section FOREIGN KEY (section_id) REFERENCES sections (section_id) ON DELETE SET NULL;


-- THE SCHEDULE CHAIN
-- One batch = one section's groups placed on one date. The Focal Person consolidates and
-- endorses; the Coordinator confirms; the Secretary finalises. The chain hangs on the BATCH,
-- not on each requirement — otherwise a coordinator would be tapped 5 requirements x 8 groups
-- = 40 times per section. Requirement approval stays exactly as it is today (a Focal Person's
-- Approve writes 'Approved' and the student goes green immediately); only the DATE waits for
-- all three signatures.
CREATE TABLE IF NOT EXISTS defense_batches (
    batch_id     INT(11) NOT NULL AUTO_INCREMENT,
    section_id   INT(11) NOT NULL,
    phase        ENUM('proposal','final') NOT NULL,
    defense_date DATE    NOT NULL,
    status       ENUM('Endorsed','Confirmed','Scheduled','Returned','Cancelled')
                 NOT NULL DEFAULT 'Endorsed',
    -- one column trio per signature; there are exactly three steps, so a generic sign-off
    -- table would be more machinery than the problem has.
    focal_by     INT(11) NULL, focal_at     DATETIME NULL,
    coord_by     INT(11) NULL, coord_at     DATETIME NULL,
    sec_by       INT(11) NULL, sec_at       DATETIME NULL,
    returned_by  INT(11) NULL, returned_at  DATETIME NULL,
    remarks      TEXT        NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    KEY idx_batch_section (section_id, phase),
    KEY idx_batch_status (status, defense_date),
    CONSTRAINT fk_batch_section FOREIGN KEY (section_id) REFERENCES sections (section_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The per-group rows stay in defense_bookings (they already carry slot_no, phase and the
-- application upload); the batch just groups them. No third table.
ALTER TABLE defense_bookings
    ADD COLUMN batch_id INT(11) NULL DEFAULT NULL AFTER phase,
    ADD KEY idx_booking_batch (batch_id),
    ADD CONSTRAINT fk_booking_batch FOREIGN KEY (batch_id) REFERENCES defense_batches (batch_id) ON DELETE SET NULL;
