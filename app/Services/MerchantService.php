<?php

namespace App\Services;

use AllowDynamicProperties;
use App\Core\Context;
use App\Fetchers\MerchantFetcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use League\Container\Container;
use Ramsey\Uuid\Uuid;
use App\Core\Api;
use Monolog\Logger;

class MerchantService {

//    private Api $api;
//    private Connection $conn;
//    private Logger $log;
//    private MerchantFetcher $merchantFetcher;

    private Context $context;
    private $businessId;


    const POYNT_ENDPOINT_API_BUSINESS = 'https://services.poynt.net/businesses';

//    public function __construct(Api $api, Connection $conn, Logger $log, MerchantFetcher $merchantFetcher) {
//        $this->api = $api;
//        $this->conn = $conn;
//        $this->log = $log;
//
//        $this->merchantFetcher = $merchantFetcher;
//    }

    public function __construct(Context $context, $businessId = null)
    {
        $this->context = $context;
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    /**
     * Insert a new merchant row or update the existing one, based on business_id.
     *
     * @param array $merchantData  Associated array with keys such as:
     *                             - 'businessId' (string),
     *                             - 'storeId'    (string),
     *                             - 'name'       (string),
     *
     * @return bool  True on success, false on failure.
     */
    public function upsert(array $merchantData): bool
    {
        // 1) Validate required keys
        if (!isset($merchantData['id'], $merchantData['legalName'])) {
            $this->context->getLog()->error(
                'MerchantService::upsert: missing required merchantData fields (id or legalName)'
            );
            return false;
        }

        $businessId = $merchantData['id'];
        $name = $merchantData['legalName'];

        // 1.a) Decide on "active". If the payload does not include it, default to true.
        $active = !isset($merchantData['active']) || (bool)$merchantData['active'];

        // 2) Prepare the full payload as JSON for the metadata column
        $metadata = json_encode($merchantData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "MerchantService::upsert: failed to json_encode merchantData for business_id={$businessId}"
            );
            return false;
        }

        // 3) Current timestamp (PostgreSQL TIMESTAMPTZ)
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            // 4) See if a business row with this business_id already exists
            $existing = $this->context->getConn()->fetchAssociative(
                'SELECT business_id FROM business WHERE business_id = ?',
                [$businessId]
            );

            if ($existing) {
                // 5a) UPDATE path (include 'active' here)
                $this->context->getConn()->update(
                    'business',
                    [
                        'name' => $name,
                        'metadata' => $metadata,
                        'updated_at' => $now,
                        'active' => $active,
                    ],
                    ['business_id' => $businessId]
                );

                $this->context->getLog()->info("MerchantService::upsert: updated business {$businessId}");
            } else {
                // 5b) INSERT path (include 'active' here, too)
                $this->context->getConn()->insert(
                    'business',
                    [
                        'business_id' => $businessId,
                        'name' => $name,
                        'metadata' => $metadata,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'active' => $active,
                    ]
                );

                $this->context->getLog()->info("MerchantService::upsert: inserted new business {$businessId}");
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "MerchantService::upsert: database error for business_id={$businessId}: "
                . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch and save merchant business.
     *
     * @param string $businessId
     * @return array|false Success(array) or failure(bool).
     */
    public function fetchMerchantBusinessById(string $businessId): array|false
    {
        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        // TODO remove all Guzzle things into separated class?

        $httpClient = new Client();

        try {
            $response = $httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching merchant details: " . $e->getMessage());
            return false;
        }

//
//        $merchantService = new MerchantService($this->context);
//
//        $merchantBusiness = $merchantService->fetchMerchantBusinessById($accessToken, $businessId);
//        if (!$merchantBusiness) {
//            $this->context->log->error("Failed to fetch merchant business.");
//            return false;
//        }
//        return $merchantBusiness;
    }

    /**
     * @param string|null $businessId
     * @return bool
     */
    public function merchantExists(string $businessId = null): bool
    {
        if (is_null($businessId)) {
            $businessId = $this->businessId;
        }

        $sql = <<<SQL
        SELECT * FROM business WHERE business_id = ?
        SQL;

        $success = $this->context->getConn()->fetchAssociative($sql, [$businessId]);

        if (is_array($success)) {
            return true;
        }

        return false;

    }


    /**
     * @param string|null $businessId
     * @return array
     */
    public function fetchBusinessStores(string $businessId = null): array
    {
        if (is_null($businessId)) {
            $businessId = $this->businessId;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        $httpClient = new Client();

        try {
            $response = $httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId . '/stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? []; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching merchant details: " . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array $stores
     * @return bool
     */
    public function upsertStores(array $stores): bool
    {
        if (empty($stores)) {
            return true;
        }

        // TODO ask GPT is there a way to upsert in this format(DBALL)
        /**
         * $this->context->getConn()->insert('webhook_audit', [
         * 'event_type'    => 'WEBHOOK_REGISTRATION',
         * 'payload'       => json_encode($payload),
         * 'headers'       => json_encode($e->hasResponse() ? $e->getResponse()->getHeaders() : []),
         * 'processed'     => false,
         * 'error_message' => $errorMsg,
         * ]);
         *
         */
        $sql = <<<SQL
        INSERT INTO store
            (store_id, business_id, name, metadata, created_at, updated_at)
        VALUES
            (:storeId, :businessId, :name, :metadata, :createdAt, :updatedAt)
        ON CONFLICT (store_id) DO UPDATE SET
            name       = EXCLUDED.name,
            metadata   = EXCLUDED.metadata,
            updated_at = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);

            $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

            foreach ($stores as $store) {
                if (
                    !isset($store['id'], $store['businessId'], $store['displayName'])
                ) {
                    $this->context->getLog()->error(
                        'MerchantService::insertStores: missing required store fields '
                        . '(id, businessId, or displayName)'
                    );
                    return false;
                }

                $storeId    = $store['id'];
                $businessId = $store['businessId'];
                $name       = $store['displayName'];

                $metadata = json_encode($store);
                if ($metadata === false) {
                    $this->context->getLog()->error(
                        "MerchantService::insertStores: failed to json_encode store for store_id={$storeId}"
                    );
                    return false;
                }

                $stmt->execute([
                    'storeId'    => $storeId,
                    'businessId' => $businessId,
                    'name'       => $name,
                    'metadata'   => $metadata,
                    'createdAt'  => $now,
                    'updatedAt'  => $now,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "MerchantService::insertStores: database error while inserting/updating stores: "
                . $e->getMessage()
            );
            return false;
        }
    }


    /**
     * Fetch and save merchant business.
     *
     * @param string $accessToken OAuth access token.
     * @return array|false Success(array) or failure(bool).
     */
    public function fetchMerchantBusiness(string $accessToken): array|false
    {
        $merchantBusiness = $this->fetchMerchantBusiness($accessToken);
        if (!$merchantBusiness) {
            $this->log->error("Failed to fetch merchant business.");
            return false;
        }
        return $merchantBusiness;
    }

    /**
     * Fetch merchant orders.
     *
     * @param string $accessToken
     * @param string $businessId
     * @return array|false
     */
    public function fetchMerchantOrders(string $accessToken, string $businessId): array|false
    {
        $merchantOrders = $this->merchantFetcher->fetchOrders($accessToken, $businessId);
        if (!$merchantOrders) {
            $this->log->error("Failed to fetch merchant orders.");
            return false;
        }
        return $merchantOrders;
    }

    /**
     * @param string $accessToken
     * @param string $businessId
     * @return array|false
     */
    public function _fetchMerchantSubscription(string $accessToken, string $businessId): array|false
    {
        $merchantSubscription = $this->merchantFetcher->fetchSubscriptionStatus($accessToken, $businessId);
        if (!$merchantSubscription) {
            $this->log->error("Failed to fetch merchant subscription.");
            return false;
        }
        return $merchantSubscription;
    }

    /**
     * @param string $accessToken
     * @return array|false
     */
    public function fetchApplicationSubscriptionPlans(string $accessToken): array|false
    {
        $applicationPlans = $this->merchantFetcher->fetchApplicationSubscriptionPlans($accessToken);
        if (!$applicationPlans) {
            $this->log->error("Failed to fetch application subscription plans.");
            return false;
        }
        return $applicationPlans;
    }

    /**
     * @param $accessToken
     * @param $businessId
     * @param $planId
     * @return array|false
     */
    public function createOrUpdateSubscription($accessToken, $businessId, $planId): array|false
    {
        $subscription = $this->merchantFetcher->createOrUpdateSubscription($accessToken, $businessId, $planId);
        if (!$subscription) {
            $this->log->error("Failed to fetch application subscription.");
            return false;
        }
        return $subscription;
    }


    /**
     * @param $accessToken
     * @param $subscriptionId
     * @return array|false
     */
    public function deleteSubscription($accessToken, $subscriptionId): array|false
    {
        $subscriptionDeleted = $this->merchantFetcher->deleteSubscription($accessToken, $subscriptionId);
        if (!$subscriptionDeleted) {
            $this->log->error("Failed to delete subscription.");
            return false;
        }
        return $subscriptionDeleted;
    }

    /**
     * @param $accessToken
     * @param $businessId
     * @param $storeId
     * @param $deviceId
     * @return array|false
     */
    public function fetchMerchantSubscription($accessToken, $businessId, $storeId = null, $deviceId = null): array|false
    {
        $subscriptions = $this->merchantFetcher->fetchMerchantSubscription($accessToken, $businessId, $storeId, $deviceId);
        if (!$subscriptions) {
            $this->log->error("Failed to fetch merchants subscriptions.");
            return false;
        }
        return $subscriptions;
    }


    /**
     * @param array $merchantDetails Details to be saved
     * @param string $storeId Store ID for the merchant.
     * @param array $tokenResponse Token response containing access/refresh tokens.
     * @return bool
     */
    public function saveMerchant(array $merchantDetails, string $storeId, array $tokenResponse): bool
    {
        try {
            $businessId = $merchantDetails['id'];
            $name = $merchantDetails['doingBusinessAs'];

            // Collect all additional metadata
            $metadata = [
                'legal_name' => $merchantDetails['legalName'] ?? null,
                'phone_number' => $merchantDetails['phoneNumber'] ?? null,
                'email' => $merchantDetails['emailAddress'] ?? null,
                'website_url' => $merchantDetails['businessUrl'] ?? null,
                'address' => [
                    'street' => $merchantDetails['address']['line1'] ?? null,
                    'city' => $merchantDetails['address']['city'] ?? null,
                    'state' => $merchantDetails['address']['territory'] ?? null,
                    'postal_code' => $merchantDetails['address']['postalCode'] ?? null,
                    'country' => $merchantDetails['address']['countryCode'] ?? null,
                ],
                'timezone' => $merchantDetails['timezone'] ?? null,
                'token' => [
                    'access_token' => $tokenResponse['accessToken'],
                    'refresh_token' => $tokenResponse['refreshToken'],
                    'expires_at' => date('Y-m-d H:i:s', time() + $tokenResponse['expiresIn']),
                ]
            ];

            // Check if the merchant already exists
            $existingMerchant = $this->conn->fetchAssociative(
                "SELECT id, metadata FROM merchant WHERE store_id = :store_id",
                ['store_id' => $storeId]
            );

            if ($existingMerchant) {
                // Merge existing metadata with new data
                $existingMetadata = json_decode($existingMerchant['metadata'], true) ?? [];
                $mergedMetadata = array_merge($existingMetadata, $metadata);

                // Update existing merchant
                $this->conn->update('merchant', [
                    'store_id' => $storeId,
                    'name' => $name,
                    'metadata' => json_encode($mergedMetadata),
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ], [
                    'id' => $existingMerchant['id'],
                ]);
            } else {
                // Insert new merchant
                $this->conn->insert('merchant', [
                    'business_id' => $businessId,
                    'store_id' => $storeId,
                    'name' => $name,
                    'metadata' => json_encode($metadata),
                    'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->log->error('Failed to save merchant data: ' . $e->getMessage());
            return false;
        }
    }


    public function _1saveMerchant(array $merchantDetails, string $storeId, array $tokenResponse): bool
    {
        // TODO extract real $merchantDetails array now
        try {
            $businessId = $merchantDetails['id'];
            $name = $merchantDetails['doingBusinessAs'];
            $legalName = $merchantDetails['legalName'] ?? null;
            $phoneNumber = $merchantDetails['phoneNumber'] ?? null;
            $email = $merchantDetails['emailAddress'] ?? null;
            $websiteUrl = $merchantDetails['businessUrl'] ?? null;
            $streetAddress = $merchantDetails['address']['line1'] ?? null;
            $city = $merchantDetails['address']['city'] ?? null;
            $state = $merchantDetails['address']['territory'] ?? null;
            $postalCode = $merchantDetails['address']['postalCode'] ?? null;
            $country = $merchantDetails['address']['countryCode'] ?? null;
            $timezone = $merchantDetails['timezone'] ?? null;

            // Check if the merchant already exists
            $existingMerchant = $this->conn->fetchAssociative(
                "SELECT id FROM merchant WHERE store_id = :store_id",
                ['store_id' => $storeId]
            );

            if ($existingMerchant) {
                // Update existing merchant
                $this->conn->update('merchant', [
                    'store_id' => $storeId,
                    'name' => $name,
                    'legal_name' => $legalName,
                    'phone_number' => $phoneNumber,
                    'email' => $email,
                    'website_url' => $websiteUrl,
                    'street_address' => $streetAddress,
                    'city' => $city,
                    'state' => $state,
                    'postal_code' => $postalCode,
                    'country' => $country,
                    'timezone' => $timezone,
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ], [
                    'business_id' => $businessId,
                ]);
            } else {
                // Insert new merchant
                $this->conn->insert('merchant', [
                    'business_id' => $businessId,
                    'store_id' => $storeId,
                    'name' => $name,
                    'legal_name' => $legalName,
                    'phone_number' => $phoneNumber,
                    'email' => $email,
                    'website_url' => $websiteUrl,
                    'street_address' => $streetAddress,
                    'city' => $city,
                    'state' => $state,
                    'postal_code' => $postalCode,
                    'country' => $country,
                    'timezone' => $timezone,
                ]);
            }

            // Save token data in token table
            $merchantId = $existingMerchant['id'] ?? $this->conn->lastInsertId();
            $this->conn->insert('token', [
                'merchant_id' => $merchantId,
                'access_token' => $tokenResponse['accessToken'],
                'refresh_token' => $tokenResponse['refreshToken'],
                'expires_at' => date('Y-m-d H:i:s', time() + $tokenResponse['expiresIn']),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->log->error('Failed to save merchant data: ' . $e->getMessage());
            return false;
        }
    }




    /**
     * Fetch merchant details from a given API endpoint.
     *
     * @param string $accessToken OAuth access token.
     * @return array|null Merchant details or null on failure.
     */
    public function _fetchMerchantDetails(string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_GET_BUSINESS, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['business'] ?? null; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->log->error("Error fetching merchant details: " . $e->getMessage());
            return null;
        }
    }



    public function _2saveMerchant(array $data): bool
    {
        try {
            $businessId = $data['business_id'];
            $storeId = $data['store_id'];
            $accessToken = $data['access_token'];
            $refreshToken = $data['refresh_token'];
            $tokenExpiry = date('Y-m-d H:i:s', $data['token_expiry']);

            // Check if the merchant already exists
            $existingMerchant = $this->conn->fetchAssociative(
                "SELECT id FROM merchant WHERE business_id = :business_id",
                ['business_id' => $businessId]
            );

            if ($existingMerchant) {
                // Update existing record
                $this->conn->update('merchant', [
                    'store_id' => $storeId,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expiry' => $tokenExpiry,
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ], [
                    'business_id' => $businessId,
                ]);
            } else {
                // Insert new record
                $this->conn->insert('merchant', [
                    'business_id' => $businessId,
                    'store_id' => $storeId,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expiry' => $tokenExpiry,
                ]);
            }

            return true;
        } catch (Exception $e) {
            $this->log->error('Failed to save merchant data: ' . $e->getMessage() . ', ' . $e->getCode() . ', ' . json_encode($e));
        }
    }

    /**
     * @throws Exception
     */
    public function _saveMerchant($data)
    {
        $businessId = $data['business_id'];
        $storeId = $data['store_id'];
        $accessToken = $data['access_token'];
        $refreshToken = $data['refresh_token'];
        $tokenExpiry = date('Y-m-d H:i:s', $data['token_expiry']);

        // Check if the merchant already exists
        $query = "SELECT id FROM merchant WHERE business_id = :business_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':business_id', $businessId);
        $result = $stmt->executeStatement();

        if ($stmt->rowCount() > 0) {
            // Update existing record
            $query = "UPDATE merchant 
                  SET store_id = :store_id, access_token = :access_token, 
                      refresh_token = :refresh_token, token_expiry = :token_expiry, 
                      updated_at = CURRENT_TIMESTAMP
                  WHERE business_id = :business_id";
        } else {
            // Insert new record
            $query = "INSERT INTO merchant (business_id, store_id, access_token, refresh_token, token_expiry) 
                  VALUES (:business_id, :store_id, :access_token, :refresh_token, :token_expiry)";
        }

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':business_id', $businessId);
        $stmt->bindParam(':store_id', $storeId);
        $stmt->bindParam(':access_token', $accessToken);
        $stmt->bindParam(':refresh_token', $refreshToken);
        $stmt->bindParam(':token_expiry', $tokenExpiry);

        if ($stmt->execute()) {
            return true;
        }

        throw new Exception('Failed to save merchant data');
    }

    public function saveMerchantData(array $merchantData, string $platform, array $tokens): string {
        $merchantId = Uuid::uuid4()->toString();

        // Insert merchant data
        $stmt = $this->pdo->prepare("
            INSERT INTO merchant (id, name, location, platform_merchant_id, created_at, updated_at)
            VALUES (:id, :name, :location, :platform_merchant_id, NOW(), NOW())
        ");
        $stmt->execute([
            ':id' => $merchantId,
            ':name' => $merchantData['name'] ?? 'Unknown',
            ':location' => $merchantData['location'] ?? '',
            ':platform_merchant_id' => $merchantData['id'],
        ]);

        // Save tokens
        $this->saveTokens($merchantId, $tokens);

        return $merchantId;
    }

    private function saveTokens(string $merchantId, array $tokens): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO token (id, merchant_id, access_token, refresh_token, expires_at, created_at, updated_at)
            VALUES (:id, :merchant_id, :access_token, :refresh_token, :expires_at, NOW(), NOW())
        ");
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':merchant_id' => $merchantId,
            ':access_token' => $tokens['access_token'],
            ':refresh_token' => $tokens['refresh_token'] ?? null,
            ':expires_at' => $tokens['expires_at'] ? date('Y-m-d H:i:s', strtotime($tokens['expires_at'])) : null,
        ]);
    }

}
