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
        $transactionId = Format::stringOrNull($transactionData['id'] ?? null);
        if ($transactionId === null) {
            $this->context->getLog()->error('TransactionService::upsert: missing required field id');

            return false;
        }

        if ($receiptData === null && isset($transactionData['receipt']) && is_array($transactionData['receipt'])) {
            $receiptData = $transactionData['receipt'];
        }

        $context = $transactionData['context'] ?? [];
        if (!is_array($context)) {
            $context = [];
        }

        $businessId = Format::stringOrNull($transactionData['businessId'] ?? ($context['businessId'] ?? null));
        if ($businessId === null) {
            $this->context->getLog()->error(
                'TransactionService::upsert: missing business id in payload context'
            );

            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        $storeId = Format::stringOrNull($transactionData['storeId'] ?? ($context['storeId'] ?? null));
        $storeDeviceId = Format::stringOrNull($transactionData['storeDeviceId'] ?? ($context['storeDeviceId'] ?? null));
        $employeeUserId = Format::optionalInt($transactionData['employeeUserId'] ?? ($context['employeeUserId'] ?? null));

        $signatureRequired = Format::optionalBool($transactionData['signatureRequired'] ?? null);
        $signatureCaptured = Format::optionalBool($transactionData['signatureCaptured'] ?? null);
        $pinCaptured = Format::optionalBool($transactionData['pinCaptured'] ?? null);
        $adjusted = Format::optionalBool($transactionData['adjusted'] ?? null);
        $amountsAdjusted = Format::optionalBool($transactionData['amountsAdjusted'] ?? null);
        $authOnly = Format::optionalBool($transactionData['authOnly'] ?? null);
        $partiallyApproved = Format::optionalBool($transactionData['partiallyApproved'] ?? null);
        $actionVoid = Format::optionalBool($transactionData['actionVoid'] ?? null);
        $voided = Format::optionalBool($transactionData['voided'] ?? null);
        $settled = Format::optionalBool($transactionData['settled'] ?? null);
        $reversalVoid = Format::optionalBool($transactionData['reversalVoid'] ?? null);

        $action = Format::stringOrNull($transactionData['action'] ?? ($transactionData['type'] ?? null));
        $status = Format::stringOrNull($transactionData['status'] ?? null);
        $settlementStatus = Format::stringOrNull($transactionData['settlementStatus'] ?? null);
        $transactionInstruction = Format::stringOrNull($context['transactionInstruction'] ?? null);
        $source = Format::stringOrNull($context['source'] ?? null);
        $sourceApp = Format::stringOrNull($context['sourceApp'] ?? null);
        $mcc = Format::stringOrNull($context['mcc'] ?? null);

        $customerUserId = Format::optionalInt($transactionData['customerUserId'] ?? null);
        $customerLanguage = Format::stringOrNull($transactionData['customerLanguage'] ?? null);

        $amounts = is_array($transactionData['amounts'] ?? null) ? $transactionData['amounts'] : [];
        $transactionAmount = Format::amount($amounts['transactionAmount'] ?? null);
        $orderAmount = Format::amount($amounts['orderAmount'] ?? null);
        $tipAmount = Format::amount($amounts['tipAmount'] ?? null);
        $cashbackAmount = Format::amount($amounts['cashbackAmount'] ?? null);
        $customerOptedNoTip = Format::optionalBool($amounts['customerOptedNoTip'] ?? null);
        $currency = $amounts['currency'] ?? null;
        if ($currency === null && isset($amounts['transactionAmount']) && is_array($amounts['transactionAmount'])) {
            $currency = $amounts['transactionAmount']['currency'] ?? null;
        }
        $currency = $currency ?? ($transactionData['currency'] ?? null);
        $currency = Format::stringOrNull($currency);

        $fundingSource = is_array($transactionData['fundingSource'] ?? null) ? $transactionData['fundingSource'] : [];
        $card = $fundingSource['card'] ?? [];
        $cardBrand = $card['brand'] ?? $card['cardBrand'] ?? null;
        if (is_array($cardBrand)) {
            $cardBrand = $cardBrand['displayName'] ?? $cardBrand['scheme'] ?? $cardBrand['name'] ?? null;
        }
        $cardBrand = $cardBrand ?? ($card['type'] ?? null);
        $cardBrand = Format::stringOrNull($cardBrand);
        $cardLast4 = Format::stringOrNull($card['numberLast4'] ?? ($card['lastFourDigits'] ?? null));
        $entryMode = Format::stringOrNull(
            $card['entryMode']
            ?? $card['entryMethod']
            ?? ($transactionData['entryMode'] ?? ($fundingSource['entryDetails']['entryMode'] ?? null))
        );

        $processorResponse = is_array($transactionData['processorResponse'] ?? null)
            ? $transactionData['processorResponse']
            : [];
        $processor = $transactionData['processor'] ?? [];
        $processorName = $processor['name']
            ?? $processor['processor']
            ?? ($processorResponse['processor'] ?? null);
        $processorName = Format::stringOrNull($processorName);
        $acquirer = Format::stringOrNull($processorResponse['acquirer'] ?? null);
        $processorStatus = Format::stringOrNull($processor['status'] ?? ($processorResponse['status'] ?? null));
        $processorCode = Format::stringOrNull(
            $processor['responseCode']
            ?? $processor['statusCode']
            ?? ($processorResponse['responseCode'] ?? $processorResponse['statusCode'] ?? null)
        );
        $approvalCode = Format::stringOrNull(
            $processor['authCode']
            ?? $processor['approvalCode']
            ?? ($processorResponse['authorizationCode'] ?? $processorResponse['approvalCode'] ?? null)
        );
        $retrievalRef = Format::stringOrNull(
            $processor['rrn']
            ?? $processor['retrievalReferenceNumber']
            ?? ($processorResponse['retrievalReferenceNumber'] ?? $processorResponse['retrievalRefNum'] ?? null)
        );
        $batchId = Format::stringOrNull($processor['batchId'] ?? ($processorResponse['batchId'] ?? null));
        $processorTransactionId = Format::stringOrNull($processorResponse['transactionId'] ?? null);
        $approvedAmount = Format::amount($processorResponse['approvedAmount'] ?? null);

        $referencesJson = Format::jsonArray($transactionData['references'] ?? []);
        $linksJson = Format::jsonArray($transactionData['links'] ?? []);
        $fundingSourceJson = Format::jsonObject($fundingSource);
        $contextJson = Format::jsonObject($context);
        $processorOptionsJson = Format::jsonObject($transactionData['processorOptions'] ?? []);
        $processorResponseJson = Format::jsonObject($processorResponse);
        $amountsJson = Format::jsonObject($amounts);
        $rawPayload = Format::jsonObject($transactionData);

        $createdAtExt = Format::optionalTimestamp($transactionData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($transactionData['updatedAt'] ?? null);

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO transaction (
                    transaction_id, business_id, store_id, store_device_id, employee_user_id,
                    signature_required, signature_captured, pin_captured, adjusted, amounts_adjusted,
                    auth_only, partially_approved, action_void, voided, settled, reversal_void,
                    action, status, settlement_status, transaction_instruction, source, source_app, mcc,
                    customer_user_id, customer_language, customer_opted_no_tip,
                    txn_amount_minor, order_amount_minor, tip_amount_minor, cashback_amount_minor, currency,
                    approved_amount_minor, processor, acquirer, processor_status, processor_code,
                    approval_code, retrieval_ref, batch_id, processor_transaction_id,
                    references_json, links_json, funding_source, context_json, processor_options, processor_response,
                    amounts_json, raw_payload, created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :transactionId, :businessId, :storeId, :storeDeviceId, :employeeUserId,
                    :signatureRequired, :signatureCaptured, :pinCaptured, :adjusted, :amountsAdjusted,
                    :authOnly, :partiallyApproved, :actionVoid, :voided, :settled, :reversalVoid,
                    :action, :status, :settlementStatus, :transactionInstruction, :source, :sourceApp, :mcc,
                    :customerUserId, :customerLanguage, :customerOptedNoTip,
                    :txnAmountMinor, :orderAmountMinor, :tipAmountMinor, :cashbackAmountMinor, :currency,
                    :approvedAmountMinor, :processor, :acquirer, :processorStatus, :processorCode,
                    :approvalCode, :retrievalRef, :batchId, :processorTransactionId,
                    :referencesJson, :linksJson, :fundingSource, :contextJson, :processorOptions, :processorResponse,
                    :amountsJson, :rawPayload, :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (transaction_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    store_id = EXCLUDED.store_id,
                    store_device_id = EXCLUDED.store_device_id,
                    employee_user_id = EXCLUDED.employee_user_id,
                    signature_required = EXCLUDED.signature_required,
                    signature_captured = EXCLUDED.signature_captured,
                    pin_captured = EXCLUDED.pin_captured,
                    adjusted = EXCLUDED.adjusted,
                    amounts_adjusted = EXCLUDED.amounts_adjusted,
                    auth_only = EXCLUDED.auth_only,
                    partially_approved = EXCLUDED.partially_approved,
                    action_void = EXCLUDED.action_void,
                    voided = EXCLUDED.voided,
                    settled = EXCLUDED.settled,
                    reversal_void = EXCLUDED.reversal_void,
                    action = EXCLUDED.action,
                    status = EXCLUDED.status,
                    settlement_status = EXCLUDED.settlement_status,
                    transaction_instruction = EXCLUDED.transaction_instruction,
                    source = EXCLUDED.source,
                    source_app = EXCLUDED.source_app,
                    mcc = EXCLUDED.mcc,
                    customer_user_id = EXCLUDED.customer_user_id,
                    customer_language = EXCLUDED.customer_language,
                    customer_opted_no_tip = EXCLUDED.customer_opted_no_tip,
                    txn_amount_minor = EXCLUDED.txn_amount_minor,
                    order_amount_minor = EXCLUDED.order_amount_minor,
                    tip_amount_minor = EXCLUDED.tip_amount_minor,
                    cashback_amount_minor = EXCLUDED.cashback_amount_minor,
                    currency = EXCLUDED.currency,
                    approved_amount_minor = EXCLUDED.approved_amount_minor,
                    processor = EXCLUDED.processor,
                    acquirer = EXCLUDED.acquirer,
                    processor_status = EXCLUDED.processor_status,
                    processor_code = EXCLUDED.processor_code,
                    approval_code = EXCLUDED.approval_code,
                    retrieval_ref = EXCLUDED.retrieval_ref,
                    batch_id = EXCLUDED.batch_id,
                    processor_transaction_id = EXCLUDED.processor_transaction_id,
                    references_json = EXCLUDED.references_json,
                    links_json = EXCLUDED.links_json,
                    funding_source = EXCLUDED.funding_source,
                    context_json = EXCLUDED.context_json,
                    processor_options = EXCLUDED.processor_options,
                    processor_response = EXCLUDED.processor_response,
                    amounts_json = EXCLUDED.amounts_json,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                [
                    'transactionId' => $transactionId,
                    'businessId' => $businessId,
                    'storeId' => $storeId,
                    'storeDeviceId' => $storeDeviceId,
                    'employeeUserId' => $employeeUserId,
                    'signatureRequired' => $signatureRequired,
                    'signatureCaptured' => $signatureCaptured,
                    'pinCaptured' => $pinCaptured,
                    'adjusted' => $adjusted,
                    'amountsAdjusted' => $amountsAdjusted,
                    'authOnly' => $authOnly,
                    'partiallyApproved' => $partiallyApproved,
                    'actionVoid' => $actionVoid,
                    'voided' => $voided,
                    'settled' => $settled,
                    'reversalVoid' => $reversalVoid,
                    'action' => $action,
                    'status' => $status,
                    'settlementStatus' => $settlementStatus,
                    'transactionInstruction' => $transactionInstruction,
                    'source' => $source,
                    'sourceApp' => $sourceApp,
                    'mcc' => $mcc,
                    'customerUserId' => $customerUserId,
                    'customerLanguage' => $customerLanguage,
                    'customerOptedNoTip' => $customerOptedNoTip,
                    'txnAmountMinor' => $transactionAmount,
                    'orderAmountMinor' => $orderAmount,
                    'tipAmountMinor' => $tipAmount,
                    'cashbackAmountMinor' => $cashbackAmount,
                    'currency' => $currency,
                    'approvedAmountMinor' => $approvedAmount,
                    'processor' => $processorName,
                    'acquirer' => $acquirer,
                    'processorStatus' => $processorStatus,
                    'processorCode' => $processorCode,
                    'approvalCode' => $approvalCode,
                    'retrievalRef' => $retrievalRef,
                    'batchId' => $batchId,
                    'processorTransactionId' => $processorTransactionId,
                    'referencesJson' => $referencesJson,
                    'linksJson' => $linksJson,
                    'fundingSource' => $fundingSourceJson,
                    'contextJson' => $contextJson,
                    'processorOptions' => $processorOptionsJson,
                    'processorResponse' => $processorResponseJson,
                    'amountsJson' => $amountsJson,
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

