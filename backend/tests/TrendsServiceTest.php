<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\TrendsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TrendsServiceTest extends TestCase
{
    public function testFetchesAndCachesKenyaEastAfricaAndGlobalArticles(): void
    {
        $db = $this->database();
        $requests = 0;
        $http = static function (string $url) use (&$requests): string {
            $requests++;
            return json_encode(['articles' => [[
                'title' => 'Dairy farming outlook in East Africa',
                'url' => 'https://news.example.test/story',
                'domain' => 'news.example.test',
                'seendate' => '20261110T100000Z',
            ]]]);
        };
        $service = new TrendsService($db, $http);

        $first = $service->current();
        $second = $service->current();

        $this->assertSame('Kenya', $first['region']);
        $this->assertArrayHasKey('kenya', $first);
        $this->assertArrayHasKey('east_africa', $first);
        $this->assertArrayHasKey('global', $first);
        $this->assertSame('Dairy farming outlook in East Africa', $first['regional']['articles'][0]['title']);
        $this->assertSame(3, $requests); // Kenya, East Africa, Global
        $this->assertFalse($second['global']['stale']);
        $this->assertSame(3, $requests); // Second call served from cache, no new requests
    }

    public function testDeduplicatesArticlesByCanonicalUrl(): void
    {
        $db = $this->database();
        $http = static function (string $url): string {
            return json_encode(['articles' => [
                [
                    'title' => 'Livestock feed innovations',
                    'url' => 'https://news.example.test/feed-innovations?utm_source=twitter&ref=agri',
                    'domain' => 'news.example.test',
                    'seendate' => '20261112T083000Z',
                ],
                [
                    'title' => 'Livestock feed innovations duplicate',
                    'url' => 'https://news.example.test/feed-innovations',
                    'domain' => 'news.example.test',
                    'seendate' => '20261112T083000Z',
                ],
            ]]);
        };
        $service = new TrendsService($db, $http);
        $result = $service->snapshot('kenya', 'dummy query');

        $this->assertCount(1, $result['articles']);
        $this->assertSame('https://news.example.test/feed-innovations', $result['articles'][0]['url']);
        $this->assertSame('livestock', $result['articles'][0]['category']);
    }

    public function testHandlesInvalidOrMissingArticleDatesGracefully(): void
    {
        $db = $this->database();
        $http = static function (string $url): string {
            return json_encode(['articles' => [
                [
                    'title' => 'Farming with missing date',
                    'url' => 'https://news.example.test/story-1',
                    'seendate' => '',
                ],
                [
                    'title' => 'Poultry management in Kenya',
                    'url' => 'https://news.example.test/story-2',
                    'seendate' => 'INVALID_DATE_FORMAT',
                ],
            ]]);
        };
        $service = new TrendsService($db, $http);
        $result = $service->snapshot('kenya', 'dummy query');

        $this->assertCount(2, $result['articles']);
        $this->assertNull($result['articles'][0]['publishedAt']);
        $this->assertNull($result['articles'][1]['publishedAt']);
        $this->assertSame('poultry', $result['articles'][1]['category']);
    }

    public function testPartialGeographicFeedFailureKeepsWorkingFeedsFresh(): void
    {
        $db = $this->database();
        $http = static function (string $url): ?string {
            if (str_contains($url, 'sourcecountry%3AKE+OR')) {
                // East Africa fails
                return null;
            }
            return json_encode(['articles' => [[
                'title' => 'Livestock vaccination progress',
                'url' => 'https://news.example.test/vaccination',
                'seendate' => '20261115T120000Z',
            ]]]);
        };
        $service = new TrendsService($db, $http);
        $current = $service->current();

        $this->assertFalse($current['kenya']['stale']);
        $this->assertCount(1, $current['kenya']['articles']);
        $this->assertFalse($current['global']['stale']);
        $this->assertCount(1, $current['global']['articles']);
        $this->assertTrue($current['east_africa']['unavailable']);
    }

    public function testExternalFailureReturnsCachedArticlesWithStaleFlag(): void
    {
        $db = $this->database();
        $successHttp = static function (string $url): string {
            return json_encode(['articles' => [[
                'title' => 'Maize silage preparation',
                'url' => 'https://news.example.test/silage',
                'seendate' => '20261110T000000Z',
            ]]]);
        };
        $service = new TrendsService($db, $successHttp);
        $initial = $service->snapshot('kenya', 'query');
        $this->assertFalse($initial['stale']);

        // Next call with failing HTTP and forced refresh
        $failHttp = static function (string $url): ?string {
            return null;
        };
        $failingService = new TrendsService($db, $failHttp);
        $staleResult = $failingService->snapshot('kenya', 'query', true);

        $this->assertTrue($staleResult['stale']);
        $this->assertCount(1, $staleResult['articles']);
        $this->assertSame('Maize silage preparation', $staleResult['articles'][0]['title']);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE trend_snapshots (snapshot_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at TEXT NOT NULL)');
        return $db;
    }
}
