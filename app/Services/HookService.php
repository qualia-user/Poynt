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
        $deliveries = $this->resolveDeliveries($hookData);
        if (!empty($deliveries)) {
            $hookData = $this->stripDeliveries($hookData);
        }

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
            if (!empty($deliveries)) {
                $this->syncDeliveries($hookId, $businessId, $deliveries);
            }
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

            $hooks = json_decode($response->getBody(), true);
            if (!is_array($hooks)) {
                return false;
            }

            foreach ($hooks as &$hook) {
                if (!is_array($hook) || !isset($hook['id'])) {
                    continue;
                }

                $deliveries = $this->fetchDeliveries($businessId, $hook['id'], $accessToken);
                if (!empty($deliveries)) {
                    $hook['deliveries'] = $deliveries;
                }
            }

            return $hooks;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'HookService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }

    private function fetchDeliveries(string $businessId, string $hookId, string $accessToken): array
    {
        try {
            $response = $this->httpClient->get(
                self::POYNT_ENDPOINT . '/' . $businessId . '/hooks/' . $hookId . '/deliveries',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                ]
            );

            $payload = json_decode($response->getBody(), true);
            return $this->normalizeDeliveries($payload, $businessId, $hookId);
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('HookService::fetchDeliveries: failed for hook %s: %s', $hookId, $e->getMessage())
            );
            return [];
        }
    }

    private function normalizeDeliveries(mixed $payload, string $businessId, string $hookId): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['deliveries']) && is_array($payload['deliveries'])) {
            $payload = $payload['deliveries'];
        }

        if (!is_array($payload)) {
            return [];
        }

        if (!array_is_list($payload)) {
            $rows = [];
            foreach ($payload as $value) {
                if (is_array($value)) {
                    $rows[] = $value;
                }
            }
            $payload = $rows;
        }

        foreach ($payload as &$row) {
            if (is_array($row)) {
                $row['businessId'] = $businessId;
                $row['hookId'] = $row['hookId'] ?? $hookId;
            }
        }

        return array_filter($payload, 'is_array');
    }

    private function resolveDeliveries(array $hookData): array
    {
        if (isset($hookData['deliveries']) && is_array($hookData['deliveries'])) {
            return $hookData['deliveries'];
        }

        if (isset($hookData['hook']) && is_array($hookData['hook'])) {
            $inner = $hookData['hook'];
            if (isset($inner['deliveries']) && is_array($inner['deliveries'])) {
                return $inner['deliveries'];
            }
        }

        return [];
    }

    private function stripDeliveries(array $hookData): array
    {
        if (isset($hookData['deliveries'])) {
            unset($hookData['deliveries']);
        }

        if (isset($hookData['hook']) && is_array($hookData['hook'])) {
            $hookData['hook'] = $this->stripDeliveries($hookData['hook']);
        }

        return $hookData;
    }

    private function syncDeliveries(string $hookId, string $businessId, array $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || !isset($delivery['id'])) {
                continue;
            }

            $deliveryId = $delivery['id'];
            $eventType = $delivery['eventType'] ?? null;
            $deliveredAt = Format::optionalTimestamp($delivery['deliveredAt'] ?? $delivery['deliveredAtExt'] ?? null);
            $status = $delivery['status'] ?? null;
            $httpStatus = isset($delivery['httpStatus']) ? (int)$delivery['httpStatus'] : null;
            $retryCount = isset($delivery['retryCount']) ? (int)$delivery['retryCount'] : null;
            $payload = Format::jsonObject($delivery);

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO hook_delivery (
                        delivery_id,
                        hook_id,
                        business_id,
                        event_type,
                        delivered_at_ext,
                        status,
                        http_status,
                        retry_count,
                        raw_payload
                    ) VALUES (
                        :deliveryId,
                        :hookId,
                        :businessId,
                        :eventType,
                        :deliveredAt,
                        :status,
                        :httpStatus,
                        :retryCount,
                        :payload
                    ) ON CONFLICT (delivery_id) DO UPDATE SET
                        hook_id          = EXCLUDED.hook_id,
                        business_id      = EXCLUDED.business_id,
                        event_type       = EXCLUDED.event_type,
                        delivered_at_ext = EXCLUDED.delivered_at_ext,
                        status           = EXCLUDED.status,
                        http_status      = EXCLUDED.http_status,
                        retry_count      = EXCLUDED.retry_count,
                        raw_payload      = EXCLUDED.raw_payload',
                    [
                        'deliveryId'  => $deliveryId,
                        'hookId'      => $hookId,
                        'businessId'  => $businessId,
                        'eventType'   => $eventType,
                        'deliveredAt' => $deliveredAt,
                        'status'      => $status,
                        'httpStatus'  => $httpStatus,
                        'retryCount'  => $retryCount,
                        'payload'     => $payload,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('HookService::syncDeliveries: failed for hook %s delivery %s: %s', $hookId, $deliveryId, $e->getMessage())
                );
            }
        }
    }
}
