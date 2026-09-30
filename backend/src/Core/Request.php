<?php
declare(strict_types=1);

namespace Lfmis\Core;

final class Request
{
    private ?array $body = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return rtrim(is_string($path) ? $path : '/', '/') ?: '/';
    }

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
