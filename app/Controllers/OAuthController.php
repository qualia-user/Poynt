<?php

namespace App\Controllers;

use App\Config\ConfigApp;
use App\Core\Context;
use App\Modules\OAuth\PlatformRegistry;
use App\Services\CallbackService;

class OAuthController extends Controller {
    private PlatformRegistry $platformRegistry;
    private CallbackService $callbackService;

    public function __construct(Context $context, ?PlatformRegistry $platformRegistry = null, ?CallbackService $callbackService = null) {
        parent::__construct($context);

        $this->platformRegistry = $platformRegistry ?? new PlatformRegistry($this->context);
        $this->callbackService = $callbackService ?? new CallbackService($this->context, $this->platformRegistry);
    }

    /**
     * Handles the "install" route.
     * Redirects the merchant to the OAuth authorization URL.
     */
    public function install(?string $platform): array
    {
        if (!$platform) {
            return ['error' => 'Platform not specified.'];
        }

        try {
            $handler = $this->platformRegistry->getHandler($platform);
        } catch (\InvalidArgumentException $e) {
            return ['error' => "Handler for platform $platform not found."];
        }

        $state = bin2hex(random_bytes(16)); // Prevent CSRF
        $_SESSION['state'] = $state;

        if (method_exists($handler, 'getAuthorizationUrl')) {
            $this->log->info("Redirecting to authorization for platform: $platform");
            return ['redirect' => $handler->getAuthorizationUrl($state)];
        }

        return ['error' => 'Authorization URL not supported.'];
    }


    /**
     * @return void
     * @throws \Exception
     */
    public function callback(): void
    {
        $platform = $this->context->getApi()->getParam('platform', ConfigApp::$platform ?? '');
        $result = $this->callbackService->handle($platform);

        if ($result['success']) {
            $this->log->info($result['message']);
            \App\Core\Api::response($result['status'], ['message' => $result['message']]);
        } else {
            $this->log->error($result['error']);
            \App\Core\Api::response($result['status'], ['error' => $result['error']]);
        }

        if (!\App\Core\Api::isExitDisabled()) {
            exit;
        }
    }



}
