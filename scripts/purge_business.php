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

$subscriptionIds = [];

try {
    $subscriptionIds = $conn->fetchFirstColumn(
        'SELECT subscription_id FROM subscription WHERE business_id = :biz',
        ['biz' => $businessId]
    );
} catch (\Throwable $e) {
    $log->warning(
        sprintf('Failed to fetch subscriptions for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

if (!empty($subscriptionIds)) {
    $tokenService = $serviceFactory->token();
    $appToken = null;

    try {
        $appToken = $tokenService->getAppToken($businessId, true);
    } catch (\Throwable $e) {
        $log->warning(
            sprintf('Failed to retrieve app token for business %s: %s', $businessId, $e->getMessage()),
            ['exception' => $e]
        );
    }

    if (is_array($appToken) && !empty($appToken['access_token'])) {
        $subscriptionService = $serviceFactory->subscription($businessId);

        foreach ($subscriptionIds as $subscriptionId) {
            try {
                $subscriptionService->deleteSubscription($appToken['access_token'], $subscriptionId);
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
    } else {
        $log->warning(
            sprintf('No app token available for business %s; skipping Poynt subscription deletion.', $businessId)
        );
    }
}

$callbackService = new CallbackService($context, $platformRegistry, $serviceFactory);

$callbackService->purgeBusiness($businessId, !$dropTokens);

echo sprintf(
    "Purged local data for business %s (%s tokens).\n",
    $businessId,
    $dropTokens ? 'removed' : 'preserved'
);
