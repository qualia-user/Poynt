#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\CallbackService;
use App\Services\CustomPDOHandler;
use App\Services\ServiceFactory;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/../public/bootstrap.php';

$options = getopt('', ['business:', 'drop-tokens']);

if (!isset($options['business']) || $options['business'] === '') {
    fwrite(STDERR, "Usage: php scripts/purge_business.php --business=<BUSINESS_ID> [--drop-tokens]\n");
    exit(1);
}

$businessId = $options['business'];
$dropTokens = array_key_exists('drop-tokens', $options);

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

$logHandler = new CustomPDOHandler($conn);
$log = new Logger('app-poynt-log');
$log->pushHandler($logHandler);
$requestId = Uuid::uuid4()->toString();
$log->pushProcessor(static function ($record) use ($requestId) {
    $record['context']['request_id'] = $requestId;

    return $record;
});

$api = new Api('', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);
$serviceFactory = new ServiceFactory($context);
$platformRegistry = new PlatformRegistry($context);

$subscriptionService = $serviceFactory->subscription($businessId);
$tokenService = $serviceFactory->token();
$appAccessToken = null;

try {
    $appToken = $tokenService->getAppToken($businessId, true);
    if (is_array($appToken) && !empty($appToken['access_token'])) {
        $appAccessToken = $appToken['access_token'];
    } else {
        $log->warning(
            sprintf('App token payload for business %s missing access_token; skipping remote subscription deletion.', $businessId)
        );
    }
} catch (\Throwable $e) {
    $log->warning(
        sprintf('Failed to retrieve app token for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

$remoteSubscriptionIds = [];

if ($appAccessToken !== null) {
    try {
        $remoteSubscriptions = $subscriptionService->fetchSubscriptions($appAccessToken, $businessId);

        if (is_array($remoteSubscriptions)) {
            foreach ($remoteSubscriptions as $remoteSubscription) {
                if (is_array($remoteSubscription) && !empty($remoteSubscription['subscriptionId'])) {
                    $remoteSubscriptionIds[] = (string) $remoteSubscription['subscriptionId'];
                }
            }

            $remoteSubscriptionIds = array_values(array_unique($remoteSubscriptionIds));

            if (empty($remoteSubscriptionIds)) {
                $log->info(
                    sprintf('No remote subscriptions returned for business %s.', $businessId)
                );
            } else {
                $log->info(
                    sprintf(
                        'Fetched %d remote subscription(s) for business %s.',
                        count($remoteSubscriptionIds),
                        $businessId
                    )
                );
            }
        } else {
            $log->warning(
                sprintf('Unexpected response when fetching remote subscriptions for business %s.', $businessId)
            );
        }
    } catch (\Throwable $e) {
        $log->error(
            sprintf('Failed to fetch remote subscriptions for business %s: %s', $businessId, $e->getMessage()),
            ['exception' => $e]
        );
    }
}

$localSubscriptionIds = [];

try {
    $localSubscriptionIds = $conn->fetchFirstColumn(
        'SELECT subscription_id FROM subscription WHERE business_id = :biz',
        ['biz' => $businessId]
    );
} catch (\Throwable $e) {
    $log->warning(
        sprintf('Failed to fetch local subscriptions for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

$subscriptionIds = array_values(array_unique(array_merge($remoteSubscriptionIds, $localSubscriptionIds)));

if (!empty($subscriptionIds)) {
    if ($appAccessToken === null) {
        $log->warning(
            sprintf('No app token available for business %s; skipping Poynt subscription deletion.', $businessId)
        );
    } else {
        foreach ($subscriptionIds as $subscriptionId) {
            try {
                $subscriptionService->deleteSubscription($appAccessToken, $subscriptionId);
                $log->info(
                    sprintf(
                        'Deleted subscription %s from Poynt billing for business %s.',
                        $subscriptionId,
                        $businessId
                    )
                );
            } catch (\Throwable $e) {
                $log->error(
                    sprintf(
                        'Failed to delete subscription %s from Poynt billing for business %s: %s',
                        $subscriptionId,
                        $businessId,
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }
    }
}

$callbackService = new CallbackService($context, $platformRegistry, $serviceFactory);

$callbackService->purgeBusiness($businessId, !$dropTokens);

echo sprintf(
    "Purged local data for business %s (%s tokens).\n",
    $businessId,
    $dropTokens ? 'removed' : 'preserved'
);
