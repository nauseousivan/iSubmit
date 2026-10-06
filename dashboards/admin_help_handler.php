<?php
// dashboards/admin_help_handler.php — the Admin's Help Desk writes (2026-10-01). ADMIN ONLY.
//
// Actions:
//   answer  — reply to a request and mark it answered. Optionally RESET the linked account's password
//             to a temporary one the person must change at the next sign-in (users.must_change_password),
//             and optionally put that temporary password in the reply. The reply is emailed to the
//             address the person gave, and — when the request belongs to an account — also lands in
//             their portal notifications (an activity_logs 'success' row, the notification kind).
//   reopen  — put an answered request back in the queue.
//
// Same conventions as the other admin handlers: POST-only, CSRF, flash + redirect back.
// The temporary password is shown to the Admin once in the flash; it is never stored in clear.

require_once '../config/db.php';
require_once '../config/mail.php';

if (empty($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_help.php'); exit; }

$actor = (int)$_SESSION['user_id'];
function hd_finish(string $msg, string $type): void
{
    $_SESSION['message'] = $msg;
    $_SESSION['message_type'] = $type;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'admin_help.php'));
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    hd_finish('Invalid security token. Please refresh and try again.', 'error');
}

$id  = (int)($_POST['request_id'] ?? 0);
$req = $pdo->prepare("SELECT * FROM help_requests WHERE request_id = ?");
$req->execute([$id]);
$req = $req->fetch(PDO::FETCH_ASSOC);
if (!$req) hd_finish('That request no longer exists.', 'error');
$action = (string)($_POST['action'] ?? '');

// ------------------------------------------------------------------ reopen
if ($action === 'reopen') {
    $pdo->prepare("UPDATE help_requests SET status = 'open' WHERE request_id = ?")->execute([$id]);
    hd_finish('Moved back to Open.', 'success');
}

// ------------------------------------------------------------------ answer (+ optional reset)
if ($action === 'answer') {
    $reply = trim((string)($_POST['reply'] ?? ''));
    if (mb_strlen($reply) < 2) hd_finish('Write a reply first.', 'error');
    if (mb_strlen($reply) > 3000) hd_finish('That reply is too long (3000 characters at most).', 'error');

    // The account this request is about: the Admin picks it on the screen from the likely matches.
    $link = (int)($_POST['link_user_id'] ?? 0);
    $acct = null;
    if ($link) {
        $st = $pdo->prepare("SELECT user_id, username, email, role FROM users WHERE user_id = ?");
        $st->execute([$link]);
        $acct = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $temp = null;
    if (!empty($_POST['reset_password'])) {
        if (!$acct) hd_finish('Pick the account to reset first.', 'error');
        if ($acct['role'] === 'Admin') hd_finish('An Admin account is not reset from the Help Desk.', 'error');
        // Readable temporary password: no look-alike characters, so it survives being read aloud.
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) $temp .= $abc[random_int(0, strlen($abc) - 1)];
        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ?")
            ->execute([password_hash($temp, PASSWORD_BCRYPT), (int)$acct['user_id']]);
    }
    $sendTemp = $temp !== null && !empty($_POST['include_password']);

    $pdo->prepare("UPDATE help_requests SET status = 'resolved', admin_reply = ?, resolved_by = ?, resolved_at = NOW(),
                          user_id = COALESCE(user_id, ?) WHERE request_id = ?")
        ->execute([$reply . ($sendTemp ? "\n\n(A temporary password was sent by email.)" : ''), $actor, $acct ? (int)$acct['user_id'] : null, $id]);

    // Email the reply to the address they gave. Mail failing must not undo the answer.
    $lines = [nl2br(htmlspecialchars($reply))];
    if ($sendTemp) {
        $lines[] = 'Sign in with the temporary password below. You will be asked to choose your own right away.';
    }
    $mailed = false;
    try {
        $mailed = (bool)sendSystemEmail($req['email'], 'Reply from the iSubmit administrator',
            rd_mail_html('Hi ' . htmlspecialchars($req['name']) . ', here is our reply', $lines,
                $sendTemp ? htmlspecialchars($temp) : null,
                'Your request: &ldquo;' . htmlspecialchars(mb_strimwidth($req['message'], 0, 160, '…')) . '&rdquo;'));
    } catch (Throwable $e) {
    }

    // In the portal too, when the request belongs to an account (the bell reads 'success' rows).
    $owner = (int)($req['user_id'] ?: ($acct['user_id'] ?? 0));
    if ($owner) {
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, 'Help request answered', ?, 'success', CURRENT_TIMESTAMP)")
                ->execute([$owner, mb_strimwidth('The administrator replied: ' . $reply, 0, 480, '…')]);
        } catch (Throwable $e) {
        }
    }

    $done = 'Answered' . ($mailed ? ' and emailed to ' . $req['email'] : ' (the email could not be sent — the reply is still saved)') . '.';
    if ($temp !== null) {
        $done .= ' Temporary password for ' . $acct['username'] . ': ' . $temp . ' — they must change it when they sign in.';
    }
    hd_finish($done, 'success');
}

hd_finish('Unknown action.', 'error');
