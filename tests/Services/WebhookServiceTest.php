<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Core\Context;
use App\Services\WebhookService;
use App\Services\Support\PoyntDataFormatter as Format;
use Doctrine\DBAL\Connection;
use GuzzleHttp\ClientInterface;
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
}
