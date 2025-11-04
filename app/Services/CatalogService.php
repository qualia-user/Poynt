<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
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
            $availableDiscounts = $this->resolveCatalogAvailableDiscounts($catalogData);
            if (!empty($availableDiscounts)) {
                $this->syncCatalogAvailableDiscounts($catalogId, $availableDiscounts);
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

            FetchResponseLogger::info(
                $this->context->getLog(),
                'CatalogService::fetchByBusinessId response',
                [
                    'businessId' => $businessId,
                    'entity' => 'catalogs',
                    'payload' => $catalogs,
                ]
            );

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

    private function resolveCatalogAvailableDiscounts(array $catalogData): array
    {
        if (isset($catalogData['availableDiscounts']) && is_array($catalogData['availableDiscounts'])) {
            return $catalogData['availableDiscounts'];
        }

        if (isset($catalogData['catalog']) && is_array($catalogData['catalog'])) {
            $innerCatalog = $catalogData['catalog'];
            if (isset($innerCatalog['availableDiscounts']) && is_array($innerCatalog['availableDiscounts'])) {
                return $innerCatalog['availableDiscounts'];
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

            $position = $product['position']
                ?? $product['displayOrder']
                ?? $product['index']
                ?? null;
            $payload = Format::jsonObject($product);
            $createdAtExt = Format::optionalTimestamp(
                $product['createdAt'] ?? $product['createdAtExt'] ?? null
            );
            $updatedAtExt = Format::optionalTimestamp(
                $product['updatedAt'] ?? $product['updatedAtExt'] ?? null
            );

            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO catalog_product (
                        catalog_id,
                        product_id,
                        position,
                        payload,
                        created_at_ext,
                        updated_at_ext,
                        created_at,
                        updated_at
                    ) VALUES (
                        :catalogId,
                        :productId,
                        :position,
                        :payload,
                        :createdAtExt,
                        :updatedAtExt,
                        :createdAt,
                        :updatedAt
                    ) ON CONFLICT (catalog_id, product_id) DO UPDATE SET
                        position = EXCLUDED.position,
                        payload  = EXCLUDED.payload,
                        created_at_ext = EXCLUDED.created_at_ext,
                        updated_at_ext = EXCLUDED.updated_at_ext,
                        updated_at = EXCLUDED.updated_at',
                    [
                        'catalogId' => $catalogId,
                        'productId' => $product['id'],
                        'position'  => $position,
                        'payload'   => $payload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );

                $taxes = [];
                if (isset($product['taxes']) && is_array($product['taxes'])) {
                    $taxes = $product['taxes'];
                }

                if (!empty($taxes)) {
                    $this->syncCatalogProductTaxes($catalogId, $product['id'], $taxes);
                } else {
                    $this->purgeCatalogProductTaxes($catalogId, $product['id']);
                }
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCatalogProducts: failed for catalog %s product %s: %s', $catalogId, $product['id'], $e->getMessage())
                );
            }
        }
    }

    private function syncCatalogProductTaxes(string $catalogId, string $productId, array $taxes): void
    {
        foreach ($taxes as $tax) {
            $taxId = null;
            $payloadSource = $tax;

            if (is_array($tax)) {
                $taxId = $tax['id'] ?? $tax['taxId'] ?? null;
            } elseif (is_string($tax) || is_int($tax)) {
                $taxId = (string) $tax;
                $payloadSource = ['id' => $taxId];
            }

            if ($taxId === null || $taxId === '') {
                continue;
            }

            $payload = Format::jsonObject($payloadSource);
            $createdAtExt = Format::optionalTimestamp(
                is_array($payloadSource) ? ($payloadSource['createdAt'] ?? $payloadSource['createdAtExt'] ?? null) : null
            );
            $updatedAtExt = Format::optionalTimestamp(
                is_array($payloadSource) ? ($payloadSource['updatedAt'] ?? $payloadSource['updatedAtExt'] ?? null) : null
            );
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO catalog_product_tax (
                        catalog_id,
                        product_id,
                        tax_id,
                        payload,
                        created_at_ext,
                        updated_at_ext,
                        created_at,
                        updated_at
                    ) VALUES (
                        :catalogId,
                        :productId,
                        :taxId,
                        :payload,
                        :createdAtExt,
                        :updatedAtExt,
                        :createdAt,
                        :updatedAt
                    ) ON CONFLICT (catalog_id, product_id, tax_id) DO UPDATE SET
                        payload = EXCLUDED.payload,
                        created_at_ext = EXCLUDED.created_at_ext,
                        updated_at_ext = EXCLUDED.updated_at_ext,
                        updated_at = EXCLUDED.updated_at',
                    [
                        'catalogId' => $catalogId,
                        'productId' => $productId,
                        'taxId' => $taxId,
                        'payload' => $payload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'CatalogService::syncCatalogProductTaxes: failed for catalog %s product %s tax %s: %s',
                        $catalogId,
                        $productId,
                        $taxId,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function purgeCatalogProductTaxes(string $catalogId, string $productId): void
    {
        try {
            $this->context->getConn()->executeStatement(
                'DELETE FROM catalog_product_tax WHERE catalog_id = :catalogId AND product_id = :productId',
                [
                    'catalogId' => $catalogId,
                    'productId' => $productId,
                ]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'CatalogService::purgeCatalogProductTaxes: failed for catalog %s product %s: %s',
                    $catalogId,
                    $productId,
                    $e->getMessage()
                )
            );
        }
    }

    private function syncCatalogAvailableDiscounts(string $catalogId, array $availableDiscounts): void
    {
        foreach ($availableDiscounts as $discount) {
            $discountId = null;
            $payloadSource = $discount;

            if (is_array($discount)) {
                $discountId = $discount['id'] ?? $discount['discountId'] ?? null;
            } elseif (is_string($discount) || is_int($discount)) {
                $discountId = (string) $discount;
                $payloadSource = ['id' => $discountId];
            }

            if ($discountId === null || $discountId === '') {
                continue;
            }

            $payload = Format::jsonObject($payloadSource);

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO catalog_available_discount (
                        catalog_id,
                        discount_id,
                        payload
                    ) VALUES (
                        :catalogId,
                        :discountId,
                        :payload
                    ) ON CONFLICT (catalog_id, discount_id) DO UPDATE SET
                        payload  = EXCLUDED.payload',
                    [
                        'catalogId' => $catalogId,
                        'discountId' => $discountId,
                        'payload'   => $payload,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCatalogAvailableDiscounts: failed for catalog %s discount %s: %s', $catalogId, $discountId, $e->getMessage())
                );
            }
        }
    }
}
