<?php
/**
 * File purpose: implements Response for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

final class Response
{
    /**
 * Performs the json operation for this service.
 * @param array $payload Input used by this operation.
 * @param int $status Input used by this operation.
 * @return void Result of this operation.
 */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
 * Performs the error operation for this service.
 * @param string $message Input used by this operation.
 * @param int $status Input used by this operation.
 * @return void Result of this operation.
 */

    public static function error(string $message, int $status): void
    {
        self::json(['error' => $message], $status);
    }
}
