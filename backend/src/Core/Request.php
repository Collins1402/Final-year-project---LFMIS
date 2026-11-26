<?php
/**
 * File purpose: implements Request for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

final class Request
{
    private ?array $body = null;

    /**
 * Performs the method operation for this service.
 * @return string Result of this operation.
 */
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
 * Performs the path operation for this service.
 * @return string Result of this operation.
 */
    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim(is_string($path) ? $path : '/', '/') ?: '/';
    }

    /**
 * Performs the body operation for this service.
 * @return array Result of this operation.
 */
    public function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 1_000_000) {
            Response::error('Request body is too large.', 413);
        }
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($decoded)) {
            Response::error('Request body must be valid JSON.', 400);
        }
        $this->body = $decoded;
        return $decoded;
    }
}
