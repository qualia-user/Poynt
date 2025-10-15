<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class StoreService
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

    /**
     * Fetch stores for a business.
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
        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf('StoreService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (is_array($data)) {
                return $data;
            }
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('StoreService::fetchByBusinessId: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Upsert a store and its terminals.
     */
    public function upsert(array $storeData): bool
    {
        if (!isset($storeData['id'], $storeData['businessId'], $storeData['displayName'])) {
            $this->context->getLog()->error(
                'StoreService::upsert: missing required store fields (id, businessId, displayName)'
            );
            return false;
        }

        $storeId = $storeData['id'];
        $businessId = $storeData['businessId'];
        $name = $storeData['displayName'];
        $metadata = Format::jsonObject($storeData);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        $sql = <<<SQL
        INSERT INTO store (
            store_id,
            business_id,
            name,
            metadata,
            created_at,
            updated_at
        ) VALUES (
            :storeId,
            :businessId,
            :name,
            :metadata,
            :createdAt,
            :updatedAt
        ) ON CONFLICT (store_id) DO UPDATE SET
            business_id = EXCLUDED.business_id,
            name        = EXCLUDED.name,
            metadata    = EXCLUDED.metadata,
            updated_at  = EXCLUDED.updated_at
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'storeId'    => $storeId,
                'businessId' => $businessId,
                'name'       => $name,
                'metadata'   => $metadata,
                'createdAt'  => $now,
                'updatedAt'  => $now,
            ]);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'StoreService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }

        $devices = $storeData['storeDevices'] ?? [];
        if (is_array($devices) && !empty($devices)) {
            $terminalService = new TerminalService($this->context);
            $terminalService->upsertTerminals($devices, $storeId);
        }

        return true;
    }
}
