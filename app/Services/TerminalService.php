<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;

class TerminalService
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Upsert a set of terminals for a store.
     *
     * @param array $devices  Array of device payloads from the API
     * @param string $storeId Store identifier
     *
     * @return bool True on success, false on failure
     */
    public function upsertTerminals(array $devices, string $storeId): bool
    {
        if (empty($devices)) {
            return true;
        }

        $sql = <<<SQL
        INSERT INTO terminal
            (terminal_id, store_id, metadata, created_at, updated_at)
        VALUES
            (:terminalId, :storeId, :metadata, :createdAt, :updatedAt)
        ON CONFLICT (terminal_id) DO UPDATE SET
            store_id  = EXCLUDED.store_id,
            metadata  = EXCLUDED.metadata,
            updated_at = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

            foreach ($devices as $device) {
                if (!isset($device['id'])) {
                    $this->context->getLog()->error(
                        'TerminalService::upsertTerminals: missing device id'
                    );
                    return false;
                }

                $terminalId = $device['id'];
                $metadata   = Format::jsonObject($device);

                $affected = $stmt->executeStatement([
                    'terminalId' => $terminalId,
                    'storeId'    => $storeId,
                    'metadata'   => $metadata,
                    'createdAt'  => $now,
                    'updatedAt'  => $now,
                ]);

                if ($affected < 1) {
                    $this->context->getLog()->error(
                        "TerminalService::upsertTerminals: failed to upsert terminal_id={$terminalId}"
                    );
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'TerminalService::upsertTerminals: database error: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Retrieve all terminals belonging to a store.
     *
     * @param string $storeId
     *
     * @return array
     */
    public function fetchByStoreId(string $storeId): array
    {
        try {
            $rows = $this->context->getConn()->fetchAllAssociative(
                'SELECT * FROM terminal WHERE store_id = ?',
                [$storeId]
            );
            return $rows ?: [];
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'TerminalService::fetchByStoreId: database error: ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Retrieve a single terminal by its identifier.
     *
     * @param string $terminalId
     *
     * @return array|false
     */
    public function fetchById(string $terminalId): array|false
    {
        try {
            $row = $this->context->getConn()->fetchAssociative(
                'SELECT * FROM terminal WHERE terminal_id = ?',
                [$terminalId]
            );
            return $row ?: false;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'TerminalService::fetchById: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
