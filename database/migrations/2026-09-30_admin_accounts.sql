-- 2026-09-30 — the Admin role and account approval.
--
-- The evaluators asked for a system administrator. The office keeps what it has — the Director
-- and each Coordinator still CREATE staff accounts from the Staff Accounts screen — but an
-- account they create now starts PENDING and cannot sign in until the Admin approves it. An
-- account the Admin creates is active at once. The Admin also maintains student accounts
-- (fix details, reset a password, disable, re-enable) and can VIEW the research workflow, never
-- act in it.
--
-- account_status is separate from is_verified on purpose. is_verified is the EMAIL-OTP flag: a
-- login with is_verified = 0 is sent to verify_otp.php, and verifying sets it back to 1. That
-- made the old "disable" (is_verified = 0) undoable by the disabled person themself — sign in,
-- receive a code, type it, back in. account_status is checked at login BEFORE the OTP branch and
-- on every request (config/db.php), and nothing a user can do to their own row changes it.
--
-- For STAFF the two are kept in step (active <=> is_verified = 1), because several queries pick
-- the staff to email with `is_verified = 1` and must keep skipping pending/disabled accounts.
-- For STUDENTS is_verified keeps meaning "email verified" and account_status is independent.

ALTER TABLE users
    MODIFY COLUMN role ENUM('Student','Research Coordinator','Statistician','Research Director','Focal Person','Admin') NOT NULL,
    ADD COLUMN account_status ENUM('active','pending','rejected','disabled') NOT NULL DEFAULT 'active' AFTER is_verified,
    ADD COLUMN created_by  INT(11)      NULL DEFAULT NULL AFTER account_status,
    ADD COLUMN reviewed_by INT(11)      NULL DEFAULT NULL AFTER created_by,
    ADD COLUMN reviewed_at DATETIME     NULL DEFAULT NULL AFTER reviewed_by,
    ADD COLUMN review_note VARCHAR(255) NULL DEFAULT NULL AFTER reviewed_at,
    ADD KEY idx_users_account_status (account_status);

-- Staff that were switched off the old way are disabled, not "unverified".
UPDATE users SET account_status = 'disabled' WHERE role <> 'Student' AND is_verified = 0;
