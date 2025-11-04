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
use App\Services\ServiceFactory;
use App\Services\Support\LoggerFactory;
use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../public/bootstrap.php';

$options = getopt('', ['business:', 'store::']);

if (!isset($options['business'])) {
    fwrite(STDERR, "Usage: php scripts/gather_resources.php --business=<BUSINESS_ID> [--store=<STORE_ID>]\\n");
    exit(1);
}

$businessId = trim((string) $options['business']);
if ($businessId === '') {
    fwrite(STDERR, "The --business option cannot be empty.\\n");
    exit(1);
}

$storeId = null;
if (array_key_exists('store', $options)) {
    $storeIdCandidate = trim((string) $options['store']);
    if ($storeIdCandidate !== '') {
        $storeId = $storeIdCandidate;
    }
}

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

$callbackService = new CallbackService($context, $platformRegistry, $serviceFactory);

if ($storeId !== null) {
    $result = $callbackService->gatherResourcesForStore($businessId, $storeId);
    $message = $result
        ? sprintf('Successfully gathered resources for business %s store %s.', $businessId, $storeId)
        : sprintf('Failed to gather resources for business %s store %s.', $businessId, $storeId);
} else {
    $result = $callbackService->gatherResourcesForBusiness($businessId);
    $message = $result
        ? sprintf('Successfully gathered resources for business %s.', $businessId)
        : sprintf('Failed to gather resources for business %s.', $businessId);
}

$stream = $result ? STDOUT : STDERR;
fwrite($stream, $message . "\n");

exit($result ? 0 : 1);
