<?php
/**
 * File purpose: implements Mailer for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class Mailer
{
    private static ?Closure $transport = null;

    /** Test hook. The callback receives the envelope and fully encoded message. */
    /**
 * Performs the use transport operation for this service.
 * @param ?Closure $transport Input used by this operation.
 * @return void Result of this operation.
 */
    public static function useTransport(?Closure $transport): void
    {
        self::$transport = $transport;
    }

    /**
 * Performs the send operation for this service.
 * @param string $to Input used by this operation.
 * @param string $subject Input used by this operation.
 * @param string $text Input used by this operation.
 * @param ?string $html Input used by this operation.
 * @return bool Result of this operation.
 */

    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        try {
            if (preg_match('/[\r\n]/', $to) || preg_match('/[\r\n]/', $subject)) {
                throw new RuntimeException('Email recipient or subject contains a line break.');
            }
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Email recipient address is invalid.');
            }
            $from = Env::get('LFMIS_MAIL_FROM', '');
            if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('LFMIS_MAIL_FROM is missing or invalid.');
            }

            $html = self::emailHtml($text, $html);
            $logoPath = dirname(__DIR__, 2) . '/public/flouri-farm-logo.png';
            $logo = is_readable($logoPath) ? file_get_contents($logoPath) : false;

            $headers = [
                'From' => $from,
                'MIME-Version' => '1.0',
                'Subject' => '=?UTF-8?B?' . base64_encode($subject) . '?=',
            ];
            if ($html === null) {
                $headers['Content-Type'] = 'text/plain; charset=UTF-8';
                $headers['Content-Transfer-Encoding'] = 'base64';
                $message = self::wrapBase64($text);
            } else {
                $boundary = '=_LFMIS_' . bin2hex(random_bytes(12));
                $relatedBoundary = '=_LFMIS_RELATED_' . bin2hex(random_bytes(12));
                $headers['Content-Type'] = 'multipart/related; boundary="' . $relatedBoundary . '"';
                $message = '--' . $relatedBoundary . "\r\nContent-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n\r\n"
                    . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . self::wrapBase64($text) . "\r\n--" . $boundary
                    . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . self::wrapBase64($html) . "\r\n--" . $boundary . "--\r\n";
                if (is_string($logo)) {
                    $message .= '--' . $relatedBoundary . "\r\nContent-Type: image/png; name=\"flouri-farm-logo.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <flouri-farm-logo>\r\nContent-Disposition: inline; filename=\"flouri-farm-logo.png\"\r\n\r\n"
                        . self::wrapBase64($logo) . "\r\n";
                }
                $message .= '--' . $relatedBoundary . "--\r\n";
            }
            $headers['To'] = $to;
            $wireMessage = implode("\r\n", array_map(
                static fn(string $name, string $value): string => $name . ': ' . $value,
                array_keys($headers),
                array_values($headers)
            )) . "\r\n\r\n" . $message;

            if (self::$transport !== null) {
                $sent = (bool) (self::$transport)($from, $to, $subject, $wireMessage);
            } else {
                $host = Env::get('LFMIS_SMTP_HOST');
                if ($host === null) {
                    $mailHeaders = $headers;
                    unset($mailHeaders['To'], $mailHeaders['Subject']);
                    $mailHeaders['Subject'] = '=?UTF-8?B?' . base64_encode($subject) . '?=';
                    $sent = mail($to, $mailHeaders['Subject'], $message, self::formatHeaders($mailHeaders));
                } else {
                    $secure = strtolower((string) Env::get('LFMIS_SMTP_SECURE', 'tls'));
                    if (!in_array($secure, ['tls', 'ssl'], true)) {
                        throw new RuntimeException('LFMIS_SMTP_SECURE must be tls or ssl.');
                    }
                    $user = Env::get('LFMIS_SMTP_USER', '');
                    $password = Env::get('LFMIS_SMTP_PASSWORD', '');
                    if (($user === '') !== ($password === '')) {
                        throw new RuntimeException('Both SMTP username and password are required.');
                    }
                    $mailer = new PHPMailer(true);
                    $mailer->isSMTP();
                    $mailer->Host = $host;
                    $mailer->Port = (int) Env::get('LFMIS_SMTP_PORT', '587');
                    $mailer->SMTPAuth = $user !== '';
                    if ($mailer->SMTPAuth) {
                        $mailer->Username = $user;
                        $mailer->Password = $password;
                    }
                    $mailer->SMTPSecure = $secure === 'ssl'
                        ? PHPMailer::ENCRYPTION_SMTPS
                        : PHPMailer::ENCRYPTION_STARTTLS;
                    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
                    $mailer->setFrom($from, Env::get('LFMIS_MAIL_FROM_NAME', 'Flouri Farm LFMIS'));
                    $mailer->addAddress($to);
                    $mailer->Subject = $subject;
                    if ($html === null) {
                        $mailer->isHTML(false);
                        $mailer->Body = $text;
                    } else {
                        $mailer->isHTML(true);
                        $mailer->Body = $html;
                        $mailer->AltBody = $text;
                        if (is_string($logo)) $mailer->addStringEmbeddedImage($logo, 'flouri-farm-logo', 'flouri-farm-logo.png', PHPMailer::ENCODING_BASE64, 'image/png');
                    }
                    $sent = $mailer->send();
                }
            }
            if (!$sent) {
                error_log('Email delivery failed: the transport did not accept the message.');
            }
            return $sent;
        } catch (Throwable $error) {
            error_log('Email delivery failed: ' . $error->getMessage());
            return false;
        }
    }

    /**
 * Performs the wrap base64 operation for this service.
 * @param string $body Input used by this operation.
 * @return string Result of this operation.
 */

    private static function wrapBase64(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
    }

    /**
 * Performs the email html operation for this service.
 * @param string $text Input used by this operation.
 * @param ?string $html Input used by this operation.
 * @return string Result of this operation.
 */

    private static function emailHtml(string $text, ?string $html): string
    {
        $content = $html ?? '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        $logoPath = dirname(__DIR__, 2) . '/public/flouri-farm-logo.png';
        $logo = is_readable($logoPath) ? '<img src="cid:flouri-farm-logo" alt="Flouri Farm" width="220" style="display:block;width:220px;height:auto;border:0;margin:0 0 20px">' : '<strong style="font-size:20px;color:#173f35">Flouri Farm</strong>';
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f5ef;font-family:Arial,sans-serif;color:#19352d"><div style="max-width:600px;margin:auto;padding:28px;background:#fffdf7;border-radius:10px">'
            . $logo . $content . '<p style="margin-top:28px;color:#668078;font-size:12px">Flouri Farm · Livestock Farm Management</p></div></body></html>';
    }

    /**
 * Performs the format headers operation for this service.
 * @param array $headers Input used by this operation.
 * @return string Result of this operation.
 */

    private static function formatHeaders(array $headers): string
    {
        return implode("\r\n", array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)));
    }
}
