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
            $client = new Client([
                'handler' => $handlerStack,
                'base_uri' => SubscriptionService::POYNT_BILLING_BASE,
            ]);

            return new SubscriptionService($this->context, null, null, $client);
        }

    }
}

