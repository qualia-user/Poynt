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
        // Build the payload
        $payload = [
            'applicationId' => 'urn:aid:' . ConfigApp::$appId,
            'businessId'    => ConfigApp::$orgId, // Developer organization ID, NOT business ID
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
            $responseData = $this->registerWebhook($merchantToken, $events);

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

        $url = $hookData['url'] ?? $hookData['destinationUrl'] ?? null;
        $eventTypes = $hookData['eventTypes'] ?? $hookData['events'] ?? [];
        if (!is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }
        $eventTypesLiteral = Format::postgresTextArray($eventTypes);
        $status = $hookData['status'] ?? null;
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
        $accessToken = $tokenService->getMerchantToken($businessId);

        try {
            $response = $this->httpClient->get(self::POYNT_WEBHOOK_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'businessId' => ConfigApp::$orgId,
                ],
            ]);

            $hooksPayload = json_decode($response->getBody(), true);
            $hooks = $this->normalizeHooks($hooksPayload);
            if ($hooks === false) {
                $this->context->getLog()->error(
                    sprintf(
                        'WebhookService::fetchByBusinessId: unexpected response while loading hooks for business %s',
                        $businessId
                    )
                );
                return false;
            }

            $deliveries = $this->fetchDeliveriesForBusiness($businessId, $accessToken);
            $deliveriesByHook = [];
            $deliveriesMissingHookId = [];
            foreach ($deliveries as $delivery) {
                if (!is_array($delivery)) {
                    continue;
                }

                $deliveryHookId = $delivery['hookId'] ?? null;
                if ((!is_string($deliveryHookId) || $deliveryHookId === '') && isset($delivery['hook']) && is_array($delivery['hook'])) {
                    $candidate = $delivery['hook']['id'] ?? $delivery['hook']['hookId'] ?? null;
                    if (!is_string($candidate) || $candidate === '') {
                        $candidate = $delivery['hook']['hook']['id'] ?? $delivery['hook']['hook']['hookId'] ?? null;
                    }

                    if (is_string($candidate) && $candidate !== '') {
                        $deliveryHookId = $candidate;
                    }
                }

                if (!is_string($deliveryHookId) || $deliveryHookId === '') {
                    $deliveriesMissingHookId[] = $delivery;
                    continue;
                }

                if (!isset($deliveriesByHook[$deliveryHookId])) {
                    $deliveriesByHook[$deliveryHookId] = [];
                }

                if (!isset($delivery['hookId'])) {
                    $delivery['hookId'] = $deliveryHookId;
                }

                $deliveriesByHook[$deliveryHookId][] = $delivery;
            }

            foreach ($hooks as &$hook) {
                if (!is_array($hook) || !isset($hook['id'])) {
                    continue;
                }

                $hookId = $hook['id'];
                if (is_string($hookId) && $hookId !== '' && isset($deliveriesByHook[$hookId])) {
                    $hook['deliveries'] = $deliveriesByHook[$hookId];
                }
            }

            if (!empty($deliveriesMissingHookId)) {
                FetchResponseLogger::info(
                    $this->context->getLog(),
                    'WebhookService::fetchByBusinessId deliveries missing hookId',
                    [
                        'businessId' => $businessId,
                        'entity' => 'deliveries',
                        'payload' => $deliveriesMissingHookId,
                    ]
                );
            }

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

            try {
                $this->httpClient->delete(
                    self::POYNT_WEBHOOK_URL . '/' . rawurlencode($hookId),
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                        'query' => [
                            'businessId' => $businessId,
                        ],
                    ]
                );

                $this->context->getLog()->info(
                    sprintf(
                        'WebhookService::deleteAllByBusinessId: deleted hook %s for business %s',
                        $hookId,
                        $businessId
                    )
                );
            } catch (BadResponseException|GuzzleException $e) {
                $this->context->getLog()->error(
                    sprintf(
                        'WebhookService::deleteAllByBusinessId: failed deleting hook %s for business %s: %s',
                        $hookId,
                        $businessId,
                        $e->getMessage()
                    )
                );
                $allDeleted = false;
            }
        }

        return $allDeleted;
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

    private function normalizeHooks(mixed $payload): array|false
    {
        if (!is_array($payload)) {
            return false;
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        $candidateKeys = ['hooks', 'items', 'data', 'results'];
        foreach ($candidateKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        if (isset($payload['_embedded']) && is_array($payload['_embedded'])) {
            return $this->normalizeHooks($payload['_embedded']);
        }

        return false;
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
            $deliveredAt = Format::optionalTimestamp($delivery['deliveredAt'] ?? $delivery['deliveredAtExt'] ?? null);
            $status = $delivery['status'] ?? null;
            $httpStatus = isset($delivery['httpStatus']) ? (int)$delivery['httpStatus'] : null;
            $retryCount = isset($delivery['retryCount']) ? (int)$delivery['retryCount'] : null;
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
