<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PoyntDataFormatter as Format;
use Doctrine\DBAL\Connection;
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
        $metadataPayload = $this->resolveCatalogMetadata($catalogData);
        $metadata = Format::jsonObject($metadataPayload);
        $createdAtExt = Format::optionalTimestamp($catalogData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($catalogData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO catalog (
                    catalog_id, business_id, name, device_id, metadata, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :catalogId, :businessId, :name, :deviceId, :metadata, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (catalog_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    name = EXCLUDED.name,
                    device_id = EXCLUDED.device_id,
                    metadata = EXCLUDED.metadata,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'catalogId' => $catalogId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'deviceId' => $deviceId,
                    'metadata' => $metadata,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("CatalogService::upsert: upserted catalog {$catalogId}");
            $products = $this->resolveCatalogProducts($catalogData);
            if ($products !== null) {
                $this->syncCatalogProducts($catalogId, $products);
            }
            $availableDiscounts = $this->resolveCatalogAvailableDiscounts($catalogData);
            if (!empty($availableDiscounts)) {
                $this->syncCatalogAvailableDiscounts($catalogId, $availableDiscounts);
            }
            $categories = $this->resolveCatalogCategories($catalogData);
            if ($categories !== null) {
                $this->syncCatalogCategories($catalogId, $businessId, $categories);
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

    private function resolveCatalogMetadata(array $catalogData): mixed
    {
        if (array_key_exists('displayMetadata', $catalogData)) {
            return $catalogData['displayMetadata'];
        }

        if (array_key_exists('metadata', $catalogData)) {
            return $catalogData['metadata'];
        }

        if (isset($catalogData['catalog']) && is_array($catalogData['catalog'])) {
            $inner = $catalogData['catalog'];

            if (array_key_exists('displayMetadata', $inner)) {
                return $inner['displayMetadata'];
            }

            if (array_key_exists('metadata', $inner)) {
                return $inner['metadata'];
            }
        }

        return [];
    }

    private function resolveCatalogProducts(array $catalogData): ?array
    {
        if (array_key_exists('products', $catalogData)) {
            return is_array($catalogData['products']) ? $catalogData['products'] : [];
        }

        if (isset($catalogData['catalog']) && is_array($catalogData['catalog'])) {
            $innerCatalog = $catalogData['catalog'];
            if (array_key_exists('products', $innerCatalog)) {
                return is_array($innerCatalog['products']) ? $innerCatalog['products'] : [];
            }
        }

        return null;
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

    private function resolveCatalogCategories(array $catalogData): ?array
    {
        if (array_key_exists('categories', $catalogData)) {
            return is_array($catalogData['categories']) ? $catalogData['categories'] : [];
        }

        if (isset($catalogData['catalog']) && is_array($catalogData['catalog'])) {
            $innerCatalog = $catalogData['catalog'];
            if (array_key_exists('categories', $innerCatalog)) {
                return is_array($innerCatalog['categories']) ? $innerCatalog['categories'] : [];
            }
        }

        return null;
    }

    private function syncCatalogProducts(string $catalogId, array $products): void
    {
        $seenProductIds = [];

        foreach ($products as $product) {
            $productId = null;
            $payloadSource = $product;
            $position = null;
            $createdAtExt = null;
            $updatedAtExt = null;

            if (is_array($product)) {
                $productId = $product['id'] ?? $product['productId'] ?? null;
                $position = $product['position']
                    ?? $product['displayOrder']
                    ?? $product['index']
                    ?? null;
                $createdAtExt = Format::optionalTimestamp($product['createdAt'] ?? $product['createdAtExt'] ?? null);
                $updatedAtExt = Format::optionalTimestamp($product['updatedAt'] ?? $product['updatedAtExt'] ?? null);
            } elseif (is_string($product) || is_int($product)) {
                $productId = (string) $product;
                $payloadSource = ['id' => $productId];
            }

            if ($productId === null || $productId === '') {
                continue;
            }

            $productId = (string) $productId;
            $seenProductIds[] = $productId;

            $payload = Format::jsonObject($payloadSource);
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
                        'productId' => $productId,
                        'position'  => $position,
                        'payload'   => $payload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCatalogProducts: failed for catalog %s product %s: %s', $catalogId, $productId, $e->getMessage())
                );
            }

            $taxes = [];
            if (is_array($product) && isset($product['taxes']) && is_array($product['taxes'])) {
                $taxes = $product['taxes'];
            }

            if (!empty($taxes)) {
                $this->syncCatalogProductTaxes($catalogId, $productId, $taxes);
            } else {
                $this->purgeCatalogProductTaxes($catalogId, $productId);
            }
        }

        $this->purgeMissingCatalogProducts($catalogId, array_values(array_unique($seenProductIds)));
    }

    private function syncCatalogProductTaxes(string $catalogId, string $productId, array $taxes): void
    {
        $seenTaxIds = [];

        foreach ($taxes as $tax) {
            $taxId = null;
            $payloadSource = $tax;
            $createdAtExt = null;
            $updatedAtExt = null;

            if (is_array($tax)) {
                $taxId = $tax['id'] ?? $tax['taxId'] ?? null;
                $createdAtExt = Format::optionalTimestamp($tax['createdAt'] ?? $tax['createdAtExt'] ?? null);
                $updatedAtExt = Format::optionalTimestamp($tax['updatedAt'] ?? $tax['updatedAtExt'] ?? null);
            } elseif (is_string($tax) || is_int($tax)) {
                $taxId = (string) $tax;
                $payloadSource = ['id' => $taxId];
            }

            if ($taxId === null || $taxId === '') {
                continue;
            }

            $taxId = (string) $taxId;
            $seenTaxIds[] = $taxId;

            $payload = Format::jsonObject($payloadSource);
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

        $this->purgeCatalogProductTaxes($catalogId, $productId, array_values(array_unique($seenTaxIds)));
    }

    private function purgeCatalogProductTaxes(string $catalogId, string $productId, array $keepTaxIds = []): void
    {
        try {
            if (empty($keepTaxIds)) {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM catalog_product_tax WHERE catalog_id = :catalogId AND product_id = :productId',
                    [
                        'catalogId' => $catalogId,
                        'productId' => $productId,
                    ]
                );

                return;
            }

            $this->context->getConn()->executeStatement(
                'DELETE FROM catalog_product_tax WHERE catalog_id = :catalogId AND product_id = :productId AND tax_id NOT IN (:taxIds)',
                [
                    'catalogId' => $catalogId,
                    'productId' => $productId,
                    'taxIds' => $keepTaxIds,
                ],
                [
                    'taxIds' => Connection::PARAM_STR_ARRAY,
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

    private function syncCatalogCategories(string $catalogId, string $businessId, array $categories): void
    {
        $categoryService = new CategoryService($this->context);
        $taxService = new TaxService($this->context);
        $seenCategoryIds = [];

        if (empty($categories)) {
            $this->purgeMissingCategoryRelations($catalogId, []);

            return;
        }

        foreach ($categories as $category) {
            if (!is_array($category) || !isset($category['id'])) {
                continue;
            }

            $categoryId = (string) $category['id'];
            if (!isset($category['businessId'])) {
                $category['businessId'] = $businessId;
            }

            $seenCategoryIds[] = $categoryId;

            if (!$categoryService->upsert($category)) {
                $this->context->getLog()->warning(
                    sprintf('CatalogService::syncCatalogCategories: failed to upsert category %s for catalog %s', $categoryId, $catalogId)
                );
            }

            $categoryProducts = $this->resolveCategoryProducts($category);
            $this->syncCategoryProducts($catalogId, $categoryId, $businessId, $categoryProducts);

            $categoryTaxes = $this->resolveCategoryTaxes($category);
            $this->syncCategoryTaxes($catalogId, $categoryId, $businessId, $categoryTaxes, $taxService);
        }

        $this->purgeMissingCategoryRelations($catalogId, array_values(array_unique($seenCategoryIds)));
    }

    private function resolveCategoryProducts(array $category): array
    {
        if (isset($category['products']) && is_array($category['products'])) {
            return $category['products'];
        }

        return [];
    }

    private function resolveCategoryTaxes(array $category): array
    {
        if (isset($category['taxes']) && is_array($category['taxes'])) {
            return $category['taxes'];
        }

        return [];
    }

    private function syncCategoryProducts(string $catalogId, string $categoryId, string $businessId, array $products): void
    {
        $conn = $this->context->getConn();
        $seenProductIds = [];

        foreach ($products as $product) {
            $productId = null;
            $payloadSource = $product;
            $position = null;
            $createdAtExt = null;
            $updatedAtExt = null;

            if (is_array($product)) {
                $productId = $product['id'] ?? $product['productId'] ?? null;
                $position = $product['position']
                    ?? $product['displayOrder']
                    ?? $product['index']
                    ?? null;
                $createdAtExt = Format::optionalTimestamp($product['createdAt'] ?? $product['createdAtExt'] ?? null);
                $updatedAtExt = Format::optionalTimestamp($product['updatedAt'] ?? $product['updatedAtExt'] ?? null);
            } elseif (is_string($product) || is_int($product)) {
                $productId = (string) $product;
                $payloadSource = ['id' => $productId];
            }

            if ($productId === null || $productId === '') {
                continue;
            }

            $productId = (string) $productId;
            $seenProductIds[] = $productId;
            $payload = Format::jsonObject($payloadSource);
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

            try {
                $conn->executeStatement(
                    'INSERT INTO category_product (
                        catalog_id,
                        category_id,
                        product_id,
                        business_id,
                        position,
                        payload,
                        created_at_ext,
                        updated_at_ext,
                        created_at,
                        updated_at
                    ) VALUES (
                        :catalogId,
                        :categoryId,
                        :productId,
                        :businessId,
                        :position,
                        :payload,
                        :createdAtExt,
                        :updatedAtExt,
                        :createdAt,
                        :updatedAt
                    ) ON CONFLICT (catalog_id, category_id, product_id) DO UPDATE SET
                        position = EXCLUDED.position,
                        payload = EXCLUDED.payload,
                        created_at_ext = EXCLUDED.created_at_ext,
                        updated_at_ext = EXCLUDED.updated_at_ext,
                        updated_at = EXCLUDED.updated_at',
                    [
                        'catalogId' => $catalogId,
                        'categoryId' => $categoryId,
                        'productId' => $productId,
                        'businessId' => $businessId,
                        'position' => $position,
                        'payload' => $payload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCategoryProducts: failed for catalog %s category %s product %s: %s', $catalogId, $categoryId, $productId, $e->getMessage())
                );
            }
        }

        $this->purgeMissingCategoryProducts($catalogId, $categoryId, array_values(array_unique($seenProductIds)));
    }

    private function syncCategoryTaxes(string $catalogId, string $categoryId, string $businessId, array $taxes, TaxService $taxService): void
    {
        $conn = $this->context->getConn();
        $seenTaxIds = [];

        foreach ($taxes as $tax) {
            $taxId = null;
            $payloadSource = $tax;
            $createdAtExt = null;
            $updatedAtExt = null;

            if (is_array($tax)) {
                $taxId = $tax['id'] ?? $tax['taxId'] ?? null;
                if (!isset($tax['businessId'])) {
                    $tax['businessId'] = $businessId;
                }
                $payloadSource = $tax;
                $createdAtExt = Format::optionalTimestamp($tax['createdAt'] ?? $tax['createdAtExt'] ?? null);
                $updatedAtExt = Format::optionalTimestamp($tax['updatedAt'] ?? $tax['updatedAtExt'] ?? null);
            } elseif (is_string($tax) || is_int($tax)) {
                $taxId = (string) $tax;
                $payloadSource = ['id' => $taxId, 'businessId' => $businessId];
            }

            if ($taxId === null || $taxId === '') {
                continue;
            }

            $taxId = (string) $taxId;
            $seenTaxIds[] = $taxId;

            if (is_array($tax)) {
                $taxService->upsert($tax);
            }

            $payload = Format::jsonObject($payloadSource);
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

            try {
                $conn->executeStatement(
                    'INSERT INTO category_tax (
                        catalog_id,
                        category_id,
                        tax_id,
                        business_id,
                        payload,
                        created_at_ext,
                        updated_at_ext,
                        created_at,
                        updated_at
                    ) VALUES (
                        :catalogId,
                        :categoryId,
                        :taxId,
                        :businessId,
                        :payload,
                        :createdAtExt,
                        :updatedAtExt,
                        :createdAt,
                        :updatedAt
                    ) ON CONFLICT (catalog_id, category_id, tax_id) DO UPDATE SET
                        payload = EXCLUDED.payload,
                        created_at_ext = EXCLUDED.created_at_ext,
                        updated_at_ext = EXCLUDED.updated_at_ext,
                        updated_at = EXCLUDED.updated_at',
                    [
                        'catalogId' => $catalogId,
                        'categoryId' => $categoryId,
                        'taxId' => $taxId,
                        'businessId' => $businessId,
                        'payload' => $payload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogService::syncCategoryTaxes: failed for catalog %s category %s tax %s: %s', $catalogId, $categoryId, $taxId, $e->getMessage())
                );
            }
        }

        $this->purgeMissingCategoryTaxes($catalogId, $categoryId, array_values(array_unique($seenTaxIds)));
    }

    private function purgeMissingCatalogProducts(string $catalogId, array $productIds): void
    {
        try {
            if (empty($productIds)) {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM catalog_product WHERE catalog_id = :catalogId',
                    ['catalogId' => $catalogId]
                );
                $this->context->getConn()->executeStatement(
                    'DELETE FROM catalog_product_tax WHERE catalog_id = :catalogId',
                    ['catalogId' => $catalogId]
                );

                return;
            }

            $this->context->getConn()->executeStatement(
                'DELETE FROM catalog_product WHERE catalog_id = :catalogId AND product_id NOT IN (:productIds)',
                [
                    'catalogId' => $catalogId,
                    'productIds' => $productIds,
                ],
                [
                    'productIds' => Connection::PARAM_STR_ARRAY,
                ]
            );

            $this->context->getConn()->executeStatement(
                'DELETE FROM catalog_product_tax WHERE catalog_id = :catalogId AND product_id NOT IN (:productIds)',
                [
                    'catalogId' => $catalogId,
                    'productIds' => $productIds,
                ],
                [
                    'productIds' => Connection::PARAM_STR_ARRAY,
                ]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('CatalogService::purgeMissingCatalogProducts: failed for catalog %s: %s', $catalogId, $e->getMessage())
            );
        }
    }

    private function purgeMissingCategoryProducts(string $catalogId, string $categoryId, array $productIds): void
    {
        try {
            if (empty($productIds)) {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM category_product WHERE catalog_id = :catalogId AND category_id = :categoryId',
                    [
                        'catalogId' => $catalogId,
                        'categoryId' => $categoryId,
                    ]
                );

                return;
            }

            $this->context->getConn()->executeStatement(
                'DELETE FROM category_product WHERE catalog_id = :catalogId AND category_id = :categoryId AND product_id NOT IN (:productIds)',
                [
                    'catalogId' => $catalogId,
                    'categoryId' => $categoryId,
                    'productIds' => $productIds,
                ],
                [
                    'productIds' => Connection::PARAM_STR_ARRAY,
                ]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('CatalogService::purgeMissingCategoryProducts: failed for catalog %s category %s: %s', $catalogId, $categoryId, $e->getMessage())
            );
        }
    }

    private function purgeMissingCategoryTaxes(string $catalogId, string $categoryId, array $taxIds): void
    {
        try {
            if (empty($taxIds)) {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM category_tax WHERE catalog_id = :catalogId AND category_id = :categoryId',
                    [
                        'catalogId' => $catalogId,
                        'categoryId' => $categoryId,
                    ]
                );

                return;
            }

            $this->context->getConn()->executeStatement(
                'DELETE FROM category_tax WHERE catalog_id = :catalogId AND category_id = :categoryId AND tax_id NOT IN (:taxIds)',
                [
                    'catalogId' => $catalogId,
                    'categoryId' => $categoryId,
                    'taxIds' => $taxIds,
                ],
                [
                    'taxIds' => Connection::PARAM_STR_ARRAY,
                ]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('CatalogService::purgeMissingCategoryTaxes: failed for catalog %s category %s: %s', $catalogId, $categoryId, $e->getMessage())
            );
        }
    }

    private function purgeMissingCategoryRelations(string $catalogId, array $categoryIds): void
    {
        try {
            if (empty($categoryIds)) {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM category_product WHERE catalog_id = :catalogId',
                    ['catalogId' => $catalogId]
                );
                $this->context->getConn()->executeStatement(
                    'DELETE FROM category_tax WHERE catalog_id = :catalogId',
                    ['catalogId' => $catalogId]
                );

                return;
            }

            $this->context->getConn()->executeStatement(
                'DELETE FROM category_product WHERE catalog_id = :catalogId AND category_id NOT IN (:categoryIds)',
                [
                    'catalogId' => $catalogId,
                    'categoryIds' => $categoryIds,
                ],
                [
                    'categoryIds' => Connection::PARAM_STR_ARRAY,
                ]
            );

            $this->context->getConn()->executeStatement(
                'DELETE FROM category_tax WHERE catalog_id = :catalogId AND category_id NOT IN (:categoryIds)',
                [
                    'catalogId' => $catalogId,
                    'categoryIds' => $categoryIds,
                ],
                [
                    'categoryIds' => Connection::PARAM_STR_ARRAY,
                ]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('CatalogService::purgeMissingCategoryRelations: failed for catalog %s: %s', $catalogId, $e->getMessage())
            );
        }
    }
}
