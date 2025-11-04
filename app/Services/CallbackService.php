<?php

namespace App\Services;

use App\Core\Context;
use App\Core\Response;
use App\Modules\OAuth\PlatformRegistry;
use App\Modules\OAuth\OAuthHandlerInterface;
use DateTime;
use DateTimeInterface;
use RuntimeException;
use Throwable;

class CallbackService
{
    private Context $context;
    private PlatformRegistry $platformRegistry;
    private ServiceFactory $serviceFactory;
    private OnboardingResourceGatherer $resourceGatherer;

    public function __construct(
        Context $context,
        PlatformRegistry $platformRegistry,
        ?ServiceFactory $serviceFactory = null,
        ?OnboardingResourceGatherer $resourceGatherer = null
    ) {
        $this->context = $context;
        $this->platformRegistry = $platformRegistry;
        $this->serviceFactory = $serviceFactory ?? new ServiceFactory($context);
        $this->resourceGatherer = $resourceGatherer ?? new OnboardingResourceGatherer($context, $this->serviceFactory);
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

        $requestedPlanId = $this->normalizePlanParameter($this->context->getApi()->getParam('planId'));
        $requestedPlanName = $this->normalizePlanParameter($this->context->getApi()->getParam('planName'));

        $workflowSucceeded = $this->runBusinessWorkflow(
            $handler->getBusinessId(),
            $handler->getStoreId(),
            $tokenResult['appToken'] ?? [],
            $tokenResult['merchantToken'] ?? [],
            $requestedPlanId,
            $requestedPlanName
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
        mixed $merchantToken,
        ?string $requestedPlanId,
        ?string $requestedPlanName
    ): bool {

        if (!$businessId) {
            $this->context->getLog()->warning('Skipping onboarding sync: missing businessId in callback response.');

            return false;
        }

        $this->context->getLog()->info(
            sprintf('CallbackService::runBusinessWorkflow starting for business %s.', $businessId),
            [
                'businessId' => $businessId,
                'storeId' => $storeId,
                'requestedPlanId' => $requestedPlanId,
                'requestedPlanName' => $requestedPlanName,
            ]
        );

        $conn = $this->context->getConn();
        $transactionStarted = false;

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            $this->context->getLog()->debug(
                sprintf('CallbackService::runBusinessWorkflow transaction started for business %s.', $businessId)
            );

            if ($this->installationExists($businessId)) {
                $this->context->getLog()->info(
                    sprintf('Existing installation detected for business %s, reactivating.', $businessId)
                );
                $this->reactivateInstallation($businessId);
            } else {
                $this->context->getLog()->info(
                    sprintf('No existing installation found for business %s, attempting trial start if necessary.', $businessId)
                );
                $this->startTrialIfMissing($businessId, $storeId);
            }

            $appAccessToken = $this->extractAccessToken($appToken);
            $merchantAccessToken = $this->extractAccessToken($merchantToken);

            $this->context->getLog()->debug(
                sprintf('CallbackService::runBusinessWorkflow extracted access tokens for business %s.', $businessId),
                [
                    'appTokenPresent' => $appAccessToken !== null,
                    'merchantTokenPresent' => $merchantAccessToken !== null,
                ]
            );

            if (!$this->synchronizeStoresAndSubscriptions(
                $businessId,
                $appAccessToken,
                $merchantAccessToken,
                $requestedPlanId,
                $requestedPlanName
            )) {
                throw new RuntimeException(
                    sprintf('Failed to synchronize stores or subscriptions for business %s.', $businessId)
                );
            }

            $this->context->getLog()->info(
                sprintf('CallbackService::runBusinessWorkflow completed store/subscription sync for business %s.', $businessId)
            );

            $gatherResult = $this->resourceGatherer->gatherWithSummary($businessId);

            if (!$gatherResult['success']) {
                $this->context->getLog()->error(
                    sprintf('Failed to gather onboarding resources for business %s.', $businessId),
                    ['summary' => $gatherResult]
                );
                throw new RuntimeException(
                    sprintf('Failed to gather onboarding resources for business %s.', $businessId)
                );
            }

            $conn->commit();

            $this->context->getLog()->info(
                sprintf('CallbackService::runBusinessWorkflow finished successfully for business %s.', $businessId)
            );

            return true;
        } catch (Throwable $e) {
            $this->logWorkflowFailureRootCause($businessId, $e);

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

    private function logWorkflowFailureRootCause(?string $businessId, Throwable $exception): void
    {
        $rootCause = $this->extractRootCause($exception);

        $message = sprintf(
            'CallbackService::runBusinessWorkflow root cause for business %s: %s (%s:%d)',
            $businessId ?? 'unknown',
            $rootCause->getMessage(),
            $rootCause->getFile(),
            $rootCause->getLine()
        );

        $context = [
            'exception' => $exception,
            'rootCauseClass' => get_class($rootCause),
            'rootCauseTrace' => $rootCause->getTraceAsString(),
        ];

        $this->context->getLog()->critical($message, $context);

        error_log($message . PHP_EOL . $rootCause->getTraceAsString());
    }

    private function extractRootCause(Throwable $exception): Throwable
    {
        $rootCause = $exception;

        while ($rootCause->getPrevious() instanceof Throwable) {
            $rootCause = $rootCause->getPrevious();
        }

        return $rootCause;
    }

    private function installationExists(string $businessId): bool
    {
        try {
            $existing = $this->context->getConn()->fetchOne(
                'SELECT 1 FROM business WHERE business_id = ? AND active = TRUE ORDER BY updated_at DESC LIMIT 1',
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
        ?string $merchantAccessToken,
        ?string $requestedPlanId,
        ?string $requestedPlanName
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
        $planId = $requestedPlanId;

        if ($appAccessToken && $planId === null) {
            $plans = $subscriptionService->fetchPlans($appAccessToken);
            if ($plans === null) {
                return false;
            }

            if ($requestedPlanName !== null) {
                $planId = $this->findPlanIdByName($plans, $requestedPlanName);

                if ($planId === null) {
                    $this->context->getLog()->warning(
                        sprintf(
                            'Requested subscription plan "%s" not found for business %s; falling back to default plan.',
                            $requestedPlanName,
                            $businessId
                        )
                    );
                }
            }

            if ($planId === null) {
                $planId = $this->selectDefaultPlanId($plans);
            }
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

        if (empty($matchingSubscriptions) && $merchantAccessToken) {
            $existing = $subscriptionService->fetchMerchantSubscriptions($merchantAccessToken, $businessId, $storeId);
            if ($existing === null) {
                return false;
            }
            $matchingSubscriptions = $this->filterSubscriptionsForStore($existing, $storeId);

            foreach ($matchingSubscriptions as $subscription) {
                if (is_array($subscription)) {
                    $subscriptionService->upsertLocalSubscription($subscription, $storeId);
                }
            }
        }

        $localSubscriptions = $this->getLocalSubscriptionsForStore($businessId, $storeId);

        if ($localSubscriptions === null) {
            return false;
        }

        $this->logSubscriptionComparison(
            $businessId,
            $storeId,
            $matchingSubscriptions,
            $localSubscriptions
        );

        if (!empty($matchingSubscriptions)) {
            return true;
        }

        if (!empty($localSubscriptions)) {
            return true;
        }

        if (!$appAccessToken || !$merchantAccessToken || !$defaultPlanId) {
            $this->context->getLog()->warning(
                sprintf(
                    'Unable to create subscription for business %s store %s: missing app token, merchant token, or plan.',
                    $businessId,
                    $storeId
                )
            );

            try {
                $fallbackSubscriptionId = $subscriptionService->startFreeTrial($businessId, $storeId);
                $this->context->getLog()->info(
                    sprintf(
                        'Started local free trial %s for business %s store %s as a fallback.',
                        $fallbackSubscriptionId,
                        $businessId,
                        $storeId
                    )
                );
            } catch (Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to create fallback trial subscription for business %s store %s: %s',
                        $businessId,
                        $storeId,
                        $e->getMessage()
                    )
                );

                return false;
            }

            $hasSubscription = $this->storeHasSubscription($businessId, $storeId);

            return $hasSubscription === true;
        }

        $created = $subscriptionService->createSubscription(
            $merchantAccessToken,
            $businessId,
            $storeId,
            $defaultPlanId
        );

        return $created !== [];
    }

    private function normalizePlanParameter(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectPlanCandidates(?array $plans): array
    {
        if (!is_array($plans) || $plans === []) {
            return [];
        }

        $queue = [$plans];
        $candidates = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (!is_array($current) || $current === []) {
                continue;
            }

            $identifier = $current['planId'] ?? $current['id'] ?? null;
            if ((is_string($identifier) || is_numeric($identifier)) && (string) $identifier !== '') {
                $id = (string) $identifier;
                if (!isset($candidates[$id])) {
                    $candidates[$id] = $current;
                } else {
                    $candidates[$id] = array_merge($candidates[$id], $current);
                }
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return array_values($candidates);
    }

    private function extractPlanId(array $plan): ?string
    {
        $identifier = $plan['planId'] ?? $plan['id'] ?? null;

        if (is_string($identifier) || is_numeric($identifier)) {
            $value = trim((string) $identifier);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function findPlanIdByName(?array $plans, string $requestedPlanName): ?string
    {
        $requested = strtolower($requestedPlanName);
        if ($requested === '') {
            return null;
        }

        foreach ($this->collectPlanCandidates($plans) as $plan) {
            $planId = $this->extractPlanId($plan);
            if ($planId === null) {
                continue;
            }

            $name = isset($plan['name']) ? strtolower((string) $plan['name']) : null;
            if ($name === $requested || strtolower($planId) === $requested) {
                return $planId;
            }
        }

        return null;
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
        $allCandidates = $this->collectPlanCandidates($plans);

        if ($allCandidates === []) {
            return null;
        }

        $storeScoped = array_filter($allCandidates, function (array $plan): bool {
            return $this->isStoreScopedPlan($plan);
        });

        $candidates = $storeScoped !== [] ? array_values($storeScoped) : $allCandidates;

        foreach ($candidates as $plan) {
            $planId = $this->extractPlanId($plan);
            if ($planId === null) {
                continue;
            }

            $status = strtolower((string) ($plan['status'] ?? ''));
            if ($status === '' || in_array($status, ['active', 'enabled'], true)) {
                return $planId;
            }
        }

        foreach ($candidates as $plan) {
            $planId = $this->extractPlanId($plan);
            if ($planId !== null) {
                return $planId;
            }
        }

        return null;
    }

    public function purgeAndReinstall(
        string $businessId,
        ?string $storeId,
        array $appTokenPayload,
        array $merchantTokenPayload
    ): bool {
        $normalizedAppToken = $this->normalizeTokenPayload($appTokenPayload);
        $normalizedMerchantToken = $this->normalizeTokenPayload($merchantTokenPayload);

        $appAccessToken = $this->extractAccessToken($normalizedAppToken);
        if ($appAccessToken === null) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeAndReinstall aborted for business %s: missing accessToken in app token payload.',
                    $businessId
                )
            );

            return false;
        }

        if (!isset($normalizedAppToken['refreshToken']) || !isset($normalizedAppToken['expiresIn'])) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeAndReinstall aborted for business %s: incomplete app token payload.',
                    $businessId
                )
            );

            return false;
        }

        $merchantAccessToken = $this->extractAccessToken($normalizedMerchantToken);
        if ($merchantAccessToken === null) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeAndReinstall aborted for business %s: missing accessToken in merchant token payload.',
                    $businessId
                )
            );

            return false;
        }

        if (!isset($normalizedMerchantToken['refreshToken']) || !isset($normalizedMerchantToken['expiresIn'])) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeAndReinstall aborted for business %s: incomplete merchant token payload.',
                    $businessId
                )
            );

            return false;
        }

        if (!$this->purgeBusinessInstallation($businessId, false)) {
            return false;
        }

        try {
            $tokenService = $this->serviceFactory->token();
            $tokenService->saveAppToken($businessId, $normalizedAppToken);
            $tokenService->saveMerchantToken($businessId, $normalizedMerchantToken);
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeAndReinstall failed to persist tokens for business %s: %s',
                    $businessId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );

            return false;
        }

        return $this->runBusinessWorkflow(
            $businessId,
            $storeId,
            $normalizedAppToken,
            $normalizedMerchantToken,
            null,
            null
        );
    }

    /**
     * Ensure token payloads contain camelCase keys expected by TokenService.
     */
    private function normalizeTokenPayload(array $token): array
    {
        $normalized = $token;

        if (!isset($normalized['accessToken']) && isset($token['access_token'])) {
            $normalized['accessToken'] = $token['access_token'];
        }

        if (!isset($normalized['refreshToken']) && isset($token['refresh_token'])) {
            $normalized['refreshToken'] = $token['refresh_token'];
        }

        if (!isset($normalized['expiresIn']) && isset($token['expires_in'])) {
            $normalized['expiresIn'] = $token['expires_in'];
        }

        if (!isset($normalized['expiresIn'])) {
            foreach (['expiresAt', 'expires_at'] as $key) {
                if (!array_key_exists($key, $token)) {
                    continue;
                }

                $dateTime = $this->coerceExpiresAtToDateTime($token[$key]);
                if ($dateTime !== null) {
                    $normalized['expiresIn'] = $dateTime;
                    break;
                }
            }
        }

        return $normalized;
    }

    private function coerceExpiresAtToDateTime(mixed $value): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            return DateTime::createFromInterface($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTime($value);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public function purgeBusinessInstallation(string $businessId, bool $preserveTokens = true): bool
    {
        $this->context->getLog()->info(
            sprintf('CallbackService::purgeBusinessInstallation starting for business %s.', $businessId)
        );

        $conn = $this->context->getConn();
        $transactionStarted = false;

        $deleteStatements = [
            'DELETE FROM hook_delivery WHERE business_id = :biz',
            'DELETE FROM hook WHERE business_id = :biz',
            'DELETE FROM paylink_link WHERE business_id = :biz',
            'DELETE FROM paylink_payment WHERE business_id = :biz',
            'DELETE FROM paylink_item WHERE business_id = :biz',
            'DELETE FROM paylink WHERE business_id = :biz',
            'DELETE FROM order_item USING "order" WHERE order_item.order_id = "order".order_id AND "order".business_id = :biz',
            'DELETE FROM order_history USING "order" WHERE order_history.order_id = "order".order_id AND "order".business_id = :biz',
            'DELETE FROM order_shipment USING "order" WHERE order_shipment.order_id = "order".order_id AND "order".business_id = :biz',
            'DELETE FROM "order" WHERE business_id = :biz',
            'DELETE FROM transaction WHERE business_id = :biz',
            'DELETE FROM subscription WHERE business_id = :biz',
            'DELETE FROM variant_inventory WHERE business_id = :biz',
            'DELETE FROM inventory WHERE business_id = :biz',
            'DELETE FROM inventory_summary WHERE business_id = :biz',
            'DELETE FROM product_variant WHERE product_id IN (SELECT product_id FROM product WHERE business_id = :biz)',
            'DELETE FROM catalog_product WHERE catalog_id IN (SELECT catalog_id FROM catalog WHERE business_id = :biz)',
            'DELETE FROM catalog_product WHERE product_id IN (SELECT product_id FROM product WHERE business_id = :biz)',
            'DELETE FROM product WHERE business_id = :biz',
            'DELETE FROM category WHERE business_id = :biz',
            'DELETE FROM catalog WHERE business_id = :biz',
            'DELETE FROM tax WHERE business_id = :biz',
            'DELETE FROM business_user WHERE business_id = :biz',
            'DELETE FROM customer WHERE business_id = :biz',
            'DELETE FROM terminal WHERE store_id IN (SELECT store_id FROM store WHERE business_id = :biz)',
            'DELETE FROM store WHERE business_id = :biz',
            'DELETE FROM token_refresh_log WHERE business_id = :biz',
        ];

        if (!$preserveTokens) {
            $deleteStatements[] = 'DELETE FROM app_token WHERE business_id = :biz';
            $deleteStatements[] = 'DELETE FROM merchant_token WHERE business_id = :biz';
        }

        $deleteStatements[] = 'DELETE FROM business WHERE business_id = :biz';

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            foreach ($deleteStatements as $statement) {
                $conn->executeStatement($statement, ['biz' => $businessId]);
            }

            $conn->commit();

            $this->context->getLog()->info(
                sprintf('CallbackService::purgeBusinessInstallation finished for business %s.', $businessId)
            );

            return true;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                try {
                    if (method_exists($conn, 'isTransactionActive') && $conn->isTransactionActive()) {
                        $conn->rollBack();
                    } else {
                        $conn->rollBack();
                    }
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
                sprintf(
                    'CallbackService::purgeBusinessInstallation failed for business %s: %s',
                    $businessId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );

            return false;
        }
    }

    private function isStoreScopedPlan(array $plan): bool
    {
        $rawScope = null;

        foreach (['scope', 'scopeType', 'scope_type'] as $key) {
            $value = $plan[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $rawScope = strtolower(trim((string) $value));
                if ($rawScope !== '') {
                    break;
                }
            }
        }

        return $rawScope === 'store';
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
}
