<?php

namespace App\Services;

use App\Core\Context;
use App\Core\Response;
use App\Modules\OAuth\PlatformRegistry;
use App\Modules\OAuth\OAuthHandlerInterface;
use App\Services\Tenant\TenantProvisioningService;
use RuntimeException;
use Throwable;

class CallbackService
{
    private Context $context;
    private PlatformRegistry $platformRegistry;
    private ServiceFactory $serviceFactory;
    private TenantProvisioningService $tenantProvisioningService;

    public function __construct(
        Context $context,
        PlatformRegistry $platformRegistry,
        ?ServiceFactory $serviceFactory = null,
        ?TenantProvisioningService $tenantProvisioningService = null
    ) {
        $this->context = $context;
        $this->platformRegistry = $platformRegistry;
        $this->serviceFactory = $serviceFactory ?? new ServiceFactory($context);
        $this->tenantProvisioningService = $tenantProvisioningService ?? new TenantProvisioningService($context);
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

        if (!$this->prepareTenantStorage($handler->getBusinessId())) {
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Failed to prepare tenant storage for onboarding.',
            ];
        }

        $handler->storeTokens($tokenResult['appToken'] ?? [], $tokenResult['merchantToken'] ?? []);

        $workflowSucceeded = $this->runBusinessWorkflow(
            $handler->getBusinessId(),
            $handler->getStoreId()
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

    private function prepareTenantStorage(?string $businessId): bool
    {
        if ($businessId === null || $businessId === '') {
            $this->context->getLog()->error('CallbackService::prepareTenantStorage missing business identifier.');

            return false;
        }

        $provisioning = $this->tenantProvisioningService->provisionTenant($businessId);
        if (!($provisioning['success'] ?? false)) {
            $this->context->getLog()->error(
                sprintf('CallbackService::prepareTenantStorage failed provisioning for %s: %s', $businessId, $provisioning['message'] ?? 'Unknown error')
            );

            return false;
        }

        $this->context->getLog()->info(
            sprintf(
                'CallbackService::prepareTenantStorage provisioned tenant %s with templates: %s',
                $businessId,
                implode(',', $provisioning['templates'] ?? [])
            )
        );

        $businessService = $this->serviceFactory->business($businessId);

        if (!$this->syncResourceCollection($businessId, $businessService, get_class($businessService))) {
            $this->context->getLog()->warning(
                sprintf('CallbackService::prepareTenantStorage failed to sync business record for %s.', $businessId)
            );

            return false;
        }

        return true;
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
        ?string $storeId
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
            ]
        );

        $conn = $this->context->getConn();
        $transactionStarted = false;

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            if ($this->installationExists($businessId)) {
                $this->context->getLog()->info(
                    sprintf('Existing installation detected for business %s, reactivating.', $businessId)
                );
                $this->reactivateInstallation($businessId);
            }
            $this->context->getLog()->debug(
                sprintf('CallbackService::runBusinessWorkflow transaction started for business %s.', $businessId)
            );

            if (!$this->synchronizeStores($businessId)) {
                throw new RuntimeException(
                    sprintf('Failed to synchronize stores for business %s.', $businessId)
                );
            }

            if (!$this->gatherInitialResources($businessId)) {
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
                'SELECT 1 FROM business WHERE business_id = ? ORDER BY updated_at DESC LIMIT 1',
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
//        $this->reactivateLocalSubscriptions($businessId);
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


    private function synchronizeStores(string $businessId): bool
    {
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
        }

        return true;
    }

    private function gatherInitialResources(string $businessId): bool
    {
        $this->setInitialGatheringStatus($businessId, true);

        $this->context->getLog()->info(
            sprintf('CallbackService::gatherInitialResources starting for business %s.', $businessId)
        );

        try {
            $allSuccessful = true;
            foreach ($this->serviceFactory->onboardingResources($businessId) as $service) {
                $serviceClass = get_class($service);

                $this->context->getLog()->info(
                    sprintf('CallbackService::gatherInitialResources syncing %s for business %s.', $serviceClass, $businessId)
                );

                $result = $this->syncResourceCollection($businessId, $service, $serviceClass);
                if (!$result) {
                    $allSuccessful = false;
                    $this->context->getLog()->warning(
                        sprintf(
                            'CallbackService::gatherInitialResources encountered issues while syncing %s for business %s.',
                            $serviceClass,
                            $businessId
                        )
                    );
                } else {
                    $this->context->getLog()->info(
                        sprintf(
                            'CallbackService::gatherInitialResources completed %s for business %s successfully.',
                            $serviceClass,
                            $businessId
                        )
                    );
                }
            }

            $this->context->getLog()->info(
                sprintf(
                    'CallbackService::gatherInitialResources finished for business %s with status: %s.',
                    $businessId,
                    $allSuccessful ? 'success' : 'partial-failure'
                )
            );

            return $allSuccessful;
        } finally {
            $this->setInitialGatheringStatus($businessId, false);
        }
    }

    private function setInitialGatheringStatus(string $businessId, bool $status): void
    {
        try {
            $updated = $this->context->getConn()->executeStatement(
                'UPDATE business SET initial_gathering = ?, updated_at = NOW() WHERE business_id = ?',
                [$status, $businessId]
            );

            if ($updated === 0) {
                $this->context->getLog()->warning(
                    sprintf(
                        'CallbackService::setInitialGatheringStatus could not find business %s to update.',
                        $businessId
                    )
                );
            }
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::setInitialGatheringStatus failed for business %s: %s',
                    $businessId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param object $service
     */
    private function syncResourceCollection(string $businessId, object $service, ?string $serviceClass = null): bool
    {
        $serviceClass = $serviceClass ?? get_class($service);

        if (!method_exists($service, 'fetchByBusinessId') || !method_exists($service, 'upsert')) {
            $this->context->getLog()->debug(
                sprintf(
                    'CallbackService::syncResourceCollection skipping %s for business %s due to missing interface methods.',
                    $serviceClass,
                    $businessId
                )
            );

            return true;
        }

        try {
            $this->context->getLog()->debug(
                sprintf('CallbackService::syncResourceCollection fetching data from %s for business %s.', $serviceClass, $businessId)
            );
            $raw = $service->fetchByBusinessId($businessId);
        } catch (Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'Failed to fetch onboarding resources via %s for business %s: %s',
                    $serviceClass,
                    $businessId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return false;
        }

        if ($raw === false) {
            $this->context->getLog()->warning(
                sprintf('CallbackService::syncResourceCollection received false payload from %s for business %s.', $serviceClass, $businessId)
            );

            return false;
        }

        if (!is_array($raw) || empty($raw)) {
            $this->context->getLog()->info(
                sprintf('CallbackService::syncResourceCollection received empty payload from %s for business %s.', $serviceClass, $businessId)
            );

            return true;
        }

        $normalized = $this->normalizeResourceItems($raw);
        $allSuccessful = true;
        $processedItems = 0;
        $successfulItems = 0;
        $failedItems = 0;

        foreach ($normalized['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $result = $service->upsert($item);
                $processedItems++;
                if ($result === false) {
                    $allSuccessful = false;
                    $failedItems++;
                    $this->context->getLog()->warning(
                        sprintf(
                            'CallbackService::syncResourceCollection upsert returned false for %s during business %s.',
                            $serviceClass,
                            $businessId
                        ),
                        ['item' => $item]
                    );
                } else {
                    $successfulItems++;
                }
            } catch (Throwable $e) {
                $processedItems++;
                $failedItems++;
                $this->context->getLog()->error(
                    sprintf(
                        'Failed to persist onboarding resource via %s for business %s: %s',
                        $serviceClass,
                        $businessId,
                        $e->getMessage()
                    ),
                    ['exception' => $e, 'item' => $item]
                );
                $allSuccessful = false;
            }
        }

        $this->recordResourceLinks($service, $businessId, $normalized['links']);

        $this->context->getLog()->info(
            sprintf('CallbackService::syncResourceCollection summary for %s business %s.', $serviceClass, $businessId),
            [
                'processedItems' => $processedItems,
                'successfulItems' => $successfulItems,
                'failedItems' => $failedItems,
            ]
        );

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

        return $this->runBusinessWorkflow($businessId, $storeId);
    }

    /**
     * Remove all local data for a business by dropping tenant tables and clearing registry rows.
     *
     * Token tables are part of the tenant schema and will be dropped as well; the $preserveTokens
     * flag is retained for compatibility and only controls informational logging.
     */
    public function purgeBusiness(string $businessId, bool $preserveTokens = true): void
    {
        $this->purgeBusinessInstallation($businessId, $preserveTokens);
    }

    private function purgeBusinessInstallation(string $businessId, bool $preserveTokens = true): void
    {
        $conn = $this->context->getConn();
        $transactionStarted = false;

        $dropResult = $this->tenantProvisioningService->dropTenant($businessId);

        if (!($dropResult['success'] ?? false)) {
            $this->context->getLog()->error(
                sprintf(
                    'CallbackService::purgeBusinessInstallation failed to drop tenant tables for business %s: %s',
                    $businessId,
                    $dropResult['message'] ?? 'unknown error'
                ),
                [
                    'log_scope' => 'shared',
                    'type' => 'maintenance',
                    'details' => [
                        'businessId' => $businessId,
                        'dropResult' => $dropResult,
                    ],
                ]
            );

            return;
        }

        if ($preserveTokens) {
            $this->context->getLog()->info(
                sprintf(
                    'CallbackService::purgeBusinessInstallation dropping tenant tables removes stored tokens for business %s.',
                    $businessId
                )
            );
        }

        try {
            $conn->beginTransaction();
            $transactionStarted = true;

            $conn->executeStatement('DELETE FROM tenant_table_registry WHERE business_id = :biz', ['biz' => $businessId]);
            $conn->executeStatement('DELETE FROM tenant_schema_version WHERE tenant_id = :biz', ['biz' => $businessId]);
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

}
