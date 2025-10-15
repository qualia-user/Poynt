<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class CatalogService
{
    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    /**
     * Upsert a catalog record.
     *
     * @param array $catalogData
     * @return bool
     */
    public function upsert(array $catalogData): bool
    {
        if (!isset($catalogData['id'], $catalogData['businessId'])) {
            $this->context->getLog()->error(
                'CatalogService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $catalogId = $catalogData['id'];
        $businessId = $catalogData['businessId'];
        $name = $catalogData['name'] ?? $catalogData['displayName'] ?? null;
        $deviceId = $catalogData['deviceId'] ?? null;
        $rawPayload = Format::jsonObject($catalogData);
        $createdAtExt = Format::optionalTimestamp($catalogData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($catalogData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO catalog (
                    catalog_id, business_id, name, device_id, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :catalogId, :businessId, :name, :deviceId, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (catalog_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    name = EXCLUDED.name,
                    device_id = EXCLUDED.device_id,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'catalogId' => $catalogId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'deviceId' => $deviceId,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("CatalogService::upsert: upserted catalog {$catalogId}");
            $products = $this->resolveCatalogProducts($catalogData);
            if (!empty($products)) {
                $this->syncCatalogProducts($catalogId, $products);
            }
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "CatalogService::upsert: database error for catalog_id={$catalogId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch catalogs for a business from the Poynt API.
     *
     * @param string|null $businessId
     * @return array|false
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

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/catalogs', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $catalogs = json_decode($response->getBody(), true);
            if (!is_array($catalogs)) {
                return false;
            }

            foreach ($catalogs as &$catalog) {
                if (!is_array($catalog) || !isset($catalog['id'])) {
                    continue;
                }

                $fullPayload = $this->fetchFullCatalog($businessId, $catalog['id'], $accessToken);
                if ($fullPayload !== null) {
                    $catalog['products'] = $this->extractProducts($fullPayload);
                }
            }

            return $catalogs;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'CatalogService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }

    private function fetchFullCatalog(string $businessId, string $catalogId, string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->get(
                self::POYNT_ENDPOINT . '/' . $businessId . '/catalogs/' . $catalogId . '/full',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                ]
            );

            $payload = json_decode($response->getBody(), true);
            return is_array($payload) ? $payload : null;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('CatalogService::fetchFullCatalog: failed for %s: %s', $catalogId, $e->getMessage())
            );
            return null;
        }
    }

    private function extractProducts(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['products']) && is_array($payload['products'])) {
            return $payload['products'];
        }

        if (isset($payload['catalog']) && is_array($payload['catalog'])) {
            $catalog = $payload['catalog'];
            if (isset($catalog['products']) && is_array($catalog['products'])) {
                return $catalog['products'];
            }
        }

        return [];
    }

    private function resolveCatalogProducts(array $catalogData): array
    {
        if (isset($catalogData['products']) && is_array($catalogData['products'])) {
            return $catalogData['products'];
        }

        if (isset($catalogData['catalog']) && is_array($catalogData['catalog'])) {
            $innerCatalog = $catalogData['catalog'];
            if (isset($innerCatalog['products']) && is_array($innerCatalog['products'])) {
                return $innerCatalog['products'];
            }
        }

        return [];
    }

    private function syncCatalogProducts(string $catalogId, array $products): void
    {
        foreach ($products as $product) {
            if (!is_array($product) || !isset($product['id'])) {
                continue;
            }

            $position = $product['position'] ?? $product['index'] ?? null;
            $payload = Format::jsonObject($product);

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO catalog_product (
                        catalog_id,
                        product_id,
                        position,
                        payload
                    ) VALUES (
                        :catalogId,
                        :productId,
                        :position,
                        :payload
                    ) ON CONFLICT (catalog_id, product_id) DO UPDATE SET
                        position = EXCLUDED.position,
                        payload  = EXCLUDED.payload',
                    [
                        'catalogId' => $catalogId,
                        'productId' => $product['id'],
                        'position'  => $position,
                        'payload'   => $payload,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCatalogProducts: failed for catalog %s product %s: %s', $catalogId, $product['id'], $e->getMessage())
                );
            }
        }
    }
}
