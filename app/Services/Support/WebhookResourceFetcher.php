<?php

namespace App\Services\Support;

use App\Core\Context;
use App\Services\TokenService;
use DateTime;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class WebhookResourceFetcher
{
    private const POYNT_BASE_URL = 'https://services.poynt.net';
    private const MAX_HTTP_ATTEMPTS = 3;

    private Context $context;
    private ClientInterface $httpClient;
    private TokenService $tokenService;
    private LoggerInterface $logger;

    public function __construct(Context $context, ?ClientInterface $httpClient = null, ?TokenService $tokenService = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        $this->tokenService = $tokenService ?? new TokenService($context);
        $this->logger = $context->getLog();
    }

    public function getFullEntity(array $payload): ?array
    {
        $resource = $this->normalizeResourceKey($payload['resource'] ?? $this->inferResourceFromEvent($payload['eventType'] ?? ''));
        $resourceId = $this->resolveResourceId($payload);
        $businessId = $this->extractBusinessId($payload);

        if ($resource !== null) {
            $embedded = $this->extractEmbeddedEntity($payload, $resource, $resourceId);
            if ($embedded !== null) {
                return $embedded;
            }
        }

        $href = $this->findResourceHref($payload);
        if ($href === null && $resource !== null && $resourceId !== null) {
            $href = $this->buildFallbackUrl($resource, $resourceId, $businessId);
        }

        if ($href === null) {
            return null;
        }

        $eventType = (string)($payload['eventType'] ?? '');
        $bearer = $this->resolveAuthToken($href, $eventType, $businessId);
        if ($bearer === null) {
            $this->logger->warning('WebhookResourceFetcher: unable to resolve auth token', [
                'resource' => $resource,
                'businessId' => $businessId,
                'eventType' => $eventType,
                'url' => $href,
            ]);

            return null;
        }

        return $this->httpGetJson($href, $bearer);
    }

    private function extractEmbeddedEntity(array $payload, string $resource, ?string $resourceId): ?array
    {
        $candidates = $this->buildResourceCandidates($resource);
        foreach ($candidates as $candidate) {
            if (isset($payload[$candidate]) && is_array($payload[$candidate])) {
                return $payload[$candidate];
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($payload['payload'][$candidate]) && is_array($payload['payload'][$candidate])) {
                return $payload['payload'][$candidate];
            }
            if (isset($payload['data'][$candidate]) && is_array($payload['data'][$candidate])) {
                return $payload['data'][$candidate];
            }
            if (isset($payload['resource'][$candidate]) && is_array($payload['resource'][$candidate])) {
                return $payload['resource'][$candidate];
            }
            if (isset($payload['body'][$candidate]) && is_array($payload['body'][$candidate])) {
                return $payload['body'][$candidate];
            }
        }

        if ($resourceId !== null) {
            $found = $this->searchForIdMatch($payload, $resourceId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildResourceCandidates(string $resource): array
    {
        $base = preg_replace('/[^a-z0-9]/i', '', $resource);
        if ($base === '') {
            return [];
        }

        $singular = $this->singularize($base);
        $variants = array_filter(array_unique([
            $base,
            strtolower($base),
            lcfirst($base),
            $singular,
            strtolower($singular),
            lcfirst($singular),
            strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $base)),
            strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $singular)),
            strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $base)),
            strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $singular)),
        ]));

        return $variants;
    }

    private function singularize(string $value): string
    {
        if (str_ends_with(strtolower($value), 'ies')) {
            return substr($value, 0, -3) . 'y';
        }
        if (str_ends_with(strtolower($value), 's')) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function searchForIdMatch(array $payload, string $resourceId): ?array
    {
        foreach ($payload as $value) {
            if (is_array($value)) {
                if (isset($value['id']) && (string)$value['id'] === $resourceId) {
                    return $value;
                }

                $nested = $this->searchForIdMatch($value, $resourceId);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function normalizeResourceKey(?string $resource): ?string
    {
        if (!is_string($resource)) {
            return null;
        }

        $trimmed = trim($resource);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function inferResourceFromEvent(?string $eventType): ?string
    {
        if (!is_string($eventType) || $eventType === '') {
            return null;
        }

        $upper = strtoupper($eventType);
        $parts = explode('_', $upper);
        if (count($parts) === 0) {
            return null;
        }

        if ($parts[0] === 'APPLICATION' && isset($parts[1])) {
            return strtolower($parts[1]);
        }

        if ($parts[0] === 'ORDER' && isset($parts[1]) && $parts[1] === 'ITEM') {
            return 'orderItem';
        }

        return strtolower($parts[0]);
    }

    private function resolveResourceId(array $payload): ?string
    {
        $candidates = [
            $payload['resourceId'] ?? null,
            $payload['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function extractBusinessId(array $payload): ?string
    {
        $candidates = [
            $payload['businessId'] ?? null,
            $payload['context']['businessId'] ?? null,
            $payload['payload']['businessId'] ?? null,
            $payload['data']['businessId'] ?? null,
            $payload['resource']['businessId'] ?? null,
            $payload['body']['businessId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return max(0, (int)$value);
        }

        try {
            $retryDate = new DateTime($value);
            $diff = $retryDate->getTimestamp() - time();
            return $diff > 0 ? $diff : 0;
        } catch (\Throwable) {
            return null;
        }
    }

    private function searchRetryAfterDelay(array $headers): ?int
    {
        $header = $headers['Retry-After'][0] ?? null;
        if (is_array($header)) {
            $header = $header[0] ?? null;
        }

        if (is_string($header)) {
            return $this->parseRetryAfter($header);
        }

        return null;
    }

    private function isMerchantScopedUrl(string $url): bool
    {
        return str_contains($url, '/businesses/');
    }

    public function findResourceHref(array $payload): ?string
    {
        $links = $payload['links'] ?? null;
        if (!is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? null;
            $method = strtoupper((string)($link['method'] ?? 'GET'));
            $href = $link['href'] ?? null;

            if ($rel === 'resource' && $method === 'GET' && is_string($href) && $href !== '') {
                return $href;
            }
        }

        return null;
    }

    public function buildFallbackUrl(string $resource, string $resourceId, ?string $businessId): ?string
    {
        $path = trim($resource, '/');
        if ($path === '') {
            return null;
        }

        if ($businessId !== null && $businessId !== '') {
            return sprintf('%s/businesses/%s/%s/%s', self::POYNT_BASE_URL, $businessId, $path, $resourceId);
        }

        return sprintf('%s/%s/%s', self::POYNT_BASE_URL, $path, $resourceId);
    }

    public function resolveAuthToken(string $url, string $eventType, ?string $businessId): ?string
    {
        if ($businessId !== null && $businessId !== '') {
            $merchantToken = $this->loadMerchantToken($businessId);

            if ($this->isMerchantScopedUrl($url)) {
                return $merchantToken;
            }

            if (str_starts_with($eventType, 'APPLICATION_') || str_contains($url, '/subscriptions')) {
                return $this->loadAppToken($businessId);
            }

            if (is_string($merchantToken) && $merchantToken !== '') {
                return $merchantToken;
            }

            return $this->loadAppToken($businessId);
        }

        return null;
    }

    private function loadMerchantToken(string $businessId): ?string
    {
        try {
            $token = $this->tokenService->getMerchantToken($businessId);
            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $exception) {
            $this->logger->error('WebhookResourceFetcher: failed to load merchant token', [
                'businessId' => $businessId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function loadAppToken(string $businessId): ?string
    {
        try {
            $token = $this->tokenService->getAppToken($businessId);
            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $exception) {
            $this->logger->error('WebhookResourceFetcher: failed to load app token', [
                'businessId' => $businessId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function httpGetJson(string $url, string $bearer): ?array
    {
        $attempt = 0;
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];

        while ($attempt < self::MAX_HTTP_ATTEMPTS) {
            ++$attempt;
            try {
                $response = $this->httpClient->request('GET', $url, $options);
            } catch (GuzzleException $exception) {
                $this->logger->error('WebhookResourceFetcher: HTTP request failed', [
                    'url' => $url,
                    'attempt' => $attempt + 1,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }

            $status = $response->getStatusCode();
            if ($status === 200) {
                $body = (string) $response->getBody();
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                $this->logger->error('WebhookResourceFetcher: invalid JSON response', [
                    'url' => $url,
                    'status' => $status,
                    'body' => $body,
                ]);

                return null;
            }

            if ($status === 404) {
                $this->logger->info('WebhookResourceFetcher: resource not found', [
                    'url' => $url,
                ]);

                return null;
            }

            if ($status === 401 || $status === 403) {
                $this->logger->warning('WebhookResourceFetcher: authorization failed', [
                    'url' => $url,
                    'status' => $status,
                ]);

                return null;
            }

            if ($status === 429) {
                $headers = $response->getHeaders();
                $delay = $this->searchRetryAfterDelay($headers) ?? 1;
                $this->logger->warning('WebhookResourceFetcher: rate limited, retrying', [
                    'url' => $url,
                    'delay' => $delay,
                    'attempt' => $attempt,
                ]);
                if ($attempt >= self::MAX_HTTP_ATTEMPTS) {
                    break;
                }
                sleep($delay);
                continue;
            }

            $this->logger->error('WebhookResourceFetcher: unexpected status code', [
                'url' => $url,
                'status' => $status,
            ]);

            return null;
        }

        return null;
    }
}
