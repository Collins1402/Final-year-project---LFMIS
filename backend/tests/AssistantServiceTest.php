<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AssistantService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AssistantServiceTest extends TestCase
{
    public function testCustomerCannotReadFarmWideOperationalMetrics(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));

        $result = $service->answer('How much milk did the farm produce?', ['role' => 'customer']);

        $this->assertStringContainsString('cannot access private farm operating records', $result['answer']);
    }

    public function testTrendQuestionUsesPublicHeadlinesAndLabelsTheirLimits(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));

        $result = $service->answer('What are the global farming trends?', ['role' => 'buyer'], [
            'global' => ['articles' => [['title' => 'Dairy sector outlook', 'domain' => 'news.example.test']]],
        ]);

        $this->assertStringContainsString('Dairy sector outlook', $result['answer']);
        $this->assertStringContainsString('not measured nearby-farm results', $result['answer']);
    }
}