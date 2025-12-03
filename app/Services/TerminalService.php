<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use App\Services\Support\TableNamer;

class TerminalService
{
    private Context $context;

    private TableNamer $tableNamer;

    private ?string $businessId;

    public function __construct(Context $context, ?string $businessId = null)
    {
        $this->context = $context;
        $this->tableNamer = new TableNamer($context->getConn());
        $this->businessId = $businessId;
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

        $terminalTable = $this->table('terminal');

        $sql = <<<SQL
        INSERT INTO {$terminalTable}
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

            foreach ($devices as $index => $device) {
                $terminalId = $device['deviceId']
                    ?? $device['externalTerminalId']
                    ?? null;

                if ($terminalId === null || $terminalId === '') {
                    $this->context->getLog()->warning(
                        sprintf(
                            'TerminalService::upsertTerminals: missing device identifier at index %d for store %s',
                            $index,
                            $storeId
                        )
                    );
                    continue;
                }

                $metadata = Format::jsonObject($device);

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
                sprintf('SELECT * FROM %s WHERE store_id = ?', $this->table('terminal')),
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
                sprintf('SELECT * FROM %s WHERE terminal_id = ?', $this->table('terminal')),
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

    private function table(string $baseName): string
    {
        return $this->tableNamer->for($this->businessId, $baseName);
    }
}
