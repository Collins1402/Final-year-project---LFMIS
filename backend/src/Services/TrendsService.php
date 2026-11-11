<?php
declare(strict_types=1);

namespace Lfmis\Services;

use Closure;
use Lfmis\Core\Env;
use PDO;

final class TrendsService
{
    private ?Closure $httpRequest;

    public function __construct(private PDO $db, ?Closure $httpRequest = null)
    {
        $this->httpRequest = $httpRequest;
    }

    public function current(): array
    {
        $countryCode = strtoupper(Env::get('LFMIS_TRENDS_COUNTRY_CODE', 'KE'));
        $regionName = Env::get('LFMIS_TRENDS_REGION', 'Kenya');
        return [
            'regional' => $this->snapshot('regional', "agriculture OR farming OR livestock sourcecountry:{$countryCode}"),
            'global' => $this->snapshot('global', 'agriculture OR farming OR livestock OR dairy'),
            'region' => $regionName,
            'refreshHours' => 6,
            'source' => 'GDELT Project news data',
        ];
    }

    private function snapshot(string $key, string $query): array
    {
        $statement = $this->db->prepare('SELECT payload, fetched_at FROM trend_snapshots WHERE snapshot_key = ?');
        $statement->execute([$key]);
        $cached = $statement->fetch(PDO::FETCH_ASSOC);
        if ($cached && strtotime((string) $cached['fetched_at'] . ' UTC') > time() - 21600) {
            return ['articles' => json_decode((string) $cached['payload'], true) ?: [], 'updatedAt' => $cached['fetched_at'], 'stale' => false];
        }

        $articles = $this->fetchArticles($query);
        if ($articles !== null) {
            $payload = json_encode($articles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $fetchedAt = gmdate('Y-m-d H:i:s');
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $save = $this->db->prepare('INSERT INTO trend_snapshots (snapshot_key, payload, fetched_at) VALUES (?, ?, ?) ON CONFLICT(snapshot_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at');
            } else {
                $save = $this->db->prepare('INSERT INTO trend_snapshots (snapshot_key, payload, fetched_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)');
            }
            $save->execute([$key, $payload, $fetchedAt]);
            return ['articles' => $articles, 'updatedAt' => $fetchedAt, 'stale' => false];
        }

        return [
            'articles' => $cached ? (json_decode((string) $cached['payload'], true) ?: []) : [],
            'updatedAt' => $cached['fetched_at'] ?? null,
            'stale' => $cached !== false,
            'unavailable' => $cached === false,
        ];
    }

    private function fetchArticles(string $query): ?array
    {
        $url = 'https://api.gdeltproject.org/api/v2/doc/doc?' . http_build_query([
            'query' => $query,
            'mode' => 'ArtList',
            'format' => 'json',
            'maxrecords' => 8,
            'sort' => 'HybridRel',
        ]);
        if ($this->httpRequest !== null) {
            $response = ($this->httpRequest)($url);
        } else {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
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
        $articles = [];
        foreach ($data['articles'] as $article) {
            if (!is_array($article) || empty($article['title']) || empty($article['url'])) {
                continue;
            }
            $articles[] = [
                'title' => (string) $article['title'],
                'url' => (string) $article['url'],
                'domain' => (string) ($article['domain'] ?? ''),
                'publishedAt' => (string) ($article['seendate'] ?? ''),
            ];
        }
        return $articles;
    }
}