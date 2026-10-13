<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

// Gmail rejects (or silently rewrites) a From that is not the authenticated account, so the two
// MUST be the same address — they were not, which is why milestone mail could vanish without an
// error anyone would see. The human-facing address goes on Reply-To instead.
const RD_MAIL_USER  = 'mcnpisap2025@gmail.com';
const RD_MAIL_NAME  = 'MCNP-ISAP Research Office';
const RD_MAIL_REPLY = 'ivan.manalo205@gmail.com';

// The Gmail app password lives in config/mail_secret.php (gitignored) — never in this file: the repo
// is public. No secret file = no mail (sendSystemEmail() returns false), never a crash.
function rd_mail_password(): string
{
    static $pw = null;
    if ($pw === null) {
        $file = __DIR__ . '/mail_secret.php';
        $pw = is_file($file) ? (string)(require $file) : (string)getenv('RD_MAIL_PASSWORD');
    }
    return $pw;
}

function sendSystemEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bdf57f001@smtp-brevo.com';
        $mail->Password   = rd_mail_password();   // Gmail app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 2525;
        $mail->Timeout    = 8;                        // never hang a student's page load on SMTP

        $mail->setFrom(RD_MAIL_USER, RD_MAIL_NAME);
        $mail->addReplyTo(RD_MAIL_REPLY, RD_MAIL_NAME);
        $mail->addAddress($to);

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $body)), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("SMTP Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * One look for every milestone mail. Email clients ignore <style> blocks, so everything is inline.
 *
 * @param string      $title  the headline — what happened
 * @param string[]    $lines  paragraphs of body copy (already escaped by the caller if needed)
 * @param ?string     $badge  a value worth pulling out big: a control number, a date, a slot
 * @param ?string     $note   small print under the body
 */
function rd_mail_html(string $title, array $lines, ?string $badge = null, ?string $note = null): string
{
    $body = '';
    foreach ($lines as $l) {
        $body .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">' . $l . '</p>';
    }
    $badgeHtml = $badge === null ? '' :
        '<div style="margin:18px 0;padding:14px 18px;background:#f5f3ff;border:1px solid #ddd6fe;'
        . 'border-radius:12px;font-family:\'Courier New\',monospace;font-size:18px;font-weight:700;'
        . 'color:#4c1d95;text-align:center;letter-spacing:.03em;">' . $badge . '</div>';
    $noteHtml = $note === null ? '' :
        '<p style="margin:16px 0 0;font-size:13px;line-height:1.55;color:#64748b;">' . $note . '</p>';

    return '<div style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">'
        . '<div style="padding:18px 26px;background:#4c1d95;color:#ffffff;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">'
        . 'MCNP-ISAP Research Office</div>'
        . '<div style="padding:26px;">'
        . '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#0f172a;">' . $title . '</h1>'
        . $body . $badgeHtml . $noteHtml
        . '</div>'
        . '<div style="padding:16px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;line-height:1.5;">'
        . 'Sent automatically by iSubmit. Open the portal to see the full details — replies to this '
        . 'message reach the research office.</div>'
        . '</div></div>';
}

/**
 * Mail a whole research group: the leader and every member, since a milestone belongs to all of
 * them.
 *
 * ONE SMTP transaction, not one per person. A Gmail handshake costs ~6 seconds, and these are
 * called inline from a staff click ("Log & Schedule", "Register & verify") — looping per member
 * would have left the office staring at a spinner for half a minute on a four-person group.
 * Members go in BCC so nobody's address is published to the rest, and so a single bad address
 * cannot bounce the whole message.
 *
 * Never throws. A mail server having a bad day must not stop a defense being scheduled, so every
 * failure is logged and swallowed. Returns the number of addresses the message went to.
 */
function sendGroupEmail(PDO $pdo, int $leaderUserId, string $subject, string $html): int
{
    try {
        $stmt = $pdo->prepare("SELECT user_id, email FROM users
                               WHERE (user_id = ? OR leader_id = ?) AND email IS NOT NULL AND email <> ''
                               ORDER BY (user_id = ?) DESC");
        $stmt->execute([$leaderUserId, $leaderUserId, $leaderUserId]);

        $to = null; $bcc = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addr = trim($row['email']);
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
            if ($to === null) { $to = $addr; continue; }
            if ($addr !== $to) $bcc[$addr] = true;
        }
        if ($to === null) return 0;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bdf57f001@smtp-brevo.com';
        $mail->Password   = rd_mail_password();
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 2525;
        $mail->Timeout    = 8;

        $mail->setFrom(RD_MAIL_USER, RD_MAIL_NAME);
        $mail->addReplyTo(RD_MAIL_REPLY, RD_MAIL_NAME);
        $mail->addAddress($to);
        foreach (array_keys($bcc) as $b) $mail->addBCC($b);

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        return 1 + count($bcc);
    } catch (Throwable $e) {
        error_log('sendGroupEmail failed: ' . $e->getMessage());
        return 0;
    }
}
