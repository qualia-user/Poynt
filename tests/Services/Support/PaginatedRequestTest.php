<?php

declare(strict_types=1);

namespace Tests\Services\Support;

use App\Services\Support\PaginatedRequest;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class PaginatedRequestTest extends TestCase
{
    public function testCollectsNextPageWhenCountIsMissing(): void
    {
        $initialData = [
            'links' => [
                [
                    'href' => '/businesses/biz-123/products?page=2',
                    'rel' => 'next',
                    'method' => 'GET',
                ],
            ],
            'products' => [
                ['id' => 'product-id-1'],
            ],
        ];

        $nextPage = [
            'products' => [
                ['id' => 'product-id-2'],
            ],
        ];

        $client = new PaginatedRequestTestHttpClient([
            new Response(200, [], json_encode($nextPage, JSON_THROW_ON_ERROR)),
        ]);

        $result = PaginatedRequest::collect(
            $client,
            $initialData,
            'https://api.example.com/businesses/biz-123/products',
            [],
            'products'
        );

        self::assertSame([
            'https://api.example.com/businesses/biz-123/products?page=2',
        ], $client->requestedUrls);

        self::assertArrayNotHasKey('count', $result);
        self::assertCount(2, $result['products']);
        self::assertSame('product-id-1', $result['products'][0]['id']);
        self::assertSame('product-id-2', $result['products'][1]['id']);
    }
}

class PaginatedRequestTestHttpClient implements ClientInterface
{
    /** @var ResponseInterface[] */
    private array $responses;

    /** @var string[] */
    public array $requestedUrls = [];

    /**
     * @param ResponseInterface[] $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request($method, $uri = '', array $options = []): ResponseInterface
    {
        if (strtoupper((string) $method) !== 'GET') {
            throw new \BadMethodCallException('Only GET is supported in tests');
        }

        return $this->get($uri, $options);
    }

    public function requestAsync($method, $uri = '', array $options = []): PromiseInterface
    {
        throw new \BadMethodCallException('Not implemented in tests');
    }

    public function getConfig(?string $option = null): mixed
    {
        return null;
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->request($request->getMethod(), (string) $request->getUri(), $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new \BadMethodCallException('Not implemented in tests');
    }

    public function get($uri, array $options = []): ResponseInterface
    {
        $this->requestedUrls[] = $uri;

        if ($this->responses === []) {
            throw new \RuntimeException('No responses available');
        }

        return array_shift($this->responses);
    }
}
