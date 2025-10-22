<?php

namespace App\Services;

use App\Core\Context;
use App\Core\Response;
use App\Modules\OAuth\PlatformRegistry;
use App\Modules\OAuth\OAuthHandlerInterface;
use RuntimeException;
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
     * Handle the OAuth callback lifecycle for a platform:
     *   1. Resolve the platform-specific handler.
     *   2. Retrieve and persist OAuth tokens.
     *   3. Execute the onboarding workflow for the business.
     *   4. Register any required webhooks.
     */
    public function handle(string $platform): array
    {
        $handlerResult = $this->resolveHandler($platform);
        if (isset($handlerResult['error'])) {
            return [
                'success' => false,
                'status' => Response::STATUS_BAD_REQUEST,
                'error' => $handlerResult['error'],
            ];
        }

        /** @var OAuthHandlerInterface $handler */
        $handler = $handlerResult['handler'];

        $tokenResult = $this->acquireTokens($handler);
        if (!($tokenResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $tokenResult['status'] ?? Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => $tokenResult['error'] ?? 'Token exchange failed.',
            ];
        }

        $workflowSucceeded = $this->runBusinessWorkflow(
            $handler->getBusinessId(),
            $handler->getStoreId(),
            $tokenResult['appToken'] ?? [],
            $tokenResult['merchantToken'] ?? []
        );

        if (!$workflowSucceeded) {
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Failed to synchronize business during onboarding.',
            ];
        }

        $handler->registerWebhooks();

        return [
            'success' => true,
            'status' => Response::STATUS_OK,
            'message' => 'Callback handled',
        ];
    }

    /**
     * Resolve the correct platform handler or capture the failure reason.
     *
     * @return array{handler: OAuthHandlerInterface}|array{error: string}
     */
    private function resolveHandler(string $platform): array
    {
        try {
            return ['handler' => $this->platformRegistry->getHandler($platform)];
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve and persist the OAuth tokens for the platform handler.
     *
     * @return array{
     *     success: bool,
     *     appToken?: mixed,
     *     merchantToken?: mixed,
     *     status?: int,
     *     error?: string
     * }
     */
    private function acquireTokens(OAuthHandlerInterface $handler): array
    {
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

        return [
            'success' => true,
            'appToken' => $appToken,
            'merchantToken' => $merchantToken,
        ];
    }

    /**
     * Execute the onboarding workflow for the business if identifiers are present.
     */
    private function runBusinessWorkflow(
        ?string $businessId,
        ?string $storeId,
        mixed $appToken,
        mixed $merchantToken
    ): bool {
        if (!$businessId) {
            $this->context->getLog()->warning('Skipping onboarding sync: missing businessId in callback response.');

            return false;
        }

        $conn = $this->context->getConn();
        $transactionStarted = false;

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            if ($this->installationExists($businessId)) {
                $this->reactivateInstallation($businessId);
            } else {
                $this->startTrialIfMissing($businessId, $storeId);
            }

            $appAccessToken = $this->extractAccessToken($appToken);
            $merchantAccessToken = $this->extractAccessToken($merchantToken);

            if (!$this->synchronizeStoresAndSubscriptions($businessId, $appAccessToken, $merchantAccessToken)) {
                throw new RuntimeException(
                    sprintf('Failed to synchronize stores or subscriptions for business %s.', $businessId)
                );
            }

            if (!$this->gatherInitialResources($businessId)) {
                throw new RuntimeException(
                    sprintf('Failed to gather onboarding resources for business %s.', $businessId)
                );
            }

            $conn->commit();

            return true;
        } catch (Throwable $e) {
            if ($transactionStarted && method_exists($conn, 'isTransactionActive') && $conn->isTransactionActive()) {
                $conn->rollBack();
            } elseif ($transactionStarted) {
                // Doctrine < 3.3 does not expose isTransactionActive; attempt rollback regardless.
                try {
                    $conn->rollBack();
                } catch (Throwable $rollbackError) {
                    $this->context->getLog()->warning(
                        sprintf(
                            'CallbackService::runBusinessWorkflow rollback warning for business %s: %s',
                            $businessId,
                            $rollbackError->getMessage()
                        )
                    );
                }
            }

            $this->context->getLog()->error(
                sprintf('CallbackService::runBusinessWorkflow failed for business %s: %s', $businessId, $e->getMessage()),
                ['exception' => $e]
            );

            if ($businessId) {
                $this->purgeBusinessInstallation($businessId, true);
            }

            return false;
        }
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
    ): bool {
        $storeService = $this->serviceFactory->store($businessId);
        $storesPayload = $storeService->fetchByBusinessId($businessId);

        if ($storesPayload === false) {
            return false;
        }

        if (!is_array($storesPayload) || $storesPayload === []) {
            return true;
        }

        $normalizedStores = $this->normalizeResourceItems($storesPayload);
        if (empty($normalizedStores['items'])) {
            return true;
        }

        $subscriptionService = $this->serviceFactory->subscription($businessId);
        $planId = null;

        if ($appAccessToken) {
            $plans = $subscriptionService->fetchPlans($appAccessToken);
            if ($plans === null) {
                return false;
            }
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

            if ($storeService->upsert($storeData) === false) {
                return false;
            }

            if (!$this->ensureStoreSubscription(
                $businessId,
                $storeId,
                $appAccessToken,
                $merchantAccessToken,
                $planId
            )) {
                return false;
            }
        }

        return true;
    }

    private function ensureStoreSubscription(
        string $businessId,
        string $storeId,
        ?string $appAccessToken,
        ?string $merchantAccessToken,
        ?string $defaultPlanId
    ): bool {
        $subscriptionService = $this->serviceFactory->subscription($businessId, $storeId);

        $existing = [];
        $matchingSubscriptions = [];

        if ($appAccessToken) {
            $existing = $subscriptionService->fetchSubscriptions($appAccessToken, $businessId, $storeId);
            if ($existing === null) {
                return false;
            }
            $matchingSubscriptions = $this->filterSubscriptionsForStore($existing, $storeId);
        }

        if (empty($matchingSubscriptions) && $merchantAccessToken) {
            $existing = $subscriptionService->fetchMerchantSubscriptions($merchantAccessToken, $businessId, $storeId);
            if ($existing === null) {
                return false;
            }
            $matchingSubscriptions = $this->filterSubscriptionsForStore($existing, $storeId);

            foreach ($matchingSubscriptions as $subscription) {
                if (is_array($subscription)) {
                    $subscriptionService->upsertLocalSubscription($subscription);
                }
            }
        }

        if (!empty($matchingSubscriptions)) {
            return true;
        }

        $localSubscriptionExists = $this->storeHasSubscription($businessId, $storeId);

        if ($localSubscriptionExists === null) {
            $this->context->getLog()->warning(
                sprintf(
                    'Unable to verify existing subscription for business %s store %s due to lookup failure.',
                    $businessId,
                    $storeId
                )
            );

            return false;
        }

        if ($localSubscriptionExists) {
            $this->context->getLog()->info(
                sprintf(
                    'Local subscription already present for business %s store %s, skipping remote creation.',
                    $businessId,
                    $storeId
                )
            );

            return true;
        }

        if (!$appAccessToken || !$defaultPlanId) {
            $this->context->getLog()->warning(
                sprintf(
                    'Unable to create subscription for business %s store %s: missing app token or plan.',
                    $businessId,
                    $storeId
                )
            );

            return false;
        }

        $created = $subscriptionService->createSubscription(
            $appAccessToken,
            $businessId,
            $storeId,
            $defaultPlanId
        );

        return $created !== [];
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

        $existingSubscription = $this->storeHasSubscription($businessId, $storeId);

        if ($existingSubscription === null) {
            $this->context->getLog()->warning(
                sprintf(
                    'Skipping free trial creation for business %s store %s: unable to determine existing subscriptions.',
                    $businessId,
                    $storeId
                )
            );

            return;
        }

        if ($existingSubscription) {
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

    private function gatherInitialResources(string $businessId): bool
    {
        $allSuccessful = true;
        foreach ($this->serviceFactory->onboardingResources($businessId) as $service) {
            $result = $this->syncResourceCollection($businessId, $service);
            if (!$result) {
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
    }

    /**
     * @param object $service
     */
    private function syncResourceCollection(string $businessId, object $service): bool
    {
        if (!method_exists($service, 'fetchByBusinessId') || !method_exists($service, 'upsert')) {
            return true;
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
            return false;
        }

        if ($raw === false) {
            return false;
        }

        if (!is_array($raw) || empty($raw)) {
            return true;
        }

        $normalized = $this->normalizeResourceItems($raw);
        $allSuccessful = true;

        foreach ($normalized['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $result = $service->upsert($item);
                if ($result === false) {
                    $allSuccessful = false;
                }
            } catch (Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to persist onboarding resource for business %s: %s',
                        $businessId,
                        $e->getMessage()
                    )
                );
                $allSuccessful = false;
            }
        }

        $this->recordResourceLinks($service, $businessId, $normalized['links']);

        return $allSuccessful;
    }

    public function purgeAndReinstall(
        string $businessId,
        string $storeId,
        array $appToken,
        array $merchantToken
    ): bool {
        if (!$this->validateTokenPayload($appToken, 'app') || !$this->validateTokenPayload($merchantToken, 'merchant')) {
            return false;
        }

        $this->purgeBusinessInstallation($businessId, false);

        $tokenService = $this->serviceFactory->token();

        try {
            $tokenService->saveAppToken($businessId, $appToken);
            $tokenService->saveMerchantToken($businessId, $merchantToken);
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf('Failed to persist tokens during reinstall for business %s: %s', $businessId, $e->getMessage()),
                ['exception' => $e]
            );

            return false;
        }

        return $this->runBusinessWorkflow($businessId, $storeId, $appToken, $merchantToken);
    }

    /**
     * Remove all local data for a business.
     *
     * Tokens are preserved by default so a subsequent reinstall can reuse them.
     */
    public function purgeBusiness(string $businessId, bool $preserveTokens = true): void
    {
        $this->purgeBusinessInstallation($businessId, $preserveTokens);
    }

    private function purgeBusinessInstallation(string $businessId, bool $preserveTokens = true): void
    {
        $conn = $this->context->getConn();
        $transactionStarted = false;

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            $conn->executeStatement('DELETE FROM token_refresh_log WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM hook_delivery WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM hook WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM paylink WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM "order" WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM transaction WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM subscription WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM catalog_available_discount WHERE catalog_id IN (SELECT catalog_id FROM catalog WHERE business_id = :biz)', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM catalog_product WHERE catalog_id IN (SELECT catalog_id FROM catalog WHERE business_id = :biz)', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM catalog WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM variant_inventory WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM inventory WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM inventory_summary WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM product_variant WHERE product_id IN (SELECT product_id FROM product WHERE business_id = :biz)', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM product WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM category WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM tax WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM customer WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM business_user WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM terminal WHERE store_id IN (SELECT store_id FROM store WHERE business_id = :biz)', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM store WHERE business_id = :biz', ['biz' => $businessId]);

            if (!$preserveTokens) {
                $conn->executeStatement('DELETE FROM merchant_token WHERE business_id = :biz', ['biz' => $businessId]);
                $conn->executeStatement('DELETE FROM app_token WHERE business_id = :biz', ['biz' => $businessId]);
            }

            $conn->executeStatement('DELETE FROM business WHERE business_id = :biz', ['biz' => $businessId]);

            $conn->commit();
        } catch (Throwable $e) {
            if ($transactionStarted && method_exists($conn, 'isTransactionActive') && $conn->isTransactionActive()) {
                $conn->rollBack();
            } elseif ($transactionStarted) {
                try {
                    $conn->rollBack();
                } catch (Throwable $rollbackError) {
                    $this->context->getLog()->warning(
                        sprintf(
                            'CallbackService::purgeBusinessInstallation rollback warning for business %s: %s',
                            $businessId,
                            $rollbackError->getMessage()
                        )
                    );
                }
            }

            $this->context->getLog()->error(
                sprintf('CallbackService::purgeBusinessInstallation failed for business %s: %s', $businessId, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    private function validateTokenPayload(array $token, string $type): bool
    {
        $requiredKeys = ['accessToken', 'refreshToken', 'expiresIn'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $token)) {
                $this->context->getLog()->error(
                    sprintf('CallbackService::purgeAndReinstall missing %s key in %s token payload.', $key, $type)
                );

                return false;
            }
        }

        return true;
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

    /**
     * Determine whether the supplied subscription payload includes the given store ID.
     *
     * @param mixed $payload
     * @param string $storeId
     * @return array<int, array<mixed>>
     */
    private function filterSubscriptionsForStore(mixed $payload, string $storeId): array
    {
        if (!is_array($payload) || $payload === []) {
            return [];
        }

        $normalized = $this->normalizeResourceItems($payload);
        $matches = [];

        foreach ($normalized['items'] as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            $subscriptionStoreId = $this->resolveSubscriptionStoreId($subscription);

            if ($subscriptionStoreId === $storeId) {
                $matches[] = $subscription;
            }
        }

        return $matches;
    }

    private function resolveSubscriptionStoreId(array $subscription): ?string
    {
        $directStoreId = $subscription['storeId'] ?? null;
        if (is_string($directStoreId) && $directStoreId !== '') {
            return $directStoreId;
        }

        $embeddedStore = $subscription['store'] ?? null;
        if (is_array($embeddedStore)) {
            $embeddedId = $embeddedStore['id'] ?? $embeddedStore['storeId'] ?? null;
            if (is_string($embeddedId) && $embeddedId !== '') {
                return $embeddedId;
            }
        }

        return null;
    }

    private function storeHasSubscription(string $businessId, string $storeId): ?bool
    {
        try {
            $existing = $this->context->getConn()->fetchOne(
                'SELECT 1 FROM subscription WHERE business_id = ? AND store_id = ? LIMIT 1',
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

            return null;
        }

        return $existing !== false && $existing !== null;
    }
}
