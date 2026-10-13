<?php
/**
 * Shared research-group membership helpers.
 * Used by auth/register.php and dashboards/members.php.
 */

function get_dept_code($department_raw) {
    if (!$department_raw) return '';
    if (strpos($department_raw, 'Medical Colleges') !== false) { return 'MCNP'; }
    if (strpos($department_raw, 'International School') !== false) { return 'ISAP'; }
    return $department_raw;
}

function link_student_to_leader(PDO $pdo, int $leader_user_id, array $leader_row, int $student_user_id): string {
    $grp = trim($leader_row['research_group_name'] ?? '');
    if (empty($grp)) {
        $grp = "My Group";
        $pdo->prepare("UPDATE users SET research_group_name = ? WHERE user_id = ?")->execute([$grp, $leader_user_id]);
    }
    $pdo->prepare("UPDATE users SET leader_id = ?, research_group_name = ? WHERE user_id = ?")
        ->execute([$leader_user_id, $grp, $student_user_id]);
    return $grp;
}

function create_or_reactivate_invite(PDO $pdo, int $leader_id, string $invitee_email): void {
    $email = strtolower(trim($invitee_email));
    $stmt = $pdo->prepare("INSERT INTO group_invites (leader_id, invitee_email, status, created_at, accepted_at)
        VALUES (?, ?, 'pending', NOW(), NULL)
        ON DUPLICATE KEY UPDATE status = 'pending', created_at = NOW(), accepted_at = NULL");
    $stmt->execute([$leader_id, $email]);
}

/**
 * Called right after a new user account is created. Resolves any pending
 * group_invites for that email against the newly created account.
 *
 * @param int|null $already_linked_leader_id leader_id already set on the new
 *   account via the explicit "leader_email" registration flow, if any.
 * @return array{linked: bool, leader_username?: string}
 */
function consume_pending_invites_for_new_account(PDO $pdo, int $new_user_id, string $email, ?int $already_linked_leader_id): array {
    $email = strtolower(trim($email));

    $stmt = $pdo->prepare("SELECT * FROM group_invites WHERE invitee_email = ? AND status = 'pending' ORDER BY created_at ASC");
    $stmt->execute([$email]);
    $invites = $stmt->fetchAll();

    if (empty($invites)) {
        return ['linked' => false];
    }

    if ($already_linked_leader_id !== null) {
        // Account already linked via the explicit leader-email flow; that choice wins.
        // Reconcile invite records so they don't linger forever.
        foreach ($invites as $invite) {
            if ((int)$invite['leader_id'] === $already_linked_leader_id) {
                $pdo->prepare("UPDATE group_invites SET status = 'accepted', accepted_at = NOW() WHERE id = ?")->execute([$invite['id']]);
            } else {
                $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE id = ?")->execute([$invite['id']]);
            }
        }
        return ['linked' => false];
    }

    $stmt_new = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
    $stmt_new->execute([$new_user_id]);
    $new_dept_code = get_dept_code($stmt_new->fetchColumn() ?: '');

    $linked = false;
    $leader_username = null;

    foreach ($invites as $invite) {
        if ($linked) {
            // Superseded by an earlier (oldest) invite that already succeeded.
            $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE id = ?")->execute([$invite['id']]);
            continue;
        }

        $stmt_leader = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt_leader->execute([$invite['leader_id']]);
        $leader_row = $stmt_leader->fetch();

        if (!$leader_row || $leader_row['leader_id'] !== null) {
            $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE id = ?")->execute([$invite['id']]);
            continue;
        }

        if (get_dept_code($leader_row['department'] ?? '') !== $new_dept_code) {
            $pdo->prepare("UPDATE group_invites SET status = 'cancelled' WHERE id = ?")->execute([$invite['id']]);
            continue;
        }

        link_student_to_leader($pdo, (int)$invite['leader_id'], $leader_row, $new_user_id);
        $pdo->prepare("UPDATE group_invites SET status = 'accepted', accepted_at = NOW() WHERE id = ?")->execute([$invite['id']]);
        $linked = true;
        $leader_username = $leader_row['username'];
    }

    return $linked ? ['linked' => true, 'leader_username' => $leader_username] : ['linked' => false];
}

function send_invite_email(string $to, string $leaderUsername, string $groupName): bool {
    $safeLeader = htmlspecialchars($leaderUsername);
    $safeGroup = htmlspecialchars($groupName ?: 'their research group');
    $body = '
        <div style="font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f7f4eb; padding: 40px 10px; text-align: center; color: #2b261f;">
            <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(12,52,61,0.06); border-top: 6px solid #1e40af; padding: 40px; text-align: left;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h2 style="color: #0c343d; font-size: 22px; font-weight: bold; margin-top: 0; margin-bottom: 5px; font-family: \'Georgia\', serif;">MCNP-ISAP Research Portal</h2>
                    <p style="color: #7d7569; font-size: 13px; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Research Group Invitation</p>
                </div>
                <p style="font-size: 16px; line-height: 1.6; margin: 0; color: #2b261f;">
                    <strong style="color: #1e40af;">' . $safeLeader . '</strong> has invited you to join <strong>' . $safeGroup . '</strong> on the Research Portal.
                </p>
                <p style="font-size: 15px; line-height: 1.6; margin-top: 14px; color: #4a453e;">
                    To join automatically, register an account using <strong>this exact email address</strong> (' . htmlspecialchars($to) . ') under the same department as your group leader. You will be linked to the group as soon as your registration is complete.
                </p>
                <p style="color: #7d7569; font-size: 13px; line-height: 1.5; margin-top: 25px; margin-bottom: 0;">
                    If you were not expecting this invitation, you can safely ignore this email.
                </p>
            </div>
        </div>';
    return sendSystemEmail($to, "You've been invited to a research group", $body);
}

/* ---------------------------------------------------------------------------------------------
 * JOIN BY QR (2026-10-07). The leader shows a QR on members.php; a teammate scans it and signs up
 * on auth/join.php with just name, School ID, email and password — no emailed code, because the
 * leader handing over the QR in person IS the check. The group facts (school, course, section,
 * title) come from the leader, as in the leader-email flow of register.php.
 * The token is SIGNED, not stored: "{leader}.{expires}.{sig}", valid GROUP_JOIN_TTL seconds, so
 * there is no table to migrate. A wrong joiner is fixed the usual way: the leader removes them.
 * ------------------------------------------------------------------------------------------- */
const GROUP_JOIN_TTL = 86400;   // 24 hours

function group_join_sig(int $leader_id, int $expires): string
{
    $secret = (string)rd_setting('qr_secret', 'isubmit-group-join');
    return substr(hash_hmac('sha256', 'groupjoin|' . $leader_id . '|' . $expires, $secret), 0, 24);
}

function group_join_token(int $leader_id): array
{
    $expires = time() + GROUP_JOIN_TTL;
    return ['token' => $leader_id . '.' . $expires . '.' . group_join_sig($leader_id, $expires), 'expires' => $expires];
}

/**
 * The leader row a join token opens, or null with a reason a person can read. Refuses a forged
 * or expired token, and a leader who is no longer a leader (joined someone else, disabled).
 */
function group_join_leader(PDO $pdo, string $token, ?string &$why = null): ?array
{
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{24})$/', $token, $m)) { $why = 'This QR code is not a valid group invite.'; return null; }
    [, $leader_id, $expires, $sig] = $m;
    if (!hash_equals(group_join_sig((int)$leader_id, (int)$expires), $sig)) { $why = 'This QR code is not a valid group invite.'; return null; }
    if ((int)$expires < time()) { $why = 'This QR code has expired. Ask your leader to show a new one.'; return null; }

    $st = $pdo->prepare("SELECT user_id, username, email, department, program, section_id, research_group_name, research_title, account_status
                         FROM users WHERE user_id = ? AND role = 'Student' AND leader_id IS NULL");
    $st->execute([(int)$leader_id]);
    $leader = $st->fetch(PDO::FETCH_ASSOC);
    if (!$leader || $leader['account_status'] !== 'active') { $why = 'This group can no longer be joined. Ask your leader for help.'; return null; }
    return $leader;
}
