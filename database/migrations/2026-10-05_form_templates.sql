-- 2026-10-05 — Blank forms the Admin maintains (Admin → System → Forms).
--
-- The schools change their form formats from time to time, and MCNP and ISAP each print their own.
-- Every blank a student downloads is a SLOT in config/forms.php (application, endorsement, ...);
-- this table holds what the Admin uploaded for a slot + institution. One row per upload, never
-- deleted: replacing a form only flips is_current, so an older version can be put back.
-- No current row = the file that ships with the system (the catalogue's default) is used, so the
-- downloads keep working before anything is uploaded.
CREATE TABLE IF NOT EXISTS form_templates (
    template_id    INT(11)      NOT NULL AUTO_INCREMENT,
    slot           VARCHAR(40)  NOT NULL,                 -- a key of rd_form_catalogue()
    institution    VARCHAR(10)  NOT NULL,                 -- 'MCNP' | 'ISAP'
    file_path      VARCHAR(255) NOT NULL,                 -- relative to the app root, e.g. forms/application_mcnp_20261005_101500.pdf
    original_name  VARCHAR(255) NULL DEFAULT NULL,
    file_size      INT(11)      NULL DEFAULT NULL,
    note           VARCHAR(200) NULL DEFAULT NULL,        -- the Admin's own note: "2026 revision", ...
    is_current     TINYINT(1)   NOT NULL DEFAULT 1,
    uploaded_by    INT(11)      NULL DEFAULT NULL,
    uploaded_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    KEY idx_form_current (slot, institution, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
