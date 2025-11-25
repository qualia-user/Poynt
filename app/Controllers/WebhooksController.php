<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Services\ServiceFactory;
use App\Services\SubscriptionService;
use App\Services\Support\WebhookResourceFetcher;
use App\Services\WebhookService;
use Doctrine\DBAL\Exception;
use PDO;

class WebhooksController extends Controller
{
    private SubscriptionService $subscriptionService;
    private ?ServiceFactory $serviceFactory = null;
    private WebhookResourceFetcher $resourceFetcher;

    /**
     * @var array<string, array{factory: string, businessScoped: bool}>
     */
    private const RESOURCE_SERVICE_MAP = [
        'catalog' => ['factory' => 'catalog', 'businessScoped' => true],
        'category' => ['factory' => 'category', 'businessScoped' => true],
        'inventory' => ['factory' => 'inventory', 'businessScoped' => true],
        'order' => ['factory' => 'order', 'businessScoped' => true],
        'product' => ['factory' => 'product', 'businessScoped' => true],
        'store' => ['factory' => 'store', 'businessScoped' => true],
        'tax' => ['factory' => 'tax', 'businessScoped' => true],
        'transaction' => ['factory' => 'transaction', 'businessScoped' => true],
        'businessuser' => ['factory' => 'businessUser', 'businessScoped' => true],
    ];

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->subscriptionService = new SubscriptionService($context);
        $this->resourceFetcher = new WebhookResourceFetcher($context);
    }

    /**
     * Webhook event listener.
     * Processes incoming webhook events using pre-stored request data.
     *
     * @return void
     * @throws Exception
     */
    public function eventListener(): void
    {
        // TODO - please create a special log table per store/business that will track all history
        // That can include all webhook events, all update events, everything related to particular store..
        //
        // For now, just log everything into global log
        //

        $payload = $this->api->data ?? [];
        $info = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->context->getLog()->info("Webhook info: " . $info);

        // Determine the event type from the payload.
        $eventType = $payload['eventType'] ?? null;

        // Insert audit record with default processed=false
        $headers = $this->getAllHeaders();
        $conn = $this->context->getConn();
        $conn->insert('webhook_audit', [
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'headers' => json_encode($headers),
            'processed' => false,
        ], [
            'processed' => PDO::PARAM_BOOL,
        ]);

        $auditId = $conn->lastInsertId();
        $responseStatus = Response::STATUS_OK;
        $responseBody = ['status' => 'ok'];
        $errorMessage = null;

        try {
            // Route to the appropriate handler based on event type.
            switch ($eventType) {
                case 'CATALOG_CREATED':
                case 'CATALOG_UPDATED':
                    $this->handleCatalogEvent($payload);
                    $responseBody = null;
                    break;

                case 'CATALOG_DELETED':
                    $this->handleCatalogDeletion($payload);
                    $responseBody = null;
                    break;

                case 'APPLICATION_SUBSCRIPTION_START':
                    $this->handleSubscriptionStart($payload);
                    break;

                case 'APPLICATION_SUBSCRIPTION_END':
                    $this->handleSubscriptionEnd($payload);
                    break;

                case 'APPLICATION_SUBSCRIPTION_PAYMENT_SUCCESS':
                case 'APPLICATION_SUBSCRIPTION_PAYMENT_FAIL':
                case 'APPLICATION_SUBSCRIPTION_PHASE_CHANGE':
                case 'APPLICATION_SUBSCRIPTION_REFUND_SUCCESS':
                    $this->handleSubscriptionLifecycleEvent($payload);
                    break;

                case 'BUSINESS_USER_CREATED':
                case 'BUSINESS_USER_UPDATED':
                    $this->handleBusinessUserEvent($payload);
                    break;

                case 'CATEGORY_CREATED':
                case 'CATEGORY_UPDATED':
                    $this->handleCategoryEvent($payload);
                    break;

                case 'CATEGORY_DELETED':
                    $this->handleCategoryDeletion($payload);
                    break;

                case 'INVENTORY_UPDATED':
                    $this->handleInventoryEvent($payload);
                    break;

                case 'ORDER_OPENED':
                case 'ORDER_CANCELLED':
                case 'ORDER_COMPLETED':
                case 'ORDER_UPDATED':
                    $this->handleOrderEvent($payload);
                    break;

                case 'ORDER_ITEM_ORDERED':
                case 'ORDER_ITEM_FULFILLED':
                case 'ORDER_ITEM_RETURNED':
                case 'ORDER_ITEM_DELETED':
                case 'ORDER_ITEM_UPDATED':
                    $this->handleOrderItemEvent($payload);
                    break;

                case 'PRODUCT_CREATED':
                case 'PRODUCT_UPDATED':
                    $this->handleProductEvent($payload);
                    break;

                case 'PRODUCT_DELETED':
                    $this->handleProductDeletion($payload);
                    break;

                case 'STORE_CREATED':
                case 'STORE_UPDATED':
                    $this->handleStoreEvent($payload);
                    break;

                case 'TAX_CREATED':
                case 'TAX_UPDATED':
                    $this->handleTaxEvent($payload);
                    break;

                case 'TAX_DELETED':
                    $this->handleTaxDeletion($payload);
                    break;

                case 'TRANSACTION_AUTHORIZED':
                case 'TRANSACTION_PENDING':
                case 'TRANSACTION_CAPTURED':
                case 'TRANSACTION_REFUNDED':
                case 'TRANSACTION_UPDATED':
                case 'TRANSACTION_VOIDED':
                    $this->handleTransactionEvent($payload);
                    break;

                case 'USER_CREATED':
                case 'USER_JOIN_ORG':
                case 'USER_LEAVE_ORG':
                case 'USER_JOIN_BIZ':
                case 'USER_LEAVE_BIZ':
                case 'USER_UPDATED':
                    $this->handleUserEvent($payload);
                    break;

                default:
                    $responseStatus = Response::STATUS_BAD_REQUEST;
                    $responseBody = ['error' => 'Unrecognized event'];
                    $errorMessage = 'Unrecognized event';
                    break;
            }
        } catch (\Throwable $e) {
            $responseStatus = Response::STATUS_INTERNAL_SERVER_ERROR;
            $responseBody = ['error' => 'Processing error'];
            $errorMessage = $e->getMessage();
        }

        // Update audit record after processing
        $conn->update('webhook_audit', [
            'processed' => true,
            'error_message' => $errorMessage,
        ], [
            'id' => $auditId,
        ], [
            'processed' => PDO::PARAM_BOOL,
        ]);

        // Respond to acknowledge the event has been processed.
        Api::response($responseStatus, $responseBody);
    }

    /**
     * Handles subscription start event.
     *
     * @param array $payload
     */
    private function handleSubscriptionStart(array $payload)
    {
        $subscriptionId = $payload['subscriptionId'] ?? null;
        $businessId = $payload['businessId'] ?? null;
        $storeId = $payload['storeId'] ?? null;

        if (!$subscriptionId || !$businessId || !$storeId) {
            throw new \InvalidArgumentException('Missing subscription data');
        }

        $this->subscriptionService->activateSubscription($subscriptionId, $businessId, $storeId);
    }

    /**
     * Handles subscription end event.
     *
     * @param array $payload
     */
    private function handleSubscriptionEnd(array $payload)
    {
        $subscriptionId = $payload['subscriptionId'] ?? null;
        $businessId = $payload['businessId'] ?? null;
        $storeId = $payload['storeId'] ?? null;

        if (!$subscriptionId || !$businessId || !$storeId) {
            throw new \InvalidArgumentException('Missing subscription data');
        }

        $this->subscriptionService->cancelSubscription($subscriptionId, $businessId, $storeId);
    }

    private function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    public function setSubscriptionService(SubscriptionService $subscriptionService): void
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function setServiceFactory(ServiceFactory $serviceFactory): void
    {
        $this->serviceFactory = $serviceFactory;
    }

    private function getServiceFactory(): ServiceFactory
    {
        if ($this->serviceFactory === null) {
            $this->serviceFactory = new ServiceFactory($this->context);
        }

        return $this->serviceFactory;
    }

    private function processResourceEvent(array $payload, string $resource): void
    {
        $normalizedResource = strtolower($resource);
        if (!isset(self::RESOURCE_SERVICE_MAP[$normalizedResource])) {
            $this->context->getLog()->warning('Webhook dispatch: unsupported resource', [
                'resource' => $resource,
                'eventType' => $payload['eventType'] ?? null,
            ]);

            return;
        }

        $eventType = (string)($payload['eventType'] ?? '');
        $isDelete = $this->isDeleteEvent($eventType);
        $entity = $this->resourceFetcher->getFullEntity($payload);
        $resourceId = $this->resolveResourceIdFromPayload($payload, $entity);
        $businessId = $this->resolveBusinessIdForResource($payload, $entity);

        if ($isDelete) {
            if ($resourceId === null) {
                $this->context->getLog()->error('Webhook dispatch: missing resource id for delete', [
                    'resource' => $resource,
                    'eventType' => $eventType,
                ]);

                return;
            }

            $this->dispatchDelete($normalizedResource, $resourceId, $businessId);

            return;
        }

        if ($entity === null) {
            $this->context->getLog()->warning('Webhook dispatch: missing entity payload', [
                'resource' => $resource,
                'eventType' => $eventType,
            ]);

            return;
        }

        if ($resourceId !== null && !isset($entity['id'])) {
            $entity['id'] = $resourceId;
        }

        $config = self::RESOURCE_SERVICE_MAP[$normalizedResource];
        if ($config['businessScoped']) {
            if ($businessId === null) {
                $this->context->getLog()->error('Webhook dispatch: missing businessId', [
                    'resource' => $resource,
                    'eventType' => $eventType,
                ]);

                return;
            }

            if (!isset($entity['businessId'])) {
                $entity['businessId'] = $businessId;
            }
        } elseif ($businessId !== null && !isset($entity['businessId'])) {
            $entity['businessId'] = $businessId;
        }

        if (!isset($entity['id'])) {
            $this->context->getLog()->error('Webhook dispatch: entity missing id', [
                'resource' => $resource,
                'eventType' => $eventType,
            ]);

            return;
        }

        if ($config['businessScoped'] && !isset($entity['businessId'])) {
            $this->context->getLog()->error('Webhook dispatch: entity missing businessId', [
                'resource' => $resource,
                'eventType' => $eventType,
            ]);

            return;
        }

        $this->dispatchUpsert($normalizedResource, $entity, $businessId);
    }

    private function resolveResourceIdFromPayload(array $payload, ?array $entity): ?string
    {
        if (is_array($entity) && isset($entity['id'])) {
            $id = $entity['id'];
            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                return (string) $id;
            }
        }

        $candidates = [
            $payload['resourceId'] ?? null,
            $payload['id'] ?? null,
            $this->extractContextValue($payload, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if ((is_string($candidate) || is_int($candidate)) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function resolveBusinessIdForResource(array $payload, ?array $entity): ?string
    {
        if (is_array($entity) && isset($entity['businessId'])) {
            $businessId = $entity['businessId'];
            if ((is_string($businessId) || is_int($businessId)) && (string) $businessId !== '') {
                return (string) $businessId;
            }
        }

        $candidates = [
            $payload['businessId'] ?? null,
            $this->extractContextValue($payload, 'businessId'),
        ];

        foreach ($candidates as $candidate) {
            if ((is_string($candidate) || is_int($candidate)) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function dispatchUpsert(string $resource, array $entity, ?string $businessId): void
    {
        $service = $this->resolveService($resource, $entity['businessId'] ?? $businessId);
        if ($service === null) {
            $this->context->getLog()->warning('Webhook dispatch: missing service for upsert', [
                'resource' => $resource,
            ]);

            return;
        }

        if (!method_exists($service, 'upsert')) {
            $this->context->getLog()->warning('Webhook dispatch: service lacks upsert method', [
                'resource' => $resource,
                'service' => $service::class,
            ]);

            return;
        }

        try {
            $service->upsert($entity);
        } catch (\Throwable $exception) {
            $this->context->getLog()->error('Webhook dispatch: upsert failed', [
                'resource' => $resource,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatchDelete(string $resource, string $id, ?string $businessId): void
    {
        $service = $this->resolveService($resource, $businessId);
        if ($service === null) {
            $this->context->getLog()->warning('Webhook dispatch: missing service for delete', [
                'resource' => $resource,
            ]);

            return;
        }

        if (!method_exists($service, 'delete')) {
            $this->context->getLog()->info('Webhook dispatch: delete skipped, method not available', [
                'resource' => $resource,
                'service' => $service::class,
                'id' => $id,
            ]);

            return;
        }

        try {
            $service->delete($id, $businessId);
        } catch (\Throwable $exception) {
            $this->context->getLog()->error('Webhook dispatch: delete failed', [
                'resource' => $resource,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveService(string $resource, ?string $businessId): object|null
    {
        $config = self::RESOURCE_SERVICE_MAP[$resource] ?? null;
        if ($config === null) {
            return null;
        }

        $factory = $this->getServiceFactory();
        $method = $config['factory'];

        if (!method_exists($factory, $method)) {
            $this->context->getLog()->warning('Webhook dispatch: factory method missing', [
                'resource' => $resource,
                'method' => $method,
            ]);

            return null;
        }

        return $factory->{$method}($businessId);
    }

    private function isDeleteEvent(string $eventType): bool
    {
        return str_ends_with($eventType, '_DELETED');
    }

    private function handleBusinessUserEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'businessUser');
    }

    private function handleCatalogEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'catalog');
    }

    private function handleCatalogDeletion(array $payload): void
    {
        $this->processResourceEvent($payload, 'catalog');
    }

    private function handleCategoryEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'category');
    }

    private function handleCategoryDeletion(array $payload): void
    {
        $this->processResourceEvent($payload, 'category');
    }

    private function handleInventoryEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'inventory');
    }

    private function handleOrderEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'order');
    }

    private function handleOrderItemEvent(array $payload): void
    {
        $orderItem = $this->extractResource($payload, [
            ['orderItem'],
            ['item'],
        ]);

        if ($orderItem === null) {
            // Some payloads include the full order instead of the specific item.
            $order = $this->extractResource($payload, [
                ['order'],
            ]);

            if ($order !== null) {
                $this->handleOrderEvent(array_merge(['order' => $order], $payload));
            } else {
                $this->context->getLog()->warning('Webhook order item event missing payload', $payload);
            }

            return;
        }

        $orderItem = $this->enrichResourceWithContext($orderItem, $payload, ['businessId', 'orderId']);

        $businessId = $orderItem['businessId'] ?? $payload['businessId'] ?? null;
        $storeId = $orderItem['storeId'] ?? $payload['storeId'] ?? null;
        $orderId = $orderItem['orderId'] ?? $payload['orderId'] ?? null;

        $orderPayload = array_filter([
            'id' => $orderId,
            'businessId' => $businessId,
            'storeId' => $storeId,
            'items' => [$orderItem],
            'context' => [
                'businessId' => $businessId,
                'storeId' => $storeId,
            ],
        ], static fn($value) => $value !== null);

        $service = $this->getServiceFactory()->order($businessId);
        $service->upsert($orderPayload);
    }

    private function handleProductEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'product');
    }

    private function handleProductDeletion(array $payload): void
    {
        $this->processResourceEvent($payload, 'product');
    }

    private function handleStoreEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'store');
    }

    private function handleTaxEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'tax');
    }

    private function handleTaxDeletion(array $payload): void
    {
        $this->processResourceEvent($payload, 'tax');
    }

    private function handleTransactionEvent(array $payload): void
    {
        $this->processResourceEvent($payload, 'transaction');
    }

    private function handleUserEvent(array $payload): void
    {
        $this->context->getLog()->info('User webhook received', $payload);
    }

    private function handleSubscriptionLifecycleEvent(array $payload): void
    {
        $subscription = $this->extractResource($payload, [
            ['subscription'],
        ]);

        if ($subscription === null) {
            $this->context->getLog()->warning('Subscription lifecycle webhook missing payload', $payload);

            return;
        }

        $subscription = $this->enrichResourceWithContext($subscription, $payload, ['businessId', 'storeId']);

        $storeId = $subscription['storeId'] ?? null;
        $this->subscriptionService->upsertLocalSubscription($subscription, $storeId);
    }

    /**
     * @param array $payload
     * @param array<int, array<int, string>> $preferredPaths
     */
    private function extractResource(array $payload, array $preferredPaths = []): ?array
    {
        foreach ($preferredPaths as $path) {
            $value = $this->resolveNestedValue($payload, (array)$path);
            if (is_array($value)) {
                return $value;
            }
        }

        $fallbackPaths = [
            ['payload'],
            ['data'],
            ['resource'],
            ['body'],
            ['eventPayload'],
            ['object'],
        ];

        foreach ($fallbackPaths as $path) {
            if (!empty($preferredPaths)) {
                foreach ($preferredPaths as $preferredPath) {
                    $combinedPath = array_merge($path, (array)$preferredPath);
                    $value = $this->resolveNestedValue($payload, $combinedPath);
                    if (is_array($value)) {
                        return $value;
                    }
                }
            }

            $value = $this->resolveNestedValue($payload, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        if (isset($payload['id']) && is_array($payload)) {
            return $payload;
        }

        return null;
    }

    /**
     * @param array $payload
     * @param array<int, string> $path
     */
    private function resolveNestedValue(array $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array $resource
     * @param array $payload
     * @param array $keys
     * @return array
     */
    private function enrichResourceWithContext(array $resource, array $payload, array $keys = []): array
    {
        $defaultKeys = ['businessId', 'storeId', 'storeDeviceId'];
        $keys = array_values(array_unique(array_merge($defaultKeys, $keys)));

        foreach ($keys as $key) {
            if (!isset($resource[$key])) {
                $value = $this->extractContextValue($payload, $key);
                if ($value !== null) {
                    $resource[$key] = $value;
                }
                if (!isset($resource[$key]) && isset($payload[$key])) {
                    $resource[$key] = $payload[$key];
                }
            }

            return $resource;
        }
    }

    /**
     * @param array $payload
     * @param string $key
     * @return mixed
     */
    function extractContextValue(array $payload, string $key): mixed
    {
        if (isset($payload[$key])) {
            return $payload[$key];
        }

        $fallbackPaths = [
            ['payload'],
            ['data'],
            ['resource'],
            ['body'],
            ['eventPayload'],
            ['object'],
        ];

        foreach ($fallbackPaths as $path) {
            $value = $this->resolveNestedValue($payload, array_merge($path, [$key]));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return void
     */
    public function deleteWebhooksByBusinessId(string $businessId): void
    {
        $webhookService = new WebhookService($this->context);

        if ($webhookService->deleteAllByBusinessId($businessId)) {
            Api::response(Response::STATUS_OK);
        }
        Api::response(Response::STATUS_BAD_REQUEST, ['error' => 'Something went wrong.']);
    }
}









