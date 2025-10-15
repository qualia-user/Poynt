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

    public function __construct(Context $context, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
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
        if (!isset($transactionData['id'], $transactionData['businessId'])) {
            $this->context->getLog()->error(
                'TransactionService::upsert: missing required fields id or businessId'
            );
            return false;
        }

        $transactionId = $transactionData['id'];
        $businessId = $transactionData['businessId'];

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        $storeId = $transactionData['storeId'] ?? null;
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

        $action = $transactionData['action'] ?? $transactionData['type'] ?? null;
        $status = $transactionData['status'] ?? null;
        $settlementStatus = $transactionData['settlementStatus'] ?? ($transactionData['processorResponse']['settlementStatus'] ?? null);
        $settled = Format::optionalBool($transactionData['settled'] ?? null);
        $partiallyApproved = Format::optionalBool($transactionData['partiallyApproved'] ?? null);

        $amounts = $transactionData['amounts'] ?? [];
        $transactionAmount = Format::amount($amounts['transactionAmount'] ?? null);
        $orderAmount = Format::amount($amounts['orderAmount'] ?? null);
        $tipAmount = Format::amount($amounts['tipAmount'] ?? null);
        $cashbackAmount = Format::amount($amounts['cashbackAmount'] ?? null);
        $currency = $amounts['transactionAmount']['currency'] ?? $transactionData['currency'] ?? null;

        $card = $transactionData['fundingSource']['card'] ?? [];
        $cardBrand = $card['brand'] ?? null;
        $cardLast4 = $card['numberLast4'] ?? ($card['lastFourDigits'] ?? null);
        $entryMode = $card['entryMode'] ?? $card['entryMethod'] ?? ($transactionData['entryMode'] ?? null);

        $processor = $transactionData['processor'] ?? [];
        $processorName = $processor['name'] ?? ($processor['processor'] ?? null);
        $processorStatus = $processor['status'] ?? ($transactionData['processorResponse']['status'] ?? null);
        $processorCode = $processor['responseCode'] ?? ($transactionData['processorResponse']['responseCode'] ?? null);
        $approvalCode = $processor['authCode'] ?? ($transactionData['processorResponse']['authorizationCode'] ?? null);
        $retrievalRef = $processor['rrn'] ?? ($transactionData['processorResponse']['retrievalReferenceNumber'] ?? null);
        $batchId = $processor['batchId'] ?? ($transactionData['processorResponse']['batchId'] ?? null);

        $customerUserId = Format::optionalInt($transactionData['customerUserId'] ?? null);

        $referencesJson = Format::jsonArray($references);
        $fundingSource = Format::jsonObject($transactionData['fundingSource'] ?? []);
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
                    'fundingSource' => $fundingSource,
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
        try {
            $url = self::POYNT_ENDPOINT . '/' . $businessId . '/transactions';
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!$data || !isset($data['transactions'])) {
                return false;
            }

            foreach ($data['transactions'] as $transaction) {
                $this->upsert($transaction, $transaction['receipt'] ?? null);
            }

            return true;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('TransactionService::fetchAndStore: ' . $e->getMessage());
            return false;
        }
    }
}

