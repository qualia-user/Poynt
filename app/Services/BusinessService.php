<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PoyntDataFormatter as Format;
use App\Services\Support\TableNamer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use DateTimeInterface;

class BusinessService {

    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;
    private TableNamer $tableNamer;

    const POYNT_ENDPOINT_API_BUSINESS = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        $this->tableNamer = new TableNamer($context->getConn());
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    /**
     * Insert a new business row or update the existing one, based on business_id.
     *
     * @param array $businessData  Associated array with keys such as:
     *                             - 'businessId' (string),
     *                             - 'storeId'    (string),
     *                             - 'name'       (string),
     *                             - 'trialEligible' (bool, optional),
     *                             - 'trialExpiresAt' (DateTimeInterface|string, optional)
     *
     * @return bool  True on success, false on failure.
     */
    public function upsert(array $businessData): bool
    {
        // 1) Validate required keys
        if (!isset($businessData['id'], $businessData['legalName'])) {
            $this->context->getLog()->error(
                'BusinessService::upsert: missing required businessData fields (id or legalName)'
            );
            return false;
        }

        $businessId = $businessData['id'];
        $name = $businessData['legalName'];

        // 1.a) Decide on "active". If the payload does not include it, default to true.
        $active = ($businessData['status'] ?? null) === 'ACTIVATED';

        $trialEligible = $businessData['trialEligible'] ?? true;
        $trialExpiresAt = $this->normalizeTrialExpiry($businessData['trialExpiresAt'] ?? $this->returnDefaultTrialDate());

        // 2) Prepare the full payload as JSON for the metadata column
        $metadata = Format::jsonObject($businessData);

        // 3) Current timestamp (PostgreSQL TIMESTAMPTZ)
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            // 4) See if a business row with this business_id already exists
            $existing = $this->context->getConn()->fetchAssociative(
                'SELECT business_id FROM business WHERE business_id = ? ORDER BY updated_at DESC LIMIT 1',
                [$businessId]
            );

            $insertPayload = [
                'business_id' => $businessId,
                'name' => $name,
                'metadata' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
                'active' => $active,
            ];

            $updatePayload = [
                'name' => $name,
                'metadata' => $metadata,
                'updated_at' => $now,
                'active' => $active,
            ];

            if ($trialEligible !== null) {
                $insertPayload['trial_eligible'] = (bool) $trialEligible;
                $updatePayload['trial_eligible'] = (bool) $trialEligible;
            }

            if ($trialExpiresAt !== null) {
                $insertPayload['trial_expires_at'] = $trialExpiresAt;
                $updatePayload['trial_expires_at'] = $trialExpiresAt;
            }

            if ($existing) {
                // 5a) UPDATE path (include 'active' here)
                $this->context->getConn()->update('business', $updatePayload, ['business_id' => $businessId]);

                $this->context->getLog()->info("BusinessService::upsert: updated business {$businessId}");
            } else {
                // 5b) INSERT path (include 'active' here, too)
                $this->context->getConn()->insert('business', $insertPayload);

                $this->context->getLog()->info("BusinessService::upsert: inserted new business {$businessId}");
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "BusinessService::upsert: database error for business_id={$businessId}: "
                . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * @return string
     */
    public function returnDefaultTrialDate(): string
    {
        return (new \DateTimeImmutable('now'))->modify('+' . SubscriptionService::DEFAULT_TRIAL_DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * Fetch and save business details.
     *
     * @param string $businessId
     * @return array|false Success(array) or failure(bool).
     */
    public function fetchBusinessById(string $businessId): array|false
    {
        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        // TODO remove all Guzzle things into separated class?

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false; // Assuming 'business' contains the business details
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching business details: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch a business payload by business identifier.
     *
     * @param string|null $businessId Optional business identifier override
     * @return array|false Array of business payloads (single element) or false when unavailable
     */
    public function fetchByBusinessId(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $business = $this->fetchBusinessById($businessId);
        if (!is_array($business) || $business === []) {
            return false;
        }

        $payload = [$business];

        FetchResponseLogger::info(
            $this->context->getLog(),
            'BusinessService::fetchByBusinessId response',
            [
                'businessId' => $businessId,
                'entity' => 'businesses',
                'payload' => $payload,
            ]
        );

        return $payload;
    }

    /**
     * Convenience wrapper that fetches business details using the
     * internally stored business identifier or the one supplied.
     *
     * @param string|null $businessId Optional business identifier
     * @return array|false Business data on success, false on failure
     */
    public function fetchBusiness(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        return $this->fetchBusinessById($businessId);
    }

    /**
     * Retrieve orders belonging to a business.
     *
     * @param string|null $businessId Optional business identifier
     * @return array|false Array of orders or false on error
     */
    public function fetchBusinessOrders(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $accessToken  = $tokenService->getMerchantToken($businessId);

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId . '/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('Error fetching business orders: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string|null $businessId
     * @return bool
     */
    public function businessExists(?string $businessId = null): bool
    {
        if (is_null($businessId)) {
            $businessId = $this->businessId;
        }

        $sql = <<<SQL
        SELECT *
          FROM business
         WHERE business_id = ?
           AND active = TRUE
         ORDER BY updated_at DESC
         LIMIT 1
        SQL;

        $success = $this->context->getConn()->fetchAssociative($sql, [$businessId]);

        if (is_array($success)) {
            return true;
        }

        return false;

    }

    public function getTrialState(?string $businessId = null): array
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return [
                'eligible' => false,
                'expiresAt' => null,
            ];
        }

        $sql = 'SELECT trial_eligible, trial_expires_at FROM business WHERE business_id = ? ORDER BY updated_at DESC LIMIT 1';

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [$businessId]);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('BusinessService::getTrialState failed for business %s: %s', $businessId, $e->getMessage())
            );

            return [
                'eligible' => false,
                'expiresAt' => null,
            ];
        }

        if (!$row) {
            return [
                'eligible' => false,
                'expiresAt' => null,
            ];
        }

        return [
            'eligible' => (bool) ($row['trial_eligible'] ?? false),
            'expiresAt' => $row['trial_expires_at'] ?? null,
        ];
    }

    public function setTrialWindow(string $businessId, bool $eligible, ?DateTimeInterface $expiresAt): bool
    {
        $normalizedExpiry = $this->normalizeTrialExpiry($expiresAt);

        try {
            $updated = $this->context->getConn()->executeStatement(
                'UPDATE business SET trial_eligible = :eligible, trial_expires_at = :expires, updated_at = NOW() WHERE business_id = :business',
                [
                    'eligible' => $eligible,
                    'expires' => $normalizedExpiry,
                    'business' => $businessId,
                ]
            );

            return $updated > 0;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf('BusinessService::setTrialWindow failed for business %s: %s', $businessId, $e->getMessage())
            );

            return false;
        }
    }

    private function normalizeTrialExpiry(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:sP');
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            try {
                $date = new \DateTime($trimmed);

                return $date->format('Y-m-d H:i:sP');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT_API_BUSINESS . '/' . $businessId . '/stores', [
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

            return $data ?? []; // Assuming 'business' contains the store details
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching business stores: " . $e->getMessage());
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

        $storeTable = $this->tableNamer->for($this->businessId, 'store');

        $sql = <<<SQL
        INSERT INTO {$storeTable}
            (store_id, name, metadata, created_at, updated_at)
        VALUES
            (:storeId, :name, :metadata, :createdAt, :updatedAt)
        ON CONFLICT (store_id) DO UPDATE SET
            name       = EXCLUDED.name,
            metadata   = EXCLUDED.metadata,
            updated_at = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);

            $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

            foreach ($stores as $store) {
                if (!isset($store['id'], $store['displayName'])) {
                    $this->context->getLog()->error(
                        'BusinessService::insertStores: missing required store fields '
                        . '(id or displayName)'
                    );
                    return false;
                }

                $storeId    = $store['id'];
                $name       = $store['displayName'];

                $metadata = Format::jsonObject($store);

                $stmt->executeStatement([
                    'storeId'    => $storeId,
                    'name'       => $name,
                    'metadata'   => $metadata,
                    'createdAt'  => $now,
                    'updatedAt'  => $now,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "BusinessService::insertStores: database error while inserting/updating stores: "
                . $e->getMessage()
            );
            return false;
        }
    }
}
