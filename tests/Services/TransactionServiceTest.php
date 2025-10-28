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
                'id' => 'd2ac85ec-c346-4dcf-9695-e626bdb6add7',
                'businessId' => '3fb53611-061a-4464-8ca7-0b91ca7c98cf',
                'signatureRequired' => false,
                'signatureCaptured' => false,
                'pinCaptured' => false,
                'adjusted' => false,
                'amountsAdjusted' => false,
                'authOnly' => false,
                'partiallyApproved' => false,
                'actionVoid' => false,
                'voided' => false,
                'settled' => true,
                'reversalVoid' => false,
                'paymentTokenUsed' => false,
                'createdAt' => '2025-07-21T07:30:05Z',
                'updatedAt' => '2025-07-22T06:41:27Z',
                'context' => [
                    'businessType' => 'TEST_MERCHANT',
                    'transmissionAtLocal' => '2025-07-21T07:30:05Z',
                    'employeeUserId' => 518295917,
                    'storeDeviceId' => 'urn:tid:3b09adcb-716b-3d76-a5e8-ab3deb449b29',
                    'sourceApp' => 'co.poynt.virtualterminal',
                    'mcc' => '1520',
                    'applicationId' => 'urn:aid:virtual-terminal',
                    'source' => 'INSTORE',
                    'businessId' => '3fb53611-061a-4464-8ca7-0b91ca7c98cf',
                    'storeId' => '194a72bf-5802-4354-8ab6-fb482780cef6',
                    'channelId' => 'b3b1e892-b073-11ee-bbe7-02bda9370977',
                ],
                'fundingSource' => [
                    'debit' => false,
                    'debitOverride' => false,
                    'debitBin' => false,
                    'card' => [
                        'cardBrand' => [
                            'createdAt' => '2020-05-27T16:14:32Z',
                            'scheme' => 'MASTERCARD',
                            'displayName' => 'Mastercard',
                            'id' => '8cd7082b-2cdd-4264-a4e3-57f78851ead3',
                        ],
                        'type' => 'MASTERCARD',
                        'source' => 'DIRECT',
                        'status' => 'ACTIVE',
                        'expirationMonth' => 12,
                        'expirationYear' => 2027,
                        'id' => 684057658,
                        'numberFirst6' => '222300',
                        'numberLast4' => '3222',
                        'numberMasked' => '222300******3222',
                        'numberHashed' => 'FA20B7098A5C729CACAD608C549A6EEE5594865766B7D037ECE91C0BDAEF5D8C',
                        'cardHolderFirstName' => '',
                        'cardHolderLastName' => '',
                        'cardHolderFullName' => '',
                        'cardId' => '79f5ee85-166f-438e-b919-8385720a3c3d',
                    ],
                    'entryDetails' => [
                        'customerPresenceStatus' => 'VIRTUAL_TERMINAL_NOT_PRESENT',
                        'entryMode' => 'KEYED',
                    ],
                    'type' => 'CREDIT_DEBIT',
                    'verificationData' => [
                        'cardHolderBillingAddress' => [
                            'status' => 'ADDED',
                            'createdAt' => '2025-07-21T07:30:05Z',
                            'updatedAt' => '2025-07-21T07:30:05Z',
                            'id' => 43644892,
                            'line1' => '2298 Client Ave',
                            'postalCode' => '95076',
                            'countryCode' => 'US',
                        ],
                    ],
                ],
                'customerUserId' => 692824955,
                'processorOptions' => [
                    'processorToken' => '0',
                ],
                'processorResponse' => [
                    'avsResult' => [
                        'addressResult' => 'MATCH',
                        'postalCodeResult' => 'MATCH',
                        'actualResult' => 'Y',
                    ],
                    'cvResult' => 'MATCH',
                    'approvedAmount' => 1000,
                    'processor' => 'MOCK',
                    'acquirer' => 'POYNT',
                    'status' => 'Successful',
                    'statusCode' => '1',
                    'statusMessage' => 'Successful',
                    'approvalCode' => '419763',
                    'batchId' => '1',
                    'retrievalRefNum' => 'd2ac85ec-c346-4dcf-9695-e626bdb6add7',
                    'cvActualResult' => 'M',
                ],
                'transactionNumber' => '5c2aeb14-8464-471e-9fcf-3c27d150c29e',
                'notes' => '',
                'settlementStatus' => 'SETTLED',
                'action' => 'SALE',
                'amounts' => [
                    'customerOptedNoTip' => false,
                    'transactionAmount' => 1000,
                    'orderAmount' => 1000,
                    'tipAmount' => 0,
                    'cashbackAmount' => 0,
                    'currency' => 'USD',
                ],
                'status' => 'CAPTURED',
            ];

            $expectedFundingSource = Format::jsonObject($transactionData['fundingSource']);
            $expectedContext = Format::jsonObject($transactionData['context']);
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
                        $expectedFundingSource,
                        $expectedContext,
                        $expectedRawPayload,
                        $expectedCreatedAtExt,
                        $expectedUpdatedAtExt
                    ): bool {
                        self::assertSame($transactionData['id'], $params['transactionId']);
                        self::assertSame($transactionData['businessId'], $params['businessId']);
                        self::assertSame('194a72bf-5802-4354-8ab6-fb482780cef6', $params['storeId']);
                        self::assertNull($params['orderId']);
                        self::assertSame('SALE', $params['action']);
                        self::assertSame('CAPTURED', $params['status']);
                        self::assertSame('SETTLED', $params['settlementStatus']);
                        self::assertTrue($params['settled']);
                        self::assertFalse($params['partiallyApproved']);
                        self::assertSame(1000, $params['txnAmountMinor']);
                        self::assertSame(1000, $params['orderAmountMinor']);
                        self::assertSame(0, $params['tipAmountMinor']);
                        self::assertSame(0, $params['cashbackAmountMinor']);
                        self::assertSame('USD', $params['currency']);
                        self::assertSame('Mastercard', $params['cardBrand']);
                        self::assertSame('3222', $params['last4']);
                        self::assertSame('KEYED', $params['entryMode']);
                        self::assertSame('MOCK', $params['processor']);
                        self::assertSame('Successful', $params['processorStatus']);
                        self::assertSame('1', $params['processorCode']);
                        self::assertSame('419763', $params['approvalCode']);
                        self::assertSame('d2ac85ec-c346-4dcf-9695-e626bdb6add7', $params['retrievalRef']);
                        self::assertSame('1', $params['batchId']);
                        self::assertSame(692824955, $params['customerUserId']);
                        self::assertSame('[]', $params['referencesJson']);
                        self::assertSame($expectedFundingSource, $params['fundingSource']);
                        self::assertSame($expectedContext, $params['contextJson']);
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
                $transactionData['businessId'],
                $this->createMock(ClientInterface::class)
            );

            self::assertTrue($service->upsert($transactionData));
            self::assertFalse($this->testHandler->hasErrorRecords());
        }
    }
}

