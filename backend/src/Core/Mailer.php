<?php
declare(strict_types=1);

namespace Lfmis\Core;

use Closure;
use RuntimeException;
use Throwable;

final class Mailer
{
    private static ?Closure $transport = null;

    /** Test hook. The callback receives the envelope and fully encoded message. */
    public static function useTransport(?Closure $transport): void
    {
        self::$transport = $transport;
    }

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
                $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
                $message = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . self::wrapBase64($text) . "\r\n--" . $boundary
                    . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . self::wrapBase64($html) . "\r\n--" . $boundary . "--\r\n";
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
                    $sent = self::smtp($host, (int) Env::get('LFMIS_SMTP_PORT', '587'), strtolower((string) Env::get('LFMIS_SMTP_SECURE', 'tls')), $from, $to, $wireMessage);
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

    private static function smtp(string $host, int $port, string $secure, string $from, string $to, string $message): bool
    {
        if (!in_array($secure, ['tls', 'ssl'], true)) {
            throw new RuntimeException('LFMIS_SMTP_SECURE must be tls or ssl.');
        }
        $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, 9, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed ({$errno}): {$errstr}");
        }
        stream_set_timeout($socket, 9);
        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO localhost', [250]);
            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                self::command($socket, 'EHLO localhost', [250]);
            }
            $user = Env::get('LFMIS_SMTP_USER', '');
            $password = Env::get('LFMIS_SMTP_PASSWORD', '');
            if ($user !== '' || $password !== '') {
                if ($user === '' || $password === '') throw new RuntimeException('Both SMTP username and password are required.');
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($user), [334]);
                self::command($socket, base64_encode($password), [235]);
            }
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $data = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $message));
            if (fwrite($socket, $data . "\r\n.\r\n") === false) throw new RuntimeException('SMTP message write failed.');
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
            return true;
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $codes): string
    {
        if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP command write failed.');
        return self::expect($socket, $codes);
    }

    private static function expect($socket, array $codes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) throw new RuntimeException('SMTP server closed the connection or timed out.');
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        if (!in_array((int) substr($response, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP server rejected a command: ' . trim($response));
        }
        return $response;
    }

    private static function wrapBase64(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
    }

    private static function formatHeaders(array $headers): string
    {
        return implode("\r\n", array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)));
    }
}
