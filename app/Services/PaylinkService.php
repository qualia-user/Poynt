<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class PaylinkService
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
     * Upsert a paylink record.
     *
     * @param array $paylinkData
     * @return bool
     */
    public function upsert(array $paylinkData): bool
    {
        if (!isset($paylinkData['id'], $paylinkData['businessId'])) {
            $this->context->getLog()->error(
                'PaylinkService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $paylinkId = $paylinkData['id'];
        $businessId = $paylinkData['businessId'];

        $domain = $paylinkData['domain'] ?? $paylinkData['vanityUrl'] ?? null;
        $status = $paylinkData['status'] ?? null;
        $amountMinor = Format::amount($paylinkData['amount'] ?? null);
        if ($amountMinor === null && isset($paylinkData['amount']['amount'])) {
            $amountMinor = Format::amount($paylinkData['amount']['amount']);
        }
        $currency = $paylinkData['amount']['currency'] ?? $paylinkData['currency'] ?? null;
        $metadata = Format::jsonObject($paylinkData['metadata'] ?? []);
        $createdAtExt = Format::optionalTimestamp($paylinkData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($paylinkData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO paylink (
                    paylink_id, business_id, domain, status, amount_minor, currency,
                    metadata, created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :paylinkId, :businessId, :domain, :status, :amountMinor, :currency,
                    :metadata, :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (paylink_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    domain = EXCLUDED.domain,
                    status = EXCLUDED.status,
                    amount_minor = EXCLUDED.amount_minor,
                    currency = EXCLUDED.currency,
                    metadata = EXCLUDED.metadata,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'paylinkId' => $paylinkId,
                    'businessId' => $businessId,
                    'domain' => $domain,
                    'status' => $status,
                    'amountMinor' => $amountMinor,
                    'currency' => $currency,
                    'metadata' => $metadata,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("PaylinkService::upsert: upserted paylink {$paylinkId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "PaylinkService::upsert: database error for paylink_id={$paylinkId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch paylinks for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/paylinks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'PaylinkService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
