<?php

namespace App\Services;

use App\Core\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class InventorySummaryService
{
    private Context $context;
    private Client $httpClient;
    private ?string $businessId = null;

    const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    public function __construct(Context $context, ?string $businessId = null)
    {
        $this->context = $context;
        $this->httpClient = new Client();
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
        if (!isset($summaryData['id'], $summaryData['businessId'])) {
            $this->context->getLog()->error(
                'InventorySummaryService::upsert: missing required fields (id or businessId)'
            );
            return false;
        }

        $summaryId = $summaryData['id'];
        $businessId = $summaryData['businessId'];
        $productId = $summaryData['productId'] ?? null;

        $metadata = json_encode($summaryData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "InventorySummaryService::upsert: failed to json_encode summaryData for inventory_summary_id={$summaryId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO inventory_summary (inventory_summary_id, business_id, product_id, metadata, created_at, updated_at)
                 VALUES (:summaryId, :businessId, :productId, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (inventory_summary_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     product_id = EXCLUDED.product_id,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'summaryId' => $summaryId,
                    'businessId' => $businessId,
                    'productId' => $productId,
                    'metadata' => $metadata,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->context->getLog()->info("InventorySummaryService::upsert: upserted inventory summary {$summaryId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "InventorySummaryService::upsert: database error for inventory_summary_id={$summaryId}: " . $e->getMessage()
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
