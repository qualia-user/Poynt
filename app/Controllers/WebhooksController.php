<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Services\SubscriptionService;
use PDO;

class WebhooksController extends Controller
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
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
        $headers = getallheaders();
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
                case 'CATALOG_DELETED':
                    $responseBody = null;
                    break;

                case 'APPLICATION_SUBSCRIPTION_START':
                    $this->handleSubscriptionStart($payload);
                    break;

                case 'APPLICATION_SUBSCRIPTION_END':
                    $this->handleSubscriptionEnd($payload);
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

        $service = new SubscriptionService($this->context);
        $service->activateSubscription($subscriptionId, $businessId, $storeId);
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

        $service = new SubscriptionService($this->context);
        $service->cancelSubscription($subscriptionId, $businessId, $storeId);
    }

}
