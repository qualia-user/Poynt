<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
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
                FetchResponseLogger::info(
                    $this->context->getLog(),
                    'StoreService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'stores',
                        'payload' => $data,
                    ]
                );

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

    public function delete(string $id, ?string $businessId = null): bool
    {
        $conn = $this->context->getConn();

        try {
            $conn->beginTransaction();

            $inventoryParams = ['storeId' => $id];
            if ($businessId !== null) {
                $inventoryParams['businessId'] = $businessId;

                $conn->executeStatement(
                    'DELETE FROM inventory WHERE business_id = :businessId AND store_id = :storeId',
                    $inventoryParams
                );

                $conn->executeStatement(
                    'DELETE FROM variant_inventory WHERE business_id = :businessId AND store_id = :storeId',
                    $inventoryParams
                );
            } else {
                $conn->executeStatement(
                    'DELETE FROM inventory WHERE store_id = :storeId',
                    ['storeId' => $id]
                );

                $conn->executeStatement(
                    'DELETE FROM variant_inventory WHERE store_id = :storeId',
                    ['storeId' => $id]
                );
            }

            $conn->executeStatement(
                'DELETE FROM terminal WHERE store_id = :storeId',
                ['storeId' => $id]
            );

            $storeParams = ['storeId' => $id];
            $condition = 'store_id = :storeId';
            if ($businessId !== null) {
                $condition .= ' AND business_id = :businessId';
                $storeParams['businessId'] = $businessId;
            }

            $conn->executeStatement(
                sprintf('DELETE FROM store WHERE %s', $condition),
                $storeParams
            );

            $conn->commit();

            $this->context->getLog()->info(
                sprintf('StoreService::delete: removed store %s and related records', $id)
            );

            return true;
        } catch (\Throwable $exception) {
            $conn->rollBack();

            $this->context->getLog()->error(
                sprintf('StoreService::delete: failed for store %s: %s', $id, $exception->getMessage())
            );

            return false;
        }
    }
}
