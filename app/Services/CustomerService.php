<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class CustomerService
{
    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    /**
     * Upsert a customer record.
     *
     * @param array $customerData
     * @return bool
     */
    public function upsert(array $customerData): bool
    {
        if (!isset($customerData['id'], $customerData['businessId'])) {
            $this->context->getLog()->error(
                'CustomerService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $customerId = $customerData['id'];
        $businessId = $customerData['businessId'];
        $name = $customerData['name'] ?? null;

        $metadata = json_encode($customerData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "CustomerService::upsert: failed to json_encode customerData for customer_id={$customerId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO customer (customer_id, business_id, name, metadata, created_at, updated_at)
                 VALUES (:customerId, :businessId, :name, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (customer_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     name = EXCLUDED.name,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'customerId' => $customerId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'metadata' => $metadata,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("CustomerService::upsert: upserted customer {$customerId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "CustomerService::upsert: database error for customer_id={$customerId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch customers for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/customers', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'CustomerService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
