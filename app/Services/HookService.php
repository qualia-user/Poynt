<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class HookService
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

        $url = $hookData['url'] ?? $hookData['destinationUrl'] ?? null;
        $eventTypes = $hookData['eventTypes'] ?? $hookData['events'] ?? [];
        if (!is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }
        $eventTypesLiteral = Format::postgresTextArray($eventTypes);
        $status = $hookData['status'] ?? null;
        $rawPayload = Format::jsonObject($hookData);
        $createdAtExt = Format::optionalTimestamp($hookData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($hookData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO hook (
                    hook_id, business_id, url, event_types, status,
                    raw_payload, created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :hookId, :businessId, :url, :eventTypes, :status,
                    :rawPayload, :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (hook_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    url = EXCLUDED.url,
                    event_types = EXCLUDED.event_types,
                    status = EXCLUDED.status,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'hookId' => $hookId,
                    'businessId' => $businessId,
                    'url' => $url,
                    'eventTypes' => $eventTypesLiteral,
                    'status' => $status,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
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
