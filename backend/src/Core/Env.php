<?php
/**
 * File purpose: implements Env for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

final class Env
{
    private static bool $loaded = false;

    /** Loads KEY=VALUE lines from .env.local; real environment variables take precedence. */
    /**
 * Performs the load operation for this service.
 * @param string $file Input used by this operation.
 * @return void Result of this operation.
 */
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

    /**
 * Performs the get operation for this service.
 * @param string $key Input used by this operation.
 * @param ?string $default Input used by this operation.
 * @return ?string Result of this operation.
 */

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    /**
 * Performs the required operation for this service.
 * @param string $key Input used by this operation.
 * @return string Result of this operation.
 */

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required environment variable {$key}.");
        }
        return $value;
    }
}
