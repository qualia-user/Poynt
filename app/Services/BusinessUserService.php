<?php

namespace App\Services;

use App\Core\Context;
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
        if (!isset($userData['id'], $userData['businessId'])) {
            $this->context->getLog()->error(
                'BusinessUserService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $userId = $userData['id'];
        $businessId = $userData['businessId'];
        $name = $userData['name'] ?? null;

        $metadata = json_encode($userData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "BusinessUserService::upsert: failed to json_encode userData for business_user_id={$userId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO business_user (business_user_id, business_id, name, metadata, created_at, updated_at)
                 VALUES (:userId, :businessId, :name, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (business_user_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     name = EXCLUDED.name,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'userId' => $userId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'metadata' => $metadata,
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
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'BusinessUserService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
