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

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'CatalogService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
