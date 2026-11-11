<?php
declare(strict_types=1);

namespace Lfmis\Core;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        $host = Env::get('LFMIS_SMTP_HOST');
        $from = filter_var((string) Env::get('LFMIS_MAIL_FROM'), FILTER_VALIDATE_EMAIL);
        if ($host === null || $from === false) {
            throw new DeliveryException('Email delivery is not configured. Set the SMTP host and a valid sender address in .env.local.');
        }

        $username = Env::get('LFMIS_SMTP_USER', '');
        $password = Env::get('LFMIS_SMTP_PASSWORD', '');
        if (($username === '') !== ($password === '')) {
            throw new DeliveryException('Email delivery is not configured correctly. Set both SMTP username and password, or leave both blank.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) Env::get('LFMIS_SMTP_PORT', '587');
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = $password;
            $secure = strtolower((string) Env::get('LFMIS_SMTP_SECURE', 'tls'));
            if ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure !== 'none') {
                throw new DeliveryException('Email delivery is not configured correctly. SMTP security must be tls, ssl, or none.');
            }
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, Env::get('LFMIS_MAIL_FROM_NAME', 'Flouri Farm LFMIS'));
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $html ?? $text;
            $mail->AltBody = $text;
            $mail->isHTML($html !== null);
            $mail->send();
            return true;
        } catch (PHPMailerException $error) {
            error_log('SMTP delivery failed. Check the SMTP host, port, encryption, credentials, sender, and provider logs.');
            throw new DeliveryException('Email delivery failed. Check the SMTP settings and provider logs.', 0, $error);
        }
    }
}
