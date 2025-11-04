<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Services\ServiceFactory;
use App\Services\SubscriptionService;
use PDO;

class WebhooksController extends Controller
{
    private SubscriptionService $subscriptionService;
    private ?ServiceFactory $serviceFactory = null;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->subscriptionService = new SubscriptionService($context);
    }

    /**
     * Webhook event listener.
     * Processes incoming webhook events using pre-stored request data.
     *
     * @return void
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
        $businessId     = $payload['businessId']     ?? null;
        $storeId        = $payload['storeId']        ?? null;

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
        $businessId     = $payload['businessId']     ?? null;
        $storeId        = $payload['storeId']        ?? null;

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

    private function handleBusinessUserEvent(array $payload): void
    {
        $businessUser = $this->extractResource($payload, [
            ['businessUser'],
            ['user'],
        ]);

        if ($businessUser === null) {
            $this->context->getLog()->warning('Webhook business user event missing payload', $payload);

            return;
        }

        $businessUser = $this->enrichResourceWithContext($businessUser, $payload, ['businessId']);

        $service = $this->getServiceFactory()->businessUser($businessUser['businessId'] ?? null);
        $service->upsert($businessUser);
    }

    private function handleCatalogEvent(array $payload): void
    {
        $catalog = $this->extractResource($payload, [
            ['catalog'],
        ]);

        if ($catalog === null) {
            $this->context->getLog()->warning('Webhook catalog event missing payload', $payload);

            return;
        }

        $catalog = $this->enrichResourceWithContext($catalog, $payload, ['businessId']);

        $service = $this->getServiceFactory()->catalog($catalog['businessId'] ?? null);
        $service->upsert($catalog);
    }

    private function handleCatalogDeletion(array $payload): void
    {
        $this->context->getLog()->info('Catalog deletion webhook received', $payload);
    }

    private function handleCategoryEvent(array $payload): void
    {
        $category = $this->extractResource($payload, [
            ['category'],
        ]);

        if ($category === null) {
            $this->context->getLog()->warning('Webhook category event missing payload', $payload);

            return;
        }

        $category = $this->enrichResourceWithContext($category, $payload, ['businessId']);

        $service = $this->getServiceFactory()->category($category['businessId'] ?? null);
        $service->upsert($category);
    }

    private function handleCategoryDeletion(array $payload): void
    {
        $this->context->getLog()->info('Category deletion webhook received', $payload);
    }

    private function handleInventoryEvent(array $payload): void
    {
        $inventory = $this->extractResource($payload, [
            ['inventory'],
        ]);

        if ($inventory === null) {
            $this->context->getLog()->warning('Webhook inventory event missing payload', $payload);

            return;
        }

        $inventory = $this->enrichResourceWithContext($inventory, $payload, ['businessId', 'storeId']);

        $service = $this->getServiceFactory()->inventory($inventory['businessId'] ?? null);
        $service->upsert($inventory);
    }

    private function handleOrderEvent(array $payload): void
    {
        $order = $this->extractResource($payload, [
            ['order'],
        ]);

        if ($order === null) {
            $this->context->getLog()->warning('Webhook order event missing payload', $payload);

            return;
        }

        $order = $this->enrichResourceWithContext($order, $payload, ['businessId', 'storeId']);

        $service = $this->getServiceFactory()->order($order['businessId'] ?? null);
        $service->upsert($order);
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
        $product = $this->extractResource($payload, [
            ['product'],
        ]);

        if ($product === null) {
            $this->context->getLog()->warning('Webhook product event missing payload', $payload);

            return;
        }

        $product = $this->enrichResourceWithContext($product, $payload, ['businessId']);

        $service = $this->getServiceFactory()->product($product['businessId'] ?? null);
        $service->upsert($product);
    }

    private function handleProductDeletion(array $payload): void
    {
        $this->context->getLog()->info('Product deletion webhook received', $payload);
    }

    private function handleStoreEvent(array $payload): void
    {
        $store = $this->extractResource($payload, [
            ['store'],
        ]);

        if ($store === null) {
            $this->context->getLog()->warning('Webhook store event missing payload', $payload);

            return;
        }

        $store = $this->enrichResourceWithContext($store, $payload, ['businessId']);

        $service = $this->getServiceFactory()->store($store['businessId'] ?? null);
        $service->upsert($store);
    }

    private function handleTaxEvent(array $payload): void
    {
        $tax = $this->extractResource($payload, [
            ['tax'],
        ]);

        if ($tax === null) {
            $this->context->getLog()->warning('Webhook tax event missing payload', $payload);

            return;
        }

        $tax = $this->enrichResourceWithContext($tax, $payload, ['businessId']);

        $service = $this->getServiceFactory()->tax($tax['businessId'] ?? null);
        $service->upsert($tax);
    }

    private function handleTaxDeletion(array $payload): void
    {
        $this->context->getLog()->info('Tax deletion webhook received', $payload);
    }

    private function handleTransactionEvent(array $payload): void
    {
        $transaction = $this->extractResource($payload, [
            ['transaction'],
        ]);

        if ($transaction === null) {
            $this->context->getLog()->warning('Webhook transaction event missing payload', $payload);

            return;
        }

        $transaction = $this->enrichResourceWithContext($transaction, $payload, ['businessId']);

        $receipt = $this->extractResource($payload, [
            ['receipt'],
            ['transaction', 'receipt'],
        ]);

        $service = $this->getServiceFactory()->transaction($transaction['businessId'] ?? null);
        $service->upsert($transaction, $receipt);
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
            $value = $this->resolveNestedValue($payload, (array) $path);
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
                    $combinedPath = array_merge($path, (array) $preferredPath);
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

    private function enrichResourceWithContext(array $resource, array $payload, array $keys = []): array
    {
        $defaultKeys = ['businessId', 'storeId', 'storeDeviceId'];
        $keys = array_values(array_unique(array_merge($defaultKeys, $keys)));

        foreach ($keys as $key) {
            if (!isset($resource[$key]) && isset($payload[$key])) {
                $resource[$key] = $payload[$key];
            }
        }

        return $resource;
    }

}
