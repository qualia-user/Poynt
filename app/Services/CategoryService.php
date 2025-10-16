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
            $catalogs = $this->fetchCatalogs($businessId, $accessToken);
            if ($catalogs === null) {
                return false;
            }

            $categories = $this->collectCategories($businessId, $accessToken, $catalogs);

            return array_values($categories);
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('CategoryService::fetchByBusinessId: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @return array|null
     */
    private function fetchCatalogs(string $businessId, string $accessToken): ?array
    {
        $catalogs = [];
        $nextUrl = $this->buildCatalogsUrl($businessId);

        $visited = [];

        while ($nextUrl !== null) {
            if (isset($visited[$nextUrl])) {
                break;
            }
            $visited[$nextUrl] = true;

            $response = $this->httpClient->get($nextUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if ($data === null && trim($body) === '') {
                break;
            }

            if (!is_array($data)) {
                return null;
            }

            $catalogs = array_merge($catalogs, $this->extractCatalogs($data));
            $nextUrl = $this->resolveNextCatalogUrl($data, $businessId);
        }

        return $catalogs;
    }

    private function buildCatalogsUrl(string $businessId): string
    {
        return sprintf('%s/%s/catalogs', self::POYNT_ENDPOINT, $businessId);
    }

    private function resolveNextCatalogUrl(array $payload, string $businessId): ?string
    {
        $candidates = [
            $payload['links']['next'] ?? null,
            $payload['paging']['next'] ?? null,
            $payload['next'] ?? null,
            $payload['nextPage'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                if (str_starts_with($candidate, 'http')) {
                    return $candidate;
                }

                if (str_starts_with($candidate, '/')) {
                    return rtrim(self::POYNT_ENDPOINT, '/') . $candidate;
                }

                if (str_starts_with($candidate, '?')) {
                    return $this->buildCatalogsUrl($businessId) . $candidate;
                }

                if (!str_contains($candidate, '/') && str_contains($candidate, '=')) {
                    return $this->buildCatalogsUrl($businessId) . '?' . $candidate;
                }

                return sprintf('%s/%s', $this->buildCatalogsUrl($businessId), ltrim($candidate, '/'));
            }
        }

        return null;
    }

    private function extractCatalogs(array $payload): array
    {
        if (isset($payload['catalogs']) && is_array($payload['catalogs'])) {
            return $payload['catalogs'];
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            return $payload['results'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function collectCategories(string $businessId, string $accessToken, array $catalogs): array
    {
        $categories = [];

        foreach ($catalogs as $catalog) {
            if (!is_array($catalog)) {
                continue;
            }

            $catalogId = $catalog['id'] ?? null;
            $catalogCategories = [];

            if (isset($catalog['categories']) && is_array($catalog['categories'])) {
                $catalogCategories = $catalog['categories'];
            }

            if (empty($catalogCategories) && $catalogId !== null) {
                $catalogCategories = $this->fetchCatalogCategories($businessId, $catalogId, $accessToken);
            }

            foreach ($catalogCategories as $category) {
                if (!is_array($category) || !isset($category['id'])) {
                    continue;
                }

                $categoryId = $category['id'];

                if (!isset($category['businessId'])) {
                    $category['businessId'] = $businessId;
                }

                $categories[$categoryId] = $category;
            }
        }

        return $categories;
    }

    private function fetchCatalogCategories(string $businessId, string $catalogId, string $accessToken): array
    {
        try {
            $response = $this->httpClient->get(sprintf(
                '%s/%s/catalogs/%s/full',
                self::POYNT_ENDPOINT,
                $businessId,
                $catalogId
            ), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                return [];
            }

            if (isset($data['catalog']['categories']) && is_array($data['catalog']['categories'])) {
                return $data['catalog']['categories'];
            }

            if (isset($data['categories']) && is_array($data['categories'])) {
                return $data['categories'];
            }
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf(
                    'CategoryService::fetchCatalogCategories: %s',
                    $e->getMessage()
                )
            );
        }

        return [];
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
