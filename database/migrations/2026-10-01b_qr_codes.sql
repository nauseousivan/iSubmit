-- 2026-10-01b — QR codes.
--
-- 1. The Admin's QR codes (posters for the RDC office). A printed QR encodes a SHORT link,
--    {site}/q.php?c=CODE, never the destination itself, so the Admin can repoint a code without
--    reprinting it. Retiring a code (or giving it a new one) makes old printouts say so politely.
--    Only a change of the site address itself needs a reprint — that is what site_url is for.
CREATE TABLE IF NOT EXISTS qr_links (
    qr_id       INT(11)      NOT NULL AUTO_INCREMENT,
    code        VARCHAR(24)  NOT NULL,
    label       VARCHAR(80)  NOT NULL,
    caption     VARCHAR(200) NULL DEFAULT NULL,
    target      VARCHAR(255) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    scans       INT(11)      NOT NULL DEFAULT 0,
    last_scan   DATETIME     NULL DEFAULT NULL,
    created_by  INT(11)      NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (qr_id),
    UNIQUE KEY uniq_qr_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO qr_links (code, label, caption, target) VALUES
    ('isubmit', 'Open iSubmit', 'Scan to sign in to the research portal.', 'auth/login.php'),
    ('help',    'Need help?',   'Can\'t sign in or something on your account is wrong? Ask the administrator.', 'auth/help.php'),
    ('signup',  'Create your account', 'New student researcher? Register here.', 'auth/register.php');

-- 2. The statistician's desk QR is SIGNED with this secret (HMAC), so nobody can type another
--    group's number into the link. Rotating it invalidates every desk QR on students' screens
--    at once — they simply show a new one next time the card loads.
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('qr_secret', SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)),
    ('site_url', '');

-- 3. The four phase cards, for the RDC office printouts. A student scans, signs in, and lands on
--    that card — or, if the group has not reached that stage, sees the same lock message the card
--    shows (student.php's ?module= handler obeys the card locks). Added 2026-10-01.
INSERT IGNORE INTO qr_links (code, label, caption, target) VALUES
    ('proposal',   'Proposal Defense', 'Scan, sign in, and upload your proposal requirements.', 'dashboards/student.php?module=proposal'),
    ('statistics', 'Statistics',       'Opens once your proposal defense is scheduled.',       'dashboards/student.php?module=stats'),
    ('final',      'Final Defense',    'Opens once your statistics results are released.',     'dashboards/student.php?module=final'),
    ('plagiarism', 'Plagiarism Test',  'Opens once the research office calls for your final manuscript.', 'dashboards/student.php?module=plag');
