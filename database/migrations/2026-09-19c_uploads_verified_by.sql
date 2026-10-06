-- 2026-09-19c — record WHO decided a requirement, and when.
--
-- Until now a verdict left no trace of its author. `uploads.verification_status` says what was
-- decided; the activity_logs row written beside it is addressed to the STUDENT (user_id is the
-- student, so the group sees it in their feed), which means the reviewer's identity was never
-- stored anywhere. With three roles able to clear a requirement — Focal Person, Coordinator,
-- Director — "Approved" on its own stopped being enough: the office needs to see whose approval
-- it is before relying on it.
--
-- Nullable on purpose: every row that predates this keeps a NULL author rather than being
-- attributed to someone who did not decide it. The UI reads NULL as "approved before this was
-- tracked", never as a name.

ALTER TABLE uploads
    ADD COLUMN verified_by INT(11)  NULL DEFAULT NULL AFTER verification_status,
    ADD COLUMN verified_at DATETIME NULL DEFAULT NULL AFTER verified_by,
    ADD KEY idx_uploads_verified_by (verified_by),
    ADD CONSTRAINT fk_uploads_verified_by FOREIGN KEY (verified_by) REFERENCES users (user_id) ON DELETE SET NULL;
