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
     * @return bool
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

        $metadata = json_encode($orderData);
        if ($metadata === false) {
            $this->context->getLog()->error(
                "OrderService::upsert: failed to json_encode orderData for order_id={$orderId}"
            );
            return false;
        }

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $this->context->getConn()->executeStatement(
                'INSERT INTO "order" (order_id, business_id, store_id, metadata, created_at, updated_at)
                 VALUES (:orderId, :businessId, :storeId, :metadata, :createdAt, :updatedAt)
                 ON CONFLICT (order_id) DO UPDATE SET
                     business_id = EXCLUDED.business_id,
                     store_id = EXCLUDED.store_id,
                     metadata = EXCLUDED.metadata,
                     updated_at = EXCLUDED.updated_at',
                [
                    'orderId' => $orderId,
                    'businessId' => $businessId,
                    'storeId' => $storeId,
                    'metadata' => $metadata,
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

        $sql = 'INSERT INTO order_item (order_item_id, order_id, name, metadata, created_at, updated_at)
                VALUES (:itemId, :orderId, :name, :metadata, :createdAt, :updatedAt)
                ON CONFLICT (order_item_id) DO UPDATE SET
                    order_id = EXCLUDED.order_id,
                    name = EXCLUDED.name,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($items as $item) {
            if (!isset($item['id'])) {
                $this->context->getLog()->error('OrderService::upsertItems: missing item id');
                continue;
            }
            $itemId = $item['id'];
            $name = $item['name'] ?? null;
            $metadata = json_encode($item);
            if ($metadata === false) {
                $this->context->getLog()->error(
                    "OrderService::upsertItems: failed to json_encode item for item_id={$itemId}"
                );
                continue;
            }
            $stmt->executeStatement([
                'itemId' => $itemId,
                'orderId' => $orderId,
                'name' => $name,
                'metadata' => $metadata,
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

        $sql = 'INSERT INTO order_history (order_history_id, order_id, status, metadata, created_at, updated_at)
                VALUES (:historyId, :orderId, :status, :metadata, :createdAt, :updatedAt)
                ON CONFLICT (order_history_id) DO UPDATE SET
                    order_id = EXCLUDED.order_id,
                    status = EXCLUDED.status,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($history as $entry) {
            if (!isset($entry['id'])) {
                $this->context->getLog()->error('OrderService::upsertHistory: missing history id');
                continue;
            }
            $historyId = $entry['id'];
            $status = $entry['status'] ?? null;
            $metadata = json_encode($entry);
            if ($metadata === false) {
                $this->context->getLog()->error(
                    "OrderService::upsertHistory: failed to json_encode history for history_id={$historyId}"
                );
                continue;
            }
            $stmt->executeStatement([
                'historyId' => $historyId,
                'orderId' => $orderId,
                'status' => $status,
                'metadata' => $metadata,
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

        $sql = 'INSERT INTO order_shipment (order_shipment_id, order_id, metadata, created_at, updated_at)
                VALUES (:shipmentId, :orderId, :metadata, :createdAt, :updatedAt)
                ON CONFLICT (order_shipment_id) DO UPDATE SET
                    order_id = EXCLUDED.order_id,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($shipments as $shipment) {
            if (!isset($shipment['id'])) {
                $this->context->getLog()->error('OrderService::upsertShipments: missing shipment id');
                continue;
            }
            $shipmentId = $shipment['id'];
            $metadata = json_encode($shipment);
            if ($metadata === false) {
                $this->context->getLog()->error(
                    "OrderService::upsertShipments: failed to json_encode shipment for shipment_id={$shipmentId}"
                );
                continue;
            }
            $stmt->executeStatement([
                'shipmentId' => $shipmentId,
                'orderId' => $orderId,
                'metadata' => $metadata,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }
}

