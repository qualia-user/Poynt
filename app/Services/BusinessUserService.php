<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class BusinessUserService
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
     * Upsert a business user record.
     *
     * @param array $userData
     * @return bool
     */
    public function upsert(array $userData): bool
    {
        if (!isset($userData['businessId'])) {
            $this->context->getLog()->error(
                'BusinessUserService::upsert: missing required field businessId'
            );
            return false;
        }

        $userIdRaw = $userData['userId'] ?? $userData['id'] ?? null;
        $userId = Format::optionalInt($userIdRaw);
        if ($userId === null) {
            $this->context->getLog()->error('BusinessUserService::upsert: user id must be numeric');
            return false;
        }
        $businessId = $userData['businessId'];
        $firstName = $userData['firstName'] ?? ($userData['name']['first'] ?? null);
        $lastName = $userData['lastName'] ?? ($userData['name']['last'] ?? null);

        $role = $userData['role']
            ?? ($userData['employmentDetails']['role'] ?? null)
            ?? ($userData['employment']['role'] ?? null)
            ?? ($userData['roles'][0] ?? null);
        $status = $userData['status'] ?? null;
        $credentials = Format::jsonArray($userData['credentials'] ?? []);
        $employmentPayload = $userData['employmentDetails'] ?? $userData['employment'] ?? $userData['employmentInfo'] ?? [];
        $employment = Format::jsonObject($employmentPayload);
        $rawPayload = Format::jsonObject($userData);
        $createdAtExt = Format::optionalTimestamp($userData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($userData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO business_user (
                    business_id, user_id, first_name, last_name,
                    role, status, credentials, employment, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :businessId, :userId, :firstName, :lastName,
                    :role, :status, :credentials, :employment, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (business_id, user_id) DO UPDATE SET
                    first_name = EXCLUDED.first_name,
                    last_name = EXCLUDED.last_name,
                    role = EXCLUDED.role,
                    status = EXCLUDED.status,
                    credentials = EXCLUDED.credentials,
                    employment = EXCLUDED.employment,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    created_at = EXCLUDED.created_at,
                    updated_at = EXCLUDED.updated_at',
                [
                    'businessId' => $businessId,
                    'userId' => $userId,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'role' => $role,
                    'status' => $status,
                    'credentials' => $credentials,
                    'employment' => $employment,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("BusinessUserService::upsert: upserted business user {$userId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "BusinessUserService::upsert: database error for business_user_id={$userId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch business users for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/businessUsers', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (is_array($data)) {
                $this->context->getLog()->info(
                    'BusinessUserService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'businessUsers',
                        'payload' => $data,
                    ]
                );
            }

            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'BusinessUserService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
