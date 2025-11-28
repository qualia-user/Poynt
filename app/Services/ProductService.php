<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PaginatedRequest;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class ProductService
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
     * Upsert product and related variants.
     *
     * @param array $productData
     * @return bool
     */
    public function upsert(array $productData): bool
    {
        if (!isset($productData['id'], $productData['businessId'])) {
            $this->context->getLog()->error(
                'ProductService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $productId = $productData['id'];
        $businessId = $productData['businessId'];
        $name = $productData['name'] ?? null;
        $sku = $productData['sku'] ?? null;
        $priceMinor = Format::amount($productData['price'] ?? ($productData['priceAmount'] ?? null));
        if ($priceMinor === null && isset($productData['price']['amount'])) {
            $priceMinor = Format::amount($productData['price']['amount']);
        }
        $currency = $productData['price']['currency'] ?? $productData['currency'] ?? null;
        $categoryId = $productData['categoryId'] ?? null;
        $isActive = Format::optionalBool($productData['isActive'] ?? $productData['active'] ?? null);
        $attributes = Format::jsonObject($productData['attributes'] ?? $productData['attributeSets'] ?? []);
        $rawPayload = Format::jsonObject($productData);
        $createdAtExt = Format::optionalTimestamp($productData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($productData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO product (
                    product_id, business_id, name, sku, price_minor, currency,
                    category_id, is_active, attributes, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :productId, :businessId, :name, :sku, :priceMinor, :currency,
                    :categoryId, :isActive, :attributes, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (product_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    name = EXCLUDED.name,
                    sku = EXCLUDED.sku,
                    price_minor = EXCLUDED.price_minor,
                    currency = EXCLUDED.currency,
                    category_id = EXCLUDED.category_id,
                    is_active = EXCLUDED.is_active,
                    attributes = EXCLUDED.attributes,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'productId' => $productId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'sku' => $sku,
                    'priceMinor' => $priceMinor,
                    'currency' => $currency,
                    'categoryId' => $categoryId,
                    'isActive' => $isActive,
                    'attributes' => $attributes,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $variants = $this->prepareVariants($productData);
            $this->upsertVariants($productId, $variants);

            $this->context->getLog()->info("ProductService::upsert: upserted product {$productId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "ProductService::upsert: database error for product_id={$productId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Upsert product variants.
     *
     * @param string $productId
     * @param array $variants
     * @return void
     */
    public function upsertVariants(string $productId, array $variants): void
    {
        try {
            $existingVariantIds = $this->context->getConn()->fetchFirstColumn(
                'SELECT variant_id FROM product_variant WHERE product_id = ?',
                [$productId]
            );
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'ProductService::upsertVariants: failed to fetch existing variants for product_id=%s: %s',
                    $productId,
                    $e->getMessage()
                )
            );
            $existingVariantIds = [];
        }

        $existingVariantIds = array_values(
            array_unique(
                array_map(
                    static fn ($value): string => (string) $value,
                    array_filter(
                        is_array($existingVariantIds) ? $existingVariantIds : [],
                        static fn ($value): bool => $value !== null && $value !== ''
                    )
                )
            )
        );

        $upsertedIds = [];

        if (!empty($variants)) {
            $sql = 'INSERT INTO product_variant (
                    product_id, variant_id, name, sku, price_minor,
                    attributes, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :productId, :variantId, :name, :sku, :priceMinor,
                    :attributes, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (product_id, variant_id) DO UPDATE SET
                    name = EXCLUDED.name,
                    sku = EXCLUDED.sku,
                    price_minor = EXCLUDED.price_minor,
                    attributes = EXCLUDED.attributes,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at';

            $stmt = $this->context->getConn()->prepare($sql);
            $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $variantId = $variant['id'] ?? $variant['variantId'] ?? null;
                if (!$variantId) {
                    $this->context->getLog()->error('ProductService::upsertVariants: missing variant id');
                    continue;
                }

                $upsertedIds[] = (string) $variantId;

                $name = $variant['name'] ?? null;
                $sku = $variant['sku'] ?? null;
                $priceMinor = Format::amount($variant['price'] ?? ($variant['priceAmount'] ?? null));
                if ($priceMinor === null && isset($variant['price']['amount'])) {
                    $priceMinor = Format::amount($variant['price']['amount']);
                }

                if ($priceMinor === null && isset($variant['priceDelta'])) {
                    $priceMinor = Format::amount($variant['priceDelta']);
                }

                $attributesSource = $variant['attributes'] ?? ($variant['selectableVariations'] ?? []);
                $attributes = Format::jsonObject($attributesSource);
                $rawPayload = Format::jsonObject($variant);
                $createdAtExt = Format::optionalTimestamp($variant['createdAt'] ?? null);
                $updatedAtExt = Format::optionalTimestamp($variant['updatedAt'] ?? null);

                try {
                    $stmt->executeStatement([
                        'productId' => $productId,
                        'variantId' => $variantId,
                        'name' => $name,
                        'sku' => $sku,
                        'priceMinor' => $priceMinor,
                        'attributes' => $attributes,
                        'rawPayload' => $rawPayload,
                        'createdAtExt' => $createdAtExt,
                        'updatedAtExt' => $updatedAtExt,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]);
                } catch (\Throwable $e) {
                    $this->context->getLog()->error(
                        sprintf(
                            'ProductService::upsertVariants: database error for product_id=%s, variant_id=%s: %s',
                            $productId,
                            $variantId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        $upsertedIds = array_values(array_unique($upsertedIds));
        $toDelete = array_diff($existingVariantIds, $upsertedIds);

        foreach ($toDelete as $variantId) {
            try {
                $this->context->getConn()->executeStatement(
                    'DELETE FROM product_variant WHERE product_id = :productId AND variant_id = :variantId',
                    [
                        'productId' => $productId,
                        'variantId' => $variantId,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'ProductService::upsertVariants: failed to delete variant_id=%s for product_id=%s: %s',
                        $variantId,
                        $productId,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function prepareVariants(array $productData): array
    {
        $variants = $productData['variants'] ?? [];

        if (!empty($variants)) {
            return $variants;
        }

        $selectableVariants = $productData['selectableVariants'] ?? null;
        if (!is_array($selectableVariants) || empty($selectableVariants)) {
            return [];
        }

        $prepared = [];
        $productId = (string) ($productData['id'] ?? '');

        foreach ($selectableVariants as $selectableVariant) {
            if (!is_array($selectableVariant)) {
                continue;
            }

            $attributes = $selectableVariant['attributes'] ?? ($selectableVariant['selectableVariations'] ?? []);
            $variantId = $selectableVariant['id']
                ?? $selectableVariant['variantId']
                ?? $this->generateVariantId(
                    $productId,
                    (string) ($selectableVariant['sku'] ?? ''),
                    $attributes
                );

            $selectableVariant['id'] = $variantId;
            if (!isset($selectableVariant['attributes']) && isset($selectableVariant['selectableVariations'])) {
                $selectableVariant['attributes'] = $selectableVariant['selectableVariations'];
            }

            $prepared[] = $selectableVariant;
        }

        return $prepared;
    }

    private function generateVariantId(string $productId, string $sku, mixed $attributes): string
    {
        $payload = [$productId, $sku];

        if (is_array($attributes) || is_object($attributes)) {
            $encoded = json_encode($attributes);
            if ($encoded !== false) {
                $payload[] = $encoded;
            }
        } elseif ($attributes !== null) {
            $payload[] = (string) $attributes;
        }

        $hashSource = implode('|', $payload);

        return substr(hash('sha256', $hashSource), 0, 32);
    }

    /**
     * Fetch a single product by its identifier.
     *
     * @param string $productId
     * @param string|null $businessId
     * @return array|null
     */
    public function fetchById(string $productId, ?string $businessId = null): ?array
    {
        $businessId = $businessId ?? $this->businessId;
        if (!$businessId) {
            $this->context->getLog()->warning(
                sprintf('ProductService::fetchById: missing businessId for product_id=%s', $productId)
            );

            return null;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf(
                    'ProductService::fetchById: missing merchant token for business_id=%s, product_id=%s',
                    $businessId,
                    $productId
                )
            );

            return null;
        }

        $url = sprintf('%s/%s/products/%s', self::POYNT_ENDPOINT, $businessId, $productId);

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                return null;
            }

            FetchResponseLogger::info(
                $this->context->getLog(),
                'ProductService::fetchById response',
                [
                    'businessId' => $businessId,
                    'productId' => $productId,
                    'entity' => 'product',
                    'payload' => $data,
                ]
            );

            return $data;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf(
                    'ProductService::fetchById: failed for business_id=%s, product_id=%s: %s',
                    $businessId,
                    $productId,
                    $e->getMessage()
                )
            );

            return null;
        }
    }

    /**
     * Fetch products for a business from the Poynt API.
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
            $url = self::POYNT_ENDPOINT . '/' . $businessId . '/products';
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ];

            $response = $this->httpClient->get($url, $requestOptions);

            $data = json_decode($response->getBody(), true);
            if (!is_array($data)) {
                return false;
            }

            $data = PaginatedRequest::collect($this->httpClient, $data, $url, $requestOptions, 'products');

            FetchResponseLogger::info(
                $this->context->getLog(),
                'ProductService::fetchByBusinessId response',
                [
                    'businessId' => $businessId,
                    'entity' => 'products',
                    'payload' => $data,
                ]
            );

            return $data;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'ProductService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }

    public function delete(string $id, ?string $businessId = null): bool
    {
        $conn = $this->context->getConn();

        try {
            $conn->beginTransaction();

            $conn->executeStatement(
                'DELETE FROM product_variant WHERE product_id = :productId',
                ['productId' => $id]
            );

            $conn->executeStatement(
                'DELETE FROM catalog_product_tax WHERE product_id = :productId',
                ['productId' => $id]
            );

            $conn->executeStatement(
                'DELETE FROM catalog_product WHERE product_id = :productId',
                ['productId' => $id]
            );

            $conn->executeStatement(
                'DELETE FROM category_product WHERE product_id = :productId',
                ['productId' => $id]
            );

            if ($businessId !== null) {
                $inventoryParams = [
                    'businessId' => $businessId,
                    'productId' => $id,
                ];

                $conn->executeStatement(
                    'DELETE FROM variant_inventory WHERE business_id = :businessId AND product_id = :productId',
                    $inventoryParams
                );

                $conn->executeStatement(
                    'DELETE FROM inventory WHERE business_id = :businessId AND product_id = :productId',
                    $inventoryParams
                );

                $conn->executeStatement(
                    'DELETE FROM inventory_summary WHERE business_id = :businessId AND product_id = :productId',
                    $inventoryParams
                );
            }

            $params = ['productId' => $id];
            $condition = 'product_id = :productId';
            if ($businessId !== null) {
                $condition .= ' AND business_id = :businessId';
                $params['businessId'] = $businessId;
            }

            $conn->executeStatement(
                sprintf('DELETE FROM product WHERE %s', $condition),
                $params
            );

            $conn->commit();

            $this->context->getLog()->info(
                sprintf('ProductService::delete: removed product %s and related records', $id)
            );

            return true;
        } catch (\Throwable $exception) {
            $conn->rollBack();

            $this->context->getLog()->error(
                sprintf('ProductService::delete: failed for product %s: %s', $id, $exception->getMessage())
            );

            return false;
        }
    }
}
