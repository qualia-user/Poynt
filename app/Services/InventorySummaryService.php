<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class InventorySummaryService
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
     * Upsert an inventory summary record.
     *
     * @param array $summaryData
     * @return bool
     */
    public function upsert(array $summaryData): bool
    {
        if (!isset($summaryData['businessId'], $summaryData['productId'])) {
            $this->context->getLog()->error(
                'InventorySummaryService::upsert: missing required fields (businessId or productId)'
            );
            return false;
        }

        $businessId = $summaryData['businessId'];
        $productId = $summaryData['productId'];
        $totalOnHand = Format::optionalNumericString($summaryData['totalOnHand'] ?? $summaryData['quantityOnHand'] ?? null);
        $totalReserved = Format::optionalNumericString($summaryData['totalReserved'] ?? $summaryData['quantityReserved'] ?? null);
        $payload = Format::jsonObject($summaryData);
        $updatedAtExt = Format::optionalTimestamp($summaryData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO inventory_summary (
                    business_id, product_id, total_on_hand, total_reserved, updated_at_ext,
                    payload, created_at, updated_at
                ) VALUES (
                    :businessId, :productId, :totalOnHand, :totalReserved, :updatedAtExt,
                    :payload, :createdAt, :updatedAt
                ) ON CONFLICT (business_id, product_id) DO UPDATE SET
                    total_on_hand = EXCLUDED.total_on_hand,
                    total_reserved = EXCLUDED.total_reserved,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    payload = EXCLUDED.payload,
                    updated_at = EXCLUDED.updated_at',
                [
                    'businessId' => $businessId,
                    'productId' => $productId,
                    'totalOnHand' => $totalOnHand,
                    'totalReserved' => $totalReserved,
                    'updatedAtExt' => $updatedAtExt,
                    'payload' => $payload,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("InventorySummaryService::upsert: upserted inventory summary {$businessId}-{$productId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "InventorySummaryService::upsert: database error for business_id={$businessId}, product_id={$productId}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch inventory summaries for a business from the Poynt API.
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
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/inventory', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?? false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                'InventorySummaryService::fetchByBusinessId: ' . $e->getMessage()
            );
            return false;
        }
    }
}
