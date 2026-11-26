<?php
/**
 * File purpose: proves the expected assistantservice behavior through automated tests.
 * PHPUnit loads this file to check the corresponding service or API behavior.
 * It depends on the test database setup and fixtures declared in the test suite.
 */
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\AssistantService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AssistantServiceTest extends TestCase
{
    /**
 * Runs the test customer cannot read farm wide operational metrics behavior under test.
 * @return void Result of this operation.
 */
    public function testCustomerCannotReadFarmWideOperationalMetrics(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));

        $result = $service->answer('How much milk did the farm produce?', ['role' => 'customer']);

        $this->assertStringContainsString('cannot access private farm operating records', $result['answer']);
    }

    /**
 * Runs the test trend question uses public headlines and labels their limits behavior under test.
 * @return void Result of this operation.
 */
    public function testTrendQuestionUsesPublicHeadlinesAndLabelsTheirLimits(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));

        $result = $service->answer('What are the global farming trends?', ['role' => 'buyer'], [
            'global' => ['articles' => [['title' => 'Dairy sector outlook', 'domain' => 'news.example.test']]],
        ]);

        $this->assertStringContainsString('Dairy sector outlook', $result['answer']);
        $this->assertStringContainsString('not measured nearby-farm results', $result['answer']);
    }

    /**
 * Runs the test meta question answers with capabilities behavior under test.
 * @return void Result of this operation.
 */
    public function testMetaQuestionAnswersWithCapabilities(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));
        $result = $service->answer('what do you do?', ['role' => 'admin']);
        $this->assertStringContainsString('Old Mark Donald', $result['answer']);
        $this->assertStringContainsString('Livestock management', $result['answer']);
    }

    /**
 * Runs the test chart question directs to reports and trends modules behavior under test.
 * @return void Result of this operation.
 */
    public function testChartQuestionDirectsToReportsAndTrendsModules(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));
        $result = $service->answer('Can you do charts?', ['role' => 'admin']);
        $this->assertStringContainsString('Reports Module', $result['answer']);
        $this->assertStringContainsString('Farm Trends & Intelligence', $result['answer']);
    }

    /**
 * Runs the test low yield question provides detailed agricultural factors behavior under test.
 * @return void Result of this operation.
 */
    public function testLowYieldQuestionProvidesDetailedAgriculturalFactors(): void
    {
        $service = new AssistantService(new PDO('sqlite::memory:'));
        $result = $service->answer('what is the cause of low yields in the farm today', ['role' => 'admin']);
        $this->assertStringContainsString('Water Intake', $result['answer']);
        $this->assertStringContainsString('Feed Quality', $result['answer']);
        $this->assertStringContainsString('Mastitis', $result['answer']);
    }
}