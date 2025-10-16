<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class CategoryService
{
    private const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

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
        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf('CategoryService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/categories', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (is_array($data)) {
                return $data;
            }
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('CategoryService::fetchByBusinessId: ' . $e->getMessage());
        }

        return false;
    }

    public function upsert(array $categoryData): bool
    {
        if (!isset($categoryData['id'], $categoryData['businessId'])) {
            $this->context->getLog()->error(
                'CategoryService::upsert: missing required category fields (id or businessId)'
            );
            return false;
        }

        $categoryId = $categoryData['id'];
        $businessId = $categoryData['businessId'];
        $name = $categoryData['name'] ?? $categoryData['displayName'] ?? null;
        $parentId = $categoryData['parentId'] ?? null;
        $rawPayload = Format::jsonObject($categoryData);
        $createdAtExt = Format::optionalTimestamp($categoryData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($categoryData['updatedAt'] ?? null);

        $sql = <<<SQL
        INSERT INTO category (
            category_id,
            business_id,
            name,
            parent_id,
            raw_payload,
            created_at_ext,
            updated_at_ext
        ) VALUES (
            :categoryId,
            :businessId,
            :name,
            :parentId,
            :rawPayload,
            :createdAtExt,
            :updatedAtExt
        ) ON CONFLICT (category_id) DO UPDATE SET
            business_id    = EXCLUDED.business_id,
            name           = EXCLUDED.name,
            parent_id      = EXCLUDED.parent_id,
            raw_payload    = EXCLUDED.raw_payload,
            created_at_ext = EXCLUDED.created_at_ext,
            updated_at_ext = EXCLUDED.updated_at_ext
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'categoryId'   => $categoryId,
                'businessId'   => $businessId,
                'name'         => $name,
                'parentId'     => $parentId,
                'rawPayload'   => $rawPayload,
                'createdAtExt' => $createdAtExt,
                'updatedAtExt' => $updatedAtExt,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'CategoryService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}
