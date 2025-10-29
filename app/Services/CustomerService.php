<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
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

        if (!is_numeric($customerData['id'])) {
            $this->context->getLog()->error(
                'CustomerService::upsert: customer id must be numeric to fit BIGINT column'
            );
            return false;
        }

        $customerId = (int) $customerData['id'];
        $businessId = $customerData['businessId'];
        $firstName = $customerData['firstName'] ?? $customerData['givenName'] ?? null;
        $lastName = $customerData['lastName'] ?? $customerData['familyName'] ?? null;
        $emailsJson = Format::jsonArray($customerData['emails'] ?? $customerData['emailAddresses'] ?? []);
        $phonesJson = Format::jsonArray($customerData['phoneNumbers'] ?? $customerData['phones'] ?? []);
        $attributes = Format::jsonObject($customerData['attributes'] ?? $customerData['customAttributes'] ?? []);
        $rawPayload = Format::jsonObject($customerData);
        $createdAtExt = Format::optionalTimestamp($customerData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($customerData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO customer (
                    customer_id, business_id, first_name, last_name,
                    emails_json, phones_json, attributes, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :customerId, :businessId, :firstName, :lastName,
                    :emailsJson, :phonesJson, :attributes, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (customer_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    first_name = EXCLUDED.first_name,
                    last_name = EXCLUDED.last_name,
                    emails_json = EXCLUDED.emails_json,
                    phones_json = EXCLUDED.phones_json,
                    attributes = EXCLUDED.attributes,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'customerId' => $customerId,
                    'businessId' => $businessId,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'emailsJson' => $emailsJson,
                    'phonesJson' => $phonesJson,
                    'attributes' => $attributes,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
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
            if (is_array($data)) {
                $this->context->getLog()->info(
                    'CustomerService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'customers',
                        'payload' => $data,
                    ]
                );
            }

            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'CustomerService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
