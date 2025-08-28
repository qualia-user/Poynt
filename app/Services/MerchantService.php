<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MerchantService {

    private Context $context;
    private ?string $businessId = null;

    const POYNT_ENDPOINT_API_BUSINESS = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null)
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
    }

    /**
     * Convenience wrapper that fetches merchant business details using the
     * internally stored business identifier or the one supplied.
     *
     * @param string|null $businessId Optional business identifier
     * @return array|false Merchant data on success, false on failure
     */
    public function fetchMerchantBusiness(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        return $this->fetchMerchantBusinessById($businessId);
    }

    /**
     * Retrieve orders belonging to a merchant business.
     *
     * @param string|null $businessId Optional business identifier
     * @return array|false Array of orders or false on error
     */
    public function fetchMerchantOrders(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $accessToken  = $tokenService->getMerchantToken($businessId);

        $httpClient = new Client();

        try {
            $response = $httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId . '/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('Error fetching merchant orders: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string|null $businessId
     * @return bool
     */
    public function merchantExists(?string $businessId = null): bool
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
    public function fetchBusinessStores(?string $businessId = null): array
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

            // Persist terminals for each store if present
            if (is_array($data)) {
                $terminalService = new TerminalService($this->context);
                foreach ($data as $store) {
                    $devices = $store['storeDevices'] ?? [];
                    $storeId = $store['id'] ?? null;
                    if ($storeId && !empty($devices)) {
                        $terminalService->upsertTerminals($devices, $storeId);
                    }
                }
            }

            return $data ?? []; // Assuming 'business' contains the merchant details
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching merchant details: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch terminals already stored for a given store.
     *
     * @param string $storeId
     * @return array
     */
    public function fetchStoreTerminals(string $storeId): array
    {
        $terminalService = new TerminalService($this->context);
        return $terminalService->fetchByStoreId($storeId);
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
}
