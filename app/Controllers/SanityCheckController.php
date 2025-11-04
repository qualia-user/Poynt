<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Response;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;

class SanityCheckController extends Controller
{
    private const ORDER_RELATED_TABLES = [
        'order_item' => 'order_id',
        'order_history' => 'order_id',
        'order_shipment' => 'order_id',
    ];

    private array $columnTableCache = [];

    public function index(): void
    {
        $this->startSession();

        $query = trim((string) $this->api->getParam('query', ''));
        $errors = [];
        $businessSummaries = [];

        if ($query !== '') {
            try {
                $businesses = $this->findBusinesses($query);

                if (empty($businesses)) {
                    $errors[] = sprintf('No businesses matched "%s".', $query);
                } else {
                    foreach ($businesses as $businessMatch) {
                        $businessSummaries[] = $this->buildBusinessSummary(
                            $businessMatch['business'],
                            $businessMatch['matchedStores'] ?? [],
                            (bool) ($businessMatch['matchedDirectly'] ?? false)
                        );
                    }
                }
            } catch (Exception $exception) {
                $errors[] = 'Unable to load business data. Please check the application logs for more details.';
                $this->log->error('Failed to load sanity check data', ['exception' => $exception]);
            }
        }

        $html = $this->renderView($query, $errors, $businessSummaries);
        Response::$contentType = 'text/html; charset=utf-8';
        Api::response(Response::STATUS_OK, $html);
    }

    /**
     * @throws Exception
     */
    private function findBusinesses(string $query): array
    {
        $params = [
            'exact' => $query,
            'fuzzy' => '%' . $query . '%',
        ];

        $sql = 'SELECT * FROM business WHERE business_id = :exact OR name ILIKE :fuzzy ORDER BY name';
        $businessMatches = $this->conn->fetchAllAssociative($sql, $params);

        $results = [];
        foreach ($businessMatches as $business) {
            $businessId = $business['business_id'] ?? null;
            if ($businessId === null) {
                continue;
            }

            $results[(string) $businessId] = [
                'business' => $business,
                'matchedDirectly' => true,
                'matchedStores' => [],
            ];
        }

        $storeSql = 'SELECT * FROM "store" WHERE store_id = :exact OR name ILIKE :fuzzy ORDER BY name';
        $storeMatches = $this->conn->fetchAllAssociative($storeSql, $params);

        if (!empty($storeMatches)) {
            $storesByBusiness = [];
            foreach ($storeMatches as $store) {
                $businessId = $store['business_id'] ?? null;
                if ($businessId === null) {
                    continue;
                }

                $businessKey = (string) $businessId;
                $storesByBusiness[$businessKey] ??= [];

                $storeKey = isset($store['store_id']) ? (string) $store['store_id'] : md5(json_encode($store));
                $storesByBusiness[$businessKey][$storeKey] = $store;
            }

            if (!empty($storesByBusiness)) {
                $storeBusinessIds = array_keys($storesByBusiness);

                $storeBusinesses = $this->conn->fetchAllAssociative(
                    'SELECT * FROM business WHERE business_id IN (:business_ids)',
                    ['business_ids' => $storeBusinessIds],
                    ['business_ids' => ArrayParameterType::STRING]
                );

                foreach ($storeBusinesses as $business) {
                    $businessId = (string) ($business['business_id'] ?? '');
                    if ($businessId === '') {
                        continue;
                    }

                    $matchedStores = array_values($storesByBusiness[$businessId]);

                    if (!isset($results[$businessId])) {
                        $results[$businessId] = [
                            'business' => $business,
                            'matchedDirectly' => false,
                            'matchedStores' => $matchedStores,
                        ];
                    } else {
                        $results[$businessId]['matchedStores'] = $matchedStores;
                    }
                }
            }
        }

        $results = array_values($results);

        usort(
            $results,
            static function (array $left, array $right): int {
                $leftDirect = (bool) ($left['matchedDirectly'] ?? false);
                $rightDirect = (bool) ($right['matchedDirectly'] ?? false);

                if ($leftDirect !== $rightDirect) {
                    return $leftDirect ? -1 : 1;
                }

                $leftName = (string) ($left['business']['name'] ?? '');
                $rightName = (string) ($right['business']['name'] ?? '');

                return strcasecmp($leftName, $rightName);
            }
        );

        return $results;
    }

    /**
     * @throws Exception
     */
    private function buildBusinessSummary(array $business, array $matchedStores = [], bool $matchedDirectly = false): array
    {
        $businessId = $business['business_id'];
        $tables = [];
        $tables['business'] = [$business];

        $tablesWithBusinessId = array_filter(
            $this->getTablesForColumn('business_id'),
            static fn(string $table): bool => $table !== 'business'
        );

        foreach ($tablesWithBusinessId as $table) {
            $quotedTable = $this->conn->quoteIdentifier($table);
            $rows = $this->conn->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE business_id = :business_id', $quotedTable),
                ['business_id' => $businessId]
            );

            if (!empty($rows)) {
                $tables[$table] = $rows;
            }
        }

        $stores = $tables['store'] ?? [];
        if (empty($stores) && in_array('store', $tablesWithBusinessId, true)) {
            $stores = $tables['store'] = $this->conn->fetchAllAssociative(
                'SELECT * FROM "store" WHERE business_id = :business_id',
                ['business_id' => $businessId]
            );
        }

        $storeIds = array_column($stores, 'store_id');
        if (!empty($storeIds)) {
            $storeOnlyTables = array_diff($this->getTablesForColumn('store_id'), $this->getTablesForColumn('business_id'));
            foreach ($storeOnlyTables as $table) {
                $quotedTable = $this->conn->quoteIdentifier($table);
                $rows = $this->conn->fetchAllAssociative(
                    sprintf('SELECT * FROM %s WHERE store_id IN (:store_ids)', $quotedTable),
                    ['store_ids' => $storeIds],
                    ['store_ids' => ArrayParameterType::STRING]
                );

                if (!empty($rows)) {
                    $tables[$table] = $rows;
                }
            }
        }

        $tables = $this->loadOrderRelatedTables($tables);
        $tables = $this->loadTransactionRelatedTables($tables);

        ksort($tables);

        return [
            'business' => $business,
            'tables' => $tables,
            'matchedStores' => $matchedStores,
            'matchedStoreIds' => array_values(array_unique(array_filter(array_map(
                static fn(array $store): ?string => isset($store['store_id']) ? (string) $store['store_id'] : null,
                $matchedStores
            )))),
            'matchedDirectly' => $matchedDirectly,
        ];
    }

    /**
     * @throws Exception
     */
    private function loadOrderRelatedTables(array $tables): array
    {
        if (empty($tables['order'])) {
            return $tables;
        }

        $orderIds = array_values(array_unique(array_column($tables['order'], 'order_id')));
        if (empty($orderIds)) {
            return $tables;
        }

        foreach (self::ORDER_RELATED_TABLES as $table => $keyColumn) {
            $quotedTable = $this->conn->quoteIdentifier($table);
            $rows = $this->conn->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE %s IN (:ids)', $quotedTable, $this->conn->quoteIdentifier($keyColumn)),
                ['ids' => $orderIds],
                ['ids' => ArrayParameterType::STRING]
            );

            if (!empty($rows)) {
                $tables[$table] = $rows;
            }
        }

        return $tables;
    }

    /**
     * @throws Exception
     */
    private function loadTransactionRelatedTables(array $tables): array
    {
        if (empty($tables['transaction'])) {
            return $tables;
        }

        $transactionIds = array_values(array_unique(array_column($tables['transaction'], 'transaction_id')));
        if (empty($transactionIds)) {
            return $tables;
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM transaction_receipt WHERE transaction_id IN (:ids)',
            ['ids' => $transactionIds],
            ['ids' => ArrayParameterType::STRING]
        );

        if (!empty($rows)) {
            $tables['transaction_receipt'] = $rows;
        }

        return $tables;
    }

    /**
     * @throws Exception
     */
    private function getTablesForColumn(string $column): array
    {
        if (!isset($this->columnTableCache[$column])) {
            $sql = 'SELECT table_name FROM information_schema.columns WHERE table_schema = :schema AND column_name = :column';
            $this->columnTableCache[$column] = $this->conn->fetchFirstColumn($sql, [
                'schema' => 'public',
                'column' => $column,
            ]);
            sort($this->columnTableCache[$column]);
        }

        return $this->columnTableCache[$column];
    }

    private function renderView(string $query, array $errors, array $businessSummaries): string
    {
        $viewPath = __DIR__ . '/../Views/sanity-check.php';
        if (!file_exists($viewPath)) {
            return '<h1>Sanity Check</h1><p>View not found.</p>';
        }

        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        include $viewPath;

        return (string) ob_get_clean();
    }
}
