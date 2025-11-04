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

            $this->upsertVariants($productId, $productData['variants'] ?? []);

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
        if (empty($variants)) {
            return;
        }

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
            if (!isset($variant['id'])) {
                $this->context->getLog()->error('ProductService::upsertVariants: missing variant id');
                continue;
            }

            $variantId = $variant['id'];
            $name = $variant['name'] ?? null;
            $sku = $variant['sku'] ?? null;
            $priceMinor = Format::amount($variant['price'] ?? ($variant['priceAmount'] ?? null));
            if ($priceMinor === null && isset($variant['price']['amount'])) {
                $priceMinor = Format::amount($variant['price']['amount']);
            }
            $attributes = Format::jsonObject($variant['attributes'] ?? []);
            $rawPayload = Format::jsonObject($variant);
            $createdAtExt = Format::optionalTimestamp($variant['createdAt'] ?? null);
            $updatedAtExt = Format::optionalTimestamp($variant['updatedAt'] ?? null);

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
}
