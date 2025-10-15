<?php

namespace App\Services;

use App\Core\Context;
use App\Core\Response;
use App\Modules\OAuth\PlatformRegistry;
use Throwable;

class CallbackService
{
    private Context $context;
    private PlatformRegistry $platformRegistry;
    private ServiceFactory $serviceFactory;

    public function __construct(
        Context $context,
        PlatformRegistry $platformRegistry,
        ?ServiceFactory $serviceFactory = null
    ) {
        $this->context = $context;
        $this->platformRegistry = $platformRegistry;
        $this->serviceFactory = $serviceFactory ?? new ServiceFactory($context);
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

        $businessId = $handler->getBusinessId();
        $storeId = $handler->getStoreId();

        $businessService = $this->serviceFactory->business($businessId);
        $this->startTrialIfMissing($businessId, $storeId);

        if ($businessId) {
            $stores = $businessService->fetchBusinessStores($businessId);
            $businessService->upsertStores($stores);

            if ($business = $businessService->fetchBusinessById($businessId)) {
                $businessService->upsert($business);
            }

            $this->gatherInitialResources($businessId, $businessService);
        } else {
            $this->context->getLog()->warning('Skipping onboarding sync: missing businessId in callback response.');
        }

        $handler->registerWebhooks();

        return [
            'success' => true,
            'status' => Response::STATUS_OK,
            'message' => 'Callback handled',
        ];
    }

    private function startTrialIfMissing(?string $businessId, ?string $storeId): void
    {
        if (!$businessId || !$storeId) {
            $this->context->getLog()->warning('Unable to start trial: missing businessId or storeId.');
            return;
        }

        try {
            $existing = $this->context->getConn()->fetchAssociative(
                'SELECT subscription_id FROM subscription WHERE business_id = ? AND store_id = ? LIMIT 1',
                [$businessId, $storeId]
            );
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to verify existing subscription for business %s store %s: %s',
                    $businessId,
                    $storeId,
                    $e->getMessage()
                )
            );
            return;
        }

        if ($existing) {
            $this->context->getLog()->info(
                sprintf(
                    'Subscription already exists for business %s store %s, skipping free trial.',
                    $businessId,
                    $storeId
                )
            );
            return;
        }

        try {
            $subscriptionId = $this->serviceFactory->subscription()->startFreeTrial($businessId, $storeId);
            $this->context->getLog()->info(
                sprintf(
                    'Started free trial %s for business %s store %s.',
                    $subscriptionId,
                    $businessId,
                    $storeId
                )
            );
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to start free trial for business %s store %s: %s',
                    $businessId,
                    $storeId,
                    $e->getMessage()
                )
            );
        }
    }

    private function gatherInitialResources(
        string $businessId,
        BusinessService $businessService
    ): void {
        foreach ($this->serviceFactory->onboardingResources($businessId) as $service) {
            $this->syncResourceCollection($businessId, $service);
        }

        $orders = $businessService->fetchBusinessOrders($businessId);
        if (is_array($orders) && !empty($orders)) {
            $orderService = $this->serviceFactory->order();
            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }

                try {
                    $orderService->upsert($order);
                } catch (Throwable $e) {
                    $this->context->getLog()->error(
                        sprintf(
                            'Failed to persist order %s for business %s: %s',
                            $order['id'] ?? 'unknown',
                            $businessId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        try {
            $merchantToken = $this->serviceFactory->token()->getMerchantToken($businessId);
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to load merchant token for transaction sync (business %s): %s',
                    $businessId,
                    $e->getMessage()
                )
            );
            return;
        }

        if (is_string($merchantToken) && $merchantToken !== '') {
            try {
                $this->serviceFactory->transaction()->fetchAndStore($merchantToken, $businessId);
            } catch (Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to sync transactions for business %s: %s',
                        $businessId,
                        $e->getMessage()
                    )
                );
            }
        } else {
            $this->context->getLog()->warning(
                sprintf('No merchant token available for transaction sync (business %s).', $businessId)
            );
        }
    }

    /**
     * @param object $service
     */
    private function syncResourceCollection(string $businessId, object $service): void
    {
        if (!method_exists($service, 'fetchByBusinessId') || !method_exists($service, 'upsert')) {
            return;
        }

        try {
            $raw = $service->fetchByBusinessId($businessId);
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to fetch onboarding resources for business %s: %s',
                    $businessId,
                    $e->getMessage()
                )
            );
            return;
        }

        if (!is_array($raw) || empty($raw)) {
            return;
        }

        foreach ($this->normalizeResourceItems($raw) as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $service->upsert($item);
            } catch (Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to persist onboarding resource for business %s: %s',
                        $businessId,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function normalizeResourceItems(array $raw): array
    {
        if (array_is_list($raw)) {
            return $raw;
        }

        $items = [];
        foreach ($raw as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $nested) {
                    if (is_array($nested)) {
                        $items[] = $nested;
                    }
                }
                continue;
            }

            $items[] = $value;
        }

        return $items;
    }
}
