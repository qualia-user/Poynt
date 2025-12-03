<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PoyntDataFormatter as Format;
use App\Services\Support\TableNamer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class StoreService
{
    private const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;
    private TableNamer $tableNamer;

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        $this->tableNamer = new TableNamer($context->getConn());
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

        $storeTable = $this->table('store', $businessId);
        $sql = <<<SQL
        INSERT INTO {$storeTable} (
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
            $terminalService = new TerminalService($this->context, $businessId);
            $terminalService->upsertTerminals($devices, $storeId);
        }

        return true;
    }

    public function delete(string $id, ?string $businessId = null): bool
    {
        $conn = $this->context->getConn();

        try {
            $conn->beginTransaction();

            $inventoryTable = $this->table('inventory', $businessId);
            $variantInventoryTable = $this->table('variant_inventory', $businessId);
            $terminalTable = $this->table('terminal', $businessId);
            $storeTable = $this->table('store', $businessId);

            $inventoryParams = ['storeId' => $id];
            if ($businessId !== null) {
                $inventoryParams['businessId'] = $businessId;

                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE business_id = :businessId AND store_id = :storeId', $inventoryTable),
                    $inventoryParams
                );

                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE business_id = :businessId AND store_id = :storeId', $variantInventoryTable),
                    $inventoryParams
                );
            } else {
                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE store_id = :storeId', $inventoryTable),
                    ['storeId' => $id]
                );

                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE store_id = :storeId', $variantInventoryTable),
                    ['storeId' => $id]
                );
            }

            $conn->executeStatement(
                sprintf('DELETE FROM %s WHERE store_id = :storeId', $terminalTable),
                ['storeId' => $id]
            );

            $storeParams = ['storeId' => $id];
            $condition = 'store_id = :storeId';
            if ($businessId !== null) {
                $condition .= ' AND business_id = :businessId';
                $storeParams['businessId'] = $businessId;
            }

            $conn->executeStatement(
                sprintf('DELETE FROM %s WHERE %s', $storeTable, $condition),
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

    private function table(string $baseName, ?string $businessId = null): string
    {
        return $this->tableNamer->for($businessId ?? $this->businessId, $baseName);
    }
}
