<?php
declare(strict_types=1);

function mailConfig(): array
{
    $local = __DIR__ . '/mail.local.php';
    $file = is_file($local) ? require $local : [];
    $file = is_array($file) ? $file : [];

    return [
        'host' => $file['host'] ?? (getenv('LFMIS_SMTP_HOST') ?: ''),
        'port' => (int) ($file['port'] ?? (getenv('LFMIS_SMTP_PORT') ?: 465)),
        'username' => $file['username'] ?? (getenv('LFMIS_SMTP_USER') ?: ''),
        'password' => $file['password'] ?? (getenv('LFMIS_SMTP_PASSWORD') ?: ''),
        'from' => $file['from'] ?? (getenv('LFMIS_MAIL_FROM') ?: ''),
        'fromName' => $file['fromName'] ?? 'Flouri Farm LFMIS',
        'cafile' => $file['cafile'] ?? (getenv('LFMIS_SMTP_CAFILE') ?: ''),
    ];
}

function sendMail(string $to, string $subject, string $body): bool
{
    $config = mailConfig();
    $from = filter_var($config['from'] ?: $config['username'], FILTER_VALIDATE_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $from === false || preg_match('/[\r\n]/', $subject)) {
        error_log('Mail not sent: invalid recipient, sender, or subject.');
        return false;
    }
    if ($config['host'] === '') {
        error_log('Mail not sent: SMTP is not configured. Create backend/config/mail.local.php.');
        return false;
    }

    try {
        smtpSend($config, $from, $to, $subject, $body);
        return true;
    } catch (RuntimeException $error) {
        error_log('SMTP error: ' . $error->getMessage());
        return false;
    }
}

function smtpSend(array $config, string $from, string $to, string $subject, string $body): void
{
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($config['cafile'] !== '') {
        $ssl['cafile'] = $config['cafile'];
    }
    $context = stream_context_create(['ssl' => $ssl]);
    $implicitTls = $config['port'] === 465;
    $address = ($implicitTls ? 'ssl://' : 'tcp://') . $config['host'] . ':' . $config['port'];
    $socket = @stream_socket_client($address, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException("Could not connect to {$address}: {$errorMessage}");
    }
    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };
    $command = static function (?string $line, array $expected, bool $secret = false) use ($socket, $read): string {
        if ($line !== null) {
            fwrite($socket, $line . "\r\n");
        }
        $response = $read();
        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            $shown = $secret ? '[credentials]' : (string) $line;
            throw new RuntimeException("Unexpected reply to {$shown}: " . trim($response));
        }
        return $response;
    };

    try {
        $command(null, [220]);
        $command('EHLO localhost', [250]);
        if (!$implicitTls) {
            $command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $command('EHLO localhost', [250]);
        }
        if ($config['username'] !== '') {
            $command('AUTH LOGIN', [334]);
            $command(base64_encode($config['username']), [334], true);
            $command(base64_encode($config['password']), [235], true);
        }
        $command("MAIL FROM:<{$from}>", [250]);
        $command("RCPT TO:<{$to}>", [250, 251]);
        $command('DATA', [354]);

        $fromName = preg_replace('/[\r\n"]/', '', (string) $config['fromName']);
        $headers = [
            'Date: ' . date('r'),
            "From: \"{$fromName}\" <{$from}>",
            "To: <{$to}>",
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = array_map(static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, explode("\n", $normalized));
        $command(implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n.", [250]);
        $command('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}
