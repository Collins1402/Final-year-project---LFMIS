<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Lfmis\Core\Env;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Mailer;
use PDO;

final class VerificationService
{
    public function __construct(private PDO $db)
    {
    }

    public function issue(int $userId, string $email, string $name): ?string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->db->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP'
        )->execute([$userId, $hash]);
        $appUrl = rtrim(Env::get('LFMIS_APP_URL', 'http://127.0.0.1:5173'), '/');
        $link = $appUrl . '/?verify=' . rawurlencode($token);
        $subject = 'Confirm your Flouri Farm LFMIS account';
        $body = "Hello {$name},\n\nConfirm your Flouri Farm account by opening this link:\n{$link}\n\nThe link expires in 24 hours.";
        try {
            if (!Mailer::send($email, $subject, $body)) {
                throw new DeliveryException('Email confirmation could not be delivered.');
            }
        } catch (DeliveryException $error) {
            if (Env::get('LFMIS_DEV_LOG_EMAIL_VERIFICATION_LINK', '0') !== '1') {
                throw $error;
            }
        }
        return Env::get('LFMIS_DEV_LOG_EMAIL_VERIFICATION_LINK', '0') === '1' ? $link : null;
    }

    public function confirm(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new \InvalidArgumentException('The confirmation link is invalid.');
        }
        $statement = $this->db->prepare(
            'SELECT user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > NOW()'
        );
        $statement->execute([hash('sha256', $token)]);
        $userId = $statement->fetchColumn();
        if (!$userId) {
            throw new \InvalidArgumentException('The confirmation link is invalid or has expired.');
        }
        $this->db->beginTransaction();
        $this->db->prepare(
            "UPDATE users JOIN roles ON roles.id = users.role_id
             SET users.email_verified_at = NOW(), users.is_active = IF(roles.name IN ('admin', 'customer', 'buyer'), 1, 0)
             WHERE users.id = ?"
        )->execute([(int) $userId]);
        $this->db->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([(int) $userId]);
        $this->db->commit();
        return ['verified' => true];
    }
}
