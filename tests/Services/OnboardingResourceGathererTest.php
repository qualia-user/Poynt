<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Core\Context;
use App\Services\OnboardingResourceGatherer;
use App\Services\ServiceFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SuccessfulStubService
{
    public array $upserts = [];

    public function fetchByBusinessId(string $businessId): array
    {
        return [
            ['id' => 'record-1', 'businessId' => $businessId],
        ];
    }

    public function upsert(array $payload, string $businessId): void
    {
        $this->upserts[] = [$payload, $businessId];
    }
}

class FailingFetchStubService
{
    public function fetchByBusinessId(string $businessId): array
    {
        throw new RuntimeException('Fetch failed for ' . $businessId);
    }

    public function upsert(array $payload, string $businessId): void
    {
        // no-op
    }
}

class OnboardingResourceGathererTest extends TestCase
{
    private function createContext(): Context
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info');
        $logger->method('debug');
        $logger->method('warning');
        $logger->method('error');

        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        return $context;
    }

    public function testGatherWithSummaryReportsSuccessAndFailure(): void
    {
        $context = $this->createContext();

        $serviceFactory = $this->createMock(ServiceFactory::class);
        $successful = new SuccessfulStubService();
        $failing = new FailingFetchStubService();

        $serviceFactory->method('onboardingResources')->willReturn([
            'business' => $successful,
            'store' => $failing,
        ]);

        $gatherer = new OnboardingResourceGatherer($context, $serviceFactory);
        $summary = $gatherer->gatherWithSummary('biz-123', ['business', 'store']);

        $this->assertFalse($summary['success']);
        $this->assertSame(['business', 'store'], $summary['matchedResources']);
        $this->assertArrayHasKey('business', $summary['resources']);
        $this->assertArrayHasKey('store', $summary['resources']);
        $this->assertTrue($summary['resources']['business']['success']);
        $this->assertFalse($summary['resources']['store']['success']);
        $this->assertArrayHasKey('error', $summary['resources']['store']);
        $this->assertTrue($gatherer->gather('biz-123', ['business']));
    }

    public function testGatherWithSummaryFailsWhenNoResourcesMatch(): void
    {
        $context = $this->createContext();

        $serviceFactory = $this->createMock(ServiceFactory::class);
        $serviceFactory->method('onboardingResources')->willReturn([
            'business' => new SuccessfulStubService(),
        ]);

        $gatherer = new OnboardingResourceGatherer($context, $serviceFactory);
        $summary = $gatherer->gatherWithSummary('biz-999', ['inventory']);

        $this->assertFalse($summary['success']);
        $this->assertSame(['inventory'], $summary['requestedFilters']);
        $this->assertSame([], $summary['matchedResources']);
        $this->assertArrayHasKey('error', $summary);
        $this->assertFalse($gatherer->gather('biz-999', ['inventory']));
    }
}
