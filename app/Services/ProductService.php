<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ProductService
{
    private Context $context;
    private Client $httpClient;
    private ?string $businessId = null;

    const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null)
    {
        $this->context = $context;
        $this->httpClient = new Client();
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

        $metadata = json_encode($productData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "ProductService::upsert: failed to json_encode productData for product_id={$productId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO product (product_id, business_id, name, metadata, created_at, updated_at)
                 VALUES (:productId, :businessId, :name, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (product_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     name = EXCLUDED.name,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'productId' => $productId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'metadata' => $metadata,
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

        $sql = 'INSERT INTO product_variant (variant_id, product_id, metadata, created_at, updated_at)
                VALUES (:variantId, :productId, :metadata, :createdAt, :updatedAt)
                ON CONFLICT (variant_id) DO UPDATE SET
                    product_id = EXCLUDED.product_id,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at';

        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($variants as $variant) {
            if (!isset($variant['id'])) {
                $this->context->getLog()->error('ProductService::upsertVariants: missing variant id');
                continue;
            }

            $variantId = $variant['id'];
            $metadata = json_encode($variant);
            if ($metadata === false) {
                $this->context->getLog()->error(
                    "ProductService::upsertVariants: failed to json_encode variant for variant_id={$variantId}"
                );
                continue;
            }

            $stmt->executeStatement([
                'variantId' => $variantId,
                'productId' => $productId,
                'metadata' => $metadata,
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/products', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'ProductService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
