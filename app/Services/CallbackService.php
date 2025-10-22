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

        $appAccessToken = $this->extractAccessToken($appToken);
        $merchantAccessToken = $this->extractAccessToken($merchantToken);

        if ($businessId) {
            if ($this->installationExists($businessId)) {
                $this->reactivateInstallation($businessId);
            } else {
                $this->startTrialIfMissing($businessId, $storeId);
            }

            $this->synchronizeStoresAndSubscriptions(
                $businessId,
                $appAccessToken,
                $merchantAccessToken
            );

            $this->gatherInitialResources($businessId);
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

    private function installationExists(string $businessId): bool
    {
        try {
            $existing = $this->context->getConn()->fetchOne(
                'SELECT 1 FROM business WHERE business_id = ? LIMIT 1',
                [$businessId]
            );
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf('Failed to verify existing installation for business %s: %s', $businessId, $e->getMessage())
            );

            return false;
        }

        return $existing !== false && $existing !== null;
    }

    private function reactivateInstallation(string $businessId): void
    {
        $this->markBusinessActive($businessId);
        $this->reactivateLocalSubscriptions($businessId);
    }

    private function markBusinessActive(string $businessId): void
    {
        try {
            $updated = $this->context->getConn()->executeStatement(
                'UPDATE business SET active = TRUE, updated_at = NOW() WHERE business_id = ?',
                [$businessId]
            );

            if ($updated === 0) {
                $this->context->getLog()->info(
                    sprintf('Business %s not found during reactivation, will populate via onboarding sync.', $businessId)
                );
            }
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf('Failed to mark business %s as active: %s', $businessId, $e->getMessage())
            );
        }
    }

    private function reactivateLocalSubscriptions(string $businessId): void
    {
        try {
            $subscriptions = $this->context->getConn()->fetchAllAssociative(
                'SELECT subscription_id, store_id FROM subscription WHERE business_id = ?',
                [$businessId]
            );
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf('Failed to load subscriptions for business %s during reactivation: %s', $businessId, $e->getMessage())
            );

            return;
        }

        if (empty($subscriptions)) {
            return;
        }

        $subscriptionService = $this->serviceFactory->subscription();

        foreach ($subscriptions as $subscription) {
            $subscriptionId = $subscription['subscription_id'] ?? null;
            $storeId = $subscription['store_id'] ?? null;

            if (!$subscriptionId || !$storeId) {
                continue;
            }

            $subscriptionService->activateSubscription($subscriptionId, $businessId, $storeId);
        }
    }

    private function synchronizeStoresAndSubscriptions(
        string $businessId,
        ?string $appAccessToken,
        ?string $merchantAccessToken
    ): void {
        $storeService = $this->serviceFactory->store($businessId);
        $storesPayload = $storeService->fetchByBusinessId($businessId);

        if (!is_array($storesPayload) || $storesPayload === []) {
            return;
        }

        $normalizedStores = $this->normalizeResourceItems($storesPayload);
        if (empty($normalizedStores['items'])) {
            return;
        }

        $subscriptionService = $this->serviceFactory->subscription($businessId);
        $planId = null;

        if ($appAccessToken) {
            $plans = $subscriptionService->fetchPlans($appAccessToken);
            $planId = $this->selectDefaultPlanId($plans);
        }

        foreach ($normalizedStores['items'] as $storeData) {
            if (!is_array($storeData)) {
                continue;
            }

            $storeId = $storeData['id'] ?? $storeData['storeId'] ?? null;
            if (!$storeId) {
                continue;
            }

            if (!isset($storeData['businessId'])) {
                $storeData['businessId'] = $businessId;
            }

            $storeService->upsert($storeData);

            $this->ensureStoreSubscription(
                $businessId,
                $storeId,
                $appAccessToken,
                $merchantAccessToken,
                $planId
            );
        }
    }

    private function ensureStoreSubscription(
        string $businessId,
        string $storeId,
        ?string $appAccessToken,
        ?string $merchantAccessToken,
        ?string $defaultPlanId
    ): void {
        $subscriptionService = $this->serviceFactory->subscription($businessId, $storeId);

        $existing = [];

        if ($appAccessToken) {
            $existing = $subscriptionService->fetchSubscriptions($appAccessToken, $businessId, $storeId);
        }

        if (empty($existing) && $merchantAccessToken) {
            $existing = $subscriptionService->fetchMerchantSubscriptions($merchantAccessToken, $businessId, $storeId) ?? [];

            foreach ($existing as $subscription) {
                if (is_array($subscription)) {
                    $subscriptionService->upsertLocalSubscription($subscription);
                }
            }
        }

        if (!empty($existing)) {
            return;
        }

        if (!$appAccessToken || !$defaultPlanId) {
            $this->context->getLog()->warning(
                sprintf(
                    'Unable to create subscription for business %s store %s: missing app token or plan.',
                    $businessId,
                    $storeId
                )
            );

            return;
        }

        $subscriptionService->createSubscription(
            $appAccessToken,
            $businessId,
            $storeId,
            $defaultPlanId
        );
    }

    private function extractAccessToken(mixed $token): ?string
    {
        if (!is_array($token)) {
            return null;
        }

        foreach (['accessToken', 'access_token'] as $key) {
            $value = $token[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function selectDefaultPlanId(?array $plans): ?string
    {
        if (!is_array($plans) || $plans === []) {
            return null;
        }

        if (isset($plans['plans']) && is_array($plans['plans'])) {
            $plans = $plans['plans'];
        } elseif (!array_is_list($plans)) {
            $plans = array_values(array_filter($plans, 'is_array'));
        }

        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planId = $plan['planId'] ?? $plan['id'] ?? null;
            if (!$planId) {
                continue;
            }

            $status = strtolower((string)($plan['status'] ?? ''));
            if ($status === '' || in_array($status, ['active', 'enabled'], true)) {
                return $planId;
            }
        }

        foreach ($plans as $plan) {
            if (is_array($plan)) {
                $planId = $plan['planId'] ?? $plan['id'] ?? null;
                if ($planId) {
                    return $planId;
                }
            }
        }

        return null;
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

    private function gatherInitialResources(string $businessId): void
    {
        foreach ($this->serviceFactory->onboardingResources($businessId) as $service) {
            $this->syncResourceCollection($businessId, $service);
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

        $normalized = $this->normalizeResourceItems($raw);

        foreach ($normalized['items'] as $item) {
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

        $this->recordResourceLinks($service, $businessId, $normalized['links']);
    }

    /**
     * Split a mixed resource payload into a list of entity payloads and pagination links.
     *
     * @param array $raw
     * @return array{items: array<int, array<mixed>>, links: array<int, array<mixed>>}
     */
    private function normalizeResourceItems(array $raw): array
    {
        $items = [];
        $links = [];

        if (array_is_list($raw)) {
            foreach ($raw as $value) {
                if (is_array($value)) {
                    $items[] = $value;
                }
            }

            return [
                'items' => $items,
                'links' => $links,
            ];
        }

        foreach ($raw as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if ($key === 'links') {
                foreach ($value as $link) {
                    if (is_array($link)) {
                        $links[] = $link;
                    }
                }

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

        return [
            'items' => $items,
            'links' => $links,
        ];
    }

    private function recordResourceLinks(object $service, string $businessId, array $links): void
    {
        if (empty($links)) {
            return;
        }

        $resourceClass = get_class($service);

        $this->context->getLog()->info(
            sprintf(
                'CallbackService::syncResourceCollection: captured %d pagination link(s) for %s',
                count($links),
                $resourceClass
            ),
            [
                'businessId' => $businessId,
                'links' => $links,
            ]
        );
    }
}
