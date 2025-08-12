<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;

class WebhooksController extends Controller
{
    private $context;
    public function __construct($api, $conn, $log)
    {
        parent::__construct($api, $conn, $log);
        $this->context = new Context($api, $conn, $log);
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

        // Route to the appropriate handler based on event type.
        switch ($eventType) {
            case 'CATALOG_CREATED':
            case 'CATALOG_UPDATED':
            case 'CATALOG_DELETED':

                Api::response(Response::STATUS_OK, null, true);
                break;

            case 'APPLICATION_SUBSCRIPTION_START':
                $this->handleSubscriptionStart($payload);
                break;

            case 'APPLICATION_SUBSCRIPTION_END':
                $this->handleSubscriptionEnd($payload);
                break;

            default:
                header("Content-Type: application/json", true, 400);
                echo json_encode(['error' => 'Unrecognized event']);
                return;
        }

        // Respond to acknowledge the event has been processed.
        header("Content-Type: application/json", true, 200);
        echo json_encode(['status' => 'ok']);
    }

    /**
     * Handles subscription start event.
     *
     * @param array $payload
     */
    private function handleSubscriptionStart(array $payload)
    {
        // Todo
    }

    /**
     * Handles subscription end event.
     *
     * @param array $payload
     */
    private function handleSubscriptionEnd(array $payload)
    {
        // Todo
    }

}