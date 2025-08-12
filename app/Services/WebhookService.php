<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Config\ConfigApp;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use PDO;

class WebhookService
{
    protected $context;
    private $businessId;

    public const POYNT_WEBHOOK_URL = 'https://services.poynt.net/hooks';

        /**
     * Constructor.
     *
     * @param object $context An object containing dependencies (like logger, config, etc.)
     */
    public function __construct($context, $businessId = null)
    {
        $this->context = $context;
        $this->businessId = $businessId;
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
        $client = new Client();

        // Build the payload
        $payload = [
            'applicationId' => 'urn:aid:' . ConfigApp::$appId,
            'businessId'    => ConfigApp::$orgId, // Developer organization ID, NOT business ID
            'deliveryUrl'   => ConfigApp::$webRootUrl . '/webhooks/event-listener',
            'eventTypes'    => $events
        ];

        try {
            $response = $client->post(self::POYNT_WEBHOOK_URL, [
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
                'payload'       => json_encode($payload),
                'headers'       => json_encode($response->getHeaders()),
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
                'payload'       => json_encode($payload),
                'headers'       => json_encode($e->hasResponse() ? $e->getResponse()->getHeaders() : []),
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

}
