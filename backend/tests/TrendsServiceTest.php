<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\TrendsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TrendsServiceTest extends TestCase
{
    public function testFetchesAndCachesRegionalAndGlobalArticles(): void
    {
        $db = $this->database();
        $requests = 0;
        $http = static function (string $url) use (&$requests): string {
            $requests++;
            return json_encode(['articles' => [[
                'title' => 'Agriculture outlook',
                'url' => 'https://news.example.test/story',
                'domain' => 'news.example.test',
                'seendate' => '20261110T100000Z',
            ]]]);
        };
        $service = new TrendsService($db, $http);

        $first = $service->current();
        $second = $service->current();

        $this->assertSame('Kenya', $first['region']);
        $this->assertSame('Agriculture outlook', $first['regional']['articles'][0]['title']);
        $this->assertSame(2, $requests);
        $this->assertFalse($second['global']['stale']);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE trend_snapshots (snapshot_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at TEXT NOT NULL)');
        return $db;
    }
}