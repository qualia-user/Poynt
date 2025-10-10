<?php

namespace App\Services;

use App\Core\Context;
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

        $metadata = json_encode($transactionData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "TransactionService::upsert: failed to encode metadata for transaction_id={$transactionId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        $amounts = $transactionData['amounts'] ?? [];
        $transactionAmount = $amounts['transactionAmount']['amount'] ?? null;
        $tipAmount = $amounts['tipAmount']['amount'] ?? null;
        $cashbackAmount = $amounts['cashbackAmount']['amount'] ?? null;
        $currency = $amounts['transactionAmount']['currency'] ?? null;

        $card = $transactionData['fundingSource']['card'] ?? [];
        $cardBrand = $card['brand'] ?? null;
        $cardLast4 = $card['numberLast4'] ?? ($card['lastFourDigits'] ?? null);
        $cardHolder = $card['cardHolderName'] ?? null;
        $cardExpMonth = $card['expirationMonth'] ?? null;
        $cardExpYear = $card['expirationYear'] ?? null;

        $processor = $transactionData['processor'] ?? [];
        $processorName = $processor['name'] ?? null;
        $processorStatus = $processor['status'] ?? ($transactionData['processorResponse']['status'] ?? null);
        $processorTransactionId = $processor['transactionId'] ?? ($transactionData['processorResponse']['transactionId'] ?? null);
        $processorAuthCode = $processor['authCode'] ?? ($transactionData['processorResponse']['authorizationCode'] ?? null);

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO transaction (
                    transaction_id, business_id, amount, tip_amount, cashback_amount, currency,
                    card_brand, card_last4, card_holder, card_exp_month, card_exp_year,
                    processor, processor_status, processor_transaction_id, processor_auth_code,
                    metadata, created_at, updated_at
                ) VALUES (
                    :transactionId, :businessId, :amount, :tipAmount, :cashbackAmount, :currency,
                    :cardBrand, :cardLast4, :cardHolder, :cardExpMonth, :cardExpYear,
                    :processor, :processorStatus, :processorTransactionId, :processorAuthCode,
                    :metadata, :createdAt, :updatedAt
                ) ON CONFLICT (transaction_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    amount = EXCLUDED.amount,
                    tip_amount = EXCLUDED.tip_amount,
                    cashback_amount = EXCLUDED.cashback_amount,
                    currency = EXCLUDED.currency,
                    card_brand = EXCLUDED.card_brand,
                    card_last4 = EXCLUDED.card_last4,
                    card_holder = EXCLUDED.card_holder,
                    card_exp_month = EXCLUDED.card_exp_month,
                    card_exp_year = EXCLUDED.card_exp_year,
                    processor = EXCLUDED.processor,
                    processor_status = EXCLUDED.processor_status,
                    processor_transaction_id = EXCLUDED.processor_transaction_id,
                    processor_auth_code = EXCLUDED.processor_auth_code,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at',
                [
                    'transactionId' => $transactionId,
                    'businessId' => $businessId,
                    'amount' => $transactionAmount,
                    'tipAmount' => $tipAmount,
                    'cashbackAmount' => $cashbackAmount,
                    'currency' => $currency,
                    'cardBrand' => $cardBrand,
                    'cardLast4' => $cardLast4,
                    'cardHolder' => $cardHolder,
                    'cardExpMonth' => $cardExpMonth,
                    'cardExpYear' => $cardExpYear,
                    'processor' => $processorName,
                    'processorStatus' => $processorStatus,
                    'processorTransactionId' => $processorTransactionId,
                    'processorAuthCode' => $processorAuthCode,
                    'metadata' => $metadata,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            if ($receiptData !== null) {
                $receiptHtml = $receiptData['html'] ?? null;
                $receiptPayload = $receiptData['payload'] ?? null;
                $payloadJson = $receiptPayload !== null ? json_encode($receiptPayload) : null;
                if ($payloadJson === false) {
                    $this->context->getLog()->error(
                        "TransactionService::upsert: failed to encode receipt payload for transaction_id={$transactionId}"
                    );
                    return false;
                }

                $this->context->getConn()->executeStatement(
                    'INSERT INTO transaction_receipt (transaction_id, html, payload, created_at, updated_at)
                     VALUES (:transactionId, :html, :payload, :createdAt, :updatedAt)
                     ON CONFLICT (transaction_id) DO UPDATE SET
                        html = EXCLUDED.html,
                        payload = EXCLUDED.payload,
                        updated_at = EXCLUDED.updated_at',
                    [
                        'transactionId' => $transactionId,
                        'html' => $receiptHtml,
                        'payload' => $payloadJson,
                        'createdAt' => $now,
                        'updatedAt' => $now,
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

