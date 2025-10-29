<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

class PaylinkService
{
    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    private const PAYLINK_STORES_ENDPOINT = 'https://poynt.godaddy.com/api/v2/stores';

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    /**
     * Upsert a paylink record.
     *
     * @param array $paylinkData
     * @return bool
     */
    public function upsert(array $paylinkData): bool
    {
        if (!isset($paylinkData['id'], $paylinkData['businessId'])) {
            $this->context->getLog()->error(
                'PaylinkService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $paylinkId = $paylinkData['id'];
        $businessId = $paylinkData['businessId'];

        $url = Format::stringOrNull($paylinkData['url'] ?? $paylinkData['href'] ?? null);
        $vanityUrl = Format::stringOrNull($paylinkData['vanityUrl'] ?? $paylinkData['vanityURL'] ?? null);
        $domain = $paylinkData['domain'] ?? $vanityUrl;
        if (!$domain && $url) {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $domain = $parsedHost;
            }
        }
        $domain = $domain !== null ? (string) $domain : null;

        $title = Format::stringOrNull($paylinkData['title'] ?? $paylinkData['name'] ?? null);
        $description = Format::stringOrNull($paylinkData['description'] ?? null);
        $status = $paylinkData['status'] ?? null;
        $amountMinor = Format::amount($paylinkData['amount'] ?? null);
        if ($amountMinor === null && isset($paylinkData['amount']['amount'])) {
            $amountMinor = Format::amount($paylinkData['amount']['amount']);
        }
        $currency = $paylinkData['amount']['currency'] ?? $paylinkData['currency'] ?? null;
        $metadata = Format::jsonObject($paylinkData['metadata'] ?? []);
        $expiresAtExt = Format::optionalTimestamp(
            $paylinkData['expiresAt']
                ?? $paylinkData['expireAt']
                ?? $paylinkData['expiration']
                ?? $paylinkData['expirationTime']
                ?? $paylinkData['expirationDate']
                ?? null
        );
        $createdAtExt = Format::optionalTimestamp($paylinkData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($paylinkData['updatedAt'] ?? null);
        $rawPayload = Format::jsonObject($paylinkData);

        $items = $this->extractItems($paylinkData);
        $payments = $this->extractPayments($paylinkData);
        $links = $this->extractLinks($paylinkData);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO paylink (
                    paylink_id, business_id, url, vanity_url, domain, title, description,
                    status, amount_minor, currency,
                    metadata, expires_at_ext, created_at_ext, updated_at_ext, raw_payload,
                    created_at, updated_at
                ) VALUES (
                    :paylinkId, :businessId, :url, :vanityUrl, :domain, :title, :description,
                    :status, :amountMinor, :currency,
                    :metadata, :expiresAtExt, :createdAtExt, :updatedAtExt, :rawPayload,
                    :createdAt, :updatedAt
                ) ON CONFLICT (paylink_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    url = EXCLUDED.url,
                    vanity_url = EXCLUDED.vanity_url,
                    domain = EXCLUDED.domain,
                    title = EXCLUDED.title,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status,
                    amount_minor = EXCLUDED.amount_minor,
                    currency = EXCLUDED.currency,
                    metadata = EXCLUDED.metadata,
                    expires_at_ext = EXCLUDED.expires_at_ext,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    raw_payload = EXCLUDED.raw_payload,
                    updated_at = EXCLUDED.updated_at',
                [
                    'paylinkId' => $paylinkId,
                    'businessId' => $businessId,
                    'url' => $url,
                    'vanityUrl' => $vanityUrl,
                    'domain' => $domain,
                    'title' => $title,
                    'description' => $description,
                    'status' => $status,
                    'amountMinor' => $amountMinor,
                    'currency' => $currency,
                    'metadata' => $metadata,
                    'expiresAtExt' => $expiresAtExt,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'rawPayload' => $rawPayload,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("PaylinkService::upsert: upserted paylink {$paylinkId}");
            $this->syncItems($paylinkId, $businessId, $items);
            $this->syncPayments($paylinkId, $businessId, $payments);
            $this->syncLinks($paylinkId, $businessId, $links);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "PaylinkService::upsert: database error for paylink_id={$paylinkId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractItems(array $paylinkData): array
    {
        $items = [];

        $candidates = [
            $paylinkData['items'] ?? null,
            $paylinkData['lineItems'] ?? null,
            $paylinkData['order']['items'] ?? null,
            $paylinkData['order']['lineItems'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (array_is_list($candidate)) {
                foreach ($candidate as $value) {
                    if (is_array($value)) {
                        $items[] = $value;
                    }
                }
                continue;
            }

            foreach ($candidate as $value) {
                if (is_array($value)) {
                    $items[] = $value;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractPayments(array $paylinkData): array
    {
        $payments = [];

        $candidates = [
            $paylinkData['payments'] ?? null,
            $paylinkData['transactions'] ?? null,
            $paylinkData['order']['payments'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (array_is_list($candidate)) {
                foreach ($candidate as $value) {
                    if (is_array($value)) {
                        $payments[] = $value;
                    }
                }
                continue;
            }

            foreach ($candidate as $value) {
                if (is_array($value)) {
                    $payments[] = $value;
                }
            }
        }

        return $payments;
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractLinks(array $paylinkData): array
    {
        $links = $paylinkData['links'] ?? $paylinkData['_links'] ?? null;

        if (!is_array($links)) {
            return [];
        }

        if (array_is_list($links)) {
            $normalized = [];
            foreach ($links as $link) {
                if (is_array($link)) {
                    $normalized[] = $link;
                }
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($links as $key => $value) {
            if (is_array($value)) {
                if (!isset($value['rel']) && is_string($key)) {
                    $value['rel'] = $key;
                }
                $normalized[] = $value;
                continue;
            }

            if (is_string($value) && is_string($key)) {
                $normalized[] = [
                    'rel' => $key,
                    'href' => $value,
                ];
            }
        }

        return $normalized;
    }

    private function syncItems(string $paylinkId, string $businessId, array $items): void
    {
        $conn = $this->context->getConn();

        try {
            $conn->executeStatement('DELETE FROM paylink_item WHERE paylink_id = ?', [$paylinkId]);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('PaylinkService::syncItems: failed to prune items for %s: %s', $paylinkId, $e->getMessage())
            );
            return;
        }

        if (empty($items)) {
            return;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($items as $index => $item) {
            $itemRef = sprintf('item-%d', $index + 1);
            $itemId = $item['id'] ?? $item['itemId'] ?? $item['uuid'] ?? null;
            $name = Format::stringOrNull($item['name'] ?? $item['title'] ?? null);
            $description = Format::stringOrNull($item['description'] ?? null);

            $amountCandidates = [
                $item['amount'] ?? null,
                $item['unitPrice'] ?? null,
                $item['price'] ?? null,
                $item['total'] ?? null,
            ];
            $amountMinor = null;
            foreach ($amountCandidates as $candidate) {
                $amountMinor = Format::amount($candidate);
                if ($amountMinor !== null) {
                    break;
                }
            }

            $currencyCandidates = [
                $item['amount']['currency'] ?? null,
                $item['unitPrice']['currency'] ?? null,
                $item['price']['currency'] ?? null,
                $item['currency'] ?? null,
            ];
            $currency = null;
            foreach ($currencyCandidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $currency = $candidate;
                    break;
                }
            }

            $quantityCandidate = $item['quantity'] ?? $item['qty'] ?? $item['quantityOrdered'] ?? null;
            $quantity = Format::optionalNumericString($quantityCandidate);

            $metadataPayload = $item['metadata'] ?? $item['attributes'] ?? [];
            $metadata = Format::jsonObject($metadataPayload);
            $payload = Format::jsonObject($item);

            try {
                $conn->executeStatement(
                    'INSERT INTO paylink_item (
                        paylink_id, business_id, item_ref, item_id, name, description,
                        amount_minor, currency, quantity, metadata, payload,
                        created_at, updated_at
                    ) VALUES (
                        :paylinkId, :businessId, :itemRef, :itemId, :name, :description,
                        :amountMinor, :currency, :quantity, :metadata, :payload,
                        :createdAt, :updatedAt
                    )',
                    [
                        'paylinkId' => $paylinkId,
                        'businessId' => $businessId,
                        'itemRef' => $itemRef,
                        'itemId' => $itemId,
                        'name' => $name,
                        'description' => $description,
                        'amountMinor' => $amountMinor,
                        'currency' => $currency,
                        'quantity' => $quantity,
                        'metadata' => $metadata,
                        'payload' => $payload,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('PaylinkService::syncItems: failed to upsert item %s for %s: %s', $itemRef, $paylinkId, $e->getMessage())
                );
            }
        }
    }

    private function syncPayments(string $paylinkId, string $businessId, array $payments): void
    {
        $conn = $this->context->getConn();

        try {
            $conn->executeStatement('DELETE FROM paylink_payment WHERE paylink_id = ?', [$paylinkId]);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('PaylinkService::syncPayments: failed to prune payments for %s: %s', $paylinkId, $e->getMessage())
            );
            return;
        }

        if (empty($payments)) {
            return;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($payments as $index => $payment) {
            $paymentId = $payment['id'] ?? $payment['paymentId'] ?? $payment['transactionId'] ?? null;
            $paymentRef = $paymentId ?? sprintf('payment-%d', $index + 1);
            $status = $payment['status'] ?? $payment['paymentStatus'] ?? $payment['state'] ?? null;

            $amountCandidates = [
                $payment['amount'] ?? null,
                $payment['totalAmount'] ?? null,
                $payment['netAmount'] ?? null,
                $payment['transactionAmount'] ?? null,
            ];
            $amountMinor = null;
            foreach ($amountCandidates as $candidate) {
                $amountMinor = Format::amount($candidate);
                if ($amountMinor !== null) {
                    break;
                }
            }

            $currencyCandidates = [
                $payment['amount']['currency'] ?? null,
                $payment['transactionAmount']['currency'] ?? null,
                $payment['totalAmount']['currency'] ?? null,
                $payment['currency'] ?? null,
            ];
            $currency = null;
            foreach ($currencyCandidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $currency = $candidate;
                    break;
                }
            }

            $processedAtExt = Format::optionalTimestamp(
                $payment['processedAt']
                    ?? $payment['createdAt']
                    ?? $payment['updatedAt']
                    ?? $payment['capturedAt']
                    ?? null
            );

            $payload = Format::jsonObject($payment);

            try {
                $conn->executeStatement(
                    'INSERT INTO paylink_payment (
                        paylink_id, business_id, payment_ref, payment_id, status,
                        amount_minor, currency, processed_at_ext, payload,
                        created_at, updated_at
                    ) VALUES (
                        :paylinkId, :businessId, :paymentRef, :paymentId, :status,
                        :amountMinor, :currency, :processedAtExt, :payload,
                        :createdAt, :updatedAt
                    )',
                    [
                        'paylinkId' => $paylinkId,
                        'businessId' => $businessId,
                        'paymentRef' => $paymentRef,
                        'paymentId' => $paymentId,
                        'status' => $status,
                        'amountMinor' => $amountMinor,
                        'currency' => $currency,
                        'processedAtExt' => $processedAtExt,
                        'payload' => $payload,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('PaylinkService::syncPayments: failed to upsert payment %s for %s: %s', $paymentRef, $paylinkId, $e->getMessage())
                );
            }
        }
    }

    private function syncLinks(string $paylinkId, string $businessId, array $links): void
    {
        $conn = $this->context->getConn();

        try {
            $conn->executeStatement('DELETE FROM paylink_link WHERE paylink_id = ?', [$paylinkId]);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('PaylinkService::syncLinks: failed to prune links for %s: %s', $paylinkId, $e->getMessage())
            );
            return;
        }

        if (empty($links)) {
            return;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($links as $index => $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = Format::stringOrNull($link['rel'] ?? $link['relationship'] ?? null);
            $href = Format::stringOrNull($link['href'] ?? $link['url'] ?? null);
            $method = Format::stringOrNull($link['method'] ?? $link['httpMethod'] ?? null);
            $linkRef = sprintf('link-%d', $index + 1);
            if ($rel && $href) {
                $linkRef = $rel . '-' . substr(md5($href), 0, 10);
            }

            $payload = Format::jsonObject($link);

            try {
                $conn->executeStatement(
                    'INSERT INTO paylink_link (
                        paylink_id, business_id, link_ref, rel, href, method, payload,
                        created_at, updated_at
                    ) VALUES (
                        :paylinkId, :businessId, :linkRef, :rel, :href, :method, :payload,
                        :createdAt, :updatedAt
                    )',
                    [
                        'paylinkId' => $paylinkId,
                        'businessId' => $businessId,
                        'linkRef' => $linkRef,
                        'rel' => $rel,
                        'href' => $href,
                        'method' => $method,
                        'payload' => $payload,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('PaylinkService::syncLinks: failed to upsert link %s for %s: %s', $linkRef, $paylinkId, $e->getMessage())
                );
            }
        }
    }

    /**
     * Fetch paylink datasets for each store that belongs to the given business.
     *
     * @param string|null $businessId
     * @return array<int, array{
     *     storeId: string,
     *     store: array<mixed>,
     *     recentSales: array<mixed>,
     *     totalSales: array<mixed>,
     *     all: array<mixed>
     * }>|false
     */
    public function fetchByBusinessId(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }
        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);
        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf('PaylinkService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );

            return false;
        }

        $storeService = new StoreService($this->context, $businessId, $this->httpClient);
        $stores = $storeService->fetchByBusinessId($businessId);
        if (!is_array($stores) || empty($stores)) {
            $this->context->getLog()->info(
                sprintf('PaylinkService::fetchByBusinessId: no stores found for business %s', $businessId)
            );

            $this->context->getLog()->info(
                'PaylinkService::fetchByBusinessId response',
                [
                    'businessId' => $businessId,
                    'entity' => 'paylinks',
                    'payload' => [],
                ]
            );

            return [];
        }

        $results = [];
        foreach ($stores as $store) {
            if (!is_array($store) || empty($store['id'])) {
                continue;
            }

            $storeId = (string) $store['id'];
            $results[] = [
                'storeId' => $storeId,
                'store' => $store,
                'recentSales' => $this->requestPaylinkCollection($storeId, $accessToken, 'recentSales'),
                'totalSales' => $this->requestPaylinkCollection($storeId, $accessToken, 'totalSales'),
                'all' => $this->requestPaylinkCollection($storeId, $accessToken, 'all'),
            ];
        }

        $this->context->getLog()->info(
            'PaylinkService::fetchByBusinessId response',
            [
                'businessId' => $businessId,
                'entity' => 'paylinks',
                'payload' => $results,
            ]
        );

        return $results;
    }

    /**
     * @param string $storeId
     * @param string $accessToken
     * @param string $collection One of "recentSales", "totalSales" or "all".
     *
     * @return array<mixed>
     */
    private function requestPaylinkCollection(string $storeId, string $accessToken, string $collection): array
    {
        $url = sprintf('%s/%s/payLinks/%s', self::PAYLINK_STORES_ENDPOINT, $storeId, $collection);

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return is_array($data) ? $data : [];
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            if ($response !== null && $response->getStatusCode() === 404) {
                $this->context->getLog()->info(
                    sprintf(
                        'PaylinkService::requestPaylinkCollection: GET %s returned 404, treating as empty dataset',
                        parse_url($url, PHP_URL_PATH) ?? $url
                    )
                );

                return [];
            }

            $this->context->getLog()->error(
                sprintf('PaylinkService::requestPaylinkCollection: %s', $e->getMessage())
            );
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('PaylinkService::requestPaylinkCollection: %s', $e->getMessage())
            );
        }

        return [];
    }
}
