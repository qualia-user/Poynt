<?php

declare(strict_types=1);

namespace App\Config {
    if (!class_exists(__NAMESPACE__ . '\\ConfigApp')) {
        class ConfigApp
        {
            public static string $orgId = '';
            public static string $appId = '';
            public static string $webRootUrl = '';
        }
    }
}

namespace Tests\Services {

    use App\Config\ConfigApp;
    use App\Core\Context;
    use App\Services\Support\PoyntDataFormatter as Format;
    use App\Services\WebhookService;
    use Doctrine\DBAL\Connection;
    use GuzzleHttp\ClientInterface;
    use GuzzleHttp\Psr7\Response;
    use Monolog\Logger;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    class WebhookServiceTest extends TestCase
    {
        public function testSyncDeliveriesPersistsAttemptAndTimestamps(): void
        {
            $logger = $this->createMock(Logger::class);
            $connection = $this->createMock(Connection::class);
            $context = $this->createMock(Context::class);
            $context->method('getLog')->willReturn($logger);
            $context->method('getConn')->willReturn($connection);

            $httpClient = $this->createMock(ClientInterface::class);
            $service = new WebhookService($context, null, $httpClient);

            $delivery = [
                'id'            => 'delivery-123',
                'businessId'    => 'business-abc',
                'hookId'        => 'hook-456',
                'eventType'     => 'APPLICATION_SUBSCRIPTION_START',
                'status'        => 'ERRORED',
                'attempt'       => 4,
                'createdAt'     => '2024-02-01T12:34:56Z',
                'updatedAt'     => '2024-02-01T13:00:00Z',
                'properties'    => ['planId' => 'plan-789'],
                'deliveryUrl'   => 'https://example.test/webhook',
            ];

            $expectedTimestamp = Format::optionalTimestamp($delivery['updatedAt']);

            $connection
                ->expects($this->once())
                ->method('executeStatement')
                ->with(
                    $this->stringContains('INSERT INTO hook_delivery'),
                    $this->callback(function (array $params) use ($expectedTimestamp) {
                        $this->assertArrayHasKey('retryCount', $params);
                        $this->assertSame(4, $params['retryCount']);
                        $this->assertArrayHasKey('deliveredAt', $params);
                        $this->assertSame($expectedTimestamp, $params['deliveredAt']);

                        return true;
                    })
                );

            $method = new ReflectionMethod($service, 'syncDeliveries');
            $method->setAccessible(true);
            $method->invoke($service, 'hook-456', 'business-abc', [$delivery]);
        }

        public function testRegisterWebhookUsesOrgIdForSubscriptionEvents(): void
        {
            ConfigApp::$orgId = 'org-123';
            ConfigApp::$appId = 'app-123';
            ConfigApp::$webRootUrl = 'https://example.test';

            $logger = $this->createMock(Logger::class);
            $logger->method('info');

            $connection = $this->createMock(Connection::class);
            $connection
                ->expects($this->once())
                ->method('insert')
                ->with('webhook_audit', $this->isType('array'));

            $context = $this->createMock(Context::class);
            $context->method('getLog')->willReturn($logger);
            $context->method('getConn')->willReturn($connection);

            $httpClient = $this->createMock(ClientInterface::class);
            $httpClient
                ->expects($this->once())
                ->method('post')
                ->with(
                    WebhookService::POYNT_WEBHOOK_URL,
                    $this->callback(function (array $options): bool {
                        $this->assertSame('Bearer merchant-token', $options['headers']['Authorization']);
                        $this->assertSame('org-123', $options['json']['businessId']);
                        $this->assertSame(
                            ['APPLICATION_SUBSCRIPTION_PAYMENT_SUCCESS'],
                            $options['json']['eventTypes']
                        );

                        return true;
                    })
                )
                ->willReturn(new Response(200, [], json_encode(['id' => 'hook-1'])));

            $service = new WebhookService($context, 'business-123', $httpClient);

            $result = $service->registerWebhook('merchant-token', ['APPLICATION_SUBSCRIPTION_PAYMENT_SUCCESS']);

            $this->assertSame(['id' => 'hook-1'], $result);
        }

        public function testRegisterWebhookUsesBusinessIdWhenNoSubscriptionEvents(): void
        {
            ConfigApp::$orgId = 'org-123';
            ConfigApp::$appId = 'app-123';
            ConfigApp::$webRootUrl = 'https://example.test';

            $logger = $this->createMock(Logger::class);
            $logger->method('info');

            $connection = $this->createMock(Connection::class);
            $connection
                ->expects($this->once())
                ->method('insert')
                ->with('webhook_audit', $this->isType('array'));

            $context = $this->createMock(Context::class);
            $context->method('getLog')->willReturn($logger);
            $context->method('getConn')->willReturn($connection);

            $httpClient = $this->createMock(ClientInterface::class);
            $httpClient
                ->expects($this->once())
                ->method('post')
                ->with(
                    WebhookService::POYNT_WEBHOOK_URL,
                    $this->callback(function (array $options): bool {
                        $this->assertSame('business-123', $options['json']['businessId']);
                        $this->assertSame(['ORDER_COMPLETED'], $options['json']['eventTypes']);

                        return true;
                    })
                )
                ->willReturn(new Response(200, [], json_encode(['id' => 'hook-2'])));

            $service = new WebhookService($context, 'business-123', $httpClient);

            $result = $service->registerWebhook('merchant-token', ['ORDER_COMPLETED']);

            $this->assertSame(['id' => 'hook-2'], $result);
        }

        public function testFetchByBusinessIdDeletesDuplicateHooks(): void
        {
            ConfigApp::$orgId = 'org-456';
            ConfigApp::$appId = 'app-456';

            $logger = $this->createMock(Logger::class);
            $logger->method('info');
            $logger->method('error');
            $logger->method('warning');

            $connection = $this->getMockBuilder(Connection::class)
                ->onlyMethods(['fetchAssociative', 'isTransactionActive', 'isRollbackOnly'])
                ->getMock();
            $connection->method('isTransactionActive')->willReturn(false);
            $connection->method('isRollbackOnly')->willReturn(false);
            $connection
                ->expects($this->exactly(2))
                ->method('fetchAssociative')
                ->with($this->isType('string'), ['biz' => 'business-123'])
                ->willReturnOnConsecutiveCalls(
                    ['access_token' => 'merchant-token'],
                    ['access_token' => 'app-token']
                );

            $context = $this->createMock(Context::class);
            $context->method('getConn')->willReturn($connection);
            $context->method('getLog')->willReturn($logger);

            $httpClient = $this->createMock(ClientInterface::class);
            $httpClient
                ->expects($this->exactly(2))
                ->method('get')
                ->withConsecutive(
                    [
                        WebhookService::POYNT_WEBHOOK_URL,
                        [
                            'headers' => ['Authorization' => 'Bearer app-token'],
                            'query' => ['businessId' => 'business-123'],
                        ],
                    ],
                    [
                        WebhookService::POYNT_WEBHOOK_URL,
                        [
                            'headers' => ['Authorization' => 'Bearer app-token'],
                            'query' => ['businessId' => ConfigApp::$orgId],
                        ],
                    ],
                )
                ->willReturnOnConsecutiveCalls(
                    new Response(200, [], json_encode([
                        'hooks' => [
                            [
                                'id' => 'hook-primary',
                                'eventTypes' => ['ORDER_COMPLETED'],
                                'deliveryUrl' => 'https://example.test/webhooks',
                                'businessId' => 'business-123',
                            ],
                            [
                                'id' => 'hook-duplicate',
                                'eventTypes' => ['ORDER_COMPLETED'],
                                'deliveryUrl' => 'https://example.test/webhooks',
                                'businessId' => 'business-123',
                            ],
                            [
                                'id' => 'hook-other',
                                'eventTypes' => ['INVENTORY_UPDATED'],
                                'deliveryUrl' => 'https://example.test/webhooks',
                                'businessId' => 'business-123',
                            ],
                        ],
                    ])),
                    new Response(200, [], json_encode(['hooks' => []]))
                );

            $httpClient
                ->expects($this->once())
                ->method('delete')
                ->with(
                    WebhookService::POYNT_WEBHOOK_URL . '/hook-duplicate',
                    [
                        'headers' => ['Authorization' => 'Bearer merchant-token'],
                        'query' => ['businessId' => 'business-123'],
                    ]
                );

            $service = new WebhookService($context, 'business-123', $httpClient);

            $result = $service->fetchByBusinessId('business-123');

            $this->assertIsArray($result);
            $this->assertCount(2, $result);
            $this->assertSame('hook-primary', $result[0]['id']);
            $this->assertSame('hook-other', $result[1]['id']);
            $this->assertSame('business-123', $result[0]['businessId']);
        }

        public function testDeleteAllByBusinessIdUsesHookSpecificBusinessIds(): void
        {
            ConfigApp::$orgId = 'org-789';

            $logger = $this->createMock(Logger::class);
            $logger->method('info');
            $logger->method('error');
            $logger->method('warning');

            $connection = $this->getMockBuilder(Connection::class)
                ->onlyMethods(['fetchAssociative', 'isTransactionActive', 'isRollbackOnly'])
                ->getMock();
            $connection->method('isTransactionActive')->willReturn(false);
            $connection->method('isRollbackOnly')->willReturn(false);
            $connection
                ->expects($this->once())
                ->method('fetchAssociative')
                ->with($this->isType('string'), ['biz' => 'business-123'])
                ->willReturn(['access_token' => 'merchant-token']);

            $context = $this->createMock(Context::class);
            $context->method('getConn')->willReturn($connection);
            $context->method('getLog')->willReturn($logger);

            $httpClient = $this->createMock(ClientInterface::class);
            $httpClient
                ->expects($this->exactly(2))
                ->method('delete')
                ->withConsecutive(
                    [
                        WebhookService::POYNT_WEBHOOK_URL . '/hook-business',
                        [
                            'headers' => ['Authorization' => 'Bearer merchant-token'],
                            'query' => ['businessId' => 'business-123'],
                        ],
                    ],
                    [
                        WebhookService::POYNT_WEBHOOK_URL . '/hook-org',
                        [
                            'headers' => ['Authorization' => 'Bearer merchant-token'],
                            'query' => ['businessId' => ConfigApp::$orgId],
                        ],
                    ]
                );

            $service = $this->getMockBuilder(WebhookService::class)
                ->setConstructorArgs([$context, 'business-123', $httpClient])
                ->onlyMethods(['fetchByBusinessId'])
                ->getMock();

            $service
                ->expects($this->once())
                ->method('fetchByBusinessId')
                ->with('business-123')
                ->willReturn([
                    [
                        'id' => 'hook-business',
                        'businessId' => 'business-123',
                        'eventTypes' => ['ORDER_COMPLETED'],
                    ],
                    [
                        'id' => 'hook-org',
                        'eventTypes' => ['APPLICATION_SUBSCRIPTION_START'],
                    ],
                ]);

            $result = $service->deleteAllByBusinessId('business-123');

            $this->assertTrue($result);
        }
    }
}
