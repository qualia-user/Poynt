<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Core\Context;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\CallbackService;
use App\Services\ServiceFactory;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CallbackServiceTest extends TestCase
{
    private function createCallbackService(Context $context): CallbackService
    {
        $platformRegistry = $this->createMock(PlatformRegistry::class);
        $serviceFactory = $this->createMock(ServiceFactory::class);

        return new CallbackService($context, $platformRegistry, $serviceFactory);
    }

    public function testNormalizeResourceItemsSeparatesLinksFromItems(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $callbackService = $this->createCallbackService($context);

        $raw = [
            'links' => [
                [
                    'href' => '/businesses/123/products?page=2',
                    'rel' => 'next',
                    'method' => 'GET',
                ],
            ],
            'products' => [
                ['id' => 'product-1', 'businessId' => '123'],
                ['id' => 'product-2', 'businessId' => '123'],
            ],
        ];

        $reflection = new ReflectionClass($callbackService);
        $method = $reflection->getMethod('normalizeResourceItems');
        $method->setAccessible(true);

        $result = $method->invoke($callbackService, $raw);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('links', $result);
        $this->assertCount(2, $result['items']);
        $this->assertCount(1, $result['links']);
        $this->assertSame('product-1', $result['items'][0]['id']);
        $this->assertSame('/businesses/123/products?page=2', $result['links'][0]['href']);
    }

    public function testSyncResourceCollectionDoesNotUpsertLinkEntries(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('captured'),
                $this->arrayHasKey('links')
            );

        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $service = new class {
            public array $upserted = [];

            public function fetchByBusinessId(?string $businessId = null): array
            {
                return [
                    'links' => [
                        [
                            'href' => '/businesses/' . ($businessId ?? 'n/a') . '/products?page=2',
                            'rel' => 'next',
                            'method' => 'GET',
                        ],
                    ],
                    'products' => [
                        ['id' => 'product-1', 'businessId' => $businessId],
                    ],
                ];
            }

            public function upsert(array $payload): void
            {
                $this->upserted[] = $payload;
            }
        };

        $callbackService = $this->createCallbackService($context);

        $method = new ReflectionMethod($callbackService, 'syncResourceCollection');
        $method->setAccessible(true);
        $method->invoke($callbackService, 'business-123', $service);

        $this->assertSame([
            ['id' => 'product-1', 'businessId' => 'business-123'],
        ], $service->upserted);
    }
}
