<?php
/**
 * File purpose: implements RequestSecurity for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

final class RequestSecurity
{
    /**
 * Performs the client ip operation for this service.
 * @return string Result of this operation.
 */
    public static function clientIp(): string
    {
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            foreach (array_reverse($forwarded) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
            }
        }
        return filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : 'Unavailable';
    }

    /**
 * Performs the send headers operation for this service.
 * @return void Result of this operation.
 */
    public static function sendHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    }

    /**
 * Performs the is trusted origin operation for this service.
 * @param ?string $origin Input used by this operation.
 * @param ?string $referer Input used by this operation.
 * @param string $appUrl Input used by this operation.
 * @return bool Result of this operation.
 */
    public static function isTrustedOrigin(?string $origin, ?string $referer, string $appUrl): bool
    {
        $candidate = $origin;
        if ($candidate === null || trim($candidate) === '') {
            $candidate = $referer === null ? null : self::originOf($referer);
        }
        if ($candidate === null) {
            return false;
        }
        $candidateOrigin = self::originOf($candidate);
        $appOrigin = self::originOf($appUrl);
        if ($candidateOrigin === null || $appOrigin === null) {
            return false;
        }
        if (hash_equals($appOrigin, $candidateOrigin)) {
            return true;
        }
        $candidateParts = parse_url($candidateOrigin);
        $appParts = parse_url($appOrigin);
        if (!is_array($candidateParts) || !is_array($appParts) || !isset($candidateParts['host'], $appParts['host'])) {
            return false;
        }
        $candidateHost = strtolower((string) $candidateParts['host']);
        $appHost = strtolower((string) $appParts['host']);
        $candidateIsLocalHost = in_array($candidateHost, ['localhost', '127.0.0.1', '::1'], true);
        $appIsLocalHost = in_array($appHost, ['localhost', '127.0.0.1', '::1'], true);
        if ($candidateIsLocalHost || $appIsLocalHost) {
            return ($candidateIsLocalHost || self::isPrivateHost($candidateHost))
                && ($appIsLocalHost || self::isPrivateHost($appHost));
        }
        return self::isPrivateHost($candidateHost) && self::isPrivateHost($appHost);
    }

    /**
 * Performs the is private host operation for this service.
 * @param string $host Input used by this operation.
 * @return bool Result of this operation.
 */
    private static function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return false;
    }

    /**
 * Performs the origin of operation for this service.
 * @param string $url Input used by this operation.
 * @return ?string Result of this operation.
 */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
