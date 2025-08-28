<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class HookService
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
     * Upsert a hook record.
     *
     * @param array $hookData
     * @return bool
     */
    public function upsert(array $hookData): bool
    {
        if (!isset($hookData['id'], $hookData['businessId'])) {
            $this->context->getLog()->error(
                'HookService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $hookId = $hookData['id'];
        $businessId = $hookData['businessId'];

        $metadata = json_encode($hookData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "HookService::upsert: failed to json_encode hookData for hook_id={$hookId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO hook (hook_id, business_id, metadata, created_at, updated_at)
                 VALUES (:hookId, :businessId, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (hook_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'hookId' => $hookId,
                    'businessId' => $businessId,
                    'metadata' => $metadata,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("HookService::upsert: upserted hook {$hookId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "HookService::upsert: database error for hook_id={$hookId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch hooks for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/hooks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'HookService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
