<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PoyntDataFormatter as Format;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class InventoryService
{
    private const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

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
                sprintf('InventoryService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        $storeIds = $this->getStoreIds($businessId);
        $items = [];

        $summaryRows = $this->fetchSummaryRows($businessId, $accessToken);
        $summaryProductIds = [];

        foreach ($summaryRows as $row) {
            $row['businessId'] = $row['businessId'] ?? $businessId;
            $row['__resourceType'] = 'summary';
            $items[] = $row;

            if (isset($row['productId']) && $row['productId'] !== null && $row['productId'] !== '') {
                $summaryProductIds[] = (string) $row['productId'];
            }
        }

        $productIds = array_values(array_unique($summaryProductIds));
        if (empty($productIds)) {
            $productIds = $this->getProductIds($businessId);
        }

        foreach ($this->fetchInventoryRows($businessId, $accessToken, $storeIds, $productIds) as $row) {
            $row['businessId'] = $businessId;
            $row['__resourceType'] = 'inventory';
            $items[] = $row;
        }

        foreach ($this->fetchVariantRows($businessId, $accessToken, $storeIds) as $row) {
            $row['businessId'] = $businessId;
            $row['__resourceType'] = 'variant';
            $items[] = $row;
        }

        FetchResponseLogger::info(
            $this->context->getLog(),
            'InventoryService::fetchByBusinessId response',
            [
                'businessId' => $businessId,
                'entity' => 'inventory',
                'payload' => $items,
            ]
        );

        return $items ?: false;
    }

    private function fetchInventoryRows(string $businessId, string $accessToken, array $storeIds, array $productIds): array
    {
        $items = [];

        if (empty($storeIds) || empty($productIds)) {
            return $items;
        }

        foreach ($storeIds as $storeId) {
            foreach ($productIds as $productId) {
                if (!$productId) {
                    continue;
                }

                $url = sprintf(
                    '%s/%s/products/%s/inventory/%s',
                    self::POYNT_ENDPOINT,
                    $businessId,
                    rawurlencode((string) $productId),
                    rawurlencode((string) $storeId)
                );

                try {
                    $response = $this->httpClient->get($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                    ]);

                    $payload = json_decode($response->getBody(), true);
                    foreach ($this->normalizeInventoryPayload($payload) as $row) {
                        $row['storeId'] = $storeId;
                        if (!isset($row['productId'])) {
                            $row['productId'] = $productId;
                        }
                        $items[] = $row;
                    }
                } catch (GuzzleException $e) {
                    $this->context->getLog()->error(
                        sprintf(
                            'InventoryService::fetchInventoryRows: failed for store %s, product %s: %s',
                            $storeId,
                            $productId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        return $items;
    }

    private function fetchSummaryRows(string $businessId, string $accessToken): array
    {
        try {
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
            ];

            $ifModifiedSince = $this->getSummaryIfModifiedSinceHeader($businessId);
            if ($ifModifiedSince !== null) {
                $headers['If-Modified-Since'] = $ifModifiedSince;
            }

            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/inventory', [
                'headers' => $headers,
            ]);

            if ($response->getStatusCode() === 304) {
                return [];
            }

            $payload = json_decode($response->getBody(), true);
            return $this->normalizeSummaryPayload($payload);
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'InventoryService::fetchSummaryRows: ' . $e->getMessage()
            );
            return [];
        }
    }

    private function fetchVariantRows(string $businessId, string $accessToken, array $storeIds): array
    {
        $items = [];

        if (empty($storeIds)) {
            return $items;
        }

        $variants = $this->getVariantIdentifiers($businessId);
        if (empty($variants)) {
            return $items;
        }

        foreach ($variants as $variant) {
            $productId = $variant['product_id'] ?? null;
            $variantId = $variant['variant_id'] ?? null;
            $sku = $variant['sku'] ?? null;

            if (!$productId || !$variantId) {
                continue;
            }

            $variantPath = rawurlencode($sku ?: $variantId);

            foreach ($storeIds as $storeId) {
                $url = sprintf(
                    '%s/%s/products/%s/variants/%s/inventory/%s',
                    self::POYNT_ENDPOINT,
                    $businessId,
                    $productId,
                    $variantPath,
                    $storeId
                );

                try {
                    $response = $this->httpClient->get($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                    ]);

                    $payload = json_decode($response->getBody(), true);
                    foreach ($this->normalizeVariantPayload($payload) as $row) {
                        $row['storeId'] = $storeId;
                        $row['productId'] = $row['productId'] ?? $productId;
                        $row['variantId'] = $row['variantId'] ?? $variantId;
                        if ($sku && !isset($row['sku'])) {
                            $row['sku'] = $sku;
                        }
                        $items[] = $row;
                    }
                } catch (GuzzleException $e) {
                    $this->context->getLog()->error(
                        sprintf(
                            'InventoryService::fetchVariantRows: failed for store %s, product %s, variant %s: %s',
                            $storeId,
                            $productId,
                            $variantId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        return $items;
    }

    private function normalizeInventoryPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['inventory']) && is_array($payload['inventory'])) {
            $payload = $payload['inventory'];
        }

        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $rows = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $rows[] = $value;
            }
        }

        return $rows;
    }

    private function normalizeSummaryPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['inventorySummary']) && is_array($payload['inventorySummary'])) {
            $payload = $payload['inventorySummary'];
        }

        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $rows = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $rows[] = $value;
            }
        }

        return $rows;
    }

    private function normalizeVariantPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['variantInventory']) && is_array($payload['variantInventory'])) {
            $payload = $payload['variantInventory'];
        }

        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $rows = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $rows[] = $value;
            }
        }

        if (!empty($rows)) {
            return $rows;
        }

        return [$payload];
    }

    private function getVariantIdentifiers(string $businessId): array
    {
        try {
            $sql = 'SELECT pv.product_id, pv.variant_id, pv.sku '
                . 'FROM product_variant pv '
                . 'INNER JOIN product p ON p.product_id = pv.product_id '
                . 'WHERE p.business_id = ?';

            $rows = $this->context->getConn()->fetchAllAssociative($sql, [$businessId]);

            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::getVariantIdentifiers: ' . $e->getMessage()
            );
        }

        return [];
    }

    private function getProductIds(string $businessId): array
    {
        try {
            $rows = $this->context->getConn()->fetchFirstColumn(
                'SELECT product_id FROM product WHERE business_id = ?',
                [$businessId]
            );

            if (is_array($rows) && !empty($rows)) {
                return array_values(
                    array_filter(
                        array_map(static fn ($value) => (string) $value, $rows),
                        static fn ($value) => $value !== ''
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::getProductIds: ' . $e->getMessage()
            );
        }

        return [];
    }

    private function getSummaryIfModifiedSinceHeader(string $businessId): ?string
    {
        try {
            $value = $this->context->getConn()->fetchOne(
                'SELECT MAX(updated_at_ext) FROM inventory_summary WHERE business_id = ? AND updated_at_ext IS NOT NULL',
                [$businessId]
            );

            if ($value) {
                try {
                    $date = new DateTimeImmutable((string) $value);
                    return $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
                } catch (\Exception $e) {
                    $this->context->getLog()->warning(
                        'InventoryService::getSummaryIfModifiedSinceHeader: unable to parse timestamp: ' . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::getSummaryIfModifiedSinceHeader: ' . $e->getMessage()
            );
        }

        return null;
    }

    private function getStoreIds(string $businessId): array
    {
        try {
            $rows = $this->context->getConn()->fetchFirstColumn(
                'SELECT store_id FROM store WHERE business_id = ?',
                [$businessId]
            );
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::getStoreIds: ' . $e->getMessage()
            );
        }

        $storeService = new StoreService($this->context, $businessId, $this->httpClient);
        $stores = $storeService->fetchByBusinessId($businessId);
        if (!is_array($stores)) {
            return [];
        }

        $ids = [];
        foreach ($stores as $store) {
            if (isset($store['id'])) {
                $ids[] = $store['id'];
                $storeService->upsert($store);
            }
        }

        return $ids;
    }

    public function upsert(array $inventoryData): bool
    {
        $type = $inventoryData['__resourceType'] ?? 'inventory';
        unset($inventoryData['__resourceType']);

        return match ($type) {
            'summary' => $this->upsertSummary($inventoryData),
            'variant' => $this->upsertVariant($inventoryData),
            default   => $this->upsertInventory($inventoryData),
        };
    }

    private function upsertInventory(array $inventoryData): bool
    {
        if (!isset($inventoryData['businessId'], $inventoryData['storeId'], $inventoryData['productId'])) {
            $this->context->getLog()->error(
                'InventoryService::upsertInventory: missing required fields (businessId, storeId, productId)'
            );
            return false;
        }

        $businessId = $inventoryData['businessId'];
        $storeId = $inventoryData['storeId'];
        $productId = $inventoryData['productId'];
        $onHand = Format::optionalNumericString($inventoryData['onHand'] ?? $inventoryData['quantityOnHand'] ?? null);
        $reserved = Format::optionalNumericString($inventoryData['reserved'] ?? $inventoryData['quantityReserved'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($inventoryData['updatedAt'] ?? null);
        $payload = Format::jsonObject($inventoryData);

        $sql = <<<SQL
        INSERT INTO inventory (
            business_id,
            store_id,
            product_id,
            on_hand,
            reserved,
            updated_at_ext,
            payload,
            created_at,
            updated_at
        ) VALUES (
            :businessId,
            :storeId,
            :productId,
            :onHand,
            :reserved,
            :updatedAtExt,
            :payload,
            NOW(),
            NOW()
        ) ON CONFLICT (business_id, store_id, product_id) DO UPDATE SET
            on_hand        = EXCLUDED.on_hand,
            reserved       = EXCLUDED.reserved,
            updated_at_ext = EXCLUDED.updated_at_ext,
            payload        = EXCLUDED.payload,
            updated_at     = EXCLUDED.updated_at
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'businessId'   => $businessId,
                'storeId'      => $storeId,
                'productId'    => $productId,
                'onHand'       => $onHand,
                'reserved'     => $reserved,
                'updatedAtExt' => $updatedAtExt,
                'payload'      => $payload,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::upsertInventory: database error: ' . $e->getMessage()
            );
            return false;
        }
    }

    private function upsertSummary(array $summaryData): bool
    {
        if (!isset($summaryData['businessId'], $summaryData['productId'])) {
            $this->context->getLog()->error(
                'InventoryService::upsertSummary: missing required fields (businessId, productId)'
            );
            return false;
        }

        $businessId = $summaryData['businessId'];
        $productId = $summaryData['productId'];
        $totalOnHand = Format::optionalNumericString($summaryData['totalOnHand'] ?? $summaryData['quantityOnHand'] ?? null);
        $totalReserved = Format::optionalNumericString($summaryData['totalReserved'] ?? $summaryData['quantityReserved'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($summaryData['updatedAt'] ?? null);
        $payload = Format::jsonObject($summaryData);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO inventory_summary (
                    business_id, product_id, total_on_hand, total_reserved, updated_at_ext,
                    payload, created_at, updated_at
                ) VALUES (
                    :businessId, :productId, :totalOnHand, :totalReserved, :updatedAtExt,
                    :payload, :createdAt, :updatedAt
                ) ON CONFLICT (business_id, product_id) DO UPDATE SET
                    total_on_hand = EXCLUDED.total_on_hand,
                    total_reserved = EXCLUDED.total_reserved,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    payload = EXCLUDED.payload,
                    updated_at = EXCLUDED.updated_at',
                [
                    'businessId'   => $businessId,
                    'productId'    => $productId,
                    'totalOnHand'  => $totalOnHand,
                    'totalReserved'=> $totalReserved,
                    'updatedAtExt' => $updatedAtExt,
                    'payload'      => $payload,
                    'createdAt'    => $now,
                    'updatedAt'    => $now,
                ]
            );
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::upsertSummary: database error: ' . $e->getMessage()
            );
            return false;
        }
    }

    private function upsertVariant(array $variantData): bool
    {
        if (!isset($variantData['businessId'], $variantData['storeId'], $variantData['productId'], $variantData['variantId'])) {
            $this->context->getLog()->error(
                'InventoryService::upsertVariant: missing required fields (businessId, storeId, productId, variantId)'
            );
            return false;
        }

        $businessId = $variantData['businessId'];
        $storeId = $variantData['storeId'];
        $productId = $variantData['productId'];
        $variantId = $variantData['variantId'];
        $onHand = Format::optionalNumericString($variantData['onHand'] ?? $variantData['quantityOnHand'] ?? null);
        $reserved = Format::optionalNumericString($variantData['reserved'] ?? $variantData['quantityReserved'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($variantData['updatedAt'] ?? null);
        $payload = Format::jsonObject($variantData);

        $sql = <<<SQL
        INSERT INTO variant_inventory (
            business_id,
            store_id,
            product_id,
            variant_id,
            on_hand,
            reserved,
            updated_at_ext,
            payload,
            created_at,
            updated_at
        ) VALUES (
            :businessId,
            :storeId,
            :productId,
            :variantId,
            :onHand,
            :reserved,
            :updatedAtExt,
            :payload,
            NOW(),
            NOW()
        ) ON CONFLICT (business_id, store_id, product_id, variant_id) DO UPDATE SET
            on_hand        = EXCLUDED.on_hand,
            reserved       = EXCLUDED.reserved,
            updated_at_ext = EXCLUDED.updated_at_ext,
            payload        = EXCLUDED.payload,
            updated_at     = EXCLUDED.updated_at
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'businessId'   => $businessId,
                'storeId'      => $storeId,
                'productId'    => $productId,
                'variantId'    => $variantId,
                'onHand'       => $onHand,
                'reserved'     => $reserved,
                'updatedAtExt' => $updatedAtExt,
                'payload'      => $payload,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::upsertVariant: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
