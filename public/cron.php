<?php
namespace App;

require_once __DIR__ . '/bootstrap.php';

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Services\BackgroundJobService;
use App\Services\CustomPDOHandler;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

// Setup database connection
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

// Logger setup
$logHandler = new CustomPDOHandler($conn);
$log = new Logger('app-poynt-log');
$log->pushHandler($logHandler);
$requestId = Uuid::uuid4()->toString();
$log->pushProcessor(function ($record) use ($requestId) {
    $record['context']['request_id'] = $requestId;
    return $record;
});

$api = new Api('', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);

$service = new BackgroundJobService($context);
$service->refreshExpiringTokens();
