<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PaylinkService
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
     * Upsert a paylink record.
     *
     * @param array $paylinkData
     * @return bool
     */
    public function upsert(array $paylinkData): bool
    {
        if (!isset($paylinkData['id'], $paylinkData['businessId'])) {
            $this->context->getLog()->error(
                'PaylinkService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $paylinkId = $paylinkData['id'];
        $businessId = $paylinkData['businessId'];

        $metadata = json_encode($paylinkData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "PaylinkService::upsert: failed to json_encode paylinkData for paylink_id={$paylinkId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO paylink (paylink_id, business_id, metadata, created_at, updated_at)
                 VALUES (:paylinkId, :businessId, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (paylink_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'paylinkId' => $paylinkId,
                    'businessId' => $businessId,
                    'metadata' => $metadata,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("PaylinkService::upsert: upserted paylink {$paylinkId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "PaylinkService::upsert: database error for paylink_id={$paylinkId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch paylinks for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/paylinks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'PaylinkService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
