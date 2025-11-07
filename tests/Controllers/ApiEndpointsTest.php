<?php

declare(strict_types=1);

namespace App\Config {
    if (!class_exists(__NAMESPACE__ . '\\ConfigApp')) {
        class ConfigApp
        {
            public static string $platform = '';
            public static string $orgId = '';
            public static string $appId = '';
        }
    }
}

namespace Controllers {

    use App\Controllers\OAuthController;
    use App\Controllers\SubscriptionController;
    use App\Controllers\TokenController;
    use App\Controllers\WebhooksController;
    use App\Core\Api;
    use App\Core\Context;
    use App\Core\Response;
    use App\Core\RouterResolver;
    use App\Modules\OAuth\PlatformRegistry;
    use App\Services\BackgroundJobService;
    use App\Services\CallbackService;
    use App\Services\BusinessUserService;
    use App\Services\InventoryService;
    use App\Services\OrderService;
    use App\Services\ProductService;
    use App\Services\ServiceFactory;
    use App\Services\SubscriptionService;
    use App\Services\TransactionService;
    use Doctrine\DBAL\Connection;
    use League\Container\Container;
    use Monolog\Handler\TestHandler;
    use Monolog\Logger;
    use Phroute\Phroute\Dispatcher;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;

    class ApiEndpointsTest extends TestCase
    {
        private TestHandler $testHandler;
        private Logger $logger;

        protected function setUp(): void
        {
            parent::setUp();
            $this->testHandler = new TestHandler();
            $this->logger = new Logger('test');
            $this->logger->pushHandler($this->testHandler);
            Api::disableExit();
            Api::clearLastResponse();
        }

        protected function tearDown(): void
        {
            Api::enableExit();
            Api::clearLastResponse();
            parent::tearDown();
        }

        public function testInstallRouteReturnsStaticMessage(): void
        {
            $api = $this->createApi(method: 'GET');
            $container = new Container();
            $container->add('CONTEXT', $this->createContext($api));
            $dispatcher = new Dispatcher($api->loadRouteData(), new RouterResolver($container));

            $result = $dispatcher->dispatch('GET', '/install');

            self::assertSame('Install endpoint reached!', $result);
        }

        public function testCallbackEndpointReturnsSuccessMessage(): void
        {
            $api = $this->createApi(['platform' => 'poynt'], 'GET');
            $context = $this->createContext($api);

            /** @var PlatformRegistry&MockObject $platformRegistry */
            $platformRegistry = $this->getMockBuilder(PlatformRegistry::class)
                ->disableOriginalConstructor()
                ->getMock();

            /** @var CallbackService&MockObject $callbackService */
            $callbackService = $this->getMockBuilder(CallbackService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['handle'])
                ->getMock();

            $callbackService->expects(self::once())
                ->method('handle')
                ->with('poynt')
                ->willReturn([
                    'success' => true,
                    'status' => Response::STATUS_OK,
                    'message' => 'Callback handled',
                ]);

            $controller = new OAuthController($context, $platformRegistry, $callbackService);
            $controller->callback();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
            self::assertSame(['message' => 'Callback handled'], $last['response']);
        }

        public function testCallbackEndpointLogsErrorWhenFailureOccurs(): void
        {
            $api = $this->createApi(['platform' => 'poynt'], 'GET');
            $context = $this->createContext($api);

            $platformRegistry = $this->getMockBuilder(PlatformRegistry::class)
                ->disableOriginalConstructor()
                ->getMock();

            $callbackService = $this->getMockBuilder(CallbackService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['handle'])
                ->getMock();

            $callbackService->expects(self::once())
                ->method('handle')
                ->with('poynt')
                ->willReturn([
                    'success' => false,
                    'status' => Response::STATUS_BAD_REQUEST,
                    'error' => 'Bad request',
                ]);

            $controller = new OAuthController($context, $platformRegistry, $callbackService);
            $controller->callback();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_BAD_REQUEST, $last['status']);
            self::assertSame(['error' => 'Bad request'], $last['response']);
        }

        public function testRefreshTokensEndpointRunsBackgroundJob(): void
        {
            $api = $this->createApi([], 'POST');
            $context = $this->createContext($api);

            /** @var BackgroundJobService&MockObject $jobService */
            $jobService = $this->getMockBuilder(BackgroundJobService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['refreshExpiringTokens'])
                ->getMock();

            $jobService->expects(self::once())->method('refreshExpiringTokens');

            $controller = new TokenController($context);
            $controller->setBackgroundJobService($jobService);

            $result = $controller->refreshTokens();

            self::assertSame(['status' => 'success'], $result);
        }

        public function testSubscriptionStatusReturnsCurrentStatus(): void
        {
            $api = $this->createApi([
                'merchantAccessToken' => 'token',
                'businessId' => 'biz',
                'storeId' => 'store',
            ], 'GET');
            $context = $this->createContext($api);

            /** @var SubscriptionService&MockObject $service */
            $service = $this->getMockBuilder(SubscriptionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['fetchMerchantSubscription', 'hasTrialExpired'])
                ->getMock();

            $service->expects(self::once())
                ->method('fetchMerchantSubscription')
                ->with('token', 'biz', 'store')
                ->willReturn([
                    [
                        'subscriptionId' => 'sub-123',
                        'status' => 'ACTIVE',
                    ],
                ]);

            $service->expects(self::once())
                ->method('hasTrialExpired')
                ->with('sub-123')
                ->willReturn(false);

            $controller = new SubscriptionController($context);
            $controller->setSubscriptionService($service);

            $controller->status();
            $last = Api::getLastResponse();

            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
            self::assertSame([
                'subscriptionStatus' => 'ACTIVE',
                'trialExpired' => false,
            ], $last['response']);
        }

        public function testSubscriptionStatusTreatsPastEndDateAsEnded(): void
        {
            $api = $this->createApi([
                'merchantAccessToken' => 'token',
                'businessId' => 'biz',
                'storeId' => 'store',
            ], 'GET');
            $context = $this->createContext($api);

            /** @var SubscriptionService&MockObject $service */
            $service = $this->getMockBuilder(SubscriptionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['fetchMerchantSubscription', 'hasTrialExpired'])
                ->getMock();

            $service->expects(self::once())
                ->method('fetchMerchantSubscription')
                ->with('token', 'biz', 'store')
                ->willReturn([
                    [
                        'subscriptionId' => 'sub-123',
                        'status' => 'ACTIVE',
                        'endAt' => '2020-01-01T00:00:00Z',
                    ],
                ]);

            $service->expects(self::once())
                ->method('hasTrialExpired')
                ->with('sub-123')
                ->willReturn(false);

            $controller = new SubscriptionController($context);
            $controller->setSubscriptionService($service);

            $controller->status();
            $last = Api::getLastResponse();

            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
            self::assertSame([
                'subscriptionStatus' => 'ENDED',
                'trialExpired' => false,
            ], $last['response']);
        }

        public function testSubscriptionStatusReturnsBadRequestWhenMissingParameters(): void
        {
            $api = $this->createApi([], 'GET');
            $context = $this->createContext($api);

            $controller = new SubscriptionController($context);
            $controller->status();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_BAD_REQUEST, $last['status']);
            self::assertSame(['error' => 'Missing required parameters'], $last['response']);
        }

        public function testStartTrialEndpointReturnsNewSubscriptionId(): void
        {
            $api = $this->createApi([
                'businessId' => 'biz',
                'storeId' => 'store',
                'trialPlanId' => 'trial-plan',
            ], 'POST');
            $context = $this->createContext($api);

            $service = $this->getMockBuilder(SubscriptionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['startFreeTrial'])
                ->getMock();

            $service->expects(self::once())
                ->method('startFreeTrial')
                ->with('biz', 'store', 'trial-plan')
                ->willReturn('sub-456');

            $controller = new SubscriptionController($context);
            $controller->setSubscriptionService($service);

            $result = $controller->startTrial();

            self::assertSame([
                'subscriptionId' => 'sub-456',
                'status' => 'free_trial',
            ], $result);
        }

        public function testWebhookEndpointProcessesSubscriptionStartEvent(): void
        {
            $payload = [
                'eventType' => 'APPLICATION_SUBSCRIPTION_START',
                'subscriptionId' => 'sub-1',
                'businessId' => 'biz-1',
                'storeId' => 'store-1',
            ];

            $api = $this->createApi($payload, 'POST');
            $connection = $this->createMock(Connection::class);

            $connection->expects(self::once())
                ->method('insert')
                ->with('webhook_audit', self::callback(static function (array $data) use ($payload): bool {
                    return $data['event_type'] === $payload['eventType'];
                }), self::anything());

            $connection->expects(self::once())
                ->method('lastInsertId')
                ->willReturn('1');

            $connection->expects(self::once())
                ->method('update')
                ->with('webhook_audit', self::arrayHasKey('processed'), ['id' => '1'], self::anything());

            $context = $this->createContext($api, $connection);

            $service = $this->getMockBuilder(SubscriptionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['activateSubscription', 'cancelSubscription'])
                ->getMock();

            $service->expects(self::once())
                ->method('activateSubscription')
                ->with('sub-1', 'biz-1', 'store-1');

            $controller = new WebhooksController($context);
            $controller->setSubscriptionService($service);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
            self::assertSame(['status' => 'ok'], $last['response']);
        }

        public function testWebhookEndpointReturnsBadRequestForUnknownEvent(): void
        {
            $payload = ['eventType' => 'UNKNOWN_EVENT'];
            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->willReturn(1);
            $connection->expects(self::once())->method('lastInsertId')->willReturn('1');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $controller = new WebhooksController($context);
            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_BAD_REQUEST, $last['status']);
            self::assertSame(['error' => 'Unrecognized event'], $last['response']);
        }

        public function testWebhookEndpointProcessesProductEvent(): void
        {
            $payload = [
                'eventType' => 'PRODUCT_UPDATED',
                'businessId' => 'biz-1',
                'payload' => [
                    'id' => 'prod-1',
                    'name' => 'Widget',
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('42');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $productService = $this->getMockBuilder(ProductService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $productService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['id'] === 'prod-1' && $data['businessId'] === 'biz-1';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['product'])
                ->getMock();

            $factory->expects(self::once())
                ->method('product')
                ->with('biz-1')
                ->willReturn($productService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointFetchesProductWhenPayloadMissing(): void
        {
            $payload = [
                'eventType' => 'PRODUCT_CREATED',
                'businessId' => 'biz-2',
                'resourceId' => 'prod-2',
                'links' => [
                    [
                        'href' => 'https://services.poynt.net/businesses/biz-2/products/prod-2',
                        'rel' => 'resource',
                        'method' => 'GET',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('43');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $productService = $this->getMockBuilder(ProductService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['fetchById', 'upsert'])
                ->getMock();

            $productService->expects(self::once())
                ->method('fetchById')
                ->with('prod-2', 'biz-2')
                ->willReturn([
                    'id' => 'prod-2',
                    'businessId' => 'biz-2',
                    'name' => 'Fetched Product',
                ]);

            $productService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['id'] === 'prod-2'
                        && $data['businessId'] === 'biz-2'
                        && $data['name'] === 'Fetched Product';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['product'])
                ->getMock();

            $factory->expects(self::once())
                ->method('product')
                ->with('biz-2')
                ->willReturn($productService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointProcessesInventoryEvent(): void
        {
            $payload = [
                'eventType' => 'INVENTORY_UPDATED',
                'businessId' => 'biz-2',
                'payload' => [
                    'productId' => 'prod-9',
                    'storeId' => 'store-4',
                    'onHand' => 7,
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('11');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $inventoryService = $this->getMockBuilder(InventoryService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $inventoryService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['businessId'] === 'biz-2'
                        && $data['productId'] === 'prod-9'
                        && $data['storeId'] === 'store-4';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['inventory'])
                ->getMock();

            $factory->expects(self::once())
                ->method('inventory')
                ->with('biz-2')
                ->willReturn($inventoryService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookInventoryEventEnrichesResourceWithContext(): void
        {
            $payload = [
                'eventType' => 'INVENTORY_UPDATED',
                'businessId' => 'biz-3',
                'storeId' => 'store-top',
                'payload' => [
                    'inventory' => [
                        'id' => 'inventory-1',
                        'productId' => 'prod-10',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('12');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $inventoryService = $this->getMockBuilder(InventoryService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $inventoryService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['businessId'] === 'biz-3'
                        && $data['storeId'] === 'store-top'
                        && $data['productId'] === 'prod-10';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['inventory'])
                ->getMock();

            $factory->expects(self::once())
                ->method('inventory')
                ->with('biz-3')
                ->willReturn($inventoryService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookInventoryEventEnrichesResourceWithNestedContext(): void
        {
            $payload = [
                'eventType' => 'INVENTORY_UPDATED',
                'payload' => [
                    'businessId' => 'biz-nested',
                    'storeId' => 'store-nested',
                    'inventory' => [
                        'id' => 'inventory-2',
                        'productId' => 'prod-11',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('112');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $inventoryService = $this->getMockBuilder(InventoryService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $inventoryService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['businessId'] === 'biz-nested'
                        && $data['storeId'] === 'store-nested'
                        && $data['productId'] === 'prod-11';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['inventory'])
                ->getMock();

            $factory->expects(self::once())
                ->method('inventory')
                ->with('biz-nested')
                ->willReturn($inventoryService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointProcessesOrderEvent(): void
        {
            $payload = [
                'eventType' => 'ORDER_COMPLETED',
                'order' => [
                    'id' => 'order-7',
                    'businessId' => 'biz-5',
                    'storeId' => 'store-2',
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('90');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $orderService = $this->getMockBuilder(OrderService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $orderService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['id'] === 'order-7'
                        && $data['businessId'] === 'biz-5'
                        && $data['storeId'] === 'store-2';
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['order'])
                ->getMock();

            $factory->expects(self::once())
                ->method('order')
                ->with('biz-5')
                ->willReturn($orderService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointProcessesTransactionEvent(): void
        {
            $payload = [
                'eventType' => 'TRANSACTION_CAPTURED',
                'payload' => [
                    'transaction' => [
                        'id' => 'txn-3',
                        'businessId' => 'biz-7',
                    ],
                    'receipt' => [
                        'transactionId' => 'txn-3',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('13');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $transactionService = $this->getMockBuilder(TransactionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $transactionService->expects(self::once())
                ->method('upsert')
                ->with(
                    self::callback(static function (array $transaction): bool {
                        return $transaction['id'] === 'txn-3' && $transaction['businessId'] === 'biz-7';
                    }),
                    self::callback(static function (array $receipt): bool {
                        return $receipt['transactionId'] === 'txn-3';
                    })
                );

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['transaction'])
                ->getMock();

            $factory->expects(self::once())
                ->method('transaction')
                ->with('biz-7')
                ->willReturn($transactionService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointProcessesBusinessUserEvent(): void
        {
            $payload = [
                'eventType' => 'BUSINESS_USER_UPDATED',
                'payload' => [
                    'businessUser' => [
                        'userId' => 77,
                        'businessId' => 'biz-8',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('55');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $businessUserService = $this->getMockBuilder(BusinessUserService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsert'])
                ->getMock();

            $businessUserService->expects(self::once())
                ->method('upsert')
                ->with(self::callback(static function (array $data): bool {
                    return $data['businessId'] === 'biz-8' && $data['userId'] === 77;
                }));

            $factory = $this->getMockBuilder(ServiceFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['businessUser'])
                ->getMock();

            $factory->expects(self::once())
                ->method('businessUser')
                ->with('biz-8')
                ->willReturn($businessUserService);

            $controller = new WebhooksController($context);
            $controller->setServiceFactory($factory);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        public function testWebhookEndpointProcessesSubscriptionLifecycleEvent(): void
        {
            $payload = [
                'eventType' => 'APPLICATION_SUBSCRIPTION_PAYMENT_SUCCESS',
                'payload' => [
                    'subscription' => [
                        'id' => 'sub-88',
                        'businessId' => 'biz-9',
                        'storeId' => 'store-9',
                    ],
                ],
            ];

            $api = $this->createApi($payload, 'POST');

            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('insert')->with('webhook_audit', self::anything(), self::anything());
            $connection->expects(self::once())->method('lastInsertId')->willReturn('21');
            $connection->expects(self::once())->method('update');

            $context = $this->createContext($api, $connection);

            $subscriptionService = $this->getMockBuilder(SubscriptionService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['upsertLocalSubscription'])
                ->getMock();

            $subscriptionService->expects(self::once())
                ->method('upsertLocalSubscription')
                ->with(
                    self::callback(static function (array $data): bool {
                        return $data['id'] === 'sub-88' && $data['businessId'] === 'biz-9';
                    }),
                    'store-9'
                );

            $controller = new WebhooksController($context);
            $controller->setSubscriptionService($subscriptionService);

            $controller->eventListener();

            $last = Api::getLastResponse();
            self::assertNotNull($last);
            self::assertSame(Response::STATUS_OK, $last['status']);
        }

        private function createApi(array $data = [], string $method = 'GET'): Api
        {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REQUEST_URI'] = '/';

            $api = new Api('', $this->logger, 'test-request');
            $api->data = $data;

            return $api;
        }

        private function createContext(Api $api, ?Connection $connection = null): Context
        {
            $connection ??= $this->createMock(Connection::class);

            return new Context($api, $connection, $this->logger);
        }
    }
}
