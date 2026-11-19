<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Lfmis\Core\Env;
use PDO;
use Throwable;

final class TrendsService
{
    private ?Closure $httpRequest;

    public const SCOPE_KENYA = 'kenya';
    public const SCOPE_EAST_AFRICA = 'east_africa';
    public const SCOPE_GLOBAL = 'global';

    public function __construct(private PDO $db, ?Closure $httpRequest = null)
    {
        $this->httpRequest = $httpRequest;
    }

    public function current(bool $force = false): array
    {
        $countryCode = strtoupper((string) Env::get('LFMIS_TRENDS_COUNTRY_CODE', 'KE'));
        $regionName = (string) Env::get('LFMIS_TRENDS_REGION', 'Kenya');

        $kenyaQuery = sprintf('(agriculture OR farming OR livestock OR dairy OR poultry OR milk OR eggs OR fodder OR "animal feed") sourcecountry:%s', $countryCode);
        $eastAfricaQuery = '(agriculture OR farming OR livestock OR dairy OR poultry OR milk OR fodder OR "food security" OR "livestock market") (sourcecountry:KE OR sourcecountry:UG OR sourcecountry:TZ OR sourcecountry:RW OR sourcecountry:BI)';
        $globalQuery = '(agriculture OR farming OR livestock OR dairy OR "grain market" OR "animal feed" OR "food security" OR "veterinary research")';

        $kenya = $this->snapshot(self::SCOPE_KENYA, $kenyaQuery, $force);
        $eastAfrica = $this->snapshot(self::SCOPE_EAST_AFRICA, $eastAfricaQuery, $force);
        $global = $this->snapshot(self::SCOPE_GLOBAL, $globalQuery, $force);

        return [
            'kenya' => $kenya,
            'east_africa' => $eastAfrica,
            'global' => $global,
            'regional' => $kenya, // backward compatibility
            'region' => $regionName,
            'refreshHours' => 6,
            'source' => 'GDELT Project news data',
            'updatedAt' => $kenya['updatedAt'] ?? $global['updatedAt'] ?? gmdate('Y-m-d H:i:s'),
        ];
    }

    public function snapshot(string $scope, string $query, bool $force = false): array
    {
        $cacheKey = $scope;
        // For backwards compatibility: if checking kenya or regional, map to standard keys
        $lookupKeys = [$cacheKey];
        if ($cacheKey === self::SCOPE_KENYA) {
            $lookupKeys[] = 'regional';
        }

        $cached = false;
        try {
            $inClause = implode(',', array_fill(0, count($lookupKeys), '?'));
            $statement = $this->db->prepare("SELECT payload, fetched_at FROM trend_snapshots WHERE snapshot_key IN ({$inClause}) ORDER BY fetched_at DESC LIMIT 1");
            $statement->execute($lookupKeys);
            $cached = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Table might not exist or connection issue
        }

        if (!$force && $cached && strtotime((string) $cached['fetched_at'] . ' UTC') > time() - 21600) {
            $articles = json_decode((string) $cached['payload'], true) ?: [];
            return [
                'articles' => $articles,
                'updatedAt' => $cached['fetched_at'],
                'stale' => false,
                'scope' => $scope,
            ];
        }

        $articles = $this->fetchArticles($query, $scope);
        if ($articles !== null) {
            $payload = json_encode($articles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $fetchedAt = gmdate('Y-m-d H:i:s');
            $this->persistSnapshot($cacheKey, $payload, $fetchedAt, $articles);

            return [
                'articles' => $articles,
                'updatedAt' => $fetchedAt,
                'stale' => false,
                'scope' => $scope,
            ];
        }

        // External request failed: return cached data with stale flag if available
        return [
            'articles' => $cached ? (json_decode((string) $cached['payload'], true) ?: []) : [],
            'updatedAt' => $cached['fetched_at'] ?? null,
            'stale' => $cached !== false,
            'unavailable' => $cached === false,
            'scope' => $scope,
        ];
    }

    public function fetchArticles(string $query, string $scope = self::SCOPE_GLOBAL): ?array
    {
        $url = 'https://api.gdeltproject.org/api/v2/doc/doc?' . http_build_query([
            'query' => $query,
            'mode' => 'ArtList',
            'format' => 'json',
            'maxrecords' => 12,
            'sort' => 'HybridRel',
        ]);

        if ($this->httpRequest !== null) {
            $response = ($this->httpRequest)($url);
        } else {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'FlouriFarmLFMIS/1.0',
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($response === false || $status < 200 || $status >= 300) {
                return null;
            }
        }

        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data) || !isset($data['articles']) || !is_array($data['articles'])) {
            return null;
        }

        $seenUrls = [];
        $articles = [];
        $retrievedAt = gmdate('Y-m-d H:i:s');

        foreach ($data['articles'] as $article) {
            if (!is_array($article) || empty($article['title']) || empty($article['url'])) {
                continue;
            }

            $rawUrl = trim((string) $article['url']);
            $canonicalUrl = $this->canonicalizeUrl($rawUrl);
            if ($canonicalUrl === '' || isset($seenUrls[$canonicalUrl])) {
                continue;
            }
            $seenUrls[$canonicalUrl] = true;

            $title = trim(strip_tags((string) $article['title']));
            if ($title === '' || $this->isPurelyPoliticalOrIrrelevant($title)) {
                continue;
            }

            $domain = trim((string) ($article['domain'] ?? ''));
            if ($domain === '') {
                $host = parse_url($canonicalUrl, PHP_URL_HOST);
                $domain = is_string($host) ? preg_replace('/^www\./i', '', $host) : '';
            }

            $rawSeenDate = (string) ($article['seendate'] ?? '');
            $publishedAt = $this->parseSeenDate($rawSeenDate);
            $country = $this->inferCountryCode($domain, $scope, (string) ($article['sourcecountry'] ?? ''));
            $category = $this->categorizeArticle($title);

            $articles[] = [
                'title' => $title,
                'headline' => $title,
                'url' => $canonicalUrl,
                'domain' => $domain,
                'sourceDomain' => $domain,
                'publishedAt' => $publishedAt,
                'retrievedAt' => $retrievedAt,
                'geographicScope' => $scope,
                'countryCode' => $country,
                'category' => $category,
                'source' => 'GDELT Project news data',
            ];
        }

        return $articles;
    }

    public function canonicalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./i', '', $host);
        $path = $parts['path'] ?? '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Clean tracking query params
        $cleanQuery = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            $filtered = [];
            foreach ($queryParams as $k => $v) {
                $kLower = strtolower((string) $k);
                if (str_starts_with($kLower, 'utm_') || in_array($kLower, ['fbclid', 'gclid', 'ref', 'source'], true)) {
                    continue;
                }
                $filtered[$k] = $v;
            }
            if (!empty($filtered)) {
                ksort($filtered);
                $cleanQuery = '?' . http_build_query($filtered);
            }
        }

        return "{$scheme}://{$host}{$path}{$cleanQuery}";
    }

    public function parseSeenDate(string $seendate): ?string
    {
        $clean = trim($seendate);
        if ($clean === '') {
            return null;
        }

        // GDELT format: YYYYMMDDTHHMMSSZ (16 chars)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $clean, $m)) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        }

        // GDELT short date: YYYYMMDD (8 digits)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $clean, $m)) {
            return sprintf('%s-%s-%s 00:00:00', $m[1], $m[2], $m[3]);
        }

        try {
            $dt = new DateTimeImmutable($clean, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function inferCountryCode(string $domain, string $scope, string $sourceCountry): ?string
    {
        $code = strtoupper(trim($sourceCountry));
        if ($code !== '' && strlen($code) === 2) {
            return $code;
        }

        if (str_ends_with($domain, '.ke')) return 'KE';
        if (str_ends_with($domain, '.ug')) return 'UG';
        if (str_ends_with($domain, '.tz')) return 'TZ';
        if (str_ends_with($domain, '.rw')) return 'RW';
        if (str_ends_with($domain, '.bi')) return 'BI';

        if ($scope === self::SCOPE_KENYA) return 'KE';
        return null;
    }

    private function categorizeArticle(string $title): string
    {
        $lower = strtolower($title);
        if (preg_match('/\b(milk|dairy|cow|cattle|butter|cream|lactat|pasteuriz)\w*\b/i', $lower)) {
            return 'dairy';
        }
        if (preg_match('/\b(egg|chicken|poultry|hen|broiler|layer|chick)\w*\b/i', $lower)) {
            return 'poultry';
        }
        if (preg_match('/\b(goat|sheep|rabbit|ram|shoat|livestock|pastoral|herd)\w*\b/i', $lower)) {
            return 'livestock';
        }
        if (preg_match('/\b(feed|fodder|hay|silage|grass|maize|grain|pasture|concentrate)\w*\b/i', $lower)) {
            return 'feed';
        }
        if (preg_match('/\b(vet|vaccin|disease|treatment|deworm|clinic|virus|fmd|anthrax|ecf)\w*\b/i', $lower)) {
            return 'veterinary';
        }
        if (preg_match('/\b(policy|subsid|tax|trade|export|import|ministry|regulation)\w*\b/i', $lower)) {
            return 'policy';
        }
        if (preg_match('/\b(drought|rain|flood|climate|weather|temperature)\w*\b/i', $lower)) {
            return 'climate';
        }
        if (preg_match('/\b(tech|digital|ai|app|solar|irrigation|sensor)\w*\b/i', $lower)) {
            return 'agritech';
        }
        return 'general';
    }

    private function isPurelyPoliticalOrIrrelevant(string $title): bool
    {
        $lower = strtolower($title);
        // If it mentions agriculture or farming terms, keep it
        if (preg_match('/\b(farm|crop|agricultur|livestock|dairy|milk|egg|poultry|cattle|vet|feed|fodder|harvest|pasture)\w*\b/i', $lower)) {
            return false;
        }
        // If no farming terms and strong political words
        return (bool) preg_match('/\b(election|ballot|impeach|parliament debate|celebrity|football|soccer|hollywood)\b/i', $lower);
    }

    private function persistSnapshot(string $key, string $payload, string $fetchedAt, array $articles): void
    {
        try {
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($isSqlite) {
                $save = $this->db->prepare('INSERT INTO trend_snapshots (snapshot_key, payload, fetched_at) VALUES (?, ?, ?) ON CONFLICT(snapshot_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at');
            } else {
                $save = $this->db->prepare('INSERT INTO trend_snapshots (snapshot_key, payload, fetched_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)');
            }
            $save->execute([$key, $payload, $fetchedAt]);

            // If trend_articles and trend_snapshot_history tables exist, store structured history
            $this->persistHistoricalArticles($key, $articles, $fetchedAt);
        } catch (Throwable) {
            // Safe fallback
        }
    }

    private function persistHistoricalArticles(string $scope, array $articles, string $fetchedAt): void
    {
        try {
            // Check if trend_articles exists
            $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $sql = $isSqlite
                ? 'INSERT OR IGNORE INTO trend_articles (snapshot_id, title, url, source_domain, published_at, retrieved_at, country_code, geographic_scope, category, external_identifier) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT IGNORE INTO trend_articles (snapshot_id, title, url, source_domain, published_at, retrieved_at, country_code, geographic_scope, category, external_identifier) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            $stmt = $this->db->prepare($sql);
            foreach ($articles as $art) {
                $pubDate = !empty($art['publishedAt']) ? $art['publishedAt'] : null;
                $stmt->execute([
                    mb_substr((string) $art['title'], 0, 255),
                    mb_substr((string) $art['url'], 0, 500),
                    mb_substr((string) ($art['sourceDomain'] ?? ''), 0, 120),
                    $pubDate,
                    $fetchedAt,
                    $art['countryCode'] ?? null,
                    $scope,
                    $art['category'] ?? 'general',
                    hash('sha256', (string) $art['url']),
                ]);
            }
        } catch (Throwable) {
            // Table might not exist yet in test environments
        }
    }
}
