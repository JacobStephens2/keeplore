<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/Mailer.php';

/**
 * Mailer over SMTP with STARTTLS, from the app and with replies to the
 * developer. The only mail code that knows PHPMailer.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName,
        private string $replyToAddress,
        private string $replyToName,
    ) {
    }

    /** The SMTP settings, From and Reply-To from environment_variables.php. */
    public static function fromEnvironment(): self
    {
        return new self(SMTP_HOST, (int) SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL, APP_NAME, DEV_EMAIL, DEV_NAME);
    }

    public function send(string $to, string $subject, string $html): void
    {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->port;
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($this->replyToAddress, $this->replyToName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }
    }
}
