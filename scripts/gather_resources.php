#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Services\OnboardingResourceGatherer;
use App\Services\ServiceFactory;
use App\Services\Support\LoggerFactory;
use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../public/bootstrap.php';

$options = getopt('', ['business:', 'resources::']);

if (!isset($options['business']) || trim((string) $options['business']) === '') {
    fwrite(STDERR, "Usage: php scripts/gather_resources.php --business=<BUSINESS_ID> [--resources=business,store]\n");
    exit(1);
}

$businessId = trim((string) $options['business']);
$filters = [];

if (isset($options['resources'])) {
    $rawFilters = is_array($options['resources']) ? $options['resources'] : [$options['resources']];
    foreach ($rawFilters as $rawFilter) {
        foreach (explode(',', (string) $rawFilter) as $chunk) {
            $candidate = trim($chunk);
            if ($candidate === '') {
                continue;
            }

            $filters[] = $candidate;
        }
    }
}

$filters = array_values(array_unique($filters));

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
$gatherer = new OnboardingResourceGatherer($context, $serviceFactory);

$summary = $gatherer->gatherWithSummary($businessId, $filters === [] ? null : $filters);

$output = [
    'businessId' => $summary['businessId'],
    'requestedFilters' => $summary['requestedFilters'],
    'matchedResources' => $summary['matchedResources'],
    'resources' => $summary['resources'],
];

if (isset($summary['error'])) {
    $output['error'] = $summary['error'];
}

fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL);

exit($summary['success'] ? 0 : 2);
