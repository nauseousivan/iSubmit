-- ============================================================================
-- Group Invites: pending invitations for research-group membership (2026-07-08)
-- Idempotent: safe to run multiple times (MariaDB 10.4+).
-- Backs the invite flow in dashboards/members.php and auth/register.php.
-- The legacy `research_group_members` table (free-text names, write-only,
-- never read anywhere) is left in place untouched but is no longer written to.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `group_invites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `leader_id` INT(11) NOT NULL,
  `invitee_email` VARCHAR(100) NOT NULL,
  `status` ENUM('pending','accepted','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_leader_invitee` (`leader_id`, `invitee_email`),
  KEY `idx_invitee_status` (`invitee_email`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
