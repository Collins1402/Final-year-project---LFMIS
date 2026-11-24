<?php
declare(strict_types=1);

namespace Lfmis\Core;

final class RequestSecurity
{
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

    public static function sendHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    }

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

    private static function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $parts = array_map('intval', explode('.', $host));
                if ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127) return true;
            }
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return false;
    }

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
