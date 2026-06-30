<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

function sendSystemEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'mcnpisap2025@gmail.com'; // Put your real Gmail here
        $mail->Password   = 'vhax mwff aajl aamc';         // Put your 16-character App Password here
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('ivan.manalo205@gmail.com', 'MCNP-ISAP Research Office'); // Ensure this matches your Username
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("SMTP Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>