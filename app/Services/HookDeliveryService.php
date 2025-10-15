<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class HookDeliveryService
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
                sprintf('HookDeliveryService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        $hookIds = $this->fetchHookIds($businessId, $accessToken);
        if (empty($hookIds)) {
            return false;
        }

        $deliveries = [];
        foreach ($hookIds as $hookId) {
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
                $rows = $this->extractDeliveries($payload);
                foreach ($rows as $row) {
                    $row['hookId'] = $hookId;
                    $row['businessId'] = $businessId;
                    $deliveries[] = $row;
                }
            } catch (GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf('HookDeliveryService::fetchByBusinessId: failed for hook %s: %s', $hookId, $e->getMessage())
                );
            }
        }

        return $deliveries ?: false;
    }

    private function fetchHookIds(string $businessId, string $accessToken): array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/hooks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!is_array($data)) {
                return [];
            }

            $ids = [];
            foreach ($data as $hook) {
                if (isset($hook['id'])) {
                    $ids[] = $hook['id'];
                }
            }

            return $ids;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('HookDeliveryService::fetchHookIds: ' . $e->getMessage());
            return [];
        }
    }

    private function extractDeliveries(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['deliveries']) && is_array($payload['deliveries'])) {
            return $payload['deliveries'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $rows = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $rows[] = $value;
            }
        }

        return $rows;
    }

    public function upsert(array $delivery): bool
    {
        if (!isset($delivery['hookId'], $delivery['id'])) {
            $this->context->getLog()->error(
                'HookDeliveryService::upsert: missing required fields (hookId or id)'
            );
            return false;
        }

        $deliveryId = $delivery['id'];
        $hookId = $delivery['hookId'];
        $businessId = $delivery['businessId'] ?? null;
        $eventType = $delivery['eventType'] ?? null;
        $deliveredAt = Format::optionalTimestamp($delivery['deliveredAt'] ?? $delivery['deliveredAtExt'] ?? null);
        $status = $delivery['status'] ?? null;
        $httpStatus = isset($delivery['httpStatus']) ? (int)$delivery['httpStatus'] : null;
        $retryCount = isset($delivery['retryCount']) ? (int)$delivery['retryCount'] : null;
        $payload = Format::jsonObject($delivery);

        $sql = <<<SQL
        INSERT INTO hook_delivery (
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
            raw_payload      = EXCLUDED.raw_payload
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'deliveryId'  => $deliveryId,
                'hookId'      => $hookId,
                'businessId'  => $businessId,
                'eventType'   => $eventType,
                'deliveredAt' => $deliveredAt,
                'status'      => $status,
                'httpStatus'  => $httpStatus,
                'retryCount'  => $retryCount,
                'payload'     => $payload,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'HookDeliveryService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
