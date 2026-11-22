<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Env;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Mailer;
use PDO;

final class PasswordResetService
{
    public function __construct(private PDO $db)
    {
    }

    public function request(string $email): ?string
    {
        $statement = $this->db->prepare(
            'SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 AND email_verified_at IS NOT NULL LIMIT 1'
        );
        $statement->execute([trim($email)]);
        $user = $statement->fetch();
        if (!$user) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP'
        )->execute([(int) $user['id'], hash('sha256', $token)]);
        $link = rtrim(Env::get('LFMIS_APP_URL', 'http://127.0.0.1:5173'), '/') . '/?reset=' . rawurlencode($token);
        $body = "Hello {$user['full_name']},\n\nReset your Flouri Farm password here:\n{$link}\n\nThe link expires in one hour.";
        try {
            if (!Mailer::send($user['email'], 'Reset your Flouri Farm password', $body)) {
                throw new DeliveryException('Password reset email could not be delivered.');
            }
        } catch (DeliveryException $error) {
            if (Env::get('LFMIS_DEV_LOG_PASSWORD_RESET_LINK', '0') !== '1') {
                throw $error;
            }
        }
        return Env::get('LFMIS_DEV_LOG_PASSWORD_RESET_LINK', '0') === '1' ? $link : null;
    }

    public function reset(string $token, string $password): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 12) {
            throw new \InvalidArgumentException('Use a valid reset link and a password of at least 12 characters.');
        }
        $statement = $this->db->prepare(
            'SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > ?'
        );
        $statement->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        $userId = $statement->fetchColumn();
        if (!$userId) {
            throw new \InvalidArgumentException('The reset link is invalid or has expired.');
        }
        $this->db->beginTransaction();
        $this->db->prepare(
            'UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), (int) $userId]);
        $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([(int) $userId]);
        $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([(int) $userId]);
        $this->db->commit();
    }
}
