<?php
/**
 * File purpose: implements Session for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

final class Session
{
    /**
 * Performs the start operation for this service.
 * @return void Result of this operation.
 */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }

    /**
 * Performs the user id operation for this service.
 * @return ?int Result of this operation.
 */

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
 * Validates credentials and starts or prepares a secure sign-in.
 * @param int $userId Input used by this operation.
 * @return void Result of this operation.
 */

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    /**
 * Performs the logout operation for this service.
 * @return void Result of this operation.
 */

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
