<?php
namespace App;

require_once __DIR__ . '/bootstrap.php';

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use Doctrine\DBAL\Exception;
use League\Container\Container;
use Phroute\Phroute\Dispatcher;
use App\Core\Api;
use App\Core\Context;
use App\Core\RouterResolver;
use App\Core\Response;
use Doctrine\DBAL\DriverManager;
use App\Http\GuzzleClientFactory;
use App\Services\Support\LoggerFactory;

// Ensure CLI compatibility for testing
if (php_sapi_name() === 'cli') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
    parse_str(implode('&', array_slice($argv, 1)), $_REQUEST);
}

// Database connection
$options = [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_EMULATE_PREPARES => false,
    \PDO::ATTR_STRINGIFY_FETCHES => false,
];

$connectionParams = [
    'driver' => 'pdo_pgsql',
    'host' => ConfigDatabase::$host,
    'port' => ConfigDatabase::$port,
    'dbname' => ConfigDatabase::$database,
    'user' => ConfigDatabase::$username,
    'password' => ConfigDatabase::$password,
    'charset' => ConfigDatabase::$charset,
    'driverOptions' => $options,
];

$logConnection = null;

try {
    $conn = DriverManager::getConnection($connectionParams);
    $conn->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");
    //$conn->executeStatement("SET search_path TO scheme,scheme"); TODO schemas

    $logConnection = DriverManager::getConnection($connectionParams);
    $logConnection->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");
} catch (Exception $e) {

}

// Logger setup
[$log, $requestId] = LoggerFactory::create($conn, $logConnection);

$api = new Api($_REQUEST['request'] ?? '', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);

// Dependency injection container
$appContainer = new Container();
$appContainer->add('CONTEXT', $context);
$appContainer->add('App\Services\ServiceFactory')->addArgument('CONTEXT');

// Register controllers
$appContainer->add('App\Controllers\OAuthController')->addArgument('CONTEXT');
$appContainer->add('App\Controllers\TokenController')->addArgument('CONTEXT');
$appContainer->add('App\Controllers\WebhooksController')->addArgument('CONTEXT');
$appContainer->add('App\Controllers\SubscriptionController')->addArgument('CONTEXT');
$appContainer->add('App\Controllers\SanityCheckController')->addArgument('CONTEXT');

$resolver = new RouterResolver($appContainer);

// Dispatch requests using Phroute
try {
    $dispatcher = new Dispatcher($api->loadRouteData(), $resolver);
    $response = $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_REQUEST['request'], PHP_URL_PATH));
    Api::response(Response::STATUS_OK, $response);
} catch (\Phroute\Phroute\Exception\HttpRouteNotFoundException $ex) {
    Api::response(Response::STATUS_BAD_REQUEST, ['error' => 'Route not found']);
} catch (\Phroute\Phroute\Exception\HttpMethodNotAllowedException $ex) {
    Api::response(Response::STATUS_METHOD_NOT_ALLOWED, ['error' => 'Method not allowed']);
}
