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

        public function testUpsertPersistsCatalogProductsWithTimestampsAndTaxes(): void
        {
            $productCreated = '2024-03-01T12:00:00Z';
            $productUpdated = '2024-03-02T12:00:00Z';
            $taxCreated = '2024-03-05T09:15:00Z';
            $taxUpdated = '2024-03-06T18:45:00Z';

            $catalogData = [
                'id' => 'catalog-1',
                'businessId' => 'biz-1',
                'products' => [
                    [
                        'id' => 'product-1',
                        'displayOrder' => 7,
                        'createdAt' => $productCreated,
                        'updatedAt' => $productUpdated,
                        'taxes' => [
                            [
                                'id' => 'tax-1',
                                'createdAt' => $taxCreated,
                                'updatedAt' => $taxUpdated,
                                'amount' => 50,
                            ],
                        ],
                    ],
                ],
            ];

            $expectedProductPayload = Format::jsonObject($catalogData['products'][0]);
            $expectedProductCreatedAtExt = Format::optionalTimestamp($productCreated);
            $expectedProductUpdatedAtExt = Format::optionalTimestamp($productUpdated);

            $expectedTaxPayload = Format::jsonObject($catalogData['products'][0]['taxes'][0]);
            $expectedTaxCreatedAtExt = Format::optionalTimestamp($taxCreated);
            $expectedTaxUpdatedAtExt = Format::optionalTimestamp($taxUpdated);

            $this->connection
                ->expects($this->exactly(3))
                ->method('executeStatement')
                ->withConsecutive(
                    [
                        $this->stringContains('INSERT INTO catalog'),
                        $this->arrayHasKey('catalogId'),
                    ],
                    [
                        $this->stringContains('INSERT INTO catalog_product'),
                        $this->callback(function (array $params) use (
                            $expectedProductPayload,
                            $expectedProductCreatedAtExt,
                            $expectedProductUpdatedAtExt
                        ): bool {
                            self::assertSame('catalog-1', $params['catalogId']);
                            self::assertSame('product-1', $params['productId']);
                            self::assertSame(7, $params['position']);
                            self::assertSame($expectedProductPayload, $params['payload']);
                            self::assertSame($expectedProductCreatedAtExt, $params['createdAtExt']);
                            self::assertSame($expectedProductUpdatedAtExt, $params['updatedAtExt']);
                            self::assertArrayHasKey('createdAt', $params);
                            self::assertArrayHasKey('updatedAt', $params);

                            return true;
                        }),
                    ],
                    [
                        $this->stringContains('INSERT INTO catalog_product_tax'),
                        $this->callback(function (array $params) use (
                            $expectedTaxPayload,
                            $expectedTaxCreatedAtExt,
                            $expectedTaxUpdatedAtExt
                        ): bool {
                            self::assertSame('catalog-1', $params['catalogId']);
                            self::assertSame('product-1', $params['productId']);
                            self::assertSame('tax-1', $params['taxId']);
                            self::assertSame($expectedTaxPayload, $params['payload']);
                            self::assertSame($expectedTaxCreatedAtExt, $params['createdAtExt']);
                            self::assertSame($expectedTaxUpdatedAtExt, $params['updatedAtExt']);
                            self::assertArrayHasKey('createdAt', $params);
                            self::assertArrayHasKey('updatedAt', $params);

                            return true;
                        }),
                    ]
                )
                ->willReturnOnConsecutiveCalls(1, 1, 1);

            $service = new CatalogService($this->context, null, $this->createMock(ClientInterface::class));

            self::assertTrue($service->upsert($catalogData));
            self::assertFalse($this->testHandler->hasErrorRecords());
        }
    }
}

