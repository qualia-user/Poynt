#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Services\Support\LoggerFactory;
use App\Services\TokenService;
use App\Services\WebhookService;
use Doctrine\DBAL\DriverManager;
use Throwable;

require_once __DIR__ . '/../public/bootstrap.php';

$options = getopt('', ['business:']);

if (!isset($options['business']) || $options['business'] === '') {
    fwrite(STDERR, "Usage: php scripts/register_subscription_webhooks.php --business=<BUSINESS_ID>\n");

    exit(1);
}

$businessId = $options['business'];

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

$tokenService = new TokenService($context);
$merchantToken = null;

try {
    $merchantToken = $tokenService->getMerchantToken($businessId);
} catch (Throwable $e) {
    $log->error(
        sprintf('Failed to retrieve merchant token for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );
}

if (!is_string($merchantToken) || $merchantToken === '') {
    fwrite(STDERR, sprintf("Missing merchant token for business %s.\n", $businessId));

    exit(1);
}

$webhookService = new WebhookService($context, $businessId);

try {
    $webhookService->registerSubscriptionWebhooks($merchantToken);
    fwrite(STDOUT, "Subscription webhooks registered.\n");
} catch (Throwable $e) {
    $log->error(
        sprintf('Failed to register subscription webhooks for business %s: %s', $businessId, $e->getMessage()),
        ['exception' => $e]
    );

    exit(1);
}
