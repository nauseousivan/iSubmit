-- 2026-09-19 — Focal Person role + course scope
--
-- A Focal Person checks the requirements of the groups in the course(s) she handles and
-- consolidates a section into a proposed defense date. She sees ONLY her own courses.
--
-- Scope is a LIST of courses, not a single one: some focal persons cover several courses
-- because MCNP/ISAP group courses into departments. Modelling the scope as rows keeps that
-- flexible (1 course, 3 courses, or a co-focal sharing a course with someone else) without
-- inventing a department level — `users.department` already holds the SCHOOL (MCNP vs ISAP),
-- so the obvious column name is taken. If real departments are ever added they can simply
-- generate these rows.

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'Student',
        'Research Coordinator',
        'Statistician',
        'Research Director',
        'Focal Person'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS focal_scope (
    scope_id   INT(11)      NOT NULL AUTO_INCREMENT,
    user_id    INT(11)      NOT NULL,
    department VARCHAR(100) NOT NULL COMMENT 'school: MCNP / ISAP — matches users.department',
    program    VARCHAR(100) NOT NULL COMMENT 'course, matches users.program',
    created_by INT(11)          NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (scope_id),
    UNIQUE KEY uniq_focal_course (user_id, department, program),
    KEY idx_scope_course (department, program),
    CONSTRAINT fk_focal_scope_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
