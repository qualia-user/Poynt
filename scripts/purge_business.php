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
use App\Services\Support\LoggerFactory;
use App\Services\ServiceFactory;
use Doctrine\DBAL\DriverManager;
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

$logConnection = DriverManager::getConnection($connectionParams);
$logConnection->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");

[$log, $requestId] = LoggerFactory::create($conn, $logConnection);

$api = new Api('', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);
$serviceFactory = new ServiceFactory($context);
$platformRegistry = new PlatformRegistry($context);

$callbackService = new CallbackService($context, $platformRegistry, $serviceFactory);

$callbackService->purgeBusiness($businessId, !$dropTokens);

echo sprintf(
    "Purged local data for business %s (%s tokens).\n",
    $businessId,
    $dropTokens ? 'removed' : 'preserved'
);
