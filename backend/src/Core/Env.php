<?php
declare(strict_types=1);

namespace Lfmis\Core;

final class Env
{
    private static bool $loaded = false;

    /** Loads KEY=VALUE lines from .env.local; real environment variables take precedence. */
    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (!is_readable($file)) {
            $timezone = self::get('LFMIS_TIMEZONE', 'Africa/Nairobi');
            if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) date_default_timezone_set($timezone);
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) {
                continue;
            }
            $value = preg_replace('/^(["\'])(.*)\1$/', '$2', $value);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
        $timezone = self::get('LFMIS_TIMEZONE', 'Africa/Nairobi');
        if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required environment variable {$key}.");
        }
        return $value;
    }
}
