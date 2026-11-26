<?php
/**
 * File purpose: implements TwoFactorService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Env;
use Lfmis\Core\Mailer;
use PDO;

final class TwoFactorService
{
    private const CODE_LIFETIME_SECONDS = 600;
    private const MAX_CODE_ATTEMPTS = 5;
    private const MAX_CODES_PER_HOUR = 3;

    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @param ?Closure $deliverEmail Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(
        private PDO $db,
        private ?Closure $deliverEmail = null
    ) {
    }

    /**
 * Performs the status operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function status(int $userId): array
    {
        $userQuery = $this->db->prepare('SELECT 1 FROM users WHERE id = ?');
        $userQuery->execute([$userId]);
        if (!$userQuery->fetchColumn()) {
            throw new \RuntimeException('Could not load the account security settings.');
        }

        $methodsQuery = $this->db->prepare('SELECT method FROM user_two_factor_methods WHERE user_id = ? ORDER BY method');
        $methodsQuery->execute([$userId]);
        return [
            'methods' => $methodsQuery->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /**
 * Performs the begin login operation for this service.
 * @param int $userId Input used by this operation.
 * @param array $methods Input used by this operation.
 * @return void Result of this operation.
 */
    public function beginLogin(int $userId, array $methods): void
    {
        $allowedMethods = array_values(array_intersect($methods, ['email']));
        if ($allowedMethods === []) {
            throw new \RuntimeException('No two-factor method is enabled for this account.');
        }
        unset($_SESSION['user_id']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['pending_2fa'] = [
            'user_id' => $userId,
            'methods' => $allowedMethods,
            'expires' => time() + 300,
        ];
    }

    /**
 * Performs the cancel login operation for this service.
 * @return void Result of this operation.
 */
    public function cancelLogin(): void
    {
        unset($_SESSION['pending_2fa']);
    }

    /**
 * Performs the send login code operation for this service.
 * @param string $method Input used by this operation.
 * @return void Result of this operation.
 */
    public function sendLoginCode(string $method): void
    {
        $pending = $this->pendingLogin();
        if (!in_array($method, $pending['methods'], true)) {
            throw new \InvalidArgumentException('Choose an enabled verification method.');
        }
        $user = $this->findEmailDeliveryDetails((int) $pending['user_id']);
        $this->issueCode((int) $pending['user_id'], 'login', $method, $user['destination'], $user['name']);
    }

    /**
 * Performs the verify login code operation for this service.
 * @param string $method Input used by this operation.
 * @param string $code Input used by this operation.
 * @return int Result of this operation.
 */
    public function verifyLoginCode(string $method, string $code): int
    {
        $pending = $this->pendingLogin();
        if (!in_array($method, $pending['methods'], true)) {
            throw new \InvalidArgumentException('Choose an enabled verification method.');
        }
        $this->verifyCode((int) $pending['user_id'], 'login', $method, $code);
        unset($_SESSION['pending_2fa']);
        return (int) $pending['user_id'];
    }

    /**
 * Performs the start enrollment operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $method Input used by this operation.
 * @return void Result of this operation.
 */
    public function startEnrollment(int $userId, string $method): void
    {
        $this->validateMethod($method);
        $existing = $this->db->prepare('SELECT 1 FROM user_two_factor_methods WHERE user_id = ? AND method = ?');
        $existing->execute([$userId, $method]);
        if ($existing->fetchColumn()) {
            throw new \DomainException('That verification method is already enabled.');
        }

        $user = $this->findEmailDeliveryDetails($userId);
        $this->issueCode($userId, 'enroll', $method, $user['destination'], $user['name']);
    }

    /**
 * Performs the complete enrollment operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $method Input used by this operation.
 * @param string $code Input used by this operation.
 * @return void Result of this operation.
 */
    public function completeEnrollment(int $userId, string $method, string $code): void
    {
        $this->validateMethod($method);
        $this->verifyCode($userId, 'enroll', $method, $code);
        $this->db->prepare('INSERT INTO user_two_factor_methods (user_id, method, enabled_at) VALUES (?, ?, ?)')
            ->execute([$userId, $method, $this->now()]);
    }

    /**
 * Performs the remove method operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $method Input used by this operation.
 * @param string $password Input used by this operation.
 * @return void Result of this operation.
 */
    public function removeMethod(int $userId, string $method, string $password): void
    {
        $this->validateMethod($method);
        $statement = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $passwordHash = $statement->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($password, $passwordHash)) {
            throw new \InvalidArgumentException('Your current password is incorrect.');
        }

        $delete = $this->db->prepare('DELETE FROM user_two_factor_methods WHERE user_id = ? AND method = ?');
        $delete->execute([$userId, $method]);
        if ($delete->rowCount() === 0) {
            throw new \DomainException('That verification method is not enabled.');
        }
    }

    /**
 * Performs the issue code operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $purpose Input used by this operation.
 * @param string $method Input used by this operation.
 * @param string $destination Input used by this operation.
 * @param string $name Input used by this operation.
 * @return void Result of this operation.
 */
    private function issueCode(int $userId, string $purpose, string $method, string $destination, string $name): void
    {
        $now = $this->now();
        $this->db->prepare('DELETE FROM two_factor_challenges WHERE created_at < ?')->execute([$this->timeAgo(86400)]);
        $recent = $this->db->prepare('SELECT COUNT(*) FROM two_factor_challenges WHERE user_id = ? AND method = ? AND created_at > ?');
        $recent->execute([$userId, $method, $this->timeAgo(3600)]);
        if ((int) $recent->fetchColumn() >= self::MAX_CODES_PER_HOUR) {
            throw new RateLimitException('Too many verification codes were requested. Try again in an hour.');
        }

        $this->db->prepare('UPDATE two_factor_challenges SET consumed_at = ? WHERE user_id = ? AND purpose = ? AND method = ? AND consumed_at IS NULL')
            ->execute([$now, $userId, $purpose, $method]);
        $code = (string) random_int(100000, 999999);
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . self::CODE_LIFETIME_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $insert = $this->db->prepare(
            'INSERT INTO two_factor_challenges (user_id, purpose, method, destination, code_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$userId, $purpose, $method, $destination, $this->hashCode($code), $expires, $now]);

        $subject = $purpose === 'login' ? 'Your Flouri Farm sign-in code' : 'Enable Flouri Farm two-factor authentication';
        $body = "Hello {$name},\n\nYour verification code is {$code}. It expires in 10 minutes. Never share this code with anyone.\n";
        try {
            if ($method === 'email') {
                if ($this->deliverEmail !== null) {
                    if (!(bool) ($this->deliverEmail)($destination, $subject, $body)) {
                        throw new DeliveryException('Email delivery failed. Check the SMTP settings and provider logs.');
                    }
                } else {
                    Mailer::send($destination, $subject, $body);
                }
            }
        } catch (DeliveryException $error) {
            $this->db->prepare('UPDATE two_factor_challenges SET consumed_at = ? WHERE id = ?')->execute([$this->now(), $this->db->lastInsertId()]);
            throw $error;
        }
    }

    /**
 * Performs the verify code operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $purpose Input used by this operation.
 * @param string $method Input used by this operation.
 * @param string $code Input used by this operation.
 * @return array Result of this operation.
 */
    private function verifyCode(int $userId, string $purpose, string $method, string $code): array
    {
        $now = $this->now();
        $statement = $this->db->prepare(
            'SELECT id, destination, code_hash, attempts FROM two_factor_challenges
             WHERE user_id = ? AND purpose = ? AND method = ? AND consumed_at IS NULL AND expires_at > ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$userId, $purpose, $method, $now]);
        $challenge = $statement->fetch();
        if (!$challenge || (int) $challenge['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            throw new \InvalidArgumentException('The verification code is invalid or has expired.');
        }

        $attempt = $this->db->prepare('UPDATE two_factor_challenges SET attempts = attempts + 1 WHERE id = ? AND attempts < ? AND consumed_at IS NULL');
        $attempt->execute([$challenge['id'], self::MAX_CODE_ATTEMPTS]);
        if ($attempt->rowCount() !== 1 || !preg_match('/^\d{6}$/', trim($code)) || !hash_equals($challenge['code_hash'], $this->hashCode(trim($code)))) {
            throw new \InvalidArgumentException('The verification code is invalid or has expired.');
        }

        $consume = $this->db->prepare('UPDATE two_factor_challenges SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $consume->execute([$now, $challenge['id']]);
        if ($consume->rowCount() !== 1) {
            throw new \InvalidArgumentException('The verification code is invalid or has expired.');
        }
        return $challenge;
    }

    /**
 * Performs the find email delivery details operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    private function findEmailDeliveryDetails(int $userId): array
    {
        $statement = $this->db->prepare('SELECT email, full_name FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user) {
            throw new \RuntimeException('Could not load the account.');
        }
        $email = filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            throw new \RuntimeException('The account does not have a valid email address.');
        }
        return ['destination' => $email, 'name' => (string) $user['full_name']];
    }

    /**
 * Performs the pending login operation for this service.
 * @return array Result of this operation.
 */
    private function pendingLogin(): array
    {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'], $pending['methods'], $pending['expires']) || (int) $pending['expires'] < time()) {
            unset($_SESSION['pending_2fa']);
            throw new \InvalidArgumentException('Your sign-in verification has expired. Enter your password again.');
        }
        return $pending;
    }

    /**
 * Performs the validate method operation for this service.
 * @param string $method Input used by this operation.
 * @return void Result of this operation.
 */
    private function validateMethod(string $method): void
    {
        if ($method !== 'email') {
            throw new \InvalidArgumentException('Choose email verification.');
        }
    }

    /**
 * Performs the hash code operation for this service.
 * @param string $code Input used by this operation.
 * @return string Result of this operation.
 */
    private function hashCode(string $code): string
    {
        $key = Env::get('LFMIS_2FA_OTP_KEY');
        if ($key === null || !preg_match('/^[a-f0-9]{64}$/i', $key)) {
            throw new DeliveryException('Two-factor authentication is not configured. Set LFMIS_2FA_OTP_KEY to a generated 64-character hexadecimal key in .env.local.');
        }
        return hash_hmac('sha256', $code, $key);
    }

    /**
 * Performs the now operation for this service.
 * @return string Result of this operation.
 */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
 * Performs the time ago operation for this service.
 * @param int $seconds Input used by this operation.
 * @return string Result of this operation.
 */
    private function timeAgo(int $seconds): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');
    }
}
