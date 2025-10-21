<?php

declare(strict_types=1);

namespace App\Config {
    if (!class_exists(__NAMESPACE__ . '\\ConfigApp')) {
        class ConfigApp
        {
            public static string $orgId = '';
            public static string $appId = '';
        }
    }
}

namespace Services {

    use App\Config\ConfigApp;
    use App\Core\Api;
    use App\Core\Context;
    use App\Services\SubscriptionService;
    use Doctrine\DBAL\Connection;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\RequestException;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Request;
    use GuzzleHttp\Psr7\Response;
    use GuzzleHttp\Middleware;
    use Monolog\Handler\TestHandler;
    use Monolog\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * @covers \App\Services\SubscriptionService::fetchPlans
     */
    class SubscriptionServiceTest extends TestCase
    {
        private Context $context;

        private TestHandler $testHandler;

        /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
        private Connection $connection;

        /** @var array<int, array<string, mixed>> */
        private array $history = [];

        protected function setUp(): void
        {
            parent::setUp();

            $api = $this->createMock(Api::class);
            $this->connection = $this->createMock(Connection::class);
            $this->testHandler = new TestHandler();
            $logger = new Logger('test');
            $logger->pushHandler($this->testHandler);

            $this->context = new Context($api, $this->connection, $logger);

            ConfigApp::$orgId = 'test-org';
            ConfigApp::$appId = 'test-app';

            $this->history = [];
        }

        public function testFetchSubscriptionsSkipsNonArrayEntries(): void
        {
            $subscription = [
                'subscriptionId' => 'sub-1',
                'businessId' => 'biz-1',
                'storeId' => 'store-1',
                'planId' => 'plan-basic',
                'status' => 'active',
                'phase' => 'active',
                'trialStart' => null,
                'trialEnd' => null,
                'startAt' => '2024-01-01T00:00:00Z',
                'currentPeriodEnd' => null,
                'cancelAtPeriodEnd' => false,
                'canceledAt' => null,
            ];

            $this->connection
                ->expects(self::once())
                ->method('executeStatement')
                ->with(self::stringContains('INSERT INTO subscription'), self::isType('array'))
                ->willReturn(1);

            $this->connection
                ->expects(self::never())
                ->method('fetchAssociative');

            $service = $this->createServiceWithQueue([
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'subscriptions' => [$subscription],
                        'total' => 1,
                    ], JSON_THROW_ON_ERROR)
                ),
            ]);

            $result = $service->fetchSubscriptions('token', 'biz-1');

            self::assertSame([$subscription], $result);
            self::assertCount(1, $this->history);
            self::assertSame('Bearer token', $this->history[0]['request']->getHeaderLine('Authorization'));
        }

        public function testFetchSubscriptionsHandlesListPayloadKey(): void
        {
            $subscription = [
                'subscriptionId' => 'sub-2',
                'businessId' => 'biz-2',
                'storeId' => 'store-99',
                'planId' => 'plan-pro',
                'status' => 'active',
            ];

            $this->connection
                ->expects(self::once())
                ->method('executeStatement')
                ->with(self::stringContains('INSERT INTO subscription'), self::isType('array'))
                ->willReturn(1);

            $service = $this->createServiceWithQueue([
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'list' => [$subscription],
                        'total' => 1,
                        'start' => 0,
                        'count' => 1,
                    ], JSON_THROW_ON_ERROR)
                ),
            ]);

            $result = $service->fetchSubscriptions('token', 'biz-2');

            self::assertSame([$subscription], $result);
            self::assertFalse($this->testHandler->hasWarningRecords());
        }

        public function testFetchSubscriptionsRetriesWithMerchantTokenWhenBusinessClaimMissing(): void
        {
            $subscription = [
                'subscriptionId' => 'sub-1',
                'businessId' => 'biz-1',
                'storeId' => 'store-1',
                'planId' => 'plan-basic',
                'status' => 'active',
                'phase' => 'active',
                'trialStart' => null,
                'trialEnd' => null,
                'startAt' => '2024-01-01T00:00:00Z',
                'currentPeriodEnd' => null,
                'cancelAtPeriodEnd' => false,
                'canceledAt' => null,
            ];

            $this->connection
                ->expects(self::once())
                ->method('executeStatement')
                ->with(self::stringContains('INSERT INTO subscription'), self::isType('array'))
                ->willReturn(1);

            $this->connection
                ->expects(self::once())
                ->method('fetchAssociative')
                ->willReturn(['access_token' => 'merchant-token']);

            $errorBody = json_encode([
                'code' => 'UNAUTHORIZED_ACCESS',
                'httpStatus' => 401,
                'message' => 'Access not authorized for the requested resource.',
                'developerMessage' => 'The businessId must be present in the JWT',
                'requestId' => 'test-request',
            ], JSON_THROW_ON_ERROR);

            $service = $this->createServiceWithQueue([
                new Response(401, ['Content-Type' => 'application/json'], $errorBody),
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['subscriptions' => [$subscription]], JSON_THROW_ON_ERROR)
                ),
            ]);

            $result = $service->fetchSubscriptions('app-token', 'biz-1');

            self::assertSame([$subscription], $result);
            self::assertCount(2, $this->history);
            self::assertSame('Bearer app-token', $this->history[0]['request']->getHeaderLine('Authorization'));
            self::assertSame('Bearer merchant-token', $this->history[1]['request']->getHeaderLine('Authorization'));
            self::assertTrue($this->testHandler->hasInfoThatContains('Retrying subscription fetch with merchant token'));
        }

        public function testFetchPlansReturnsDecodedResponseAndDoesNotLogErrors(): void
        {
            $expected = [
                ['planId' => '123', 'name' => 'Starter'],
            ];

            $service = $this->createServiceWithQueue([
                new Response(200, ['Content-Type' => 'application/json'], json_encode($expected, JSON_THROW_ON_ERROR)),
            ]);

            $result = $service->fetchPlans('token');

            self::assertSame($expected, $result);
            self::assertFalse($this->testHandler->hasErrorRecords());
        }

        public function testFetchPlansReturnsNullAndLogsErrorWhenJsonInvalid(): void
        {
            $service = $this->createServiceWithQueue([
                new Response(200, ['Content-Type' => 'application/json'], '{invalid json'),
            ]);

            $result = $service->fetchPlans('token');

            self::assertNull($result);
            self::assertTrue($this->testHandler->hasErrorThatContains('Error parsing plans response'));
        }

        public function testFetchPlansReturnsNullAndLogsWhenRequestExceptionOccurs(): void
        {
            $service = $this->createServiceWithQueue([
                new RequestException(
                    'Server error',
                    new Request('GET', SubscriptionService::POYNT_BILLING_BASE)
                ),
            ]);

            $result = $service->fetchPlans('token');

            self::assertNull($result);
            self::assertTrue($this->testHandler->hasErrorThatContains('Error fetching plans'));
        }

        /**
         * @param array<int, mixed> $queue
         */
        private function createServiceWithQueue(array $queue): SubscriptionService
        {
            $mockHandler = new MockHandler($queue);
            $handlerStack = HandlerStack::create($mockHandler);
            $handlerStack->push(Middleware::history($this->history));
            $client = new Client([
                'handler' => $handlerStack,
                'base_uri' => SubscriptionService::POYNT_BILLING_BASE,
                'http_errors' => true,
            ]);

            return new SubscriptionService($this->context, null, null, $client);
        }

    }
}

