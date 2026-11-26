<?php
/**
 * File purpose: implements TotpService for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Mailer;
use Lfmis\Core\Totp;
use PDO;

final class TotpService
{
    private const LOGIN_COOKIE = 'lfmis_trusted_device';

    /**
 * Connects this service to its required dependency.
 * @param PDO $db Input used by this operation.
 * @return void Result of this operation.
 */
    public function __construct(private PDO $db) {}

    /**
 * Performs the begin setup operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function beginSetup(int $userId): array
    {
        $user = $this->user($userId);
        if (!empty($user['totp_enabled_at'])) throw new \DomainException('Two-step verification is already enabled.');
        $secret = Totp::generateSecret();
        $_SESSION['totp_setup'] = ['user_id' => $userId, 'secret' => $secret, 'expires' => time() + 300];
        return ['secret' => $secret, 'otpauthUri' => Totp::otpauthUri($secret, (string) $user['email'])];
    }

    /**
 * Performs the enable operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $code Input used by this operation.
 * @return array Result of this operation.
 */
    public function enable(int $userId, string $code): array
    {
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!is_array($setup) || (int) ($setup['user_id'] ?? 0) !== $userId || (int) ($setup['expires'] ?? 0) < time()) {
            unset($_SESSION['totp_setup']);
            throw new \InvalidArgumentException('Start two-step setup again; the setup session expired.');
        }
        $step = Totp::verify((string) $setup['secret'], trim($code), time());
        if ($step === null) throw new \InvalidArgumentException('The authenticator code is invalid.');
        $this->db->prepare('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?')
            ->execute([Totp::encrypt((string) $setup['secret']), $this->now(), $step, $userId]);
        $codes = $this->newRecoveryCodes($userId);
        unset($_SESSION['totp_setup']);
        return ['enabled' => true, 'recoveryCodes' => $codes];
    }

    /**
 * Performs the status operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    public function status(int $userId): array
    {
        $user = $this->user($userId);
        $count = $this->db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $count->execute([$userId]);
        $devices = $this->db->prepare('SELECT label, expires_at, created_at FROM trusted_devices WHERE user_id = ? AND expires_at > ? ORDER BY created_at DESC');
        $devices->execute([$userId, $this->now()]);
        return ['enabled' => !empty($user['totp_enabled_at']), 'recoveryCodesRemaining' => (int) $count->fetchColumn(), 'trustedDevices' => $devices->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
 * Performs the verify sensitive action operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $code Input used by this operation.
 * @return void Result of this operation.
 */
    public function verifySensitiveAction(int $userId, string $code): void
    {
        $this->consumeCurrentTotpStep($this->user($userId), $code);
    }

    /**
 * Performs the disable operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $password Input used by this operation.
 * @param string $code Input used by this operation.
 * @return void Result of this operation.
 */
    public function disable(int $userId, string $password, string $code): void
    {
        $user = $this->user($userId);
        if (!password_verify($password, (string) $user['password_hash'])) throw new \InvalidArgumentException('Your current password is incorrect.');
        $this->consumeCurrentTotpStep($user, $code);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = 0 WHERE id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
            $this->revokeDevices($userId);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        $this->clearTrustedCookie();
    }

    /**
 * Performs the regenerate recovery codes operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $password Input used by this operation.
 * @param string $code Input used by this operation.
 * @return array Result of this operation.
 */
    public function regenerateRecoveryCodes(int $userId, string $password, string $code): array
    {
        $user = $this->user($userId);
        if (!password_verify($password, (string) $user['password_hash'])) throw new \InvalidArgumentException('Your current password is incorrect.');
        $this->consumeCurrentTotpStep($user, $code);
        return $this->newRecoveryCodes($userId);
    }

    /**
 * Performs the verify pending operation for this service.
 * @param string $code Input used by this operation.
 * @param bool $rememberDevice Input used by this operation.
 * @return array Result of this operation.
 */
    public function verifyPending(string $code, bool $rememberDevice): array
    {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'], $pending['expires'], $pending['tries']) || (int) $pending['expires'] < time()) {
            unset($_SESSION['pending_2fa']);
            throw new \InvalidArgumentException('Sign-in verification expired. Enter your password again.');
        }
        if ((int) $pending['tries'] >= 5) throw new \InvalidArgumentException('Too many incorrect codes. Sign in with your password again.');
        $_SESSION['pending_2fa']['tries'] = (int) $pending['tries'] + 1;
        $user = $this->user((int) $pending['user_id']);
        $isRecovery = false;
        $step = $this->verifiedStep($user, $code, false);
        if ($step === null) {
            $isRecovery = $this->consumeRecovery((int) $user['id'], trim($code));
            if (!$isRecovery) throw new \InvalidArgumentException('The code is invalid or has already been used.');
        } else {
            $update = $this->db->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?');
            $update->execute([$step, $user['id'], $step]);
            if ($update->rowCount() !== 1) throw new \InvalidArgumentException('That authenticator code was already used. Wait for a new code.');
        }
        unset($_SESSION['pending_2fa']);
        if ($rememberDevice) $this->rememberDevice((int) $user['id']);
        if ($isRecovery) $this->notifyRecoveryUse($user);
        return ['userId' => (int) $user['id'], 'recoveryCodeUsed' => $isRecovery];
    }

    /**
 * Performs the has trusted device operation for this service.
 * @param int $userId Input used by this operation.
 * @param ?string $token Input used by this operation.
 * @return bool Result of this operation.
 */
    public function hasTrustedDevice(int $userId, ?string $token): bool
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $query = $this->db->prepare('SELECT 1 FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > ?');
        $query->execute([$userId, hash('sha256', $token), $this->now()]);
        return (bool) $query->fetchColumn();
    }

    /**
 * Performs the begin login operation for this service.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function beginLogin(int $userId): void
    {
        unset($_SESSION['user_id']);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['user_id' => $userId, 'expires' => time() + 300, 'tries' => 0];
    }

    /**
 * Performs the revoke devices operation for this service.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function revokeDevices(int $userId): void
    {
        $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
    }

    /**
 * Performs the revoke all devices operation for this service.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    public function revokeAllDevices(int $userId): void
    {
        $this->revokeDevices($userId);
        $this->clearTrustedCookie();
    }

    /**
 * Performs the verified step operation for this service.
 * @param array $user Input used by this operation.
 * @param string $code Input used by this operation.
 * @param bool $throw Input used by this operation.
 * @return ?int Result of this operation.
 */
    private function verifiedStep(array $user, string $code, bool $throw = true): ?int
    {
        if (empty($user['totp_secret_enc']) || empty($user['totp_enabled_at'])) {
            if ($throw) throw new \InvalidArgumentException('Two-step verification is not enabled.');
            return null;
        }
        $secret = Totp::decrypt((string) $user['totp_secret_enc']);
        $step = Totp::verify($secret, trim($code), time(), (int) $user['totp_last_step']);
        if ($step === null && $throw) throw new \InvalidArgumentException('The authenticator code is invalid or has already been used.');
        return $step;
    }

    /**
 * Performs the consume current totp step operation for this service.
 * @param array $user Input used by this operation.
 * @param string $code Input used by this operation.
 * @return void Result of this operation.
 */
    private function consumeCurrentTotpStep(array $user, string $code): void
    {
        $step = $this->verifiedStep($user, $code);
        $update = $this->db->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?');
        $update->execute([$step, $user['id'], $step]);
        if ($update->rowCount() !== 1) throw new \InvalidArgumentException('That authenticator code was already used. Wait for a new code.');
    }

    /**
 * Performs the new recovery codes operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    private function newRecoveryCodes(int $userId): array
    {
        $codes = [];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
            $insert = $this->db->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
            for ($i = 0; $i < 10; $i++) { $code = strtoupper(bin2hex(random_bytes(5))); $codes[] = $code; $insert->execute([$userId, hash('sha256', $code)]); }
            $this->revokeDevices($userId);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        return $codes;
    }

    /**
 * Performs the consume recovery operation for this service.
 * @param int $userId Input used by this operation.
 * @param string $code Input used by this operation.
 * @return bool Result of this operation.
 */
    private function consumeRecovery(int $userId, string $code): bool
    {
        if (!preg_match('/^[A-Fa-f0-9]{10}$/', $code)) return false;
        $stmt = $this->db->prepare('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $stmt->execute([$this->now(), $userId, hash('sha256', strtoupper($code))]);
        return $stmt->rowCount() === 1;
    }

    /**
 * Performs the remember device operation for this service.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */
    private function rememberDevice(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $this->db->prepare('INSERT INTO trusted_devices (user_id, token_hash, label, expires_at) VALUES (?, ?, ?, ?)')->execute([$userId, hash('sha256', $token), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Browser'), 0, 120), $expiresAt]);
        setcookie(self::LOGIN_COOKIE, $token, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /**
 * Performs the clear trusted cookie operation for this service.
 * @return void Result of this operation.
 */
    private function clearTrustedCookie(): void
    {
        setcookie(self::LOGIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /**
 * Performs the user operation for this service.
 * @param int $userId Input used by this operation.
 * @return array Result of this operation.
 */
    private function user(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, email, password_hash, totp_secret_enc, totp_enabled_at, totp_last_step FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new \RuntimeException('Could not load the account security settings.');
        return $user;
    }

    /**
 * Performs the now operation for this service.
 * @return string Result of this operation.
 */
    private function now(): string { return gmdate('Y-m-d H:i:s'); }

    /**
 * Performs the notify recovery use operation for this service.
 * @param array $user Input used by this operation.
 * @return void Result of this operation.
 */
    private function notifyRecoveryUse(array $user): void
    {
        $stmt = $this->db->prepare('SELECT email, full_name FROM users WHERE id = ?'); $stmt->execute([$user['id']]); $details = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($details) Mailer::send((string) $details['email'], 'LFMIS recovery code used', 'A recovery code was used to sign in to your LFMIS account. If this was not you, change your password and disable remembered devices.');
    }
}
