#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigApp;
use App\Config\ConfigDatabase;
use App\Core\Api;
use App\Core\Context;
use App\Http\GuzzleClientFactory;
use App\Services\BusinessService;
use App\Services\ServiceFactory;
use App\Services\SubscriptionService;
use App\Services\Support\LoggerFactory;
use App\Services\Support\PoyntDataFormatter as Format;
use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../public/bootstrap.php';

/**
 * Script to perform subscription workflow previously handled inside the OAuth callback.
 *
 * Usage:
 *  php scripts/subscription_workflow.php --business=<BUSINESS_ID> [--store=<STORE_ID>] \
 *      [--app-token-json='{"accessToken":"..."}'] [--merchant-token-json='{"accessToken":"..."}'] \
 *      [--plan-id=<PLAN_ID>] [--plan-name=<PLAN_NAME>] [--trial-expires="2024-12-31T00:00:00Z"]
 */
final class SubscriptionWorkflow
{
    private Context $context;
    private ServiceFactory $serviceFactory;

    public function __construct(Context $context, ServiceFactory $serviceFactory)
    {
        $this->context = $context;
        $this->serviceFactory = $serviceFactory;
    }

    public function run(
        string $businessId,
        ?string $storeId,
        array $appToken,
        array $merchantToken,
        ?string $requestedPlanId,
        ?string $requestedPlanName,
        ?DateTimeImmutable $trialExpiry
    ): bool {
        $appAccessToken = $this->extractAccessToken($appToken);
        $merchantAccessToken = $this->extractAccessToken($merchantToken);

        $effectiveTrialExpiry = $trialExpiry ?? $this->bootstrapBusinessTrial($businessId, $storeId);

        return $this->synchronizeStoresAndSubscriptions(
            $businessId,
            $appAccessToken,
            $merchantAccessToken,
            $requestedPlanId,
            $requestedPlanName,
            $effectiveTrialExpiry
        );
    }

    private function bootstrapBusinessTrial(string $businessId, ?string $storeId): ?DateTimeImmutable
    {
        /** @var BusinessService $businessService */
        $businessService = $this->serviceFactory->business($businessId);
        $trialState = $businessService->getTrialState($businessId);
        $existingExpiry = $this->normalizeTrialExpiry($trialState['expiresAt'] ?? null);

        if ($existingExpiry !== null) {
            return $existingExpiry;
        }

        $trialExpiry = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval(sprintf('P%dD', SubscriptionService::DEFAULT_TRIAL_DAYS)));

        $payload = $businessService->fetchBusiness($businessId);
        if (!is_array($payload)) {
            $payload = [
                'id' => $businessId,
                'legalName' => $businessId,
            ];
        }

        $payload['trialEligible'] = true;
        $payload['trialExpiresAt'] = $trialExpiry;

        $businessService->upsert($payload);

        $this->recordPlanDecision(
            $businessId,
            $storeId,
            'free_trial',
            'SubscriptionWorkflow',
            'Initialized business-level trial window.',
            ['trialExpiresAt' => $trialExpiry->format(DateTimeInterface::ATOM)]
        );

        return $trialExpiry;
    }

    private function synchronizeStoresAndSubscriptions(
        string $businessId,
        ?string $appAccessToken,
        ?string $merchantAccessToken,
        ?string $requestedPlanId,
        ?string $requestedPlanName,
        ?DateTimeImmutable $businessTrialExpiresAt
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
                $planId,
                $businessTrialExpiresAt
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
        ?string $defaultPlanId,
        ?DateTimeImmutable $businessTrialExpiresAt
    ): bool {
        $subscriptionService = $this->serviceFactory->subscription($businessId, $storeId);

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

        $trialExpiry = $businessTrialExpiresAt;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $trialActive = $trialExpiry !== null && $trialExpiry > $now;
        $trialExpired = $trialExpiry !== null && $trialExpiry <= $now;

        if (!empty($matchingSubscriptions) || !empty($localSubscriptions)) {
            if ($trialActive) {
                $planId = $this->resolvePlanIdFromSubscriptions($matchingSubscriptions, $localSubscriptions) ?? 'free_trial';
                $this->recordPlanDecision(
                    $businessId,
                    $storeId,
                    $planId,
                    'SubscriptionWorkflow',
                    'Business trial active; retaining existing subscription.',
                    ['trialExpiresAt' => $trialExpiry?->format(DateTimeInterface::ATOM)]
                );
            }

            return true;
        }

        if ($trialActive) {
            try {
                $subscriptionId = $subscriptionService->startFreeTrial($businessId, $storeId, 'free_trial', $trialExpiry);
                $this->recordPlanDecision(
                    $businessId,
                    $storeId,
                    'free_trial',
                    'SubscriptionWorkflow',
                    'Business trial active; assigning free trial plan to store.',
                    ['subscriptionId' => $subscriptionId, 'trialExpiresAt' => $trialExpiry->format(DateTimeInterface::ATOM)]
                );
            } catch (Throwable $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to start business-aligned free trial for business %s store %s: %s',
                        $businessId,
                        $storeId,
                        $e->getMessage()
                    )
                );

                return false;
            }

            return true;
        }

        if ($trialExpired) {
            $this->recordPlanDecision(
                $businessId,
                $storeId,
                $defaultPlanId ?? 'none',
                'SubscriptionWorkflow',
                'Business trial expired; blocking until paid plan is provided.',
                ['trialExpiresAt' => $trialExpiry->format(DateTimeInterface::ATOM)]
            );

            if (!$defaultPlanId) {
                $this->context->getLog()->warning(
                    sprintf(
                        'Business %s store %s cannot continue: trial expired and no plan specified.',
                        $businessId,
                        $storeId
                    )
                );

                return false;
            }
        }

        if ($trialExpired && (!$appAccessToken || !$merchantAccessToken)) {
            $this->context->getLog()->warning(
                sprintf(
                    'Business %s store %s cannot create paid subscription: tokens missing after trial expiry.',
                    $businessId,
                    $storeId
                )
            );

            return false;
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
                $subscriptionService->startFreeTrial($businessId, $storeId, 'free_trial', $trialExpiry);
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

            return $this->storeHasSubscription($businessId, $storeId) === true;
        }

        $created = $subscriptionService->createSubscription(
            $merchantAccessToken,
            $businessId,
            $storeId,
            $defaultPlanId
        );

        return $created !== [];
    }

    private function resolvePlanIdFromSubscriptions(array $remoteSubscriptions, array $localSubscriptions): ?string
    {
        foreach ([$remoteSubscriptions, $localSubscriptions] as $collection) {
            foreach ($collection as $subscription) {
                if (!is_array($subscription)) {
                    continue;
                }

                $planId = $subscription['planId'] ?? $subscription['plan_id'] ?? null;
                if (is_string($planId) && trim($planId) !== '') {
                    return $planId;
                }
            }
        }

        return null;
    }

    private function recordPlanDecision(
        string $businessId,
        ?string $storeId,
        string $planId,
        string $actor,
        string $reason,
        array $metadata = []
    ): void {
        try {
            $this->context->getConn()->insert(
                'subscription_plan_audit',
                [
                    'business_id' => $businessId,
                    'store_id' => $storeId,
                    'plan_id' => $planId,
                    'decided_by' => $actor,
                    'decision_reason' => $reason,
                    'metadata' => Format::jsonObject($metadata),
                ]
            );
        } catch (Throwable $e) {
            $this->context->getLog()->warning(
                sprintf(
                    'Failed to record subscription decision for business %s store %s: %s',
                    $businessId,
                    $storeId ?? 'n/a',
                    $e->getMessage()
                )
            );
        }
    }

    public function normalizeTrialExpiry(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            try {
                return new DateTimeImmutable($trimmed);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public function normalizePlanParameter(mixed $value): ?string
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

    /**
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

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function getLocalSubscriptionsForStore(string $businessId, string $storeId): ?array
    {
        try {
            $rows = $this->context->getConn()->fetchAllAssociative(
                'SELECT subscription_id, plan_id, status FROM subscription WHERE business_id = ? AND store_id = ?',
                [$businessId, $storeId]
            );
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to load local subscriptions for business %s store %s: %s',
                    $businessId,
                    $storeId,
                    $e->getMessage()
                )
            );

            return null;
        }

        return is_array($rows) ? $rows : [];
    }

    private function logSubscriptionComparison(
        string $businessId,
        string $storeId,
        array $remoteSubscriptions,
        array $localSubscriptions
    ): void {
        $remoteIds = $this->extractRemoteSubscriptionIds($remoteSubscriptions);
        $localIds = $this->extractLocalSubscriptionIds($localSubscriptions);

        sort($remoteIds);
        sort($localIds);

        if ($remoteIds === $localIds) {
            return;
        }

        $missingLocally = array_values(array_diff($remoteIds, $localIds));
        if (!empty($missingLocally)) {
            $this->context->getLog()->warning(
                sprintf(
                    'Remote subscription(s) missing locally for business %s store %s.',
                    $businessId,
                    $storeId
                ),
                [
                    'remoteOnlySubscriptionIds' => $missingLocally,
                ]
            );
        }

        $missingRemotely = array_values(array_diff($localIds, $remoteIds));
        if (!empty($missingRemotely)) {
            $this->context->getLog()->warning(
                sprintf(
                    'Local subscription(s) missing from Poynt for business %s store %s.',
                    $businessId,
                    $storeId
                ),
                [
                    'localOnlySubscriptionIds' => $missingRemotely,
                ]
            );
        }
    }

    /**
     * @param array<int, array<mixed>> $remoteSubscriptions
     * @return array<int, string>
     */
    private function extractRemoteSubscriptionIds(array $remoteSubscriptions): array
    {
        $ids = [];

        foreach ($remoteSubscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            $id = $subscription['subscriptionId'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $localSubscriptions
     * @return array<int, string>
     */
    private function extractLocalSubscriptionIds(array $localSubscriptions): array
    {
        $ids = [];

        foreach ($localSubscriptions as $subscription) {
            $id = $subscription['subscription_id'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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
}

$options = getopt('', [
    'business:',
    'store::',
    'app-token-json::',
    'merchant-token-json::',
    'plan-id::',
    'plan-name::',
    'trial-expires::',
]);

if (!isset($options['business']) || $options['business'] === '') {
    fwrite(STDERR, "Usage: php scripts/subscription_workflow.php --business=<BUSINESS_ID> [--store=<STORE_ID>] [--app-token-json=<JSON>] [--merchant-token-json=<JSON>] [--plan-id=<PLAN_ID>] [--plan-name=<PLAN_NAME>] [--trial-expires=<ISO_DATETIME>]\\n");
    exit(1);
}

$businessId = $options['business'];
$storeId = $options['store'] ?? null;
$appToken = isset($options['app-token-json']) ? json_decode((string) $options['app-token-json'], true) ?: [] : [];
$merchantToken = isset($options['merchant-token-json']) ? json_decode((string) $options['merchant-token-json'], true) ?: [] : [];
$requestedPlanId = isset($options['plan-id']) ? trim((string) $options['plan-id']) : null;
$requestedPlanName = isset($options['plan-name']) ? trim((string) $options['plan-name']) : null;
$trialExpiryRaw = $options['trial-expires'] ?? null;

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

$logConnection = DriverManager::getConnection($connectionParams);
$logConnection->executeStatement("SET TIME ZONE '" . ConfigApp::$timezone . "'");

[$log, $requestId] = LoggerFactory::create($conn, $logConnection);

$api = new Api('', $log, $requestId);
$httpClientFactory = new GuzzleClientFactory();
$context = new Context($api, $conn, $log, $httpClientFactory);
$serviceFactory = new ServiceFactory($context);

$workflow = new SubscriptionWorkflow($context, $serviceFactory);
$trialExpiry = $workflow->normalizeTrialExpiry($trialExpiryRaw);

if ($workflow->run(
    $businessId,
    $storeId,
    is_array($appToken) ? $appToken : [],
    is_array($merchantToken) ? $merchantToken : [],
    $workflow->normalizePlanParameter($requestedPlanId),
    $workflow->normalizePlanParameter($requestedPlanName),
    $trialExpiry
)) {
    fwrite(STDOUT, "Subscription workflow completed successfully\n");
    exit(0);
}

fwrite(STDERR, "Subscription workflow failed\n");
exit(1);
