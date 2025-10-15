<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
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

        $storeIds = $this->getStoreIds($businessId);
        if (empty($storeIds)) {
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

        $items = [];
        foreach ($storeIds as $storeId) {
            try {
                $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/inventory', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'storeId' => $storeId,
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $rows = $this->extractInventoryRows($data);
                foreach ($rows as $row) {
                    $row['businessId'] = $businessId;
                    $row['storeId'] = $storeId;
                    $items[] = $row;
                }
            } catch (GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf('InventoryService::fetchByBusinessId: failed for store %s: %s', $storeId, $e->getMessage())
                );
            }
        }

        return $items ?: false;
    }

    private function extractInventoryRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['inventory'])) {
            $data = $data['inventory'];
        }

        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            return $data;
        }

        $rows = [];
        foreach ($data as $value) {
            if (is_array($value)) {
                $rows[] = $value;
            }
        }

        return $rows;
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
        if (!isset($inventoryData['businessId'], $inventoryData['storeId'], $inventoryData['productId'])) {
            $this->context->getLog()->error(
                'InventoryService::upsert: missing required inventory fields (businessId, storeId, productId)'
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
            on_hand       = EXCLUDED.on_hand,
            reserved      = EXCLUDED.reserved,
            updated_at_ext = EXCLUDED.updated_at_ext,
            payload       = EXCLUDED.payload,
            updated_at    = EXCLUDED.updated_at
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'businessId'  => $businessId,
                'storeId'     => $storeId,
                'productId'   => $productId,
                'onHand'      => $onHand,
                'reserved'    => $reserved,
                'updatedAtExt'=> $updatedAtExt,
                'payload'     => $payload,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'InventoryService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
