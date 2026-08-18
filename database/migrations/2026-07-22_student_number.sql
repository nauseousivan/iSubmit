-- ============================================================================
-- School ID Number on student accounts (e.g. 2023-10078)
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
-- ============================================================================

-- Named `student_number`, not `school_id`: `users.department` already stores the
-- school (MCNP vs. ISAP), so a `school_id` column would read like a foreign key
-- to a schools table. The UI label stays "School ID Number".
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `student_number` VARCHAR(20) NULL AFTER `email`;

-- UNIQUE allows unlimited NULLs in MySQL/MariaDB, so every pre-existing account
-- coexists fine while it's still blank; only real values are constrained.
-- Writers must normalise '' -> NULL, otherwise two blank saves collide here.
ALTER TABLE `users`
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_student_number` (`student_number`);
