<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class TaxService
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
     * Upsert a tax record.
     *
     * @param array $taxData
     * @return bool
     */
    public function upsert(array $taxData): bool
    {
        if (!isset($taxData['id'], $taxData['businessId'])) {
            $this->context->getLog()->error(
                'TaxService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $taxId = $taxData['id'];
        $businessId = $taxData['businessId'];
        $name = $taxData['name'] ?? null;

        $rateBp = null;
        if (isset($taxData['rateBp']) && is_numeric($taxData['rateBp'])) {
            $rateBp = (int) $taxData['rateBp'];
        } elseif (isset($taxData['rate']) && is_numeric($taxData['rate'])) {
            $rate = (float) $taxData['rate'];
            $rateBp = (int) round(($rate <= 1 ? $rate * 10000 : $rate * 100));
        }

        $scope = $taxData['scope'] ?? $taxData['scopeType'] ?? null;
        $active = Format::optionalBool($taxData['active'] ?? $taxData['isActive'] ?? null);
        $rawPayload = Format::jsonObject($taxData);
        $createdAtExt = Format::optionalTimestamp($taxData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($taxData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO tax (
                    tax_id, business_id, name, rate_bp, scope, active,
                    raw_payload, created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :taxId, :businessId, :name, :rateBp, :scope, :active,
                    :rawPayload, :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (tax_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    name = EXCLUDED.name,
                    rate_bp = EXCLUDED.rate_bp,
                    scope = EXCLUDED.scope,
                    active = EXCLUDED.active,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'taxId' => $taxId,
                    'businessId' => $businessId,
                    'name' => $name,
                    'rateBp' => $rateBp,
                    'scope' => $scope,
                    'active' => $active,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("TaxService::upsert: upserted tax {$taxId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "TaxService::upsert: database error for tax_id={$taxId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch taxes for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/taxes', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (is_array($data)) {
                $this->context->getLog()->info(
                    'TaxService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'taxes',
                        'payload' => $data,
                    ]
                );
            }

            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'TaxService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
