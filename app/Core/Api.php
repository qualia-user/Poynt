<?php
namespace App\Core;

use Psr\Log\LoggerInterface;
use App\Core\Response;

class Api
{

    private static ?LoggerInterface $log = null;

    /**
     * @var bool
     */
    private static bool $exitDisabled = false;

    /**
     * @var array|null
     */
    private static ?array $lastResponse = null;

    /**
     * @var string
     */
    private string $requestId;

    /**
     * @var string
     */
    protected string $method = '';

    /**
     * @var string
     */
    protected string $endpoint = 'default';

    /**
     * @var string
     */
    protected string $clientIp = '';

    /**
     * @var string
     */
    protected string $requestUri = '';

    /**
     * @var array|mixed|object
     */
    public array $data = [];

    /**
     * @var array|array[]|\false[][]
     */
    protected array $files = [];

    /**
     * @var array
     */
    public array $get = [];


    public function __construct($request, $log = null, string $requestId = '')
    {
        // Assign log if provided
        if (isset($log)) {
            self::$log = $log;
        }

        $this->requestId = $requestId;

        // Determine HTTP method, defaulting to CLI when invoked outside HTTP context
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

        // Handle custom HTTP methods
        if ($this->method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD'])) {
            $this->method = match ($_SERVER['HTTP_X_HTTP_METHOD']) {
                'DELETE' => 'DELETE',
                'PUT' => 'PUT',
                default => self::response(Response::STATUS_METHOD_NOT_ALLOWED),
            };
        }

        // Parse request and set endpoint
        if (!empty($request)) {
            $args = explode('/', rtrim($request, '/'));
            $this->endpoint = array_shift($args);

            if (!empty($args[0]) && !is_numeric($args[0])) {
                $this->endpoint .= '-' . array_shift($args);
            }
        } else {
            $this->endpoint = 'default'; // Fallback endpoint if $request is empty
        }

        // Set client IP and request URI
        $this->clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Retrieve raw input data
        $inputData = file_get_contents('php://input');
        if (!empty($_SERVER['HTTP_CONTENT_ENCODING']) && $_SERVER['HTTP_CONTENT_ENCODING'] === 'gzip') {
            $inputData = gzdecode($inputData) ?: '';
        }

        // Decode JSON input data
        $jsonData = json_decode($inputData, true) ?? [];

        // Store GET parameters separately for direct access
        $this->get = $_GET ?? [];

        // Merge all input sources into $this->data
        $this->data = array_merge(
            $this->get,        // GET parameters available as $this->get
            $_POST ?? [],       // POST parameters
            $jsonData           // JSON body data
        );

        // Process uploaded files if any
        if (!empty($_FILES)) {
            $this->files = array_map(function ($file) {
                return array_map(function ($value) {
                    return is_array($value) ? reset($value) : $value;
                }, $file);
            }, $_FILES);
        }

        // Ensure data is an array (to prevent null assignment)
        $this->data = is_array($this->data) ? $this->data : [];
    }
    /**
     * @param $status
     * @param $response
     * @param $exit
     * @param $returnRaw
     * @return false|string|void
     */
    public static function response($status, $response = null, $exit = true, $returnRaw = false)
    {
        header("HTTP/1.1 {$status} Status Code", true);

        if (isset(Response::$contentType) && in_array(Response::$contentType, Response::$imageTypes)) {
            header("Content-Type: " . Response::$contentType);
            echo $response;
            if ($status == Response::STATUS_OK) {
                self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent. Max Memory: " . memory_get_peak_usage(), self::getLogContext());
            } else {
                $responseJSON = json_encode($response);
                self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent with error status '{$status}' and response '{$responseJSON}'.", self::getLogContext());
            }
            exit;
        } else {
            $return = !$returnRaw ? json_encode(new Response($response)) : json_encode($response);

            self::$lastResponse = [
                'status' => $status,
                'response' => $response,
                'raw' => $return,
            ];

            if ($exit && !self::$exitDisabled) {
                if (isset($response)) {
                    echo $return;
                }
                if ($status == Response::STATUS_OK) {
                    self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent. Max Memory: " . memory_get_peak_usage(), self::getLogContext());
                } else {
                    $responseJSON = json_encode($response);
                    self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent with error status '{$status}' and response '{$responseJSON}'.", self::getLogContext());
                }
                exit;
            }

            return $return;
        }
    }

    public static function disableExit(): void
    {
        self::$exitDisabled = true;
    }

    public static function enableExit(): void
    {
        self::$exitDisabled = false;
    }

    public static function getLastResponse(): ?array
    {
        return self::$lastResponse;
    }

    public static function clearLastResponse(): void
    {
        self::$lastResponse = null;
    }

    public static function isExitDisabled(): bool
    {
        return self::$exitDisabled;
    }

    /**
     * Get the request ID
     *
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @param $user
     * @param $tenant
     * @param $url
     * @return array|null[]
     */
    public static function getLogContext($user = 0, $tenant = 0, $url = null)
    {
        if (!isset($url)) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
            $uri = isset($_SERVER['REQUEST_URI']) ? urldecode($_SERVER['REQUEST_URI']) : '';
            $url = trim("{$method} {$uri}");
        }

        return [
            'url' => $url,
        ];

    }

    /**
     * @param $status
     * @param mixed $response
     * @return void
     */
    public static function logStatusAndExit($status, mixed $response): void
    {
        if ($status == Response::STATUS_OK) {
            self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent. Max Memory: " . memory_get_peak_usage(), self::getLogContext());
        } else {
            $responseJSON = json_encode($response);
            self::$log->debug(basename(__FILE__) . ' (' . __LINE__ . "): Data sent with error status '{$status}' and response '{$responseJSON}'.", self::getLogContext());
        }
        exit;
    }


    /**
     * Define all API routes and map them to controllers.
     */
    public function loadRouteData()
    {
        $router = new \Phroute\Phroute\RouteCollector();

        // Install route
//        $router->get('/install', ['App\Controllers\OAuthController', 'install']);

        $router->get('/install', function () {
            return 'Install endpoint reached!';
        });

        // Callback route
        $router->get('/callback', ['App\Controllers\OAuthController', 'callback']);

        // Webhooks
//        $router->post('/webhooks/event-listener', ['App\Controllers\WebhooksController', 'eventListener']);
        $router->post('/webhooks/event-listener', ['App\Controllers\WebhooksController', 'eventListener']);

        // Internal route to trigger token refreshes
        $router->post('/internal/refresh-tokens', ['App\Controllers\TokenController', 'refreshTokens']);
        // Subscription routes
        $router->get('/subscriptions/status', ['App\Controllers\SubscriptionController', 'status']);
        $router->post('/subscriptions/start-trial', ['App\Controllers\SubscriptionController', 'startTrial']);

        return $router->getData();
    }


    public function getParam($param, $default = null)
    {
        return $this->data[$param] ?? $default;
    }
}
