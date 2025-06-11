<?php
namespace App\Fetchers;

use App\Config\ConfigApp;
use Doctrine\DBAL\Driver\IBMDB2\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class MerchantFetcher
{
    private $httpClient;
    private $log;

    const POYNT_ENDPOINT_API = 'https://services.poynt.net/businesses';
    const POYNT_ENDPOINT_GET_BUSINESS = 'https://services.poynt.net/businesses';
    const POYNT_ENDPOINT_GET_ORDERS = 'https://services.poynt.net/businesses';

    public function __construct(LoggerInterface $log)
    {
        $this->httpClient = new Client();
        $this->log = $log;
    }

    /**
     * In order to provide GoDaddy Poynt merchants with the ability to subscribe to your app
     * directly from your web application, you must connect with the billing system first to
     * fetch your active billing plans.
     *
     * @param string $accessToken
     * @return array|null
     */
    public function fetchApplicationSubscriptionPlans(string $accessToken): ?array
    {
        try {
            $orgId = ConfigApp::$orgId;
            $appId = ConfigApp::$appId;

            // Define the endpoint for fetching plans
            $url = "https://billing.poynt.net/organizations/{$orgId}/apps/{$appId}/plans";

            // Set up request headers
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            // Make the GET request to the Poynt API
            $response = $this->httpClient->get($url, $options);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Return the plans list if available
            return $data ?? null;
        } catch (GuzzleException $e) {
            // Log any errors encountered
            $this->log->error("Error fetching subscription plans: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Fetch merchant business details from the API.
     *
     * @param string $accessToken OAuth access token.
     * @return array|null Merchant details or null on failure.
     */
    public function fetchMerchantBusinessById(string $accessToken, string $businessId): ?array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API . '/' . $businessId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? null; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->log->error("Error fetching merchant details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch merchant business details from the API.
     *
     * @param string $accessToken OAuth access token.
     * @return array|null Merchant details or null on failure.
     */
    public function fetchMerchantBusiness(string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? null; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->log->error("Error fetching merchant details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @param string $accessToken
     * @param string $businessId
     * @return array|null
     */
    public function fetchOrders(string $accessToken, string $businessId): ?array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API . '/' . $businessId . '/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? null; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->log->error("Error fetching merchant details: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Use the POST endpoint to create, downgrade, and upgrade subscriptions on behalf of the merchant.
     *
     * @param string $merchantAccessToken
     * @param string $businessId
     * @param string $planId
     * @param bool $replaceV2
     * @param string|null $startAt
     * @param array $addOns
     * @param string|null $channelAttribution
     * @return array|null
     */
    public function createOrUpdateSubscription(
        string $merchantAccessToken,
        string $businessId,
        string $planId,
        bool $replaceV2 = false,
        ?string $startAt = null,
        array $addOns = [],
        ?string $channelAttribution = null
    ): ?array {
        try {
            $orgId = ConfigApp::$orgId;
            $appId = ConfigApp::$appId;

            // Define the endpoint
            $url = "https://billing.poynt.net/organizations/{$orgId}/apps/{$appId}/subscriptions";

            // Prepare request body
            $payload = [
                "businessId" => $businessId,
                "orgId" => $orgId,
                "planId" => $planId,
                "replaceV2" => $replaceV2,
            ];

            // Add optional fields if provided
            if ($startAt) {
                $payload["startAt"] = $startAt;
            }
            if (!empty($addOns)) {
                $payload["addOns"] = $addOns;
            }
            if ($channelAttribution) {
                $payload["channelAttribution"] = $channelAttribution;
            }

            // Set up request options
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ];

            // Execute POST request
            $response = $this->httpClient->post($url, $options);

            // Decode JSON response
            $data = json_decode($response->getBody(), true);

            return $data ?? null;
        } catch (GuzzleException $e) {
            // Log any errors encountered
            $this->log->error("Error creating/updating subscription: " . $e->getMessage());
            return null;
        }
    }


    public function deleteSubscription(
        string $merchantAccessToken,
        string $subscriptionId
    ): bool {
        try {
            $orgId = ConfigApp::$orgId;
            $appId = ConfigApp::$appId;

            // Define the API endpoint
            $url = "https://billing.poynt.net/organizations/{$orgId}/apps/{$appId}/subscriptions/{$subscriptionId}";

            // Set up request options
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Content-Type'  => 'application/json',
                ],
            ];

            // Make the DELETE request
            $response = $this->httpClient->delete($url, $options);

            // Check if the deletion was successful (204 No Content or 200 OK)
            return in_array($response->getStatusCode(), [200, 204], true);
        } catch (GuzzleException $e) {
            // Log the error
            $this->log->error("Error deleting subscription: " . $e->getMessage());
            return false;
        }
    }


    /**
     * @param string $merchantAccessToken
     * @param string $businessId
     * @param string|null $storeId
     * @param string|null $deviceId
     * @param string|null $status
     * @return array|null
     */
    public function fetchMerchantSubscription(
        string $merchantAccessToken,
        string $businessId,
        ?string $storeId = null,
        ?string $deviceId = null,
        ?string $status = null
    ): ?array {
        try {
            $orgId = ConfigApp::$orgId;
            $appId = ConfigApp::$appId;

            // Define the base endpoint
            $url = "https://billing.poynt.net/organizations/{$orgId}/apps/{$appId}/subscriptions";

            // Prepare query parameters
            $queryParams = [
                'businessId' => $businessId, // Required
            ];

            // Include optional parameters only if they are provided
            if ($storeId) {
                $queryParams['storeId'] = $storeId;
            }
            if ($deviceId) {
                $queryParams['deviceId'] = $deviceId;
            }
            if ($status) {
                $queryParams['status'] = $status;
            }

            // Set up request options
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Content-Type'  => 'application/json',
                ],
                'query' => $queryParams, // Pass query parameters dynamically
            ];

            // Make the GET request
            $response = $this->httpClient->get($url, $options);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            return $data ?? null;
        } catch (GuzzleException $e) {
            // Log any errors encountered
            $this->log->error("Error fetching merchant subscriptions: " . $e->getMessage());
            return null;
        }
    }



    public function fetchSubscriptionStatus(string $accessToken, string $businessId): ?array
    {
        try {
            // Define the endpoint for fetching subscriptions
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API . '/' . $businessId . '/subscriptions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Return the subscription data if available
            return $data ?? null;
        } catch (GuzzleException $e) {
            // Log any errors encountered
            $this->log->error("Error fetching subscription status: " . $e->getMessage());
            return null;
        }
    }


}
