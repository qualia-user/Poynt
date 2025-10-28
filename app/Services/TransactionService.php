<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\PoyntDataFormatter as Format;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class TransactionService
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

    public function fetchByBusinessId(?string $businessId = null, ?string $accessTokenOverride = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $accessTokenOverride;
        if ($accessToken === null) {
            $accessToken = $tokenService->getMerchantToken($businessId);
        }

        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf('TransactionService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        try {
            $url = self::POYNT_ENDPOINT . '/' . $businessId . '/transactions';
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!is_array($data) || !isset($data['transactions']) || !is_array($data['transactions'])) {
                return false;
            }

            return $data['transactions'];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('TransactionService::fetchByBusinessId: %s', $e->getMessage())
            );
            return false;
        }
    }

    /**
     * Upsert a transaction and optionally its receipt.
     *
     * @param array $transactionData Data for the transaction.
     * @param array|null $receiptData Optional receipt data.
     * @return bool True on success, false on failure.
     */
    public function upsert(array $transactionData, ?array $receiptData = null): bool
    {
        // Require transaction id and business id
        if (!isset($transactionData['id'])) {
            $this->context->getLog()->error(
                'TransactionService::upsert: missing required fields id or businessId'
            );
            return false;
        }

        if ($receiptData === null && isset($transactionData['receipt']) && is_array($transactionData['receipt'])) {
            $receiptData = $transactionData['receipt'];
        }

        $transactionId = $transactionData['id'];
        $businessId = $transactionData['businessId'];

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        $storeId = $transactionData['storeId']
            ?? $transactionData['context']['storeId']
            ?? null;
        $storeId = Format::stringOrNull($storeId);
        $references = $transactionData['references'] ?? [];
        $orderId = $transactionData['orderId'] ?? null;
        if (!$orderId && is_array($references)) {
            foreach ($references as $reference) {
                $type = strtoupper((string) ($reference['type'] ?? $reference['referenceType'] ?? ''));
                if ($type === 'POYNT_ORDER' || $type === 'ORDER') {
                    $orderId = $reference['id']
                        ?? $reference['referenceId']
                        ?? $reference['value']
                        ?? null;
                    if ($orderId) {
                        break;
                    }
                }
            }
        }
        $orderId = Format::stringOrNull($orderId);

        $action = $transactionData['action'] ?? $transactionData['type'] ?? null;
        $status = $transactionData['status'] ?? null;
        $settlementStatus = $transactionData['settlementStatus']
            ?? ($transactionData['processorResponse']['settlementStatus'] ?? null);
        $action = Format::stringOrNull($action);
        $status = Format::stringOrNull($status);
        $settlementStatus = Format::stringOrNull($settlementStatus);
        $settled = Format::optionalBool($transactionData['settled'] ?? null);
        $partiallyApproved = Format::optionalBool($transactionData['partiallyApproved'] ?? null);

        $amounts = $transactionData['amounts'] ?? [];
        $transactionAmount = Format::amount($amounts['transactionAmount'] ?? null);
        $orderAmount = Format::amount($amounts['orderAmount'] ?? null);
        $tipAmount = Format::amount($amounts['tipAmount'] ?? null);
        $cashbackAmount = Format::amount($amounts['cashbackAmount'] ?? null);
        $currency = $amounts['currency'] ?? null;
        if ($currency === null && isset($amounts['transactionAmount']) && is_array($amounts['transactionAmount'])) {
            $currency = $amounts['transactionAmount']['currency'] ?? null;
        }
        $currency = $currency ?? ($transactionData['currency'] ?? null);
        $currency = Format::stringOrNull($currency);

        $fundingSource = $transactionData['fundingSource'] ?? [];
        $card = $fundingSource['card'] ?? [];
        $cardBrand = $card['brand'] ?? $card['cardBrand'] ?? null;
        if (is_array($cardBrand)) {
            $cardBrand = $cardBrand['displayName'] ?? $cardBrand['scheme'] ?? $cardBrand['name'] ?? null;
        }
        $cardBrand = $cardBrand ?? ($card['type'] ?? null);
        $cardBrand = Format::stringOrNull($cardBrand);
        $cardLast4 = $card['numberLast4'] ?? ($card['lastFourDigits'] ?? null);
        $cardLast4 = Format::stringOrNull($cardLast4);
        $entryMode = $card['entryMode']
            ?? $card['entryMethod']
            ?? ($transactionData['entryMode'] ?? $fundingSource['entryDetails']['entryMode'] ?? null);
        $entryMode = Format::stringOrNull($entryMode);

        $processor = $transactionData['processor'] ?? [];
        $processorResponse = $transactionData['processorResponse'] ?? [];
        $processorName = $processor['name']
            ?? $processor['processor']
            ?? ($processorResponse['processor'] ?? null);
        $processorName = Format::stringOrNull($processorName);
        $processorStatus = $processor['status'] ?? ($processorResponse['status'] ?? null);
        $processorStatus = Format::stringOrNull($processorStatus);
        $processorCode = $processor['responseCode']
            ?? $processor['statusCode']
            ?? ($processorResponse['responseCode'] ?? $processorResponse['statusCode'] ?? null);
        $processorCode = Format::stringOrNull($processorCode);
        $approvalCode = $processor['authCode']
            ?? $processor['approvalCode']
            ?? ($processorResponse['authorizationCode'] ?? $processorResponse['approvalCode'] ?? null);
        $approvalCode = Format::stringOrNull($approvalCode);
        $retrievalRef = $processor['rrn']
            ?? $processor['retrievalReferenceNumber']
            ?? ($processorResponse['retrievalReferenceNumber'] ?? $processorResponse['retrievalRefNum'] ?? null);
        $retrievalRef = Format::stringOrNull($retrievalRef);
        $batchId = $processor['batchId'] ?? ($processorResponse['batchId'] ?? null);
        $batchId = Format::stringOrNull($batchId);

        $customerUserId = Format::optionalInt($transactionData['customerUserId'] ?? null);

        $referencesJson = Format::jsonArray($references);
        $fundingSourceJson = Format::jsonObject($fundingSource);
        $contextJson = Format::jsonObject($transactionData['context'] ?? []);
        $rawPayload = Format::jsonObject($transactionData);

        $createdAtExt = Format::optionalTimestamp($transactionData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($transactionData['updatedAt'] ?? null);

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO transaction (
                    transaction_id, business_id, store_id, order_id,
                    action, status, settlement_status, settled, partially_approved,
                    txn_amount_minor, order_amount_minor, tip_amount_minor, cashback_amount_minor, currency,
                    card_brand, last4, entry_mode,
                    processor, processor_status, processor_code, approval_code, retrieval_ref, batch_id,
                    customer_user_id, references_json, funding_source, context_json, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :transactionId, :businessId, :storeId, :orderId,
                    :action, :status, :settlementStatus, :settled, :partiallyApproved,
                    :txnAmountMinor, :orderAmountMinor, :tipAmountMinor, :cashbackAmountMinor, :currency,
                    :cardBrand, :last4, :entryMode,
                    :processor, :processorStatus, :processorCode, :approvalCode, :retrievalRef, :batchId,
                    :customerUserId, :referencesJson, :fundingSource, :contextJson, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (transaction_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    store_id = EXCLUDED.store_id,
                    order_id = EXCLUDED.order_id,
                    action = EXCLUDED.action,
                    status = EXCLUDED.status,
                    settlement_status = EXCLUDED.settlement_status,
                    settled = EXCLUDED.settled,
                    partially_approved = EXCLUDED.partially_approved,
                    txn_amount_minor = EXCLUDED.txn_amount_minor,
                    order_amount_minor = EXCLUDED.order_amount_minor,
                    tip_amount_minor = EXCLUDED.tip_amount_minor,
                    cashback_amount_minor = EXCLUDED.cashback_amount_minor,
                    currency = EXCLUDED.currency,
                    card_brand = EXCLUDED.card_brand,
                    last4 = EXCLUDED.last4,
                    entry_mode = EXCLUDED.entry_mode,
                    processor = EXCLUDED.processor,
                    processor_status = EXCLUDED.processor_status,
                    processor_code = EXCLUDED.processor_code,
                    approval_code = EXCLUDED.approval_code,
                    retrieval_ref = EXCLUDED.retrieval_ref,
                    batch_id = EXCLUDED.batch_id,
                    customer_user_id = EXCLUDED.customer_user_id,
                    references_json = EXCLUDED.references_json,
                    funding_source = EXCLUDED.funding_source,
                    context_json = EXCLUDED.context_json,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'transactionId' => $transactionId,
                    'businessId' => $businessId,
                    'storeId' => $storeId,
                    'orderId' => $orderId,
                    'action' => $action,
                    'status' => $status,
                    'settlementStatus' => $settlementStatus,
                    'settled' => $settled,
                    'partiallyApproved' => $partiallyApproved,
                    'txnAmountMinor' => $transactionAmount,
                    'orderAmountMinor' => $orderAmount,
                    'tipAmountMinor' => $tipAmount,
                    'cashbackAmountMinor' => $cashbackAmount,
                    'currency' => $currency,
                    'cardBrand' => $cardBrand,
                    'last4' => $cardLast4,
                    'entryMode' => $entryMode,
                    'processor' => $processorName,
                    'processorStatus' => $processorStatus,
                    'processorCode' => $processorCode,
                    'approvalCode' => $approvalCode,
                    'retrievalRef' => $retrievalRef,
                    'batchId' => $batchId,
                    'customerUserId' => $customerUserId,
                    'referencesJson' => $referencesJson,
                    'fundingSource' => $fundingSourceJson,
                    'contextJson' => $contextJson,
                    'rawPayload' => $rawPayload,
                    'createdAtExt' => $createdAtExt,
                    'updatedAtExt' => $updatedAtExt,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            if ($receiptData !== null) {
                $receiptHtml = $receiptData['html'] ?? null;
                $payloadJson = Format::jsonObject($receiptData['payload'] ?? []);

                $this->context->getConn()->executeStatement(
                    'INSERT INTO transaction_receipt (transaction_id, html, payload, created_at)
                     VALUES (:transactionId, :html, :payload, :createdAt)
                     ON CONFLICT (transaction_id) DO UPDATE SET
                        html = EXCLUDED.html,
                        payload = EXCLUDED.payload',
                    [
                        'transactionId' => $transactionId,
                        'html' => $receiptHtml,
                        'payload' => $payloadJson,
                        'createdAt' => $now,
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'TransactionService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Fetch transactions for a business and store them.
     */
    public function fetchAndStore(string $accessToken, string $businessId): bool
    {
        $transactions = $this->fetchByBusinessId($businessId, $accessToken);

        if (!is_array($transactions)) {
            return false;
        }

        foreach ($transactions as $transaction) {
            $this->upsert($transaction, $transaction['receipt'] ?? null);
        }

        return true;
    }
}

