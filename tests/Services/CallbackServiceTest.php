<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Core\Context;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\CallbackService;
use App\Services\ServiceFactory;
use App\Services\TokenService;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use DateTime;

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
        $result = $method->invoke($callbackService, 'business-123', $service);

        $this->assertSame([
            ['id' => 'product-1', 'businessId' => 'business-123'],
        ], $service->upserted);
        $this->assertTrue($result);
    }

    public function testPurgeAndReinstallValidatesTokenPayload(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('missing accessToken'));

        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);
        $context->method('getConn')->willReturn($this->createMock(Connection::class));

        $platformRegistry = $this->createMock(PlatformRegistry::class);
        $serviceFactory = $this->createMock(ServiceFactory::class);

        $callbackService = new CallbackService($context, $platformRegistry, $serviceFactory);

        $result = $callbackService->purgeAndReinstall(
            'business-123',
            'store-456',
            ['refreshToken' => 'refresh', 'expiresIn' => 3600],
            ['accessToken' => 'merchant', 'refreshToken' => 'merchant-refresh', 'expiresIn' => 3600]
        );

        $this->assertFalse($result);
    }

    public function testPurgeBusinessInstallationRemovesTokensWhenRequested(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $capturedStatements = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->method('executeStatement')->willReturnCallback(
            function (string $statement, array $params) use (&$capturedStatements) {
                $capturedStatements[] = $statement;

                return 1;
            }
        );
        $connection->expects($this->once())->method('commit');

        $context->method('getConn')->willReturn($connection);

        $callbackService = new CallbackService(
            $context,
            $this->createMock(PlatformRegistry::class),
            $this->createMock(ServiceFactory::class)
        );

        $callbackService->purgeBusinessInstallation('business-123', false);

        $this->assertContains('DELETE FROM app_token WHERE business_id = :biz', $capturedStatements);
        $this->assertContains('DELETE FROM merchant_token WHERE business_id = :biz', $capturedStatements);
    }

    public function testPurgeBusinessInstallationPreservesTokensByDefault(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $capturedStatements = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->method('executeStatement')->willReturnCallback(
            function (string $statement, array $params) use (&$capturedStatements) {
                $capturedStatements[] = $statement;

                return 1;
            }
        );
        $connection->expects($this->once())->method('commit');

        $context->method('getConn')->willReturn($connection);

        $callbackService = new CallbackService(
            $context,
            $this->createMock(PlatformRegistry::class),
            $this->createMock(ServiceFactory::class)
        );

        $callbackService->purgeBusinessInstallation('business-123');

        $this->assertNotContains('DELETE FROM app_token WHERE business_id = :biz', $capturedStatements);
        $this->assertNotContains('DELETE FROM merchant_token WHERE business_id = :biz', $capturedStatements);
    }

    public function testPurgeAndReinstallNormalizesPersistedTokens(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('info')->willReturn(null);
        $logger->method('debug')->willReturn(null);
        $logger->method('error')->willReturn(null);

        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);
        $context->method('getConn')->willReturn($this->createMock(Connection::class));

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('saveAppToken')
            ->with(
                'business-123',
                $this->callback(function (array $payload): bool {
                    $this->assertSame('app-access', $payload['accessToken']);
                    $this->assertSame('app-refresh', $payload['refreshToken']);
                    $this->assertArrayHasKey('expiresIn', $payload);
                    $this->assertInstanceOf(DateTime::class, $payload['expiresIn']);

                    return true;
                })
            );

        $tokenService->expects($this->once())
            ->method('saveMerchantToken')
            ->with(
                'business-123',
                $this->callback(function (array $payload): bool {
                    $this->assertSame('merchant-access', $payload['accessToken']);
                    $this->assertSame('merchant-refresh', $payload['refreshToken']);
                    $this->assertArrayHasKey('expiresIn', $payload);
                    $this->assertInstanceOf(DateTime::class, $payload['expiresIn']);

                    return true;
                })
            );

        $serviceFactory = $this->createMock(ServiceFactory::class);
        $serviceFactory->method('token')->willReturn($tokenService);

        $callbackService = $this->getMockBuilder(CallbackService::class)
            ->setConstructorArgs([
                $context,
                $this->createMock(PlatformRegistry::class),
                $serviceFactory,
            ])
            ->onlyMethods(['purgeBusinessInstallation', 'runBusinessWorkflow'])
            ->getMock();

        $callbackService->expects($this->once())
            ->method('purgeBusinessInstallation')
            ->with('business-123', false)
            ->willReturn(true);

        $callbackService->expects($this->once())
            ->method('runBusinessWorkflow')
            ->with(
                'business-123',
                'store-456',
                $this->callback(function (array $payload): bool {
                    return isset($payload['accessToken'], $payload['refreshToken'], $payload['expiresIn']);
                }),
                $this->callback(function (array $payload): bool {
                    return isset($payload['accessToken'], $payload['refreshToken'], $payload['expiresIn']);
                }),
                null,
                null
            )
            ->willReturn(true);

        $result = $callbackService->purgeAndReinstall(
            'business-123',
            'store-456',
            [
                'access_token' => 'app-access',
                'refresh_token' => 'app-refresh',
                'expires_at' => '2024-01-02T03:04:05+00:00',
            ],
            [
                'access_token' => 'merchant-access',
                'refresh_token' => 'merchant-refresh',
                'expires_at' => '2024-01-03T04:05:06+00:00',
            ]
        );

        $this->assertTrue($result);
    }

    public function testSelectDefaultPlanIdHandlesNestedItems(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $callbackService = $this->createCallbackService($context);

        $plans = [
            'items' => [
                [
                    'planId' => 'plan-basic',
                    'status' => 'ACTIVE',
                    'scope' => 'STORE',
                ],
                [
                    'planId' => 'plan-pro',
                    'status' => 'inactive',
                    'scope' => 'BUSINESS',
                ],
            ],
            'links' => [],
        ];

        $method = new ReflectionMethod($callbackService, 'selectDefaultPlanId');
        $method->setAccessible(true);

        $this->assertSame('plan-basic', $method->invoke($callbackService, $plans));
    }

    public function testSelectDefaultPlanIdPrefersStoreScopedPlans(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $callbackService = $this->createCallbackService($context);

        $plans = [
            'items' => [
                [
                    'planId' => 'plan-business',
                    'status' => 'ACTIVE',
                    'scope' => 'BUSINESS',
                ],
                [
                    'planId' => 'plan-store',
                    'status' => 'ACTIVE',
                    'scopeType' => 'STORE',
                ],
            ],
        ];

        $method = new ReflectionMethod($callbackService, 'selectDefaultPlanId');
        $method->setAccessible(true);

        $this->assertSame('plan-store', $method->invoke($callbackService, $plans));
    }

    public function testSelectDefaultPlanIdFallsBackWhenNoStoreScopedPlans(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $callbackService = $this->createCallbackService($context);

        $plans = [
            'items' => [
                [
                    'planId' => 'plan-business-active',
                    'status' => 'ACTIVE',
                    'scope' => 'BUSINESS',
                ],
                [
                    'planId' => 'plan-business-disabled',
                    'status' => 'disabled',
                    'scope' => 'BUSINESS',
                ],
            ],
        ];

        $method = new ReflectionMethod($callbackService, 'selectDefaultPlanId');
        $method->setAccessible(true);

        $this->assertSame('plan-business-active', $method->invoke($callbackService, $plans));
    }

    public function testFindPlanIdByNameMatchesCaseInsensitive(): void
    {
        $logger = $this->createMock(Logger::class);
        $context = $this->createMock(Context::class);
        $context->method('getLog')->willReturn($logger);

        $callbackService = $this->createCallbackService($context);

        $plans = [
            'plans' => [
                [
                    'planId' => 'plan-basic',
                    'name' => 'Basic',
                    'status' => 'active',
                ],
                [
                    'planId' => 'plan-pro',
                    'name' => 'Pro',
                    'status' => 'active',
                ],
            ],
        ];

        $method = new ReflectionMethod($callbackService, 'findPlanIdByName');
        $method->setAccessible(true);

        $this->assertSame('plan-pro', $method->invoke($callbackService, $plans, 'PRO'));
    }
}
