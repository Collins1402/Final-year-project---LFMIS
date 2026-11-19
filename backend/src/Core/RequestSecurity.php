<?php
declare(strict_types=1);

namespace Lfmis\Core;

final class RequestSecurity
{
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
        return $candidateOrigin !== null && $appOrigin !== null && hash_equals($appOrigin, $candidateOrigin);
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
