<?php
namespace App;

require_once 'bootstrap.php';

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Controllers\OAuthController;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\MerchantService;

// Initialize PDO
$dsn = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s",
    ConfigDatabase::$host,
    ConfigDatabase::$port,
    ConfigDatabase::$database
);
$pdo = new PDO($dsn, ConfigDatabase::$username, ConfigDatabase::$password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize components
$platformRegistry = new PlatformRegistry(ConfigApp::$platform);
$merchantService = new MerchantService($pdo);
$oauthController = new OAuthController($platformRegistry, $merchantService);

// Handle the request
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestUri === '/api/oauth/callback' && $requestMethod === 'POST') {
    // Parse JSON body
    $request = json_decode(file_get_contents('php://input'), true);

    try {
        $response = $oauthController->handleCallback($request);
        header('Content-Type: application/json');
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
