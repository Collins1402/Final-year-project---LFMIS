<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Env;
use Lfmis\Core\Mailer;
use Lfmis\Core\RequestSecurity;

final class LoginAlertService
{
    private ?Closure $sendEmail;

    public function __construct(?Closure $sendEmail = null)
    {
        $this->sendEmail = $sendEmail;
    }

    public function notify(array $user, ?array $requestDetails = null): bool
    {
        if (strtolower((string) Env::get('LFMIS_LOGIN_ALERTS_ENABLED', 'true')) === 'false') {
            return false;
        }
        $email = filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            return false;
        }
        $requestDetails = $requestDetails ?? [
            'ip' => RequestSecurity::clientIp(),
            'agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unavailable',
        ];
        $agent = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($requestDetails['agent'] ?? 'Unavailable'));
        $rawIp = (string) ($requestDetails['ip'] ?? '');
        $ip = filter_var($rawIp, FILTER_VALIDATE_IP) ? $rawIp : 'Unavailable';
        $name = trim((string) ($user['fullName'] ?? '')) ?: 'there';
        $when = date('D, j M Y H:i:s T');
        $subject = 'New sign-in to your Flouri Farm account';
        $body = "Hello {$name},\n\nYour Flouri Farm account was just used to sign in.\n\nTime: {$when}\nIP address: {$ip}\nBrowser/device: " . substr($agent, 0, 300) . "\n\nIf this was you, no action is needed. If you do not recognize this activity, change your password and contact the farm administrator.\n";
        try {
            $sent = $this->sendEmail !== null
                ? (bool) ($this->sendEmail)($email, $subject, $body)
                : Mailer::send($email, $subject, $body);
            if (!$sent) {
                error_log('Unable to send sign-in alert email.');
            }
            return $sent;
        } catch (DeliveryException $error) {
            error_log('Unable to send sign-in alert email.');
            return false;
        }
    }
}
