<?php

declare(strict_types=1);

namespace Services {

    use App\Core\Api;
    use App\Core\Context;
    use App\Services\Support\PoyntDataFormatter as Format;
    use App\Services\TransactionService;
    use Doctrine\DBAL\Connection;
    use GuzzleHttp\ClientInterface;
    use Monolog\Handler\TestHandler;
    use Monolog\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * @covers \App\Services\TransactionService
     */
    class TransactionServiceTest extends TestCase
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

        public function testUpsertNormalisesTransactionPayload(): void
        {
            $transactionData = [
                'id' => '29c5b96d-0180-1000-d4b4-c6fb481f142d',
                'signatureRequired' => false,
                'signatureCaptured' => false,
                'pinCaptured' => false,
                'adjusted' => true,
                'amountsAdjusted' => true,
                'authOnly' => true,
                'partiallyApproved' => false,
                'actionVoid' => false,
                'voided' => false,
                'settled' => false,
                'reversalVoid' => false,
                'createdAt' => '2022-04-14T20:31:06Z',
                'updatedAt' => '2022-04-14T20:32:22Z',
                'context' => [
                    'businessType' => 'TEST_MERCHANT',
                    'transmissionAtLocal' => '2022-04-14T20:31:05Z',
                    'employeeUserId' => 369299726,
                    'storeDeviceId' => 'urn:tid:c1e269b9-5afe-379e-b28c-c63a3373f3af',
                    'sourceApp' => 'co.poynt.posconnector',
                    'mcc' => '5812',
                    'transactionInstruction' => 'ONLINE_AUTH_REQUIRED',
                    'source' => 'INSTORE',
                    'businessId' => 'a3f24e9e-6764-42f2-bbcd-5541e7f3be38',
                    'storeId' => '0ac7c74b-d88e-4bd8-8d6b-ddf8db6ec700',
                ],
                'fundingSource' => [
                    'debit' => false,
                    'card' => [
                        'cardBrand' => [
                            'createdAt' => '2020-05-27T16:14:32Z',
                            'scheme' => 'VISA',
                            'displayName' => 'Visa',
                            'id' => 'c74ab71f-9024-4de0-910d-54c6a9c2bbc5',
                        ],
                        'type' => 'VISA',
                        'source' => 'DIRECT',
                        'status' => 'ACTIVE',
                        'expirationDate' => 31,
                        'expirationMonth' => 12,
                        'expirationYear' => 2023,
                        'id' => 369442365,
                        'numberFirst6' => '405413',
                        'numberLast4' => '6357',
                        'numberMasked' => '4054136357',
                        'cardHolderFirstName' => '',
                        'cardHolderLastName' => '',
                        'cardHolderFullName' => '/',
                        'serviceCode' => '201',
                        'cardId' => '885f6b75-5e8f-4933-a55d-cc53a8dda6e9',
                    ],
                    'entryDetails' => [
                        'customerPresenceStatus' => 'PRESENT',
                        'entryMode' => 'CONTACTLESS_MAGSTRIPE',
                    ],
                    'type' => 'CREDIT_DEBIT',
                ],
                'links' => [
                    [
                        'href' => '71241c6f-ff98-46b2-879f-1805596465ca',
                        'rel' => 'CAPTURE',
                        'method' => 'GET',
                    ],
                ],
                'references' => [
                    [
                        'id' => '',
                        'customType' => 'externalReferenceId',
                        'type' => 'CUSTOM',
                    ],
                    [
                        'id' => '38caea72-3965-48af-9dfe-ab81145abe4e',
                        'type' => 'POYNT_ORDER',
                    ],
                ],
                'customerUserId' => 369458304,
                'processorOptions' => [
                    'scaIndicator' => 'supported',
                ],
                'processorResponse' => [
                    'approvedAmount' => 2500,
                    'processor' => 'MOCK',
                    'acquirer' => 'CHASE_PAYMENTECH',
                    'status' => 'Successful',
                    'statusCode' => '1',
                    'statusMessage' => 'Successful',
                    'transactionId' => '29c5b96d-0180-1000-d4b4-c6fb481f142d',
                    'approvalCode' => '219475',
                    'batchId' => '1',
                    'retrievalRefNum' => '29c5b96d-0180-1000-d4b4-c6fb481f142d',
                ],
                'notes' => '',
                'customerLanguage' => 'en',
                'settlementStatus' => 'UNSETTLED',
                'action' => 'AUTHORIZE',
                'amounts' => [
                    'customerOptedNoTip' => false,
                    'transactionAmount' => 7500,
                    'orderAmount' => 2500,
                    'tipAmount' => 5000,
                    'cashbackAmount' => 0,
                    'currency' => 'USD',
                ],
                'status' => 'CAPTURED',
            ];

            $expectedContext = Format::jsonObject($transactionData['context']);
            $expectedFundingSource = Format::jsonObject($transactionData['fundingSource']);
            $expectedLinks = Format::jsonArray($transactionData['links']);
            $expectedReferences = Format::jsonArray($transactionData['references']);
            $expectedProcessorOptions = Format::jsonObject($transactionData['processorOptions']);
            $expectedProcessorResponse = Format::jsonObject($transactionData['processorResponse']);
            $expectedAmountsJson = Format::jsonObject($transactionData['amounts']);
            $expectedRawPayload = Format::jsonObject($transactionData);
            $expectedCreatedAtExt = Format::optionalTimestamp($transactionData['createdAt']);
            $expectedUpdatedAtExt = Format::optionalTimestamp($transactionData['updatedAt']);

            $this->connection
                ->expects($this->once())
                ->method('executeStatement')
                ->with(
                    $this->stringContains('INSERT INTO transaction'),
                    $this->callback(function (array $params) use (
                        $transactionData,
                        $expectedContext,
                        $expectedFundingSource,
                        $expectedLinks,
                        $expectedReferences,
                        $expectedProcessorOptions,
                        $expectedProcessorResponse,
                        $expectedAmountsJson,
                        $expectedRawPayload,
                        $expectedCreatedAtExt,
                        $expectedUpdatedAtExt
                    ): bool {
                        self::assertSame($transactionData['id'], $params['transactionId']);
                        self::assertSame('a3f24e9e-6764-42f2-bbcd-5541e7f3be38', $params['businessId']);
                        self::assertSame('0ac7c74b-d88e-4bd8-8d6b-ddf8db6ec700', $params['storeId']);
                        self::assertSame('urn:tid:c1e269b9-5afe-379e-b28c-c63a3373f3af', $params['storeDeviceId']);
                        self::assertSame(369299726, $params['employeeUserId']);
                        self::assertFalse($params['signatureRequired']);
                        self::assertFalse($params['signatureCaptured']);
                        self::assertFalse($params['pinCaptured']);
                        self::assertTrue($params['adjusted']);
                        self::assertTrue($params['amountsAdjusted']);
                        self::assertTrue($params['authOnly']);
                        self::assertFalse($params['partiallyApproved']);
                        self::assertFalse($params['actionVoid']);
                        self::assertFalse($params['voided']);
                        self::assertFalse($params['settled']);
                        self::assertFalse($params['reversalVoid']);
                        self::assertSame('AUTHORIZE', $params['action']);
                        self::assertSame('CAPTURED', $params['status']);
                        self::assertSame('UNSETTLED', $params['settlementStatus']);
                        self::assertSame('ONLINE_AUTH_REQUIRED', $params['transactionInstruction']);
                        self::assertSame('INSTORE', $params['source']);
                        self::assertSame('co.poynt.posconnector', $params['sourceApp']);
                        self::assertSame('5812', $params['mcc']);
                        self::assertSame(369458304, $params['customerUserId']);
                        self::assertSame('en', $params['customerLanguage']);
                        self::assertFalse($params['customerOptedNoTip']);
                        self::assertSame(7500, $params['txnAmountMinor']);
                        self::assertSame(2500, $params['orderAmountMinor']);
                        self::assertSame(5000, $params['tipAmountMinor']);
                        self::assertSame(0, $params['cashbackAmountMinor']);
                        self::assertSame('USD', $params['currency']);
                        self::assertSame(2500, $params['approvedAmountMinor']);
                        self::assertSame('MOCK', $params['processor']);
                        self::assertSame('CHASE_PAYMENTECH', $params['acquirer']);
                        self::assertSame('Successful', $params['processorStatus']);
                        self::assertSame('1', $params['processorCode']);
                        self::assertSame('219475', $params['approvalCode']);
                        self::assertSame('29c5b96d-0180-1000-d4b4-c6fb481f142d', $params['retrievalRef']);
                        self::assertSame('1', $params['batchId']);
                        self::assertSame('29c5b96d-0180-1000-d4b4-c6fb481f142d', $params['processorTransactionId']);
                        self::assertSame($expectedReferences, $params['referencesJson']);
                        self::assertSame($expectedLinks, $params['linksJson']);
                        self::assertSame($expectedFundingSource, $params['fundingSource']);
                        self::assertSame($expectedContext, $params['contextJson']);
                        self::assertSame($expectedProcessorOptions, $params['processorOptions']);
                        self::assertSame($expectedProcessorResponse, $params['processorResponse']);
                        self::assertSame($expectedAmountsJson, $params['amountsJson']);
                        self::assertSame($expectedRawPayload, $params['rawPayload']);
                        self::assertSame($expectedCreatedAtExt, $params['createdAtExt']);
                        self::assertSame($expectedUpdatedAtExt, $params['updatedAtExt']);
                        self::assertArrayHasKey('createdAt', $params);
                        self::assertArrayHasKey('updatedAt', $params);

                        return true;
                    })
                )
                ->willReturn(1);

            $service = new TransactionService(
                $this->context,
                null,
                $this->createMock(ClientInterface::class)
            );

            self::assertTrue($service->upsert($transactionData));
            self::assertFalse($this->testHandler->hasErrorRecords());
        }
    }
}

