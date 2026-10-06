-- 2026-10-01 — the Admin's system tools: Help Desk, forced password change, access log + lockout,
-- system settings. All additive; nothing existing is altered except one new users column.

-- A person who cannot sign in (lost the inbox, typed the wrong email) used to walk to the IT office.
-- They write here instead — from the login page without an account, or from inside the portal.
-- user_id is set when the sender is signed in, or when the Admin links the request to an account.
CREATE TABLE IF NOT EXISTS help_requests (
    request_id     INT(11)      NOT NULL AUTO_INCREMENT,
    user_id        INT(11)      NULL DEFAULT NULL,
    name           VARCHAR(100) NOT NULL,
    email          VARCHAR(100) NOT NULL,
    student_number VARCHAR(20)  NULL DEFAULT NULL,
    category       ENUM('email_access','password','fix_details','group_change','suggestion','other') NOT NULL DEFAULT 'other',
    message        TEXT         NOT NULL,
    status         ENUM('open','resolved') NOT NULL DEFAULT 'open',
    admin_reply    TEXT         NULL DEFAULT NULL,
    resolved_by    INT(11)      NULL DEFAULT NULL,
    resolved_at    DATETIME     NULL DEFAULT NULL,
    ip             VARCHAR(45)  NULL DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_help_status (status, created_at),
    KEY idx_help_user (user_id),
    KEY idx_help_ip (ip, created_at),
    CONSTRAINT fk_help_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_help_resolver FOREIGN KEY (resolved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every sign-in attempt. `reason`: ok · wrong_password · no_account · locked · inactive · maintenance · unlock
-- (an 'unlock' row is the Admin clearing a lockout: failures are counted after the latest ok/unlock).
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id  INT(11)      NOT NULL AUTO_INCREMENT,
    user_id     INT(11)      NULL DEFAULT NULL,
    email       VARCHAR(100) NOT NULL,
    success     TINYINT(1)   NOT NULL DEFAULT 0,
    reason      VARCHAR(20)  NOT NULL,
    ip          VARCHAR(45)  NULL DEFAULT NULL,
    user_agent  VARCHAR(255) NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id),
    KEY idx_login_email (email, created_at),
    KEY idx_login_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- System parameters the Admin can change without touching code. Read once per request in
-- config/db.php into $GLOBALS['RD_SETTINGS']; every reader has a default, so a missing row is safe.
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(60) NOT NULL,
    setting_value TEXT        NULL,
    updated_by    INT(11)     NULL DEFAULT NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('slots_per_day', '10'),
    ('lockout_attempts', '5'),
    ('lockout_minutes', '15'),
    ('registration_open', '1'),
    ('maintenance_mode', '0'),
    ('announcement_active', '0'),
    ('announcement_level', 'info'),
    ('announcement_text', '');

-- Set by the Admin's reset: the person must choose their own password at the next sign-in.
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status;
