<?php

namespace App\Services;

use App\Config\ConfigApp;
use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PaginatedRequest;
use App\Services\Support\PoyntDataFormatter as Format;
use App\Services\TokenService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use PDO;

class WebhookService
{
    protected Context $context;
    private ?string $businessId;
    private ClientInterface $httpClient;

    public const POYNT_WEBHOOK_URL = 'https://services.poynt.net/hooks';
    private const POYNT_BUSINESS_URL = 'https://services.poynt.net/businesses';

    /**
     * Constructor.
     *
     * @param Context $context An object containing dependencies (like logger, config, etc.)
     */
    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->businessId = $businessId;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
    }

    /**
     * Registers your webhook with Poynt.
     *
     * This method sends a POST request to Poynt’s webhook registration endpoint,
     * providing your callback URL and the events you wish to subscribe to.
     *
     * @param string $merchantAccessToken The merchant access token obtained via OAuth.
     * @param array $events
     * @return mixed|null Returns response data on success or null on failure.
     * @throws GuzzleException
     */
    public function registerWebhook(string $merchantAccessToken, array $events): mixed
    {
        $businessId = $this->resolveBusinessIdForEvents($events);
        if ($businessId === null) {
            $this->context->getLog()->error('WebhookService::registerWebhook: unable to resolve businessId for payload');

            return null;
        }

        // Build the payload
        $payload = [
            'applicationId' => 'urn:aid:' . ConfigApp::$appId,
            'businessId'    => $businessId,
            'deliveryUrl'   => ConfigApp::$webRootUrl . '/webhooks/event-listener',
            'eventTypes'    => $events
        ];

        try {
            $response = $this->httpClient->post(self::POYNT_WEBHOOK_URL, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                ],
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody(), true);

            // Insert an audit record for this outgoing registration call
            $this->context->getConn()->insert('webhook_audit', [
                'event_type'    => 'WEBHOOK_REGISTRATION',
                'payload'       => Format::jsonObject($payload),
                'headers'       => Format::jsonObject($response->getHeaders()),
                'processed'     => true,
                'error_message' => null,
            ]);

            if (is_array($responseData)) {
                $responseData['businessId'] = $responseData['businessId'] ?? $businessId;

                if (!isset($responseData['eventTypes']) && isset($payload['eventTypes'])) {
                    $responseData['eventTypes'] = $payload['eventTypes'];
                }

                if (!isset($responseData['deliveryUrl']) && isset($payload['deliveryUrl'])) {
                    $responseData['deliveryUrl'] = $payload['deliveryUrl'];
                }

                $this->upsert($responseData);
            }

            $this->context->getLog()->info("Webhook registered successfully", $responseData);
            return $responseData;

        } catch (RequestException $e) {
            $errorMsg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();

            // Insert an audit record capturing the failure
            $this->context->getConn()->insert('webhook_audit', [
                'event_type'    => 'WEBHOOK_REGISTRATION',
                'payload'       => Format::jsonObject($payload),
                'headers'       => Format::jsonObject($e->hasResponse() ? $e->getResponse()->getHeaders() : []),
                'processed'     => false,
                'error_message' => $errorMsg,
            ],
            [
                'processed' => PDO::PARAM_BOOL,
            ]);

            $this->context->getLog()->error(
                "Webhook registration for payload " . json_encode($payload) .
                " failed with error: " . $errorMsg
            );
        }

        return null;
    }


    // ------------------------------------------------------------------------
    // 1. APPLICATION_SUBSCRIPTION_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerSubscriptionWebhooks(string $merchantToken): void
    {
        $events = [
            'APPLICATION_SUBSCRIPTION_START',
            'APPLICATION_SUBSCRIPTION_END',
            'APPLICATION_SUBSCRIPTION_PAYMENT_SUCCESS',
            'APPLICATION_SUBSCRIPTION_PAYMENT_FAIL',
            'APPLICATION_SUBSCRIPTION_PHASE_CHANGE',
            'APPLICATION_SUBSCRIPTION_REFUND_SUCCESS',
        ];

        $this->callRegisterWebhook($merchantToken, $events);

    }

    // ------------------------------------------------------------------------
    // 2. BUSINESS_USER_*
    // --------------------------------------------------

    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerBusinessUserWebhooks(string $merchantToken): void
    {
        $events = [
            'BUSINESS_USER_CREATED',
            'BUSINESS_USER_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 3. CATALOG_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerCatalogWebhooks(string $merchantToken): void
    {
        $events = [
            'CATALOG_CREATED',
            'CATALOG_UPDATED',
            'CATALOG_DELETED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 4. CATEGORY_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerCategoryWebhooks(string $merchantToken): void
    {
        $events = [
            'CATEGORY_CREATED',
            'CATEGORY_UPDATED',
            'CATEGORY_DELETED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 5. INVENTORY_UPDATED
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerInventoryWebhook(string $merchantToken): void
    {
        $events = [
            'INVENTORY_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 6. ORDER_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerOrderWebhooks(string $merchantToken): void
    {
        $events = [
            'ORDER_OPENED',
            'ORDER_CANCELLED',
            'ORDER_COMPLETED',
            'ORDER_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 7. ORDER_ITEM_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerOrderItemWebhooks(string $merchantToken): void
    {
        $events = [
            'ORDER_ITEM_ORDERED',
            'ORDER_ITEM_FULFILLED',
            'ORDER_ITEM_RETURNED',
            'ORDER_ITEM_DELETED',
            'ORDER_ITEM_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 8. PRODUCT_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerProductWebhooks(string $merchantToken): void
    {
        $events = [
            'PRODUCT_CREATED',
            'PRODUCT_UPDATED',
            'PRODUCT_DELETED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 9. STORE_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerStoreWebhooks(string $merchantToken): void
    {
        $events = [
            'STORE_CREATED',
            'STORE_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken,$events);
    }

    // ------------------------------------------------------------------------
    // 10. TAX_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerTaxWebhooks(string $merchantToken): void
    {
        $events = [
            'TAX_CREATED',
            'TAX_UPDATED',
            'TAX_DELETED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 11. TRANSACTION_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerTransactionWebhooks(string $merchantToken): void
    {
        $events = [
            'TRANSACTION_AUTHORIZED',
            'TRANSACTION_PENDING',
            'TRANSACTION_CAPTURED',
            'TRANSACTION_REFUNDED',
            'TRANSACTION_UPDATED',
            'TRANSACTION_VOIDED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }

    // ------------------------------------------------------------------------
    // 12. USER_*
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken
     * @return void
     */
    public function registerUserWebhooks(string $merchantToken): void
    {
        $events = [
            'USER_CREATED',
            'USER_JOIN_ORG',
            'USER_LEAVE_ORG',
            'USER_JOIN_BIZ',
            'USER_LEAVE_BIZ',
            'USER_UPDATED',
        ];

        $this->callRegisterWebhook($merchantToken, $events);
    }



    // ------------------------------------------------------------------------
    // INTERNAL “glue” method that sends the actual HTTP call via WebhookService
    // ------------------------------------------------------------------------
    /**
     * @param string $merchantToken The merchant’s access token
     * @param array $events List of event-names to subscribe to
     *
     * @return void
     */
    public function callRegisterWebhook(string $merchantToken, array $events): void
    {
        try {
            $responseData = $this->ensureWebhookExists(
                ConfigApp::$webRootUrl . '/webhooks/event-listener',
                $events
            );

            if ($responseData) {
                $this->context->getLog()->info('Successfully registered webhooks: ' . implode(', ', $events), $responseData);
            } else if (is_null($responseData)) {
                $this->context->getLog()->error('Something went wrong, response data for webhooks: ' . implode(', ', $events) . ' is null.', []);
            }

        } catch (GuzzleException $e) {
            // If the request fails, capture error details:
            $this->context->getLog()->error(
                'Error registering webhooks: ' . implode(', ', $events) . ' ‒ ' . $e->getMessage()
            );
            // Optionally rethrow or swallow depending on your error‐handling policy:
            // TODO send status HTTP response and exit()
            // throw $e;
        }
    }

    /**
     * Ensure that a webhook exists for the current business.
     *
     * If an active webhook already exists for the configured delivery URL, it is returned.
     * Otherwise, a new webhook is registered and any previous hook is removed.
     *
     * @param string $deliveryUrl
     * @param array $eventTypes
     * @return mixed|null
     */
    public function ensureWebhookExists(string $deliveryUrl, array $eventTypes): mixed
    {
        $targetBusinessId = $this->resolveBusinessIdForEvents($eventTypes);
        if ($targetBusinessId === null) {
            $this->context->getLog()->error('WebhookService::ensureWebhookExists: missing businessId');

            return null;
        }

        $tokenBusinessId = $this->businessId;
        if (!is_string($tokenBusinessId) || $tokenBusinessId === '') {
            $this->context->getLog()->error('WebhookService::ensureWebhookExists: missing merchant businessId for tokens');

            return null;
        }

        $existingHooks = $this->fetchByBusinessId($tokenBusinessId);
        if ($existingHooks === false) {
            return null;
        }

        $targetDeliveryUrl = $deliveryUrl;
        $normalizedTargetEvents = $this->normalizeEventTypes($eventTypes);
        $oldHook = null;

        foreach ($existingHooks as $hook) {
            if (!is_array($hook)) {
                continue;
            }

            $hookUrl = $hook['deliveryUrl'] ?? $hook['destinationUrl'] ?? $hook['url'] ?? null;
            $status = $hook['active'] ?? null;

            $hookEvents = [];
            if (isset($hook['eventTypes'])) {
                $hookEvents = is_array($hook['eventTypes']) ? $hook['eventTypes'] : [$hook['eventTypes']];
            }

            $normalizedHookEvents = $this->normalizeEventTypes($hookEvents);

            if ($hookUrl === $targetDeliveryUrl && $normalizedHookEvents === $normalizedTargetEvents && $this->isHookActive($hook, $status)) {
                return $hook;
            }

            if ($hookUrl === $targetDeliveryUrl && ($oldHook === null || $this->isHookActive($hook, $status))) {
                $oldHook = $hook;
            }
        }

        $tokenService = new TokenService($this->context);
        $merchantAccessToken = $tokenService->getMerchantToken($tokenBusinessId);

        if (!is_string($merchantAccessToken) || $merchantAccessToken === '') {
            $this->context->getLog()->error(
                sprintf('WebhookService::ensureWebhookExists: missing merchant token for business %s', $tokenBusinessId)
            );

            return null;
        }

        $newHook = $this->registerWebhook($merchantAccessToken, $eventTypes);

        if ($newHook && is_array($oldHook)) {
            $hookId = $oldHook['id'] ?? $oldHook['hookId'] ?? null;
            if (is_string($hookId) && $hookId !== '') {
                $hookBusinessId = $oldHook['businessId'] ?? $targetBusinessId;
                $oldEventTypes = $oldHook['eventTypes'] ?? [];
                if (!is_array($oldEventTypes)) {
                    $oldEventTypes = [$oldEventTypes];
                }

                if ($this->eventsRequireOrgId($oldEventTypes)) {
                    $orgId = ConfigApp::$orgId ?? '';
                    if (is_string($orgId) && $orgId !== '') {
                        $hookBusinessId = $orgId;
                    }
                }

                try {
                    $this->httpClient->delete(
                        self::POYNT_WEBHOOK_URL . '/' . rawurlencode($hookId),
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $merchantAccessToken,
                            ],
                            'query' => [
                                'businessId' => $hookBusinessId,
                            ],
                        ]
                    );

                    $this->context->getLog()->info(
                        sprintf(
                            'WebhookService::ensureWebhookExists: deleted old hook %s for business %s',
                            $hookId,
                            $hookBusinessId
                        )
                    );
                } catch (BadResponseException|GuzzleException $e) {
                    $this->context->getLog()->error(
                        sprintf(
                            'WebhookService::ensureWebhookExists: failed deleting old hook %s for business %s: %s',
                            $hookId,
                            $hookBusinessId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        return $newHook;
    }

    /**
     * Upsert a hook record.
     *
     * @param array $hookData
     * @return bool
     */
    public function upsert(array $hookData): bool
    {
        if (!isset($hookData['id'], $hookData['businessId'])) {
            $this->context->getLog()->error(
                'WebhookService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $hookId = $hookData['id'];
        $businessId = $hookData['businessId'];

        $url = $hookData['deliveryUrl'] ?? null;
        $eventTypes = $hookData['eventTypes'] ?? $hookData['events'] ?? [];
        if (!is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }
        $eventTypesLiteral = Format::postgresTextArray($eventTypes);
        $status = $hookData['active'] ?? null;
        $deliveries = $this->resolveDeliveries($hookData);
        if (!empty($deliveries)) {
            $hookData = $this->stripDeliveries($hookData);
        }

        $rawPayload = Format::jsonObject($hookData);
        $createdAtExt = Format::optionalTimestamp($hookData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($hookData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO hook (
                    hook_id, business_id, url, event_types, status,
                    raw_payload, created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :hookId, :businessId, :url, :eventTypes, :status,
                    :rawPayload, :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (hook_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    url = EXCLUDED.url,
                    event_types = EXCLUDED.event_types,
                    status = EXCLUDED.status,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'hookId' => $hookId,
                    'businessId' => $businessId,
                    'url' => $url,
                    'eventTypes' => $eventTypesLiteral,
                    'status' => $status,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("WebhookService::upsert: upserted hook {$hookId}");
            if (!empty($deliveries)) {
                $this->syncDeliveries($hookId, $businessId, $deliveries);
            }
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "WebhookService::upsert: database error for hook_id={$hookId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch hooks for a business from the Poynt API.
     *
     * @param string|null $businessId
     * @return array|false
     */
    public function fetchByBusinessId(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }
        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $merchantToken = $tokenService->getMerchantToken($businessId);
        $appToken = $tokenService->getAppToken($businessId);

        if (!is_string($appToken) || $appToken === '') {
            $this->context->getLog()->error(
                sprintf('WebhookService::fetchByBusinessId: missing app access token for business %s', $businessId)
            );

            return false;
        }

        if (!is_string($merchantToken) || $merchantToken === '') {
            $this->context->getLog()->error(
                sprintf('WebhookService::fetchByBusinessId: missing merchant access token for business %s', $businessId)
            );

            return false;
        }

        try {
            $hooks = $this->fetchAllHooks($businessId, $appToken);
            if ($hooks === false) {
                $this->context->getLog()->error(
                    sprintf(
                        'WebhookService::fetchByBusinessId: unexpected response while loading hooks for business %s',
                        $businessId
                    )
                );
                return false;
            }

            $hooks = $this->deleteDuplicateHooks($hooks, $businessId, $merchantToken);

            $hooksById = [];
            foreach ($hooks as $hook) {
                if (!is_array($hook)) {
                    continue;
                }

                $hookId = $hook['id'] ?? null;
                if (!is_string($hookId) || $hookId === '') {
                    continue;
                }

                $hooksById[$hookId] = $hook;
            }

            $hookIds = array_keys($hooksById);
            $this->context->getLog()->info(
                'WebhookService::fetchByBusinessId hook ids',
                [
                    'businessId' => $businessId,
                    'hookIds' => $hookIds,
                ]
            );

            $hooks = array_values($hooksById);

            FetchResponseLogger::info(
                $this->context->getLog(),
                'WebhookService::fetchByBusinessId response',
                [
                    'businessId' => $businessId,
                    'entity' => 'hooks',
                    'payload' => $hooks,
                ]
            );

            return $hooks;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            if ($response !== null && $response->getStatusCode() === 404) {
                $this->context->getLog()->info(
                    sprintf(
                        'WebhookService::fetchByBusinessId: GET /hooks?businessId=%s returned 404, treating as no hooks',
                        $businessId
                    )
                );

                FetchResponseLogger::info(
                    $this->context->getLog(),
                    'WebhookService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'hooks',
                        'payload' => [],
                    ]
                );

                return [];
            }

            $this->context->getLog()->error(
                'WebhookService::fetchByBusinessId: ' . $e->getMessage()
            );

            return false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'WebhookService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Delete all hooks for a business using the Poynt API.
     *
     * @param string|null $businessId
     * @return bool True when all deletions succeed, false otherwise.
     */
    public function deleteAllByBusinessId(?string $businessId = null): bool
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }
        if (!$businessId) {
            return false;
        }

        $hooks = $this->fetchByBusinessId($businessId);
        if ($hooks === false) {
            return false;
        }

        if (empty($hooks)) {
            $this->context->getLog()->info(
                sprintf('WebhookService::deleteAllByBusinessId: no hooks to delete for business %s', $businessId)
            );
            return true;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        $allDeleted = true;
        foreach ($hooks as $hook) {
            if (!is_array($hook) || empty($hook['id'])) {
                continue;
            }

            $hookId = $hook['id'];

            $hookBusinessId = $hook['businessId'] ?? null;
            if (!is_string($hookBusinessId) || $hookBusinessId === '') {
                $hookBusinessId = $businessId;
            }

            $eventTypes = [];
            if (isset($hook['eventTypes'])) {
                $eventTypes = is_array($hook['eventTypes']) ? $hook['eventTypes'] : [$hook['eventTypes']];
            }

            if ($this->eventsRequireOrgId($eventTypes)) {
                $orgId = ConfigApp::$orgId ?? '';
                if (is_string($orgId) && $orgId !== '') {
                    $hookBusinessId = $orgId;
                }
            }

            try {
                $this->httpClient->delete(
                    self::POYNT_WEBHOOK_URL . '/' . rawurlencode($hookId),
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                        'query' => [
                            'businessId' => $hookBusinessId,
                        ],
                    ]
                );

                $this->context->getLog()->info(
                    sprintf(
                        'WebhookService::deleteAllByBusinessId: deleted hook %s for business %s',
                        $hookId,
                        $hookBusinessId
                    )
                );
            } catch (BadResponseException|GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'WebhookService::deleteAllByBusinessId: failed deleting hook %s for business %s: %s',
                        $hookId,
                        $hookBusinessId,
                        $e->getMessage()
                    )
                );
                $allDeleted = false;
            }
        }

        return $allDeleted;
    }

    /**
     * Remove duplicate hooks by delivery URL and event types, deleting redundant entries via the Poynt API.
     *
     * @param array<int, mixed> $hooks
     * @return array<int, mixed>
     */
    private function deleteDuplicateHooks(array $hooks, string $businessId, string $merchantToken): array
    {
        $uniqueHooks = [];
        $seenHookKeys = [];

        foreach ($hooks as $hook) {
            if (!is_array($hook)) {
                $uniqueHooks[] = $hook;
                continue;
            }

            $hookId = $hook['id'] ?? $hook['hookId'] ?? null;
            $deliveryUrl = $hook['deliveryUrl'] ?? $hook['destinationUrl'] ?? $hook['url'] ?? null;
            $eventTypes = $hook['eventTypes'] ?? [];

            if (!is_array($eventTypes)) {
                $eventTypes = [$eventTypes];
            }

            if (!is_string($hookId) || $hookId === '' || !is_string($deliveryUrl) || $deliveryUrl === '') {
                $uniqueHooks[] = $hook;
                continue;
            }

            $normalizedEventTypes = $this->normalizeEventTypes($eventTypes);
            $hookKey = $deliveryUrl . '|' . implode('|', $normalizedEventTypes);

            if (!isset($seenHookKeys[$hookKey])) {
                $seenHookKeys[$hookKey] = true;
                $uniqueHooks[] = $hook;
                continue;
            }

            $hookBusinessId = $hook['businessId'] ?? $businessId;
            if (!is_string($hookBusinessId) || $hookBusinessId === '') {
                $hookBusinessId = $businessId;
            }

            if ($this->eventsRequireOrgId($eventTypes)) {
                $orgId = ConfigApp::$orgId ?? '';
                if (is_string($orgId) && $orgId !== '') {
                    $hookBusinessId = $orgId;
                }
            }

            try {
                $this->httpClient->delete(
                    self::POYNT_WEBHOOK_URL . '/' . rawurlencode($hookId),
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $merchantToken,
                        ],
                        'query' => [
                            'businessId' => $hookBusinessId,
                        ],
                    ]
                );

                $this->context->getLog()->info(
                    'WebhookService::fetchByBusinessId: deleted duplicate hook',
                    [
                        'hookId' => $hookId,
                        'businessId' => $hookBusinessId,
                        'deliveryUrl' => $deliveryUrl,
                    ]
                );
            } catch (BadResponseException|GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'WebhookService::fetchByBusinessId: failed deleting duplicate hook %s for business %s: %s',
                        $hookId,
                        $hookBusinessId,
                        $e->getMessage()
                    )
                );
            }
        }

        return $uniqueHooks;
    }

    /**
     * Determine whether a webhook entry is active.
     *
     * @param array $hook
     * @param string|null $status
     * @return bool
     */
    private function isHookActive(array $hook, ?string $status = null): bool
    {
        $statusValue = $status ?? ($hook['status'] ?? null);
        if (is_string($statusValue) && strtoupper($statusValue) === 'ACTIVE') {
            return true;
        }

        $activeFlag = $hook['active'] ?? null;

        return $activeFlag === true || $activeFlag === 1 || $activeFlag === 'true';
    }

    /**
     * Normalize event types for comparison regardless of ordering or duplicates.
     *
     * @param array<int, string> $eventTypes
     * @return array<int, string>
     */
    private function normalizeEventTypes(array $eventTypes): array
    {
        $normalized = array_filter(array_map(static function ($event) {
            return is_string($event) ? $event : null;
        }, $eventTypes));

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * Fetch all hooks associated with the merchant business as well as any organization-scoped entries.
     *
     * @param string $businessId
     * @param string $appToken
     * @return array|false
     * @throws BadResponseException
     * @throws GuzzleException
     */
    private function fetchAllHooks(string $businessId, string $appToken): array|false
    {
        $hooksById = [];
        $anonymousHooks = [];

        $businessIds = [$businessId];
        $orgId = ConfigApp::$orgId ?? '';
        if (is_string($orgId) && $orgId !== '' && $orgId !== $businessId) {
            $businessIds[] = $orgId;
        }

        foreach ($businessIds as $targetBusinessId) {
            $pageHooks = $this->fetchHooksForBusiness($targetBusinessId, $appToken);
            if ($pageHooks === false) {
                return false;
            }

            foreach ($pageHooks as $hook) {
                if (!is_array($hook)) {
                    continue;
                }

                $hookId = $hook['id'] ?? null;
                if (is_string($hookId) && $hookId !== '') {
                    $hooksById[$hookId] = $hook;
                    continue;
                }

                $anonymousHooks[] = $hook;
            }
        }

        return array_merge(array_values($hooksById), $anonymousHooks);
    }

    /**
     * Fetch hooks for a specific business identifier, following pagination links.
     *
     * @param string $businessId
     * @param string $appToken
     * @return array<int, mixed>|false
     * @throws BadResponseException
     * @throws GuzzleException
     */
    private function fetchHooksForBusiness(string $businessId, string $appToken): array|false
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $appToken,
            ],
            'query' => [
                'businessId' => $businessId,
            ],
        ];

        $hooks = [];
        $nextUrl = self::POYNT_WEBHOOK_URL;
        $visited = [];
        $isFirstPage = true;

        while ($nextUrl !== null) {
            $requestOptions = $options;
            if (!$isFirstPage) {
                unset($requestOptions['query']);
            }
            $isFirstPage = false;

            $response = $this->httpClient->get($nextUrl, $requestOptions);
            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                return false;
            }

            $pageHooks = $this->normalizeHooks($payload);
            if ($pageHooks === false) {
                return false;
            }

            foreach ($pageHooks as $hook) {
                if (!is_array($hook)) {
                    continue;
                }

                $hooks[] = $hook;
            }

            $nextCandidate = $this->extractNextHooksUrl($payload);
            if ($nextCandidate === null) {
                break;
            }

            if (isset($visited[$nextCandidate])) {
                break;
            }

            $visited[$nextCandidate] = true;
            $nextUrl = $nextCandidate;
        }

        return $hooks;
    }

    private function fetchDeliveriesForBusiness(string $businessId, string $accessToken): array
    {
        $url = self::POYNT_BUSINESS_URL . '/' . rawurlencode($businessId) . '/deliveries';
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ];

        try {
            $response = $this->httpClient->get($url, $options);
            $payload = json_decode($response->getBody(), true);
            if (!is_array($payload)) {
                return [];
            }

            $payload = PaginatedRequest::collect($this->httpClient, $payload, $url, $options, 'deliveries');

            return $this->normalizeDeliveries($payload, $businessId);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            if ($response !== null && $response->getStatusCode() === 404) {
                $this->context->getLog()->info(
                    sprintf(
                        'WebhookService::fetchDeliveriesForBusiness: GET /businesses/%s/deliveries returned 404, treating as none',
                        $businessId
                    )
                );

                return [];
            }

            $this->context->getLog()->error(
                sprintf(
                    'WebhookService::fetchDeliveriesForBusiness: failed for business %s: %s',
                    $businessId,
                    $e->getMessage()
                )
            );

            return [];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('WebhookService::fetchDeliveriesForBusiness: failed for business %s: %s', $businessId, $e->getMessage())
            );
            return [];
        }
    }

    /**
     * @param array<int, mixed> $events
     */
    private function eventsRequireOrgId(array $events): bool
    {
        foreach ($events as $event) {
            if (!is_string($event)) {
                continue;
            }

            if (str_starts_with($event, 'APPLICATION_SUBSCRIPTION_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Select the correct business identifier for the given events.
     *
     * @param array<int, mixed> $events
     */
    private function resolveBusinessIdForEvents(array $events): ?string
    {
        $businessId = $this->businessId;

        if ($this->eventsRequireOrgId($events)) {
            $orgId = ConfigApp::$orgId ?? null;
            if (is_string($orgId) && $orgId !== '') {
                $businessId = $orgId;
            }
        }

        if (!is_string($businessId) || $businessId === '') {
            return null;
        }

        return $businessId;
    }

    private function normalizeHooks(mixed $payload): array|false
    {
        if (!is_array($payload)) {
            return false;
        }

        $items = null;
        $candidateKeys = ['hooks', 'items', 'data', 'results'];

        if (array_is_list($payload)) {
            $items = $payload;
        } else {
            foreach ($candidateKeys as $key) {
                if (array_key_exists($key, $payload)) {
                    if ($payload[$key] === null) {
                        $items = [];
                        break;
                    }

                    if (is_array($payload[$key])) {
                        $items = $payload[$key];
                        break;
                    }

                    return false;
                }
            }
        }

        if ($items === null && isset($payload['_embedded']) && is_array($payload['_embedded'])) {
            return $this->normalizeHooks($payload['_embedded']);
        }

        if ($items === null) {
            $items = [];
        }

        if (!is_array($items)) {
            return false;
        }

        if (!array_is_list($items)) {
            $rows = [];
            foreach ($items as $value) {
                if (is_array($value)) {
                    $rows[] = $value;
                }
            }
            $items = $rows;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = $this->normalizeSingleHook($item);
        }

        return $normalized;
    }

    private function normalizeSingleHook(array $hook): array
    {
        $flattened = $this->flattenHook($hook);

        unset($flattened['hook']);
        unset($flattened['deliveries']);

        $requiredKeys = [
            'id',
            'eventTypes',
            'deliveryUrl',
            'businessId',
            'applicationId',
            'status',
            'createdAt',
            'updatedAt',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $flattened)) {
                $flattened[$key] = null;
            }
        }

        $eventTypes = $flattened['eventTypes'];
        if (!is_array($eventTypes)) {
            $eventTypes = $eventTypes === null ? [] : [$eventTypes];
        }

        if ($this->eventsRequireOrgId($eventTypes)) {
            $orgBusinessId = ConfigApp::$orgId ?? '';
            if (is_string($orgBusinessId) && $orgBusinessId !== '') {
                $flattened['businessId'] = $orgBusinessId;
            }
        }

        return $flattened;
    }

    private function flattenHook(array $hook): array
    {
        $flattened = $hook;

        while (isset($flattened['hook']) && is_array($flattened['hook'])) {
            $inner = $flattened['hook'];
            unset($flattened['hook']);
            $flattened = array_merge($flattened, $inner);
        }

        return $flattened;
    }

    private function extractNextHooksUrl(array $payload): ?string
    {
        $origin = $this->buildHooksOrigin();

        foreach ($this->normaliseHookLinks($payload) as $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? $link['type'] ?? null;
            if (!is_string($rel) || strtolower($rel) !== 'next') {
                continue;
            }

            $href = $link['href'] ?? $link['uri'] ?? $link['url'] ?? null;
            if (!is_string($href) || $href === '') {
                continue;
            }

            $href = trim($href);
            if ($href === '') {
                continue;
            }

            if (parse_url($href, PHP_URL_SCHEME) === null && $origin !== '') {
                return $this->resolveHookHref($href, $origin);
            }

            return $href;
        }

        if (isset($payload['links']['next']) && is_string($payload['links']['next']) && $payload['links']['next'] !== '') {
            $href = trim($payload['links']['next']);
            if ($href === '') {
                return null;
            }

            if (parse_url($href, PHP_URL_SCHEME) === null && $origin !== '') {
                return $this->resolveHookHref($href, $origin);
            }

            return $href;
        }

        return null;
    }

    private function buildHooksOrigin(): string
    {
        $parts = parse_url(self::POYNT_WEBHOOK_URL);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function resolveHookHref(string $href, string $origin): string
    {
        $basePath = parse_url(self::POYNT_WEBHOOK_URL, PHP_URL_PATH) ?? '';
        $basePath = '/' . ltrim($basePath, '/');

        if ($href !== '' && $href[0] === '?') {
            return rtrim($origin, '/') . $basePath . $href;
        }

        if ($href === '' || $href[0] !== '/') {
            $href = '/' . ltrim($href, '/');
        }

        return rtrim($origin, '/') . $href;
    }

    private function normaliseHookLinks(array $payload): array
    {
        $links = [];

        foreach (['links', 'link', '_links'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $links = array_merge($links, $this->parseHookLinkValue($payload[$key]));
        }

        return $links;
    }

    private function parseHookLinkValue(mixed $value): array
    {
        $links = [];

        if (is_array($value)) {
            if ($this->isAssoc($value) && $this->looksLikeSingleLink($value)) {
                $links[] = $value;

                return $links;
            }

            if ($this->isAssoc($value) && !$this->looksLikeSingleLink($value)) {
                foreach ($value as $rel => $linkValue) {
                    if (is_array($linkValue)) {
                        $link = $linkValue;
                        if (!isset($link['rel']) && is_string($rel)) {
                            $link['rel'] = $rel;
                        }
                        $links[] = $link;
                    } elseif (is_string($linkValue)) {
                        $links[] = [
                            'rel' => is_string($rel) ? $rel : 'next',
                            'href' => $linkValue,
                        ];
                    }
                }

                return $links;
            }

            foreach ($value as $link) {
                if (is_array($link)) {
                    $links[] = $link;
                }
            }

            return $links;
        }

        if (is_string($value) && $value !== '') {
            $links[] = [
                'rel' => 'next',
                'href' => $value,
            ];
        }

        return $links;
    }

    private function looksLikeSingleLink(array $value): bool
    {
        return array_key_exists('rel', $value) || array_key_exists('href', $value) || array_key_exists('uri', $value);
    }

    private function isAssoc(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    private function normalizeDeliveries(mixed $payload, string $businessId, ?string $hookId = null): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['deliveries']) && is_array($payload['deliveries'])) {
            $payload = $payload['deliveries'];
        }

        if (!is_array($payload)) {
            return [];
        }

        if (!array_is_list($payload)) {
            $rows = [];
            foreach ($payload as $value) {
                if (is_array($value)) {
                    $rows[] = $value;
                }
            }
            $payload = $rows;
        }

        foreach ($payload as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $row['businessId'] = $businessId;

            if (!isset($row['hookId']) || !is_string($row['hookId']) || $row['hookId'] === '') {
                if (isset($row['hook']) && is_array($row['hook'])) {
                    $candidate = $row['hook']['id'] ?? $row['hook']['hookId'] ?? null;
                    if (!is_string($candidate) || $candidate === '') {
                        $candidate = $row['hook']['hook']['id'] ?? $row['hook']['hook']['hookId'] ?? null;
                    }

                    if (is_string($candidate) && $candidate !== '') {
                        $row['hookId'] = $candidate;
                    }
                }
            }

            if ($hookId !== null && (!isset($row['hookId']) || $row['hookId'] === '')) {
                $row['hookId'] = $hookId;
            }
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    private function resolveDeliveries(array $hookData): array
    {
        if (isset($hookData['deliveries']) && is_array($hookData['deliveries'])) {
            return $hookData['deliveries'];
        }

        if (isset($hookData['hook']) && is_array($hookData['hook'])) {
            $inner = $hookData['hook'];
            if (isset($inner['deliveries']) && is_array($inner['deliveries'])) {
                return $inner['deliveries'];
            }
        }

        return [];
    }

    private function stripDeliveries(array $hookData): array
    {
        if (isset($hookData['deliveries'])) {
            unset($hookData['deliveries']);
        }

        if (isset($hookData['hook']) && is_array($hookData['hook'])) {
            $hookData['hook'] = $this->stripDeliveries($hookData['hook']);
        }

        return $hookData;
    }

    private function syncDeliveries(string $hookId, string $businessId, array $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || !isset($delivery['id'])) {
                continue;
            }

            $deliveryId = $delivery['id'];
            $eventType = $delivery['eventType'] ?? null;
            $deliveredAt = Format::optionalTimestamp(
                $delivery['deliveredAt']
                    ?? $delivery['deliveredAtExt']
                    ?? $delivery['updatedAt']
                    ?? $delivery['createdAt']
                    ?? null
            );
            $status = $delivery['status'] ?? null;
            $httpStatus = Format::optionalInt(
                $delivery['httpStatus']
                    ?? $delivery['statusCode']
                    ?? $delivery['responseCode']
                    ?? null
            );
            $retryCount = Format::optionalInt($delivery['retryCount'] ?? $delivery['attempt'] ?? null);
            $payload = Format::jsonObject($delivery);

            try {
                $this->context->getConn()->executeStatement(
                    'INSERT INTO hook_delivery (
                        delivery_id,
                        hook_id,
                        business_id,
                        event_type,
                        delivered_at_ext,
                        status,
                        http_status,
                        retry_count,
                        raw_payload
                    ) VALUES (
                        :deliveryId,
                        :hookId,
                        :businessId,
                        :eventType,
                        :deliveredAt,
                        :status,
                        :httpStatus,
                        :retryCount,
                        :payload
                    ) ON CONFLICT (delivery_id) DO UPDATE SET
                        hook_id          = EXCLUDED.hook_id,
                        business_id      = EXCLUDED.business_id,
                        event_type       = EXCLUDED.event_type,
                        delivered_at_ext = EXCLUDED.delivered_at_ext,
                        status           = EXCLUDED.status,
                        http_status      = EXCLUDED.http_status,
                        retry_count      = EXCLUDED.retry_count,
                        raw_payload      = EXCLUDED.raw_payload',
                    [
                        'deliveryId'  => $deliveryId,
                        'hookId'      => $hookId,
                        'businessId'  => $businessId,
                        'eventType'   => $eventType,
                        'deliveredAt' => $deliveredAt,
                        'status'      => $status,
                        'httpStatus'  => $httpStatus,
                        'retryCount'  => $retryCount,
                        'payload'     => $payload,
                    ]
                );
            } catch (\Throwable $e) {
                $this->context->getLog()->error(
                    sprintf('WebhookService::syncDeliveries: failed for hook %s delivery %s: %s', $hookId, $deliveryId, $e->getMessage())
                );
            }
        }
    }
}
