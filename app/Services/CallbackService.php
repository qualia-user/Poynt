<?php

namespace App\Services;

use App\Core\Context;
use App\Core\Response;
use App\Modules\OAuth\PlatformRegistry;

class CallbackService
{
    private Context $context;
    private PlatformRegistry $platformRegistry;

    public function __construct(Context $context, PlatformRegistry $platformRegistry)
    {
        $this->context = $context;
        $this->platformRegistry = $platformRegistry;
    }

    /**
     * Handle OAuth callback for a platform.
     */
    public function handle(string $platform): array
    {
        try {
            $handler = $this->platformRegistry->getHandler($platform);
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'status' => Response::STATUS_BAD_REQUEST,
                'error' => $e->getMessage(),
            ];
        }

        $tokenResponse = $handler->retrieveTokens();
        if (!($tokenResponse['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $tokenResponse['status'] ?? Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => $tokenResponse['error'] ?? 'Token exchange failed.',
            ];
        }

        $appToken = $tokenResponse['data']['appAccessToken'] ?? [];
        $merchantToken = $tokenResponse['data']['merchantAccessToken'] ?? [];
        $handler->storeTokens($appToken, $merchantToken);

        $businessService = new BusinessService($this->context, $handler->getBusinessId());
        if (!$businessService->businessExists()) {
            $stores = $businessService->fetchBusinessStores();
            $businessService->upsertStores($stores);
            if ($business = $businessService->fetchBusinessById($handler->getBusinessId())) {
                $businessService->upsert($business);
            }
        }

        $handler->registerWebhooks();

        return [
            'success' => true,
            'status' => Response::STATUS_OK,
            'message' => 'Callback handled',
        ];
    }
}
