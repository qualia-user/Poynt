<?php

namespace App\Services;

use App\Core\Context;

class OrderService
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Upsert order and related entities (items, history, shipments).
     *
     * @param array $orderData
     */
    public function upsert(array $orderData): bool
    {
        if (!isset($orderData['id'], $orderData['businessId'])) {
            $this->context->getLog()->error(
                'OrderService::upsert: missing required order fields (id or businessId)'
            );
            return false;
        }

        $orderId = $orderData['id'];
        $businessId = $orderData['businessId'];
        $storeId = $orderData['storeId'] ?? null;
        $status = $orderData['status'] ?? null;
        $isTaxInclusive = $orderData['isTaxInclusive'] ?? null;

        $customerJson = json_encode($orderData['customer'] ?? null);
        $transactionsJson = json_encode($orderData['transactions'] ?? null);
        $totalsJson = json_encode($orderData['totals'] ?? null);
        $taxesJson = json_encode($orderData['taxes'] ?? null);

        if (in_array(false, [$customerJson, $transactionsJson, $totalsJson, $taxesJson], true)) {
            $this->context->getLog()->error(
                "OrderService::upsert: failed to json_encode orderData for order_id={$orderId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO "order" (order_id, business_id, store_id, status, is_tax_inclusive, customer_json, transactions_json, totals_json, taxes_json, created_at, updated_at) '
                . 'VALUES (:orderId, :businessId, :storeId, :status, :isTaxInclusive, :customerJson, :transactionsJson, :totalsJson, :taxesJson, :createdAt, :updatedAt) '
                . 'ON CONFLICT (order_id) DO UPDATE SET '
                . 'business_id = EXCLUDED.business_id, '
                . 'store_id = EXCLUDED.store_id, '
                . 'status = EXCLUDED.status, '
                . 'is_tax_inclusive = EXCLUDED.is_tax_inclusive, '
                . 'customer_json = EXCLUDED.customer_json, '
                . 'transactions_json = EXCLUDED.transactions_json, '
                . 'totals_json = EXCLUDED.totals_json, '
                . 'taxes_json = EXCLUDED.taxes_json, '
                . 'updated_at = EXCLUDED.updated_at',
                [
                    'orderId' => $orderId,
                    'businessId' => $businessId,
                    'storeId' => $storeId,
                    'status' => $status,
                    'isTaxInclusive' => $isTaxInclusive,
                    'customerJson' => $customerJson,
                    'transactionsJson' => $transactionsJson,
                    'totalsJson' => $totalsJson,
                    'taxesJson' => $taxesJson,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );

            $this->upsertItems($orderId, $orderData['items'] ?? []);
            $this->upsertHistory($orderId, $orderData['history'] ?? []);
            $this->upsertShipments($orderId, $orderData['shipments'] ?? []);

            $this->context->getLog()->info("OrderService::upsert: upserted order {$orderId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "OrderService::upsert: database error for order_id={$orderId}: " . $e->getMessage()
            );
            return false;
        }
    }

    private function upsertItems(string $orderId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $sql = 'INSERT INTO order_item (order_item_id, order_id, name, quantity, price_amount, taxes_json, payload, created_at, updated_at)'
            . ' VALUES (:itemId, :orderId, :name, :quantity, :priceAmount, :taxesJson, :payload, :createdAt, :updatedAt)'
            . ' ON CONFLICT (order_item_id) DO UPDATE SET'
            . ' order_id = EXCLUDED.order_id,'
            . ' name = EXCLUDED.name,'
            . ' quantity = EXCLUDED.quantity,'
            . ' price_amount = EXCLUDED.price_amount,'
            . ' taxes_json = EXCLUDED.taxes_json,'
            . ' payload = EXCLUDED.payload,'
            . ' updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($items as $item) {
            if (!isset($item['id'])) {
                $this->context->getLog()->error('OrderService::upsertItems: missing item id');
                continue;
            }
            $itemId = $item['id'];
            $name = $item['name'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $priceAmount = $item['price'] ?? null;
            $taxesJson = json_encode($item['taxes'] ?? null);
            $payload = json_encode($item);

            if (in_array(false, [$taxesJson, $payload], true)) {
                $this->context->getLog()->error(
                    "OrderService::upsertItems: failed to json_encode item for item_id={$itemId}"
                );
                continue;
            }

            $stmt->executeStatement([
                'itemId' => $itemId,
                'orderId' => $orderId,
                'name' => $name,
                'quantity' => $quantity,
                'priceAmount' => $priceAmount,
                'taxesJson' => $taxesJson,
                'payload' => $payload,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    private function upsertHistory(string $orderId, array $history): void
    {
        if (empty($history)) {
            return;
        }

        $sql = 'INSERT INTO order_history (order_history_id, order_id, status, payload, created_at, updated_at)'
            . ' VALUES (:historyId, :orderId, :status, :payload, :createdAt, :updatedAt)'
            . ' ON CONFLICT (order_history_id) DO UPDATE SET'
            . ' order_id = EXCLUDED.order_id,'
            . ' status = EXCLUDED.status,'
            . ' payload = EXCLUDED.payload,'
            . ' updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($history as $entry) {
            if (!isset($entry['id'])) {
                $this->context->getLog()->error('OrderService::upsertHistory: missing history id');
                continue;
            }
            $historyId = $entry['id'];
            $status = $entry['status'] ?? null;
            $payload = json_encode($entry);

            if ($payload === false) {
                $this->context->getLog()->error(
                    "OrderService::upsertHistory: failed to json_encode history for history_id={$historyId}"
                );
                continue;
            }

            $stmt->executeStatement([
                'historyId' => $historyId,
                'orderId' => $orderId,
                'status' => $status,
                'payload' => $payload,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    private function upsertShipments(string $orderId, array $shipments): void
    {
        if (empty($shipments)) {
            return;
        }

        $sql = 'INSERT INTO order_shipment (order_shipment_id, order_id, status, taxes_json, payload, created_at, updated_at)'
            . ' VALUES (:shipmentId, :orderId, :status, :taxesJson, :payload, :createdAt, :updatedAt)'
            . ' ON CONFLICT (order_shipment_id) DO UPDATE SET'
            . ' order_id = EXCLUDED.order_id,'
            . ' status = EXCLUDED.status,'
            . ' taxes_json = EXCLUDED.taxes_json,'
            . ' payload = EXCLUDED.payload,'
            . ' updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($shipments as $shipment) {
            if (!isset($shipment['id'])) {
                $this->context->getLog()->error('OrderService::upsertShipments: missing shipment id');
                continue;
            }
            $shipmentId = $shipment['id'];
            $status = $shipment['status'] ?? null;
            $taxesJson = json_encode($shipment['taxes'] ?? null);
            $payload = json_encode($shipment);

            if (in_array(false, [$taxesJson, $payload], true)) {
                $this->context->getLog()->error(
                    "OrderService::upsertShipments: failed to json_encode shipment for shipment_id={$shipmentId}"
                );
                continue;
            }

            $stmt->executeStatement([
                'shipmentId' => $shipmentId,
                'orderId' => $orderId,
                'status' => $status,
                'taxesJson' => $taxesJson,
                'payload' => $payload,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }
}

