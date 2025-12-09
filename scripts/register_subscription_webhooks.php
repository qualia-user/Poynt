#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Services\Support\LoggerFactory;
use App\Services\OAuthService;
use App\Services\TokenService;
use App\Services\WebhookService;
use Doctrine\DBAL\DriverManager;
use Throwable;

require_once __DIR__ . '/../public/bootstrap.php';

$orgId = ConfigApp::$orgId ?? '';

if (!is_string($orgId) || $orgId === '') {
    fwrite(STDERR, "ConfigApp::\$orgId must be configured to register subscription webhooks.\n");

    exit(1);
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

$oauthService = new OAuthService($context);
$tokenService = new TokenService($context);
$appToken = null;

try {
    $appToken = $oauthService->exchangeJwtForToken();
} catch (Throwable $e) {
    $log->error(
        sprintf('Failed to retrieve app token for org %s: %s', $orgId, $e->getMessage()),
        ['exception' => $e]
    );
}

if (!is_array($appToken) || empty($appToken['accessToken'])) {
    fwrite(STDERR, sprintf("Missing app token for org %s.\n", $orgId));

    exit(1);
}

try {
    $tokenService->saveAppToken($orgId, $appToken);
} catch (Throwable $e) {
    $log->error(
        sprintf('Failed to persist app token for org %s: %s', $orgId, $e->getMessage()),
        ['exception' => $e]
    );

    exit(1);
}

$webhookService = new WebhookService($context, $orgId);

try {
    $webhookService->registerSubscriptionWebhooks($appToken['accessToken']);
    fwrite(STDOUT, "Subscription webhooks registered.\n");
} catch (Throwable $e) {
    $log->error(
        sprintf('Failed to register subscription webhooks for org %s: %s', $orgId, $e->getMessage()),
        ['exception' => $e]
    );

    exit(1);
}
