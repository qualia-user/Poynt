<?php

namespace App\Services;

use App\Core\Context;

class TransactionService
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
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

        try {
            $existing = $this->context->getConn()->fetchAssociative(
                'SELECT transaction_id FROM transaction WHERE transaction_id = ?',
                [$transactionId]
            );

            if ($existing) {
                $this->context->getConn()->update(
                    'transaction',
                    [
                        'business_id' => $businessId,
                        'metadata' => $metadata,
                        'updated_at' => $now,
                    ],
                    ['transaction_id' => $transactionId]
                );
            } else {
                $this->context->getConn()->insert(
                    'transaction',
                    [
                        'transaction_id' => $transactionId,
                        'business_id' => $businessId,
                        'metadata' => $metadata,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            if ($receiptData !== null) {
                $receiptJson = json_encode($receiptData);
                if ($receiptJson === false) {
                    $this->context->getLog()->error(
                        "TransactionService::upsert: failed to encode receipt for transaction_id={$transactionId}"
                    );
                    return false;
                }

                $receiptExisting = $this->context->getConn()->fetchAssociative(
                    'SELECT transaction_id FROM transaction_receipt WHERE transaction_id = ?',
                    [$transactionId]
                );

                if ($receiptExisting) {
                    $this->context->getConn()->update(
                        'transaction_receipt',
                        [
                            'data' => $receiptJson,
                            'updated_at' => $now,
                        ],
                        ['transaction_id' => $transactionId]
                    );
                } else {
                    $this->context->getConn()->insert(
                        'transaction_receipt',
                        [
                            'transaction_id' => $transactionId,
                            'data' => $receiptJson,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                'TransactionService::upsert: database error: ' . $e->getMessage()
            );
            return false;
        }
    }
}

