<?php

declare(strict_types=1);

namespace Services {

    use App\Core\Api;
    use App\Core\Context;
    use App\Services\CatalogService;
    use App\Services\Support\PoyntDataFormatter as Format;
    use Doctrine\DBAL\Connection;
    use GuzzleHttp\ClientInterface;
    use Monolog\Handler\TestHandler;
    use Monolog\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * @covers \App\Services\CatalogService
     */
    class CatalogServiceTest extends TestCase
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
        }

        public function testUpsertPersistsAvailableDiscounts(): void
        {
            $catalogData = [
                'id' => 'catalog-1',
                'businessId' => 'biz-1',
                'availableDiscounts' => [
                    ['id' => 'discount-1', 'name' => 'Promo'],
                ],
            ];

            $expectedPayload = Format::jsonObject($catalogData['availableDiscounts'][0]);

            $this->connection
                ->expects($this->exactly(2))
                ->method('executeStatement')
                ->withConsecutive(
                    [
                        $this->stringContains('INSERT INTO catalog'),
                        $this->arrayHasKey('catalogId'),
                    ],
                    [
                        $this->stringContains('INSERT INTO catalog_available_discount'),
                        $this->callback(function (array $params) use ($expectedPayload): bool {
                            self::assertSame('catalog-1', $params['catalogId']);
                            self::assertSame('discount-1', $params['discountId']);
                            self::assertSame($expectedPayload, $params['payload']);

                            return true;
                        }),
                    ],
                )
                ->willReturnOnConsecutiveCalls(1, 1);

            $service = new CatalogService($this->context, null, $this->createMock(ClientInterface::class));

            self::assertTrue($service->upsert($catalogData));
            self::assertFalse($this->testHandler->hasErrorRecords());
        }
    }
}

