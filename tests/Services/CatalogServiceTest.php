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

        /** @var array<int, array{sql:string,params:array,types:array}> */
        private array $executedStatements;

        protected function setUp(): void
        {
            parent::setUp();

            $api = $this->createMock(Api::class);
            $this->connection = $this->createMock(Connection::class);
            $this->testHandler = new TestHandler();
            $logger = new Logger('test');
            $logger->pushHandler($this->testHandler);

            $this->context = new Context($api, $this->connection, $logger);
            $this->executedStatements = [];

            $this->connection
                ->method('executeStatement')
                ->willReturnCallback(function (string $sql, array $params = [], array $types = []) {
                    $this->executedStatements[] = [
                        'sql' => $sql,
                        'params' => $params,
                        'types' => $types,
                    ];

                    return 1;
                });
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

            $service = new CatalogService($this->context, null, $this->createMock(ClientInterface::class));

            self::assertTrue($service->upsert($catalogData));

            $catalogInsert = $this->findStatement('INSERT INTO catalog');
            self::assertNotNull($catalogInsert);
            self::assertSame('catalog-1', $catalogInsert['params']['catalogId']);
            self::assertArrayHasKey('metadata', $catalogInsert['params']);

            $discountInsert = $this->findStatement('INSERT INTO catalog_available_discount');
            self::assertNotNull($discountInsert);
            self::assertSame('catalog-1', $discountInsert['params']['catalogId']);
            self::assertSame('discount-1', $discountInsert['params']['discountId']);
            self::assertSame($expectedPayload, $discountInsert['params']['payload']);

            self::assertFalse($this->testHandler->hasErrorRecords());
        }

        public function testUpsertPersistsCatalogMetadataAndRelations(): void
        {
            $productCreated = '2024-03-01T12:00:00Z';
            $productUpdated = '2024-03-02T12:00:00Z';
            $taxCreated = '2024-03-05T09:15:00Z';
            $taxUpdated = '2024-03-06T18:45:00Z';

            $catalogData = [
                'id' => 'catalog-1',
                'businessId' => 'biz-1',
                'displayMetadata' => ['theme' => 'dark'],
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
                                'businessId' => 'biz-1',
                            ],
                        ],
                    ],
                ],
                'categories' => [
                    [
                        'id' => 'category-1',
                        'name' => 'Coffee',
                        'products' => [
                            [
                                'id' => 'product-1',
                                'position' => 3,
                            ],
                        ],
                        'taxes' => [
                            [
                                'id' => 'tax-2',
                                'rateBp' => 725,
                                'businessId' => 'biz-1',
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

            $service = new CatalogService($this->context, null, $this->createMock(ClientInterface::class));

            self::assertTrue($service->upsert($catalogData));

            $catalogInsert = $this->findStatement('INSERT INTO catalog');
            self::assertNotNull($catalogInsert);
            self::assertSame(Format::jsonObject($catalogData['displayMetadata']), $catalogInsert['params']['metadata']);

            $productInsert = $this->findStatement('INSERT INTO catalog_product');
            self::assertNotNull($productInsert);
            self::assertSame('product-1', $productInsert['params']['productId']);
            self::assertSame(7, $productInsert['params']['position']);
            self::assertSame($expectedProductPayload, $productInsert['params']['payload']);
            self::assertSame($expectedProductCreatedAtExt, $productInsert['params']['createdAtExt']);
            self::assertSame($expectedProductUpdatedAtExt, $productInsert['params']['updatedAtExt']);

            $productTaxInsert = $this->findStatement('INSERT INTO catalog_product_tax');
            self::assertNotNull($productTaxInsert);
            self::assertSame($expectedTaxPayload, $productTaxInsert['params']['payload']);
            self::assertSame($expectedTaxCreatedAtExt, $productTaxInsert['params']['createdAtExt']);
            self::assertSame($expectedTaxUpdatedAtExt, $productTaxInsert['params']['updatedAtExt']);

            $categoryInsert = $this->findStatement('INSERT INTO category');
            self::assertNotNull($categoryInsert);
            self::assertSame('category-1', $categoryInsert['params']['categoryId']);

            $categoryProductInsert = $this->findStatement('INSERT INTO category_product');
            self::assertNotNull($categoryProductInsert);
            self::assertSame('category-1', $categoryProductInsert['params']['categoryId']);
            self::assertSame('product-1', $categoryProductInsert['params']['productId']);

            $categoryTaxInsert = $this->findStatement('INSERT INTO category_tax');
            self::assertNotNull($categoryTaxInsert);
            self::assertSame('tax-2', $categoryTaxInsert['params']['taxId']);

            $taxUpsert = $this->findStatement('INSERT INTO tax');
            self::assertNotNull($taxUpsert);

            self::assertFalse($this->testHandler->hasErrorRecords());
        }

        public function testUpsertRemovesMissingRelations(): void
        {
            $catalogData = [
                'id' => 'catalog-2',
                'businessId' => 'biz-2',
                'products' => [],
                'categories' => [],
            ];

            $service = new CatalogService($this->context, null, $this->createMock(ClientInterface::class));

            self::assertTrue($service->upsert($catalogData));

            $catalogDeletes = $this->findAllStatements('DELETE FROM catalog_product');
            self::assertNotEmpty($catalogDeletes);
            self::assertTrue($this->statementContains($catalogDeletes, 'product_id = :productId') || $this->statementContains($catalogDeletes, 'catalog_id = :catalogId'));

            $catalogTaxDeletes = $this->findAllStatements('DELETE FROM catalog_product_tax');
            self::assertNotEmpty($catalogTaxDeletes);

            $categoryProductDeletes = $this->findAllStatements('DELETE FROM category_product');
            self::assertNotEmpty($categoryProductDeletes);

            $categoryTaxDeletes = $this->findAllStatements('DELETE FROM category_tax');
            self::assertNotEmpty($categoryTaxDeletes);
        }

        private function findStatement(string $needle): ?array
        {
            foreach ($this->executedStatements as $statement) {
                if (str_contains($statement['sql'], $needle)) {
                    return $statement;
                }
            }

            return null;
        }

        /**
         * @return array<int, array{sql:string,params:array,types:array}>
         */
        private function findAllStatements(string $needle): array
        {
            return array_values(array_filter(
                $this->executedStatements,
                static fn (array $statement): bool => str_contains($statement['sql'], $needle)
            ));
        }

        /**
         * @param array<int, array{sql:string,params:array,types:array}> $statements
         */
        private function statementContains(array $statements, string $fragment): bool
        {
            foreach ($statements as $statement) {
                if (str_contains($statement['sql'], $fragment)) {
                    return true;
                }
            }

            return false;
        }
    }
}

