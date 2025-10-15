<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class CatalogProductService
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
                sprintf('CatalogProductService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        $catalogs = $this->fetchCatalogIds($businessId, $accessToken);
        if (empty($catalogs)) {
            return false;
        }

        $items = [];
        foreach ($catalogs as $catalogId) {
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
                foreach ($this->extractProducts($payload) as $product) {
                    $product['catalogId'] = $catalogId;
                    $items[] = $product;
                }
            } catch (GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf('CatalogProductService::fetchByBusinessId: failed for catalog %s: %s', $catalogId, $e->getMessage())
                );
            }
        }

        return $items ?: false;
    }

    private function fetchCatalogIds(string $businessId, string $accessToken): array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/catalogs', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!is_array($data)) {
                return [];
            }

            $ids = [];
            foreach ($data as $catalog) {
                if (isset($catalog['id'])) {
                    $ids[] = $catalog['id'];
                }
            }

            return $ids;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('CatalogProductService::fetchCatalogIds: ' . $e->getMessage());
            return [];
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

    public function upsert(array $catalogProduct): bool
    {
        if (!isset($catalogProduct['catalogId'], $catalogProduct['id'])) {
            $this->context->getLog()->error(
                'CatalogProductService::upsert: missing required fields (catalogId or product id)'
            );
            return false;
        }

        $catalogId = $catalogProduct['catalogId'];
        $productId = $catalogProduct['id'];
        $position = $catalogProduct['position'] ?? $catalogProduct['index'] ?? null;
        $payload = Format::jsonObject($catalogProduct);

        $sql = <<<SQL
        INSERT INTO catalog_product (
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
            payload  = EXCLUDED.payload
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'catalogId' => $catalogId,
                'productId' => $productId,
                'position'  => $position,
                'payload'   => $payload,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'CatalogProductService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
