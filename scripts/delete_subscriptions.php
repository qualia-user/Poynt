#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\Support\LoggerFactory;
use App\Services\ServiceFactory;
use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../public/bootstrap.php';

/**
 * @param mixed $payload
 * @return array<int, string>
 */
function extractStoreIdsFromPayload(mixed $payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    $collections = [];

    if (\array_is_list($payload)) {
        $collections[] = $payload;
    } else {
        foreach (['items', 'stores', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $collections[] = $payload[$key];
            }
        }

        if ($collections === []) {
            $collections[] = array_values(array_filter($payload, 'is_array'));
        }
    }

    $ids = [];

    foreach ($collections as $collection) {
        if (!is_array($collection)) {
            continue;
        }

        foreach ($collection as $storeData) {
            if (!is_array($storeData)) {
                continue;
            }

            $candidateId = $storeData['id'] ?? $storeData['storeId'] ?? null;
            if (is_string($candidateId) && $candidateId !== '') {
                $ids[] = $candidateId;
                continue;
            }

            if (isset($storeData['store']) && is_array($storeData['store'])) {
                $nestedId = $storeData['store']['id'] ?? $storeData['store']['storeId'] ?? null;
                if (is_string($nestedId) && $nestedId !== '') {
                    $ids[] = $nestedId;
                }
            }
        }
    }

    if ($ids === []) {
        return [];
    }

    return array_values(array_unique($ids));
}

$options = getopt('', ['business:', 'dry-run']);

if (!isset($options['business']) || $options['business'] === '') {
    fwrite(STDERR, "Usage: php scripts/delete_subscriptions.php --business=<BUSINESS_ID> [--dry-run]\n");
    exit(1);
}

$businessId = $options['business'];
$dryRun = array_key_exists('dry-run', $options);

$connectionParams = [
    'driver' => 'pdo_pgsql',
    'host' => ConfigDatabase::$host,
    'port' => ConfigDatabase::$port,
    'dbname' => ConfigDatabase::$database,
    'user' => ConfigDatabase::$username,
    'password' => ConfigDatabase::$password,
    'charset' => ConfigDatabase::$charset,
];

$conn = DriverManager::getConnection($connectionParams);
$conn->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");

$logConnection = DriverManager::getConnection($connectionParams);
$logConnection->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");

[$log, $requestId] = LoggerFactory::create($conn, $logConnection);

$api = new Api('', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);
$serviceFactory = new ServiceFactory($context);
$platformRegistry = new PlatformRegistry($context);

$subscriptionIds = [];
$remoteSubscriptionIds = [];

try {
    $subscriptionIds = $conn->fetchFirstColumn(
        'SELECT subscription_id FROM subscription WHERE business_id = :biz',
        ['biz' => $businessId]
    );
} catch (Throwable $e) {
    $log->warning(
        sprintf('Failed to fetch subscriptions for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

$tokenService = $serviceFactory->token();
$appToken = null;
$merchantToken = null;

try {
    $appToken = $tokenService->getAppToken($businessId, true);
} catch (Throwable $e) {
    $log->warning(
        sprintf('Failed to retrieve app token for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

try {
    $merchantToken = $tokenService->getMerchantToken($businessId);
} catch (Throwable $e) {
    $log->warning(
        sprintf('Failed to retrieve merchant token for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

$subscriptionService = $serviceFactory->subscription($businessId);

$storeIds = [];

try {
    $storeIds = $conn->fetchFirstColumn(
        'SELECT store_id FROM store WHERE business_id = :biz',
        ['biz' => $businessId]
    );
} catch (Throwable $e) {
    $log->warning(
        sprintf('Failed to fetch local stores for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

$storeIds = array_values(array_filter(
    array_map(static fn($value) => is_string($value) ? trim($value) : null, $storeIds),
    static fn($value) => is_string($value) && $value !== ''
));

if (is_string($merchantToken) && $merchantToken !== '') {
    $storeService = $serviceFactory->store($businessId);
    $remoteStores = $storeService->fetchByBusinessId($businessId);

    if (is_array($remoteStores)) {
        $storeIds = array_values(array_unique(array_merge(
            $storeIds,
            extractStoreIdsFromPayload($remoteStores)
        )));
    } elseif ($remoteStores === false) {
        $log->warning(
            sprintf('Failed to fetch remote stores for business %s; continuing with local store list only.', $businessId)
        );
    }
}

if (is_array($appToken) && !empty($appToken['access_token'])) {
    $storesForLookup = $storeIds;

    if ($storesForLookup === []) {
        $storesForLookup[] = null;
    }

    foreach ($storesForLookup as $storeId) {
        try {
            $remoteSubscriptions = $subscriptionService->fetchSubscriptions(
                $appToken['access_token'],
                $businessId,
                $storeId
            );

            if (is_array($remoteSubscriptions)) {
                foreach ($remoteSubscriptions as $subscription) {
                    $remoteId = $subscription['subscriptionId'] ?? null;

                    if (is_string($remoteId) && $remoteId !== '') {
                        $remoteSubscriptionIds[] = $remoteId;
                    }
                }
            }
        } catch (Throwable $e) {
            $log->error(
                sprintf(
                    'Failed to fetch remote subscriptions for business %s%s: %s',
                    $businessId,
                    $storeId !== null ? sprintf(' (store %s)', $storeId) : '',
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }
}

$subscriptionIds = array_values(array_unique(array_merge($subscriptionIds, $remoteSubscriptionIds)));

if ($subscriptionIds === []) {
    echo sprintf("No subscriptions found for business %s.\n", $businessId);
    exit(0);
}

if (!is_string($merchantToken) || $merchantToken === '') {
    echo sprintf(
        "Found %d subscription(s) for business %s but no merchant token is available; skipping deletion.\n",
        count($subscriptionIds),
        $businessId
    );
    exit(1);
}

if ($dryRun) {
    echo sprintf(
        "Dry run: would delete %d subscription(s) for business %s:%s\n",
        count($subscriptionIds),
        $businessId,
        $subscriptionIds === [] ? '' : "\n- " . implode("\n- ", $subscriptionIds)
    );
    exit(0);
}

foreach ($subscriptionIds as $subscriptionId) {
    try {
        $subscriptionService->deleteSubscription($subscriptionId, $merchantToken);
        $log->info(
            sprintf(
                'Deleted subscription %s from Poynt billing for business %s.',
                $subscriptionId,
                $businessId
            )
        );
        echo sprintf("Deleted subscription %s for business %s.\n", $subscriptionId, $businessId);
    } catch (Throwable $e) {
        $log->error(
            sprintf(
                'Failed to delete subscription %s from Poynt billing for business %s: %s',
                $subscriptionId,
                $businessId,
                $e->getMessage()
            ),
            ['exception' => $e]
        );
        echo sprintf(
            "Failed to delete subscription %s for business %s: %s\n",
            $subscriptionId,
            $businessId,
            $e->getMessage()
        );
    }
}

